<?php
declare(strict_types=1);

defined('APP_PORTAL') || exit;   // opened only through portal/index.php

$a = portal_account();
if (!$a) {
    redirect(IspPortal::url('home'));
}
$rows = DB::all('SELECT * FROM isp_payments WHERE account_id = :a ORDER BY pay_date DESC, id DESC LIMIT 120', ['a' => $a['id']]);
$labels = ['pending' => 'Waiting for verification', 'posted' => 'Confirmed', 'rejected' => 'Not accepted', 'reversed' => 'Reversed'];
portal_start('Payments', ['actions' => portal_account_switch($a, 'payments') . '<a class="btn btn-sm btn-primary" href="' . h(IspPortal::url('paid', ['account' => (int)$a['id']])) . '"><i class="bi bi-plus-lg"></i> Report a payment</a>']);
?>
<div class="pp-card pp-reveal">
<?php if (!$rows): ?>
  <?= portal_empty('No payments yet.', 'wallet2') ?>
<?php else: ?>
  <div class="pp-list">
  <?php foreach ($rows as $r): ?>
    <a class="pp-row" href="<?= h(IspPortal::url('receipt', ['id' => (int)$r['id']])) ?>">
      <div class="pp-row-main"><strong><?= h($r['receipt_no']) ?></strong>
        <span class="pp-row-sub"><?= h(fdate($r['pay_date'])) ?>, <?= h(IspPayments::CHANNELS[$r['channel']] ?? $r['channel']) ?><?= $r['reference_no'] ? ' ref. ' . h($r['reference_no']) : '' ?></span></div>
      <div class="pp-row-mid"><?= portal_status($r['status'], $labels) ?><?php if ($r['status'] === 'rejected' && $r['rejected_reason']): ?><span class="pp-row-sub"><?= h($r['rejected_reason']) ?></span><?php endif; ?></div>
      <div class="pp-row-num"><strong><?= h(money($r['amount'])) ?></strong><?php if ($r['status'] === 'posted' && (float)$r['unapplied_amount'] > 0.004): ?><span class="pp-row-sub">Credit <?= h(money($r['unapplied_amount'])) ?></span><?php endif; ?></div>
      <i class="bi bi-chevron-right pp-row-go"></i>
    </a>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>
<?php
portal_end();
