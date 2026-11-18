<?php
declare(strict_types=1);

/**
 * Invoices: every subscriber invoice (drafts of a run, posted, paid, overdue, disputed, voided), the invoice with its
 * lines, VAT, payments and credits, the printed SERVICE INVOICE (one, or all of a run), the void of an invoice nothing
 * was paid on, and the pending one-time charges (added here, billed on the next invoice or at once).
 */
require_once __DIR__ . '/_lib.php';

$pk = 'ispbill.invoices';
$id = gint('id');
$a = (string)g('a');
const ISPBILL_KINDS = ['regular' => 'Monthly', 'initial' => 'Initial (activation)', 'final' => 'Final (termination)', 'manual' => 'One-time charges'];
const ISPBILL_CHARGE_TYPES = ['one_time' => 'One-time charge', 'installation' => 'Installation / activation', 'equipment' => 'Equipment', 'reconnection_fee' => 'Reconnection fee',
    'router_rental' => 'Router rental', 'addon' => 'Add-on service', 'late_fee' => 'Late payment charge', 'termination_fee' => 'Early termination fee'];

handle_post(function () use ($pk) {
    $action = (string)p('action');
    if ($action === 'void') {
        Access::require($pk, 'delete');
        $iid = (int)p('id');
        IspBilling::void($iid, trim((string)p('reason')), $pk);
        redirect(url($pk, ['id' => $iid]), 'Invoice voided. Its period can be billed again.', 'success');
    }
    if ($action === 'add_charge') {
        Access::require($pk, 'create');
        $acct = IspUi::postedAccount('account_id') ?? fail('Choose the subscriber account.');
        $type = (string)p('charge_type');
        if (!isset(ISPBILL_CHARGE_TYPES[$type])) {
            fail('Choose the kind of charge.');
        }
        $desc = trim((string)p('description'));
        if ($desc === '') {
            fail('Describe the charge (it is printed on the invoice).');
        }
        $amount = pnum('amount');
        if ($amount <= 0) {
            fail('Enter the amount of the charge. A credit to the subscriber is an adjustment (Adjustments & Write-offs).');
        }
        $mode = (string)p('vat_mode');
        if (!isset(IspPlans::VAT_MODES[$mode])) {
            $mode = 'inclusive';
        }
        $date = pdate('charge_date') ?? today();
        $cid = IspBilling::addCharge($acct, $type, $desc, $amount, $date, 'MAN-' . bin2hex(random_bytes(8)), $mode, null, 'manual', null);
        Audit::log('create', 'ispbill', 'isp_charge', $cid, $desc, ['amount' => $amount, 'page_key' => $pk]);
        if (p('bill_now') === '1') {
            $inv = IspBilling::chargesInvoice($acct, $date);
            redirect(url($pk, ['id' => $inv]), 'Charge added and billed on invoice ' . DB::value('SELECT invoice_no FROM isp_invoices WHERE id = :i', ['i' => $inv]) . '.', 'success');
        }
        redirect(url($pk, ['tab' => 'charges']), 'Charge added: it is billed on the next invoice of the account.', 'success');
    }
    if ($action === 'bill_now') {
        Access::require($pk, 'create');
        $acct = (int)p('account_id');
        $inv = IspBilling::chargesInvoice($acct, today());
        redirect(url($pk, ['id' => $inv]), 'Pending charges billed on invoice ' . DB::value('SELECT invoice_no FROM isp_invoices WHERE id = :i', ['i' => $inv]) . '.', 'success');
    }
    if ($action === 'cancel_charge') {
        Access::require($pk, 'delete');
        if (trim((string)p('reason')) === '') {
            fail('Enter why the charge is cancelled.');
        }
        IspBilling::cancelCharge((int)p('charge_id'), trim((string)p('reason')));
        Audit::log('cancel', 'ispbill', 'isp_charge', (int)p('charge_id'), '', ['reason' => trim((string)p('reason'))]);
        redirect(url($pk, ['tab' => 'charges']), 'Charge cancelled.', 'success');
    }
});

