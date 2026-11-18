<?php
declare(strict_types=1);

/**
 * Cashier Shifts & Cash Count: open the drawer with the opening cash, see the shift's collections by payment method,
 * count the cash by denomination at closing (the over / short goes to the books), the supervisor's certification and
 * the remittance of the cash to the bank.
 */
require_once __DIR__ . '/_lib.php';

$pk = 'ispbill.shifts';
$uid = (int)Auth::id();
$id = gint('id');

/** Bank and cash-in-bank accounts the cash of a shift is remitted to: id => label */
function ispbill_remit_targets(int $exclude): array
{
    $rows = DB::pairs("SELECT id, CONCAT(code, ' - ', name) FROM accounts WHERE is_active = 1 AND account_type = 'Bank' AND id <> :x ORDER BY code", ['x' => $exclude]);
    $und = Accts::get('undeposited_funds');
    if ($und && $und !== $exclude) {
        $rows = [$und => (string)DB::value("SELECT CONCAT(code, ' - ', name) FROM accounts WHERE id = :i", ['i' => $und])] + $rows;
    }
    return $rows;
}

handle_post(function () use ($pk, $uid) {
    $action = (string)p('action');
    $sid = (int)p('id');
    $own = fn(array $s) => (int)$s['user_id'] === $uid;
    if ($action === 'open') {
        Access::require($pk, 'create');
        $branch = IspSetup::userBranch() ?? (pint('branch_id') ?: null);
        $sid = IspCashier::open($uid, (int)pint('cash_account_id'), pnum('opening_cash'), $branch);
        redirect(url('ispbill.collect'), 'Shift opened. You can now receive cash and checks.', 'success');
    }
    if ($action === 'close') {
        Access::require($pk, 'create');
        $denoms = [];
        foreach (IspCashier::DENOMINATIONS as $d) {
            $denoms[$d] = max(0, (int)($_POST['d'][$d] ?? 0));
        }
        $r = IspCashier::close($sid, $denoms, trim((string)p('notes')));
        $msg = 'Shift closed. Counted ' . money($r['counted']) . ' against ' . money($r['expected']) . ' expected'
            . (abs($r['over_short']) > 0.004 ? ($r['over_short'] > 0 ? ', over by ' : ', short by ') . money(abs($r['over_short'])) . ' (posted to Cashier Over and Short)' : ', no difference') . '.';
        redirect(url($pk, ['id' => $sid]), $msg, abs($r['over_short']) > 0.004 ? 'warning' : 'success');
    }
    if ($action === 'certify') {
        Access::require($pk, 'approve');
        IspCashier::certify($sid, trim((string)p('note')));
        redirect(url($pk, ['id' => $sid]), 'Cash count certified.', 'success');
    }
    if ($action === 'remit') {
        $s = DB::row('SELECT * FROM isp_shifts WHERE id = :id', ['id' => $sid]) ?? fail('Shift not found.');
        if (!$own($s)) {
            Access::require($pk, 'approve');
        } else {
            Access::require($pk, 'create');
        }
        $to = (int)pint('to_account_id');
        if (!isset(ispbill_remit_targets((int)$s['cash_account_id'])[$to])) {
            fail('Choose the bank account (or Undeposited Funds) the cash goes to.');
        }
        IspCashier::remit($sid, $to, pnum('amount'), trim((string)p('ref')));
        redirect(url($pk, ['id' => $sid]), 'Remittance recorded as a fund transfer.', 'success');
    }
});

/** The collections of a shift: channel rows, the expected cash */
function ispbill_shift_summary(array $s): array
{
    $tot = IspCashier::totals((int)$s['id']);
    $reversed = DB::row("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amt FROM isp_payments WHERE shift_id = :s AND status = 'reversed'", ['s' => $s['id']]);
    $pending = DB::row("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amt FROM isp_payments WHERE shift_id = :s AND status = 'pending'", ['s' => $s['id']]);
    return [$tot, $reversed, $pending, $s['status'] === 'open' ? IspCashier::expectedCash($s) : (float)$s['expected_cash']];
}

