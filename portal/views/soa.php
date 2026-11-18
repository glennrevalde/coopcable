<?php
declare(strict_types=1);

defined('APP_PORTAL') || exit;   // opened only through portal/index.php

$a = portal_account();
if (!$a) {
    redirect(IspPortal::url('home'));
}
$to = valid_date(g('to')) ? (string)g('to') : today();
$from = valid_date(g('from')) ? (string)g('from') : date('Y-m-01', strtotime('-5 months', strtotime(date('Y-m-01'))));
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
[$opening, $rows] = portal_statement((int)$a['id'], $from, $to);
$closing = $rows ? end($rows)['balance'] : $opening;
$cust = IspAccounts::customer((int)$a['isp_customer_id']);
if (g('csv') === '1') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $out = [['', '', 'Opening balance', '', '', '', number_format($opening, 2, '.', '')]];
    foreach ($rows as $r) {
        $out[] = [$r['date'], $r['doc'], $r['what'], $r['memo'], $r['charge'] ? number_format($r['charge'], 2, '.', '') : '', $r['credit'] ? number_format($r['credit'], 2, '.', '') : '', number_format($r['balance'], 2, '.', '')];
    }
    csv_download('statement_' . $a['account_no'] . '_' . $from . '_' . $to . '.csv', ['Date', 'Document', 'Entry', 'Details', 'Charges', 'Payments and credits', 'Balance'], $out);
}
$q = ['account' => (int)$a['id'], 'from' => $from, 'to' => $to];
$aging = DB::row("SELECT COALESCE(SUM(CASE WHEN due_date >= :d0 THEN balance END), 0) AS cur,
        COALESCE(SUM(CASE WHEN due_date < :d1 AND DATEDIFF(:d2, due_date) <= 30 THEN balance END), 0) AS d30,
        COALESCE(SUM(CASE WHEN DATEDIFF(:d3, due_date) BETWEEN 31 AND 60 THEN balance END), 0) AS d60,
        COALESCE(SUM(CASE WHEN DATEDIFF(:d4, due_date) > 60 THEN balance END), 0) AS d61
    FROM isp_invoices WHERE account_id = :a AND status IN ('posted', 'partially_paid', 'overdue', 'disputed') AND balance > 0",
    ['a' => $a['id'], 'd0' => today(), 'd1' => today(), 'd2' => today(), 'd3' => today(), 'd4' => today()]);
portal_start('Statement of account', ['print' => true, 'back' => [IspPortal::url('invoices', ['account' => (int)$a['id']]), 'Bills'],
    'actions' => portal_account_switch($a, 'soa', ['from' => $from, 'to' => $to])
        . '<a class="btn btn-sm btn-outline-light" href="' . h(IspPortal::url('soa', $q + ['csv' => 1])) . '"><i class="bi bi-filetype-csv"></i> Download CSV</a> '
        . '<button class="btn btn-sm btn-outline-light" type="button" data-print><i class="bi bi-printer"></i> Print / save PDF</button>']);
?>
<form class="pp-filter no-print pp-reveal" method="get" action="<?= h(base_url() . '/portal/index.php') ?>">
  <input type="hidden" name="v" value="soa"><input type="hidden" name="account" value="<?= (int)$a['id'] ?>">
  <div><label class="form-label" for="f">From</label><input class="form-control form-control-sm" type="date" id="f" name="from" value="<?= h($from) ?>"></div>
  <div><label class="form-label" for="t">To</label><input class="form-control form-control-sm" type="date" id="t" name="to" value="<?= h($to) ?>"></div>
  <div class="pp-filter-go"><button class="btn btn-sm btn-primary">Show</button></div>
</form>
<article class="isp-doc pp-doc pp-reveal">
  <?= portal_letterhead('STATEMENT OF ACCOUNT', '<div class="doc-no">' . h(fdate($from)) . ' to ' . h(fdate($to)) . '</div>') ?>
  <section class="doc-parties">
    <div><span class="doc-k">Subscriber</span><strong><?= h($cust['name'] ?? $a['account_name']) ?></strong><span><?= h((string)(($cust['billing_address'] ?? '') ?: $a['install_address'])) ?></span></div>
    <div class="doc-facts">
      <div><span class="doc-k">Account no.</span><strong><?= h($a['account_no']) ?></strong></div>
      <div><span class="doc-k">Plan</span><strong><?= h($a['plan_name']) ?></strong></div>
      <div><span class="doc-k">Service</span><strong><?= h(IspAccounts::SERVICE_STATUSES[$a['service_status']] ?? $a['service_status']) ?></strong></div>
      <div><span class="doc-k">Printed</span><strong><?= h(fdatetime(date('Y-m-d H:i:s'))) ?></strong></div>
    </div>
  </section>
  <div class="table-responsive">
  <table class="table table-sm doc-lines">
    <thead><tr><th>Date</th><th>Document</th><th>Entry</th><th class="num">Charges</th><th class="num">Payments / credits</th><th class="num">Balance</th></tr></thead>
    <tbody>
      <tr class="doc-open"><td><?= h(fdate($from)) ?></td><td></td><td>Balance brought forward</td><td></td><td></td><td class="num"><?= h(money($opening)) ?></td></tr>
      <?php foreach ($rows as $r): ?>
      <tr><td><?= h(fdate($r['date'])) ?></td><td><?= h($r['doc']) ?></td><td><?= h($r['what']) ?><?php if ($r['memo'] !== ''): ?><div class="small text-muted"><?= h(mb_strimwidth($r['memo'], 0, 90, '...')) ?></div><?php endif; ?></td>
        <td class="num"><?= h(money($r['charge'], true)) ?></td><td class="num"><?= h(money($r['credit'], true)) ?></td><td class="num"><?= h(money($r['balance'])) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-muted">No bills, payments or credits in this period.</td></tr><?php endif; ?>
    </tbody>
    <tfoot><tr><th colspan="3">Balance as of <?= h(fdate($to)) ?><?= $closing < -0.004 ? ' (advance credit)' : '' ?></th>
      <th class="num"><?= h(money(array_sum(array_column($rows, 'charge')))) ?></th><th class="num"><?= h(money(array_sum(array_column($rows, 'credit')))) ?></th><th class="num"><?= h(money($closing)) ?></th></tr></tfoot>
  </table>
  </div>
  <section class="doc-aging">
    <div><span>Not yet due</span><strong><?= h(money($aging['cur'])) ?></strong></div>
    <div><span>1-30 days overdue</span><strong><?= h(money($aging['d30'])) ?></strong></div>
    <div><span>31-60 days overdue</span><strong><?= h(money($aging['d60'])) ?></strong></div>
    <div><span>Over 60 days overdue</span><strong><?= h(money($aging['d61'])) ?></strong></div>
  </section>
  <footer class="doc-foot">
    <?php if (($instr = IspSetup::get('isp_payment_instructions')) !== ''): ?><p><strong>How to pay.</strong> <?= nl2br(h($instr)) ?></p><?php endif; ?>
    <p class="small">This statement lists the bills, payments and credits recorded on your account. Payments waiting for verification are not included until they are confirmed.</p>
  </footer>
</article>
<?php
portal_end();
