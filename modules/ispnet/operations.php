<?php
declare(strict_types=1);

/**
 * Network Operations: the queue between the business records and the devices (isp_net_ops). Every activation,
 * disconnection, reconnection, plan change and session reset goes through it with its own state: requested, queued,
 * sent, confirmed, failed, manual or cancelled. Only a device confirmation makes an operation "confirmed".
 */
require_once __DIR__ . '/_common.php';

$pk = 'ispnet.operations';
$GLOBALS['ISPNET_RECONCILE'] = null;

handle_post(function () use ($pk) {
    $action = (string)p('action');
    $id = (int)p('id');
    switch ($action) {
        case 'process':
            Access::require($pk, 'edit');
            ispnet_require_admin();
            $r = IspNetwork::processQueue(200);
            redirect(url($pk, ['state' => 'open']), 'Queue processed: ' . $r['sent'] . ' operation(s) sent, ' . $r['verified'] . ' waiting confirmation read back from the device.', 'success');
        case 'retry':
            Access::require($pk, 'edit');
            ispnet_require_admin();
            IspNetwork::retry($id);
            $op = DB::row('SELECT op_no, state FROM isp_net_ops WHERE id = :id', ['id' => $id]);
            redirect(url($pk, ['id' => $id]), 'Operation ' . $op['op_no'] . ' sent again: now ' . (IspNetwork::STATES[$op['state']] ?? $op['state']) . '.', $op['state'] === 'confirmed' ? 'success' : 'warning');
        case 'cancel':
            Access::require($pk, 'delete');
            ispnet_require_admin();
            $reason = trim((string)p('reason'));
            if ($reason === '') {
                fail('Enter the reason for cancelling the operation.');
            }
            $op = DB::row('SELECT * FROM isp_net_ops WHERE id = :id', ['id' => $id]) ?? fail('Operation not found.');
            IspNetwork::cancel($id, $reason);
            // the business record the operation served is told
            if ($op['source_type'] === 'disc_queue' && $op['source_id']) {
                $q = DB::row('SELECT status FROM isp_disc_queue WHERE id = :q', ['q' => $op['source_id']]);
                if ($q && in_array($q['status'], ['executing', 'failed'], true)) {
                    DB::run("UPDATE isp_disc_queue SET status = 'failed', skip_reason = :r WHERE id = :q", ['r' => mb_substr('Network operation cancelled: ' . $reason, 0, 255), 'q' => $op['source_id']]);
                }
            } elseif ($op['source_type'] === 'reconnection' && $op['source_id']) {
                IspReconnect::cancelled((int)$op['source_id'], 'Network operation cancelled: ' . $reason);
            }
            IspAccounts::event((int)$op['account_id'], 'network', 'Network operation ' . $op['op_no'] . ' cancelled: ' . $reason, 'net_op', $id);
            redirect(url($pk, ['id' => $id]), 'Operation cancelled.', 'success');
        case 'confirm_manual':
            Access::require($pk, 'edit');
            ispnet_require_admin();
            $note = trim((string)p('note'));
            if ($note === '') {
                fail('Enter what was done (or why it could not be done).');
            }
            $done = p('done') === '1';
            IspNetwork::confirmManual($id, $done, $note);
            redirect(url($pk, ['id' => $id]), $done ? 'Marked done by hand: the account state follows.' : 'Recorded as not done.', $done ? 'success' : 'warning');
        case 'request':
            Access::require($pk, 'create');
            ispnet_require_admin();
            $acct = IspUi::postedAccount('account_id') ?? fail('Choose the subscriber account.');
            if (!ispnet_account_visible($acct)) {
                fail('That account belongs to another branch.');
            }
            $op = (string)p('op_type');
            if (!in_array($op, ['status', 'reset_session', 'change_plan', 'provision'], true)) {
                fail('Choose the operation. Disconnections and reconnections go through their own queues, with their eligibility checks.');
            }
            $reason = trim((string)p('reason')) ?: (IspNetwork::OP_TYPES[$op] . ' requested by ' . (Auth::user()['full_name'] ?? 'staff'));
            // a new operation each time it is asked (status reads and session resets repeat)
            $opId = IspNetwork::request($acct, $op, $reason, 'manual', time());
            Audit::log('create', 'ispnet', 'isp_net_op', $opId, (string)DB::value('SELECT op_no FROM isp_net_ops WHERE id = :i', ['i' => $opId]), ['page_key' => $pk, 'reason' => $reason]);
            $st = (string)DB::value('SELECT state FROM isp_net_ops WHERE id = :i', ['i' => $opId]);
            redirect(url($pk, ['id' => $opId]), 'Operation sent: ' . (IspNetwork::STATES[$st] ?? $st) . '.', $st === 'confirmed' ? 'success' : 'warning');
        case 'reconcile':
            Access::require($pk, 'edit');
            ispnet_require_admin();
            $GLOBALS['ISPNET_RECONCILE'] = IspNetwork::reconcile(500);
            Audit::log('reconcile', 'ispnet', 'isp_net_op', null, 'Network reconciliation', ['page_key' => $pk, 'reason' => count($GLOBALS['ISPNET_RECONCILE']) . ' difference(s)']);
            return;
        default:
            fail('Unknown action.');
    }
});