function ispbill_shift_table(array $s): string
{
    [$tot, $rev, $pen, $expected] = ispbill_shift_summary($s);
    $out = '<div class="table-responsive"><table class="table table-sm isp-table"><thead><tr><th>Payment method</th><th class="num">Count</th><th class="num">Amount</th></tr></thead><tbody>';
    $sum = 0.0;
    foreach (IspPayments::CHANNELS as $k => $label) {
        if (!isset($tot[$k])) {
            continue;
        }
        $out .= '<tr><td>' . h($label) . '</td><td class="num">' . (int)$tot[$k][0] . '</td><td class="num">' . money($tot[$k][1]) . '</td></tr>';
        $sum += $tot[$k][1];
    }
    if (!$tot) {
        $out .= '<tr><td colspan="3" class="text-muted">No posted collection in this shift.</td></tr>';
    }
    $out .= '</tbody><tfoot><tr><th>Total posted</th><th class="num">' . array_sum(array_map(fn($t) => $t[0], $tot)) . '</th><th class="num">' . money($sum) . '</th></tr>';
    if ((int)$rev['n']) {
        $out .= '<tr><td class="text-muted">Reversed (not counted)</td><td class="num">' . (int)$rev['n'] . '</td><td class="num">' . money($rev['amt']) . '</td></tr>';
    }
    if ((int)$pen['n']) {
        $out .= '<tr><td class="text-muted">References waiting for verification</td><td class="num">' . (int)$pen['n'] . '</td><td class="num">' . money($pen['amt']) . '</td></tr>';
    }
    $out .= '<tr><td>Opening cash</td><td></td><td class="num">' . money($s['opening_cash']) . '</td></tr>'
        . '<tr><th>Cash expected in the drawer</th><td></td><th class="num">' . money($expected) . '</th></tr></tfoot></table></div>';
    return $out;
}

$names = fn(array $ids) => DB::pairs('SELECT id, full_name FROM users WHERE id IN (' . implode(',', array_map('intval', array_filter($ids) ?: [0])) . ')');

