<?php
declare(strict_types=1);

defined('APP_PORTAL') || exit;   // opened only through portal/index.php

$id = (int)g('id');
[$in, $ip] = DB::in('st', PORTAL_BILL_STATUSES);
$inv = DB::row("SELECT * FROM isp_invoices WHERE id = :id AND status IN $in", ['id' => $id] + $ip);
if (!$inv) {
    http_response_code(404);
    fail('That bill was not found.');
}
$a = IspPortal::account((int)$inv['account_id']);
$lines = DB::all('SELECT * FROM isp_invoice_lines WHERE invoice_id = :i ORDER BY line_no, id', ['i' => $id]);
$paid = DB::all("SELECT al.amount, y.receipt_no, y.pay_date, y.channel FROM isp_payment_allocs al JOIN isp_payments y ON y.id = al.payment_id
                  WHERE al.invoice_id = :i AND al.is_reversed = 0 AND y.status = 'posted' ORDER BY y.pay_date, y.id", ['i' => $id]);
$credits = DB::all("SELECT adj_no, adj_type, amount, reason, approved_at FROM isp_adjustments WHERE invoice_id = :i AND status = 'posted' AND adj_type <> 'debit' ORDER BY id", ['i' => $id]);
$vatReg = (int)$inv['vat_registered'] === 1;
$vatable = $exempt = $zero = 0.0;
foreach ($lines as $l) {
    $m = (string)$l['vat_mode'];
    if (!$vatReg || $m === 'exempt') {
        $exempt += (float)$l['amount'];
    } elseif ($m === 'zero') {
        $zero += (float)$l['amount'];
    } else {
        $vatable += (float)$l['amount'];
    }
}
$cust = IspAccounts::customer((int)$a['isp_customer_id']);
$prev = (float)$inv['previous_balance'];
$atp = IspSetup::get('isp_bir_atp');
$series = IspSetup::get('isp_bir_series');
$open = in_array($inv['status'], IspBilling::OPEN, true) && (float)$inv['balance'] > 0.004;
$q = ['account' => (int)$a['id']];
portal_start('Bill ' . $inv['invoice_no'], ['print' => true, 'back' => [IspPortal::url('invoices', $q), 'Bills'],
    'actions' => ($open && IspGateway::enabled() ? '<a class="btn btn-sm btn-primary" href="' . h(IspPortal::url('pay', $q + ['amount' => $inv['balance']])) . '"><i class="bi bi-credit-card"></i> Pay online</a> ' : '')
        . ($open ? '<a class="btn btn-sm btn-outline-light" href="' . h(IspPortal::url('paid', $q + ['amount' => $inv['balance']])) . '"><i class="bi bi-qr-code-scan"></i> I paid</a> ' : '')
        . '<button class="btn btn-sm btn-outline-light" type="button" data-print><i class="bi bi-printer"></i> Print / save PDF</button>']);
?>
<article class="isp-doc pp-doc pp-reveal">
  <?= portal_letterhead(IspSetup::get('isp_invoice_title') ?: 'SERVICE INVOICE', '<div class="doc-no">No. <strong>' . h($inv['invoice_no']) . '</strong></div>'
      . ($inv['status'] === 'voided' ? '<div class="doc-void">CANCELLED</div>' : '')) ?>
  <section class="doc-parties">
    <div><span class="doc-k">Billed to</span><strong><?= h($cust['name'] ?? $a['account_name']) ?></strong>
      <span><?= h((string)($cust['billing_address'] ?: $a['install_address'])) ?></span>
      <?php if (!empty($cust['tin'])): ?><span>TIN <?= h($cust['tin']) ?></span><?php endif; ?></div>
    <div class="doc-facts">
      <div><span class="doc-k">Account no.</span><strong><?= h($a['account_no']) ?></strong></div>
      <div><span class="doc-k">Bill date</span><strong><?= h(fdate($inv['invoice_date'])) ?></strong></div>
      <?php if ($inv['period_start']): ?><div><span class="doc-k">Service period</span><strong><?= h(fdate($inv['period_start']) . ' - ' . fdate($inv['period_end'])) ?></strong></div><?php endif; ?>
      <div><span class="doc-k">Due date</span><strong><?= h(fdate($inv['due_date'])) ?></strong></div>
      <div><span class="doc-k">Plan</span><strong><?= h($a['plan_name']) ?></strong></div>
    </div>
  </section>
  <div class="table-responsive">
  <table class="table table-sm doc-lines">
    <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Amount</th><?php if ($vatReg): ?><th class="num">VAT</th><?php endif; ?><th class="num">Total</th></tr></thead>
    <tbody>
    <?php foreach ($lines as $l): ?>
      <tr><td><?= h($l['description']) ?><?php if ($l['period_start'] && $l['period_start'] !== $inv['period_start']): ?><div class="small text-muted"><?= h(fdate($l['period_start']) . ' - ' . fdate($l['period_end'])) ?></div><?php endif; ?></td>
        <td class="num"><?= h(qty($l['qty'])) ?></td><td class="num"><?= h(money($l['amount'])) ?></td><?php if ($vatReg): ?><td class="num"><?= h(money($l['vat_amount'], true)) ?></td><?php endif; ?>
        <td class="num"><?= h(money((float)$l['amount'] + (float)$l['vat_amount'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <section class="doc-sums">
    <div class="doc-vat">
      <?php if ($vatReg): ?>
      <div><span>VATable sales</span><span><?= h(money($vatable)) ?></span></div>
      <div><span>VAT-exempt sales</span><span><?= h(money($exempt)) ?></span></div>
      <div><span>Zero-rated sales</span><span><?= h(money($zero)) ?></span></div>
      <div><span>VAT (<?= h(rtrim(rtrim(number_format(IspSetup::vatRate(), 2), '0'), '.')) ?>%)</span><span><?= h(money($inv['vat_total'])) ?></span></div>
      <?php else: ?>
      <div><span>Non-VAT sales</span><span><?= h(money($inv['total'])) ?></span></div>
      <?php endif; ?>
    </div>
    <div class="doc-total">
      <?php if ((float)$inv['discount_total'] > 0.004): ?><div><span>Discounts included</span><span><?= h(money($inv['discount_total'])) ?></span></div><?php endif; ?>
      <div class="doc-strong"><span>Current charges</span><span><?= h(money($inv['total'])) ?></span></div>
      <?php if (abs($prev) > 0.004): ?><div><span><?= $prev > 0 ? 'Previous unpaid balance' : 'Advance credit' ?> (at billing)</span><span><?= h(money($prev)) ?></span></div>
      <div class="doc-strong"><span>Total amount due at billing</span><span><?= h(money(max(0, $prev + (float)$inv['total']))) ?></span></div><?php endif; ?>
      <?php foreach ($paid as $p): ?><div><span>Paid <?= h(fdate($p['pay_date'])) ?>, <?= h($p['receipt_no']) ?></span><span>(<?= h(money($p['amount'])) ?>)</span></div><?php endforeach; ?>
      <?php foreach ($credits as $cr): ?><div><span><?= h(IspCredit::ADJ_TYPES[$cr['adj_type']] ?? 'Credit') ?> <?= h($cr['adj_no']) ?></span><span>(<?= h(money($cr['amount'])) ?>)</span></div><?php endforeach; ?>
      <div class="doc-grand"><span>Balance of this bill</span><span><?= h(money($inv['balance'])) ?></span></div>
    </div>
  </section>
  <footer class="doc-foot">
    <?php if (($instr = IspSetup::get('isp_payment_instructions')) !== ''): ?><p><strong>How to pay.</strong> <?= nl2br(h($instr)) ?></p><?php endif; ?>
    <?php if (($foot = IspSetup::get('isp_invoice_footer')) !== ''): ?><p><?= nl2br(h($foot)) ?></p><?php endif; ?>
    <?php if ($atp !== '' || $series !== ''): ?><p class="small"><?= $atp !== '' ? 'BIR Authority to Print / CAS Permit No. ' . h($atp) . '. ' : '' ?><?= $series !== '' ? 'Approved series ' . h($series) . '.' : '' ?></p><?php endif; ?>
  </footer>
</article>
<?php
portal_end();
