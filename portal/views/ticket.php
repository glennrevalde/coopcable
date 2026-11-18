<?php
declare(strict_types=1);

defined('APP_PORTAL') || exit;   // opened only through portal/index.php

$c = portal_customer();
$t = IspTickets::get((int)g('id'));
if (!$t || !$t['account_id']) {
    http_response_code(404);
    fail('That ticket was not found.');
}
$a = IspPortal::account((int)$t['account_id']);
$puid = (int)$c['portal_user']['id'];
portal_post(function () use ($t, $puid) {
    $action = (string)p('action');
    $text = trim((string)p('note'));
    if ($action === 'reply') {
        if ($text === '') {
            fail('Write your message.');
        }
        if (in_array($t['status'], ['closed'], true)) {
            fail('This ticket is closed. Open a new one if the problem is back.');
        }
        IspTickets::note((int)$t['id'], 'Subscriber: ' . mb_substr($text, 0, 2000), null, true, $puid);
        DB::update('isp_tickets', ['updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $t['id']]);
        redirect(IspPortal::url('ticket', ['id' => (int)$t['id']]), 'Your message was added to the ticket.', 'success');
    }
    if ($action === 'confirm' && in_array($t['status'], ['resolved', 'verified'], true)) {
        IspTickets::move((int)$t['id'], 'closed', ['rating' => (int)p('rating'), 'feedback' => mb_substr($text, 0, 255), 'note' => 'The subscriber confirmed the problem is fixed' . ($text !== '' ? ': ' . $text : '') . '.']);
        redirect(IspPortal::url('ticket', ['id' => (int)$t['id']]), 'Thank you for confirming.', 'success');
    }
    if ($action === 'reopen' && in_array($t['status'], ['resolved', 'verified'], true)) {
        if ($text === '') {
            fail('Tell us what is still wrong.');
        }
        DB::tx(function () use ($t, $text, $puid) {
            IspTickets::move((int)$t['id'], 'in_progress', ['note' => 'Reopened by the subscriber on the portal']);
            IspTickets::note((int)$t['id'], 'Subscriber: still not fixed. ' . mb_substr($text, 0, 1000), null, true, $puid);
        });
        redirect(IspPortal::url('ticket', ['id' => (int)$t['id']]), 'The ticket is open again. Our team will follow up.', 'success');
    }
    fail('That cannot be done on this ticket now.');
});
$notes = DB::all('SELECT n.*, u.full_name FROM isp_ticket_notes n LEFT JOIN users u ON u.id = n.user_id WHERE n.ticket_id = :t AND n.is_public = 1 ORDER BY n.id', ['t' => $t['id']]);
$files = portal_files('ticket', (int)$t['id']);
$wo = $t['work_order_id'] ? DB::row('SELECT wo_no, status, scheduled_date, scheduled_slot FROM isp_work_orders WHERE id = :w', ['w' => $t['work_order_id']]) : null;
portal_start('Ticket ' . $t['ticket_no'], ['back' => [IspPortal::url('tickets', ['account' => (int)$a['id']]), 'Support']]);
?>
<div class="pp-grid-2 pp-grid-wide">
  <section class="pp-card pp-reveal">
    <div class="pp-ticket-head"><h2 class="pp-sec mb-1"><?= h($t['subject']) ?></h2><?= portal_status($t['status'], IspTickets::STATUSES) ?></div>
    <div class="pp-row-sub mb-3"><?= ($typeLabel = IspTickets::TYPES[$t['ticket_type']] ?? $t['ticket_type']) !== $t['subject'] ? h($typeLabel) . ', a' : 'A' ?>ccount <?= h($a['account_no']) ?>, opened <?= h(fdatetime($t['created_at'])) ?></div>
    <?php if ($t['description']): ?><p class="pp-pre"><?= nl2br(h($t['description'])) ?></p><?php endif; ?>
    <?php foreach ($files as $f): ?><a class="small me-3" href="<?= h(IspPortal::url('file', ['id' => (int)$f['id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-paperclip"></i> <?= h($f['original_name'] ?: $f['label']) ?></a><?php endforeach; ?>
    <?php if ($t['resolution'] && in_array($t['status'], ['resolved', 'verified', 'closed'], true)): ?>
      <div class="pp-alert pp-alert-ok mt-3"><i class="bi bi-check2-circle"></i><div><strong>What we did:</strong> <?= h($t['resolution']) ?></div></div>
    <?php endif; ?>
    <h3 class="pp-sub mt-4">Updates</h3>
    <ol class="pp-timeline">
      <?php foreach ($notes as $n): $mine = $n['portal_user_id'] !== null; ?>
      <li class="<?= $mine ? 'is-mine' : '' ?>"><div class="pp-tl-meta"><?= $mine ? 'You' : ($n['user_id'] !== null ? 'Support team' : 'Update') ?>, <?= h(fdatetime($n['created_at'])) ?><?= $n['to_status'] ? ': ' . h(IspTickets::STATUSES[$n['to_status']] ?? $n['to_status']) : '' ?></div>
        <div class="pp-tl-text"><?= nl2br(h(preg_replace('/^Subscriber: /', '', (string)$n['note']))) ?></div></li>
      <?php endforeach; ?>
      <?php if (!$notes): ?><li><div class="pp-tl-text text-muted">We received your ticket. Updates from our team appear here.</div></li><?php endif; ?>
    </ol>
  </section>
  <aside class="pp-reveal">
    <?php if ($wo): ?>
    <div class="pp-card mb-3"><h2 class="pp-sec">Technician visit</h2>
      <div class="pp-kv"><span>Work order</span><strong><?= h($wo['wo_no']) ?></strong></div>
      <div class="pp-kv"><span>Status</span><strong><?= portal_status($wo['status'], IspWorkOrders::STATUSES) ?></strong></div>
      <?php if ($wo['scheduled_date']): ?><div class="pp-kv"><span>Schedule</span><strong><?= h(fdate($wo['scheduled_date'])) ?> <?= h(IspWorkOrders::SLOTS[$wo['scheduled_slot']] ?? '') ?></strong></div><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (in_array($t['status'], ['resolved', 'verified'], true)): ?>
    <form class="pp-card mb-3" method="post" action="<?= h(IspPortal::url('ticket', ['id' => (int)$t['id']])) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="confirm">
      <h2 class="pp-sec">Is it fixed?</h2>
      <div class="pp-stars" role="radiogroup" aria-label="Rating"><?php for ($s = 5; $s >= 1; $s--): ?><input type="radio" id="r<?= $s ?>" name="rating" value="<?= $s ?>" <?= $s === 5 ? 'checked' : '' ?>><label for="r<?= $s ?>" title="<?= $s ?> of 5"><i class="bi bi-star-fill"></i></label><?php endfor; ?></div>
      <textarea class="form-control form-control-sm mt-2" name="note" rows="2" maxlength="255" placeholder="Comment (optional)"></textarea>
      <button class="btn btn-primary btn-sm mt-2">Yes, close the ticket</button>
    </form>
    <form class="pp-card mb-3" method="post" action="<?= h(IspPortal::url('ticket', ['id' => (int)$t['id']])) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="reopen">
      <h2 class="pp-sec">Still not working?</h2>
      <textarea class="form-control form-control-sm" name="note" rows="2" maxlength="1000" required placeholder="What is still wrong"></textarea>
      <button class="btn btn-outline-danger btn-sm mt-2">Open it again</button>
    </form>
    <?php elseif ($t['status'] !== 'closed'): ?>
    <form class="pp-card" method="post" action="<?= h(IspPortal::url('ticket', ['id' => (int)$t['id']])) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="reply">
      <h2 class="pp-sec">Add a message</h2>
      <textarea class="form-control" name="note" rows="3" maxlength="2000" required></textarea>
      <button class="btn btn-primary btn-sm mt-2"><i class="bi bi-send"></i> Send</button>
    </form>
    <?php else: ?>
    <div class="pp-card"><p class="mb-2">This ticket is closed<?= $t['feedback_rating'] ? ', rated ' . (int)$t['feedback_rating'] . ' of 5' : '' ?>.</p>
      <a class="btn btn-outline-primary btn-sm" href="<?= h(IspPortal::url('tickets', ['account' => (int)$a['id'], 'new' => 1])) ?>">Report a new problem</a></div>
    <?php endif; ?>
  </aside>
</div>
<?php
portal_end();
