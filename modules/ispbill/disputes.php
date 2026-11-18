<?php
declare(strict_types=1);

/**
 * Disputes, Arrangements & Exemptions: billing disputes with their investigation and resolution (with or without a
 * credit), payment arrangements (extensions, installments, partial settlements) with their schedule, approval and
 * consequence of non-compliance, and temporary disconnection exemptions.
 */
require_once __DIR__ . '/_lib.php';

$pk = 'ispbill.disputes';
$tab = (string)g('tab', 'disputes');
if (!in_array($tab, ['disputes', 'arrangements', 'exemptions'], true)) {
    $tab = 'disputes';
}
$id = gint('id');
$arrId = gint('arr');
$mayProtect = (bool)Access::special('isp_exempt');
const ISPBILL_LINE_STATUSES = ['open' => 'Open', 'paid' => 'Paid', 'missed' => 'Missed'];

handle_post(function () use ($pk, $mayProtect) {
    $action = (string)p('action');
    switch ($action) {
        case 'open_dispute':
            Access::require($pk, 'create');
            $acct = IspUi::postedAccount('account_id') ?? fail('Choose the subscriber account.');
            $protect = p('protect') === '1';
            if ($protect && !$mayProtect) {
                fail('Protecting an account from disconnection while a dispute is investigated needs the permission to approve dispute protection.');
            }
            $did = IspCredit::openDispute($acct, pint('invoice_id') ?: null, pnum('amount_disputed'), trim((string)p('reason')), $protect);
            redirect(url($pk, ['id' => $did]), 'Dispute opened.', 'success');
        case 'note':
            Access::require($pk, 'edit');
            if (trim((string)p('note')) === '') {
                fail('Write the investigation note.');
            }
            IspCredit::noteDispute((int)p('id'), trim((string)p('note')));
            redirect(url($pk, ['id' => (int)p('id')]), 'Note added; the dispute is under investigation.', 'success');
        case 'resolve':
            Access::require($pk, 'approve');
            IspCredit::resolveDispute((int)p('id'), (string)p('outcome'), pnum('credit'), trim((string)p('resolution')));
            redirect(url($pk, ['id' => (int)p('id')]), p('outcome') === 'resolved_credit' ? 'Dispute resolved. The credit waits for approval in Adjustments & Write-offs.' : 'Dispute resolved.', 'success');
        case 'create_arrangement':
            Access::require($pk, 'create');
            $acct = IspUi::postedAccount('account_id') ?? fail('Choose the subscriber account.');
            $sched = [];
            foreach ((array)($_POST['due'] ?? []) as $i => $due) {
                $amt = to_num($_POST['amt'][$i] ?? 0);
                if (trim((string)$due) !== '' || $amt > 0) {
                    if (!valid_date((string)$due) || $amt <= 0) {
                        fail('Each payment of the schedule needs a due date and an amount.');
                    }
                    $sched[] = [(string)$due, $amt];
                }
            }
            $protect = p('protect') === '1';
            if (($protect || p('waive') === '1') && !$mayProtect) {
                fail('Disconnection protection and penalty waivers are given by a user with the permission to approve arrangements.');
            }
            $rid = IspCredit::createArrangement($acct, (string)p('arr_type'), $sched, $protect, p('waive') === '1', trim((string)p('notes')), trim((string)p('consequence')) ?: null);
            redirect(url($pk, ['tab' => 'arrangements', 'arr' => $rid]), 'Arrangement recorded; it takes effect once approved.', 'success');
        case 'decide_arrangement':
            Access::require($pk, 'approve');
            IspCredit::decideArrangement((int)p('arr'), p('decision') === 'approve', trim((string)p('note')));
            redirect(url($pk, ['tab' => 'arrangements', 'arr' => (int)p('arr')]), p('decision') === 'approve' ? 'Arrangement approved and in force.' : 'Arrangement rejected.', 'success');
        case 'grant_exemption':
            Access::require($pk, 'create');
            $acct = IspUi::postedAccount('account_id') ?? fail('Choose the subscriber account.');
            IspCredit::grantExemption($acct, (string)p('valid_from'), (string)p('valid_until'), trim((string)p('reason')));
            redirect(url($pk, ['tab' => 'exemptions']), 'Exemption granted: the account is not disconnected in that period.', 'success');
        case 'revoke_exemption':
            Access::require($pk, 'edit');
            if (!$mayProtect) {
                fail('Revoking an exemption needs the permission to approve disconnection exemptions.');
            }
            if (trim((string)p('reason')) === '') {
                fail('Enter why the exemption is revoked.');
            }
            IspCredit::revokeExemption((int)p('ex_id'), trim((string)p('reason')));
            Audit::log('revoke', 'ispbill', 'isp_exemption', (int)p('ex_id'), '', ['reason' => trim((string)p('reason'))]);
            redirect(url($pk, ['tab' => 'exemptions']), 'Exemption revoked.', 'success');
    }
});

