<?php
declare(strict_types=1);

/**
 * Adjustments & Write-offs: credit and debit adjustments, write-offs, penalty waivers and service credits. Each is
 * requested with a reason and approved by a user with the right (within an approval limit; a write-off by someone
 * other than the requester). Nothing is deleted: a credit is a credit memo on the invoice, a debit a charge on the
 * next invoice.
 */
require_once __DIR__ . '/_lib.php';

$pk = 'ispbill.adjustments';
$id = gint('id');
// the kinds a person asks for here (outage credits, deposits and dispute credits come from their own pages)
const ISPBILL_ADJ_REQUEST = ['credit' => 'Credit adjustment', 'debit' => 'Debit adjustment', 'write_off' => 'Write-off', 'penalty_waiver' => 'Penalty waiver', 'outage_credit' => 'Outage service credit'];

handle_post(function () use ($pk) {
    $action = (string)p('action');
    if ($action === 'request') {
        Access::require($pk, 'create');
        $acct = IspUi::postedAccount('account_id') ?? fail('Choose the subscriber account.');
        $type = (string)p('adj_type');
        if (!isset(ISPBILL_ADJ_REQUEST[$type])) {
            fail('Choose the kind of adjustment.');
        }
        $inv = $type === 'debit' ? null : (pint('invoice_id') ?: null);
        $aid = IspCredit::request($acct, $inv, $type, pnum('amount'), trim((string)p('reason')));
        redirect(url($pk, ['id' => $aid]), 'Adjustment requested; it takes effect once approved.', 'success');
    }
    if ($action === 'approve' || $action === 'reject') {
        Access::require($pk, 'approve');
        $ids = array_map('intval', (array)($_POST['ids'] ?? [p('id')]));
        $done = 0;
        $errors = [];
        foreach (array_filter($ids) as $aid) {
            try {
                IspCredit::decide($aid, $action === 'approve', trim((string)p('note')));
                $done++;
            } catch (UserError $e) {
                $errors[] = DB::value('SELECT adj_no FROM isp_adjustments WHERE id = :i', ['i' => $aid]) . ': ' . $e->getMessage();
            }
        }
        if ($errors && !$done) {
            fail(implode(' ', $errors));
        }
        $to = count($ids) === 1 ? url($pk, ['id' => $ids[0]]) : url($pk, ['tab' => 'queue']);
        redirect($to, $done . ' adjustment(s) ' . ($action === 'approve' ? 'approved and posted' : 'rejected') . ($errors ? '. Not done: ' . implode(' ', $errors) : '.'), $errors ? 'warning' : 'success');
    }
});

$limit = Access::limit('isp_max_adjustment');
$mayDecide = Access::can($pk, 'approve') && Access::special('isp_write_off');

