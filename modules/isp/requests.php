<?php
declare(strict_types=1);

/**
 * Service Requests: what subscribers ask for (from the portal or at the counter) and how each was handled: plan
 * upgrades and downgrades, relocations (work order), voluntary suspensions, reconnections, cancellations, profile
 * updates. Every request keeps the answer given to the subscriber, who sees it on the portal.
 */
$pk = 'isp.requests';
$types = ['upgrade' => 'Plan upgrade', 'downgrade' => 'Plan downgrade', 'relocation' => 'Relocation (transfer of the service address)', 'suspension' => 'Temporary suspension',
    'reconnection' => 'Reconnection', 'cancellation' => 'Cancellation of the service', 'profile_update' => 'Update of contact details', 'other' => 'Other request'];
$statuses = ['open' => 'Open', 'in_progress' => 'In progress', 'done' => 'Done', 'declined' => 'Declined', 'cancelled' => 'Cancelled'];
$id = (int)g('id', (string)p('id'));
$req = $id ? DB::row('SELECT r.*, a.account_no, a.account_name, a.branch_id, a.service_status, a.plan_id AS current_plan_id, a.install_address, pl.name AS requested_plan
    FROM isp_service_requests r JOIN isp_accounts a ON a.id = r.account_id LEFT JOIN isp_plans pl ON pl.id = r.requested_plan_id WHERE r.id = :i', ['i' => $id]) : null;
if ($req) {
    $myBranch = IspSetup::userBranch();
    if ($myBranch !== null && $req['branch_id'] !== null && (int)$req['branch_id'] !== $myBranch) {
        Access::denyPage($pk);
    }
}

$close = function (int $rid, string $status, string $response, array $extra = []): void {
    DB::update('isp_service_requests', $extra + ['status' => $status, 'response' => mb_substr($response, 0, 255) ?: null, 'handled_by' => Auth::id(), 'handled_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $rid]);
};

handle_post(function () use ($pk, $id, $req, $types, $statuses, $close) {
    $act = (string)p('action');
    $response = trim((string)p('response'));
    if ($act === 'create') {
        Access::require($pk, 'create');
        $acct = IspUi::postedAccount('account_id') ?? fail('Choose the subscriber account.');
        $a = IspAccounts::get($acct);
        $myBranch = IspSetup::userBranch();
        if ($myBranch !== null && $a['branch_id'] !== null && (int)$a['branch_id'] !== $myBranch) {
            fail('The account belongs to another branch.');
        }
        $type = (string)p('request_type');
        if (!isset($types[$type])) {
            fail('Choose what the subscriber asks for.');
        }
        $details = trim((string)p('details'));
        $plan = pint('requested_plan_id');
        $addr = trim((string)p('new_address'));
        if (in_array($type, ['upgrade', 'downgrade'], true) && !$plan) {
            fail('Choose the plan the subscriber asks for.');
        }
        if ($type === 'relocation' && $addr === '') {
            fail('Enter the new service address.');
        }
        if ($details === '' && !in_array($type, ['upgrade', 'downgrade', 'relocation'], true)) {
            fail('Describe the request.');
        }
        $rid = DB::insert('isp_service_requests', ['request_no' => Seq::next('isp_request'), 'account_id' => $acct, 'request_type' => $type, 'details' => $details ?: null,
            'requested_plan_id' => $plan, 'new_address' => $addr !== '' ? mb_substr($addr, 0, 255) : null, 'status' => 'open', 'created_by' => Auth::id()]);
        $no = (string)DB::value('SELECT request_no FROM isp_service_requests WHERE id = :i', ['i' => $rid]);
        IspAccounts::event($acct, 'request', 'Service request ' . $no . ': ' . $types[$type] . ($details !== '' ? ' (' . mb_substr($details, 0, 120) . ')' : ''), 'service_request', $rid);
        Audit::log('create', 'isp', 'isp_service_request', $rid, $no);
        redirect_after_save(url($pk, ['a' => 'new']), url($pk, ['id' => $rid]));
    }
    if (!$req) {
        fail('Request not found.');
    }
    if (!in_array($req['status'], ['open', 'in_progress'], true) && $act !== 'reopen') {
        fail('Request ' . $req['request_no'] . ' is already ' . strtolower($statuses[$req['status']] ?? $req['status']) . '.');
    }
    $acct = (int)$req['account_id'];
    switch ($act) {
        case 'take':
            Access::require($pk, 'edit');
            DB::update('isp_service_requests', ['status' => 'in_progress', 'handled_by' => Auth::id()], 'id = :id', ['id' => $id]);
            redirect(url($pk, ['id' => $id]), 'You are handling the request.', 'success');
        case 'change_plan':
            Access::require($pk, 'edit');
            Access::require('isp.account', 'edit');
            $plan = (int)p('plan_id');
            IspAccounts::changePlan($acct, $plan, pdate('effective', today()) ?? today(), 'Service request ' . $req['request_no'] . ($response !== '' ? ': ' . $response : ''), $pk);
            $close($id, 'done', $response ?: 'Your plan was changed to ' . IspPlans::get($plan)['name'] . ' effective ' . fdate(pdate('effective', today()) ?? today()) . '.');
            redirect(url($pk, ['id' => $id]), 'Plan changed; the request is done.', 'success');
        case 'relocate':
            Access::require($pk, 'edit');
            Access::require('ispfield.workorders', 'create');
            $addr = trim((string)p('new_address')) ?: (string)$req['new_address'];
            if ($addr === '') {
                fail('Enter the new service address.');
            }
            $wo = IspWorkOrders::create(['wo_type' => 'relocation', 'account_id' => $acct, 'address' => $addr, 'area_id' => pint('area_id'), 'priority' => 'normal',
                'description' => 'Relocation of the service to ' . $addr . ' (request ' . $req['request_no'] . ')', 'scheduled_date' => pdate('scheduled_date')]);
            $charge = r2(pnum('relocation_fee'));
            if ($charge > 0) {
                $plan = IspPlans::get((int)$req['current_plan_id']);
                IspBilling::addCharge($acct, 'one_time', 'Relocation fee (request ' . $req['request_no'] . ')', $charge, today(), 'REL-' . $id, (string)$plan['vat_mode'], null, 'service_request', $id);
            }
            DB::update('isp_service_requests', ['status' => 'in_progress', 'work_order_id' => $wo, 'new_address' => mb_substr($addr, 0, 255), 'response' => mb_substr($response ?: 'A technician will be scheduled to transfer your service.', 0, 255),
                'handled_by' => Auth::id()], 'id = :id', ['id' => $id]);
            redirect(url($pk, ['id' => $id]), 'Relocation work order ' . DB::value('SELECT wo_no FROM isp_work_orders WHERE id = :w', ['w' => $wo]) . ' created.', 'success');
        case 'relocated':
            Access::require($pk, 'edit');
            Access::require('isp.account', 'edit');
            if (!$req['work_order_id']) {
                fail('Create the relocation work order first.');
            }
            $wo = IspWorkOrders::get((int)$req['work_order_id']);
            if (!in_array($wo['status'], ['completed', 'verified'], true)) {
                fail('Work order ' . $wo['wo_no'] . ' is not completed yet.');
            }
            $set = ['install_address' => (string)$req['new_address']];
            if ($wo['area_id']) {
                $set['area_id'] = (int)$wo['area_id'];
            }
            IspAccounts::saveDetails($acct, [], $set, $pk);
            $close($id, 'done', $response ?: 'Your service was transferred to ' . $req['new_address'] . '.');
            redirect(url($pk, ['id' => $id]), 'The installation address was updated; the request is done.', 'success');
        case 'suspend':
            Access::require($pk, 'edit');
            Access::require('isp.account', 'edit');
            $until = pdate('until');
            if ($until !== null && $until <= today()) {
                fail('The suspension has to end after today.');
            }
            IspAccounts::suspend($acct, $until, 'Service request ' . $req['request_no'] . ($req['details'] ? ': ' . mb_substr((string)$req['details'], 0, 100) : ''), $pk);
            $close($id, 'done', $response ?: 'Your service is suspended' . ($until ? ' until ' . fdate($until) : '') . '.');
            redirect(url($pk, ['id' => $id]), 'The account is suspended; the request is done.', 'success');
        case 'reconnect':
            Access::require($pk, 'edit');
            $rid = IspReconnect::manual($acct, (string)p('recon_type') ?: 'manual', 'Service request ' . $req['request_no'] . ($response !== '' ? ': ' . $response : ''));
            $r = $rid ? DB::row('SELECT recon_no, status FROM isp_reconnections WHERE id = :r', ['r' => $rid]) : null;
            $close($id, $r && $r['status'] === 'restored' ? 'done' : 'in_progress', $response ?: ($r && $r['status'] === 'restored' ? 'Your service was restored.' : 'Your reconnection is being processed.'));
            redirect(url($pk, ['id' => $id]), $r ? 'Reconnection ' . $r['recon_no'] . ': ' . (IspReconnect::STATUSES[$r['status']] ?? $r['status']) . '.' : 'A reconnection was already in progress.', 'success');
        case 'terminate':
            Access::require($pk, 'edit');
            Access::require('isp.account', 'delete');
            $date = pdate('end_date', today()) ?? today();
            IspAccounts::terminate($acct, $date, 'Cancellation asked by the subscriber (request ' . $req['request_no'] . ')' . ($response !== '' ? ': ' . $response : ''), p('charge_etf') === '1', $pk);
            $close($id, 'done', $response ?: 'Your service ends on ' . fdate($date) . '. The final statement will be sent to you.');
            redirect(url($pk, ['id' => $id]), 'The subscription was terminated; the request is done.', 'success');
        case 'done':
            Access::require($pk, 'edit');
            if ($response === '') {
                fail('Enter the answer given to the subscriber.');
            }
            $close($id, 'done', $response);
            IspAccounts::event($acct, 'request', 'Service request ' . $req['request_no'] . ' done: ' . $response, 'service_request', $id);
            redirect(url($pk, ['id' => $id]), 'The request is done.', 'success');
        case 'decline':
            Access::require($pk, 'edit');
            if ($response === '') {
                fail('Enter the reason, it is shown to the subscriber.');
            }
            $close($id, 'declined', $response);
            IspAccounts::event($acct, 'request', 'Service request ' . $req['request_no'] . ' declined: ' . $response, 'service_request', $id);
            Audit::log('reject', 'isp', 'isp_service_request', $id, (string)$req['request_no'], ['reason' => $response]);
            redirect(url($pk, ['id' => $id]), 'The request was declined.', 'success');
        case 'cancel':
            Access::require($pk, 'edit');
            $close($id, 'cancelled', $response ?: 'Withdrawn by the subscriber.');
            Audit::log('cancel', 'isp', 'isp_service_request', $id, (string)$req['request_no'], ['reason' => $response]);
            redirect(url($pk, ['id' => $id]), 'The request was cancelled.', 'success');
        case 'ticket':
            Access::require('ispfield.tickets', 'create');
            $tType = ['relocation' => 'relocation', 'upgrade' => 'plan_change', 'downgrade' => 'plan_change'][$req['request_type']] ?? 'other';
            $tid = IspTickets::create(['account_id' => $acct, 'ticket_type' => $tType, 'subject' => mb_substr($types[$req['request_type']] ?? 'Service request', 0, 120) . ' (' . $req['request_no'] . ')',
                'description' => (string)($req['details'] ?: ($types[$req['request_type']] ?? '')), 'priority' => 'normal', 'source' => $req['portal_user_id'] ? 'portal' : 'walkin']);
            DB::update('isp_service_requests', ['ticket_id' => $tid, 'status' => 'in_progress', 'handled_by' => Auth::id()], 'id = :id', ['id' => $id]);
            redirect(url($pk, ['id' => $id]), 'Support ticket ' . DB::value('SELECT ticket_no FROM isp_tickets WHERE id = :t', ['t' => $tid]) . ' created.', 'success');
    }
    fail('Unknown action.');
});

// ================================================================ NEW REQUEST
if (g('a') === 'new') {
    Access::require($pk, 'create');
    $plans = IspPlans::options(true);
    layout_start('New Service Request', ['actions' => btn_link(url($pk), 'Service requests', 'btn-outline-secondary', 'arrow-left')]);
    ?>
<form method="post" class="card isp-card"><div class="card-body">
  <?= csrf_field() ?><input type="hidden" name="action" value="create">
  <div class="row g-2">
    <?= IspUi::accountField('account_id', IspUi::postedAccount('account_id') ?? gint('account_id'), 'Subscriber account', 'col-md-6') ?>
    <?= select_field('request_type', 'The subscriber asks for', $types, fv('request_type', null, (string)g('type')), ['class' => 'col-md-6', 'extra' => 'id="reqType"']) ?>
    <?= select_field('requested_plan_id', 'Plan asked for (upgrade / downgrade)', $plans, fv('requested_plan_id'), ['class' => 'col-md-6 req-plan', 'blank' => '']) ?>
    <?= input_field('new_address', 'New service address (relocation)', fv('new_address'), ['class' => 'col-md-6 req-addr']) ?>
    <div class="col-12"><label class="form-label">Details</label><textarea class="form-control form-control-sm" name="details" rows="3"><?= h(fv('details')) ?></textarea></div>
  </div>
  <div class="mt-3"><?= save_buttons('Save request', url($pk)) ?></div>
</div></form>
<script>
(function () {
  var t = document.getElementById('reqType');
  function apply() {
    var v = t.value;
    document.querySelectorAll('.req-plan').forEach(function (e) { e.hidden = v !== 'upgrade' && v !== 'downgrade'; });
    document.querySelectorAll('.req-addr').forEach(function (e) { e.hidden = v !== 'relocation'; });
  }
  t.addEventListener('change', apply); apply();
})();
</script>
    <?php
    layout_end();
    return;
}

// ================================================================ ONE REQUEST
if ($req) {
    $a = IspAccounts::get((int)$req['account_id']);
    $bal = IspAccounts::balances((int)$req['account_id']);
    $wo = $req['work_order_id'] ? IspWorkOrders::get((int)$req['work_order_id']) : null;
    $tk = $req['ticket_id'] ? DB::row('SELECT id, ticket_no, status FROM isp_tickets WHERE id = :t', ['t' => $req['ticket_id']]) : null;
    $live = in_array($req['status'], ['open', 'in_progress'], true);
    $canEdit = Access::can($pk, 'edit');
    $handler = $req['handled_by'] ? (string)DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => $req['handled_by']]) : '';
    $from = $req['portal_user_id'] ? 'the customer portal' : (string)DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => (int)$req['created_by']]);
    layout_start('Service Request ' . $req['request_no'], ['actions' => btn_link(url($pk), 'Service requests', 'btn-outline-secondary', 'arrow-left')]);
    ?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card isp-card"><div class="card-body">
      <div class="isp-head">
        <div><h2 class="isp-head-title"><?= h($types[$req['request_type']] ?? ucfirst((string)$req['request_type'])) ?></h2>
          <div class="isp-head-sub"><?= IspUi::acct($req['account_id'], $req['account_no'], $req['account_name']) ?>, received <?= h(fdatetime($req['created_at'])) ?> through <?= h($from) ?>.</div></div>
        <div class="isp-head-actions"><?= IspUi::status($req['status'] === 'done' ? 'completed' : $req['status'], ['completed' => 'Done'] + $statuses) ?></div>
      </div>
      <?= IspUi::dl([
          'Details' => $req['details'] ? nl2br(h($req['details'])) : null,
          'Plan asked for' => $req['requested_plan'] ? h($req['requested_plan']) : null,
          'Current plan' => h($a['plan_name']),
          'New address' => $req['new_address'] ? h($req['new_address']) : null,
          'Current address' => IspUi::sensitive($a['install_address']),
          'Service' => IspUi::status($a['service_status'], IspAccounts::SERVICE_STATUSES),
          'Balance' => money($bal['balance']) . ($bal['overdue'] > 0 ? ' <span class="text-danger small">overdue ' . money($bal['overdue']) . '</span>' : ''),
          'Work order' => $wo ? safe_link('ispfield.workorders', ['id' => $wo['id']], $wo['wo_no']) . ' ' . IspUi::status($wo['status'], IspWorkOrders::STATUSES) : null,
          'Ticket' => $tk ? safe_link('ispfield.tickets', ['id' => $tk['id']], $tk['ticket_no']) . ' ' . IspUi::status($tk['status'], IspTickets::STATUSES) : null,
          'Answer to the subscriber' => $req['response'] ? h($req['response']) : null,
          'Handled by' => $handler !== '' ? h($handler) . ($req['handled_at'] ? ' <span class="small text-muted">' . h(fdatetime($req['handled_at'])) . '</span>' : '') : null,
      ]) ?>
    </div></div>
  </div>
  <div class="col-lg-5">
    <?php if ($live && $canEdit): $type = (string)$req['request_type']; ?>
    <div class="card isp-card"><div class="card-body">
      <h2 class="sec-title">Handle the request</h2>
      <?php if ($req['status'] === 'open'): ?><div class="mb-3"><?= IspUi::actionForm('take', 'I am handling it', [], 'btn-outline-secondary', 'person-check') ?></div><?php endif; ?>

      <?php if (in_array($type, ['upgrade', 'downgrade'], true) && Access::can('isp.account', 'edit') && in_array($a['service_status'], ['active', 'suspended', 'disconnected', 'pending'], true)):
          $planOpts = IspPlans::options(true, $req['requested_plan_id'] ? (int)$req['requested_plan_id'] : null, $a['area_id'] ? (int)$a['area_id'] : null);
          unset($planOpts[(int)$a['plan_id']]); ?>
      <form method="post" class="row g-2" data-confirm="Change the plan? A change inside a billed period is prorated on the next invoice."><?= csrf_field() ?><input type="hidden" name="action" value="change_plan">
        <?= select_field('plan_id', 'New plan', $planOpts, fv('plan_id', null, $req['requested_plan_id']), ['class' => 'col-12', 'required' => true, 'blank' => '']) ?>
        <?= input_field('effective', 'Effective', fv('effective', null, today()), ['class' => 'col-md-6', 'type' => 'date']) ?>
        <?= input_field('response', 'Answer (optional)', '', ['class' => 'col-md-6']) ?>
        <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-arrow-left-right"></i> Change the plan</button></div>
      </form>
      <?php elseif ($type === 'relocation' && !$wo && Access::can('ispfield.workorders', 'create')):
          $areaOpts = DB::pairs("SELECT a.id, CONCAT(a.name, CASE WHEN p.name IS NULL THEN '' ELSE CONCAT(', ', p.name) END) FROM isp_areas a LEFT JOIN isp_areas p ON p.id = a.parent_id WHERE a.is_active = 1 ORDER BY p.name, a.name"); ?>
      <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="relocate">
        <?= input_field('new_address', 'New service address', fv('new_address', null, $req['new_address']), ['class' => 'col-12', 'required' => true]) ?>
        <?= select_field('area_id', 'Service area', $areaOpts, fv('area_id', null, $a['area_id']), ['class' => 'col-md-6', 'blank' => '']) ?>
        <?= input_field('scheduled_date', 'Preferred date', fv('scheduled_date'), ['class' => 'col-md-6', 'type' => 'date']) ?>
        <?= input_field('relocation_fee', 'Relocation fee (next invoice)', fv('relocation_fee', null, '0'), ['class' => 'col-md-6', 'type' => 'number']) ?>
        <?= input_field('response', 'Answer (optional)', '', ['class' => 'col-md-6']) ?>
        <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-truck"></i> Create the relocation work order</button></div>
      </form>
      <?php elseif ($type === 'relocation' && $wo && Access::can('isp.account', 'edit')): ?>
      <form method="post" class="row g-2" data-confirm="Update the installation address of the account to the new address?"><?= csrf_field() ?><input type="hidden" name="action" value="relocated">
        <?= input_field('response', 'Answer (optional)', '', ['class' => 'col-12']) ?>
        <div class="col-12"><button class="btn btn-sm btn-primary"<?= in_array($wo['status'], ['completed', 'verified'], true) ? '' : ' disabled' ?>><i class="bi bi-house-check"></i> Relocated: update the address</button>
          <?php if (!in_array($wo['status'], ['completed', 'verified'], true)): ?><div class="small text-muted mt-1">Waiting for work order <?= h($wo['wo_no']) ?>.</div><?php endif; ?></div>
      </form>
      <?php elseif ($type === 'suspension' && $a['service_status'] === 'active' && Access::can('isp.account', 'edit')): ?>
      <form method="post" class="row g-2" data-confirm="Suspend the service?"><?= csrf_field() ?><input type="hidden" name="action" value="suspend">
        <?= input_field('until', 'Until (optional)', fv('until'), ['class' => 'col-md-6', 'type' => 'date']) ?>
        <?= input_field('response', 'Answer (optional)', '', ['class' => 'col-md-6']) ?>
        <div class="col-12"><button class="btn btn-sm btn-outline-warning"><i class="bi bi-pause-circle"></i> Suspend the service</button></div>
      </form>
      <?php elseif ($type === 'reconnection' && $a['service_status'] === 'disconnected' && Access::special('isp_reconnect')): ?>
      <p class="small text-muted">A payment that settles the arrears reconnects the account automatically. Use this to reconnect by authority.</p>
      <form method="post" class="row g-2" data-confirm="Reconnect the account?"><?= csrf_field() ?><input type="hidden" name="action" value="reconnect">
        <?= select_field('recon_type', 'Kind', array_diff_key(IspReconnect::TYPES, ['automatic' => 1]), 'manual', ['class' => 'col-md-6']) ?>
        <?= input_field('response', 'Answer (optional)', '', ['class' => 'col-md-6']) ?>
        <div class="col-12"><button class="btn btn-sm btn-success"><i class="bi bi-arrow-repeat"></i> Reconnect</button></div>
      </form>
      <?php elseif ($type === 'cancellation' && in_array($a['service_status'], ['active', 'suspended', 'disconnected'], true) && Access::can('isp.account', 'delete')):
          $plan = IspPlans::get((int)$a['plan_id']);
          $etf = $a['lock_in_until'] && today() <= $a['lock_in_until'] && (float)$plan['early_termination_fee'] > 0; ?>
      <form method="post" class="row g-2" data-confirm="Terminate the subscription? This cannot be undone." data-confirm-tone="danger"><?= csrf_field() ?><input type="hidden" name="action" value="terminate">
        <?= input_field('end_date', 'Service ends', fv('end_date', null, today()), ['class' => 'col-md-6', 'type' => 'date']) ?>
        <?= input_field('response', 'Answer (optional)', '', ['class' => 'col-md-6']) ?>
        <?php if ($etf): ?><?= check_field('charge_etf', 'Charge the early termination fee of ' . money($plan['early_termination_fee']), '1', 'col-12') ?><?php endif; ?>
        <div class="col-12"><button class="btn btn-sm btn-danger"><i class="bi bi-x-octagon"></i> Terminate the subscription</button></div>
      </form>
      <?php elseif ($type === 'profile_update' && Access::can('isp.account', 'edit')): ?>
      <p class="small">Update the details on the account page, then mark the request done.</p>
      <p><?= btn_link(url('isp.account', ['id' => $req['account_id'], 'a' => 'edit']), 'Edit the account details', 'btn-outline-primary', 'pencil') ?></p>
      <?php endif; ?>

      <hr>
      <form method="post" class="row g-2"><?= csrf_field() ?>
        <?= input_field('response', 'Answer to the subscriber', '', ['class' => 'col-12', 'extra' => 'maxlength="255"']) ?>
        <div class="col-12 d-flex flex-wrap gap-2">
          <button class="btn btn-sm btn-outline-success" name="action" value="done">Mark done</button>
          <button class="btn btn-sm btn-outline-danger" name="action" value="decline" data-confirm="Decline the request?">Decline</button>
          <button class="btn btn-sm btn-outline-secondary" name="action" value="cancel" data-confirm="Cancel the request (withdrawn)?">Withdrawn</button>
          <?php if (!$tk && Access::can('ispfield.tickets', 'create')): ?><button class="btn btn-sm btn-outline-secondary" name="action" value="ticket" formnovalidate>Open a support ticket</button><?php endif; ?>
        </div>
      </form>
    </div></div>
    <?php endif; ?>
  </div>
