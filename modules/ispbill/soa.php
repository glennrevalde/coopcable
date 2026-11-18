<?php
declare(strict_types=1);

/**
 * Statement of Account: the subscriber's receivable ledger for a period. Beginning balance, every invoice, payment,
 * credit, reversal and void with the running balance, the ending balance, the aging of what is still open and the
 * payment instructions; printable for the subscriber.
 */
require_once __DIR__ . '/_lib.php';

$pk = 'ispbill.soa';
$acctId = IspUi::postedAccount('account_id') ?? gint('account_id');
$acct = $acctId ? IspAccounts::get($acctId) : null;
$from = gdate('from', date('Y-m-01', strtotime('-2 months'))) ?? date('Y-m-01');
$to = gdate('to', today()) ?? today();
if ($to < $from) {
    [$from, $to] = [$to, $from];
}
if ($acct && IspSetup::userBranch() !== null && $acct['branch_id'] && (int)$acct['branch_id'] !== (int)IspSetup::userBranch()) {
    Access::denyPage($pk, 'open another branch\'s statement');
}

/**
 * The receivable movements of an account: [date, sort, kind, document, description, debit, credit, link]
 * (debit raises what the subscriber owes, credit lowers it)
 */
function ispbill_soa_moves(int $accountId): array
{
    $m = [];
    foreach (DB::all("SELECT * FROM isp_invoices WHERE account_id = :a AND status NOT IN ('draft', 'approved') ORDER BY invoice_date, id", ['a' => $accountId]) as $i) {
        $desc = ($i['period_start'] ? 'Service ' . fdate($i['period_start']) . ' - ' . fdate($i['period_end']) : ucfirst((string)$i['kind']) . ' charges') . ', due ' . fdate($i['due_date']);
        $m[] = [$i['invoice_date'], 1, 'Invoice', (string)$i['invoice_no'], $desc, (float)$i['total'], 0.0, ['ispbill.invoices', ['id' => $i['id']]]];
        if ($i['status'] === 'voided' && $i['voided_at']) {
            $m[] = [substr((string)$i['voided_at'], 0, 10), 4, 'Void', (string)$i['invoice_no'], 'Invoice voided: ' . $i['void_reason'], 0.0, (float)$i['total'], ['ispbill.invoices', ['id' => $i['id']]]];
        }
    }
    foreach (DB::all("SELECT * FROM isp_payments WHERE account_id = :a AND status IN ('posted', 'reversed') ORDER BY pay_date, id", ['a' => $accountId]) as $p) {
        $desc = (IspPayments::CHANNELS[$p['channel']] ?? $p['channel']) . ($p['reference_no'] ? ' ref. ' . $p['reference_no'] : '') . ($p['check_no'] ? ' check ' . $p['check_no'] : '');
        $m[] = [$p['pay_date'], 2, 'Payment', (string)$p['receipt_no'], $desc, 0.0, (float)$p['amount'], ['ispbill.payments', ['id' => $p['id']]]];
        if ($p['status'] === 'reversed' && $p['reversed_at']) {
            $m[] = [substr((string)$p['reversed_at'], 0, 10), 5, 'Reversal', (string)$p['receipt_no'], 'Payment reversed: ' . $p['reversal_reason'], (float)$p['amount'], 0.0, ['ispbill.payments', ['id' => $p['id']]]];
        }
    }
    foreach (DB::all("SELECT x.*, i.invoice_no FROM isp_adjustments x LEFT JOIN isp_invoices i ON i.id = x.invoice_id WHERE x.account_id = :a AND x.status = 'posted' AND x.adj_type <> 'debit' ORDER BY x.approved_at, x.id",
                 ['a' => $accountId]) as $x) {
        $m[] = [substr((string)($x['approved_at'] ?: $x['created_at']), 0, 10), 3, IspCredit::ADJ_TYPES[$x['adj_type']] ?? 'Credit', (string)$x['adj_no'],
            $x['reason'] . ($x['invoice_no'] ? ' (invoice ' . $x['invoice_no'] . ')' : ''), 0.0, (float)$x['amount'], ['ispbill.adjustments', ['id' => $x['id']]]];
    }
    usort($m, fn($a, $b) => [$a[0], $a[1], $a[3]] <=> [$b[0], $b[1], $b[3]]);
    return $m;
}

/** The open invoices by age on a date */
function ispbill_soa_aging(int $accountId, string $asOf): array
{
    $b = ['Current' => 0.0, '1-30 days' => 0.0, '31-60 days' => 0.0, '61-90 days' => 0.0, 'Over 90 days' => 0.0];
    foreach (IspPayments::openInvoices($accountId) as $i) {
        $days = (int)floor((strtotime($asOf) - strtotime((string)$i['due_date'])) / 86400);
        $k = $days <= 0 ? 'Current' : ($days <= 30 ? '1-30 days' : ($days <= 60 ? '31-60 days' : ($days <= 90 ? '61-90 days' : 'Over 90 days')));
        $b[$k] += (float)$i['balance'];
    }
    return array_map('r2', $b);
}

