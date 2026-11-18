<?php
declare(strict_types=1);

/**
 * Sales Agents & Commissions: the agents who bring in subscribers, their commission rules, the commissions earned on
 * activations and first payments, their approval and payment (a bill of the agent's supplier record), clawbacks on
 * early cancellations, and each agent's performance. An agent who logs in sees only his or her own figures.
 */
$pk = 'isp.agents';
$id = (int)g('id', (string)p('id'));
$a = (string)g('a');
$view = (string)g('view') === 'commissions' ? 'commissions' : 'agents';
$myAgent = Auth::id() ? DB::row('SELECT * FROM isp_agents WHERE user_id = :u', ['u' => Auth::id()]) : null;
$manager = Access::can($pk, 'edit') || Access::can($pk, 'approve') || Access::can($pk, 'create');
if (!$manager) {
    // an agent: own record only
    if (!$myAgent) {
        layout_start('Sales Agents & Commissions');
        echo IspUi::empty('Your user is not linked to a sales agent record. Ask the administrator to link it.', 'person-badge');
        layout_end();
        return;
    }
    $id = (int)$myAgent['id'];
    $a = '';
    $view = 'agents';
}

$saveAgent = function (?int $agentId): int {
    $f = ['code' => strtoupper(trim((string)p('code'))), 'name' => trim((string)p('name')), 'user_id' => pint('user_id'), 'vendor_id' => pint('vendor_id'),
        'mobile' => trim((string)p('mobile')) ?: null, 'email' => trim((string)p('email')) ?: null,
        'activation_type' => (string)p('activation_type'), 'activation_value' => round(pnum('activation_value'), 4),
        'first_payment_type' => (string)p('first_payment_type'), 'first_payment_value' => round(pnum('first_payment_value'), 4),
        'clawback_days' => max(0, min(3650, (int)p('clawback_days'))), 'is_active' => (int)(p('is_active') === '1')];
    if ($f['code'] === '' || $f['name'] === '') {
        fail('Enter the agent code and name.');
    }
    if (!preg_match('/^[A-Z0-9._-]{1,20}$/', $f['code'])) {
        fail('The code has letters, numbers, dots or dashes only (20 at most).');
    }
    if ($f['email'] !== null && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
        fail('The e-mail address is not valid.');
    }
    foreach (['activation_type', 'first_payment_type'] as $k) {
        if (!isset(IspAgents::TYPES[$f[$k]])) {
            fail('Choose how the commission is worked out.');
        }
    }
    foreach ([['activation_type', 'activation_value'], ['first_payment_type', 'first_payment_value']] as [$t, $v]) {
        if ($f[$v] < 0 || ($f[$t] === 'percent' && $f[$v] > 100)) {
            fail('A commission cannot be negative, and a percent is at most 100.');
        }
        if ($f[$t] === 'none') {
            $f[$v] = 0;
        }
    }
    if (DB::value('SELECT id FROM isp_agents WHERE code = :c AND id <> :i', ['c' => $f['code'], 'i' => (int)$agentId])) {
        fail('Another agent already has the code ' . $f['code'] . '.');
    }
    if ($f['user_id'] && DB::value('SELECT id FROM isp_agents WHERE user_id = :u AND id <> :i', ['u' => $f['user_id'], 'i' => (int)$agentId])) {
        fail('That user is already linked to another agent.');
    }
    if ($agentId) {
        $before = DB::row('SELECT * FROM isp_agents WHERE id = :i', ['i' => $agentId]) ?? fail('Agent not found.');
        DB::update('isp_agents', $f, 'id = :id', ['id' => $agentId]);
        Audit::log('edit', 'isp', 'isp_agent', $agentId, $f['code'], ['before' => $before, 'after' => $f]);
        return $agentId;
    }
    $newId = DB::insert('isp_agents', $f);
    Audit::log('create', 'isp', 'isp_agent', $newId, $f['code'], ['after' => $f]);
    return $newId;
};