</div>
    <?php
    layout_end();
    return;
}
if ($id) {
    layout_start('Service Requests');
    echo IspUi::empty('The request was not found.', 'search');
    layout_end();
    return;
}

// ================================================================ THE LIST
$tabs = ['open' => 'Open', 'done' => 'Done', 'closed' => 'Declined / withdrawn', 'all' => 'All'];
$tab = isset($tabs[(string)g('tab')]) ? (string)g('tab') : 'open';
$type = (string)g('type');
$q = trim((string)g('q'));
$params = [];
$where = ' WHERE 1 = 1' . IspSetup::branchSql('a.branch_id', $params);
$counts = DB::pairs('SELECT r.status, COUNT(*) FROM isp_service_requests r JOIN isp_accounts a ON a.id = r.account_id' . $where . ' GROUP BY r.status', $params);
if ($tab === 'open') {
    $where .= " AND r.status IN ('open', 'in_progress')";
} elseif ($tab === 'done') {
    $where .= " AND r.status = 'done'";
} elseif ($tab === 'closed') {
    $where .= " AND r.status IN ('declined', 'cancelled')";
}
if (isset($types[$type])) {
    $where .= ' AND r.request_type = :t';
    $params['t'] = $type;
}
if ($q !== '') {
    $where .= ' AND (r.request_no LIKE :q1 OR a.account_no LIKE :q2 OR a.account_name LIKE :q3)';
    $params += ['q1' => $q . '%', 'q2' => $q . '%', 'q3' => '%' . $q . '%'];
}
$from = ' FROM isp_service_requests r JOIN isp_accounts a ON a.id = r.account_id LEFT JOIN users u ON u.id = r.handled_by';
[$per, $off, $page] = IspUi::paging(50);
$total = (int)DB::value('SELECT COUNT(*)' . $from . $where, $params);
$rows = DB::all('SELECT r.*, a.account_no, a.account_name, u.full_name AS handler' . $from . $where . ' ORDER BY r.status IN (\'open\', \'in_progress\') DESC, r.id DESC LIMIT ' . $per . ' OFFSET ' . $off, $params);
$n = fn(array $s) => array_sum(array_map(fn($k) => (int)($counts[$k] ?? 0), $s));
$tabLabels = ['open' => 'Open' . (($c = $n(['open', 'in_progress'])) ? ' (' . $c . ')' : ''), 'done' => 'Done', 'closed' => 'Declined / withdrawn', 'all' => 'All'];
layout_start('Service Requests', ['actions' => page_link($pk, ['a' => 'new'], 'New request', 'btn-primary', 'plus-lg', 'create')]);
echo IspUi::tabs($tabLabels, $tab, $pk, array_filter(['type' => $type, 'q' => $q]));
?>
<?= IspUi::filterStart($pk, ['tab' => $tab]) ?>
  <?= IspUi::fSearch($q, 'Request no., account no. or name') ?>
  <?= IspUi::fSelect('type', 'Request', $types, $type) ?>