/** The open invoices of an account for a drop-down: id => label */
function ispbill_open_invoice_opts(int $accountId): array
{
    $out = [];
    foreach (IspPayments::openInvoices($accountId) as $i) {
        $out[$i['id']] = $i['invoice_no'] . ', due ' . fdate($i['due_date']) . ', open ' . money($i['balance']);
    }
    return $out;
}

// ================================================================ one dispute
if ($id) {
    $d = DB::row('SELECT d.*, a.account_no, a.account_name, a.branch_id, i.invoice_no, i.balance AS inv_balance, uo.full_name AS opener, ur.full_name AS resolver, x.adj_no, x.status AS adj_status
                    FROM isp_disputes d JOIN isp_accounts a ON a.id = d.account_id LEFT JOIN isp_invoices i ON i.id = d.invoice_id LEFT JOIN users uo ON uo.id = d.opened_by
                    LEFT JOIN users ur ON ur.id = d.resolved_by LEFT JOIN isp_adjustments x ON x.id = d.adjustment_id WHERE d.id = :id', ['id' => $id]) ?? fail('Dispute not found.');
    if (IspSetup::userBranch() !== null && $d['branch_id'] && (int)$d['branch_id'] !== (int)IspSetup::userBranch()) {
        Access::denyPage($pk, 'open another branch\'s dispute');
    }
    $openD = in_array($d['status'], ['open', 'investigating'], true);
    layout_start('Dispute ' . $d['dispute_no'], ['actions' => btn_link(url($pk), 'All disputes', 'btn-outline-secondary', 'list-ul')]);
    ?>
    <div class="card isp-card mb-3"><div class="card-body">
      <div class="isp-head"><div><h2 class="isp-head-title"><?= money($d['amount_disputed']) ?> <span class="fw-normal text-muted">disputed</span></h2>
        <div class="isp-head-sub"><?= IspUi::status($d['status'], IspCredit::DISPUTE_STATUSES) ?> <span class="ms-2"><?= IspUi::acct($d['account_id'], $d['account_no'], $d['account_name']) ?></span></div></div></div>
      <?= IspUi::dl(['What is disputed' => h($d['reason']), 'Invoice' => $d['invoice_id'] ? safe_link('ispbill.invoices', ['id' => $d['invoice_id']], (string)$d['invoice_no']) . ' (open ' . money($d['inv_balance']) . ')' : 'The account in general',
          'Disconnection' => (int)$d['protect'] ? 'Protected while the dispute is open' : 'Not protected', 'Opened' => h(trim($d['opener'] . ', ' . fdatetime($d['created_at']), ', ')),
          'Resolution' => h((string)$d['resolution']), 'Resolved' => $d['resolved_at'] ? h($d['resolver'] . ', ' . fdatetime($d['resolved_at'])) : '',
          'Credit' => $d['adjustment_id'] ? safe_link('ispbill.adjustments', ['id' => $d['adjustment_id']], (string)$d['adj_no']) . ' ' . IspUi::status($d['adj_status'], IspCredit::ADJ_STATUSES) : '']) ?>
    </div></div>
    <div class="row g-3">
      <div class="col-lg-6"><div class="card isp-card h-100"><div class="card-body">
        <h2 class="sec-title">Investigation</h2>
        <?php $notes = array_filter(explode("\n", (string)$d['investigation'])); if (!$notes): ?><p class="small text-muted">No note yet.</p><?php else: ?>
        <ul class="list-unstyled small"><?php foreach ($notes as $n): ?><li class="mb-2 border-start ps-2"><?= h($n) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if ($openD && Access::can($pk, 'edit')): ?>
        <form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="note"><input type="hidden" name="id" value="<?= (int)$id ?>">
          <input class="form-control form-control-sm" name="note" placeholder="What was checked, who was contacted" maxlength="400" required><button class="btn btn-sm btn-outline-primary">Add</button></form>
        <?php endif; ?>
      </div></div></div>
      <div class="col-lg-6"><div class="card isp-card h-100"><div class="card-body">
        <h2 class="sec-title">Resolution</h2>
        <?php if ($openD && Access::can($pk, 'approve')): ?>
        <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="resolve"><input type="hidden" name="id" value="<?= (int)$id ?>">
          <?= select_field('outcome', 'Outcome', array_diff_key(IspCredit::DISPUTE_STATUSES, ['open' => 1, 'investigating' => 1]), fv('outcome', null, 'resolved_credit'), ['class' => 'col-md-7', 'extra' => 'id="outcome"']) ?>
          <div class="col-md-5" id="creditBox"><label class="form-label">Credit to give</label><input class="form-control form-control-sm" name="credit" inputmode="decimal" value="<?= h((string)fv('credit', null, $d['amount_disputed'])) ?>"></div>
          <?= input_field('resolution', 'Resolution (the subscriber is told)', fv('resolution'), ['class' => 'col-12', 'required' => true, 'extra' => 'maxlength="250"']) ?>
          <div class="col-12"><button class="btn btn-sm btn-primary" data-confirm="Resolve the dispute?"><i class="bi bi-check2"></i> Resolve</button></div>
          <div class="col-12 small text-muted">A credit becomes a dispute credit adjustment, approved in Adjustments & Write-offs like any other.</div>
        </form>
        <script>(function () { var o = document.getElementById('outcome'), b = document.getElementById('creditBox'); function s() { b.hidden = o.value !== 'resolved_credit'; } o.addEventListener('change', s); s(); })();</script>
        <?php elseif ($openD): ?><p class="text-muted small">Waiting for the resolution by a supervisor.</p>
        <?php else: ?><p class="mb-0"><?= h((string)$d['resolution']) ?></p><?php endif; ?>
      </div></div></div>
    </div>
    <?php
    layout_end();
    return;
}

// ================================================================ one arrangement
if ($arrId) {
    $r = DB::row('SELECT r.*, a.account_no, a.account_name, a.branch_id, uc.full_name AS creator, ua.full_name AS approver FROM isp_arrangements r JOIN isp_accounts a ON a.id = r.account_id
                    LEFT JOIN users uc ON uc.id = r.created_by LEFT JOIN users ua ON ua.id = r.approved_by WHERE r.id = :id', ['id' => $arrId]) ?? fail('Arrangement not found.');
    if (IspSetup::userBranch() !== null && $r['branch_id'] && (int)$r['branch_id'] !== (int)IspSetup::userBranch()) {
        Access::denyPage($pk, 'open another branch\'s arrangement');
    }
    $lines = DB::all('SELECT * FROM isp_arrangement_lines WHERE arrangement_id = :r ORDER BY due_date', ['r' => $arrId]);
    $paid = array_sum(array_map(fn($l) => (float)$l['paid_amount'], $lines));
    layout_start('Arrangement ' . $r['arrangement_no'], ['actions' => btn_link(url($pk, ['tab' => 'arrangements']), 'All arrangements', 'btn-outline-secondary', 'list-ul')]);
    ?>
    <div class="card isp-card mb-3"><div class="card-body">
      <div class="isp-head"><div><h2 class="isp-head-title"><?= money($r['total_amount']) ?> <span class="fw-normal text-muted"><?= h(IspCredit::ARR_TYPES[$r['arr_type']] ?? $r['arr_type']) ?></span></h2>
        <div class="isp-head-sub"><?= IspUi::status($r['status'], IspCredit::ARR_STATUSES) ?> <span class="ms-2"><?= IspUi::acct($r['account_id'], $r['account_no'], $r['account_name']) ?></span></div></div></div>
      <div class="kpi-grid mt-2">
        <?= IspUi::kpi('Agreed', money($r['total_amount']), count($lines) . ' payment(s)', null, 'calendar2-check', 'info') ?>
        <?= IspUi::kpi('Paid so far', money($paid), $r['total_amount'] > 0 ? round($paid / (float)$r['total_amount'] * 100) . '% of the agreement' : '', null, 'check2-circle', 'ok') ?>
        <?= IspUi::kpi('Ends', fdate($r['expires_on']), 'last due date', null, 'hourglass', 'idle') ?>
      </div>
      <?= IspUi::dl(['Disconnection' => (int)$r['protect'] ? 'Protected while the arrangement is kept' : 'Not protected', 'Penalties' => (int)$r['waive_penalties'] ? 'Late charges waived while it is kept' : 'Late charges apply',
          'If a payment is missed' => h((string)($r['consequence'] ?: 'The arrangement ends and the account follows the normal collection and disconnection rules.')),
          'Recorded' => h(trim($r['creator'] . ', ' . fdatetime($r['created_at']), ', ')), 'Decided' => $r['approved_at'] ? h($r['approver'] . ', ' . fdatetime($r['approved_at'])) : '', 'Notes' => h((string)$r['notes'])]) ?>
    </div></div>
    <div class="card isp-card mb-3"><div class="card-body">
      <h2 class="sec-title">Schedule</h2>
      <table class="table table-sm isp-table"><thead><tr><th>Due</th><th>Status</th><th class="num">Amount</th><th class="num">Paid</th></tr></thead><tbody>
      <?php foreach ($lines as $l): ?><tr><td class="<?= $l['status'] === 'open' && $l['due_date'] < today() ? 'text-danger' : '' ?>"><?= h(fdate($l['due_date'])) ?></td><td><?= IspUi::status($l['status'], ISPBILL_LINE_STATUSES) ?></td>
        <td class="num"><?= money($l['amount']) ?></td><td class="num"><?= money($l['paid_amount']) ?></td></tr><?php endforeach; ?>
      </tbody></table>
    </div></div>
    <?php if ($r['status'] === 'pending' && Access::can($pk, 'approve') && $mayProtect): ?>
    <div class="card isp-card"><div class="card-body"><h2 class="sec-title">Decision</h2>
      <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="decide_arrangement"><input type="hidden" name="arr" value="<?= (int)$arrId ?>">
        <div class="col-md-8"><input class="form-control form-control-sm" name="note" placeholder="Note (optional)" maxlength="200"></div>
        <div class="col-md-4 d-flex gap-1"><button class="btn btn-sm btn-success" name="decision" value="approve" data-confirm="Approve the arrangement? A waiting disconnection of the account is cancelled."><i class="bi bi-check2"></i> Approve</button>
          <button class="btn btn-sm btn-outline-danger" name="decision" value="reject"><i class="bi bi-x-lg"></i> Reject</button></div></form></div></div>
    <?php endif;
    layout_end();
    return;
}

// ================================================================ the tabs
$counts = DB::row("SELECT (SELECT COUNT(*) FROM isp_disputes WHERE status IN ('open','investigating')) AS d, (SELECT COUNT(*) FROM isp_arrangements WHERE status IN ('pending','active')) AS r,
                          (SELECT COUNT(*) FROM isp_exemptions WHERE status = 'active' AND valid_until >= CURDATE()) AS e");
layout_start('Disputes, Arrangements & Exemptions');
echo IspUi::tabs(['disputes' => 'Disputes' . ((int)$counts['d'] ? ' (' . (int)$counts['d'] . ')' : ''), 'arrangements' => 'Payment arrangements' . ((int)$counts['r'] ? ' (' . (int)$counts['r'] . ')' : ''),
    'exemptions' => 'Disconnection exemptions' . ((int)$counts['e'] ? ' (' . (int)$counts['e'] . ')' : '')], $tab, $pk);
$acctId = IspUi::postedAccount('account_id') ?? gint('account_id');
$acct = $acctId ? IspAccounts::get($acctId) : null;
$pickForm = function (string $tab, string $label) use ($pk): string {
    return '<form class="card isp-card mb-3" method="get" action="' . h(base_url() . '/index.php') . '"><input type="hidden" name="p" value="' . h($pk) . '"><input type="hidden" name="tab" value="' . h($tab) . '">'
        . '<div class="card-body row g-2 align-items-end"><div class="col-12"><h2 class="sec-title">' . h($label) . '</h2></div>' . IspUi::accountField('account_id', null, 'Subscriber account', 'col-md-9')
        . '<div class="col-md-3 d-grid"><button class="btn btn-sm btn-primary">Continue</button></div></div></form>';
};
$bp = [];
$branchSql = IspSetup::branchSql('a.branch_id', $bp);

if ($tab === 'disputes') {
    if (Access::can($pk, 'create')) {
        if (!$acct) {
            echo $pickForm('disputes', 'Open a dispute');
        } else { ?>
        <div class="card isp-card mb-3"><div class="card-body">
          <h2 class="sec-title">Open a dispute for <?= IspUi::acct($acct['id'], $acct['account_no'], $acct['account_name']) ?></h2>
          <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="open_dispute"><input type="hidden" name="account_id" value="<?= (int)$acct['id'] ?>">
            <?php $opts = ispbill_open_invoice_opts((int)$acct['id']); ?>
            <?= select_field('invoice_id', 'Invoice disputed', $opts, fv('invoice_id', null, gint('invoice_id')), ['class' => 'col-md-6', 'blank' => 'The account in general']) ?>
            <?= input_field('amount_disputed', 'Amount disputed', fv('amount_disputed'), ['class' => 'col-md-3', 'required' => true, 'extra' => 'inputmode="decimal"']) ?>
            <?php if ($mayProtect): ?><?= check_field('protect', 'Protect from disconnection while open', fv('protect') === '1', 'col-md-3') ?><?php endif; ?>
            <?= input_field('reason', 'What the subscriber disputes', fv('reason'), ['class' => 'col-12', 'required' => true, 'extra' => 'maxlength="250"']) ?>
            <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-chat-left-text"></i> Open dispute</button> <?= btn_link(url($pk), 'Other account') ?></div>
          </form></div></div>
        <?php }
    }
    $st = (string)g('status', 'live');
    $p = $bp;
    $where = $st === 'live' ? "d.status IN ('open','investigating')" : ($st !== '' && isset(IspCredit::DISPUTE_STATUSES[$st]) ? 'd.status = :s' : '1 = 1');
    if ($st !== 'live' && isset(IspCredit::DISPUTE_STATUSES[$st])) {
        $p['s'] = $st;
    }
    [$per, $off, $page] = IspUi::paging(50);
    $total = (int)DB::value("SELECT COUNT(*) FROM isp_disputes d JOIN isp_accounts a ON a.id = d.account_id WHERE $where$branchSql", $p);
    $rows = DB::all("SELECT d.*, a.account_no, a.account_name, i.invoice_no FROM isp_disputes d JOIN isp_accounts a ON a.id = d.account_id LEFT JOIN isp_invoices i ON i.id = d.invoice_id
                      WHERE $where$branchSql ORDER BY d.id DESC LIMIT $per OFFSET $off", $p);
    echo IspUi::filterStart($pk, ['tab' => 'disputes']) . IspUi::fSelect('status', 'Status', ['live' => 'Open and investigating'] + IspCredit::DISPUTE_STATUSES, $st, 'All') . IspUi::filterEnd();
    ?>
    <div class="card isp-card"><div class="card-body">
      <?php if (!$rows): echo IspUi::empty('No dispute.', 'chat-left-text'); else: ?>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
        <thead><tr><th>Dispute</th><th>Opened</th><th>Account</th><th>Invoice</th><th>Reason</th><th>Protected</th><th>Status</th><th class="num">Disputed</th></tr></thead>
        <tbody><?php foreach ($rows as $d): ?>
          <tr><td><a href="<?= h(url($pk, ['id' => $d['id']])) ?>"><?= h($d['dispute_no']) ?></a></td><td class="text-nowrap"><?= h(fdate($d['created_at'])) ?></td>
            <td><?= IspUi::acct($d['account_id'], $d['account_no'], $d['account_name']) ?></td><td><?= h((string)$d['invoice_no']) ?></td><td class="small"><?= h($d['reason']) ?></td>
            <td><?= (int)$d['protect'] ? 'Yes' : 'No' ?></td><td><?= IspUi::status($d['status'], IspCredit::DISPUTE_STATUSES) ?></td><td class="num"><?= money($d['amount_disputed']) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <?= IspUi::pager($total, $per, $page) ?>
      <?php endif; ?>
    </div></div>
    <?php
} elseif ($tab === 'arrangements') {
    if (Access::can($pk, 'create')) {
        if (!$acct) {
            echo $pickForm('arrangements', 'Record a payment arrangement');
        } else {
            $bal = IspAccounts::balances((int)$acct['id']);
            $dues = array_values((array)($_POST['due'] ?? []));
            $amts = array_values((array)($_POST['amt'] ?? [])); ?>
        <div class="card isp-card mb-3"><div class="card-body">
          <h2 class="sec-title">Payment arrangement for <?= IspUi::acct($acct['id'], $acct['account_no'], $acct['account_name']) ?></h2>
          <p class="small text-muted">Balance due <?= money($bal['balance']) ?>, of which <?= money($bal['overdue']) ?> overdue.</p>
          <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="create_arrangement"><input type="hidden" name="account_id" value="<?= (int)$acct['id'] ?>">
            <?= select_field('arr_type', 'Kind', IspCredit::ARR_TYPES, fv('arr_type', null, 'installment'), ['class' => 'col-md-4']) ?>
            <div class="col-md-8"><label class="form-label">Split a total into payments</label>
              <div class="input-group input-group-sm"><input class="form-control" id="splTotal" placeholder="Total" inputmode="decimal" value="<?= h(number_format(max(0, $bal['balance']), 2, '.', '')) ?>">
                <input class="form-control" id="splN" type="number" min="1" max="12" value="3" title="Number of payments"><input class="form-control" id="splFirst" type="date" value="<?= h(IspPlans::addDays(today(), 7)) ?>" title="First due date">
                <select class="form-select" id="splEvery" title="Interval"><option value="7">weekly</option><option value="15">every 15 days</option><option value="30" selected>monthly</option></select>
                <button class="btn btn-outline-secondary" type="button" id="splGo">Fill schedule</button></div></div>
            <div class="col-12"><div class="table-responsive"><table class="table table-sm align-middle isp-table mb-0" id="schedTable">
              <thead><tr><th style="width:3rem">#</th><th>Due date</th><th>Amount</th></tr></thead><tbody>
              <?php for ($i = 0; $i < 12; $i++): ?><tr><td><?= $i + 1 ?></td><td><input class="form-control form-control-sm" type="date" name="due[<?= $i ?>]" value="<?= h((string)($dues[$i] ?? '')) ?>"></td>
                <td><input class="form-control form-control-sm text-end" name="amt[<?= $i ?>]" inputmode="decimal" value="<?= h((string)($amts[$i] ?? '')) ?>"></td></tr><?php endfor; ?>
              </tbody><tfoot><tr><th colspan="2">Total</th><th class="text-end" id="schedSum">0.00</th></tr></tfoot></table></div></div>
            <?php if ($mayProtect): ?>
            <?= check_field('protect', 'Protect from disconnection while kept', fv('protect', null, '1') === '1', 'col-md-4') ?>
            <?= check_field('waive', 'Waive late charges while kept', fv('waive') === '1', 'col-md-4') ?>
            <?php endif; ?>
            <?= input_field('consequence', 'Consequence if a payment is missed', fv('consequence', null, 'The arrangement ends; the account is disconnected under the normal rules and the whole balance becomes due.'), ['class' => 'col-12', 'extra' => 'maxlength="250"']) ?>
            <?= input_field('notes', 'Notes', fv('notes'), ['class' => 'col-12', 'extra' => 'maxlength="250"']) ?>
            <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-calendar2-check"></i> Record arrangement</button> <?= btn_link(url($pk, ['tab' => 'arrangements']), 'Other account') ?></div>
          </form>
          <script>
          (function () {
            var t = document.getElementById('schedTable');
            function sum() { var s = 0; t.querySelectorAll('input[name^="amt"]').forEach(function (i) { s += parseFloat(String(i.value).replace(/,/g, '')) || 0; });
              document.getElementById('schedSum').textContent = s.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
            t.addEventListener('input', sum);
            document.getElementById('splGo').addEventListener('click', function () {
              var tot = parseFloat(String(document.getElementById('splTotal').value).replace(/,/g, '')) || 0, n = Math.max(1, Math.min(12, parseInt(document.getElementById('splN').value, 10) || 1));
              var first = document.getElementById('splFirst').value, every = parseInt(document.getElementById('splEvery').value, 10);
              var each = Math.floor(tot / n * 100) / 100, dues = t.querySelectorAll('input[name^="due"]'), amts = t.querySelectorAll('input[name^="amt"]');
              for (var i = 0; i < 12; i++) {
                if (i < n && first) {
                  var d = new Date(first + 'T00:00:00');
                  if (every === 30) d.setMonth(d.getMonth() + i); else d.setDate(d.getDate() + every * i);
                  dues[i].value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
                  amts[i].value = (i === n - 1 ? Math.round((tot - each * (n - 1)) * 100) / 100 : each).toFixed(2);
                } else { dues[i].value = ''; amts[i].value = ''; }
              }
              sum();
            });
            sum();
          })();
          </script>
        </div></div>
        <?php }
    }
    $st = (string)g('status', 'live');
    $p = $bp;
    $where = $st === 'live' ? "r.status IN ('pending','active')" : (isset(IspCredit::ARR_STATUSES[$st]) ? 'r.status = :s' : '1 = 1');
    if ($st !== 'live' && isset(IspCredit::ARR_STATUSES[$st])) {
        $p['s'] = $st;
    }
    $rows = DB::all("SELECT r.*, a.account_no, a.account_name, (SELECT COALESCE(SUM(l.paid_amount), 0) FROM isp_arrangement_lines l WHERE l.arrangement_id = r.id) AS paid,
                            (SELECT MIN(l.due_date) FROM isp_arrangement_lines l WHERE l.arrangement_id = r.id AND l.status = 'open') AS next_due
                       FROM isp_arrangements r JOIN isp_accounts a ON a.id = r.account_id WHERE $where$branchSql ORDER BY r.id DESC LIMIT 300", $p);
    echo IspUi::filterStart($pk, ['tab' => 'arrangements']) . IspUi::fSelect('status', 'Status', ['live' => 'Pending and active'] + IspCredit::ARR_STATUSES, $st, 'All') . IspUi::filterEnd();
    ?>
    <div class="card isp-card"><div class="card-body">
      <?php if (!$rows): echo IspUi::empty('No payment arrangement.', 'calendar2-check'); else: ?>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
        <thead><tr><th>No.</th><th>Account</th><th>Kind</th><th>Next due</th><th>Ends</th><th>Protected</th><th>Status</th><th class="num">Agreed</th><th class="num">Paid</th></tr></thead>
        <tbody><?php foreach ($rows as $r): ?>
          <tr><td><a href="<?= h(url($pk, ['tab' => 'arrangements', 'arr' => $r['id']])) ?>"><?= h($r['arrangement_no']) ?></a></td><td><?= IspUi::acct($r['account_id'], $r['account_no'], $r['account_name']) ?></td>
            <td class="small"><?= h(IspCredit::ARR_TYPES[$r['arr_type']] ?? $r['arr_type']) ?></td><td class="<?= $r['next_due'] && $r['next_due'] < today() ? 'text-danger' : '' ?>"><?= h(fdate($r['next_due'])) ?></td>
            <td><?= h(fdate($r['expires_on'])) ?></td><td><?= (int)$r['protect'] ? 'Yes' : 'No' ?></td><td><?= IspUi::status($r['status'], IspCredit::ARR_STATUSES) ?></td>
            <td class="num"><?= money($r['total_amount']) ?></td><td class="num"><?= money($r['paid']) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <?php endif; ?>
    </div></div>
    <?php
} else {
    if (Access::can($pk, 'create') && $mayProtect) {
        if (!$acct) {
            echo $pickForm('exemptions', 'Grant a disconnection exemption');
        } else { ?>
        <div class="card isp-card mb-3"><div class="card-body">
          <h2 class="sec-title">Exemption for <?= IspUi::acct($acct['id'], $acct['account_no'], $acct['account_name']) ?></h2>
          <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="grant_exemption"><input type="hidden" name="account_id" value="<?= (int)$acct['id'] ?>">
            <?= input_field('valid_from', 'From', fv('valid_from', null, today()), ['type' => 'date', 'class' => 'col-md-3', 'required' => true]) ?>
            <?= input_field('valid_until', 'Until', fv('valid_until', null, IspPlans::addDays(today(), 30)), ['type' => 'date', 'class' => 'col-md-3', 'required' => true]) ?>
            <?= input_field('reason', 'Reason (calamity, medical, government account, ...)', fv('reason'), ['class' => 'col-md-6', 'required' => true, 'extra' => 'maxlength="250"']) ?>
            <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-shield-check"></i> Grant exemption</button> <?= btn_link(url($pk, ['tab' => 'exemptions']), 'Other account') ?></div>
          </form></div></div>
        <?php }
    }
    $rows = DB::all("SELECT e.*, a.account_no, a.account_name, u.full_name AS approver FROM isp_exemptions e JOIN isp_accounts a ON a.id = e.account_id LEFT JOIN users u ON u.id = e.approved_by
                      WHERE 1 = 1$branchSql ORDER BY (e.status = 'active' AND e.valid_until >= CURDATE()) DESC, e.valid_until DESC LIMIT 300", $bp);
    ?>
    <div class="card isp-card"><div class="card-body">
      <p class="small text-muted">An account under an exemption is skipped by the disconnection engine for the whole period. Billing continues as usual.</p>
      <?php if (!$rows): echo IspUi::empty('No exemption.', 'shield-check'); else: ?>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
        <thead><tr><th>Account</th><th>From</th><th>Until</th><th>Reason</th><th>Approved by</th><th>Status</th><th></th></tr></thead>
        <tbody><?php foreach ($rows as $e): $live = $e['status'] === 'active' && $e['valid_until'] >= today(); ?>
          <tr><td><?= IspUi::acct($e['account_id'], $e['account_no'], $e['account_name']) ?></td><td><?= h(fdate($e['valid_from'])) ?></td><td><?= h(fdate($e['valid_until'])) ?></td>
            <td class="small"><?= h($e['reason']) ?></td><td class="small"><?= h((string)$e['approver']) ?></td>
            <td><?= IspUi::status($live ? 'active' : ($e['status'] === 'revoked' ? 'cancelled' : 'expired'), ['active' => 'In force', 'cancelled' => 'Revoked', 'expired' => 'Ended']) ?></td>
            <td class="text-end"><?php if ($live && Access::can($pk, 'edit') && $mayProtect): ?>
              <form method="post" class="d-flex gap-1 justify-content-end"><?= csrf_field() ?><input type="hidden" name="action" value="revoke_exemption"><input type="hidden" name="ex_id" value="<?= (int)$e['id'] ?>">
                <input class="form-control form-control-sm" style="max-width:12rem" name="reason" placeholder="Reason" required maxlength="200"><button class="btn btn-sm btn-outline-danger" data-confirm="Revoke the exemption?">Revoke</button></form>
            <?php endif; ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <?php endif; ?>
    </div></div>
    <?php
}
layout_end();
