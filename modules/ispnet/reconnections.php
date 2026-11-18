<?php
declare(strict_types=1);

/**
 * Reconnections (Workflow D): automatic ones after a verified payment, and those an authorized person asks for:
 * manual, temporary (with an expiry), under a payment arrangement, partial-payment and emergency restoration. The
 * service shows Active only when the device confirms; a failed reconnection stays here until it is retried or done
 * by hand, even when the account is fully paid.
 */
require_once __DIR__ . '/_common.php';

$pk = 'ispnet.reconnections';

handle_post(function () use ($pk) {
    $action = (string)p('action');
    $id = (int)p('id');
    $outcome = function (string $res): array {
        return match ($res) {
            'confirmed' => ['success', 'Service restored: the device confirmed it.'],
            'manual' => ['warning', 'No device does this account: a work order was made. The account stays disconnected until the technician confirms.'],
            'retry', 'queued' => ['warning', 'The device did not do it; it will be retried automatically. The service is not restored yet.'],
            'failed' => ['danger', 'The reconnection FAILED. The service is NOT restored; a work order was made for the network administrator.'],
            'waiting' => ['warning', 'Waiting for the payment of the reconnection fee before the service is restored.'],
            'sent' => ['warning', 'Sent; waiting for the device to confirm.'],
            default => ['info', 'Not sent: the account no longer qualifies (checked just before sending).'],
        };
    };
    switch ($action) {
        case 'manual':
            Access::require($pk, 'create');
            $acct = IspUi::postedAccount('account_id') ?? fail('Choose the subscriber account.');
            if (!ispnet_account_visible($acct)) {
                fail('That account belongs to another branch.');
            }
            $exp = trim((string)p('expires_at'));
            $rid = IspReconnect::manual($acct, (string)p('recon_type'), trim((string)p('reason')), $exp !== '' ? date('Y-m-d H:i:s', strtotime($exp)) : null);
            $r = DB::row('SELECT r.status, o.state FROM isp_reconnections r LEFT JOIN isp_net_ops o ON o.id = r.net_op_id WHERE r.id = :i', ['i' => $rid]);
            [$t, $m] = $outcome($r['state'] ?? ($r['status'] === 'approved' ? 'waiting' : (string)$r['status']));
            redirect(url($pk, ['id' => $rid]), $m, $t);
        case 'auto':
            Access::require($pk, 'create');
            $acct = (int)p('account_id');
            $a = IspAccounts::get($acct) ?? fail('Account not found.');
            if (!ispnet_account_visible($acct)) {
                fail('That account belongs to another branch.');
            }
            if (($b = IspReconnect::blocker($a)) !== null) {
                fail('Account ' . $a['account_no'] . ' does not qualify for an automatic reconnection: ' . $b . '.');
            }
            $rid = IspReconnect::onPayment($acct, null) ?? fail('A reconnection of this account is already in progress.');
            $st = (string)DB::value('SELECT o.state FROM isp_reconnections r LEFT JOIN isp_net_ops o ON o.id = r.net_op_id WHERE r.id = :i', ['i' => $rid]);
            [$t, $m] = $outcome($st ?: 'waiting');
            redirect(url($pk, ['id' => $rid]), $m, $t);
        case 'approve':
            Access::require($pk, 'approve');
            [$t, $m] = $outcome(IspReconnect::approve($id, trim((string)p('note'))));
            redirect(url($pk, ['id' => $id]), $m, $t);
        case 'execute':
            Access::require($pk, 'edit');
            if (!Access::special('isp_reconnect')) {
                fail('Sending reconnections needs the permission "Cable & Internet: can reconnect subscribers manually".');
            }
            $res = IspReconnect::execute($id);
            [$t, $m] = $outcome($res === 'skipped' ? 'skipped' : $res);
            redirect(url($pk, ['id' => $id]), $m, $t);
        case 'retry':
            Access::require($pk, 'edit');
            if (!Access::special('isp_reconnect')) {
                fail('Retrying reconnections needs the permission "Cable & Internet: can reconnect subscribers manually".');
            }
            $r = DB::row('SELECT * FROM isp_reconnections WHERE id = :id', ['id' => $id]) ?? fail('Reconnection not found.');
            if (!$r['net_op_id'] || $r['status'] !== 'failed') {
                fail('Only a failed reconnection is retried.');
            }
            IspNetwork::retry((int)$r['net_op_id']);
            $st = (string)DB::value('SELECT state FROM isp_net_ops WHERE id = :o', ['o' => $r['net_op_id']]);
            [$t, $m] = $outcome($st);
            redirect(url($pk, ['id' => $id]), $m, $t);
        case 'cancel':
            Access::require($pk, 'delete');
            $reason = trim((string)p('reason'));
            if ($reason === '') {
                fail('Enter the reason.');
            }
            IspReconnect::cancel($id, $reason);
            Audit::log('cancel', 'ispnet', 'isp_reconnection', $id, (string)DB::value('SELECT recon_no FROM isp_reconnections WHERE id = :i', ['i' => $id]), ['page_key' => $pk, 'reason' => $reason]);
            redirect(url($pk, ['id' => $id]), 'Reconnection cancelled.', 'success');
        default:
            fail('Unknown action.');
    }
});

