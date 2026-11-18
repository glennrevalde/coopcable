<?php
declare(strict_types=1);

defined('APP_PORTAL') || exit;   // opened only through portal/index.php

$p = DB::row('SELECT * FROM isp_payments WHERE id = :id', ['id' => (int)g('id')]);
if (!$p) {
    http_response_code(404);
    fail('That payment was not found.');
}
$a = IspPortal::account((int)$p['account_id']);
$allocs = DB::all('SELECT al.amount, i.invoice_no, i.period_start, i.period_end, i.invoice_date FROM isp_payment_allocs al JOIN isp_invoices i ON i.id = al.invoice_id
                    WHERE al.payment_id = :p AND al.is_reversed = 0 ORDER BY i.invoice_date, i.id', ['p' => $p['id']]);
$files = portal_files('payment', (int)$p['id']);
$cust = IspAccounts::customer((int)$a['isp_customer_id']);
$posted = $p['status'] === 'posted';
$title = $posted ? (IspSetup::get('isp_receipt_title') ?: 'COLLECTION RECEIPT') : (IspSetup::get('isp_ack_title') ?: 'PAYMENT ACKNOWLEDGMENT');
$labels = ['pending' => 'Waiting for verification', 'posted' => 'Confirmed', 'rejected' => 'Not accepted', 'reversed' => 'Reversed'];
portal_start('Payment ' . $p['receipt_no'], ['print' => true, 'back' => [IspPortal::url('payments', ['account' => (int)$a['id']]), 'Payments'],
    'actions' => '<button class="btn btn-sm btn-outline-light" type="button" data-print><i class="bi bi-printer"></i> Print / save PDF</button>']);
?>
<?php if ($p['status'] === 'pending'): ?>
  <div class="pp-alert pp-alert-info no-print"><i class="bi bi-hourglass-split"></i><div>We received your payment details. Our staff checks them against the <?= h(IspPayments::CHANNELS[$p['channel']] ?? '') ?> records; the payment is applied to your bills once confirmed. This acknowledgment is not an official receipt.</div></div>
<?php elseif ($p['status'] === 'rejected'): ?>
  <div class="pp-alert pp-alert-bad no-print"><i class="bi bi-x-octagon"></i><div>This payment could not be confirmed<?= $p['rejected_reason'] ? ': ' . h($p['rejected_reason']) : '' ?>. Please call our office if you have paid.</div></div>
<?php endif; ?>
<article class="isp-doc pp-doc pp-receipt pp-reveal">
  <?= portal_letterhead($title, '<div class="doc-no">No. <strong>' . h($p['receipt_no']) . '</strong></div>' . ($p['status'] === 'reversed' ? '<div class="doc-void">REVERSED</div>' : '')) ?>
  <section class="doc-parties">
    <div><span class="doc-k">Received from</span><strong><?= h($cust['name'] ?? $a['account_name']) ?></strong><span><?= h((string)(($cust['billing_address'] ?? '') ?: $a['install_address'])) ?></span>
      <?php if (!empty($cust['tin'])): ?><span>TIN <?= h($cust['tin']) ?></span><?php endif; ?></div>
    <div class="doc-facts">
      <div><span class="doc-k">Account no.</span><strong><?= h($a['account_no']) ?></strong></div>
      <div><span class="doc-k">Date paid</span><strong><?= h(fdate($p['pay_date'])) ?></strong></div>
      <div><span class="doc-k">Paid by</span><strong><?= h(IspPayments::CHANNELS[$p['channel']] ?? $p['channel']) ?></strong></div>
      <?php if ($p['reference_no']): ?><div><span class="doc-k">Reference</span><strong><?= h($p['reference_no']) ?></strong></div><?php endif; ?>
      <?php if ($p['check_no']): ?><div><span class="doc-k">Check</span><strong><?= h(trim($p['check_bank'] . ' ' . $p['check_no'])) ?></strong></div><?php endif; ?>
      <div><span class="doc-k">Status</span><strong><?= portal_status($p['status'], $labels) ?></strong></div>
    </div>
  </section>
  <div class="doc-amount"><span>Amount</span><strong><?= h(money($p['amount'])) ?></strong><em><?= h(amount_in_words((float)$p['amount'])) ?></em></div>
  <?php if ($allocs): ?>
  <table class="table table-sm doc-lines">
    <thead><tr><th>Applied to bill</th><th>Period</th><th class="num">Amount</th></tr></thead>
    <tbody><?php foreach ($allocs as $al): ?><tr><td><?= h($al['invoice_no']) ?></td><td><?= $al['period_start'] ? h(fdate($al['period_start']) . ' - ' . fdate($al['period_end'])) : h(fdate($al['invoice_date'])) ?></td><td class="num"><?= h(money($al['amount'])) ?></td></tr><?php endforeach; ?></tbody>
  </table>
  <?php endif; ?>
  <?php if ($posted && (float)$p['unapplied_amount'] > 0.004): ?><p class="small">Advance credit kept for your next bills: <strong><?= h(money($p['unapplied_amount'])) ?></strong>.</p><?php endif; ?>
  <?php if ($files): ?>
  <div class="no-print pp-files"><span class="doc-k">Proof sent</span>
    <?php foreach ($files as $f): ?><a href="<?= h(IspPortal::url('file', ['id' => (int)$f['id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-paperclip"></i> <?= h($f['original_name'] ?: $f['label']) ?></a><?php endforeach; ?></div>
  <?php endif; ?>
  <footer class="doc-foot"><p class="small"><?= $posted ? 'Thank you for your payment.' : 'Not valid as an official receipt.' ?> <?= h(fdatetime($p['received_at'] ?: $p['created_at'])) ?></p></footer>
</article>
<?php
portal_end();
