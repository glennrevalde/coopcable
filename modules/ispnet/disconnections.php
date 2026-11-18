<?php
declare(strict_types=1);

/**
 * Disconnection Queue (Workflow C): candidates found by the nonpayment rule, their approval in bulk after the preview,
 * the final notice, the execution through the network layer (checked again just before the command) and the result
 * the device confirmed. Disconnections asked by a person (fraud, technical, administrative, customer request,
 * cancellation) are queued here too.
 */
require_once __DIR__ . '/_common.php';

$pk = 'ispnet.disconnections';

handle_post(function () use ($pk) {
    $action = (string)p('action');
    $id = (int)p('id');
    $mayDisconnect = (bool)Access::special('isp_disconnect');
    switch ($action) {
        case 'evaluate':
            Access::require($pk, 'approve');
            $n = IspDisconnect::evaluate();
            Audit::log('evaluate', 'ispnet', 'isp_disc_queue', null, 'Disconnection evaluation', ['page_key' => $pk, 'reason' => $n . ' account(s) queued']);
            redirect(url($pk, ['tab' => 'candidate']), $n ? $n . ' account(s) added to the queue. Review them, then approve.' : 'No new account qualifies for disconnection today.', $n ? 'success' : 'info');
        case 'approve':
            Access::require($pk, 'approve');
            if (!$mayDisconnect) {
                fail('Approving disconnections needs the permission "Cable & Internet: can disconnect subscribers".');
            }
            $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
            if (!$ids) {
                fail('Tick the accounts to approve.');
            }
            $n = IspDisconnect::approve($ids);
            redirect(url($pk, ['tab' => 'waiting']), $n . ' disconnection(s) approved. Final notices went out where the rule asks for them.', 'success');
        case 'notices':
            Access::require($pk, 'approve');
            $n = IspDisconnect::sendNotices();
            redirect(url($pk, ['tab' => 'waiting']), $n . ' approved item(s) given their final notice and execution time.', 'success');
        case 'execute_due':
            Access::require($pk, 'approve');
            if (!$mayDisconnect) {
                fail('Executing disconnections needs the permission "Cable & Internet: can disconnect subscribers".');
            }
            $n = IspDisconnect::executeDue(true);
            Audit::log('execute', 'ispnet', 'isp_disc_queue', null, 'Disconnections executed', ['page_key' => $pk, 'reason' => $n . ' item(s) sent']);
            redirect(url($pk, ['tab' => 'waiting']), $n ? $n . ' disconnection(s) sent to the network. Each is shown disconnected only when its device confirms.' : 'No item is due: final notice periods have not ended yet.', $n ? 'success' : 'info');
        case 'execute':
            Access::require($pk, 'approve');
            if (!$mayDisconnect) {
                fail('Executing disconnections needs the permission "Cable & Internet: can disconnect subscribers".');
            }
            $q = DB::row('SELECT * FROM isp_disc_queue WHERE id = :id', ['id' => $id]) ?? fail('Queue item not found.');
            if (!ispnet_account_visible((int)$q['account_id'])) {
                fail('That account belongs to another branch.');
            }
            if ($q['status'] === 'notified' && $q['execute_after'] && strtotime((string)$q['execute_after']) > time()) {
                fail('The final notice period of ' . $q['queue_no'] . ' ends ' . fdatetime($q['execute_after']) . '. It cannot be executed before then.');
            }
            $res = IspDisconnect::execute($id);
            $msg = match ($res) {
                'confirmed' => ['success', 'Disconnection confirmed by the device.'],
                'manual' => ['warning', 'No device does this account: a work order was made. The account stays connected until the technician confirms.'],
                'retry', 'queued' => ['warning', 'The device did not do it; it will be retried automatically. The account is still connected.'],
                'failed' => ['danger', 'The disconnection FAILED. The account is still connected; a work order was made for the network administrator.'],
                'sent' => ['warning', 'Sent; waiting for the device to confirm.'],
                'skipped' => ['info', 'Not sent: the account no longer qualifies (checked just before sending).'],
                default => ['info', 'Result: ' . $res],
            };
            redirect(url($pk, ['id' => $id]), $msg[1], $msg[0]);
        case 'cancel':
            Access::require($pk, 'delete');
            IspDisconnect::cancel($id, trim((string)p('reason')));
            redirect(url($pk, ['id' => $id]), 'Disconnection cancelled.', 'success');
        case 'manual':
            Access::require($pk, 'create');
            $acct = IspUi::postedAccount('account_id') ?? fail('Choose the subscriber account.');
            if (!ispnet_account_visible($acct)) {
                fail('That account belongs to another branch.');
            }
            $qid = IspDisconnect::queueManual($acct, (string)p('reason_type'), trim((string)p('details')), p('execute_now') === '1');
            $st = (string)DB::value('SELECT status FROM isp_disc_queue WHERE id = :i', ['i' => $qid]);
            redirect(url($pk, ['id' => $qid]), 'Disconnection queued: ' . (IspDisconnect::STATUSES[$st] ?? $st) . '.', in_array($st, ['disconnected'], true) ? 'success' : 'info');
        default:
            fail('Unknown action.');
    }
});