// ================================================================ one adjustment
if ($id) {
    $x = DB::row('SELECT x.*, a.account_no, a.account_name, a.branch_id, i.invoice_no, ur.full_name AS requester, ua.full_name AS approver
                    FROM isp_adjustments x JOIN isp_accounts a ON a.id = x.account_id LEFT JOIN isp_invoices i ON i.id = x.invoice_id
                    LEFT JOIN users ur ON ur.id = x.requested_by LEFT JOIN users ua ON ua.id = x.approved_by WHERE x.id = :id', ['id' => $id]) ?? fail('Adjustment not found.');
    if (IspSetup::userBranch() !== null && $x['branch_id'] && (int)$x['branch_id'] !== (int)IspSetup::userBranch()) {
        Access::denyPage($pk, 'open another branch\'s adjustment');
    }
    layout_start('Adjustment ' . $x['adj_no'], ['actions' => btn_link(url($pk), 'All adjustments', 'btn-outline-secondary', 'list-ul')]);
    ?>
    <div class="card isp-card mb-3"><div class="card-body">
      <div class="isp-head"><div><h2 class="isp-head-title"><?= money($x['amount']) ?> <span class="fw-normal text-muted"><?= h(IspCredit::ADJ_TYPES[$x['adj_type']] ?? $x['adj_type']) ?></span></h2>
        <div class="isp-head-sub"><?= IspUi::status($x['status'], IspCredit::ADJ_STATUSES) ?> <span class="ms-2"><?= IspUi::acct($x['account_id'], $x['account_no'], $x['account_name']) ?></span></div></div></div>
      <?= IspUi::dl(['Invoice' => $x['invoice_id'] ? safe_link('ispbill.invoices', ['id' => $x['invoice_id']], (string)$x['invoice_no']) : ($x['adj_type'] === 'debit' ? 'Billed on the next invoice' : ''),
          'Reason' => h($x['reason']), 'Requested' => h(trim($x['requester'] . ', ' . fdatetime($x['created_at']), ', ')), 'Source' => h(ucfirst(str_replace('_', ' ', (string)$x['source_type']))),
          'Decided' => $x['approved_at'] ? h($x['approver'] . ', ' . fdatetime($x['approved_at'])) : '', 'Rejected because' => h((string)$x['rejected_reason']),
          'Ledger' => ispbill_txn($x['txn_id'] ? (int)$x['txn_id'] : null)]) ?>
    </div></div>
    <?php if ($x['status'] === 'pending'): ?>
    <div class="card isp-card"><div class="card-body">
      <h2 class="sec-title">Decision</h2>
      <?php if (!$mayDecide): ?><p class="mb-0 text-muted">Waiting for a user with the approval right.</p>
      <?php elseif ((int)$x['requested_by'] === (int)Auth::id() && $x['adj_type'] === 'write_off' && !Access::isAdmin()): ?><p class="mb-0 text-muted">A write-off is approved by someone other than the user who asked for it.</p>
      <?php else: ?>
      <?php if ($limit !== null && (float)$x['amount'] > $limit && !Access::isAdmin()): ?><div class="alert alert-warning py-2">This is above your approval limit of <?= money($limit) ?>: it needs a user with a higher limit.</div><?php endif; ?>
      <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
        <div class="col-md-8"><input class="form-control form-control-sm" name="note" placeholder="Note (required to reject)" maxlength="250"></div>
        <div class="col-md-4 d-flex gap-1"><button class="btn btn-sm btn-success" name="action" value="approve" data-confirm="Approve and post this adjustment?"><i class="bi bi-check2"></i> Approve</button>
          <button class="btn btn-sm btn-outline-danger" name="action" value="reject"><i class="bi bi-x-lg"></i> Reject</button></div></form>
      <?php endif; ?>
    </div></div>
    <?php endif;
    layout_end();
    return;
}

// ================================================================ request, queue, history
$tab = (string)g('tab', 'request');
$nPending = (int)DB::value("SELECT COUNT(*) FROM isp_adjustments WHERE status = 'pending'");
$tabs = ['request' => 'New request', 'queue' => 'Waiting for approval' . ($nPending ? ' (' . $nPending . ')' : ''), 'history' => 'History'];
if (!isset($tabs[$tab]) || ($tab === 'request' && !Access::can($pk, 'create'))) {
    $tab = 'queue';
}
layout_start('Adjustments & Write-offs');
echo IspUi::tabs($tabs, $tab, $pk);

if ($tab === 'request') {
    $acctId = IspUi::postedAccount('account_id') ?? gint('account_id');
    $acct = $acctId ? IspAccounts::get($acctId) : null;
    if (!$acct): ?>
    <form class="card isp-card" method="get" action="<?= h(base_url() . '/index.php') ?>"><input type="hidden" name="p" value="<?= h($pk) ?>">
      <div class="card-body row g-2 align-items-end">
        <?= IspUi::accountField('account_id', null, 'Subscriber account', 'col-md-9') ?>
        <div class="col-md-3 d-grid"><button class="btn btn-sm btn-primary">Continue</button></div></div></form>
    <?php else:
        echo ispbill_account_panel($acct);
        $invs = DB::all("SELECT i.*, (SELECT COALESCE(SUM(x.amount), 0) FROM isp_adjustments x WHERE x.invoice_id = i.id AND x.status IN ('pending','approved') AND x.adj_type <> 'debit') AS waiting
                           FROM isp_invoices i WHERE i.account_id = :a AND i.status IN ('posted','partially_paid','overdue','disputed') AND i.balance > 0 ORDER BY i.due_date", ['a' => $acct['id']]);
        $invOpts = [];
        foreach ($invs as $i) {
            $invOpts[$i['id']] = $i['invoice_no'] . ', due ' . fdate($i['due_date']) . ', open ' . money($i['balance']) . ((float)$i['waiting'] > 0 ? ' (' . money($i['waiting']) . ' credit waiting)' : '');
        }
        ?>
    <div class="card isp-card"><div class="card-body">
      <h2 class="sec-title">Request an adjustment</h2>
      <form method="post" class="row g-2">
        <?= csrf_field() ?><input type="hidden" name="action" value="request"><input type="hidden" name="account_id" value="<?= (int)$acct['id'] ?>">
        <?= select_field('adj_type', 'Kind', ISPBILL_ADJ_REQUEST, fv('adj_type', null, 'credit'), ['class' => 'col-md-4', 'extra' => 'id="adjType"']) ?>
        <div class="col-md-5" id="adjInv"><label class="form-label">Invoice credited</label>
          <select class="form-select form-select-sm" name="invoice_id"><?= options($invOpts, fv('invoice_id', null, gint('invoice_id')), $invOpts ? null : '(no open invoice)') ?></select></div>
        <?= input_field('amount', 'Amount', fv('amount'), ['class' => 'col-md-3', 'required' => true, 'extra' => 'inputmode="decimal"']) ?>
        <?= input_field('reason', 'Reason (printed on the statement, recorded in the audit trail)', fv('reason'), ['class' => 'col-12', 'required' => true, 'extra' => 'maxlength="250"']) ?>
        <div class="col-12 small text-muted">A credit, write-off, penalty waiver or service credit is applied to the chosen invoice once approved. A debit adjustment is added to the account's next invoice.
          <?= $limit !== null ? 'Your approval limit is ' . money($limit) . '.' : '' ?></div>
        <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-send"></i> Request adjustment</button> <?= btn_link(url($pk), 'Other account') ?></div>
      </form>
      <script>(function () { var t = document.getElementById('adjType'), b = document.getElementById('adjInv'); function s() { b.hidden = t.value === 'debit'; } t.addEventListener('change', s); s(); })();</script>
    </div></div>
    <?php endif;
    layout_end();
    return;
}

$p = [];
$where = '1 = 1';
if ($tab === 'queue') {
    $where = "x.status = 'pending'";
} else {
    $from = gdate('from', date('Y-m-d', strtotime('-90 days')));
    $to = gdate('to', today());
    $where = 'x.created_at BETWEEN :f AND :t';
    $p = ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59'];
    if (($t = (string)g('adj_type')) !== '' && isset(IspCredit::ADJ_TYPES[$t])) {
        $where .= ' AND x.adj_type = :ty';
        $p['ty'] = $t;
    }
    if (($s = (string)g('status')) !== '' && isset(IspCredit::ADJ_STATUSES[$s])) {
        $where .= ' AND x.status = :st';
        $p['st'] = $s;
    }
    if (($q = trim((string)g('q'))) !== '') {
        $where .= ' AND (x.adj_no LIKE :q1 OR a.account_no LIKE :q2 OR a.account_name LIKE :q3 OR x.reason LIKE :q4)';
        $p += ['q1' => "%$q%", 'q2' => "$q%", 'q3' => "%$q%", 'q4' => "%$q%"];
    }
    echo IspUi::filterStart($pk, ['tab' => 'history']) . IspUi::fSearch((string)g('q'), 'Number, account or reason') . IspUi::fDate('from', 'From', $from) . IspUi::fDate('to', 'To', $to)
        . IspUi::fSelect('adj_type', 'Kind', IspCredit::ADJ_TYPES, (string)g('adj_type')) . IspUi::fSelect('status', 'Status', IspCredit::ADJ_STATUSES, (string)g('status'))
        . IspUi::filterEnd(url($pk, ['tab' => 'history']));
}
$where .= IspSetup::branchSql('a.branch_id', $p);
[$per, $off, $page] = IspUi::paging(50);
$sum = DB::row("SELECT COUNT(*) AS n, COALESCE(SUM(x.amount), 0) AS amt FROM isp_adjustments x JOIN isp_accounts a ON a.id = x.account_id WHERE $where", $p);
$rows = DB::all("SELECT x.*, a.account_no, a.account_name, i.invoice_no, u.full_name AS requester FROM isp_adjustments x JOIN isp_accounts a ON a.id = x.account_id
                  LEFT JOIN isp_invoices i ON i.id = x.invoice_id LEFT JOIN users u ON u.id = x.requested_by WHERE $where ORDER BY x.id " . ($tab === 'queue' ? 'ASC' : 'DESC') . " LIMIT $per OFFSET $off", $p);
?>
<div class="card isp-card"><div class="card-body">
  <?php if ($tab === 'queue' && $limit !== null && $mayDecide): ?><p class="small text-muted">Your approval limit is <?= money($limit) ?> per adjustment.</p><?php endif; ?>
  <?php if (!$rows): echo IspUi::empty($tab === 'queue' ? 'Nothing is waiting for approval.' : 'No adjustment matches.', 'sliders'); else: ?>
  <form method="post"><?= csrf_field() ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
    <thead><tr><?php if ($tab === 'queue' && $mayDecide): ?><th><input class="form-check-input" type="checkbox" data-check-all="ids" aria-label="Tick all"></th><?php endif; ?>
      <th>No.</th><th>Date</th><th>Account</th><th>Kind</th><th>Invoice</th><th>Reason</th><th>Requested by</th><th>Status</th><th class="num">Amount</th></tr></thead>
    <tbody><?php foreach ($rows as $x): ?>
      <tr><?php if ($tab === 'queue' && $mayDecide): ?><td><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int)$x['id'] ?>" <?= (int)$x['requested_by'] === (int)Auth::id() && $x['adj_type'] === 'write_off' && !Access::isAdmin() ? 'disabled' : '' ?>></td><?php endif; ?>
        <td><a href="<?= h(url($pk, ['id' => $x['id']])) ?>"><?= h($x['adj_no']) ?></a></td><td class="text-nowrap"><?= h(fdate($x['created_at'])) ?></td>
        <td><?= IspUi::acct($x['account_id'], $x['account_no'], $x['account_name']) ?></td><td class="small"><?= h(IspCredit::ADJ_TYPES[$x['adj_type']] ?? $x['adj_type']) ?></td>
        <td><?= $x['invoice_id'] ? safe_link('ispbill.invoices', ['id' => $x['invoice_id']], (string)$x['invoice_no']) : '' ?></td><td class="small"><?= h($x['reason']) ?></td>
        <td class="small"><?= h((string)$x['requester']) ?></td><td><?= IspUi::status($x['status'], IspCredit::ADJ_STATUSES) ?></td>
        <td class="num <?= $limit !== null && (float)$x['amount'] > $limit && $x['status'] === 'pending' ? 'text-warning-emphasis' : '' ?>"><?= money($x['amount']) ?></td></tr>
    <?php endforeach; ?></tbody>
    <tfoot><tr><th colspan="<?= $tab === 'queue' && $mayDecide ? 9 : 8 ?>">Total (<?= (int)$sum['n'] ?>)</th><th class="num"><?= money($sum['amt']) ?></th></tr></tfoot>
  </table></div>
  <?php if ($tab === 'queue' && $mayDecide): ?>
  <div class="row g-2 align-items-end"><div class="col-md-6"><input class="form-control form-control-sm" name="note" placeholder="Note (required to reject)" maxlength="250"></div>
    <div class="col-md-6 d-flex gap-1"><button class="btn btn-sm btn-success" name="action" value="approve" data-confirm="Approve and post the ticked adjustments?"><i class="bi bi-check2-all"></i> Approve ticked</button>
      <button class="btn btn-sm btn-outline-danger" name="action" value="reject" data-confirm="Reject the ticked adjustments?"><i class="bi bi-x-lg"></i> Reject ticked</button></div></div>
  <?php endif; ?>
  </form>
  <?= IspUi::pager((int)$sum['n'], $per, $page) ?>
  <?php endif; ?>
</div></div>
<?php
layout_end();