/** One printed SERVICE INVOICE */
function ispbill_invoice_doc(array $inv): string
{
    $acct = IspAccounts::get((int)$inv['account_id']);
    $cust = IspAccounts::customer((int)$acct['isp_customer_id']);
    $lines = DB::all('SELECT * FROM isp_invoice_lines WHERE invoice_id = :i ORDER BY line_no', ['i' => $inv['id']]);
    $vatReg = (int)$inv['vat_registered'] === 1;
    $vatable = $exempt = $zero = 0.0;
    foreach ($lines as $l) {
        if ($l['vat_mode'] === 'exempt' || $l['line_type'] === 'deposit') {
            $exempt += (float)$l['amount'];
        } elseif ($l['vat_mode'] === 'zero') {
            $zero += (float)$l['amount'];
        } else {
            $vatable += (float)$l['amount'];
        }
    }
    $draft = in_array($inv['status'], ['draft', 'approved'], true);
    $no = $draft ? 'DRAFT' : (string)$inv['invoice_no'];
    $right = '<div class="doc-no">No. <strong>' . h($no) . '</strong></div><div>Date ' . h(fdate($inv['invoice_date'])) . '</div>';
    $out = '<div class="isp-doc isp-invoice">' . IspUi::letterhead(IspSetup::get('isp_invoice_title') ?: 'SERVICE INVOICE', $right);
    if ($draft) {
        $out .= '<div class="doc-stamp">DRAFT: not a valid invoice until posted</div>';
    } elseif ($inv['status'] === 'voided') {
        $out .= '<div class="doc-stamp">VOID ' . h(fdate($inv['voided_at'])) . ': ' . h((string)$inv['void_reason']) . '</div>';
    }
    $out .= '<div class="row g-2 doc-parties"><div class="col-7"><table class="table table-sm doc-meta mb-0">'
        . '<tr><th style="width:32%">Billed to</th><td><strong>' . h($cust['name'] ?? $acct['account_name']) . '</strong></td></tr>'
        . '<tr><th>Address</th><td>' . h((string)($cust['billing_address'] ?: $acct['install_address'])) . '</td></tr>'
        . '<tr><th>TIN</th><td>' . h((string)($cust['tin'] ?? '')) . '</td></tr>'
        . '<tr><th>Service address</th><td>' . h((string)$acct['install_address']) . '</td></tr></table></div>'
        . '<div class="col-5"><table class="table table-sm doc-meta mb-0">'
        . '<tr><th>Account no.</th><td><strong>' . h($acct['account_no']) . '</strong></td></tr>'
        . '<tr><th>Plan</th><td>' . h((string)$acct['plan_name']) . '</td></tr>'
        . ($inv['period_start'] ? '<tr><th>Billing period</th><td>' . h(fdate($inv['period_start']) . ' - ' . fdate($inv['period_end'])) . '</td></tr>' : '')
        . '<tr><th>Due date</th><td><strong>' . h(fdate($inv['due_date'])) . '</strong></td></tr></table></div></div>';
    $out .= '<table class="table table-sm doc-lines mt-2"><thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead><tbody>';
    foreach ($lines as $l) {
        $gross = r2((float)$l['amount'] + (float)$l['vat_amount']);
        $out .= '<tr><td>' . h($l['description']) . ($vatReg && $l['vat_mode'] === 'exempt' ? ' <span class="small">(VAT-exempt)</span>' : '') . ($vatReg && $l['vat_mode'] === 'zero' ? ' <span class="small">(zero-rated)</span>' : '') . '</td>'
            . '<td class="num">' . h(qty($l['qty'])) . '</td><td class="num">' . ((float)$l['qty'] != 0.0 && (float)$l['qty'] != 1.0 ? money($l['unit_price']) : money($gross)) . '</td><td class="num">' . money($gross) . '</td></tr>';
    }
    $out .= '</tbody></table><div class="row g-3"><div class="col-6 small">';
    $out .= '<div class="doc-pay"><strong>How to pay</strong><br>' . nl2br(h(IspSetup::get('isp_payment_instructions'))) . '</div>';
    if (!$vatReg) {
        $out .= '<p class="mt-2 mb-0">THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAX.</p>';
    }
    $out .= '</div><div class="col-6"><table class="table table-sm doc-totals mb-0">';
    if ($vatReg) {
        $out .= '<tr><td>VATable sales</td><td class="num">' . money($vatable) . '</td></tr><tr><td>VAT (' . h(rtrim(rtrim(number_format(IspSetup::vatRate(), 2), '0'), '.')) . '%)</td><td class="num">' . money($inv['vat_total']) . '</td></tr>'
            . '<tr><td>VAT-exempt sales</td><td class="num">' . money($exempt) . '</td></tr><tr><td>Zero-rated sales</td><td class="num">' . money($zero) . '</td></tr>';
    }
    if ((float)$inv['discount_total'] > 0) {
        $out .= '<tr><td>Discounts included</td><td class="num">(' . money($inv['discount_total']) . ')</td></tr>';
    }
    $out .= '<tr class="doc-total"><th>Current charges</th><th class="num">' . money($inv['total']) . '</th></tr>';
    $prev = r2((float)$inv['previous_balance']);
    if (abs($prev) > 0.004 && !$draft) {
        $out .= '<tr><td>' . ($prev > 0 ? 'Previous unpaid balance' : 'Advance credit on file') . '</td><td class="num">' . money($prev) . '</td></tr>'
            . '<tr class="doc-total"><th>Total amount due</th><th class="num">' . money(r2((float)$inv['total'] + $prev)) . '</th></tr>';
    }
    $out .= '</table></div></div>';
    $out .= '<div class="doc-foot small mt-3">' . nl2br(h(IspSetup::get('isp_invoice_footer'))) . ispbill_bir_foot() . '</div></div>';
    return $out;
}