$print = g('a') === 'print';
if ($print) {
    Access::require($pk, 'print');
}
if (!$acct) {
    layout_start('Statement of Account');
    if (g('account_id_text') !== '') {
        echo '<div class="alert alert-warning">No account matches "' . h(g('account_id_text')) . '".</div>';
    }
    ?>
    <form class="card isp-card" method="get" action="<?= h(base_url() . '/index.php') ?>"><input type="hidden" name="p" value="<?= h($pk) ?>">
      <div class="card-body row g-2 align-items-end">
        <?= IspUi::accountField('account_id', null, 'Subscriber account', 'col-md-6') ?>
        <?= IspUi::fDate('from', 'From', $from) ?><?= IspUi::fDate('to', 'To', $to) ?>
        <div class="col-md-2 d-grid"><button class="btn btn-sm btn-primary"><i class="bi bi-file-text"></i> Show statement</button></div></div></form>
    <?php
    layout_end();
    return;
}

$moves = ispbill_soa_moves((int)$acct['id']);
$opening = 0.0;
$rows = [];
$tDebit = $tCredit = 0.0;
foreach ($moves as $mv) {
    if ($mv[0] < $from) {
        $opening = r2($opening + $mv[5] - $mv[6]);
    } elseif ($mv[0] <= $to) {
        $rows[] = $mv;
        $tDebit += $mv[5];
        $tCredit += $mv[6];
    }
}
$closing = r2($opening + $tDebit - $tCredit);
$aging = ispbill_soa_aging((int)$acct['id'], $to);
$cust = IspAccounts::customer((int)$acct['isp_customer_id']);
$nextDue = DB::value("SELECT MIN(due_date) FROM isp_invoices WHERE account_id = :a AND status IN ('posted','partially_paid','overdue','disputed') AND balance > 0", ['a' => $acct['id']]);
$current = IspAccounts::balances((int)$acct['id']);

if ($print) {
    Audit::log('print', 'ispbill', 'isp_account', (int)$acct['id'], (string)$acct['account_no'], ['reason' => 'Statement of account ' . $from . ' to ' . $to]);
    layout_start('Statement of Account ' . $acct['account_no'], ['bare' => true, 'no_title' => true]);
    echo '<div class="no-print d-flex gap-2 mb-3"><button class="btn btn-sm btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>'
        . btn_link(url($pk, ['account_id' => $acct['id'], 'from' => $from, 'to' => $to]), 'Back') . '</div>';
    echo '<div class="isp-doc isp-soa">' . IspUi::letterhead('STATEMENT OF ACCOUNT', '<div>' . h(fdate($from)) . ' to ' . h(fdate($to)) . '</div><div>Printed ' . h(fdate(today())) . '</div>');
    echo '<div class="row g-2"><div class="col-7"><table class="table table-sm doc-meta mb-0"><tr><th style="width:32%">Subscriber</th><td><strong>' . h($cust['name'] ?? $acct['account_name']) . '</strong></td></tr>'
        . '<tr><th>Address</th><td>' . h((string)($cust['billing_address'] ?: $acct['install_address'])) . '</td></tr><tr><th>Account no.</th><td><strong>' . h($acct['account_no']) . '</strong>, ' . h((string)$acct['plan_name']) . '</td></tr></table></div>'
        . '<div class="col-5"><table class="table table-sm doc-totals mb-0"><tr><td>Beginning balance</td><td class="num">' . money($opening) . '</td></tr><tr><td>Charges</td><td class="num">' . money($tDebit) . '</td></tr>'
        . '<tr><td>Payments and credits</td><td class="num">(' . money($tCredit) . ')</td></tr><tr class="doc-total"><th>Ending balance</th><th class="num">' . money($closing) . '</th></tr>'
        . ($nextDue && $closing > 0 ? '<tr><td>Pay on or before</td><td class="num"><strong>' . h(fdate($nextDue)) . '</strong></td></tr>' : '') . '</table></div></div>';
} else {
    $actions = (Access::can($pk, 'print') ? btn_link(url($pk, ['account_id' => $acct['id'], 'from' => $from, 'to' => $to, 'a' => 'print']), 'Print statement', 'btn-primary', 'printer') . ' ' : '')
        . (Access::can('ispbill.collect', 'create') ? btn_link(url('ispbill.collect', ['account_id' => $acct['id']]), 'Collect', 'btn-outline-primary', 'cash-coin') : '');
    layout_start('Statement of Account', ['actions' => $actions]);
    echo '<form class="card isp-card mb-3 no-print" method="get" action="' . h(base_url() . '/index.php') . '"><input type="hidden" name="p" value="' . h($pk) . '">'
        . '<div class="card-body row g-2 align-items-end">' . IspUi::accountField('account_id', (int)$acct['id'], 'Subscriber account', 'col-md-6')
        . IspUi::fDate('from', 'From', $from) . IspUi::fDate('to', 'To', $to) . '<div class="col-md-2 d-grid"><button class="btn btn-sm btn-primary">Show</button></div></div></form>';
    echo ispbill_account_panel($acct, true);
    echo '<div class="kpi-grid mb-3">' . IspUi::kpi('Beginning balance', money($opening), 'before ' . fdate($from), null, 'skip-start', 'idle')
        . IspUi::kpi('Charges', money($tDebit), 'invoices and reversals', null, 'plus-circle', 'info') . IspUi::kpi('Payments and credits', money($tCredit), 'in the period', null, 'dash-circle', 'ok')
        . IspUi::kpi('Ending balance', money($closing), 'as of ' . fdate($to), null, 'wallet2', $closing > 0 ? 'warn' : 'ok') . '</div>';
    echo '<div class="card isp-card mb-3"><div class="card-body"><h2 class="sec-title">Transactions</h2>';
}