// ================================================================ one shift (and its printed report)
if ($id) {
    $s = DB::row('SELECT s.*, u.full_name AS cashier, CONCAT(ac.code, \' - \', ac.name) AS drawer FROM isp_shifts s JOIN users u ON u.id = s.user_id JOIN accounts ac ON ac.id = s.cash_account_id WHERE s.id = :id', ['id' => $id])
        ?? fail('Shift not found.');
    if ((int)$s['user_id'] !== $uid && !Access::can($pk, 'approve') && !Access::can($pk, 'print')) {
        Access::denyPage($pk, 'open another cashier\'s shift');
    }
    $nm = $names([$s['certified_by']]);
    $pays = DB::all('SELECT p.*, a.account_no, a.account_name FROM isp_payments p JOIN isp_accounts a ON a.id = p.account_id WHERE p.shift_id = :s ORDER BY p.id', ['s' => $id]);
    $denoms = [];
    foreach (array_filter(explode(';', (string)$s['denominations'])) as $part) {
        [$d, $n] = array_pad(explode('=', $part, 2), 2, '0');
        $denoms[$d] = (int)$n;
    }
    $print = g('a') === 'print';
    if ($print) {
        Access::require($pk, 'print');
        layout_start('Cashier shift report ' . $s['shift_no'], ['bare' => true, 'no_title' => true]);
        echo '<div class="no-print d-flex gap-2 mb-3"><button class="btn btn-sm btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>'
            . btn_link(url($pk, ['id' => $id]), 'Back to the shift') . '</div><div class="isp-doc">'
            . IspUi::letterhead('CASHIER SHIFT REPORT', '<div class="doc-no">No. <strong>' . h($s['shift_no']) . '</strong></div>');
    } else {
        $actions = (Access::can($pk, 'print') ? btn_link(url($pk, ['id' => $id, 'a' => 'print']), 'Print report', 'btn-outline-secondary', 'printer') : '')
            . ' ' . btn_link(url($pk), 'All shifts', 'btn-outline-secondary', 'list-ul');
        layout_start('Shift ' . $s['shift_no'], ['actions' => $actions]);
        echo '<div class="card isp-card mb-3"><div class="card-body">';
    }
    echo IspUi::dl(['Cashier' => h($s['cashier']), 'Status' => IspUi::status($s['status'], IspCashier::STATUSES), 'Cash drawer' => h($s['drawer']),
        'Opened' => h(fdatetime($s['opened_at'])), 'Closed' => h(fdatetime($s['closed_at'])), 'Opening cash' => money($s['opening_cash']),
        'Cash expected' => $s['closed_at'] ? money($s['expected_cash']) : '', 'Cash counted' => $s['closed_at'] ? money($s['counted_cash']) : '',
        'Over / short' => $s['closed_at'] ? '<span class="' . ((float)$s['over_short'] < 0 ? 'text-danger' : '') . '">' . money($s['over_short']) . '</span> ' . ispbill_txn($s['over_short_txn_id'] ? (int)$s['over_short_txn_id'] : null) : '',
        'Remitted' => $s['remit_txn_id'] ? money($s['remitted_amount']) . ' ' . ispbill_txn((int)$s['remit_txn_id']) : '',
        'Certified' => $s['certified_at'] ? h(($nm[$s['certified_by']] ?? '') . ', ' . fdatetime($s['certified_at'])) : '', 'Notes' => h((string)$s['notes'])]);
    if (!$print) {
        echo '</div></div><div class="row g-3"><div class="col-lg-6"><div class="card isp-card h-100"><div class="card-body"><h2 class="sec-title">Collections by payment method</h2>';
    } else {
        echo '<h3 class="h6 mt-3">Collections by payment method</h3>';
    }
    echo ispbill_shift_table($s);
    if ($denoms) {
        echo $print ? '<h3 class="h6 mt-3">Cash count</h3>' : '<h2 class="sec-title mt-3">Cash count</h2>';
        echo '<table class="table table-sm isp-table"><thead><tr><th>Denomination</th><th class="num">Pieces</th><th class="num">Amount</th></tr></thead><tbody>';
        foreach (IspCashier::DENOMINATIONS as $d) {
            if (!empty($denoms[$d])) {
                echo '<tr><td>' . h($d) . '</td><td class="num">' . (int)$denoms[$d] . '</td><td class="num">' . money((float)$d * $denoms[$d]) . '</td></tr>';
            }
        }
        echo '</tbody><tfoot><tr><th colspan="2">Counted</th><th class="num">' . money($s['counted_cash']) . '</th></tr></tfoot></table>';
    }
    if (!$print) {
        echo '</div></div></div><div class="col-lg-6"><div class="card isp-card h-100"><div class="card-body">';
        if ($s['status'] === 'closed' && Access::can($pk, 'approve') && (int)$s['user_id'] !== $uid) {
            echo '<h2 class="sec-title">Certify the cash count</h2><form method="post" class="row g-2 mb-3">' . csrf_field()
                . '<input type="hidden" name="action" value="certify"><input type="hidden" name="id" value="' . (int)$id . '">'
                . '<div class="col-8"><input class="form-control form-control-sm" name="note" placeholder="Note (optional)" maxlength="200"></div>'
                . '<div class="col-4 d-grid"><button class="btn btn-sm btn-success" data-confirm="Certify that the cash was counted as recorded?"><i class="bi bi-patch-check"></i> Certify</button></div></form>';
        }
        $mayRemit = ((int)$s['user_id'] === $uid && Access::can($pk, 'create')) || Access::can($pk, 'approve');
        if (in_array($s['status'], ['closed', 'certified'], true) && !$s['remit_txn_id'] && $mayRemit) {
            echo '<h2 class="sec-title">Remit the cash</h2><form method="post" class="row g-2">' . csrf_field()
                . '<input type="hidden" name="action" value="remit"><input type="hidden" name="id" value="' . (int)$id . '">'
                . select_field('to_account_id', 'Deposit to', ispbill_remit_targets((int)$s['cash_account_id']), fv('to_account_id'), ['class' => 'col-12', 'required' => true])
                . input_field('amount', 'Amount', fv('amount', null, number_format((float)$s['counted_cash'], 2, '.', '')), ['class' => 'col-6', 'required' => true, 'extra' => 'inputmode="decimal"'])
                . input_field('ref', 'Deposit slip / reference', fv('ref'), ['class' => 'col-6', 'extra' => 'maxlength="40"'])
                . '<div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-bank"></i> Record remittance</button></div></form>';
        } elseif ($s['status'] === 'open' && (int)$s['user_id'] === $uid) {
            echo '<p>This is your open shift. ' . btn_link(url($pk), 'Close it with the cash count', 'btn-outline-primary', 'calculator') . '</p>';
        } elseif (!$s['remit_txn_id']) {
            echo '<p class="text-muted small">The cash is remitted once the shift is closed.</p>';
        } else {
            echo '<p class="text-muted small mb-0">The cash of this shift was remitted.</p>';
        }
        echo '</div></div></div></div><div class="card isp-card mt-3"><div class="card-body"><h2 class="sec-title">Payments of the shift</h2>';
    } else {
        echo '<h3 class="h6 mt-3">Payments of the shift</h3>';
    }
    if (!$pays) {
        echo IspUi::empty('No payment in this shift.', 'receipt');
    } else {
        echo '<div class="table-responsive"><table class="table table-sm table-hover isp-table"><thead><tr><th>Receipt</th><th>Time</th><th>Account</th><th>Channel</th><th>Reference</th><th>Status</th><th class="num">Amount</th></tr></thead><tbody>';
        foreach ($pays as $r) {
            echo '<tr><td>' . ($print ? h($r['receipt_no']) : '<a href="' . h(url('ispbill.payments', ['id' => $r['id']])) . '">' . h($r['receipt_no']) . '</a>') . '</td><td class="small">' . h(date('H:i', strtotime((string)$r['received_at']))) . '</td>'
                . '<td>' . ($print ? h($r['account_no'] . ' ' . $r['account_name']) : IspUi::acct($r['account_id'], $r['account_no'], $r['account_name'])) . '</td><td>' . h(IspPayments::CHANNELS[$r['channel']] ?? $r['channel']) . '</td>'
                . '<td class="small">' . h((string)($r['reference_no'] ?: $r['check_no'])) . '</td><td>' . IspUi::status($r['status'], IspPayments::STATUSES) . '</td><td class="num">' . money($r['amount']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    if ($print) {
        echo '<div class="row g-4 mt-4 small text-center"><div class="col-4"><div class="doc-sign">' . h($s['cashier']) . '</div>Cashier</div>'
            . '<div class="col-4"><div class="doc-sign">' . h($nm[$s['certified_by']] ?? '') . '</div>Counted and certified by</div><div class="col-4"><div class="doc-sign">&nbsp;</div>Received for deposit by</div></div></div>';
    } else {
        echo '</div></div>';
    }
    layout_end();
    return;
}

// ================================================================ my shift and the list
$mine = IspCashier::current($uid);
$actions = page_link('ispbill.collect', [], 'Collect payment', 'btn-primary', 'cash-coin', 'create');
layout_start('Cashier Shifts & Cash Count', ['actions' => $actions]);
?>
<div class="row g-3 mb-3">
<?php if ($mine): [$tot, , , $expected] = ispbill_shift_summary($mine); ?>
  <div class="col-lg-6"><div class="card isp-card h-100"><div class="card-body">
    <div class="isp-head"><div><h2 class="isp-head-title">Your shift <?= h($mine['shift_no']) ?></h2>
      <div class="isp-head-sub"><?= IspUi::status('open', IspCashier::STATUSES) ?> <span class="ms-2">since <?= h(fdatetime($mine['opened_at'])) ?></span></div></div>
      <div class="isp-head-actions"><?= btn_link(url($pk, ['id' => $mine['id']]), 'Details', 'btn-outline-secondary', 'eye') ?></div></div>
    <?= ispbill_shift_table($mine) ?>
  </div></div></div>
  <div class="col-lg-6"><div class="card isp-card h-100"><div class="card-body">
    <h2 class="sec-title">Close the shift: count the cash</h2>
    <?php if (Access::can($pk, 'create')): ?>
    <form method="post" data-confirm="Close the shift with this cash count? The difference is posted to the books.">
      <?= csrf_field() ?><input type="hidden" name="action" value="close"><input type="hidden" name="id" value="<?= (int)$mine['id'] ?>">
      <div class="table-responsive"><table class="table table-sm align-middle isp-table mb-2" id="denomTable">
        <thead><tr><th>Denomination</th><th style="width:7rem">Pieces</th><th class="num">Amount</th></tr></thead>
        <tbody><?php foreach (IspCashier::DENOMINATIONS as $d): ?>
          <tr><td><?= h($d) ?></td><td><input class="form-control form-control-sm text-end" type="number" min="0" step="1" name="d[<?= h($d) ?>]" data-denom="<?= h($d) ?>" value="<?= h((string)($_POST['d'][$d] ?? '')) ?>"></td>
            <td class="num" data-line>0.00</td></tr>
        <?php endforeach; ?></tbody>
        <tfoot><tr><th colspan="2">Counted</th><th class="num" id="denomTotal">0.00</th></tr>
          <tr><td colspan="2">Expected</td><td class="num" id="denomExpected" data-expected="<?= h((string)$expected) ?>"><?= money($expected) ?></td></tr>
          <tr><th colspan="2">Over / short</th><th class="num" id="denomDiff">0.00</th></tr></tfoot>
      </table></div>
      <input class="form-control form-control-sm mb-2" name="notes" placeholder="Notes (optional)" maxlength="250">
      <button class="btn btn-sm btn-primary"><i class="bi bi-lock"></i> Close shift</button>
    </form>
    <script>
    (function () {
      var t = document.getElementById('denomTable');
      var fmt = function (v) { return (v < 0 ? '(' : '') + Math.abs(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + (v < 0 ? ')' : ''); };
      function calc() {
        var sum = 0;
        t.querySelectorAll('input[data-denom]').forEach(function (i) {
          var v = parseFloat(i.getAttribute('data-denom')) * (parseInt(i.value, 10) || 0);
          i.closest('tr').querySelector('[data-line]').textContent = fmt(v);
          sum += v;
        });
        sum = Math.round(sum * 100) / 100;
        var exp = parseFloat(document.getElementById('denomExpected').getAttribute('data-expected')) || 0;
        document.getElementById('denomTotal').textContent = fmt(sum);
        var d = document.getElementById('denomDiff');
        d.textContent = fmt(Math.round((sum - exp) * 100) / 100);
        d.className = 'num ' + (sum - exp < -0.004 ? 'text-danger' : (sum - exp > 0.004 ? 'text-warning' : 'text-success'));
      }
      t.addEventListener('input', calc);
      calc();
    })();
    </script>
    <?php else: ?><p class="text-muted">You may not close shifts.</p><?php endif; ?>
  </div></div></div>
<?php elseif (Access::can($pk, 'create')): ?>
  <div class="col-lg-6"><div class="card isp-card h-100"><div class="card-body">
    <h2 class="sec-title">Open your shift</h2>
    <p class="small text-muted">Count the change fund in the drawer before the first customer. Cash and checks are received only in an open shift.</p>
    <form method="post" class="row g-2">
      <?= csrf_field() ?><input type="hidden" name="action" value="open">
      <?= select_field('cash_account_id', 'Cash drawer account', IspCashier::drawers(), fv('cash_account_id', null, (string)Accts::get('isp_cash')), ['class' => 'col-md-7', 'required' => true]) ?>
      <?= input_field('opening_cash', 'Opening cash (change fund)', fv('opening_cash', null, '0.00'), ['class' => 'col-md-5', 'extra' => 'inputmode="decimal"']) ?>
      <?php if (IspSetup::userBranch() === null && IspSetup::branches()): ?>
      <?= select_field('branch_id', 'Branch', IspSetup::branches(), fv('branch_id'), ['class' => 'col-md-7', 'blank' => '(none)']) ?>
      <?php endif; ?>
      <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-unlock"></i> Open shift</button></div>
    </form>
  </div></div></div>
<?php endif; ?>
</div>
<?php
$st = (string)g('status');
$cashier = gint('cashier');
$from = gdate('from', date('Y-m-d', strtotime('-30 days')));
$to = gdate('to', today());
$p = ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59'];
$where = 's.opened_at BETWEEN :f AND :t';
if ($st !== '' && isset(IspCashier::STATUSES[$st])) {
    $where .= ' AND s.status = :st';
    $p['st'] = $st;
}
if (!Access::can($pk, 'approve')) {
    $where .= ' AND s.user_id = :me';
    $p['me'] = $uid;
} elseif ($cashier) {
    $where .= ' AND s.user_id = :cu';
    $p['cu'] = $cashier;
}
$where .= IspSetup::branchSql('s.branch_id', $p);
[$per, $off, $page] = IspUi::paging(30);
$total = (int)DB::value("SELECT COUNT(*) FROM isp_shifts s WHERE $where", $p);
$rows = DB::all("SELECT s.*, u.full_name AS cashier,
        (SELECT COALESCE(SUM(y.amount), 0) FROM isp_payments y WHERE y.shift_id = s.id AND y.status = 'posted') AS collected
        FROM isp_shifts s JOIN users u ON u.id = s.user_id WHERE $where ORDER BY s.id DESC LIMIT $per OFFSET $off", $p);
$cashiers = DB::pairs('SELECT DISTINCT u.id, u.full_name FROM isp_shifts s JOIN users u ON u.id = s.user_id ORDER BY u.full_name');
echo IspUi::filterStart($pk) . IspUi::fDate('from', 'Opened from', $from) . IspUi::fDate('to', 'To', $to) . IspUi::fSelect('status', 'Status', IspCashier::STATUSES, $st)
    . (Access::can($pk, 'approve') ? IspUi::fSelect('cashier', 'Cashier', $cashiers, $cashier) : '') . IspUi::filterEnd(url($pk));
?>
<div class="card isp-card"><div class="card-body">
  <h2 class="sec-title">Shifts</h2>
  <?php if (!$rows): echo IspUi::empty('No shift in this period.', 'cash-stack'); else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
    <thead><tr><th>Shift</th><th>Cashier</th><th>Opened</th><th>Closed</th><th>Status</th><th class="num">Collected</th><th class="num">Counted</th><th class="num">Over / short</th><th>Remitted</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr><td><a href="<?= h(url($pk, ['id' => $r['id']])) ?>"><?= h($r['shift_no']) ?></a></td><td><?= h($r['cashier']) ?></td><td class="small text-nowrap"><?= h(fdatetime($r['opened_at'])) ?></td>
        <td class="small text-nowrap"><?= h(fdatetime($r['closed_at'])) ?></td><td><?= IspUi::status($r['status'], IspCashier::STATUSES) ?></td><td class="num"><?= money($r['collected']) ?></td>
        <td class="num"><?= $r['closed_at'] ? money($r['counted_cash']) : '' ?></td>
        <td class="num <?= (float)$r['over_short'] < 0 ? 'text-danger' : '' ?>"><?= $r['closed_at'] ? money($r['over_short']) : '' ?></td>
        <td><?= $r['remit_txn_id'] ? money($r['remitted_amount']) : ($r['status'] !== 'open' ? '<span class="text-muted small">not yet</span>' : '') ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?= IspUi::pager($total, $per, $page) ?>
  <?php endif; ?>
</div></div>
<?php
layout_end();
