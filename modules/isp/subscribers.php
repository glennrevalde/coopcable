<?php
declare(strict_types=1);

/** Subscriber Center: every subscription account with its plan, service and network status and its balance */
$pk = 'isp.subscribers';

$q = trim((string)g('q'));
$status = (string)g('status');
$net = (string)g('net');
$planId = gint('plan_id');
$areaId = gint('area_id');
$branchId = gint('branch_id');
$money = (string)g('money');
$class = (string)g('class');

$params = [];
$where = ' WHERE 1 = 1' . IspSetup::branchSql('a.branch_id', $params);
if ($q !== '') {
    $where .= ' AND (a.account_no LIKE :q1 OR a.account_name LIKE :q2 OR c.name LIKE :q3 OR c.customer_no LIKE :q4 OR c.mobile LIKE :q5 OR a.pppoe_username LIKE :q6 OR a.install_address LIKE :q7)';
    $params += ['q1' => $q . '%', 'q2' => '%' . $q . '%', 'q3' => '%' . $q . '%', 'q4' => $q . '%', 'q5' => '%' . $q . '%', 'q6' => $q . '%', 'q7' => '%' . $q . '%'];
}
if (isset(IspAccounts::SERVICE_STATUSES[$status])) {
    $where .= ' AND a.service_status = :st';
    $params['st'] = $status;
}
if (isset(IspAccounts::NETWORK_STATES[$net])) {
    $where .= ' AND a.network_state = :ns';
    $params['ns'] = $net;
}
if ($planId) {
    $where .= ' AND a.plan_id = :pl';
    $params['pl'] = $planId;
}
if ($areaId) {
    // the area and the areas inside it
    $tree = IspOutages::areaTree($areaId);
    [$in, $inP] = DB::in('ar', $tree ?: [$areaId]);
    $where .= " AND a.area_id IN $in";
    $params += $inP;
}
if ($branchId) {
    $where .= ' AND a.branch_id = :br';
    $params['br'] = $branchId;
}
if (isset(IspAccounts::CLASSIFICATIONS[$class])) {
    $where .= ' AND c.classification = :cl';
    $params['cl'] = $class;
}
$openSql = "SELECT COALESCE(SUM(i.balance), 0) FROM isp_invoices i WHERE i.account_id = a.id AND i.status IN ('posted','partially_paid','overdue','disputed')";
$overSql = "SELECT COALESCE(SUM(i.balance), 0) FROM isp_invoices i WHERE i.account_id = a.id AND i.status IN ('posted','partially_paid','overdue','disputed') AND i.due_date < :today";
if ($money === 'balance') {
    $where .= " AND ($openSql) > 0";
} elseif ($money === 'overdue') {
    $where .= " AND ($overSql) > 0";
    $params['today'] = today();
} elseif ($money === 'credit') {
    $where .= " AND (SELECT COALESCE(SUM(y.unapplied_amount), 0) FROM isp_payments y WHERE y.account_id = a.id AND y.status = 'posted') > 0";
}

$from = ' FROM isp_accounts a JOIN isp_customers c ON c.id = a.isp_customer_id LEFT JOIN isp_plans p ON p.id = a.plan_id LEFT JOIN isp_areas ar ON ar.id = a.area_id';
[$per, $off, $page] = IspUi::paging(50);
$total = (int)DB::value('SELECT COUNT(*)' . $from . $where, $params);
$listParams = $params;
$listParams['today2'] = today();
$rows = DB::all("SELECT a.id, a.account_no, a.account_name, a.service_status, a.network_state, a.activation_date, a.install_address, a.cycle_day, c.customer_no, c.name AS customer_name,
        c.mobile, c.classification, p.name AS plan_name, p.download_mbps, p.upload_mbps, ar.name AS area_name,
        ($openSql) AS open_amt,
        (SELECT COALESCE(SUM(i.balance), 0) FROM isp_invoices i WHERE i.account_id = a.id AND i.status IN ('posted','partially_paid','overdue','disputed') AND i.due_date < :today2) AS overdue_amt,
        (SELECT COALESCE(SUM(y.unapplied_amount), 0) FROM isp_payments y WHERE y.account_id = a.id AND y.status = 'posted') AS credit_amt"
    . $from . $where . ' ORDER BY a.account_no LIMIT ' . $per . ' OFFSET ' . $off, $listParams);