handle_post(function () use ($pk, $id, $saveAgent, $manager) {
    $act = (string)p('action');
    if (!$manager) {
        Access::denyPage($pk, 'edit');
    }
    switch ($act) {
        case 'save':
            Access::require($pk, $id ? 'edit' : 'create');
            $sid = $saveAgent($id ?: null);
            redirect_after_save(url($pk, ['a' => 'new']), url($pk, ['id' => $sid]));
        case 'approve':
        case 'reject':
            Access::require($pk, 'approve');
            $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
            if (!$ids) {
                fail('Tick the commissions first.');
            }
            $note = trim((string)p('note'));
            if ($act === 'reject' && $note === '') {
                fail('Enter the reason for rejecting the commissions.');
            }
            $n = IspAgents::approve($ids, $act === 'approve', $note);
            Audit::log($act, 'isp', 'isp_commission', null, count($ids) . ' commission(s)', ['reason' => $note ?: null]);
            $back = (string)p('back');
            if (!str_starts_with($back, base_url() . '/index.php?')) {
                $back = url($pk, ['view' => 'commissions']);
            }
            redirect($back, $n . ' commission(s) ' . ($act === 'approve' ? 'approved' : 'rejected') . '.', 'success');
        case 'pay':
            Access::require($pk, 'approve');
            Access::require('vend.bills', 'create');
            $date = pdate('pay_date', today()) ?? today();
            $txn = IspAgents::pay($id, $date);
            $no = (string)(Txn::load($txn)['txn_no'] ?? '');
            redirect(url($pk, ['id' => $id]), 'The approved commissions were billed to accounts payable' . ($no !== '' ? ' (bill ' . $no . ')' : '') . '; pay the bill with Pay Bills.', 'success');
    }
    fail('Unknown action.');
});

$userOpts = DB::pairs("SELECT id, CONCAT(full_name, ' (', username, ')') FROM users WHERE status = 'active' AND is_platform = 0 AND created_via <> 'system' ORDER BY full_name");
$vendorOpts = DB::pairs('SELECT id, name FROM vendors WHERE is_active = 1 ORDER BY name');
$basisLabels = ['activation' => 'Activation', 'first_payment' => 'First payment', 'clawback_activation' => 'Clawback (activation)', 'clawback_first_payment' => 'Clawback (first payment)'];

// ================================================================ AGENT FORM
if ($a === 'new' || $a === 'edit' || (is_post() && p('action') === 'save')) {
    $ag = $id ? DB::row('SELECT * FROM isp_agents WHERE id = :i', ['i' => $id]) : null;
    if ($id && !$ag) {
        redirect(url($pk), 'Agent not found.', 'warning');
    }
    if (!Access::can($pk, $ag ? 'edit' : 'create')) {
        Access::denyPage($pk, $ag ? 'edit' : 'create');
    }
    $rec = $ag ?? ['code' => '', 'name' => '', 'activation_type' => 'fixed', 'activation_value' => '0', 'first_payment_type' => 'none', 'first_payment_value' => '0', 'clawback_days' => '90', 'is_active' => 1];
    layout_start($ag ? 'Agent ' . $ag['code'] : 'New Sales Agent', ['actions' => btn_link($ag ? url($pk, ['id' => $id]) : url($pk), 'Back', 'btn-outline-secondary', 'arrow-left')]);
    ?>
<form method="post" class="card isp-card"><div class="card-body">
  <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$id ?>">
  <div class="row g-2">
    <?= input_field('code', 'Code', fv('code', $rec), ['class' => 'col-md-3', 'required' => true]) ?>
    <?= input_field('name', 'Name', fv('name', $rec), ['class' => 'col-md-5', 'required' => true]) ?>
    <?= check_field('is_active', 'Active', fv('is_active', $rec), 'col-md-4') ?>
    <?= input_field('mobile', 'Mobile', fv('mobile', $rec), ['class' => 'col-md-4']) ?>
    <?= input_field('email', 'E-mail', fv('email', $rec), ['class' => 'col-md-4', 'type' => 'email']) ?>
    <?= select_field('user_id', 'User log-in (the agent sees own applications and commissions)', $userOpts, fv('user_id', $rec), ['class' => 'col-md-6', 'blank' => 'None']) ?>
    <?= select_field('vendor_id', 'Supplier record the commissions are paid to', $vendorOpts, fv('vendor_id', $rec), ['class' => 'col-md-6', 'blank' => 'Made at the first payout']) ?>
  </div>
  <h2 class="sec-title mt-4">Commission rules</h2>
  <div class="row g-2">
    <?= select_field('activation_type', 'On each activation', IspAgents::TYPES, fv('activation_type', $rec), ['class' => 'col-md-3']) ?>
    <?= input_field('activation_value', 'Amount or percent of the monthly fee', fv('activation_value', $rec), ['class' => 'col-md-3', 'type' => 'number', 'step' => '0.0001']) ?>
    <?= select_field('first_payment_type', 'On the first payment (optional)', IspAgents::TYPES, fv('first_payment_type', $rec), ['class' => 'col-md-3']) ?>
    <?= input_field('first_payment_value', 'Amount or percent of the first payment', fv('first_payment_value', $rec), ['class' => 'col-md-3', 'type' => 'number', 'step' => '0.0001']) ?>
    <?= input_field('clawback_days', 'Clawback period (days after activation)', fv('clawback_days', $rec), ['class' => 'col-md-4', 'type' => 'number', 'step' => '1']) ?>
  </div>
  <ul class="small text-muted mt-3 mb-3">
    <li>One activation commission per account, recorded when the account is activated; a percent is of the account's monthly fee.</li>
    <li>The first-payment commission is recorded on the account's first posted payment; a percent is of that payment.</li>
    <li>A cancellation within the clawback period reverses the commissions not yet paid and records a clawback against the next payout for those already paid.</li>
    <li>Commissions are approved, then paid with one bill of the agent's supplier record (Accounts Payable, Sales Agent Commissions).</li>
  </ul>
  <?= save_buttons('Save agent', $ag ? url($pk, ['id' => $id]) : url($pk), !$ag) ?>
</div></form>
    <?php
    layout_end();
    return;
}

