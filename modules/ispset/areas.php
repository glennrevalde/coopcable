<?php
declare(strict_types=1);

/**
 * Service Areas: the coverage tree (province, city / municipality, barangay, zone / purok / subdivision), the
 * office that serves each area, its coordinates and whether new applications there are feasible.
 */
$pk = 'ispset.areas';
const ISPSET_AREA_TYPES = ['region' => 'Region', 'province' => 'Province', 'municipality' => 'City / municipality', 'barangay' => 'Barangay', 'zone' => 'Zone / purok / sitio', 'subdivision' => 'Subdivision / village'];
$branches = IspSetup::branches();

/** Every area in tree order: [id => row + depth] */
function ispset_area_tree(): array
{
    $rows = DB::all('SELECT a.*, (SELECT COUNT(*) FROM isp_accounts x WHERE x.area_id = a.id AND x.service_status NOT IN (\'terminated\')) AS accounts_n,
            (SELECT COUNT(*) FROM isp_net_nodes n WHERE n.area_id = a.id) AS nodes_n FROM isp_areas a ORDER BY a.name');
    $kids = [];
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int)$r['id']] = $r;
        $kids[(int)($r['parent_id'] ?? 0)][] = (int)$r['id'];
    }
    $out = [];
    $walk = function (int $parent, int $depth) use (&$walk, &$out, $kids, $byId) {
        foreach ($kids[$parent] ?? [] as $id) {
            if (isset($out[$id])) {
                continue;
            }
            $out[$id] = $byId[$id] + ['depth' => $depth];
            $walk($id, $depth + 1);
        }
    };
    $walk(0, 0);
    foreach ($byId as $id => $r) {   // an area whose parent is missing still shows (at the top)
        if (!isset($out[$id])) {
            $out[$id] = $r + ['depth' => 0];
            $walk($id, 1);
        }
    }
    return $out;
}

/** The ids below an area (its children, their children ...) */
function ispset_area_below(int $id): array
{
    $out = [];
    $todo = [$id];
    while ($todo) {
        $next = DB::column('SELECT id FROM isp_areas WHERE parent_id IN (' . implode(',', array_map('intval', $todo)) . ')');
        $next = array_values(array_diff(array_map('intval', $next), $out, [$id]));
        $out = array_merge($out, $next);
        $todo = $next;
    }
    return $out;
}

handle_post(function () use ($pk, $branches) {
    $action = (string)p('action');
    if ($action === 'save') {
        $id = pint('id');
        Access::require($pk, $id ? 'edit' : 'create');
        $before = $id ? DB::row('SELECT * FROM isp_areas WHERE id = :id', ['id' => $id]) : null;
        if ($id && !$before) {
            fail('Area not found.');
        }
        $f = ['parent_id' => pint('parent_id'), 'area_type' => isset(ISPSET_AREA_TYPES[p('area_type')]) ? (string)p('area_type') : 'barangay',
            'code' => strtoupper(trim((string)p('code'))), 'name' => trim((string)p('name')), 'branch_id' => pint('branch_id'),
            'latitude' => trim((string)p('latitude')) === '' ? null : (float)p('latitude'), 'longitude' => trim((string)p('longitude')) === '' ? null : (float)p('longitude'),
            'is_serviceable' => p('is_serviceable') === '1' ? 1 : 0, 'notes' => mb_substr(trim((string)p('notes')), 0, 255) ?: null, 'is_active' => p('is_active') === '1' ? 1 : 0];
        if ($f['code'] === '' || $f['name'] === '') {
            fail('Enter the code and the name of the area.');
        }
        if (strlen($f['code']) > 20) {
            fail('The area code is at most 20 characters.');
        }
        if (DB::value('SELECT id FROM isp_areas WHERE code = :c AND id <> :id', ['c' => $f['code'], 'id' => (int)$id])) {
            fail('Another area already has the code ' . $f['code'] . '.');
        }
        if ($f['latitude'] !== null && ($f['latitude'] < -90 || $f['latitude'] > 90) || $f['longitude'] !== null && ($f['longitude'] < -180 || $f['longitude'] > 180)) {
            fail('The coordinates are out of range (latitude -90 to 90, longitude -180 to 180).');
        }
        if ($f['branch_id'] && !isset($branches[$f['branch_id']])) {
            fail('Choose an active branch.');
        }
        if ($f['parent_id']) {
            if (!DB::value('SELECT id FROM isp_areas WHERE id = :p', ['p' => $f['parent_id']])) {
                fail('The parent area was not found.');
            }
            if ($id && ($f['parent_id'] === $id || in_array($f['parent_id'], ispset_area_below($id), true))) {
                fail('An area cannot be placed under itself or under one of its own sub-areas.');
            }
        }
        if ($id) {
            DB::update('isp_areas', $f, 'id = :id', ['id' => $id]);
            Audit::log('edit', 'ispset', 'isp_area', $id, $f['code'], ['before' => $before, 'after' => $f]);
        } else {
            $id = DB::insert('isp_areas', $f);
            Audit::log('create', 'ispset', 'isp_area', $id, $f['code'], ['after' => $f]);
        }
        redirect_after_save(url($pk, ['a' => 'new', 'parent' => $f['parent_id']]), url($pk, ['id' => $id]));
    }
    if ($action === 'delete') {
        Access::require($pk, 'delete');
        $id = (int)p('id');
        $a = DB::row('SELECT * FROM isp_areas WHERE id = :id', ['id' => $id]) ?? fail('Area not found.');
        $uses = [
            'sub-areas' => (int)DB::value('SELECT COUNT(*) FROM isp_areas WHERE parent_id = :i', ['i' => $id]),
            'subscriber accounts' => (int)DB::value('SELECT COUNT(*) FROM isp_accounts WHERE area_id = :i', ['i' => $id]),
            'applications' => (int)DB::value('SELECT COUNT(*) FROM isp_applications WHERE area_id = :i', ['i' => $id]),
            'network nodes' => (int)DB::value('SELECT COUNT(*) FROM isp_net_nodes WHERE area_id = :i', ['i' => $id]),
            'work orders' => (int)DB::value('SELECT COUNT(*) FROM isp_work_orders WHERE area_id = :i', ['i' => $id]),
            'outages' => (int)DB::value('SELECT COUNT(*) FROM isp_outages WHERE area_id = :i', ['i' => $id]),
        ];
        $used = array_filter($uses);
        if ($used) {
            fail('Area ' . $a['name'] . ' is used by ' . implode(', ', array_map(fn($k, $n) => $n . ' ' . $k, array_keys($used), $used)) . ': mark it inactive instead.');
        }
        DB::tx(function () use ($id, $a) {
            DB::run('DELETE FROM isp_plan_areas WHERE area_id = :i', ['i' => $id]);
            DB::run('DELETE FROM isp_areas WHERE id = :i', ['i' => $id]);
            Audit::log('delete', 'ispset', 'isp_area', $id, (string)$a['code'], ['before' => $a]);
        });
        redirect(url($pk), 'Area ' . $a['name'] . ' deleted.', 'success');
    }
    fail('Unknown action.');
});

