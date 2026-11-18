<?php
declare(strict_types=1);

defined('APP_PORTAL') || exit;   // opened only through portal/index.php

$c = portal_customer();
$a = portal_account();
if (!$a) {
    redirect(IspPortal::url('home'));
}
portal_post(function () use ($c) {
    $acct = portal_account((int)p('account'));
    $type = (string)p('ticket_type');
    if (!isset(portal_ticket_types()[$type])) {
        fail('Choose what the problem is.');
    }
    $desc = trim((string)p('description'));
    if (mb_strlen($desc) < 5) {
        fail('Describe the problem in a few words (what happens, since when).');
    }
    $file = portal_upload_check('photo');
    $puid = (int)$c['portal_user']['id'];
    $open = DB::value("SELECT ticket_no FROM isp_tickets WHERE account_id = :a AND ticket_type = :t AND status NOT IN ('resolved', 'verified', 'closed') LIMIT 1", ['a' => $acct['id'], 't' => $type]);
    if ($open) {
        fail('Ticket ' . $open . ' about the same problem is still open. Add your details to it instead of opening another.');
    }
    $contact = trim((string)p('contact'));
    $id = IspTickets::create(['account_id' => (int)$acct['id'], 'ticket_type' => $type, 'subject' => mb_substr((string)(trim((string)p('subject')) ?: portal_ticket_types()[$type]), 0, 160),
        'description' => $desc . ($contact !== '' ? "\nContact: " . $contact : '') . ((string)p('preferred') !== '' ? "\nBest time to visit: " . p('preferred') : ''),
        'source' => 'portal', 'portal_user_id' => $puid]);
    if ($file) {
        portal_upload_store($file, 'ticket', $id, $puid, 'Photo from the subscriber');
    }
    $no = DB::value('SELECT ticket_no FROM isp_tickets WHERE id = :i', ['i' => $id]);
    redirect(IspPortal::url('ticket', ['id' => $id]), 'Ticket ' . $no . ' was opened. We will update you here and by message.', 'success');
});
$rows = DB::all('SELECT * FROM isp_tickets WHERE account_id = :a ORDER BY id DESC LIMIT 60', ['a' => $a['id']]);
$showNew = g('new') === '1' || is_post() || !$rows;
$outages = portal_outages((int)$a['id']);
portal_start('Support', ['actions' => portal_account_switch($a, 'tickets') . ($showNew ? '' : '<a class="btn btn-sm btn-primary" href="' . h(IspPortal::url('tickets', ['account' => (int)$a['id'], 'new' => 1])) . '"><i class="bi bi-plus-lg"></i> Report a problem</a>')]);
?>
<?php foreach ($outages as $o): ?>
  <div class="pp-alert pp-alert-warn"><i class="bi bi-cone-striped"></i><div><strong>There is a known <?= (int)$o['is_planned'] === 1 ? 'maintenance' : 'service interruption' ?> in your area.</strong> <?= h($o['title']) ?>. Our team is on it; you do not need to report it.</div></div>
<?php endforeach; ?>
<?php if ($showNew): ?>
<form class="pp-card pp-reveal" method="post" enctype="multipart/form-data" action="<?= h(IspPortal::url('tickets', ['account' => (int)$a['id'], 'new' => 1])) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="account" value="<?= (int)$a['id'] ?>">
  <h2 class="pp-sec">Report a problem with account <?= h($a['account_no']) ?></h2>
  <div class="pp-tip small mb-3"><i class="bi bi-lightbulb"></i> Before reporting no connection: turn the modem off for 30 seconds and on again, and check that the cables are plugged in and the PON / LOS light is not red.</div>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label" for="tt">What is the problem?</label>
      <select class="form-select" id="tt" name="ticket_type" required><?= options(portal_ticket_types(), p('ticket_type') ?: 'no_internet') ?></select></div>
    <div class="col-md-6"><label class="form-label" for="sj">Short title (optional)</label><input class="form-control" id="sj" name="subject" value="<?= h(p('subject')) ?>" maxlength="160"></div>
    <div class="col-12"><label class="form-label" for="ds">Details</label><textarea class="form-control" id="ds" name="description" rows="4" required minlength="5" placeholder="What happens, since when, which lights are on the modem"><?= h(p('description')) ?></textarea></div>
    <div class="col-md-6"><label class="form-label" for="ct">Contact number for the technician</label><input class="form-control" id="ct" name="contact" value="<?= h((string)(p('contact') ?: $c['mobile'])) ?>" maxlength="40"></div>
    <div class="col-md-6"><label class="form-label" for="pf">Best time for a visit (optional)</label><input class="form-control" id="pf" name="preferred" value="<?= h(p('preferred')) ?>" maxlength="60" placeholder="e.g. weekdays after 1 PM"></div>
    <div class="col-12"><label class="form-label" for="ph">Photo (optional)</label><input class="form-control" id="ph" type="file" name="photo" accept="image/jpeg,image/png,image/webp,application/pdf">
      <div class="form-text">A photo of the modem lights or a damaged cable helps. JPG, PNG, WebP or PDF, up to 5 MB.</div></div>
    <div class="col-12"><button class="btn btn-primary"><i class="bi bi-send"></i> Send</button>
      <?php if ($rows): ?><a class="btn btn-outline-secondary" href="<?= h(IspPortal::url('tickets', ['account' => (int)$a['id']])) ?>">Cancel</a><?php endif; ?></div>
  </div>
</form>
<?php endif; ?>
<?php if ($rows): ?>
<div class="pp-card pp-reveal">
  <h2 class="pp-sec">Your tickets</h2>
  <div class="pp-list">
  <?php foreach ($rows as $r): ?>
    <a class="pp-row" href="<?= h(IspPortal::url('ticket', ['id' => (int)$r['id']])) ?>">
      <div class="pp-row-main"><strong><?= h($r['ticket_no']) ?></strong><span class="pp-row-sub"><?= h($r['subject']) ?></span></div>
      <div class="pp-row-mid"><?= portal_status($r['status'], IspTickets::STATUSES) ?><span class="pp-row-sub">Opened <?= h(fdate($r['created_at'])) ?></span></div>
      <i class="bi bi-chevron-right pp-row-go"></i>
    </a>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<?php
portal_end();