// the commission list (shared by the agent page and the Commissions tab)
$commissionTable = function (array $rows, bool $tick, string $back) use ($pk, $basisLabels): string {
    if (!$rows) {
        return IspUi::empty('No commission here.', 'cash');
    }
    $canApprove = $tick && Access::can($pk, 'approve');
    ob_start(); ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="back" value="<?= h($back) ?>">
<div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table mb-0">
  <thead><tr><?php if ($canApprove): ?><th style="width:1%"><input class="form-check-input" type="checkbox" data-check-all="ids" aria-label="Tick all"></th><?php endif; ?>
    <th>Date</th><th>Agent</th><th>Account</th><th>Basis</th><th class="num">Amount</th><th>Status</th><th class="d-none d-lg-table-cell">Rule</th></tr></thead><tbody>
  <?php foreach ($rows as $c): ?>
    <tr><?php if ($canApprove): ?><td><?php if ($c['status'] === 'pending'): ?><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int)$c['id'] ?>" aria-label="Tick"><?php endif; ?></td><?php endif; ?>
      <td class="small"><?= h(fdate($c['created_at'])) ?></td><td><?= h($c['agent_code']) ?> <span class="text-muted"><?= h($c['agent_name']) ?></span></td>
      <td><?= IspUi::acct($c['account_id'], $c['account_no'], $c['account_name']) ?></td><td><?= h($basisLabels[$c['basis']] ?? $c['basis']) ?></td>
      <td class="num<?= (float)$c['amount'] < 0 ? ' text-danger' : '' ?>"><?= money($c['amount']) ?></td>
      <td><?= IspUi::status($c['status'], IspAgents::STATUSES) ?><?php if ($c['reversed_reason']): ?><div class="small text-muted"><?= h($c['reversed_reason']) ?></div><?php endif; ?>
        <?php if ($c['bill_txn_id'] && Access::page('vend.bills')): ?><div class="small"><a href="<?= h(url('vend.bills', ['a' => 'view', 'id' => $c['bill_txn_id']])) ?>">Bill</a></div><?php endif; ?></td>
      <td class="d-none d-lg-table-cell small text-muted"><?= h((string)$c['rule_note']) ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
<?php if ($canApprove && array_filter($rows, fn($r) => $r['status'] === 'pending')): ?>
<div class="d-flex flex-wrap gap-2 align-items-end p-2 border-top">
  <div><label class="form-label small mb-0">Note (needed to reject)</label><input class="form-control form-control-sm" name="note" maxlength="255"></div>
  <button class="btn btn-sm btn-success" name="action" value="approve" data-confirm="Approve the ticked commissions?"><i class="bi bi-check2"></i> Approve ticked</button>
  <button class="btn btn-sm btn-outline-danger" name="action" value="reject" data-confirm="Reject the ticked commissions?">Reject ticked</button>
</div>
<?php endif; ?>
</form>
    <?php
    return (string)ob_get_clean();
};
$commSql = 'SELECT c.*, g.code AS agent_code, g.name AS agent_name, a.account_no, a.account_name FROM isp_commissions c JOIN isp_agents g ON g.id = c.agent_id JOIN isp_accounts a ON a.id = c.account_id';

