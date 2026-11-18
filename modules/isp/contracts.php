<?php
declare(strict_types=1);

/** Contracts & Renewals: the subscriber contracts (as accepted), renewals, renewal reminders and the contract templates */
$pk = 'isp.contracts';
$id = (int)g('id', (string)p('id'));
$view = (string)g('view') === 'templates' ? 'templates' : 'contracts';
$placeholders = ['date' => 'Date of the contract', 'company' => 'Company name', 'company_address' => 'Company address', 'name' => 'Subscriber (account name)',
    'address' => 'Installation address', 'account_no' => 'Account number', 'plan' => 'Plan name', 'download' => 'Download speed (Mbps)', 'upload' => 'Upload speed (Mbps)',
    'fair_use' => 'Fair use policy / data allowance', 'monthly_fee' => 'Monthly fee', 'vat_note' => '"(VAT inclusive)" or "plus VAT"', 'billing' => 'Monthly in advance / in arrears',
    'due_days' => 'Days to pay', 'installation_fee' => 'Installation fee', 'deposit' => 'Security deposit', 'start_date' => 'Start of the term', 'months' => 'Months of the term',
    'lock_in' => 'Lock-in sentence (empty without lock-in)', 'etf' => 'Early termination fee', 'grace_days' => 'Grace days before disconnection', 'reconnection_fee' => 'Reconnection fee',
    'sla_hours' => 'Outage hours before a service credit', 'privacy' => 'Data privacy notice'];

handle_post(function () use ($pk, $id) {
    $act = (string)p('action');
    if ($act === 'renew') {
        Access::require($pk, 'edit');
        $c = DB::row('SELECT * FROM isp_contracts WHERE id = :i', ['i' => $id]) ?? fail('Contract not found.');
        $by = trim((string)p('accepted_name'));
        if ($by === '') {
            fail('Enter the name of the person accepting the renewal.');
        }
        if (p('agree') !== '1') {
            fail('Tick that the subscriber accepted the renewed terms.');
        }
        $new = IspContracts::renew($id, (int)p('months'), $by);
        Audit::log('renew', 'isp', 'isp_contract', $new, (string)DB::value('SELECT contract_no FROM isp_contracts WHERE id = :i', ['i' => $new]), ['reason' => 'Renewal of ' . $c['contract_no'] . ' for ' . (int)p('months') . ' months']);
        redirect(url($pk, ['id' => $new]), 'Contract renewed.', 'success');
    }
    if ($act === 'note') {
        Access::require($pk, 'edit');
        $c = DB::row('SELECT * FROM isp_contracts WHERE id = :i', ['i' => $id]) ?? fail('Contract not found.');
        DB::update('isp_contracts', ['notes' => mb_substr(trim((string)p('notes')), 0, 255) ?: null], 'id = :id', ['id' => $id]);
        Audit::log('edit', 'isp', 'isp_contract', $id, (string)$c['contract_no'], ['changes' => [['field' => 'notes', 'old' => $c['notes'], 'new' => trim((string)p('notes'))]]]);
        redirect(url($pk, ['id' => $id]), 'Note saved.', 'success');
    }
    if ($act === 'reminders') {
        Access::require($pk, 'edit');
        $n = IspContracts::notices();
        redirect(url($pk), $n ? $n . ' renewal reminder(s) and contract end(s) processed.' : 'No contract is due for a reminder.', 'success');
    }
    if ($act === 'save_template') {
        $tid = pint('template_id');
        Access::require($pk, $tid ? 'edit' : 'create');
        $name = trim((string)p('name'));
        $body = trim((string)p('body'));
        if ($name === '' || $body === '') {
            fail('Enter the name and the text of the template.');
        }
        if (mb_strlen($body) > 60000) {
            fail('The template is too long.');
        }
        $row = ['name' => mb_substr($name, 0, 120), 'body' => $body, 'is_default' => (int)(p('is_default') === '1'), 'is_active' => (int)(p('is_active') === '1')];
        if ($row['is_default'] && !$row['is_active']) {
            fail('The default template has to be active.');
        }
        DB::tx(function () use (&$tid, $row) {
            if ($row['is_default']) {
                DB::run('UPDATE isp_contract_templates SET is_default = 0');
            }
            if ($tid) {
                $before = DB::row('SELECT * FROM isp_contract_templates WHERE id = :i', ['i' => $tid]) ?? fail('Template not found.');
                DB::update('isp_contract_templates', $row, 'id = :id', ['id' => $tid]);
                Audit::log('edit', 'isp', 'isp_contract_template', $tid, $row['name'], ['before' => $before, 'after' => $row]);
            } else {
                $tid = DB::insert('isp_contract_templates', $row);
                Audit::log('create', 'isp', 'isp_contract_template', $tid, $row['name']);
            }
            if (!DB::value('SELECT id FROM isp_contract_templates WHERE is_default = 1 AND is_active = 1')) {
                DB::run('UPDATE isp_contract_templates SET is_default = 1 WHERE id = (SELECT id FROM (SELECT id FROM isp_contract_templates WHERE is_active = 1 ORDER BY id LIMIT 1) t)');
            }
        });
        redirect(url($pk, ['view' => 'templates', 'tpl' => $tid]), 'Template saved. New contracts use the default template; contracts already made keep the text that was accepted.', 'success');
    }
    fail('Unknown action.');
});

