<?php
declare(strict_types=1);

defined('APP_PORTAL') || exit;   // opened only through portal/index.php

// Service requests: plan upgrade / downgrade, relocation, temporary suspension, reconnection, cancellation, a change
// of the billing details. They go to the office (Subscribers > Service Requests), which acts on them; nothing about
// the service changes from here by itself.
$c = portal_customer();
$a = portal_account();
if (!$a) {
    redirect(IspPortal::url('home'));
}
$puid = (int)$c['portal_user']['id'];
portal_post(function () use ($c, $puid) {
    $acct = portal_account((int)p('account'));
    $full = IspAccounts::get((int)$acct['id']);
    if ((string)p('action') === 'cancel') {
        $r = DB::row('SELECT * FROM isp_service_requests WHERE id = :id AND account_id = :a', ['id' => (int)p('id'), 'a' => $acct['id']]) ?? fail('That request was not found.');
        if ($r['status'] !== 'open') {
            fail('Request ' . $r['request_no'] . ' is already being handled; call our office to change it.');
        }
        DB::update('isp_service_requests', ['status' => 'cancelled', 'response' => 'Withdrawn by the subscriber on the portal', 'handled_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $r['id']]);
        IspAccounts::event((int)$acct['id'], 'request', 'Service request ' . $r['request_no'] . ' withdrawn by the subscriber', 'service_request', (int)$r['id']);
        redirect(IspPortal::url('requests', ['account' => (int)$acct['id']]), 'Request ' . $r['request_no'] . ' was withdrawn.', 'success');
    }
    $type = (string)p('request_type');
    $details = trim((string)p('details'));
    $row = ['account_id' => (int)$acct['id'], 'request_type' => $type, 'status' => 'open', 'portal_user_id' => $puid];
    switch ($type) {
        case 'plan':
            $plan = IspPlans::get((int)p('plan_id'));
            if (!$plan || (int)$plan['id'] === (int)$full['plan_id'] || !IspPlans::offered($plan, today(), $full['area_id'] ? (int)$full['area_id'] : null)) {
                fail('Choose one of the plans offered in your area.');
            }
            $current = IspPlans::get((int)$full['plan_id']);
            $row['request_type'] = IspPlans::priceFor($plan, $full) >= IspPlans::priceFor($current, $full) ? 'upgrade' : 'downgrade';
            $row['requested_plan_id'] = (int)$plan['id'];
            $row['details'] = 'From ' . $current['name'] . ' to ' . $plan['name'] . ($details !== '' ? ". " . $details : '');
            break;
        case 'relocation':
            $addr = trim((string)p('new_address'));
            if (mb_strlen($addr) < 8) {
                fail('Enter the complete new address (house no., street, barangay, town).');
            }
            $when = (string)p('preferred_date');
            $row['new_address'] = mb_substr($addr, 0, 255);
            $row['details'] = trim('Landmark: ' . trim((string)p('landmark')) . '. ' . (valid_date($when) ? 'Preferred date: ' . fdate($when) . '. ' : '') . $details, ' .');
            break;
        case 'suspension':
            $from = (string)p('from');
            $to = (string)p('to');
            if (!valid_date($from) || !valid_date($to) || $from < today() || $to <= $from || $to > date('Y-m-d', strtotime($from . ' +365 days'))) {
                fail('Enter the dates of the suspension: from today or later, until a later date within a year.');
            }
            if ($full['service_status'] !== 'active') {
                fail('Only an active service can be suspended.');
            }
            $row['details'] = 'Suspend from ' . fdate($from) . ' until ' . fdate($to) . ($details !== '' ? '. Reason: ' . $details : '');
            break;
        case 'reconnection':
            if (!in_array($full['service_status'], ['disconnected', 'suspended'], true)) {
                fail('This service is not disconnected or suspended.');
            }
            $row['details'] = $details !== '' ? $details : 'Please reconnect my service.';
            break;
        case 'cancellation':
            if ($details === '') {
                fail('Tell us why you want to cancel the service.');
            }
            $row['details'] = $details;
            break;
        case 'profile_update':
        case 'other':
            if ($details === '') {
                fail('Describe your request.');
            }
            $row['details'] = $details;
            break;
        default:
            fail('Choose the kind of request.');
    }
    $dup = DB::value("SELECT request_no FROM isp_service_requests WHERE account_id = :a AND request_type = :t AND status IN ('open', 'in_progress') LIMIT 1", ['a' => $acct['id'], 't' => $row['request_type']]);
    if ($dup) {
        fail('Request ' . $dup . ' of the same kind is still being handled.');
    }
    $row['details'] = mb_substr((string)$row['details'], 0, 4000);
    $row['request_no'] = Seq::next('isp_request');
    $id = DB::insert('isp_service_requests', $row);
    IspAccounts::event((int)$acct['id'], 'request', 'Service request ' . $row['request_no'] . ' (' . PORTAL_REQUEST_TYPES[$row['request_type']] . ') sent on the portal', 'service_request', $id);
    Audit::log('create', 'isp', 'isp_service_request', $id, $row['request_no'], ['user_id' => null, 'username' => 'portal:' . $c['customer_no'], 'roles' => 'Subscriber', 'page_key' => 'portal',
        'reason' => PORTAL_REQUEST_TYPES[$row['request_type']]]);
    redirect(IspPortal::url('requests', ['account' => (int)$acct['id']]), 'Request ' . $row['request_no'] . ' was sent. Our office will contact you about it.', 'success');
});
$full = IspAccounts::get((int)$a['id']);
$rows = DB::all('SELECT r.*, p.name AS plan_name FROM isp_service_requests r LEFT JOIN isp_plans p ON p.id = r.requested_plan_id WHERE r.account_id = :a ORDER BY r.id DESC LIMIT 60', ['a' => $a['id']]);
$plans = IspPlans::options(true, null, $full['area_id'] ? (int)$full['area_id'] : null);
unset($plans[(int)$full['plan_id']]);
$new = (string)(p('request_type') ?: g('new'));
$new = $new === 'upgrade' || $new === 'downgrade' ? 'plan' : $new;
$types = ['plan' => 'Change my plan (upgrade or downgrade)', 'relocation' => 'Move my service to a new address', 'suspension' => 'Suspend my service for a while',
    'reconnection' => 'Reconnect my service', 'cancellation' => 'Cancel my service', 'profile_update' => 'Change my billing name or address', 'other' => 'Something else'];
$lockIn = $full['lock_in_until'] && $full['lock_in_until'] >= today();
portal_start('Service requests', ['actions' => portal_account_switch($a, 'requests')]);
?>
<div class="pp-grid-2 pp-grid-wide">
  <form class="pp-card pp-reveal" method="post" action="<?= h(IspPortal::url('requests', ['account' => (int)$a['id']])) ?>" data-request-form>
    <?= csrf_field() ?>
    <input type="hidden" name="account" value="<?= (int)$a['id'] ?>">
    <h2 class="pp-sec">New request for account <?= h($a['account_no']) ?></h2>
    <div class="mb-3"><label class="form-label" for="rt">What would you like?</label>
      <select class="form-select" id="rt" name="request_type" required data-request-type><?= options($types, $new, 'Choose') ?></select></div>
    <div data-for="plan" hidden>
      <div class="pp-kv"><span>Current plan</span><strong><?= h($full['plan_name']) ?></strong></div>
      <label class="form-label mt-2" for="pl">New plan</label>
      <select class="form-select" id="pl" name="plan_id"><?= options($plans, p('plan_id'), 'Choose a plan') ?></select>
      <?php if ($lockIn): ?><div class="form-text">Your contract has a lock-in period until <?= h(fdate($full['lock_in_until'])) ?>; a downgrade before then may not be possible.</div><?php endif; ?>
    </div>
    <div data-for="relocation" hidden>
      <label class="form-label" for="na">New installation address</label><input class="form-control" id="na" name="new_address" value="<?= h(p('new_address')) ?>" maxlength="255">
      <div class="row g-2 mt-1">
        <div class="col-sm-7"><label class="form-label" for="lm">Landmark</label><input class="form-control" id="lm" name="landmark" value="<?= h(p('landmark')) ?>" maxlength="120"></div>
        <div class="col-sm-5"><label class="form-label" for="pd">Preferred date</label><input class="form-control" id="pd" type="date" name="preferred_date" value="<?= h(p('preferred_date')) ?>" min="<?= h(today()) ?>"></div>
      </div>
      <div class="form-text">We check the coverage at the new address first. A relocation fee may apply.</div>
    </div>
    <div data-for="suspension" hidden>
      <div class="row g-2">
        <div class="col-6"><label class="form-label" for="sf">From</label><input class="form-control" id="sf" type="date" name="from" value="<?= h(p('from')) ?>" min="<?= h(today()) ?>"></div>
        <div class="col-6"><label class="form-label" for="st">Until</label><input class="form-control" id="st" type="date" name="to" value="<?= h(p('to')) ?>" min="<?= h(today()) ?>"></div>
      </div>
    </div>
    <div data-for="cancellation" hidden>
      <?php if ($lockIn): ?><div class="pp-alert pp-alert-warn"><i class="bi bi-exclamation-triangle"></i><div>Your contract has a lock-in period until <?= h(fdate($full['lock_in_until'])) ?>. Cancelling earlier may carry an early termination fee.</div></div><?php endif; ?>
      <div class="form-text mb-2">Company-owned equipment (modem, router) is collected after the cancellation.</div>
    </div>
    <div class="mt-3"><label class="form-label" for="dt">Details</label><textarea class="form-control" id="dt" name="details" rows="3" maxlength="2000"><?= h(p('details')) ?></textarea></div>
    <button class="btn btn-primary mt-3"><i class="bi bi-send"></i> Send request</button>
  </form>
  <section class="pp-card pp-reveal">
    <h2 class="pp-sec">Your requests</h2>
    <?php if (!$rows): ?><?= portal_empty('No requests yet.', 'arrow-left-right') ?><?php endif; ?>
    <div class="pp-list">
    <?php foreach ($rows as $r): ?>
      <div class="pp-row pp-row-static">
        <div class="pp-row-main"><strong><?= h($r['request_no']) ?>, <?= h(PORTAL_REQUEST_TYPES[$r['request_type']] ?? $r['request_type']) ?></strong>
          <span class="pp-row-sub"><?= h(mb_strimwidth((string)$r['details'], 0, 140, '...')) ?><?= $r['new_address'] ? ' New address: ' . h($r['new_address']) : '' ?></span>
          <?php if ($r['response']): ?><span class="pp-row-sub"><i class="bi bi-reply"></i> <?= h($r['response']) ?></span><?php endif; ?></div>
        <div class="pp-row-mid"><?= portal_status($r['status'], PORTAL_REQUEST_STATUSES) ?><span class="pp-row-sub"><?= h(fdate($r['created_at'])) ?></span>
          <?php if ($r['status'] === 'open'): ?>
          <form method="post" action="<?= h(IspPortal::url('requests', ['account' => (int)$a['id']])) ?>" data-confirm="Withdraw request <?= h($r['request_no']) ?>?">
            <?= csrf_field() ?><input type="hidden" name="account" value="<?= (int)$a['id'] ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-link btn-sm p-0">Withdraw</button></form>
          <?php endif; ?></div>
      </div>
    <?php endforeach; ?>
    </div>
  </section>
</div>
<?php
portal_end();
