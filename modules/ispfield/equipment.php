<?php
declare(strict_types=1);

/**
 * Equipment Register: the serialized units lent, rented or sold to subscribers (ONUs / ONTs, routers, set-top boxes),
 * kept apart from the bulk stock: ownership, deposit and rental, where each unit is, and its whole history.
 */
$pk = 'ispfield.equipment';
$technicians = DB::pairs('SELECT id, name FROM isp_technicians WHERE is_active = 1 ORDER BY name');
$sites = DB::pairs("SELECT id, CONCAT(name, ' (', site_type, ')') FROM sites WHERE is_active = 1 ORDER BY name");
$items = Lookup::itemPairs("is_active = 1 AND item_type IN ('inventory', 'assembly', 'non_inventory')");

handle_post(function () use ($pk) {
    $action = (string)p('action');
    if ($action === 'register') {
        Access::require($pk, 'create');
        $itemId = pint('item_id') ?? fail('Choose the item (model) of the units.');
        if (!DB::value('SELECT id FROM items WHERE id = :i', ['i' => $itemId])) {
            fail('Item not found.');
        }
        $serials = array_values(array_unique(array_filter(array_map(fn($s) => strtoupper(trim($s)), preg_split('/[\r\n,;]+/', (string)p('serials'))))));
        if (!$serials) {
            fail('Enter the serial numbers, one per line.');
        }
        foreach ($serials as $s) {
            if (strlen($s) > 60) {
                fail('Serial ' . mb_substr($s, 0, 20) . '... is longer than 60 characters.');
            }
        }
        [$in, $ip] = DB::in('sn', $serials);
        $dup = DB::column("SELECT serial_no FROM isp_equipment WHERE item_id = :i AND serial_no IN $in", ['i' => $itemId] + $ip);
        if ($dup) {
            fail('Already registered: ' . implode(', ', array_slice($dup, 0, 10)) . (count($dup) > 10 ? ' and ' . (count($dup) - 10) . ' more' : '') . '.');
        }
        $f = ['ownership' => isset(IspEquipment::OWNERSHIP[p('ownership')]) ? (string)p('ownership') : 'company', 'status' => 'in_stock', 'site_id' => pint('site_id'),
            'mac_address' => count($serials) === 1 ? (trim((string)p('mac_address')) ?: null) : null, 'notes' => trim((string)p('notes')) ?: null];
        if (Access::viewCosts() && trim((string)p('unit_cost')) !== '') {
            $f['unit_cost'] = pnum('unit_cost');
        }
        $deposit = pnum('deposit');
        $rental = pnum('rental_fee');
        $ids = DB::tx(function () use ($serials, $itemId, $f, $deposit, $rental) {
            $ids = [];
            foreach ($serials as $s) {
                $id = IspEquipment::register($itemId, $s, $f);
                DB::update('isp_equipment', ['deposit' => $deposit, 'rental_fee' => $rental], 'id = :id', ['id' => $id]);
                $ids[] = $id;
            }
            Audit::log('create', 'ispfield', 'isp_equipment', count($ids) === 1 ? $ids[0] : null, implode(', ', array_slice($serials, 0, 5)) . (count($serials) > 5 ? '...' : ''),
                ['reason' => count($ids) . ' unit(s) registered']);
            return $ids;
        });
        redirect(count($ids) === 1 ? url($pk, ['id' => $ids[0]]) : url($pk, ['item_id' => $itemId]), count($ids) . ' unit' . (count($ids) === 1 ? '' : 's') . ' registered.', 'success');
    }
    $id = (int)p('id');
    $e = IspEquipment::get($id) ?? fail('Equipment not found.');
    if ($action === 'move') {
        $to = (string)p('to');
        Access::require($pk, in_array($to, ['disposed', 'missing'], true) ? 'delete' : 'edit');
        $remarks = trim((string)p('remarks'));
        $set = [];
        $accountId = null;
        switch ($to) {
            case 'with_technician':
                $tid = pint('technician_id') ?? fail('Choose the technician.');
                $site = DB::value('SELECT site_id FROM isp_technicians WHERE id = :t', ['t' => $tid]);
                $set = ['technician_id' => $tid, 'site_id' => $site ?: $e['site_id']];
                break;
            case 'installed':
                $accountId = IspUi::postedAccount('account_id') ?? fail('Choose the subscriber account the unit is installed at.');
                $set = ['site_id' => null];
                break;
            case 'in_stock':
            case 'recovered':
                $set = ['site_id' => pint('site_id') ?? IspWorkOrders::stockSite(), 'technician_id' => null];
                break;
            case 'for_recovery':
                if (!$e['account_id']) {
                    fail('Only a unit installed at a subscriber is marked for recovery.');
                }
                break;
            case 'damaged':
            case 'missing':
            case 'disposed':
                if ($remarks === '') {
                    fail('Enter what happened to the unit.');
                }
                $set = ['disposition' => mb_substr($remarks, 0, 160)];
                if ($to === 'disposed') {
                    $set += ['site_id' => null, 'technician_id' => null];
                }
                break;
            default:
                fail('Choose the new status.');
        }
        if ($remarks === '') {
            $remarks = IspEquipment::STATUSES[$to];
        }
        DB::tx(fn() => IspEquipment::move($id, $to, $remarks, $accountId, null, $set));
        Audit::log('move', 'ispfield', 'isp_equipment', $id, (string)$e['serial_no'], ['reason' => $e['status'] . ' -> ' . $to . ': ' . $remarks]);
        redirect(url($pk, ['id' => $id]), 'Unit ' . $e['serial_no'] . ': ' . strtolower(IspEquipment::STATUSES[$to]) . '.', 'success');
    }
    if ($action === 'edit') {
        Access::require($pk, 'edit');
        $set = ['mac_address' => trim((string)p('mac_address')) ?: null, 'ownership' => isset(IspEquipment::OWNERSHIP[p('ownership')]) ? (string)p('ownership') : $e['ownership'],
            'deposit' => pnum('deposit'), 'rental_fee' => pnum('rental_fee'), 'notes' => mb_substr(trim((string)p('notes')), 0, 255) ?: null];
        DB::update('isp_equipment', $set + ['updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        Audit::log('edit', 'ispfield', 'isp_equipment', $id, (string)$e['serial_no'], ['before' => array_intersect_key($e, $set), 'after' => $set]);
        redirect(url($pk, ['id' => $id]), 'Unit saved.', 'success');
    }
    fail('Unknown action.');
});

// ------------------------------------------------------------------ register units
if (g('a') === 'new') {
    Access::require($pk, 'create');
    layout_start('Register equipment', ['actions' => btn_link(url($pk), 'Equipment register', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <div class="card isp-card"><div class="card-body">
      <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="register">
        <?= select_field('item_id', 'Item (model)', $items, fv('item_id', null, g('item_id')), ['blank' => '', 'class' => 'col-md-6', 'required' => true]) ?>
        <?= select_field('ownership', 'Ownership', IspEquipment::OWNERSHIP, fv('ownership', null, 'company'), ['class' => 'col-md-3']) ?>
        <?= select_field('site_id', 'Stock site', $sites, fv('site_id', null, Settings::get('isp_stock_site_id')), ['blank' => '', 'class' => 'col-md-3']) ?>
        <div class="col-md-6"><label class="form-label">Serial numbers (one per line; a whole box at once)</label><textarea class="form-control form-control-sm" name="serials" rows="6" required style="text-transform:uppercase"><?= h((string)fv('serials')) ?></textarea></div>
        <div class="col-md-6"><div class="row g-2">
          <?= input_field('mac_address', 'MAC address (one unit only)', fv('mac_address'), ['class' => 'col-12']) ?>
          <?php if (Access::viewCosts()): ?><?= input_field('unit_cost', 'Unit cost (empty: the item\'s average cost)', fv('unit_cost'), ['type' => 'number', 'class' => 'col-md-6']) ?><?php endif; ?>
          <?= input_field('deposit', 'Deposit per unit', fv('deposit', null, '0'), ['type' => 'number', 'class' => 'col-md-6']) ?>
          <?= input_field('rental_fee', 'Monthly rental per unit', fv('rental_fee', null, '0'), ['type' => 'number', 'class' => 'col-md-6']) ?>
          <?= input_field('notes', 'Notes', fv('notes'), ['class' => 'col-12']) ?>
        </div></div>
        <div class="col-12 small text-muted">Units issued to a work order with their serial are registered automatically. Registering here records the units received in the stockroom; the quantity itself is received through the purchase receiving.</div>
        <div class="col-12 mt-2"><button class="btn btn-sm btn-primary"><i class="bi bi-upc-scan"></i> Register units</button> <?= btn_link(url($pk), 'Cancel') ?></div>
      </form>
    </div></div>
    <?php
    layout_end();
    exit;
}

// ------------------------------------------------------------------ one unit
if ($id = gint('id')) {
    $e = IspEquipment::get($id);
    if (!$e) {
        Access::denyPage($pk, 'view');
    }
    $log = DB::all('SELECT l.*, u.full_name, a.account_no, w.wo_no FROM isp_equipment_log l LEFT JOIN users u ON u.id = l.user_id LEFT JOIN isp_accounts a ON a.id = l.account_id
        LEFT JOIN isp_work_orders w ON w.id = l.work_order_id WHERE l.equipment_id = :e ORDER BY l.id DESC', ['e' => $id]);
    $next = match ($e['status']) {
        'in_stock' => ['with_technician', 'installed', 'damaged', 'missing', 'disposed'],
        'with_technician' => ['in_stock', 'installed', 'damaged', 'missing'],
        'installed' => ['for_recovery', 'recovered', 'damaged', 'missing'],
        'for_recovery' => ['recovered', 'damaged', 'missing', 'installed'],
        'recovered' => ['in_stock', 'damaged', 'disposed'],
        'damaged' => ['in_stock', 'disposed'],
        'missing' => ['recovered', 'disposed'],
        default => [],
    };
    $next = array_values(array_filter($next, fn($s) => Access::can($pk, in_array($s, ['disposed', 'missing'], true) ? 'delete' : 'edit')));
    $to = (string)(is_post() ? p('to') : ($next[0] ?? ''));
    layout_start($e['item_code'] . ' ' . $e['serial_no'], ['actions' => btn_link(url($pk), 'Equipment register', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <div class="isp-head"><div><h2 class="isp-head-title"><?= h($e['item_name']) ?></h2>
      <div class="isp-head-sub"><?= IspUi::status($e['status'], IspEquipment::STATUSES) ?> <?= h(IspEquipment::OWNERSHIP[$e['ownership']] ?? $e['ownership']) ?></div></div></div>
    <div class="row g-3">
      <div class="col-lg-5">
        <div class="card isp-card"><div class="card-body">
          <h2 class="sec-title">The unit</h2>
          <?= IspUi::dl(['Serial' => h($e['serial_no']), 'MAC address' => h((string)$e['mac_address']), 'Item' => h($e['item_code'] . ' ' . $e['item_name']),
              'Subscriber' => $e['account_id'] ? IspUi::acct($e['account_id'], $e['account_no'], (string)DB::value('SELECT account_name FROM isp_accounts WHERE id = :a', ['a' => $e['account_id']])) : '',
              'Technician' => h((string)($technicians[(int)$e['technician_id']] ?? '')), 'Site' => h((string)($sites[(int)$e['site_id']] ?? '')),
              'Deposit' => (float)$e['deposit'] ? h(money($e['deposit'])) : '', 'Monthly rental' => (float)$e['rental_fee'] ? h(money($e['rental_fee'])) : '',
              'Unit cost' => Access::viewCosts() && $e['unit_cost'] !== null ? h(money($e['unit_cost'])) : '', 'Installed' => h(fdatetime($e['installed_at'])),
              'Recovered' => h(fdatetime($e['recovered_at'])), 'Disposition' => h((string)$e['disposition']), 'Notes' => h((string)$e['notes'])]) ?>
        </div></div>
        <?php if (Access::can($pk, 'edit')): ?>
        <div class="card isp-card mt-3"><div class="card-body">
          <h2 class="sec-title">Edit</h2>
          <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?= $id ?>">
            <?= input_field('mac_address', 'MAC address', fv('mac_address', $e), ['class' => 'col-md-6']) ?>
            <?= select_field('ownership', 'Ownership', IspEquipment::OWNERSHIP, fv('ownership', $e), ['class' => 'col-md-6']) ?>
            <?= input_field('deposit', 'Deposit', fv('deposit', $e), ['type' => 'number', 'class' => 'col-md-6']) ?>
            <?= input_field('rental_fee', 'Monthly rental', fv('rental_fee', $e), ['type' => 'number', 'class' => 'col-md-6']) ?>
            <?= input_field('notes', 'Notes', fv('notes', $e), ['class' => 'col-12']) ?>
            <div class="col-12"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-check-lg"></i> Save</button></div>
          </form>
        </div></div>
        <?php endif; ?>
      </div>
      <div class="col-lg-7">
        <?php if ($next): ?>
        <div class="card isp-card"><div class="card-body">
          <h2 class="sec-title">Move the unit</h2>
          <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= $id ?>">
            <div class="col-md-5"><label class="form-label">New status</label><select class="form-select form-select-sm" name="to" id="eqTo"><?= options(array_intersect_key(IspEquipment::STATUSES, array_flip($next)), $to) ?></select></div>
            <div class="col-md-7 eq-for" data-for="with_technician"><label class="form-label">Technician</label><select class="form-select form-select-sm" name="technician_id"><?= options($technicians, fv('technician_id'), '') ?></select></div>
            <div class="col-md-7 eq-for" data-for="in_stock recovered"><label class="form-label">Stock site</label><select class="form-select form-select-sm" name="site_id"><?= options($sites, fv('site_id', null, Settings::get('isp_stock_site_id')), '(the network stockroom)') ?></select></div>
            <div class="col-12 eq-for" data-for="installed"><div class="row g-2"><?= IspUi::accountField('account_id', IspUi::postedAccount('account_id') ?? ($e['account_id'] ? (int)$e['account_id'] : null), 'Installed at', 'col-12', false) ?></div></div>
            <?= input_field('remarks', 'Remarks (required for damaged, missing, disposed)', fv('remarks'), ['class' => 'col-12']) ?>
            <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-arrow-left-right"></i> Move</button></div>
          </form>
          <script>(function () { var s = document.getElementById('eqTo'); function f() { document.querySelectorAll('.eq-for').forEach(function (d) { d.hidden = d.getAttribute('data-for').split(' ').indexOf(s.value) < 0; }); } s.addEventListener('change', f); f(); })();</script>
        </div></div>
        <?php endif; ?>
        <div class="card isp-card mt-3"><div class="card-body">
          <h2 class="sec-title">History</h2>
          <div class="table-responsive"><table class="table table-sm align-middle isp-table"><thead><tr><th>When</th><th>From</th><th>To</th><th>Account</th><th>Work order</th><th>Remarks</th><th>By</th></tr></thead><tbody>
          <?php foreach ($log as $l): ?>
            <tr><td class="small text-nowrap"><?= h(fdatetime($l['created_at'])) ?></td><td><?= $l['from_status'] ? IspUi::status($l['from_status'], IspEquipment::STATUSES) : '' ?></td><td><?= IspUi::status($l['to_status'], IspEquipment::STATUSES) ?></td>
              <td><?= h((string)$l['account_no']) ?></td><td><?= $l['work_order_id'] ? safe_link('ispfield.workorders', ['id' => (int)$l['work_order_id']], (string)$l['wo_no']) : '' ?></td><td class="small"><?= h((string)$l['remarks']) ?></td><td class="small"><?= h((string)$l['full_name']) ?></td></tr>
          <?php endforeach; ?>
          </tbody></table></div>
        </div></div>
      </div>
    </div>
    <?php
    layout_end();
    exit;
}

// ------------------------------------------------------------------ the register
$q = trim((string)g('q'));
$item = gint('item_id');
$status = isset(IspEquipment::STATUSES[g('status')]) ? (string)g('status') : '';
$own = isset(IspEquipment::OWNERSHIP[g('ownership')]) ? (string)g('ownership') : '';
$tech = gint('technician_id');
$p = [];
$where = ['1 = 1'];
if ($q !== '') {
    $where[] = '(e.serial_no LIKE :q1 OR e.mac_address LIKE :q2 OR a.account_no LIKE :q3 OR a.account_name LIKE :q4)';
    $p += ['q1' => "%$q%", 'q2' => "%$q%", 'q3' => "$q%", 'q4' => "%$q%"];
}
foreach (['item_id' => $item, 'status' => $status, 'ownership' => $own, 'technician_id' => $tech] as $col => $v) {
    if ($v !== null && $v !== '') {
        $where[] = "e.$col = :$col";
        $p[$col] = $v;
    }
}
$from = 'FROM isp_equipment e JOIN items i ON i.id = e.item_id LEFT JOIN isp_accounts a ON a.id = e.account_id LEFT JOIN isp_technicians t ON t.id = e.technician_id LEFT JOIN sites s ON s.id = e.site_id WHERE ' . implode(' AND ', $where);
[$per, $off, $page] = IspUi::paging(50);
$total = (int)DB::value("SELECT COUNT(*) $from", $p);
$rows = DB::all("SELECT e.*, i.code AS item_code, i.name AS item_name, a.account_no, a.account_name, t.name AS technician_name, s.name AS site_name $from ORDER BY i.code, e.serial_no LIMIT $per OFFSET $off", $p);
$counts = DB::pairs('SELECT status, COUNT(*) FROM isp_equipment GROUP BY status');
layout_start('Equipment Register', ['actions' => page_link($pk, ['a' => 'new'], 'Register units', 'btn-primary', 'upc-scan', 'create')]);
?>
<div class="kpi-grid">
  <?= IspUi::kpi('In the stockroom', number_format((int)($counts['in_stock'] ?? 0)), 'ready to issue', url($pk, ['status' => 'in_stock']), 'box-seam', 'ok') ?>
  <?= IspUi::kpi('With technicians', number_format((int)($counts['with_technician'] ?? 0)), 'issued to jobs', url($pk, ['status' => 'with_technician']), 'truck', 'info') ?>
  <?= IspUi::kpi('Installed at subscribers', number_format((int)($counts['installed'] ?? 0)), 'customer asset register', url($pk, ['status' => 'installed']), 'router', 'info') ?>
  <?= IspUi::kpi('To recover', number_format((int)($counts['for_recovery'] ?? 0)), 'disconnected or ended accounts', url($pk, ['status' => 'for_recovery']), 'arrow-repeat', 'warn') ?>
  <?= IspUi::kpi('Damaged or missing', number_format((int)($counts['damaged'] ?? 0) + (int)($counts['missing'] ?? 0)), 'needs a decision', url($pk, ['status' => 'damaged']), 'exclamation-octagon', 'bad') ?>
</div>
<?= IspUi::filterStart($pk) ?>
  <?= IspUi::fSearch($q, 'Serial, MAC, account') ?>
  <?= IspUi::fSelect('item_id', 'Item', $items, $item) ?>
  <?= IspUi::fSelect('status', 'Status', IspEquipment::STATUSES, $status) ?>
  <?= IspUi::fSelect('ownership', 'Ownership', IspEquipment::OWNERSHIP, $own) ?>
  <?= IspUi::fSelect('technician_id', 'Technician', $technicians, $tech) ?>
<?= IspUi::filterEnd(url($pk)) ?>
<div class="card isp-card"><div class="card-body">
<?php if (!$rows): ?>
  <?= IspUi::empty('No unit matches. Units are registered here or when a technician is issued a serialized unit.', 'router') ?>
<?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
    <thead><tr><th>Serial</th><th>Item</th><th>Status</th><th>Where</th><th>Ownership</th><th class="num">Deposit</th><th class="num">Rental</th><th>Updated</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><a href="<?= h(url($pk, ['id' => (int)$r['id']])) ?>"><?= h($r['serial_no']) ?></a><?= $r['mac_address'] ? '<div class="small text-muted">' . h($r['mac_address']) . '</div>' : '' ?></td>
        <td><?= h($r['item_code']) ?><div class="small text-muted"><?= h($r['item_name']) ?></div></td><td><?= IspUi::status($r['status'], IspEquipment::STATUSES) ?></td>
        <td class="small"><?= $r['account_id'] ? h($r['account_no'] . ' ' . $r['account_name']) : h((string)($r['technician_name'] ?: $r['site_name'])) ?></td>
        <td class="small"><?= h($r['ownership'] === 'customer' ? 'Sold' : 'Company') ?></td><td class="num"><?= h(money($r['deposit'], true)) ?></td><td class="num"><?= h(money($r['rental_fee'], true)) ?></td>
        <td class="small"><?= h(fdate($r['updated_at'] ?: $r['created_at'])) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?= IspUi::pager($total, $per, $page) ?>
<?php endif; ?>
</div></div>
<?php
layout_end();