<?= IspUi::filterEnd(url($pk, ['tab' => $tab])) ?>
<div class="card isp-card"><div class="card-body p-0">
  <?php if (!$rows): ?><?= IspUi::empty('No service request here.', 'inbox') ?><?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table mb-0">
    <thead><tr><th>Request</th><th>Account</th><th>Asks for</th><th class="d-none d-md-table-cell">From</th><th>Status</th><th class="d-none d-lg-table-cell">Handled by</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><a href="<?= h(url($pk, ['id' => $r['id']])) ?>"><strong><?= h($r['request_no']) ?></strong></a><div class="small text-muted"><?= h(fdatetime($r['created_at'])) ?></div></td>
        <td><?= IspUi::acct($r['account_id'], $r['account_no'], $r['account_name']) ?></td>
        <td><?= h($types[$r['request_type']] ?? ucfirst((string)$r['request_type'])) ?><?php if ($r['details']): ?><div class="small text-muted"><?= h(mb_substr((string)$r['details'], 0, 90)) ?></div><?php endif; ?></td>
        <td class="d-none d-md-table-cell small"><?= $r['portal_user_id'] ? 'Portal' : 'Staff' ?></td>
        <td><?= IspUi::status($r['status'] === 'done' ? 'completed' : $r['status'], ['completed' => 'Done'] + $statuses) ?></td>
        <td class="d-none d-lg-table-cell small"><?= h((string)$r['handler']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div></div>
<?= IspUi::pager($total, $per, $page) ?>
<?php
layout_end();
