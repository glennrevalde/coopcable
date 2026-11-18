<?php
declare(strict_types=1);

/**
 * Disconnected Accounts & Recovery: the subscribers whose service is cut, how long ago and why, what they owe, the
 * collection follow-ups, whether they can come back, the company equipment still at their place and its recovery,
 * the security deposit, the final settlement and the permanent termination.
 */
require_once __DIR__ . '/_common.php';

$pk = 'ispnet.recovery';
const ISPNET_FOLLOWUPS = ['call' => 'Phone call', 'sms' => 'SMS sent', 'visit' => 'Field visit', 'letter' => 'Demand letter', 'promise' => 'Promise to pay', 'refused' => 'Refused to pay', 'unreachable' => 'Could not be reached', 'other' => 'Other'];

/** The company-owned units still at the subscriber */
function ispnet_units_out(int $accountId): array
{
    return DB::all("SELECT e.*, i.code AS item_code, i.name AS item_name FROM isp_equipment e JOIN items i ON i.id = e.item_id
                     WHERE e.account_id = :a AND e.ownership = 'company' AND e.status IN ('installed', 'for_recovery') ORDER BY e.id", ['a' => $accountId]);
}

handle_post(function () use ($pk) {
    $action = (string)p('action');
    $acctId = (int)p('account_id');
    if ($acctId && !ispnet_account_visible($acctId)) {
        fail('That account belongs to another branch.');
    }
    switch ($action) {
        case 'note':
            Access::require($pk, 'edit');
            $a = IspAccounts::get($acctId) ?? fail('Account not found.');
            $kind = (string)p('kind');
            $note = trim((string)p('note'));
            if (!isset(ISPNET_FOLLOWUPS[$kind]) || $note === '') {
                fail('Choose what was done and write the note.');
            }
            $promise = pdate('promise_date');
            IspAccounts::event($acctId, 'collection', 'Collection follow-up (' . ISPNET_FOLLOWUPS[$kind] . '): ' . $note . ($promise ? '; promised to pay on ' . fdate($promise) : ''), 'collection', null,
                ($amt = pnum('promise_amount')) > 0 ? $amt : null);
            Audit::log('note', 'ispnet', 'isp_account', $acctId, (string)$a['account_no'], ['page_key' => $pk, 'reason' => ISPNET_FOLLOWUPS[$kind] . ': ' . $note]);
            redirect(current_url(), 'Follow-up recorded on ' . $a['account_no'] . '.', 'success');
        case 'recovery_wo':
            Access::require($pk, 'create');
            $a = IspAccounts::get($acctId) ?? fail('Account not found.');
            $units = array_values(array_filter(ispnet_units_out($acctId), fn($e) => !$e['work_order_id'] || !in_array(DB::value('SELECT status FROM isp_work_orders WHERE id = :w', ['w' => $e['work_order_id']]), ['open', 'scheduled', 'accepted', 'in_progress'], true)));
            if (!$units) {
                fail('Account ' . $a['account_no'] . ' has no company equipment waiting for a recovery work order.');
            }
            $wo = DB::tx(function () use ($a, $units, $acctId) {
                $wo = IspWorkOrders::create(['wo_type' => 'recovery', 'account_id' => $acctId, 'priority' => (string)p('priority', 'normal'), 'address' => $a['install_address'], 'area_id' => $a['area_id'],
                    'description' => 'Recover ' . implode(', ', array_map(fn($e) => $e['item_name'] . ' ' . $e['serial_no'], $units)) . ' from a disconnected subscriber']);
                foreach ($units as $e) {
                    IspEquipment::move((int)$e['id'], 'for_recovery', 'Recovery work order', $acctId, $wo);
                }
                return $wo;
            });
            redirect(current_url(), 'Recovery work order ' . DB::value('SELECT wo_no FROM isp_work_orders WHERE id = :w', ['w' => $wo]) . ' created for ' . count($units) . ' unit(s).', 'success');
        case 'unit':
            Access::require($pk, 'edit');
            $e = IspEquipment::get((int)p('equipment_id')) ?? fail('Equipment not found.');
            if (!ispnet_account_visible((int)$e['account_id'])) {
                fail('That equipment is at an account of another branch.');
            }
            $to = (string)p('to');
            if (!in_array($to, ['recovered', 'damaged', 'missing'], true)) {
                fail('Choose what happened to the unit.');
            }
            if (!in_array($e['status'], ['installed', 'for_recovery'], true)) {
                fail('Unit ' . $e['serial_no'] . ' is ' . (IspEquipment::STATUSES[$e['status']] ?? $e['status']) . '.');
            }
            $remarks = trim((string)p('remarks'));
            $charge = pnum('charge');
            if ($charge > 0 && !Access::can($pk, 'approve')) {
                fail('Charging the subscriber for the equipment needs the approve right of this page.');
            }
            DB::tx(function () use ($e, $to, $remarks, $charge) {
                IspEquipment::move((int)$e['id'], $to, $remarks !== '' ? $remarks : (IspEquipment::STATUSES[$to] ?? $to), (int)$e['account_id'] ?: null, null,
                    $to !== 'recovered' ? ['disposition' => mb_substr($remarks ?: $to, 0, 100)] : []);
                if ($charge > 0 && $e['account_id']) {
                    $a = IspAccounts::get((int)$e['account_id']);
                    $plan = IspPlans::get((int)$a['plan_id']);
                    IspBilling::addCharge((int)$e['account_id'], 'equipment', ($to === 'missing' ? 'Unreturned equipment ' : 'Damaged equipment ') . $e['item_name'] . ' ' . $e['serial_no'], $charge, today(),
                        'EQ-' . $to . '-' . $e['id'], $plan['vat_mode'], null, 'equipment', (int)$e['id']);
                }
            });
            redirect(current_url(), 'Unit ' . $e['serial_no'] . ': ' . IspEquipment::STATUSES[$to] . ($charge > 0 ? ', ' . money($charge) . ' charged on the next invoice' : '') . '.', 'success');
        case 'deposit':
            Access::require($pk, 'approve');
            $a = IspAccounts::get($acctId) ?? fail('Account not found.');
            [$applied, $left] = IspBilling::applyDeposit($acctId, 'Security deposit applied to the balance of a disconnected account');
            if ($applied <= 0) {
                fail('Account ' . $a['account_no'] . ' has no deposit to apply, or nothing open to apply it to.');
            }
            redirect(current_url(), money($applied) . ' of the security deposit applied to ' . $a['account_no'] . ($left > 0 ? '; ' . money($left) . ' remains refundable' : '') . '.', 'success');
        case 'terminate':
            Access::require($pk, 'approve');
            $a = IspAccounts::get($acctId) ?? fail('Account not found.');
            $date = pdate('end_date', today());
            $out = IspAccounts::terminate($acctId, $date, trim((string)p('reason')), p('charge_etf') === '1', $pk);
            $msg = 'Account ' . $a['account_no'] . ' terminated.';
            if (!empty($out['final_invoice_id'])) {
                $msg .= ' Final invoice ' . DB::value('SELECT invoice_no FROM isp_invoices WHERE id = :i', ['i' => $out['final_invoice_id']]) . ' issued.';
            }
            if (!empty($out['recovery_wo_id'])) {
                $msg .= ' Recovery work order ' . DB::value('SELECT wo_no FROM isp_work_orders WHERE id = :w', ['w' => $out['recovery_wo_id']]) . ' created.';
            }
            redirect(url($pk, ['tab' => 'terminated']), $msg, 'success');
        default:
            fail('Unknown action.');
    }
});

$tabs = ['disconnected' => 'Disconnected', 'terminated' => 'Terminated, to settle', 'equipment' => 'Equipment to recover', 'history' => 'Recovery history'];
$tab = (string)g('tab', 'disconnected');
if (!isset($tabs[$tab])) {
    $tab = 'disconnected';
}
$qtext = trim((string)g('q'));
$age = (string)g('age');
$areaId = gint('area_id');
$areas = DB::pairs("SELECT id, name FROM isp_areas WHERE is_active = 1 AND area_type IN ('municipality', 'barangay', 'zone') ORDER BY name");

$cp = [];
$bsql = IspSetup::branchSql('a.branch_id', $cp);
$sum = DB::row("SELECT SUM(a.service_status = 'disconnected') AS disc,
                       SUM(a.service_status = 'disconnected' AND a.disconnected_at < :d60) AS old60,
                       (SELECT COALESCE(SUM(i.balance), 0) FROM isp_invoices i JOIN isp_accounts b ON b.id = i.account_id WHERE b.service_status IN ('disconnected', 'terminated')
                          AND i.status IN ('posted', 'partially_paid', 'overdue', 'disputed')" . str_replace('a.branch_id', 'b.branch_id', $bsql) . ") AS owed
                  FROM isp_accounts a WHERE 1 = 1$bsql", $cp + ['d60' => date('Y-m-d H:i:s', strtotime('-60 days'))]);
$eqCount = (int)DB::value("SELECT COUNT(*) FROM isp_equipment e JOIN isp_accounts a ON a.id = e.account_id WHERE e.ownership = 'company' AND (e.status = 'for_recovery'
                            OR (e.status = 'installed' AND a.service_status IN ('disconnected', 'terminated')))$bsql", $cp);

layout_start('Disconnected Accounts & Recovery');
echo ispnet_sim_notice();
?>
<div class="kpi-grid mb-3">
  <?= IspUi::kpi('Disconnected', number_format((int)$sum['disc']), 'accounts with the service cut', url($pk, ['tab' => 'disconnected']), 'plug', 'bad') ?>
  <?= IspUi::kpi('Over 60 days', number_format((int)$sum['old60']), 'candidates for termination', url($pk, ['tab' => 'disconnected', 'age' => '60']), 'calendar-x', 'warn') ?>
  <?= IspUi::kpi('Owed', money($sum['owed']), 'disconnected and terminated accounts', null, 'cash-stack', 'warn') ?>
  <?= IspUi::kpi('Equipment out', number_format($eqCount), 'company units to recover', url($pk, ['tab' => 'equipment']), 'router', 'info') ?>
</div>
<?= IspUi::tabs($tabs, $tab, $pk, array_filter(['q' => $qtext, 'area_id' => $areaId])) ?>
<?php
[$per, $off, $page] = IspUi::paging(40);

if ($tab === 'disconnected' || $tab === 'terminated'):
    $params = [];
    $where = $tab === 'disconnected' ? " WHERE a.service_status = 'disconnected'"
        : " WHERE a.service_status = 'terminated' AND (EXISTS (SELECT 1 FROM isp_invoices i WHERE i.account_id = a.id AND i.status IN ('posted', 'partially_paid', 'overdue', 'disputed') AND i.balance > 0)
             OR EXISTS (SELECT 1 FROM isp_equipment e WHERE e.account_id = a.id AND e.ownership = 'company' AND e.status IN ('installed', 'for_recovery'))
             OR (a.security_deposit > 0 AND a.deposit_status = 'held'))";
    if ($qtext !== '') {
        $where .= ' AND (a.account_no LIKE :q1 OR a.account_name LIKE :q2)';
        $params += ['q1' => $qtext . '%', 'q2' => '%' . $qtext . '%'];
    }
    if (in_array($age, ['30', '60', '90', '180'], true)) {
        $where .= ' AND COALESCE(a.disconnected_at, a.terminated_at) < :age';
        $params['age'] = date('Y-m-d H:i:s', strtotime('-' . $age . ' days'));
    }
    if ($areaId) {
        $where .= ' AND a.area_id = :ar';
        $params['ar'] = $areaId;
    }
    $where .= IspSetup::branchSql('a.branch_id', $params);
    $total = (int)DB::value("SELECT COUNT(*) FROM isp_accounts a$where", $params);
    $rows = DB::all("SELECT a.*, c.mobile,
            (SELECT COALESCE(SUM(i.balance), 0) FROM isp_invoices i WHERE i.account_id = a.id AND i.status IN ('posted', 'partially_paid', 'overdue', 'disputed')) AS owed,
            (SELECT COUNT(*) FROM isp_equipment e WHERE e.account_id = a.id AND e.ownership = 'company' AND e.status IN ('installed', 'for_recovery')) AS units,
            (SELECT e2.summary FROM isp_account_events e2 WHERE e2.account_id = a.id AND e2.event_type = 'collection' ORDER BY e2.id DESC LIMIT 1) AS last_note,
            (SELECT e3.event_date FROM isp_account_events e3 WHERE e3.account_id = a.id AND e3.event_type = 'collection' ORDER BY e3.id DESC LIMIT 1) AS last_note_at,
            (SELECT COUNT(*) FROM isp_account_events e4 WHERE e4.account_id = a.id AND e4.event_type = 'collection') AS attempts
          FROM isp_accounts a JOIN isp_customers c ON c.id = a.isp_customer_id$where ORDER BY COALESCE(a.disconnected_at, a.terminated_at) LIMIT $per OFFSET $off", $params);
    echo IspUi::filterStart($pk, ['tab' => $tab]) . IspUi::fSearch($qtext, 'Account no. or name') . IspUi::fSelect('age', 'Disconnected for', ['30' => 'Over 30 days', '60' => 'Over 60 days', '90' => 'Over 90 days', '180' => 'Over 180 days'], $age, 'Any time')
        . IspUi::fSelect('area_id', 'Area', $areas, $areaId) . IspUi::filterEnd(url($pk, ['tab' => $tab]));
    ?>
    <div class="card isp-card"><div class="card-body">
    <?php if (!$rows): ?><?= IspUi::empty($tab === 'disconnected' ? 'No disconnected account.' : 'No terminated account left to settle.', 'check2-circle') ?><?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
      <thead><tr><th>Account</th><th><?= $tab === 'disconnected' ? 'Disconnected' : 'Terminated' ?></th><th class="num">Days</th><th>Reason</th><th class="num">Owed</th><th class="num">Deposit</th><th class="num">Units out</th><th>Collection</th><th><?= $tab === 'disconnected' ? 'Can come back' : 'Network' ?></th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $a):
          $when = $tab === 'disconnected' ? $a['disconnected_at'] : $a['terminated_at'];
          $days = $when ? (int)floor((time() - strtotime((string)$when)) / 86400) : null;
          $blocker = $tab === 'disconnected' ? IspReconnect::blocker(IspAccounts::get((int)$a['id'])) : null; ?>
        <tr>
          <td><?= IspUi::acct($a['id'], $a['account_no'], $a['account_name']) ?><div class="small text-muted"><?= IspUi::sensitive($a['mobile']) ?></div></td>
          <td class="small text-nowrap"><?= h(fdate($when)) ?></td>
          <td class="num"><?= $days !== null ? number_format($days) : '' ?></td>
          <td class="small"><?= h(mb_strimwidth((string)($tab === 'disconnected' ? $a['disconnect_reason'] : $a['termination_reason']), 0, 70, '...')) ?></td>
          <td class="num"><?= h(money($a['owed'])) ?></td>
          <td class="num small"><?= (float)$a['security_deposit'] > 0 ? h(money($a['security_deposit'])) . '<div class="text-muted">' . h($a['deposit_status']) . '</div>' : '' ?></td>
          <td class="num"><?= (int)$a['units'] ?: '' ?></td>
          <td class="small"><?php if ($a['last_note']): ?><?= h(mb_strimwidth((string)$a['last_note'], 0, 70, '...')) ?><div class="text-muted"><?= (int)$a['attempts'] ?> follow-up(s), last <?= h(fdate($a['last_note_at'])) ?></div><?php else: ?><span class="text-muted">No follow-up yet</span><?php endif; ?></td>
          <td class="small"><?php if ($tab === 'disconnected'): ?><?= $blocker === null ? '<span class="text-success">Yes, once sent</span>' : '<span class="text-muted">' . h(ucfirst($blocker)) . '</span>' ?><?php else: ?><?= IspUi::status($a['network_state'], IspAccounts::NETWORK_STATES) ?><?php endif; ?></td>
          <td class="text-end no-print text-nowrap">
            <div class="dropdown">
              <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">Actions</button>
              <div class="dropdown-menu dropdown-menu-end">
                <?php if (Access::can($pk, 'edit')): ?><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#noteM" data-account="<?= (int)$a['id'] ?>" data-label="<?= h($a['account_no'] . ' ' . $a['account_name']) ?>"><i class="bi bi-chat-left-text"></i> Record a follow-up</button><?php endif; ?>
                <?php if ((int)$a['units'] && Access::can($pk, 'create')): ?>
                  <form method="post" data-confirm="Create a recovery work order for the equipment of <?= h($a['account_no']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="recovery_wo"><input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
                    <button class="dropdown-item"><i class="bi bi-truck"></i> Recovery work order</button></form>
                <?php endif; ?>
                <?php if ((float)$a['security_deposit'] > 0 && in_array($a['deposit_status'], ['held', 'billed'], true) && (float)$a['owed'] > 0 && Access::can($pk, 'approve')): ?>
                  <form method="post" data-confirm="Apply the security deposit of <?= h($a['account_no']) ?> to its open invoices?"><?= csrf_field() ?><input type="hidden" name="action" value="deposit"><input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
                    <button class="dropdown-item"><i class="bi bi-safe"></i> Apply the deposit</button></form>
                <?php endif; ?>
                <?php if ($tab === 'disconnected' && Access::can($pk, 'approve')): ?><button class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#termM" data-account="<?= (int)$a['id'] ?>" data-label="<?= h($a['account_no'] . ' ' . $a['account_name']) ?>"><i class="bi bi-x-octagon"></i> Terminate</button><?php endif; ?>
                <?php if (Access::page('ispbill.soa')): ?><a class="dropdown-item" href="<?= h(url('ispbill.soa', ['account_id' => $a['id']])) ?>"><i class="bi bi-file-earmark-text"></i> Statement of account</a><?php endif; ?>
                <?php if ($tab === 'disconnected' && Access::page('ispnet.reconnections')): ?><a class="dropdown-item" href="<?= h(url('ispnet.reconnections', ['tab' => 'eligible', 'q' => $a['account_no']])) ?>"><i class="bi bi-plug-fill"></i> Reconnection</a><?php endif; ?>
              </div>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?= IspUi::pager($total, $per, $page) ?>
    <?php endif; ?>
    </div></div>
<?php elseif ($tab === 'equipment'):
    $params = [];
    $where = " WHERE e.ownership = 'company' AND (e.status = 'for_recovery' OR (e.status = 'installed' AND a.service_status IN ('disconnected', 'terminated')))";
    if ($qtext !== '') {
        $where .= ' AND (a.account_no LIKE :q1 OR a.account_name LIKE :q2 OR e.serial_no LIKE :q3)';
        $params += ['q1' => $qtext . '%', 'q2' => '%' . $qtext . '%', 'q3' => $qtext . '%'];
    }
    if ($areaId) {
        $where .= ' AND a.area_id = :ar';
        $params['ar'] = $areaId;
    }
    $where .= IspSetup::branchSql('a.branch_id', $params);
    $total = (int)DB::value("SELECT COUNT(*) FROM isp_equipment e JOIN isp_accounts a ON a.id = e.account_id$where", $params);
    $rows = DB::all("SELECT e.*, i.code AS item_code, i.name AS item_name, a.account_no, a.account_name, a.service_status, a.install_address, w.wo_no, w.status AS wo_status, w.technician_id
                       FROM isp_equipment e JOIN items i ON i.id = e.item_id JOIN isp_accounts a ON a.id = e.account_id LEFT JOIN isp_work_orders w ON w.id = e.work_order_id$where
                      ORDER BY e.updated_at LIMIT $per OFFSET $off", $params);
    echo IspUi::filterStart($pk, ['tab' => $tab]) . IspUi::fSearch($qtext, 'Account, name or serial no.') . IspUi::fSelect('area_id', 'Area', $areas, $areaId) . IspUi::filterEnd(url($pk, ['tab' => $tab]));
    ?>
    <div class="card isp-card"><div class="card-body">
    <?php if (!$rows): ?><?= IspUi::empty('No company equipment is waiting to be recovered.', 'router') ?><?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
      <thead><tr><th>Unit</th><th>Serial no.</th><th>Account</th><th>Address</th><th>Status</th><th>Work order</th><th class="num">Value</th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $e): ?>
        <tr>
          <td><?= h($e['item_code'] . ' ' . $e['item_name']) ?></td>
          <td><code><?= h($e['serial_no']) ?></code></td>
          <td><?= IspUi::acct($e['account_id'], $e['account_no'], $e['account_name']) ?><div class="small"><?= IspUi::status($e['service_status'], IspAccounts::SERVICE_STATUSES) ?></div></td>
          <td class="small"><?= IspUi::sensitive($e['install_address']) ?></td>
          <td><?= IspUi::status($e['status'], IspEquipment::STATUSES) ?></td>
          <td class="small"><?= $e['wo_no'] ? safe_link('ispfield.workorders', ['id' => $e['work_order_id']], $e['wo_no']) . ' ' . IspUi::status($e['wo_status'], IspWorkOrders::STATUSES) : '<span class="text-muted">None yet</span>' ?></td>
          <td class="num"><?= Access::viewCosts() ? h(money($e['unit_cost'])) : '' ?></td>
          <td class="text-end no-print text-nowrap">
            <?php if (!$e['wo_no'] && Access::can($pk, 'create')): ?>
              <?= IspUi::actionForm('recovery_wo', 'Work order', ['account_id' => $e['account_id']], 'btn-outline-primary', 'truck') ?>
            <?php endif; ?>
            <?php if (Access::can($pk, 'edit')): ?>
              <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#unitM" data-unit="<?= (int)$e['id'] ?>" data-label="<?= h($e['item_name'] . ' ' . $e['serial_no'] . ' at ' . $e['account_no']) ?>" data-cost="<?= h(number_format((float)$e['unit_cost'], 2, '.', '')) ?>">Record result</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?= IspUi::pager($total, $per, $page) ?>
    <?php endif; ?>
    </div></div>
<?php else:
    $params = ['f' => date('Y-m-d H:i:s', strtotime('-180 days'))];
    $where = " WHERE l.to_status IN ('recovered', 'damaged', 'missing', 'for_recovery') AND l.created_at >= :f";
    if ($qtext !== '') {
        $where .= ' AND (a.account_no LIKE :q1 OR e.serial_no LIKE :q2)';
        $params += ['q1' => $qtext . '%', 'q2' => $qtext . '%'];
    }
    $where .= IspSetup::branchSql('a.branch_id', $params);
    $total = (int)DB::value("SELECT COUNT(*) FROM isp_equipment_log l JOIN isp_equipment e ON e.id = l.equipment_id LEFT JOIN isp_accounts a ON a.id = l.account_id$where", $params);
    $rows = DB::all("SELECT l.*, e.serial_no, i.name AS item_name, a.account_no, a.account_name, u.full_name FROM isp_equipment_log l JOIN isp_equipment e ON e.id = l.equipment_id
                       JOIN items i ON i.id = e.item_id LEFT JOIN isp_accounts a ON a.id = l.account_id LEFT JOIN users u ON u.id = l.user_id$where ORDER BY l.id DESC LIMIT $per OFFSET $off", $params);
    echo IspUi::filterStart($pk, ['tab' => $tab]) . IspUi::fSearch($qtext, 'Account or serial no.') . IspUi::filterEnd(url($pk, ['tab' => $tab]));
    ?>
    <div class="card isp-card"><div class="card-body">
    <p class="small text-muted">The last 180 days of equipment flagged for recovery, recovered, found damaged or missing.</p>
    <?php if (!$rows): ?><?= IspUi::empty('Nothing recorded in the last 180 days.') ?><?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
      <thead><tr><th>When</th><th>Unit</th><th>Account</th><th>From</th><th>To</th><th>Remarks</th><th>By</th></tr></thead><tbody>
      <?php foreach ($rows as $l): ?>
        <tr><td class="small text-nowrap"><?= h(fdatetime($l['created_at'])) ?></td><td><?= h($l['item_name']) ?> <code><?= h($l['serial_no']) ?></code></td>
          <td><?= $l['account_id'] ? IspUi::acct($l['account_id'], $l['account_no'], $l['account_name']) : '' ?></td>
          <td class="small"><?= h(IspEquipment::STATUSES[$l['from_status']] ?? (string)$l['from_status']) ?></td><td><?= IspUi::status($l['to_status'], IspEquipment::STATUSES) ?></td>
          <td class="small"><?= h($l['remarks']) ?></td><td class="small"><?= h($l['full_name']) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?= IspUi::pager($total, $per, $page) ?>
    <?php endif; ?>
    </div></div>
<?php endif; ?>

<?php if (Access::can($pk, 'edit')): ?>
<div class="modal fade" id="noteM" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post">
  <?= csrf_field() ?><input type="hidden" name="action" value="note"><input type="hidden" name="account_id" value="">
  <div class="modal-header"><h5 class="modal-title">Collection follow-up <span class="text-muted small" data-label></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-2">
    <?= select_field('kind', 'What was done', ISPNET_FOLLOWUPS, 'call', ['class' => 'col-md-6']) ?>
    <?= input_field('promise_date', 'Promised to pay on', '', ['type' => 'date', 'class' => 'col-md-3']) ?>
    <?= input_field('promise_amount', 'Amount promised', '', ['type' => 'number', 'class' => 'col-md-3', 'extra' => 'min="0"']) ?>
    <div class="col-12"><label class="form-label">Note</label><textarea class="form-control form-control-sm" name="note" rows="3" required></textarea></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button class="btn btn-primary">Record</button></div>
</form></div></div>
<div class="modal fade" id="unitM" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post">
  <?= csrf_field() ?><input type="hidden" name="action" value="unit"><input type="hidden" name="equipment_id" value="">
  <div class="modal-header"><h5 class="modal-title">Recovery result <span class="text-muted small" data-label></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-2">
    <?= select_field('to', 'Result', ['recovered' => 'Recovered in good condition', 'damaged' => 'Recovered damaged', 'missing' => 'Not returned / missing'], 'recovered', ['class' => 'col-md-7']) ?>
    <?= input_field('charge', 'Charge the subscriber', '', ['type' => 'number', 'class' => 'col-md-5', 'extra' => 'min="0" data-cost' . (Access::can($pk, 'approve') ? '' : ' disabled')]) ?>
    <div class="col-12"><label class="form-label">Remarks (condition, who returned it)</label><textarea class="form-control form-control-sm" name="remarks" rows="2"></textarea></div>
  </div><p class="small text-muted mt-2 mb-0">A charge is billed on the subscriber's next invoice<?= Access::can($pk, 'approve') ? '' : ' (charging needs the approve right of this page)' ?>. Recovered units go back to the stockroom from Equipment.</p></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button class="btn btn-primary">Record</button></div>
</form></div></div>
<?php endif; ?>
<?php if (Access::can($pk, 'approve')): ?>
<div class="modal fade" id="termM" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post">
  <?= csrf_field() ?><input type="hidden" name="action" value="terminate"><input type="hidden" name="account_id" value="">
  <div class="modal-header"><h5 class="modal-title">Terminate permanently <span class="text-muted small" data-label></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-2">
    <?= input_field('end_date', 'Service end date', today(), ['type' => 'date', 'class' => 'col-md-5', 'required' => true]) ?>
    <?= check_field('charge_etf', 'Charge the early termination fee (inside the lock-in)', true, 'col-md-7') ?>
    <div class="col-12"><label class="form-label">Reason (recorded in the audit trail)</label><textarea class="form-control form-control-sm" name="reason" rows="2" required></textarea></div>
  </div><p class="small text-muted mt-2 mb-0">The final invoice is issued, the subscriber is removed from the network, company equipment gets a recovery work order and the security deposit is applied to the final balance. The history and the balance stay on record.</p></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button class="btn btn-danger">Terminate</button></div>
</form></div></div>
<?php endif; ?>
<script>
(function () {
  [['noteM', 'account', 'account_id'], ['termM', 'account', 'account_id'], ['unitM', 'unit', 'equipment_id']].forEach(function (c) {
    var m = document.getElementById(c[0]);
    if (!m) return;
    m.addEventListener('show.bs.modal', function (e) {
      var b = e.relatedTarget;
      if (!b) return;
      m.querySelector('input[name=' + c[2] + ']').value = b.getAttribute('data-' + c[1]) || '';
      var l = m.querySelector('[data-label]');
      if (l) l.textContent = b.getAttribute('data-label') || '';
      var cost = m.querySelector('[data-cost]');
      if (cost) { cost.value = ''; cost.placeholder = b.getAttribute('data-cost') || ''; }
    });
  });
})();
</script>
<?php
layout_end();