$admin = (bool)Access::special('isp_network_admin');
$id = gint('id');

// ------------------------------------------------------------------ one operation
if ($id) {
    $op = DB::row('SELECT o.*, a.account_no, a.account_name, a.service_status, a.network_state, a.branch_id, d.name AS device_name, d.adapter
                     FROM isp_net_ops o JOIN isp_accounts a ON a.id = o.account_id LEFT JOIN isp_devices d ON d.id = o.device_id WHERE o.id = :id', ['id' => $id]);
    if (!$op || !ispnet_account_visible((int)$op['account_id'])) {
        layout_start('Network operation');
        echo IspUi::empty('The operation was not found.');
        layout_end();
        return;
    }
    $logs = DB::all('SELECT l.*, u.full_name FROM isp_net_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.op_id = :o ORDER BY l.id', ['o' => $id]);
    $names = ispnet_user_names([$op['requested_by'], $op['approved_by']]);
    $source = match ($op['source_type']) {
        'disc_queue' => ['Disconnection', DB::value('SELECT queue_no FROM isp_disc_queue WHERE id = :i', ['i' => $op['source_id']]), 'ispnet.disconnections'],
        'reconnection' => ['Reconnection', DB::value('SELECT recon_no FROM isp_reconnections WHERE id = :i', ['i' => $op['source_id']]), 'ispnet.reconnections'],
        'activation' => ['Service activation', null, null],
        'termination' => ['Termination', null, null],
        default => [ucfirst(str_replace('_', ' ', (string)$op['source_type'])), null, null],
    };
    $wo = $op['work_order_id'] ? DB::row('SELECT id, wo_no, status FROM isp_work_orders WHERE id = :w', ['w' => $op['work_order_id']]) : null;
    $actions = btn_link(url($pk), 'All operations', 'btn-outline-secondary', 'arrow-left');
    layout_start('Network operation ' . $op['op_no'], ['actions' => $actions]);
    echo ispnet_sim_notice();
    ?>
    <div class="card isp-card mb-3"><div class="card-body">
      <div class="isp-head">
        <div>
          <h2 class="isp-head-title"><?= h(IspNetwork::OP_TYPES[$op['op_type']] ?? $op['op_type']) ?> <?= IspUi::acct($op['account_id'], $op['account_no'], $op['account_name']) ?></h2>
          <div class="isp-head-sub"><?= IspUi::status($op['state'], IspNetwork::STATES) ?> <?= ispnet_sim_label($op['simulated']) ?></div>
        </div>
        <div class="isp-head-actions">
          <?php if ($admin && Access::can($pk, 'edit') && in_array($op['state'], ['failed', 'sent'], true)): ?>
            <?= IspUi::actionForm('retry', 'Retry now', ['id' => $op['id']], 'btn-primary', 'arrow-repeat', 'Send operation ' . $op['op_no'] . ' to the device again?') ?>
          <?php endif; ?>
          <?php if ($admin && Access::can($pk, 'edit') && in_array($op['state'], ['manual', 'failed', 'sent'], true)): ?>
            <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#confirmManual"><i class="bi bi-person-check"></i> Confirm done by hand</button>
          <?php endif; ?>
          <?php if ($admin && Access::can($pk, 'delete') && in_array($op['state'], ['queued', 'failed', 'manual', 'requested'], true)): ?>
            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelOp"><i class="bi bi-x-circle"></i> Cancel</button>
          <?php endif; ?>
        </div>
      </div>
      <div class="row g-3">
        <div class="col-lg-6"><?= IspUi::dl([
            'Operation' => h($op['op_no']),
            'Reason' => h($op['reason']),
            'Requested by' => h(($names[$op['requested_by']] ?? 'Scheduler') . ', ' . fdatetime($op['created_at'])),
            'Origin' => h($source[0]) . ($source[1] && $source[2] && Access::page($source[2]) ? ' <a href="' . h(url($source[2], ['id' => $op['source_id']])) . '">' . h($source[1]) . '</a>' : ($source[1] ? ' ' . h($source[1]) : '')),
            'Device' => h($op['device_name'] ? $op['device_name'] . ' (' . (IspNetwork::ADAPTER_LABELS[$op['adapter']] ?? $op['adapter']) . ')' : 'No device: manual work order'),
            'Work order' => $wo ? safe_link('ispfield.workorders', ['id' => $wo['id']], $wo['wo_no']) . ' ' . IspUi::status($wo['status'], IspWorkOrders::STATUSES) : null,
        ]) ?></div>
        <div class="col-lg-6"><?= IspUi::dl([
            'Attempts' => h($op['attempts'] . ' of ' . $op['max_attempts']),
            'Next try' => $op['next_try_at'] && in_array($op['state'], ['queued', 'sent'], true) ? h(fdatetime($op['next_try_at']) . ' (' . IspUi::ago($op['next_try_at']) . ')') : null,
            'Sent' => $op['sent_at'] ? h(fdatetime($op['sent_at'])) : null,
            'Confirmed' => $op['confirmed_at'] ? h(fdatetime($op['confirmed_at'])) : null,
            'Last failure' => $op['failed_at'] ? h(fdatetime($op['failed_at'])) : null,
            'Account now' => IspUi::status($op['service_status'], IspAccounts::SERVICE_STATUSES) . ', network ' . IspUi::status($op['network_state'], IspAccounts::NETWORK_STATES),
        ]) ?></div>
      </div>
      <?php if ($op['error']): ?><div class="alert alert-danger mt-3 mb-0 small"><strong>Error:</strong> <?= h(ispnet_scrub($op['error'])) ?></div><?php endif; ?>
      <?php if ($op['response']): ?><div class="mt-3"><div class="form-label">Answer of the device</div><pre class="isp-pre small mb-0"><?= h(ispnet_scrub($op['response'])) ?></pre></div><?php endif; ?>
      <?php if ($op['payload']): ?><div class="mt-3"><div class="form-label">Request sent</div><pre class="isp-pre small mb-0"><?= h(ispnet_scrub($op['payload'])) ?></pre></div><?php endif; ?>
    </div></div>

    <div class="card isp-card"><div class="card-body">
      <h2 class="sec-title">Command log</h2>
      <?php if (!$logs): ?>
        <?= IspUi::empty('No command was sent yet.', 'terminal') ?>
      <?php else: ?>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
        <thead><tr><th>When</th><th>Direction</th><th>Command</th><th>Result</th><th>OK</th><th>By</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td class="text-nowrap"><?= h(fdatetime($l['created_at'])) ?></td>
            <td><?= h($l['direction'] === 'out' ? 'To the device' : ($l['direction'] === 'in' ? 'From the device' : 'Note')) ?></td>
            <td><code class="small"><?= h(ispnet_scrub($l['command'])) ?></code></td>
            <td class="small"><?= h(ispnet_scrub($l['result'])) ?> <?= ispnet_sim_label($l['simulated']) ?></td>
            <td><?= (int)$l['ok'] === 1 ? IspUi::status('ok', ['ok' => 'Yes']) : IspUi::status('failed', ['failed' => 'No']) ?></td>
            <td class="small"><?= h($l['full_name'] ?: 'Scheduler') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div></div>

    <div class="modal fade" id="cancelOp" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$op['id'] ?>">
      <div class="modal-header"><h5 class="modal-title">Cancel operation <?= h($op['op_no']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><p class="small text-muted">Nothing more is sent to the device. A disconnection or reconnection this operation served is left for a person to decide.</p>
        <label class="form-label">Reason (recorded in the audit trail)</label><textarea class="form-control" name="reason" rows="2" required></textarea></div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button class="btn btn-danger">Cancel the operation</button></div>
    </form></div></div>

    <div class="modal fade" id="confirmManual" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="confirm_manual"><input type="hidden" name="id" value="<?= (int)$op['id'] ?>">
      <div class="modal-header"><h5 class="modal-title">Confirm the work done by hand</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <p class="small text-muted">Use this only when a person did the <?= h(strtolower(IspNetwork::OP_TYPES[$op['op_type']] ?? $op['op_type'])) ?> on the network equipment and checked it. The account's network state follows what you confirm.</p>
        <div class="mb-2"><label class="form-label">Result</label><select class="form-select form-select-sm" name="done"><option value="1">Done and checked</option><option value="0">Could not be done</option></select></div>
        <label class="form-label">What was done (or why not)</label><textarea class="form-control" name="note" rows="2" required></textarea>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button class="btn btn-success">Record</button></div>
    </form></div></div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ the queue
$states = ['open' => 'Open', 'problem' => 'Needs attention', 'queued' => 'Queued', 'sent' => 'Waiting confirmation', 'confirmed' => 'Confirmed', 'failed' => 'Failed', 'manual' => 'Manual', 'cancelled' => 'Cancelled', 'all' => 'All'];
$state = (string)g('state', 'open');
if (!isset($states[$state])) {
    $state = 'open';
}
$q = trim((string)g('q'));
$opType = (string)g('op_type');
$sim = (string)g('sim');
$from = gdate('from');
$to = gdate('to');

$where = ' WHERE 1 = 1';
$params = [];
$where .= match ($state) {
    'open' => " AND o.state IN ('requested', 'queued', 'sent', 'failed', 'manual')",
    'problem' => " AND o.state IN ('failed', 'manual')",
    'queued' => " AND o.state IN ('requested', 'queued')",
    'all' => '',
    default => ' AND o.state = ' . DB::pdo()->quote($state),
};
if ($q !== '') {
    $where .= ' AND (o.op_no LIKE :q1 OR a.account_no LIKE :q2 OR a.account_name LIKE :q3 OR o.reason LIKE :q4)';
    $params += ['q1' => $q . '%', 'q2' => $q . '%', 'q3' => '%' . $q . '%', 'q4' => '%' . $q . '%'];
}
if (isset(IspNetwork::OP_TYPES[$opType])) {
    $where .= ' AND o.op_type = :t';
    $params['t'] = $opType;
}
if ($sim === '1' || $sim === '0') {
    $where .= ' AND o.simulated = :s';
    $params['s'] = (int)$sim;
}
if ($from) {
    $where .= ' AND o.created_at >= :f';
    $params['f'] = $from . ' 00:00:00';
}
if ($to) {
    $where .= ' AND o.created_at <= :tt';
    $params['tt'] = $to . ' 23:59:59';
}
$where .= IspSetup::branchSql('a.branch_id', $params);

$cp = [];
$counts = DB::pairs('SELECT o.state, COUNT(*) FROM isp_net_ops o JOIN isp_accounts a ON a.id = o.account_id WHERE 1 = 1' . IspSetup::branchSql('a.branch_id', $cp) . ' GROUP BY o.state', $cp);
[$per, $off, $page] = IspUi::paging(50);
$total = (int)DB::value("SELECT COUNT(*) FROM isp_net_ops o JOIN isp_accounts a ON a.id = o.account_id$where", $params);
$rows = DB::all("SELECT o.*, a.account_no, a.account_name, d.name AS device_name, d.adapter FROM isp_net_ops o JOIN isp_accounts a ON a.id = o.account_id
                  LEFT JOIN isp_devices d ON d.id = o.device_id$where ORDER BY o.id DESC LIMIT $per OFFSET $off", $params);

$actions = '';
if ($admin && Access::can($pk, 'edit')) {
    $actions .= IspUi::actionForm('process', 'Process queue now', [], 'btn-primary', 'play-circle', 'Send every queued operation that is due and read back the ones waiting for confirmation?') . ' ';
    $actions .= IspUi::actionForm('reconcile', 'Reconcile with devices', [], 'btn-outline-primary', 'arrow-left-right', 'Read the state of every provisioned subscriber from its device and compare it with the expected state?');
}
if ($admin && Access::can($pk, 'create')) {
    $actions .= ' <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#newOp"><i class="bi bi-terminal"></i> Run a command</button>';
}
layout_start('Network Operations', ['actions' => $actions]);
echo ispnet_sim_notice();

$recon = $GLOBALS['ISPNET_RECONCILE'];
if ($recon !== null): ?>
  <div class="card isp-card mb-3"><div class="card-body">
    <h2 class="sec-title">Reconciliation result</h2>
    <?php if (!$recon): ?>
      <p class="mb-0 text-success"><i class="bi bi-check2-circle"></i> Every provisioned subscriber the devices could read is in the expected state.</p>
    <?php else: ?>
      <p class="small text-muted">The stored network state of each account below was updated to what its device reported. Accounts whose actual state differs from the expected one need a person: retry the operation, or check the subscriber.</p>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
        <thead><tr><th>Account</th><th>Expected</th><th>Actual on the device</th><th>Note</th></tr></thead>
        <tbody><?php foreach ($recon as $r): ?>
          <tr><td><?= IspUi::acct($r['account_id'], $r['account_no']) ?></td><td><?= h(IspAccounts::NETWORK_STATES[$r['expected']] ?? $r['expected']) ?></td>
            <td><?= IspUi::status($r['actual'], IspAccounts::NETWORK_STATES + ['unreadable' => 'Could not be read']) ?></td><td class="small"><?= h(ispnet_scrub($r['note'])) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
  </div></div>
<?php endif;

$kp = fn(string $s) => (int)($counts[$s] ?? 0);
?>
<div class="kpi-grid mb-3">
  <?= IspUi::kpi('Queued', number_format($kp('queued') + $kp('requested')), 'waiting to be sent', url($pk, ['state' => 'queued']), 'hourglass-split', 'info') ?>
  <?= IspUi::kpi('Waiting confirmation', number_format($kp('sent')), 'sent, not yet read back', url($pk, ['state' => 'sent']), 'broadcast', 'warn') ?>
  <?= IspUi::kpi('Failed', number_format($kp('failed')), 'need a person', url($pk, ['state' => 'failed']), 'exclamation-octagon', 'bad') ?>
  <?= IspUi::kpi('Manual', number_format($kp('manual')), 'work orders to confirm', url($pk, ['state' => 'manual']), 'person-gear', 'warn') ?>
  <?= IspUi::kpi('Confirmed', number_format($kp('confirmed')), 'by the devices', url($pk, ['state' => 'confirmed']), 'check2-circle', 'ok') ?>
</div>
<?= IspUi::tabs($states, $state, $pk, array_filter(['q' => $q, 'op_type' => $opType, 'sim' => $sim, 'from' => $from, 'to' => $to]), 'state') ?>
<?= IspUi::filterStart($pk, ['state' => $state]) ?>
  <?= IspUi::fSearch($q, 'Operation no., account, name or reason') ?>
  <?= IspUi::fSelect('op_type', 'Operation', IspNetwork::OP_TYPES, $opType) ?>
  <?= IspUi::fSelect('sim', 'Source', ['0' => 'Real devices', '1' => 'Simulator'], $sim) ?>
  <?= IspUi::fDate('from', 'From', $from) ?>
  <?= IspUi::fDate('to', 'To', $to) ?>
<?= IspUi::filterEnd(url($pk, ['state' => $state])) ?>

<div class="card isp-card"><div class="card-body">
<?php if (!$rows): ?>
  <?= IspUi::empty('No network operation matches.', 'diagram-3') ?>
<?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
    <thead><tr><th>Operation</th><th>Account</th><th>Kind</th><th>State</th><th>Device</th><th class="num">Attempts</th><th>Requested</th><th>Note</th><th class="no-print"></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="<?= h(url($pk, ['id' => $r['id']])) ?>"><?= h($r['op_no']) ?></a></td>
        <td><?= IspUi::acct($r['account_id'], $r['account_no'], $r['account_name']) ?></td>
        <td><?= h(IspNetwork::OP_TYPES[$r['op_type']] ?? $r['op_type']) ?></td>
        <td><?= IspUi::status($r['state'], IspNetwork::STATES) ?><?= (int)$r['simulated'] === 1 ? '<div>' . ispnet_sim_label(1) . '</div>' : '' ?></td>
        <td class="small"><?= h($r['device_name'] ?: 'Manual') ?></td>
        <td class="num"><?= (int)$r['attempts'] ?>/<?= (int)$r['max_attempts'] ?></td>
        <td class="small text-nowrap"><?= h(fdatetime($r['created_at'])) ?></td>
        <td class="small"><?= h(mb_strimwidth(ispnet_scrub($r['error'] ?: $r['reason']), 0, 90, '...')) ?></td>
        <td class="text-end no-print text-nowrap">
          <?php if ($admin && Access::can($pk, 'edit') && in_array($r['state'], ['failed', 'sent'], true)): ?>
            <?= IspUi::actionForm('retry', 'Retry', ['id' => $r['id']], 'btn-outline-primary', 'arrow-repeat') ?>
          <?php endif; ?>
          <a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pk, ['id' => $r['id']])) ?>">Open</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= IspUi::pager($total, $per, $page) ?>
<?php endif; ?>
</div></div>

<?php if ($admin && Access::can($pk, 'create')): ?>
<div class="modal fade" id="newOp" tabindex="-1"><div class="modal-dialog modal-lg"><form class="modal-content" method="post">
  <?= csrf_field() ?><input type="hidden" name="action" value="request">
  <div class="modal-header"><h5 class="modal-title">Run a network command</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-2">
    <?= IspUi::accountField('account_id', null, 'Subscriber account', 'col-md-7') ?>
    <?= select_field('op_type', 'Command', ['status' => 'Read the status from the device', 'reset_session' => 'Reset the session (reconnect the PPPoE)', 'change_plan' => 'Push the plan profile again', 'provision' => 'Provision again (credentials and profile)'], 'status', ['class' => 'col-md-5']) ?>
    <?= input_field('reason', 'Reason', '', ['class' => 'col-12', 'extra' => 'maxlength="200" placeholder="Why this is needed (recorded with the operation)"']) ?>
  </div>
  <p class="small text-muted mt-2 mb-0">Disconnections and reconnections are not run from here: they go through the Disconnection Queue and Reconnections pages, which check the subscriber's eligibility first.</p></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button class="btn btn-primary"><i class="bi bi-send"></i> Send</button></div>
</form></div></div>
<?php endif; ?>
<?php
layout_end();