// the strip of figures (whole base the user may see, not the filtered list)
$kp = [];
$kb = IspSetup::branchSql('a.branch_id', $kp);
$counts = DB::pairs('SELECT a.service_status, COUNT(*) FROM isp_accounts a WHERE 1 = 1' . $kb . ' GROUP BY a.service_status', $kp);
$kp2 = $kp + ['d' => today()];
$overdueAccts = (int)DB::value("SELECT COUNT(DISTINCT i.account_id) FROM isp_invoices i JOIN isp_accounts a ON a.id = i.account_id
    WHERE i.status IN ('posted','partially_paid','overdue','disputed') AND i.balance > 0 AND i.due_date < :d" . $kb, $kp2);
$receivable = (float)DB::value("SELECT COALESCE(SUM(i.balance), 0) FROM isp_invoices i JOIN isp_accounts a ON a.id = i.account_id
    WHERE i.status IN ('posted','partially_paid','overdue','disputed')" . $kb, $kp);

$areaOpts = DB::pairs("SELECT a.id, CONCAT(a.name, CASE WHEN p.name IS NULL THEN '' ELSE CONCAT(', ', p.name) END) FROM isp_areas a LEFT JOIN isp_areas p ON p.id = a.parent_id
    WHERE a.is_active = 1 ORDER BY p.name, a.name");
$planOpts = DB::pairs('SELECT id, name FROM isp_plans ORDER BY sort_order, name');

$actions = page_link('isp.applications', ['a' => 'new'], 'New application', 'btn-primary', 'person-plus', 'create')
    . ' ' . (Access::page('rpt.isp_master') ? btn_link(url('rpt.isp_master'), 'Master list report', 'btn-outline-secondary', 'file-earmark-spreadsheet') : '');
layout_start('Subscriber Center', ['actions' => $actions]);
?>
<div class="kpi-grid">
  <?= IspUi::kpi('Active', number_format((int)($counts['active'] ?? 0)), 'connected subscriptions', url($pk, ['status' => 'active']), 'wifi', 'ok') ?>
  <?= IspUi::kpi('Pending activation', number_format((int)($counts['pending'] ?? 0)), 'approved, being installed', url($pk, ['status' => 'pending']), 'hourglass-split', 'info') ?>
  <?= IspUi::kpi('Disconnected', number_format((int)($counts['disconnected'] ?? 0)), 'service cut', url($pk, ['status' => 'disconnected']), 'wifi-off', 'bad') ?>
  <?= IspUi::kpi('Suspended', number_format((int)($counts['suspended'] ?? 0)), 'voluntary hold', url($pk, ['status' => 'suspended']), 'pause-circle', 'warn') ?>
  <?= IspUi::kpi('Overdue accounts', number_format($overdueAccts), 'receivable ' . money($receivable), url($pk, ['money' => 'overdue']), 'exclamation-triangle', 'warn') ?>
</div>

<?= IspUi::filterStart($pk) ?>
  <?= IspUi::fSearch($q, 'Account no., name, customer no., mobile, PPPoE user, address') ?>
  <?= IspUi::fSelect('status', 'Service status', IspAccounts::SERVICE_STATUSES, $status) ?>
  <?= IspUi::fSelect('net', 'Network', IspAccounts::NETWORK_STATES, $net) ?>
  <?= IspUi::fSelect('plan_id', 'Plan', $planOpts, $planId) ?>
  <?= IspUi::fSelect('area_id', 'Area', $areaOpts, $areaId) ?>
  <?php if (count(IspSetup::branches()) > 1 && IspSetup::userBranch() === null): ?>
  <?= IspUi::fSelect('branch_id', 'Branch', IspSetup::branches(), $branchId) ?>
  <?php endif; ?>
  <?= IspUi::fSelect('class', 'Classification', IspAccounts::CLASSIFICATIONS, $class) ?>
  <?= IspUi::fSelect('money', 'Balance', ['balance' => 'With an open balance', 'overdue' => 'Overdue', 'credit' => 'With an advance / credit'], $money) ?>
<?= IspUi::filterEnd(url($pk)) ?>

<div class="card isp-card">
  <div class="card-body p-0">
  <?php if (!$rows): ?>
    <?= IspUi::empty($total === 0 && $where === ' WHERE 1 = 1' ? 'No subscriber yet. Subscribers are added through New application.' : 'No account matches the filter.', 'people') ?>
  <?php else: ?>
  <div class="table-responsive">
  <table class="table table-sm table-hover align-middle isp-table mb-0">
    <thead><tr><th>Account</th><th>Subscriber</th><th>Plan</th><th class="d-none d-lg-table-cell">Area</th><th>Service</th><th class="d-none d-md-table-cell">Network</th>
      <th class="num">Balance</th><th class="num d-none d-md-table-cell">Overdue</th><th class="d-none d-xl-table-cell">Activated</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $bal = r2((float)$r['open_amt'] - (float)$r['credit_amt']); ?>
      <tr>
        <td class="text-nowrap"><a href="<?= h(url('isp.account', ['id' => $r['id']])) ?>"><strong><?= h($r['account_no']) ?></strong></a></td>
        <td><div><?= h($r['account_name']) ?></div><div class="small text-muted"><?= h($r['customer_no']) ?><?= $r['mobile'] ? ', ' . IspUi::sensitive($r['mobile']) : '' ?></div></td>
        <td><?= h($r['plan_name']) ?><div class="small text-muted"><?= h(IspPlans::speed($r)) ?></div></td>
        <td class="d-none d-lg-table-cell"><?= h((string)$r['area_name']) ?></td>
        <td><?= IspUi::status($r['service_status'], IspAccounts::SERVICE_STATUSES) ?></td>
        <td class="d-none d-md-table-cell"><?= IspUi::status($r['network_state'], IspAccounts::NETWORK_STATES) ?></td>
        <td class="num<?= $bal < 0 ? ' text-success' : '' ?>"><?= money($bal) ?></td>
        <td class="num d-none d-md-table-cell<?= (float)$r['overdue_amt'] > 0 ? ' text-danger' : '' ?>"><?= money($r['overdue_amt'], true) ?></td>
        <td class="d-none d-xl-table-cell"><?= h(fdate($r['activation_date'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  </div>
</div>
<?= IspUi::pager($total, $per, $page) ?>
<?php
layout_end();