// ================================================================ printing
if ($a === 'print') {
    Access::require($pk, 'print');
    $runId = gint('run_id');
    if ($runId) {
        $invs = DB::all("SELECT i.* FROM isp_invoices i JOIN isp_accounts a ON a.id = i.account_id WHERE i.run_id = :r AND i.status <> 'voided' ORDER BY a.account_no", ['r' => $runId]);
        $title = 'Invoices of run ' . DB::value('SELECT run_no FROM isp_billing_runs WHERE id = :r', ['r' => $runId]);
    } else {
        $invs = array_filter([DB::row('SELECT * FROM isp_invoices WHERE id = :id', ['id' => (int)$id])]);
        $title = 'Invoice ' . ($invs ? $invs[0]['invoice_no'] : '');
    }
    if (!$invs) {
        fail('No invoice to print.');
    }
    Audit::log('print', 'ispbill', 'isp_invoice', $runId ? null : (int)$id, $title, ['reason' => count($invs) . ' invoice(s)']);
    layout_start($title, ['bare' => true, 'no_title' => true]);
    echo '<div class="no-print d-flex gap-2 mb-3"><button class="btn btn-sm btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print ' . count($invs) . ' invoice' . (count($invs) === 1 ? '' : 's') . '</button>'
        . btn_link($runId ? url('ispbill.runs', ['id' => $runId]) : url($pk, ['id' => $id]), 'Back') . '</div>';
    $last = count($invs) - 1;
    foreach (array_values($invs) as $n => $inv) {
        echo '<div' . ($n < $last ? ' style="page-break-after:always;break-after:page"' : '') . '>' . ispbill_invoice_doc($inv) . '</div>';
    }
    layout_end();
    return;
}