// ================================================================ ONE AGENT
if ($id) {
    $ag = DB::row('SELECT g.*, u.full_name AS user_name, v.name AS vendor_name FROM isp_agents g LEFT JOIN users u ON u.id = g.user_id LEFT JOIN vendors v ON v.id = g.vendor_id WHERE g.id = :i', ['i' => $id]);
    if (!$ag) {
        layout_start('Sales Agents & Commissions');
        echo IspUi::empty('The agent was not found.', 'search');
        layout_end();
        return;
    }
    $from = gdate('from', date('Y-01-01'));
    $to = gdate('to', today());
    $apps = DB::pairs('SELECT status, COUNT(*) FROM isp_applications WHERE agent_id = :g AND DATE(created_at) BETWEEN :f AND :t GROUP BY status', ['g' => $id, 'f' => $from, 't' => $to]);
    $activated = (int)DB::value('SELECT COUNT(*) FROM isp_accounts WHERE agent_id = :g AND activation_date BETWEEN :f AND :t', ['g' => $id, 'f' => $from, 't' => $to]);
    $activeNow = (int)DB::value("SELECT COUNT(*) FROM isp_accounts WHERE agent_id = :g AND service_status = 'active'", ['g' => $id]);
    $cancelled = (int)DB::value("SELECT COUNT(*) FROM isp_accounts WHERE agent_id = :g AND service_status IN ('terminated', 'cancelled') AND DATE(COALESCE(terminated_at, updated_at)) BETWEEN :f AND :t", ['g' => $id, 'f' => $from, 't' => $to]);
    $sums = DB::pairs('SELECT status, SUM(amount) FROM isp_commissions WHERE agent_id = :g GROUP BY status', ['g' => $id]);
    $earned = (float)DB::value("SELECT COALESCE(SUM(amount), 0) FROM isp_commissions WHERE agent_id = :g AND status IN ('approved', 'paid', 'pending') AND DATE(created_at) BETWEEN :f AND :t", ['g' => $id, 'f' => $from, 't' => $to]);
    $comms = DB::all($commSql . ' WHERE c.agent_id = :g ORDER BY c.id DESC LIMIT 200', ['g' => $id]);
    $recent = DB::all('SELECT x.id, x.application_no, x.status, x.created_at, x.last_name, x.first_name, x.middle_name, x.business_name, x.classification, p.name AS plan_name FROM isp_applications x
        LEFT JOIN isp_plans p ON p.id = x.plan_id WHERE x.agent_id = :g ORDER BY x.id DESC LIMIT 15', ['g' => $id]);
    $approved = (float)($sums['approved'] ?? 0);
    $head = ($manager ? btn_link(url($pk), 'Agents', 'btn-outline-secondary', 'arrow-left') . ' ' : '')
        . (Access::can($pk, 'edit') ? btn_link(url($pk, ['id' => $id, 'a' => 'edit']), 'Edit', 'btn-outline-secondary', 'pencil') : '');
    layout_start(($manager ? 'Agent ' : 'My Commissions: ') . $ag['code'], ['actions' => $head]);
    ?>
<div class="card isp-card"><div class="card-body">
  <div class="isp-head">
    <div><h2 class="isp-head-title"><?= h($ag['name']) ?></h2>
      <div class="isp-head-sub">Activation: <?= h($ag['activation_type'] === 'none' ? 'none' : ($ag['activation_type'] === 'percent' ? qty($ag['activation_value']) . '% of the monthly fee' : money($ag['activation_value']) . ' each')) ?>.
        First payment: <?= h($ag['first_payment_type'] === 'none' ? 'none' : ($ag['first_payment_type'] === 'percent' ? qty($ag['first_payment_value']) . '% of it' : money($ag['first_payment_value']) . ' each')) ?>.
        Clawback within <?= (int)$ag['clawback_days'] ?> days.</div></div>
    <div class="isp-head-actions"><?= IspUi::status((int)$ag['is_active'] ? 'active' : 'inactive') ?></div>
  </div>
  <?php if ($manager): ?>
  <div class="small text-muted mt-2">User <?= h((string)($ag['user_name'] ?: 'not linked')) ?>, paid through <?= h((string)($ag['vendor_name'] ?: 'a supplier record made at the first payout')) ?>.
    <?= $ag['mobile'] ? 'Mobile ' . IspUi::sensitive($ag['mobile']) . '.' : '' ?></div>
  <?php endif; ?>
</div></div>
<form method="get" class="isp-filter no-print mt-3" action="<?= h(base_url() . '/index.php') ?>"><input type="hidden" name="p" value="<?= h($pk) ?>"><input type="hidden" name="id" value="<?= $id ?>">
  <?= IspUi::fDate('from', 'From', $from) ?><?= IspUi::fDate('to', 'To', $to) ?>
  <div class="isp-filter-actions"><button class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> Show</button></div></form>
<div class="kpi-grid">
  <?= IspUi::kpi('Applications', number_format(array_sum(array_map('intval', $apps))), number_format((int)($apps['rejected'] ?? 0)) . ' rejected', null, 'person-plus', 'info') ?>
  <?= IspUi::kpi('Activated', number_format($activated), 'in the period', null, 'power', 'ok') ?>
  <?= IspUi::kpi('Active now', number_format($activeNow), number_format($cancelled) . ' cancelled in the period', null, 'wifi', 'ok') ?>
  <?= IspUi::kpi('Commissions earned', money($earned), 'in the period', null, 'cash-coin', 'info') ?>
  <?= IspUi::kpi('Approved, to be paid', money($approved), 'pending ' . money($sums['pending'] ?? 0), null, 'wallet2', 'warn') ?>
</div>
<?php if (Access::can($pk, 'approve') && $approved > 0 && Access::can('vend.bills', 'create')): ?>
<div class="card isp-card mt-3"><div class="card-body">
  <form method="post" class="d-flex flex-wrap gap-2 align-items-end" data-confirm="Bill the approved commissions of <?= h($ag['name']) ?> (<?= money($approved) ?>) to accounts payable?"><?= csrf_field() ?>
    <input type="hidden" name="action" value="pay"><div><label class="form-label">Bill date</label><input class="form-control form-control-sm" type="date" name="pay_date" value="<?= h(today()) ?>"></div>
    <button class="btn btn-sm btn-primary"><i class="bi bi-receipt"></i> Pay the approved commissions (<?= money($approved) ?>)</button>
    <span class="small text-muted">One bill of the agent's supplier record; clawbacks are netted.</span></form>
</div></div>
<?php endif; ?>
<div class="row g-3 mt-0">
  <div class="col-lg-8"><div class="card isp-card"><div class="card-body p-0">
    <h2 class="sec-title p-3 pb-0">Commissions</h2>
    <?= $commissionTable($comms, $manager, url($pk, ['id' => $id])) ?>
  </div></div></div>
  <div class="col-lg-4"><div class="card isp-card"><div class="card-body">
    <h2 class="sec-title">Latest applications</h2>
    <?php if (!$recent): ?><p class="small text-muted">None.</p><?php else: ?>
    <ul class="list-unstyled mb-0">
      <?php foreach ($recent as $r): ?><li class="mb-1"><?= safe_link('isp.applications', ['id' => $r['id']], $r['application_no']) ?> <?= h(IspApplications::name($r)) ?>
        <div class="small text-muted"><?= h((string)$r['plan_name']) ?>, <?= h(fdate($r['created_at'])) ?>, <?= IspUi::status($r['status'], IspApplications::STATUSES) ?></div></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div></div></div>
</div>
    <?php
    layout_end();
    return;
}

$tabsHtml = IspUi::tabs(['agents' => 'Agents', 'commissions' => 'Commissions'], $view, $pk, [], 'view');

// ================================================================ COMMISSIONS
if ($view === 'commissions') {
    $status = (string)g('status', 'pending');
    $agentId = gint('agent_id');
    $params = [];
    $where = ' WHERE 1 = 1';
    if (isset(IspAgents::STATUSES[$status])) {
        $where .= ' AND c.status = :st';
        $params['st'] = $status;
    }
    if ($agentId) {
        $where .= ' AND c.agent_id = :g';
        $params['g'] = $agentId;
    }
    [$per, $off, $page] = IspUi::paging(100);
    $total = (int)DB::value('SELECT COUNT(*) FROM isp_commissions c' . $where, $params);
    $rows = DB::all($commSql . $where . ' ORDER BY c.id DESC LIMIT ' . $per . ' OFFSET ' . $off, $params);
    $sum = (float)DB::value('SELECT COALESCE(SUM(c.amount), 0) FROM isp_commissions c' . $where, $params);
    layout_start('Sales Agents & Commissions', ['actions' => Access::page('rpt.isp_agent_perf') ? btn_link(url('rpt.isp_agent_perf'), 'Performance report', 'btn-outline-secondary', 'graph-up') : '']);
    echo $tabsHtml;
    echo IspUi::filterStart($pk, ['view' => 'commissions'])
        . IspUi::fSelect('status', 'Status', IspAgents::STATUSES, $status, 'All')
        . IspUi::fSelect('agent_id', 'Agent', DB::pairs("SELECT id, CONCAT(code, ' ', name) FROM isp_agents ORDER BY name"), $agentId)
        . IspUi::filterEnd(url($pk, ['view' => 'commissions']));
    ?>
<div class="card isp-card"><div class="card-body p-0">
  <?= $commissionTable($rows, true, current_url()) ?>
  <div class="p-2 small text-muted border-top"><?= number_format($total) ?> commission(s), total <?= money($sum) ?></div>
</div></div>
<?= IspUi::pager($total, $per, $page) ?>
    <?php
    layout_end();
    return;
}

// ================================================================ AGENTS
$q = trim((string)g('q'));
$params = [];
$where = '';
if ($q !== '') {
    $where = ' WHERE (g.code LIKE :q1 OR g.name LIKE :q2)';
    $params = ['q1' => $q . '%', 'q2' => '%' . $q . '%'];
}
$rows = DB::all("SELECT g.*, u.full_name AS user_name,
        (SELECT COUNT(*) FROM isp_applications x WHERE x.agent_id = g.id) AS apps,
        (SELECT COUNT(*) FROM isp_accounts a WHERE a.agent_id = g.id AND a.service_status = 'active') AS active_accts,
        (SELECT COALESCE(SUM(c.amount), 0) FROM isp_commissions c WHERE c.agent_id = g.id AND c.status = 'pending') AS pending_amt,
        (SELECT COALESCE(SUM(c.amount), 0) FROM isp_commissions c WHERE c.agent_id = g.id AND c.status = 'approved') AS approved_amt,
        (SELECT COALESCE(SUM(c.amount), 0) FROM isp_commissions c WHERE c.agent_id = g.id AND c.status = 'paid') AS paid_amt
    FROM isp_agents g LEFT JOIN users u ON u.id = g.user_id" . $where . ' ORDER BY g.is_active DESC, g.name', $params);
layout_start('Sales Agents & Commissions', ['actions' => page_link($pk, ['a' => 'new'], 'New agent', 'btn-primary', 'plus-lg', 'create')]);
echo $tabsHtml;
?>
<?= IspUi::filterStart($pk) ?><?= IspUi::fSearch($q, 'Agent code or name') ?><?= IspUi::filterEnd(url($pk)) ?>
<div class="card isp-card"><div class="card-body p-0">
  <?php if (!$rows): ?><?= IspUi::empty('No sales agent yet.', 'person-badge') ?><?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table mb-0">
    <thead><tr><th>Agent</th><th class="d-none d-md-table-cell">Rules</th><th class="num">Applications</th><th class="num">Active accounts</th><th class="num">Pending</th><th class="num">Approved</th><th class="num d-none d-lg-table-cell">Paid</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><a href="<?= h(url($pk, ['id' => $r['id']])) ?>"><strong><?= h($r['code']) ?></strong></a> <?= h($r['name']) ?><?= $r['user_name'] ? '<div class="small text-muted">User ' . h($r['user_name']) . '</div>' : '' ?></td>
        <td class="d-none d-md-table-cell small"><?= h($r['activation_type'] === 'none' ? 'No activation commission' : ($r['activation_type'] === 'percent' ? qty($r['activation_value']) . '% per activation' : money($r['activation_value']) . ' per activation')) ?>
          <?= $r['first_payment_type'] !== 'none' ? '<div class="text-muted">' . h($r['first_payment_type'] === 'percent' ? qty($r['first_payment_value']) . '% of the first payment' : money($r['first_payment_value']) . ' on the first payment') . '</div>' : '' ?></td>
        <td class="num"><?= number_format((int)$r['apps']) ?></td><td class="num"><?= number_format((int)$r['active_accts']) ?></td>
        <td class="num"><?= money($r['pending_amt'], true) ?></td><td class="num"><?= money($r['approved_amt'], true) ?></td><td class="num d-none d-lg-table-cell"><?= money($r['paid_amt'], true) ?></td>
        <td><?= IspUi::status((int)$r['is_active'] ? 'active' : 'inactive') ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div></div>
<?php
layout_end();