// ================================================================ ONE CONTRACT
if ($id) {
    $c = DB::row('SELECT c.*, a.account_no, a.account_name, a.install_address, a.branch_id, a.service_status, p.name AS plan_name, t.name AS template_name, u.full_name AS created_name
        FROM isp_contracts c JOIN isp_accounts a ON a.id = c.account_id LEFT JOIN isp_plans p ON p.id = c.plan_id LEFT JOIN isp_contract_templates t ON t.id = c.template_id
        LEFT JOIN users u ON u.id = c.created_by WHERE c.id = :i', ['i' => $id]);
    if (!$c) {
        layout_start('Contracts & Renewals');
        echo IspUi::empty('The contract was not found.', 'search');
        layout_end();
        return;
    }
    $myBranch = IspSetup::userBranch();
    if ($myBranch !== null && $c['branch_id'] !== null && (int)$c['branch_id'] !== $myBranch) {
        Access::denyPage($pk);
    }
    if (g('a') === 'print') {
        Access::require($pk, 'print');
        layout_start('Contract ' . $c['contract_no'], ['bare' => true, 'no_title' => true]);
        ?>
<div class="no-print mb-3 d-flex gap-2"><button class="btn btn-sm btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
  <?= btn_link(url($pk, ['id' => $id]), 'Back', 'btn-outline-secondary', 'arrow-left') ?></div>
<div class="isp-doc">
  <?= IspUi::letterhead('SUBSCRIPTION CONTRACT', '<div>No. ' . h($c['contract_no']) . '</div><div>Account ' . h($c['account_no']) . '</div>') ?>
  <p class="small mb-3">Term <?= h(fdate($c['start_date'])) ?><?= $c['end_date'] ? ' to ' . h(fdate($c['end_date'])) : ', month to month' ?><?= (int)$c['lock_in_months'] ? ', lock-in ' . (int)$c['lock_in_months'] . ' months' : '' ?><?= (float)$c['early_termination_fee'] > 0 ? ', early termination fee ' . money($c['early_termination_fee']) : '' ?>.</p>
  <div style="white-space:pre-wrap"><?= h((string)($c['body_snapshot'] ?: 'The terms of this contract were not recorded.')) ?></div>
  <p class="small mt-4"><?= $c['accepted_at'] ? 'Accepted by ' . h((string)$c['accepted_name']) . ' on ' . h(fdatetime($c['accepted_at'])) . ($c['accepted_ip'] ? ' from ' . h($c['accepted_ip']) : '') . '.' : 'Acceptance not recorded.' ?></p>
</div>
        <?php
        layout_end();
        return;
    }
    $chain = DB::all('SELECT id, contract_no, start_date, end_date, status FROM isp_contracts WHERE account_id = :a ORDER BY start_date, id', ['a' => $c['account_id']]);
    $head = (Access::can($pk, 'print') ? btn_link(url($pk, ['id' => $id, 'a' => 'print']), 'Print', 'btn-outline-secondary', 'printer') . ' ' : '')
        . btn_link(url($pk), 'Contracts', 'btn-outline-secondary', 'arrow-left');
    layout_start('Contract ' . $c['contract_no'], ['actions' => $head]);
    ?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card isp-card"><div class="card-body">
      <div class="isp-head">
        <div><h2 class="isp-head-title"><?= IspUi::acct($c['account_id'], $c['account_no'], $c['account_name']) ?></h2>
          <div class="isp-head-sub"><?= h((string)$c['plan_name']) ?>, <?= h(fdate($c['start_date'])) ?><?= $c['end_date'] ? ' to ' . h(fdate($c['end_date'])) : ', month to month' ?></div></div>
        <div class="isp-head-actions"><?= IspUi::status($c['status'], IspContracts::STATUSES) ?></div>
      </div>
      <h2 class="sec-title mt-3">Terms as accepted</h2>
      <div class="small border rounded p-3" style="white-space:pre-wrap;max-height:520px;overflow:auto"><?= h((string)($c['body_snapshot'] ?: 'The terms of this contract were not recorded.')) ?></div>
    </div></div>
  </div>
  <div class="col-lg-4">
    <div class="card isp-card"><div class="card-body">
      <h2 class="sec-title">Details</h2>
      <?= IspUi::dl([
          'Template' => $c['template_name'] ? h($c['template_name']) : null,
          'Lock-in' => (int)$c['lock_in_months'] ? (int)$c['lock_in_months'] . ' months' : 'None',
          'Early termination fee' => (float)$c['early_termination_fee'] > 0 ? money($c['early_termination_fee']) : 'None',
          'Accepted' => $c['accepted_at'] ? h((string)$c['accepted_name']) . '<div class="small text-muted">' . h(fdatetime($c['accepted_at'])) . ($c['accepted_ip'] ? ', ' . h($c['accepted_ip']) : '') . '</div>' : 'Not recorded',
          'Ended' => $c['terminated_at'] ? h(fdatetime($c['terminated_at'])) : null,
          'Made by' => h((string)$c['created_name']) . ' <span class="small text-muted">' . h(fdatetime($c['created_at'])) . '</span>',
          'Notes' => $c['notes'] ? h($c['notes']) : null,
      ]) ?>
      <?php if (Access::can($pk, 'edit')): ?>
      <form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="note">
        <label class="form-label">Note</label><div class="d-flex gap-2"><input class="form-control form-control-sm" name="notes" value="<?= h((string)$c['notes']) ?>" maxlength="255"><button class="btn btn-sm btn-outline-secondary">Save</button></div></form>
      <?php endif; ?>
    </div></div>
    <?php if (Access::can($pk, 'edit') && in_array($c['status'], ['active', 'expired'], true) && !in_array($c['service_status'], ['terminated', 'cancelled'], true)): ?>
    <div class="card isp-card mt-3"><div class="card-body">
      <h2 class="sec-title">Renew</h2>
      <p class="small text-muted">The renewal starts the day after this contract ends (today if it already ended), with the current plan and the default template, and no new lock-in.</p>
      <form method="post" class="row g-2" data-confirm="Renew the contract?"><?= csrf_field() ?><input type="hidden" name="action" value="renew">
        <?= select_field('months', 'Months', ['6' => '6 months', '12' => '12 months', '24' => '24 months', '36' => '36 months'], fv('months', null, '12'), ['class' => 'col-md-5']) ?>
        <?= input_field('accepted_name', 'Accepted by', fv('accepted_name', null, $c['account_name']), ['class' => 'col-md-7', 'required' => true]) ?>
        <?= check_field('agree', 'The subscriber accepted the renewed terms', fv('agree'), 'col-12') ?>
        <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-arrow-clockwise"></i> Renew</button></div>
      </form>
    </div></div>
    <?php endif; ?>
    <?php if (count($chain) > 1): ?>
    <div class="card isp-card mt-3"><div class="card-body">
      <h2 class="sec-title">Contracts of this account</h2>
      <ul class="list-unstyled mb-0">
        <?php foreach ($chain as $ch): ?><li><?= (int)$ch['id'] === $id ? '<strong>' . h($ch['contract_no']) . '</strong>' : '<a href="' . h(url($pk, ['id' => $ch['id']])) . '">' . h($ch['contract_no']) . '</a>' ?>
          <span class="small text-muted"><?= h(fdate($ch['start_date'])) ?><?= $ch['end_date'] ? ' - ' . h(fdate($ch['end_date'])) : '' ?></span> <?= IspUi::status($ch['status'], IspContracts::STATUSES) ?></li><?php endforeach; ?>
      </ul>
    </div></div>
    <?php endif; ?>
  </div>
</div>
    <?php
    layout_end();
    return;
}

$viewTabs = IspUi::tabs(['contracts' => 'Contracts', 'templates' => 'Contract templates'], $view, $pk, [], 'view');

// ================================================================ TEMPLATES
if ($view === 'templates') {
    $tpls = DB::all('SELECT t.*, (SELECT COUNT(*) FROM isp_contracts c WHERE c.template_id = t.id) AS used FROM isp_contract_templates t ORDER BY t.is_default DESC, t.is_active DESC, t.name');
    $tplId = g('tpl') === 'new' ? 0 : gint('tpl');
    $editing = g('tpl') === 'new' || $tplId || (is_post() && p('action') === 'save_template');
    $tpl = $tplId ? DB::row('SELECT * FROM isp_contract_templates WHERE id = :i', ['i' => $tplId]) : null;
    $sample = DB::row("SELECT id FROM isp_accounts WHERE service_status IN ('active', 'pending', 'suspended') ORDER BY id DESC LIMIT 1");
    if ($editing && !Access::can($pk, $tplId ? 'edit' : 'create')) {
        Access::denyPage($pk, $tplId ? 'edit' : 'create');
    }
    layout_start('Contracts & Renewals', ['actions' => page_link($pk, ['view' => 'templates', 'tpl' => 'new'], 'New template', 'btn-primary', 'plus-lg', 'create')]);
    echo $viewTabs;
    ?>
<div class="row g-3">
  <div class="col-lg-<?= $editing ? '4' : '12' ?>">
    <div class="card isp-card"><div class="card-body p-0">
      <?php if (!$tpls): ?><?= IspUi::empty('No contract template.', 'file-earmark-text') ?><?php else: ?>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table mb-0">
        <thead><tr><th>Template</th><th>Status</th><th class="num">Contracts</th></tr></thead><tbody>
        <?php foreach ($tpls as $t): ?>
          <tr class="<?= (int)$t['id'] === $tplId ? 'table-active' : '' ?>"><td><a href="<?= h(url($pk, ['view' => 'templates', 'tpl' => $t['id']])) ?>"><?= h($t['name']) ?></a><?= (int)$t['is_default'] ? '<div class="small text-muted">Default for new contracts</div>' : '' ?></td>
            <td><?= IspUi::status((int)$t['is_active'] ? 'active' : 'inactive', ['active' => 'In use', 'inactive' => 'Not used']) ?></td><td class="num"><?= (int)$t['used'] ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
      <?php endif; ?>
    </div></div>
  </div>
  <?php if ($editing): $rec = $tpl ?? ['name' => '', 'body' => '', 'is_default' => 0, 'is_active' => 1]; ?>
  <div class="col-lg-8">
    <form method="post" class="card isp-card"><div class="card-body">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_template"><input type="hidden" name="template_id" value="<?= (int)$tplId ?>">
      <h2 class="sec-title"><?= $tpl ? 'Edit template' : 'New template' ?></h2>
      <div class="row g-2">
        <?= input_field('name', 'Name', fv('name', $rec), ['class' => 'col-md-6', 'required' => true]) ?>
        <?= check_field('is_default', 'Default for new contracts', fv('is_default', $rec), 'col-md-3') ?>
        <?= check_field('is_active', 'In use', fv('is_active', $rec), 'col-md-3') ?>
        <div class="col-12"><label class="form-label">Text</label><textarea class="form-control form-control-sm font-monospace" name="body" rows="18" required><?= h(fv('body', $rec)) ?></textarea></div>
      </div>
      <details class="mt-2"><summary class="small">Placeholders</summary>
        <div class="row small mt-1"><?php foreach ($placeholders as $k => $v): ?><div class="col-md-6"><code>{<?= h($k) ?>}</code> <?= h($v) ?></div><?php endforeach; ?></div></details>
      <div class="mt-3"><?= save_buttons('Save template', url($pk, ['view' => 'templates']), false) ?></div>
      <p class="small text-muted mt-2 mb-0">A contract keeps the text it was accepted with: editing a template changes only the contracts made after.</p>
    </div></form>
    <?php if ($tpl && $sample): $sa = IspAccounts::get((int)$sample['id']); ?>
    <div class="card isp-card mt-3"><div class="card-body">
      <h2 class="sec-title">Preview with account <?= h($sa['account_no']) ?></h2>
      <div class="small border rounded p-3" style="white-space:pre-wrap;max-height:420px;overflow:auto"><?= h(IspContracts::render((string)$tpl['body'], $sa, today())) ?></div>
    </div></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
    <?php
    layout_end();
    return;
}

// ================================================================ THE LIST
$status = (string)g('status');
$due = (string)g('due');
$q = trim((string)g('q'));
$params = [];
$where = ' WHERE 1 = 1' . IspSetup::branchSql('a.branch_id', $params);
if (isset(IspContracts::STATUSES[$status])) {
    $where .= ' AND c.status = :st';
    $params['st'] = $status;
}
if (in_array($due, ['30', '60', '90'], true)) {
    $where .= " AND c.status = 'active' AND c.end_date IS NOT NULL AND c.end_date BETWEEN :t AND :e";
    $params['t'] = today();
    $params['e'] = IspPlans::addDays(today(), (int)$due);
} elseif ($due === 'ended') {
    $where .= " AND c.status IN ('active', 'expired') AND c.end_date IS NOT NULL AND c.end_date < :t";
    $params['t'] = today();
} elseif ($due === 'lockin') {
    $where .= " AND c.status = 'active' AND a.lock_in_until IS NOT NULL AND a.lock_in_until >= :t";
    $params['t'] = today();
}
if ($q !== '') {
    $where .= ' AND (c.contract_no LIKE :q1 OR a.account_no LIKE :q2 OR a.account_name LIKE :q3)';
    $params += ['q1' => $q . '%', 'q2' => $q . '%', 'q3' => '%' . $q . '%'];
}
$from = ' FROM isp_contracts c JOIN isp_accounts a ON a.id = c.account_id LEFT JOIN isp_plans p ON p.id = c.plan_id';
[$per, $off, $page] = IspUi::paging(50);
$total = (int)DB::value('SELECT COUNT(*)' . $from . $where, $params);
$rows = DB::all('SELECT c.*, a.account_no, a.account_name, a.lock_in_until, a.service_status, p.name AS plan_name' . $from . $where
    . ' ORDER BY (c.end_date IS NULL), c.end_date, c.id DESC LIMIT ' . $per . ' OFFSET ' . $off, $params);
$kp = [];
$kb = IspSetup::branchSql('a.branch_id', $kp);
$due30 = (int)DB::value("SELECT COUNT(*) FROM isp_contracts c JOIN isp_accounts a ON a.id = c.account_id WHERE c.status = 'active' AND c.end_date BETWEEN :t AND :e" . $kb,
    $kp + ['t' => today(), 'e' => IspPlans::addDays(today(), 30)]);
$ended = (int)DB::value("SELECT COUNT(*) FROM isp_contracts c JOIN isp_accounts a ON a.id = c.account_id WHERE c.status = 'expired'" . $kb, $kp);
$active = (int)DB::value("SELECT COUNT(*) FROM isp_contracts c JOIN isp_accounts a ON a.id = c.account_id WHERE c.status = 'active'" . $kb, $kp);

$actions = Access::can($pk, 'edit') ? IspUi::actionForm('reminders', 'Send renewal reminders now', [], 'btn-outline-primary', 'bell', 'Send the renewal reminders of the contracts ending in the next 30 days, and mark the ended ones as month to month?') : '';
layout_start('Contracts & Renewals', ['actions' => $actions]);
echo $viewTabs;
?>
<div class="kpi-grid">
  <?= IspUi::kpi('Active contracts', number_format($active), '', url($pk, ['status' => 'active']), 'file-earmark-check', 'ok') ?>
  <?= IspUi::kpi('Ending in 30 days', number_format($due30), 'renewal reminders go out daily', url($pk, ['due' => '30']), 'calendar-event', 'warn') ?>
  <?= IspUi::kpi('Month to month', number_format($ended), 'term ended, not renewed', url($pk, ['status' => 'expired']), 'arrow-repeat', 'info') ?>
</div>
<?= IspUi::filterStart($pk) ?>
  <?= IspUi::fSearch($q, 'Contract no., account no. or name') ?>
  <?= IspUi::fSelect('status', 'Status', IspContracts::STATUSES, $status) ?>
  <?= IspUi::fSelect('due', 'Renewal', ['30' => 'Ending within 30 days', '60' => 'Ending within 60 days', '90' => 'Ending within 90 days', 'ended' => 'Term already ended', 'lockin' => 'Inside the lock-in period'], $due) ?>
<?= IspUi::filterEnd(url($pk)) ?>
<div class="card isp-card"><div class="card-body p-0">
  <?php if (!$rows): ?><?= IspUi::empty('No contract matches.', 'file-earmark-text') ?><?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table mb-0">
    <thead><tr><th>Contract</th><th>Account</th><th class="d-none d-md-table-cell">Plan</th><th>Term</th><th class="d-none d-lg-table-cell">Lock-in until</th><th>Status</th><th class="d-none d-lg-table-cell">Accepted</th></tr></thead><tbody>
    <?php foreach ($rows as $r): $soon = $r['status'] === 'active' && $r['end_date'] && $r['end_date'] <= IspPlans::addDays(today(), 30); ?>
      <tr><td><a href="<?= h(url($pk, ['id' => $r['id']])) ?>"><strong><?= h($r['contract_no']) ?></strong></a></td>
        <td><?= IspUi::acct($r['account_id'], $r['account_no'], $r['account_name']) ?></td><td class="d-none d-md-table-cell"><?= h((string)$r['plan_name']) ?></td>
        <td class="small<?= $soon ? ' text-warning-emphasis fw-semibold' : '' ?>"><?= h(fdate($r['start_date'])) ?> - <?= $r['end_date'] ? h(fdate($r['end_date'])) : 'open' ?></td>
        <td class="d-none d-lg-table-cell small"><?= h(fdate($r['lock_in_until'])) ?></td><td><?= IspUi::status($r['status'], IspContracts::STATUSES) ?></td>
        <td class="d-none d-lg-table-cell small"><?= $r['accepted_at'] ? h((string)$r['accepted_name']) : '<span class="text-muted">Not recorded</span>' ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div></div>
<?= IspUi::pager($total, $per, $page) ?>
<?php
layout_end();