$mayDisconnect = (bool)Access::special('isp_disconnect');
$canApprove = Access::can($pk, 'approve');
$rule = IspDisconnect::rule('overdue');
$id = gint('id');

// ------------------------------------------------------------------ one queue item
if ($id) {
    $q = DB::row('SELECT q.*, a.account_no, a.account_name, a.service_status, a.network_state, a.branch_id, a.install_address, r.name AS rule_name, r.grace_days
                    FROM isp_disc_queue q JOIN isp_accounts a ON a.id = q.account_id LEFT JOIN isp_disc_rules r ON r.id = q.rule_id WHERE q.id = :id', ['id' => $id]);
    if (!$q || !ispnet_account_visible((int)$q['account_id'])) {
        layout_start('Disconnection');
        echo IspUi::empty('The queue item was not found.');
        layout_end();
        return;
    }
    $a = IspAccounts::get((int)$q['account_id']);
    $bal = IspAccounts::balances((int)$q['account_id']);
    $ruleNow = $q['rule_id'] ? DB::row('SELECT * FROM isp_disc_rules WHERE id = :r', ['r' => $q['rule_id']]) : $rule;
    $elig = $q['reason_type'] === 'overdue' && $ruleNow ? IspDisconnect::eligible($a, $ruleNow) : null;
    $op = $q['net_op_id'] ? DB::row('SELECT * FROM isp_net_ops WHERE id = :o', ['o' => $q['net_op_id']]) : null;
    $events = DB::all("SELECT e.*, u.full_name FROM isp_account_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.account_id = :a AND e.event_type IN ('disconnection', 'network', 'disconnected', 'active', 'reconnection')
                        ORDER BY e.id DESC LIMIT 15", ['a' => $q['account_id']]);
    $names = ispnet_user_names([$q['approved_by'], $q['created_by']]);
    $live = in_array($q['status'], ['candidate', 'approved', 'notified', 'failed'], true);
    layout_start('Disconnection ' . $q['queue_no'], ['actions' => btn_link(url($pk), 'Queue', 'btn-outline-secondary', 'arrow-left')]);
    echo ispnet_sim_notice();
    ?>
    <div class="card isp-card mb-3"><div class="card-body">
      <div class="isp-head">
        <div>
          <h2 class="isp-head-title"><?= IspUi::acct($q['account_id'], $q['account_no'], $q['account_name']) ?></h2>
          <div class="isp-head-sub"><?= IspUi::status($q['status'], IspDisconnect::STATUSES) ?> <span class="text-muted"><?= h(IspDisconnect::REASON_TYPES[$q['reason_type']] ?? $q['reason_type']) ?></span></div>
        </div>
        <div class="isp-head-actions">
          <?php if ($q['status'] === 'candidate' && $canApprove && $mayDisconnect): ?>
            <form method="post" class="d-inline" data-confirm="Approve the disconnection of <?= h($q['account_no']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="ids[]" value="<?= (int)$q['id'] ?>">
              <button class="btn btn-sm btn-primary"><i class="bi bi-check2-square"></i> Approve</button></form>
          <?php endif; ?>
          <?php if (in_array($q['status'], ['approved', 'notified'], true) && $canApprove && $mayDisconnect): ?>
            <?php if ($q['status'] === 'notified' && $q['execute_after'] && strtotime((string)$q['execute_after']) > time()): ?>
              <span class="small text-muted">Final notice period ends <?= h(fdatetime($q['execute_after'])) ?></span>
            <?php else: ?>
              <?= IspUi::actionForm('execute', 'Disconnect now', ['id' => $q['id']], 'btn-danger', 'plug', 'Send the disconnection of ' . $q['account_no'] . ' to the network now? Eligibility is checked again just before sending.') ?>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($live && Access::can($pk, 'delete')): ?>
            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelQ"><i class="bi bi-x-circle"></i> Cancel</button>
          <?php endif; ?>
        </div>
      </div>
      <div class="row g-3">
        <div class="col-lg-6"><?= IspUi::dl([
            'Queue no.' => h($q['queue_no']),
            'Reason' => h($q['reason']),
            'Rule' => h($q['rule_name'] ?: 'None (asked by a person)'),
            'Initiated by' => h(str_starts_with((string)$q['initiated_by'], 'user:') ? ($names[$q['created_by']] ?? 'Staff') : 'The scheduler (nonpayment rule)') . ', ' . h(fdatetime($q['created_at'])),
            'Approved' => $q['approved_at'] ? h(($names[$q['approved_by']] ?? '') . ', ' . fdatetime($q['approved_at'])) : null,
            'Final notice' => $q['final_notice_at'] ? h(fdatetime($q['final_notice_at'])) : null,
            'Execute after' => $q['execute_after'] ? h(fdatetime($q['execute_after'])) : null,
            'Executed' => $q['executed_at'] ? h(fdatetime($q['executed_at'])) : null,
            'Not done because' => $q['skip_reason'] ? h($q['skip_reason']) : null,
        ]) ?></div>
        <div class="col-lg-6"><?= IspUi::dl([
            'Arrears when queued' => h(money($q['eligible_amount'])) . ($q['oldest_due'] ? ' <span class="text-muted">oldest due ' . h(fdate($q['oldest_due'])) . '</span>' : ''),
            'Balance now' => h(money($bal['balance'])) . ' <span class="text-muted">overdue ' . h(money($bal['overdue'])) . ($bal['credit'] > 0 ? ', unapplied credit ' . h(money($bal['credit'])) : '') . '</span>',
            'Eligible now' => $elig ? ($elig[0] ? '<span class="text-danger">Yes</span>: ' . h($elig[1]) : '<span class="text-success">No</span>: ' . h($elig[1])) : null,
            'Protection' => ($pr = IspCredit::protection((int)$q['account_id'], $ruleNow)) ? h($pr) : null,
            'Account' => IspUi::status($q['service_status'], IspAccounts::SERVICE_STATUSES) . ', network ' . IspUi::status($q['network_state'], IspAccounts::NETWORK_STATES),
            'Network operation' => $op ? safe_link('ispnet.operations', ['id' => $op['id']], $op['op_no']) . ' ' . IspUi::status($op['state'], IspNetwork::STATES) . ' ' . ispnet_sim_label($op['simulated']) : null,
        ]) ?></div>
      </div>
    </div></div>
    <div class="card isp-card"><div class="card-body">
      <h2 class="sec-title">Service history</h2>
      <?php if (!$events): ?><?= IspUi::empty('Nothing recorded yet.') ?><?php else: ?>
      <div class="table-responsive"><table class="table table-sm align-middle isp-table"><thead><tr><th>When</th><th>What</th><th>By</th></tr></thead><tbody>
        <?php foreach ($events as $e): ?><tr><td class="text-nowrap small"><?= h(fdatetime($e['event_date'])) ?></td><td><?= h($e['summary']) ?></td><td class="small"><?= h($e['full_name'] ?: 'Scheduler') ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div></div>
    <?php if ($live && Access::can($pk, 'delete')): ?>
    <div class="modal fade" id="cancelQ" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
      <div class="modal-header"><h5 class="modal-title">Cancel disconnection <?= h($q['queue_no']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><label class="form-label">Reason (recorded in the audit trail and the account history)</label><textarea class="form-control" name="reason" rows="2" required></textarea></div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button class="btn btn-danger">Cancel the disconnection</button></div>
    </form></div></div>
    <?php endif; ?>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ the queue
$tabs = ['candidate' => 'Candidates', 'waiting' => 'Approved, waiting', 'executing' => 'Executing', 'failed' => 'Failed', 'disconnected' => 'Disconnected', 'notdone' => 'Skipped / cancelled', 'all' => 'All'];
$tab = (string)g('tab', 'candidate');
if (!isset($tabs[$tab])) {
    $tab = 'candidate';
}
$qtext = trim((string)g('q'));
$reasonType = (string)g('reason_type');
$from = gdate('from');
$to = gdate('to');
$params = [];
$where = ' WHERE 1 = 1' . match ($tab) {
    'waiting' => " AND q.status IN ('approved', 'notified')",
    'notdone' => " AND q.status IN ('skipped', 'cancelled')",
    'all' => '',
    default => ' AND q.status = ' . DB::pdo()->quote($tab),
};
if ($qtext !== '') {
    $where .= ' AND (q.queue_no LIKE :q1 OR a.account_no LIKE :q2 OR a.account_name LIKE :q3)';
    $params += ['q1' => $qtext . '%', 'q2' => $qtext . '%', 'q3' => '%' . $qtext . '%'];
}
if (isset(IspDisconnect::REASON_TYPES[$reasonType])) {
    $where .= ' AND q.reason_type = :rt';
    $params['rt'] = $reasonType;
}
if ($from) {
    $where .= ' AND q.created_at >= :f';
    $params['f'] = $from . ' 00:00:00';
}
if ($to) {
    $where .= ' AND q.created_at <= :t';
    $params['t'] = $to . ' 23:59:59';
}
$where .= IspSetup::branchSql('a.branch_id', $params);
[$per, $off, $page] = IspUi::paging(50);
$total = (int)DB::value("SELECT COUNT(*) FROM isp_disc_queue q JOIN isp_accounts a ON a.id = q.account_id$where", $params);
$rows = DB::all("SELECT q.*, a.account_no, a.account_name, a.service_status, a.network_state, o.state AS op_state, o.simulated FROM isp_disc_queue q JOIN isp_accounts a ON a.id = q.account_id
                  LEFT JOIN isp_net_ops o ON o.id = q.net_op_id$where ORDER BY q.id DESC LIMIT $per OFFSET $off", $params);

$cp = [];
$bsql = IspSetup::branchSql('a.branch_id', $cp);
$sum = DB::row("SELECT SUM(q.status = 'candidate') AS cand, SUM(CASE WHEN q.status = 'candidate' THEN q.eligible_amount ELSE 0 END) AS cand_amt,
                       SUM(q.status IN ('approved', 'notified')) AS waiting, SUM(q.status = 'executing') AS executing, SUM(q.status = 'failed') AS failed,
                       SUM(q.status = 'disconnected' AND q.executed_at >= :m) AS month
                  FROM isp_disc_queue q JOIN isp_accounts a ON a.id = q.account_id WHERE 1 = 1$bsql", $cp + ['m' => date('Y-m-01') . ' 00:00:00']);

$actions = '';
if ($canApprove) {
    $actions .= IspUi::actionForm('evaluate', 'Evaluate now', [], 'btn-primary', 'search', 'Check every active account against the nonpayment rule now and queue those that qualify?') . ' ';
    $actions .= IspUi::actionForm('notices', 'Send final notices', [], 'btn-outline-primary', 'envelope-exclamation') . ' ';
    if ($mayDisconnect) {
        $actions .= IspUi::actionForm('execute_due', 'Execute due now', [], 'btn-outline-danger', 'plug', 'Send every approved disconnection whose final notice period has ended to the network now (outside the rule\'s hours too)?') . ' ';
    }
}
if (Access::can($pk, 'create') && $mayDisconnect) {
    $actions .= '<button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#manualQ"><i class="bi bi-plus-lg"></i> Disconnect an account</button>';
}
layout_start('Disconnection Queue', ['actions' => $actions]);
echo ispnet_sim_notice();
?>
<div class="kpi-grid mb-3">
  <?= IspUi::kpi('Candidates', number_format((int)$sum['cand']), money($sum['cand_amt']) . ' overdue', url($pk, ['tab' => 'candidate']), 'person-exclamation', 'warn') ?>
  <?= IspUi::kpi('Approved, waiting', number_format((int)$sum['waiting']), 'final notice running', url($pk, ['tab' => 'waiting']), 'hourglass-split', 'info') ?>
  <?= IspUi::kpi('Executing', number_format((int)$sum['executing']), 'waiting for the device', url($pk, ['tab' => 'executing']), 'broadcast', 'info') ?>
  <?= IspUi::kpi('Failed', number_format((int)$sum['failed']), 'still connected', url($pk, ['tab' => 'failed']), 'exclamation-octagon', 'bad') ?>
  <?= IspUi::kpi('Disconnected this month', number_format((int)$sum['month']), 'confirmed by the devices', url($pk, ['tab' => 'disconnected']), 'plug', 'idle') ?>
</div>

<?= IspUi::tabs($tabs, $tab, $pk, array_filter(['q' => $qtext, 'reason_type' => $reasonType, 'from' => $from, 'to' => $to])) ?>
<div class="row g-3 mb-3">
  <div class="col-lg-8 order-2 order-lg-1">
    <?= IspUi::filterStart($pk, ['tab' => $tab]) ?>
      <?= IspUi::fSearch($qtext, 'Queue no., account no. or name') ?>
      <?= IspUi::fSelect('reason_type', 'Reason', IspDisconnect::REASON_TYPES, $reasonType) ?>
      <?= IspUi::fDate('from', 'Queued from', $from) ?>
      <?= IspUi::fDate('to', 'To', $to) ?>
    <?= IspUi::filterEnd(url($pk, ['tab' => $tab])) ?>
  </div>
  <div class="col-lg-4 order-1 order-lg-2">
    <div class="card isp-card h-100"><div class="card-body">
      <h2 class="sec-title">Nonpayment rule</h2>
      <?php if (!$rule): ?>
        <p class="small text-muted mb-0">No active nonpayment rule: nothing is queued automatically.</p>
      <?php else: ?>
        <?= IspUi::dl([
            'Grace period' => h((int)$rule['grace_days'] . ' day(s) after the due date'),
            'Minimum' => h(money($rule['min_overdue_amount']) . ', ' . (int)$rule['min_overdue_invoices'] . ' invoice(s)'),
            'Final notice' => h((int)$rule['final_notice_hours'] ? (int)$rule['final_notice_hours'] . ' hours before' : 'None'),
            'Approval' => h((int)$rule['require_approval'] ? 'A supervisor approves each candidate' : 'Not needed'),
            'Runs' => h(((int)$rule['auto_execute'] ? 'Automatically' : 'When a person executes') . ', ' . sprintf('%02d:00-%02d:00', (int)$rule['run_from_hour'], (int)$rule['run_to_hour'])),
            'Protected' => h(implode(', ', array_filter([(int)$rule['protect_disputes'] ? 'disputed invoices' : '', (int)$rule['protect_arrangements'] ? 'payment arrangements' : '', 'exemptions']))),
        ]) ?>
        <?php if (Access::page('ispset.policies')): ?><a class="small" href="<?= h(url('ispset.policies')) ?>"><i class="bi bi-sliders"></i> Change the rules</a><?php endif; ?>
      <?php endif; ?>
    </div></div>
  </div>
</div>

<div class="card isp-card"><div class="card-body">
<?php if (!$rows): ?>
  <?= IspUi::empty($tab === 'candidate' ? 'No candidate. Evaluate now to check the accounts against the rule.' : 'Nothing in this list.', 'plug') ?>
<?php else: ?>
  <?php $bulk = $tab === 'candidate' && $canApprove && $mayDisconnect; ?>
  <?php if ($bulk): ?><form method="post" data-confirm="Approve the disconnection of the ticked accounts? Final notices go out where the rule asks for them."><?= csrf_field() ?><input type="hidden" name="action" value="approve"><?php endif; ?>
  <?php if ($tab === 'candidate'): ?><p class="small text-muted">Preview: the eligibility below is recalculated now from the posted payments, disputes, arrangements and exemptions. Pending payment proofs do not count.</p><?php endif; ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
    <thead><tr>
      <?php if ($bulk): ?><th class="no-print"><input class="form-check-input" type="checkbox" data-check-all="ids" aria-label="Tick all"></th><?php endif; ?>
      <th>Queue no.</th><th>Account</th><th>Reason</th><th>Status</th><th class="num">Arrears queued</th><th>Eligibility now</th><th>Queued</th><th class="no-print"></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $now = null;
        if (in_array($r['status'], ['candidate', 'approved', 'notified'], true) && $r['reason_type'] === 'overdue' && $rule) {
            $ar = IspDisconnect::arrears((int)$r['account_id'], $rule);
            $now = $ar['protected'] ? ['ok', 'Protected: ' . $ar['protected']] : ($ar['amount'] >= max(0.01, (float)$rule['min_overdue_amount']) && $ar['invoices'] >= max(1, (int)$rule['min_overdue_invoices'])
                ? ['bad', money($ar['amount']) . ' overdue since ' . fdate($ar['oldest_due']) . ($ar['disputed'] > 0 ? ' (' . money($ar['disputed']) . ' disputed, left out)' : '')]
                : ['ok', 'No longer qualifies (' . money($ar['amount']) . ')']);
        } ?>
      <tr>
        <?php if ($bulk): ?><td class="no-print"><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" <?= $now && $now[0] === 'bad' ? 'checked' : '' ?> aria-label="Tick <?= h($r['account_no']) ?>"></td><?php endif; ?>
        <td><a href="<?= h(url($pk, ['id' => $r['id']])) ?>"><?= h($r['queue_no']) ?></a></td>
        <td><?= IspUi::acct($r['account_id'], $r['account_no'], $r['account_name']) ?></td>
        <td class="small"><?= h(IspDisconnect::REASON_TYPES[$r['reason_type']] ?? $r['reason_type']) ?></td>
        <td><?= IspUi::status($r['status'], IspDisconnect::STATUSES) ?><?php if ($r['op_state']): ?><div class="small"><?= ispnet_sim_label($r['simulated']) ?></div><?php endif; ?>
          <?php if ($r['status'] === 'notified' && $r['execute_after']): ?><div class="small text-muted">from <?= h(fdatetime($r['execute_after'])) ?></div><?php endif; ?></td>
        <td class="num"><?= h(money($r['eligible_amount'])) ?></td>
        <td class="small"><?php if ($now): ?><span class="<?= $now[0] === 'bad' ? 'text-danger' : 'text-success' ?>"><?= h($now[1]) ?></span><?php else: ?><span class="text-muted"><?= h($r['skip_reason'] ?: '') ?></span><?php endif; ?></td>
        <td class="small text-nowrap"><?= h(fdatetime($r['created_at'])) ?></td>
        <td class="text-end no-print"><a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pk, ['id' => $r['id']])) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($bulk): ?><div class="d-flex gap-2 align-items-center mt-2"><button class="btn btn-sm btn-primary"><i class="bi bi-check2-square"></i> Approve the ticked</button><span class="small text-muted">Each is checked again just before its command is sent.</span></div></form><?php endif; ?>
  <?= IspUi::pager($total, $per, $page) ?>
<?php endif; ?>
</div></div>

<?php if (Access::can($pk, 'create') && $mayDisconnect): ?>
<div class="modal fade" id="manualQ" tabindex="-1"><div class="modal-dialog modal-lg"><form class="modal-content" method="post">
  <?= csrf_field() ?><input type="hidden" name="action" value="manual">
  <div class="modal-header"><h5 class="modal-title">Disconnect an account</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-2">
    <?= IspUi::accountField('account_id', gint('account_id'), 'Subscriber account', 'col-md-7') ?>
    <?= select_field('reason_type', 'Reason', array_diff_key(IspDisconnect::REASON_TYPES, ['overdue' => 1]) + ['overdue' => 'Overdue payment (by hand)'], 'technical', ['class' => 'col-md-5']) ?>
    <div class="col-12"><label class="form-label">Details (recorded with the disconnection)</label><textarea class="form-control form-control-sm" name="details" rows="2" required></textarea></div>
    <?= check_field('execute_now', 'Send it to the network now', false, 'col-12') ?>
  </div>
  <p class="small text-muted mt-2 mb-0">A customer-requested suspension leaves the account Suspended; fraud, technical, administrative and cancellation disconnections need an authorized person to reconnect.</p></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button class="btn btn-danger"><i class="bi bi-plug"></i> Queue the disconnection</button></div>
</form></div></div>
<?php endif; ?>
<?php
layout_end();