echo '<div class="table-responsive"><table class="table table-sm align-middle isp-table doc-lines"><thead><tr><th>Date</th><th>Transaction</th><th>No.</th><th>Description</th>'
    . '<th class="num">Charges</th><th class="num">Payments / credits</th><th class="num">Balance</th></tr></thead><tbody>'
    . '<tr><td>' . h(fdate($from)) . '</td><td colspan="5"><em>Beginning balance</em></td><td class="num">' . money($opening) . '</td></tr>';
$run = $opening;
foreach ($rows as $mv) {
    $run = r2($run + $mv[5] - $mv[6]);
    $doc = !$print && $mv[7] ? safe_link($mv[7][0], $mv[7][1], $mv[3]) : h($mv[3]);
    echo '<tr><td class="text-nowrap">' . h(fdate($mv[0])) . '</td><td>' . h($mv[2]) . '</td><td>' . $doc . '</td><td class="small">' . h($mv[4]) . '</td>'
        . '<td class="num">' . money($mv[5], true) . '</td><td class="num">' . money($mv[6], true) . '</td><td class="num">' . money($run) . '</td></tr>';
}
if (!$rows) {
    echo '<tr><td colspan="7" class="text-muted">No transaction in the period.</td></tr>';
}
echo '</tbody><tfoot><tr><th colspan="4">Ending balance ' . h(fdate($to)) . '</th><th class="num">' . money($tDebit) . '</th><th class="num">' . money($tCredit) . '</th><th class="num">' . money($closing) . '</th></tr></tfoot></table></div>';

$agingHtml = '<table class="table table-sm isp-table doc-aging mb-0"><thead><tr>';
foreach (array_keys($aging) as $k) {
    $agingHtml .= '<th class="num">' . h($k) . '</th>';
}
$agingHtml .= '<th class="num">Total open</th></tr></thead><tbody><tr>';
foreach ($aging as $v) {
    $agingHtml .= '<td class="num">' . money($v) . '</td>';
}
$agingHtml .= '<td class="num"><strong>' . money(array_sum($aging)) . '</strong></td></tr></tbody></table>';

if ($print) {
    echo '<h3 class="h6 mt-3">Open invoices by age</h3>' . $agingHtml;
    if ($current['credit'] > 0.004) {
        echo '<p class="small mt-2">Advance payments on file: ' . money($current['credit']) . ' (applied to the next invoices).</p>';
    }
    echo '<div class="row g-3 mt-2 small"><div class="col-7"><div class="doc-pay"><strong>How to pay</strong><br>' . nl2br(h(IspSetup::get('isp_payment_instructions'))) . '</div></div>'
        . '<div class="col-5"><p class="mb-1">Please examine this statement. If no error is reported within 15 days of receipt, the account is considered correct.</p>'
        . '<p class="mb-0">This statement is not an invoice or an official receipt.</p></div></div></div>';
} else {
    echo '</div></div><div class="card isp-card"><div class="card-body"><h2 class="sec-title">Open invoices by age on ' . h(fdate($to)) . '</h2><div class="table-responsive">' . $agingHtml . '</div>';
    if (abs($closing - $current['balance']) > 0.004 && $to >= today()) {
        echo '<p class="small text-warning-emphasis mt-2 mb-0"><i class="bi bi-exclamation-triangle"></i> The statement balance differs from the account balance (' . money($current['balance']) . '); please report this to the accountant.</p>';
    }
    echo '</div></div>';
}
layout_end();
