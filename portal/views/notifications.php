<?php
declare(strict_types=1);

defined('APP_PORTAL') || exit;   // opened only through portal/index.php

$c = portal_customer();
portal_post(function () use ($c) {
    if ((string)p('action') === 'read_all') {
        DB::run("UPDATE isp_notifications SET read_at = :t WHERE isp_customer_id = :c AND channel = 'inapp' AND read_at IS NULL", ['t' => date('Y-m-d H:i:s'), 'c' => $c['id']]);
        redirect(IspPortal::url('notifications'));
    }
    DB::run("UPDATE isp_notifications SET read_at = :t WHERE id = :id AND isp_customer_id = :c AND channel = 'inapp' AND read_at IS NULL", ['t' => date('Y-m-d H:i:s'), 'id' => (int)p('id'), 'c' => $c['id']]);
    redirect(IspPortal::url('notifications'));
});
$rows = array_values(array_filter(IspNotify::inbox((int)$c['id'], 60), fn($n) => in_array($n['status'], ['sent', 'queued'], true)));
$unread = count(array_filter($rows, fn($n) => !$n['read_at']));
$icons = ['invoice_generated' => 'receipt', 'due_reminder' => 'calendar-event', 'payment_overdue' => 'exclamation-circle', 'final_notice' => 'exclamation-octagon',
    'service_disconnected' => 'plug', 'payment_received' => 'check-circle', 'service_restored' => 'wifi', 'network_outage' => 'cone-striped', 'service_maintenance' => 'tools',
    'technician_dispatched' => 'truck', 'ticket_resolved' => 'check2-all', 'service_activated' => 'router', 'contract_expiration' => 'file-earmark-text', 'payment_pending' => 'hourglass-split'];
portal_start('Notifications', ['actions' => $unread ? '<form method="post" action="' . h(IspPortal::url('notifications')) . '">' . csrf_field() . '<input type="hidden" name="action" value="read_all"><button class="btn btn-sm btn-outline-light"><i class="bi bi-check2-all"></i> Mark all as read</button></form>' : '']);
?>
<div class="pp-card pp-reveal">
<?php if (!$rows): ?><?= portal_empty('No notifications yet.', 'bell') ?><?php endif; ?>
  <ul class="pp-inbox">
  <?php foreach ($rows as $n): ?>
    <li class="<?= $n['read_at'] ? '' : 'is-new' ?>">
      <span class="pp-inbox-ico"><i class="bi bi-<?= h($icons[$n['event_code']] ?? 'bell') ?>"></i></span>
      <div class="pp-inbox-body">
        <?php if ($n['subject']): ?><strong><?= h($n['subject']) ?></strong><?php endif; ?>
        <p><?= nl2br(h($n['body'])) ?></p>
        <span class="pp-row-sub"><?= h(fdatetime($n['created_at'])) ?></span>
      </div>
      <?php if (!$n['read_at']): ?>
      <form method="post" action="<?= h(IspPortal::url('notifications')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><button class="btn btn-link btn-sm" title="Mark as read" aria-label="Mark as read"><i class="bi bi-check2"></i></button></form>
      <?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ul>
</div>
<?php
portal_end();