$tree = ispset_area_tree();
$parentOpts = [];
foreach ($tree as $tid => $t) {
    $parentOpts[$tid] = str_repeat('— ', (int)$t['depth']) . $t['name'] . ' (' . (ISPSET_AREA_TYPES[$t['area_type']] ?? $t['area_type']) . ')';
}

// ------------------------------------------------------------------ the form
if (g('a') === 'new' || gint('id')) {
    $id = gint('id');
    $a = $id ? DB::row('SELECT * FROM isp_areas WHERE id = :id', ['id' => $id]) : null;
    if ($id && !$a) {
        Access::denyPage($pk, 'view');
    }
    $opts = $parentOpts;
    if ($id) {
        foreach (array_merge([$id], ispset_area_below($id)) as $x) {
            unset($opts[$x]);
        }
    }
    $parentDefault = $a['parent_id'] ?? gint('parent');
    $childType = ['region' => 'province', 'province' => 'municipality', 'municipality' => 'barangay', 'barangay' => 'zone', 'zone' => 'subdivision'];
    $typeDefault = $a['area_type'] ?? ($parentDefault && isset($tree[$parentDefault]) ? ($childType[$tree[$parentDefault]['area_type']] ?? 'barangay') : 'province');
    layout_start($a ? $a['name'] : 'New service area', ['actions' => btn_link(url($pk), 'Service areas', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <div class="row g-3"><div class="col-lg-8">
    <div class="card isp-card"><div class="card-body">
      <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$id ?>">
        <?= select_field('parent_id', 'Inside', $opts, fv('parent_id', null, $parentDefault), ['blank' => '(top level)', 'class' => 'col-md-6']) ?>
        <?= select_field('area_type', 'Kind of area', ISPSET_AREA_TYPES, fv('area_type', null, $typeDefault), ['class' => 'col-md-6']) ?>
        <?= input_field('code', 'Code', fv('code', $a), ['class' => 'col-md-4', 'required' => true, 'extra' => 'maxlength="20" style="text-transform:uppercase"']) ?>
        <?= input_field('name', 'Name', fv('name', $a), ['class' => 'col-md-8', 'required' => true, 'extra' => 'maxlength="120"']) ?>
        <?= select_field('branch_id', 'Office that serves it', $branches, fv('branch_id', $a), ['blank' => '(not set)', 'class' => 'col-md-6']) ?>
        <?= input_field('latitude', 'Latitude', fv('latitude', $a), ['type' => 'number', 'step' => '0.0000001', 'class' => 'col-md-3']) ?>
        <?= input_field('longitude', 'Longitude', fv('longitude', $a), ['type' => 'number', 'step' => '0.0000001', 'class' => 'col-md-3']) ?>
        <?= input_field('notes', 'Notes', fv('notes', $a), ['class' => 'col-12', 'extra' => 'maxlength="255"']) ?>
        <?= check_field('is_serviceable', 'Serviceable: new applications here pass the coverage check', (string)fv('is_serviceable', $a, '1') === '1', 'col-md-7') ?>
        <?= check_field('is_active', 'Active', (string)fv('is_active', $a, '1') === '1', 'col-md-5') ?>
        <?php if (Access::can($pk, $id ? 'edit' : 'create')): ?><div class="col-12 mt-2"><?= save_buttons('Save area', url($pk), true) ?></div><?php endif; ?>
      </form>
    </div></div>
    </div>
    <?php if ($a): $t = $tree[$id] ?? $a + ['accounts_n' => 0, 'nodes_n' => 0]; ?>
    <div class="col-lg-4"><div class="card isp-card"><div class="card-body">
      <h2 class="sec-title">In this area</h2>
      <?= IspUi::dl(['Subscriber accounts' => (int)$t['accounts_n'], 'Network nodes' => (int)$t['nodes_n'], 'Sub-areas' => count(DB::column('SELECT id FROM isp_areas WHERE parent_id = :i', ['i' => $id])),
          'Plans limited to it' => (int)DB::value('SELECT COUNT(*) FROM isp_plan_areas WHERE area_id = :i', ['i' => $id])]) ?>
      <div class="d-flex gap-2 flex-wrap mt-2">
        <?= page_link($pk, ['a' => 'new', 'parent' => $id], 'Add a sub-area', 'btn-outline-primary', 'plus-lg', 'create') ?>
        <?php if (Access::can($pk, 'delete')): ?><?= IspUi::actionForm('delete', 'Delete', ['id' => $id], 'btn-outline-danger', 'trash', 'Delete area ' . $a['name'] . '? Only an unused area can be deleted.') ?><?php endif; ?>
      </div>
    </div></div></div>
    <?php endif; ?>
    </div>
    <?php
    layout_end();
    exit;
}

// ------------------------------------------------------------------ the tree
$q = trim(mb_strtolower((string)g('q')));
$type = isset(ISPSET_AREA_TYPES[g('type')]) ? (string)g('type') : '';
$rows = array_filter($tree, fn($t) => ($q === '' || str_contains(mb_strtolower($t['name'] . ' ' . $t['code']), $q)) && ($type === '' || $t['area_type'] === $type));
layout_start('Service Areas', ['actions' => page_link($pk, ['a' => 'new'], 'New area', 'btn-primary', 'plus-lg', 'create')]);
?>
<?= IspUi::filterStart($pk) ?>
  <?= IspUi::fSearch((string)g('q'), 'Name or code') ?>
  <?= IspUi::fSelect('type', 'Kind', ISPSET_AREA_TYPES, $type) ?>
<?= IspUi::filterEnd(url($pk)) ?>
<div class="card isp-card"><div class="card-body">
<?php if (!$rows): ?>
  <?= IspUi::empty($tree ? 'No area matches.' : 'No service area yet. Start with the province, then its cities, barangays and zones.', 'geo-alt') ?>
<?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
    <thead><tr><th>Area</th><th>Kind</th><th>Code</th><th>Office</th><th>Coordinates</th><th class="num">Accounts</th><th class="num">Nodes</th><th>Coverage</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($rows as $id => $r): ?>
      <tr><td style="padding-left:<?= 0.5 + ($q === '' && $type === '' ? (int)$r['depth'] * 1.25 : 0) ?>rem"><?= (int)$r['depth'] && $q === '' && $type === '' ? '<span class="text-muted">&#8627;</span> ' : '' ?><a href="<?= h(url($pk, ['id' => (int)$id])) ?>"><?= h($r['name']) ?></a></td>
        <td class="small"><?= h(ISPSET_AREA_TYPES[$r['area_type']] ?? $r['area_type']) ?></td><td class="small"><?= h($r['code']) ?></td><td class="small"><?= h((string)($branches[(int)$r['branch_id']] ?? '')) ?></td>
        <td class="small text-nowrap"><?= $r['latitude'] !== null ? h(round((float)$r['latitude'], 5) . ', ' . round((float)$r['longitude'], 5)) : '' ?></td>
        <td class="num"><?= (int)$r['accounts_n'] ?></td><td class="num"><?= (int)$r['nodes_n'] ?></td>
        <td><?= IspUi::status((int)$r['is_serviceable'] ? 'ok' : 'inactive', ['ok' => 'Serviceable', 'inactive' => 'Not serviceable']) ?></td>
        <td><?= IspUi::status((int)$r['is_active'] ? 'active' : 'inactive', ['active' => 'Active', 'inactive' => 'Inactive']) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
<?php endif; ?>
</div></div>
<?php
layout_end();