// ================================================================ one invoice
if ($id) {
    $inv = DB::row('SELECT i.*, a.account_no, a.account_name, a.branch_id, r.run_no FROM isp_invoices i JOIN isp_accounts a ON a.id = i.account_id LEFT JOIN isp_billing_runs r ON r.id = i.run_id WHERE i.id = :id', ['id' => $id])
        ?? fail('Invoice not found.');
    if (IspSetup::userBranch() !== null && $inv['branch_id'] && (int)$inv['branch_id'] !== (int)IspSetup::userBranch()) {
        Access::denyPage($pk, 'open another branch\'s invoice');
    }
    $lines = DB::all('SELECT l.*, ac.code AS acode, ac.name AS aname FROM isp_invoice_lines l LEFT JOIN accounts ac ON ac.id = l.income_account_id WHERE l.invoice_id = :i ORDER BY l.line_no', ['i' => $id]);
    $allocs = DB::all('SELECT al.*, p.receipt_no, p.pay_date, p.channel, p.status AS pstatus FROM isp_payment_allocs al JOIN isp_payments p ON p.id = al.payment_id WHERE al.invoice_id = :i ORDER BY al.id', ['i' => $id]);
    $adjs = DB::all('SELECT * FROM isp_adjustments WHERE invoice_id = :i ORDER BY id', ['i' => $id]);
    $disputes = DB::all('SELECT * FROM isp_disputes WHERE invoice_id = :i ORDER BY id', ['i' => $id]);
    $txn = IspPosting::invoiceTxnId($id);
    $open = in_array($inv['status'], IspBilling::OPEN, true);
    $actions = Access::can($pk, 'print') ? btn_link(url($pk, ['id' => $id, 'a' => 'print']), 'Print', 'btn-primary', 'printer') . ' ' : '';
    if ($open && Access::can('ispbill.collect', 'create')) {
        $actions .= btn_link(url('ispbill.collect', ['account_id' => $inv['account_id']]), 'Collect', 'btn-outline-primary', 'cash-coin') . ' ';
    }
    if ($open && Access::can('ispbill.adjustments', 'create')) {
        $actions .= btn_link(url('ispbill.adjustments', ['account_id' => $inv['account_id'], 'invoice_id' => $id]), 'Adjust', 'btn-outline-secondary', 'sliders') . ' ';
    }
    if ($open && Access::can('ispbill.disputes', 'create')) {
        $actions .= btn_link(url('ispbill.disputes', ['account_id' => $inv['account_id'], 'invoice_id' => $id]), 'Dispute', 'btn-outline-secondary', 'chat-left-text') . ' ';
    }
    $voidable = $inv['status'] !== 'voided' && (float)$inv['paid_amount'] <= 0.004 && (float)$inv['credit_amount'] <= 0.004 && !in_array($inv['status'], ['draft', 'approved'], true);
    if ($voidable && Access::can($pk, 'delete')) {
        $actions .= '<button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#voidInv"><i class="bi bi-slash-circle"></i> Void</button>';
    }
    layout_start('Invoice ' . $inv['invoice_no'], ['actions' => $actions]);
    ?>
    <div class="card isp-card mb-3"><div class="card-body">
      <div class="isp-head"><div><h2 class="isp-head-title"><?= money($inv['total']) ?> <span class="fw-normal text-muted"><?= h(ISPBILL_KINDS[$inv['kind']] ?? $inv['kind']) ?> invoice</span></h2>
        <div class="isp-head-sub"><?= IspUi::status($inv['status'], IspBilling::STATUSES) ?> <span class="ms-2"><?= IspUi::acct($inv['account_id'], $inv['account_no'], $inv['account_name']) ?></span></div></div>
        <div class="isp-head-actions"><?= btn_link(url($pk), 'All invoices', 'btn-outline-secondary', 'list-ul') ?></div></div>
      <div class="kpi-grid mt-2">
        <?= IspUi::kpi('Invoice total', money($inv['total']), 'VAT ' . money($inv['vat_total']), null, 'receipt', 'info') ?>
        <?= IspUi::kpi('Paid', money($inv['paid_amount']), count(array_filter($allocs, fn($x) => !(int)$x['is_reversed'])) . ' payment(s)', null, 'check2-circle', 'ok') ?>
        <?= IspUi::kpi('Credited', money($inv['credit_amount']), 'adjustments posted', null, 'sliders', 'idle') ?>
        <?= IspUi::kpi('Open balance', money($open ? $inv['balance'] : 0), $open ? 'due ' . fdate($inv['due_date']) : '', null, 'wallet2', $open ? ($inv['due_date'] < today() ? 'bad' : 'warn') : 'ok') ?>
      </div>
      <?= IspUi::dl(['Invoice no.' => h($inv['invoice_no']), 'Invoice date' => h(fdate($inv['invoice_date'])), 'Due date' => h(fdate($inv['due_date'])),
          'Period' => $inv['period_start'] ? h(fdate($inv['period_start']) . ' - ' . fdate($inv['period_end'])) : '', 'Billing run' => $inv['run_id'] ? safe_link('ispbill.runs', ['id' => $inv['run_id']], (string)$inv['run_no']) : '',
          'Overdue since' => h(fdate($inv['overdue_since'])), 'Previous balance' => abs((float)$inv['previous_balance']) > 0.004 ? money($inv['previous_balance']) : '',
          'Tax treatment' => (int)$inv['vat_registered'] ? 'VAT-registered' : 'Non-VAT', 'Ledger' => ispbill_txn($txn),
          'Posted' => $inv['posted_at'] ? h(fdatetime($inv['posted_at'])) : '', 'Voided' => $inv['voided_at'] ? h(fdatetime($inv['voided_at']) . ': ' . $inv['void_reason']) : '', 'Notes' => h((string)$inv['notes'])]) ?>
    </div></div>
    <div class="card isp-card mb-3"><div class="card-body">
      <h2 class="sec-title">Lines</h2>
      <div class="table-responsive"><table class="table table-sm align-middle isp-table">
        <thead><tr><th>#</th><th>Type</th><th>Description</th><th>VAT</th><th>Income account</th><th class="num">Net</th><th class="num">VAT</th><th class="num">Amount</th></tr></thead>
        <tbody><?php foreach ($lines as $l): ?>
          <tr><td><?= (int)$l['line_no'] ?></td><td class="small"><?= h(IspBilling::LINE_TYPES[$l['line_type']] ?? $l['line_type']) ?></td><td><?= h($l['description']) ?></td>
            <td class="small"><?= h(IspPlans::VAT_MODES[$l['vat_mode']] ?? $l['vat_mode']) ?></td><td class="small"><?= h(trim($l['acode'] . ' ' . $l['aname'])) ?></td>
            <td class="num"><?= money($l['amount']) ?></td><td class="num"><?= money($l['vat_amount']) ?></td><td class="num"><?= money((float)$l['amount'] + (float)$l['vat_amount']) ?></td></tr>
        <?php endforeach; ?></tbody>
        <tfoot><tr><th colspan="5">Total</th><th class="num"><?= money($inv['subtotal']) ?></th><th class="num"><?= money($inv['vat_total']) ?></th><th class="num"><?= money($inv['total']) ?></th></tr></tfoot>
      </table></div>
    </div></div>
    <div class="row g-3">
      <div class="col-lg-6"><div class="card isp-card h-100"><div class="card-body">
        <h2 class="sec-title">Payments applied</h2>
        <?php if (!$allocs): echo IspUi::empty('No payment applied.', 'cash'); else: ?>
        <table class="table table-sm isp-table"><thead><tr><th>Receipt</th><th>Date</th><th>Channel</th><th class="num">Amount</th></tr></thead><tbody>
        <?php foreach ($allocs as $al): ?><tr class="<?= (int)$al['is_reversed'] ? 'text-decoration-line-through text-muted' : '' ?>"><td><?= safe_link('ispbill.payments', ['id' => $al['payment_id']], (string)$al['receipt_no']) ?></td>
          <td><?= h(fdate($al['pay_date'])) ?></td><td class="small"><?= h(IspPayments::CHANNELS[$al['channel']] ?? $al['channel']) ?></td><td class="num"><?= money($al['amount']) ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
      </div></div></div>
      <div class="col-lg-6"><div class="card isp-card h-100"><div class="card-body">
        <h2 class="sec-title">Adjustments and disputes</h2>
        <?php if (!$adjs && !$disputes): echo IspUi::empty('No adjustment or dispute.', 'sliders'); endif; ?>
        <?php if ($adjs): ?><table class="table table-sm isp-table"><thead><tr><th>No.</th><th>Type</th><th>Status</th><th class="num">Amount</th></tr></thead><tbody>
          <?php foreach ($adjs as $x): ?><tr><td><?= safe_link('ispbill.adjustments', ['id' => $x['id']], (string)$x['adj_no']) ?></td><td class="small"><?= h(IspCredit::ADJ_TYPES[$x['adj_type']] ?? $x['adj_type']) ?></td>
            <td><?= IspUi::status($x['status'], IspCredit::ADJ_STATUSES) ?></td><td class="num"><?= money($x['amount']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
        <?php if ($disputes): ?><table class="table table-sm isp-table"><thead><tr><th>Dispute</th><th>Reason</th><th>Status</th><th class="num">Disputed</th></tr></thead><tbody>
          <?php foreach ($disputes as $x): ?><tr><td><?= safe_link('ispbill.disputes', ['id' => $x['id']], (string)$x['dispute_no']) ?></td><td class="small"><?= h($x['reason']) ?></td>
            <td><?= IspUi::status($x['status'], IspCredit::DISPUTE_STATUSES) ?></td><td class="num"><?= money($x['amount_disputed']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
      </div></div></div>
    </div>
    <?php if ($voidable && Access::can($pk, 'delete')): ?>
    <div class="modal fade" id="voidInv" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="id" value="<?= (int)$id ?>">
      <div class="modal-header"><h5 class="modal-title">Void invoice <?= h($inv['invoice_no']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><p class="small">The invoice keeps its number and stays on file marked void; its ledger invoice is voided and its charges become pending again. The period can then be billed again.</p>
        <label class="form-label">Reason (required)</label><textarea class="form-control" name="reason" rows="2" required maxlength="240"></textarea></div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep it</button><button class="btn btn-danger">Void invoice</button></div>
    </form></div></div>
    <?php endif; ?>
    <?php
    layout_end();
    return;
}

// ================================================================ lists
$tab = g('tab') === 'charges' ? 'charges' : 'invoices';
$nCharges = (int)DB::value("SELECT COUNT(*) FROM isp_charges WHERE status = 'pending'");
$actions = page_link('ispbill.runs', [], 'Billing runs', 'btn-outline-secondary', 'calendar3');
layout_start('Invoices', ['actions' => $actions]);
echo IspUi::tabs(['invoices' => 'Invoices', 'charges' => 'Pending charges' . ($nCharges ? ' (' . $nCharges . ')' : '')], $tab, $pk);

if ($tab === 'charges') {
    $p = [];
    $where = "c.status = 'pending'" . IspSetup::branchSql('a.branch_id', $p);
    if (($q = trim((string)g('q'))) !== '') {
        $where .= ' AND (a.account_no LIKE :q1 OR a.account_name LIKE :q2 OR c.description LIKE :q3)';
        $p += ['q1' => "$q%", 'q2' => "%$q%", 'q3' => "%$q%"];
    }
    $rows = DB::all("SELECT c.*, a.account_no, a.account_name FROM isp_charges c JOIN isp_accounts a ON a.id = c.account_id WHERE $where ORDER BY a.account_no, c.charge_date, c.id LIMIT 500", $p);
    if (Access::can($pk, 'create')): ?>
    <div class="card isp-card mb-3"><div class="card-body">
      <h2 class="sec-title">Add a charge</h2>
      <form method="post" class="row g-2 align-items-end">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_charge">
        <?= IspUi::accountField('account_id', IspUi::postedAccount('account_id') ?? gint('account_id'), 'Subscriber account', 'col-md-6') ?>
        <?= select_field('charge_type', 'Kind', ISPBILL_CHARGE_TYPES, fv('charge_type', null, 'one_time'), ['class' => 'col-md-3']) ?>
        <?= input_field('charge_date', 'Date', fv('charge_date', null, today()), ['type' => 'date', 'class' => 'col-md-3']) ?>
        <?= input_field('description', 'Description (printed on the invoice)', fv('description'), ['class' => 'col-md-6', 'required' => true, 'extra' => 'maxlength="250"']) ?>
        <?= input_field('amount', 'Amount', fv('amount'), ['class' => 'col-md-2', 'required' => true, 'extra' => 'inputmode="decimal"']) ?>
        <?= select_field('vat_mode', 'Tax', IspPlans::VAT_MODES, fv('vat_mode', null, 'inclusive'), ['class' => 'col-md-2']) ?>
        <div class="col-md-2"><div class="form-check"><input type="hidden" name="bill_now" value="0"><input class="form-check-input" type="checkbox" name="bill_now" value="1" id="billNow"><label class="form-check-label small" for="billNow">Bill now</label></div></div>
        <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Add charge</button>
          <span class="small text-muted ms-2">Without "Bill now" it goes on the account's next invoice.</span></div>
      </form>
    </div></div>
    <?php endif; ?>
    <?= IspUi::filterStart($pk, ['tab' => 'charges']) . IspUi::fSearch((string)g('q'), 'Account or description') . IspUi::filterEnd(url($pk, ['tab' => 'charges'])) ?>
    <div class="card isp-card"><div class="card-body">
      <?php if (!$rows): echo IspUi::empty('No pending charge.', 'check2-circle'); else: ?>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
        <thead><tr><th>Account</th><th>Date</th><th>Kind</th><th>Description</th><th>Reserved by</th><th class="num">Amount</th><th></th></tr></thead>
        <tbody><?php $lastAcct = null; foreach ($rows as $c): ?>
          <tr><td><?= $lastAcct !== $c['account_id'] ? IspUi::acct($c['account_id'], $c['account_no'], $c['account_name']) : '' ?></td><td><?= h(fdate($c['charge_date'])) ?></td>
            <td class="small"><?= h(IspBilling::LINE_TYPES[$c['charge_type']] ?? str_replace('_', ' ', $c['charge_type'])) ?></td><td><?= h($c['description']) ?></td>
            <td class="small"><?= $c['invoice_id'] ? safe_link($pk, ['id' => $c['invoice_id']], 'draft D-' . $c['invoice_id']) : '' ?></td><td class="num"><?= money($c['amount']) ?></td>
            <td class="text-end text-nowrap">
              <?php if ($lastAcct !== $c['account_id'] && Access::can($pk, 'create')): ?><?= IspUi::actionForm('bill_now', 'Bill now', ['account_id' => $c['account_id']], 'btn-outline-primary', 'receipt', 'Issue an invoice now for the pending charges of ' . $c['account_no'] . '?') ?><?php endif; ?>
              <?php if (Access::can($pk, 'delete') && !$c['invoice_id']): ?>
              <form method="post" class="d-inline" data-confirm="Cancel this charge?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_charge"><input type="hidden" name="charge_id" value="<?= (int)$c['id'] ?>">
                <input type="hidden" name="reason" value="Cancelled from the pending charges list"><button class="btn btn-sm btn-outline-danger" title="Cancel the charge"><i class="bi bi-x-lg"></i></button></form>
              <?php endif; ?></td></tr>
        <?php $lastAcct = $c['account_id']; endforeach; ?></tbody>
      </table></div>
      <?php endif; ?>
    </div></div>
    <?php
    layout_end();
    return;
}

$from = gdate('from', date('Y-m-01', strtotime('-2 months')));
$to = gdate('to', date('Y-m-t'));
$status = (string)g('status');
$kind = (string)g('kind');
$branch = IspSetup::userBranch() ?? gint('branch_id');
$overdue = g('overdue') === '1';
$q = trim((string)g('q'));
$p = ['f' => $from, 't' => $to];
$where = 'i.invoice_date BETWEEN :f AND :t';
if ($overdue) {
    $where = "i.status IN ('posted','partially_paid','overdue','disputed') AND i.balance > 0 AND i.due_date < :td";
    $p = ['td' => today()];
} elseif ($status === 'open') {
    $where .= " AND i.status IN ('posted','partially_paid','overdue','disputed')";
} elseif ($status !== '' && isset(IspBilling::STATUSES[$status])) {
    $where .= ' AND i.status = :st';
    $p['st'] = $status;
}
if ($kind !== '' && isset(ISPBILL_KINDS[$kind])) {
    $where .= ' AND i.kind = :k';
    $p['k'] = $kind;
}
if ($branch) {
    $where .= ' AND a.branch_id = :br';
    $p['br'] = $branch;
}
if ($q !== '') {
    $where .= ' AND (i.invoice_no LIKE :q1 OR a.account_no LIKE :q2 OR a.account_name LIKE :q3)';
    $p += ['q1' => "%$q%", 'q2' => "$q%", 'q3' => "%$q%"];
}
[$per, $off, $page] = IspUi::paging(50);
$sum = DB::row("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN i.status NOT IN ('voided','draft','approved') THEN i.total END), 0) AS billed,
        COALESCE(SUM(CASE WHEN i.status IN ('posted','partially_paid','overdue','disputed') THEN i.balance END), 0) AS open,
        COALESCE(SUM(CASE WHEN i.status NOT IN ('voided','draft','approved') THEN i.vat_total END), 0) AS vat
        FROM isp_invoices i JOIN isp_accounts a ON a.id = i.account_id WHERE $where", $p);
$rows = DB::all("SELECT i.*, a.account_no, a.account_name FROM isp_invoices i JOIN isp_accounts a ON a.id = i.account_id WHERE $where ORDER BY i.invoice_date DESC, i.id DESC LIMIT $per OFFSET $off", $p);
echo IspUi::filterStart($pk) . IspUi::fSearch($q, 'Invoice or account');
if (!$overdue) {
    echo IspUi::fDate('from', 'Invoice date from', $from) . IspUi::fDate('to', 'To', $to) . IspUi::fSelect('status', 'Status', ['open' => 'Open (unpaid)'] + IspBilling::STATUSES, $status);
}
echo IspUi::fSelect('kind', 'Kind', ISPBILL_KINDS, $kind) . (IspSetup::userBranch() === null && IspSetup::branches() ? IspUi::fSelect('branch_id', 'Branch', IspSetup::branches(), $branch) : '')
    . IspUi::fSelect('overdue', 'Show', ['1' => 'Overdue only (any date)'], $overdue ? '1' : '', 'By invoice date') . IspUi::filterEnd(url($pk));
?>
<div class="kpi-grid mb-3">
  <?= IspUi::kpi('Invoices', number_format((int)$sum['n']), $overdue ? 'overdue now' : fdate($from) . ' to ' . fdate($to), null, 'receipt', 'info') ?>
  <?= IspUi::kpi('Billed', money($sum['billed']), 'posted invoices', null, 'cash-stack', 'idle') ?>
  <?= IspUi::kpi('Output VAT', money($sum['vat']), 'in the billed total', null, 'percent', 'idle') ?>
  <?= IspUi::kpi('Still open', money($sum['open']), 'unpaid balance', $overdue ? null : url($pk, ['overdue' => '1']), 'wallet2', (float)$sum['open'] > 0 ? 'warn' : 'ok') ?>
</div>
<div class="card isp-card"><div class="card-body">
  <?php if (!$rows): echo IspUi::empty('No invoice matches.', 'receipt'); else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
    <thead><tr><th>Invoice</th><th>Date</th><th>Account</th><th>Kind</th><th>Period</th><th>Due</th><th>Status</th><th class="num">Total</th><th class="num">Open</th></tr></thead>
    <tbody><?php foreach ($rows as $i): $isOpen = in_array($i['status'], IspBilling::OPEN, true); ?>
      <tr><td><a href="<?= h(url($pk, ['id' => $i['id']])) ?>"><?= h($i['invoice_no']) ?></a></td><td class="text-nowrap"><?= h(fdate($i['invoice_date'])) ?></td>
        <td><?= IspUi::acct($i['account_id'], $i['account_no'], $i['account_name']) ?></td><td class="small"><?= h(ISPBILL_KINDS[$i['kind']] ?? $i['kind']) ?></td>
        <td class="small text-nowrap"><?= $i['period_start'] ? h(fdate($i['period_start']) . ' - ' . fdate($i['period_end'])) : '' ?></td>
        <td class="text-nowrap <?= $isOpen && $i['due_date'] < today() ? 'text-danger' : '' ?>"><?= h(fdate($i['due_date'])) ?></td><td><?= IspUi::status($i['status'], IspBilling::STATUSES) ?></td>
        <td class="num"><?= money($i['total']) ?></td><td class="num"><?= $isOpen ? money($i['balance']) : '' ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?= IspUi::pager((int)$sum['n'], $per, $page) ?>
  <?php endif; ?>
</div></div>
<?php
layout_end();