$mayReconnect = (bool)Access::special('isp_reconnect');
$id = gint('id');

// ------------------------------------------------------------------ one reconnection
if ($id) {
    $r = DB::row('SELECT r.*, a.account_no, a.account_name, a.service_status, a.network_state, o.op_no, o.state AS op_state, o.simulated, o.error AS op_error,
                         c.amount AS fee_charged, c.status AS fee_status, c.invoice_id AS fee_invoice
                    FROM isp_reconnections r JOIN isp_accounts a ON a.id = r.account_id LEFT JOIN isp_net_ops o ON o.id = r.net_op_id LEFT JOIN isp_charges c ON c.id = r.fee_charge_id
                   WHERE r.id = :id', ['id' => $id]);
    if (!$r || !ispnet_account_visible((int)$r['account_id'])) {
        layout_start('Reconnection');
        echo IspUi::empty('The reconnection was not found.');
        layout_end();
        return;
    }
    $a = IspAccounts::get((int)$r['account_id']);
    $bal = IspAccounts::balances((int)$r['account_id']);
    $names = ispnet_user_names([$r['requested_by'], $r['approved_by']]);
    $pay = $r['payment_id'] ? DB::row('SELECT id, receipt_no, amount, pay_date FROM isp_payments WHERE id = :p', ['p' => $r['payment_id']]) : null;
    $wo = DB::row('SELECT w.id, w.wo_no, w.status FROM isp_work_orders w WHERE w.net_op_id = :o ORDER BY w.id DESC LIMIT 1', ['o' => (int)$r['net_op_id']]);
    layout_start('Reconnection ' . $r['recon_no'], ['actions' => btn_link(url($pk), 'Reconnections', 'btn-outline-secondary', 'arrow-left')]);
    echo ispnet_sim_notice();
    ?>
    <div class="card isp-card mb-3"><div class="card-body">
      <div class="isp-head">
        <div>
          <h2 class="isp-head-title"><?= IspUi::acct($r['account_id'], $r['account_no'], $r['account_name']) ?></h2>
          <div class="isp-head-sub"><?= IspUi::status($r['status'], IspReconnect::STATUSES) ?> <span class="text-muted"><?= h(IspReconnect::TYPES[$r['recon_type']] ?? $r['recon_type']) ?></span></div>
        </div>
        <div class="isp-head-actions">
          <?php if (in_array($r['status'], ['requested', 'pending_approval'], true) && Access::can($pk, 'approve') && $mayReconnect): ?>
            <?= IspUi::actionForm('approve', 'Approve and reconnect', ['id' => $r['id']], 'btn-primary', 'check2-square', 'Approve reconnection ' . $r['recon_no'] . ' and send it to the network?') ?>
          <?php endif; ?>
          <?php if ($r['status'] === 'approved' && Access::can($pk, 'edit') && $mayReconnect): ?>
            <?= IspUi::actionForm('execute', 'Send now', ['id' => $r['id']], 'btn-primary', 'send') ?>
          <?php endif; ?>
          <?php if ($r['status'] === 'failed' && Access::can($pk, 'edit') && $mayReconnect): ?>
            <?= IspUi::actionForm('retry', 'Retry', ['id' => $r['id']], 'btn-primary', 'arrow-repeat', 'Send reconnection ' . $r['recon_no'] . ' to the device again?') ?>
          <?php endif; ?>
          <?php if (in_array($r['status'], ['requested', 'pending_approval', 'approved', 'failed'], true) && Access::can($pk, 'delete')): ?>
            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelR"><i class="bi bi-x-circle"></i> Cancel</button>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($r['status'] === 'failed'): ?>
        <div class="alert alert-danger small">The service is NOT restored on the network<?= $bal['overdue'] <= 0 ? ', although the account is paid' : '' ?>. Retry the operation, or have it done by hand<?= $wo ? ' (work order ' . h($wo['wo_no']) . ')' : '' ?>.</div>
      <?php endif; ?>
      <div class="row g-3">
        <div class="col-lg-6"><?= IspUi::dl([
            'Reconnection no.' => h($r['recon_no']),
            'Reason' => h($r['reason']),
            'Requested' => h(($names[$r['requested_by']] ?? 'Scheduler') . ', ' . fdatetime($r['created_at'])),
            'Approved' => $r['approved_at'] ? h(($names[$r['approved_by']] ?? '') . ', ' . fdatetime($r['approved_at'])) : null,
            'Payment' => $pay ? safe_link('ispbill.payments', ['id' => $pay['id']], $pay['receipt_no']) . ' ' . h(money($pay['amount']) . ', ' . fdate($pay['pay_date'])) : null,
            'Temporary until' => $r['expires_at'] ? h(fdatetime($r['expires_at']) . ' (' . IspUi::ago($r['expires_at']) . ')') : null,
            'Restored' => $r['restored_at'] ? h(fdatetime($r['restored_at'])) : null,
        ]) ?></div>
        <div class="col-lg-6"><?= IspUi::dl([
            'Reconnection fee' => (float)$r['fee_amount'] > 0 ? h(money($r['fee_amount'])) . ' <span class="text-muted">' . h($r['fee_invoice'] ? 'billed' : 'on the next invoice') . ', policy: ' . h(str_replace('_', ' ', IspSetup::get('isp_recon_fee_policy'))) . '</span>' : 'None',
            'Balance now' => h(money($bal['balance'])) . ' <span class="text-muted">overdue ' . h(money($bal['overdue'])) . '</span>',
            'Account' => IspUi::status($r['service_status'], IspAccounts::SERVICE_STATUSES) . ', network ' . IspUi::status($r['network_state'], IspAccounts::NETWORK_STATES),
            'Network operation' => $r['op_no'] ? safe_link('ispnet.operations', ['id' => $r['net_op_id']], $r['op_no']) . ' ' . IspUi::status($r['op_state'], IspNetwork::STATES) . ' ' . ispnet_sim_label($r['simulated']) : null,
            'Device error' => $r['op_error'] ? h(ispnet_scrub($r['op_error'])) : null,
            'Work order' => $wo ? safe_link('ispfield.workorders', ['id' => $wo['id']], $wo['wo_no']) . ' ' . IspUi::status($wo['status'], IspWorkOrders::STATUSES) : null,
        ]) ?></div>
      </div>
    </div></div>
    <?php if (Access::can($pk, 'delete')): ?>
    <div class="modal fade" id="cancelR" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <div class="modal-header"><h5 class="modal-title">Cancel reconnection <?= h($r['recon_no']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><label class="form-label">Reason (recorded in the audit trail)</label><textarea class="form-control" name="reason" rows="2" required></textarea></div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button class="btn btn-danger">Cancel the reconnection</button></div>
    </form></div></div>
    <?php endif; ?>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ the list
$tabs = ['live' => 'In progress', 'failed' => 'Failed', 'temporary' => 'Temporary, running', 'eligible' => 'Disconnected: can they come back?', 'restored' => 'Restored', 'all' => 'All'];
$tab = (string)g('tab', 'live');
if (!isset($tabs[$tab])) {
    $tab = 'live';
}
$qtext = trim((string)g('q'));
$type = (string)g('recon_type');
$cp = [];
$bsql = IspSetup::branchSql('a.branch_id', $cp);
$sum = DB::row("SELECT SUM(r.status IN ('requested', 'pending_approval', 'approved', 'executing')) AS live, SUM(r.status = 'failed') AS failed,
                       SUM(r.status = 'restored' AND r.restored_at >= :m) AS month, SUM(r.recon_type = 'temporary' AND r.status = 'restored') AS temp
                  FROM isp_reconnections r JOIN isp_accounts a ON a.id = r.account_id WHERE 1 = 1$bsql", $cp + ['m' => date('Y-m-01') . ' 00:00:00']);

$actions = Access::can($pk, 'create') && $mayReconnect ? '<button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#manualR"><i class="bi bi-plug-fill"></i> Reconnect an account</button>' : '';
layout_start('Reconnections', ['actions' => $actions]);
echo ispnet_sim_notice();
?>
<div class="kpi-grid mb-3">
  <?= IspUi::kpi('In progress', number_format((int)$sum['live']), 'requested, approved or sent', url($pk, ['tab' => 'live']), 'hourglass-split', 'info') ?>
  <?= IspUi::kpi('Failed', number_format((int)$sum['failed']), 'service NOT restored', url($pk, ['tab' => 'failed']), 'exclamation-octagon', 'bad') ?>
  <?= IspUi::kpi('Temporary, running', number_format((int)$sum['temp']), 'disconnected again at expiry if unpaid', url($pk, ['tab' => 'temporary']), 'clock-history', 'warn') ?>
  <?= IspUi::kpi('Restored this month', number_format((int)$sum['month']), 'confirmed by the devices', url($pk, ['tab' => 'restored']), 'check2-circle', 'ok') ?>
</div>
<?= IspUi::tabs($tabs, $tab, $pk, array_filter(['q' => $qtext, 'recon_type' => $type])) ?>
<?php
[$per, $off, $page] = IspUi::paging(50);
if ($tab === 'eligible'):
    $params = [];
    $where = " WHERE a.service_status = 'disconnected'";
    if ($qtext !== '') {
        $where .= ' AND (a.account_no LIKE :q1 OR a.account_name LIKE :q2)';
        $params += ['q1' => $qtext . '%', 'q2' => '%' . $qtext . '%'];
    }
    $where .= IspSetup::branchSql('a.branch_id', $params);
    $total = (int)DB::value("SELECT COUNT(*) FROM isp_accounts a$where", $params);
    $accts = DB::all("SELECT a.id FROM isp_accounts a$where ORDER BY a.disconnected_at DESC LIMIT $per OFFSET $off", $params);
    echo IspUi::filterStart($pk, ['tab' => $tab]) . IspUi::fSearch($qtext, 'Account no. or name') . IspUi::filterEnd(url($pk, ['tab' => $tab]));
    ?>
    <div class="card isp-card"><div class="card-body">
    <p class="small text-muted">Each disconnected account is checked against the reconnection policy now: what still blocks an automatic reconnection, or nothing (it can be reconnected at once).</p>
    <?php if (!$accts): ?><?= IspUi::empty('No disconnected account.', 'plug') ?><?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
      <thead><tr><th>Account</th><th>Disconnected</th><th>Reason</th><th class="num">Overdue</th><th>Automatic reconnection</th><th class="no-print"></th></tr></thead><tbody>
      <?php foreach ($accts as $row):
          $a = IspAccounts::get((int)$row['id']);
          $b = IspReconnect::blocker($a);
          $live = DB::row('SELECT id, recon_no, status FROM isp_reconnections WHERE live_key = :k', ['k' => (string)$a['id']]);
          $bal = IspAccounts::balances((int)$a['id']); ?>
        <tr>
          <td><?= IspUi::acct($a['id'], $a['account_no'], $a['account_name']) ?></td>
          <td class="small text-nowrap"><?= h(fdatetime($a['disconnected_at'])) ?></td>
          <td class="small"><?= h($a['disconnect_reason']) ?></td>
          <td class="num"><?= h(money($bal['overdue'])) ?></td>
          <td class="small"><?php if ($live): ?><a href="<?= h(url($pk, ['id' => $live['id']])) ?>"><?= h($live['recon_no']) ?></a> <?= IspUi::status($live['status'], IspReconnect::STATUSES) ?>
            <?php elseif ($b === null): ?><span class="text-success">Qualifies now</span><?php else: ?><span class="text-muted"><?= h(ucfirst($b)) ?></span><?php endif; ?></td>
          <td class="text-end no-print text-nowrap">
            <?php if (!$live && $b === null && Access::can($pk, 'create')): ?><?= IspUi::actionForm('auto', 'Reconnect', ['account_id' => $a['id']], 'btn-outline-primary', 'plug-fill', 'Reconnect ' . $a['account_no'] . ' now?') ?><?php endif; ?>
            <?php if (!$live && $b !== null && Access::can($pk, 'create') && $mayReconnect): ?><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#manualR" data-account="<?= (int)$a['id'] ?>" data-account-text="<?= h($a['account_no'] . ' - ' . $a['account_name']) ?>">By authority</button><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?= IspUi::pager($total, $per, $page) ?>
    <?php endif; ?>
    </div></div>
<?php else:
    $params = [];
    $where = ' WHERE 1 = 1' . match ($tab) {
        'live' => " AND r.status IN ('requested', 'pending_approval', 'approved', 'executing')",
        'failed' => " AND r.status = 'failed'",
        'temporary' => " AND r.recon_type = 'temporary' AND r.status = 'restored'",
        'restored' => " AND r.status = 'restored'",
        default => '',
    };
    if ($qtext !== '') {
        $where .= ' AND (r.recon_no LIKE :q1 OR a.account_no LIKE :q2 OR a.account_name LIKE :q3)';
        $params += ['q1' => $qtext . '%', 'q2' => $qtext . '%', 'q3' => '%' . $qtext . '%'];
    }
    if (isset(IspReconnect::TYPES[$type])) {
        $where .= ' AND r.recon_type = :t';
        $params['t'] = $type;
    }
    $where .= IspSetup::branchSql('a.branch_id', $params);
    $total = (int)DB::value("SELECT COUNT(*) FROM isp_reconnections r JOIN isp_accounts a ON a.id = r.account_id$where", $params);
    $rows = DB::all("SELECT r.*, a.account_no, a.account_name, o.state AS op_state, o.simulated FROM isp_reconnections r JOIN isp_accounts a ON a.id = r.account_id
                      LEFT JOIN isp_net_ops o ON o.id = r.net_op_id$where ORDER BY r.id DESC LIMIT $per OFFSET $off", $params);
    echo IspUi::filterStart($pk, ['tab' => $tab]) . IspUi::fSearch($qtext, 'Reconnection no., account no. or name') . IspUi::fSelect('recon_type', 'Kind', IspReconnect::TYPES, $type) . IspUi::filterEnd(url($pk, ['tab' => $tab]));
    ?>
    <div class="card isp-card"><div class="card-body">
    <?php if (!$rows): ?><?= IspUi::empty('Nothing in this list.', 'plug-fill') ?><?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
      <thead><tr><th>Reconnection</th><th>Account</th><th>Kind</th><th>Status</th><th class="num">Fee</th><th>Reason</th><th>Requested</th><th class="no-print"></th></tr></thead><tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><a href="<?= h(url($pk, ['id' => $r['id']])) ?>"><?= h($r['recon_no']) ?></a></td>
          <td><?= IspUi::acct($r['account_id'], $r['account_no'], $r['account_name']) ?></td>
          <td class="small"><?= h(IspReconnect::TYPES[$r['recon_type']] ?? $r['recon_type']) ?><?= $r['expires_at'] ? '<div class="text-muted">until ' . h(fdatetime($r['expires_at'])) . '</div>' : '' ?></td>
          <td><?= IspUi::status($r['status'], IspReconnect::STATUSES) ?><?php if ($r['op_state']): ?><div class="small"><?= ispnet_sim_label($r['simulated']) ?></div><?php endif; ?></td>
          <td class="num"><?= h(money($r['fee_amount'], true)) ?></td>
          <td class="small"><?= h(mb_strimwidth((string)$r['reason'], 0, 80, '...')) ?></td>
          <td class="small text-nowrap"><?= h(fdatetime($r['created_at'])) ?></td>
          <td class="text-end no-print text-nowrap">
            <?php if ($r['status'] === 'failed' && Access::can($pk, 'edit') && $mayReconnect): ?><?= IspUi::actionForm('retry', 'Retry', ['id' => $r['id']], 'btn-outline-primary', 'arrow-repeat') ?><?php endif; ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pk, ['id' => $r['id']])) ?>">Open</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?= IspUi::pager($total, $per, $page) ?>
    <?php endif; ?>
    </div></div>
<?php endif; ?>

<?php if (Access::can($pk, 'create') && $mayReconnect): ?>
<div class="modal fade" id="manualR" tabindex="-1"><div class="modal-dialog modal-lg"><form class="modal-content" method="post">
  <?= csrf_field() ?><input type="hidden" name="action" value="manual">
  <div class="modal-header"><h5 class="modal-title">Reconnect an account by authority</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-2">
    <?= IspUi::accountField('account_id', gint('account_id'), 'Disconnected account', 'col-md-7') ?>
    <?= select_field('recon_type', 'Kind', array_diff_key(IspReconnect::TYPES, ['automatic' => 1]), 'manual', ['class' => 'col-md-5']) ?>
    <div class="col-md-5"><label class="form-label">Temporary: until</label><input class="form-control form-control-sm" type="datetime-local" name="expires_at" value="<?= h(date('Y-m-d\TH:i', time() + IspSetup::int('isp_temp_recon_hours') * 3600)) ?>"></div>
    <div class="col-md-7 d-flex align-items-end"><p class="small text-muted mb-0">Used only by a temporary reconnection (default <?= (int)IspSetup::int('isp_temp_recon_hours') ?> hours). If the arrears are still unpaid at expiry, the account is disconnected again.</p></div>
    <div class="col-12"><label class="form-label">Reason (recorded with the reconnection and in the account history)</label><textarea class="form-control form-control-sm" name="reason" rows="2" required></textarea></div>
  </div>
  <p class="small text-muted mt-2 mb-0">Emergency restoration charges no reconnection fee. The account shows Active only when the device confirms.</p></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button class="btn btn-primary"><i class="bi bi-plug-fill"></i> Reconnect</button></div>
</form></div></div>
<script>
(function () {
  var m = document.getElementById('manualR');
  if (!m) return;
  m.addEventListener('show.bs.modal', function (e) {
    var b = e.relatedTarget;
    if (!b || !b.getAttribute('data-account')) return;
    m.querySelector('input[type=hidden][name=account_id]').value = b.getAttribute('data-account');
    m.querySelector('input[name=account_id_text]').value = b.getAttribute('data-account-text');
  });
})();
</script>
<?php endif; ?>
<?php
layout_end();
