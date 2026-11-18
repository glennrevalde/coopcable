<?php
declare(strict_types=1);

defined('APP_PORTAL') || exit;   // opened only through portal/index.php

$a = portal_account();
if (!$a) {
    redirect(IspPortal::url('home'));
}
[$in, $ip] = DB::in('st', PORTAL_BILL_STATUSES);
$rows = DB::all("SELECT * FROM isp_invoices WHERE account_id = :a AND status IN $in ORDER BY invoice_date DESC, id DESC LIMIT 120", ['a' => $a['id']] + $ip);
$q = ['account' => (int)$a['id']];
portal_start('Bills', ['actions' => portal_account_switch($a, 'invoices') . '<a class="btn btn-sm btn-outline-light" href="' . h(IspPortal::url('soa', $q)) . '"><i class="bi bi-file-earmark-text"></i> Statement</a>']);
?>
<div class="pp-summary pp-reveal">
  <div><span>Account</span><strong><?= h($a['account_no']) ?></strong></div>
  <div><span>Open bills</span><strong><?= h(money($a['open_invoices'])) ?></strong></div>
  <?php if ((float)$a['credit'] > 0.004): ?><div><span>Advance credit</span><strong><?= h(money($a['credit'])) ?></strong></div><?php endif; ?>
  <div class="<?= (float)$a['overdue'] > 0.004 ? 'is-bad' : '' ?>"><span>Overdue</span><strong><?= h(money($a['overdue'])) ?></strong></div>
</div>
<div class="pp-card pp-reveal">
<?php if (!$rows): ?>
  <?= portal_empty('No bills yet. Your first bill is issued when your service is activated.', 'receipt') ?>
<?php else: ?>
  <div class="pp-list">
  <?php foreach ($rows as $r): $open = in_array($r['status'], IspBilling::OPEN, true) && (float)$r['balance'] > 0.004; ?>
    <a class="pp-row" href="<?= h(IspPortal::url('invoice', ['id' => (int)$r['id']])) ?>">
      <div class="pp-row-main">
        <strong><?= h($r['invoice_no']) ?></strong>
        <span class="pp-row-sub"><?= $r['period_start'] ? h(fdate($r['period_start']) . ' to ' . fdate($r['period_end'])) : h(ucfirst((string)$r['kind']) . ' bill') ?>, issued <?= h(fdate($r['invoice_date'])) ?></span>
      </div>
      <div class="pp-row-mid"><?= portal_status($r['status'] === 'posted' && $r['due_date'] < today() && (float)$r['balance'] > 0.004 ? 'overdue' : $r['status'], PORTAL_BILL_LABELS) ?>
        <span class="pp-row-sub"><?= $open ? 'Due ' . h(fdate($r['due_date'])) : '' ?></span></div>
      <div class="pp-row-num"><strong><?= h(money($r['total'])) ?></strong><span class="pp-row-sub"><?= $open ? 'Balance ' . h(money($r['balance'])) : ($r['status'] === 'voided' ? 'Cancelled' : 'Settled') ?></span></div>
      <i class="bi bi-chevron-right pp-row-go"></i>
    </a>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>
<?php
portal_end();
