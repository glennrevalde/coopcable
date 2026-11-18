<?php
declare(strict_types=1);

/**
 * Infrastructure & Ports: the physical network as records. OLTs and their PON ports, splitters, NAPs and their drop
 * ports, cabinets, poles, splice closures and switches (nodes); the cable segments between them with their cores and
 * splices; the capacity of every node with ports; the maintenance history. A subscriber hangs on one drop port, and
 * from there the chain goes up to the OLT (the trace).
 */
require_once __DIR__ . '/_common.php';

$pk = 'ispnet.infra';
const ISPNET_EVENT_TYPES = ['inspected' => 'Inspected', 'maintenance' => 'Maintenance', 'damaged' => 'Damaged', 'repaired' => 'Repaired', 'relocated' => 'Relocated', 'replaced' => 'Replaced',
    'installed' => 'Installed', 'retired' => 'Retired', 'note' => 'Note'];
const ISPNET_CORE_STATUSES = ['free' => 'Free', 'used' => 'In use', 'reserved' => 'Reserved', 'broken' => 'Broken'];

/** "lat,lng" points from free text (one per line, or ";"-separated): the stored "lat,lng;lat,lng" form */
function ispnet_norm_path(string $text): string
{
    $pts = [];
    foreach (preg_split('/[;\r\n]+/', $text) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $xy = preg_split('/[\s,]+/', $line);
        if (count($xy) !== 2 || !is_numeric($xy[0]) || !is_numeric($xy[1]) || abs((float)$xy[0]) > 90 || abs((float)$xy[1]) > 180) {
            fail('The route point "' . $line . '" is not a "latitude, longitude" pair.');
        }
        $pts[] = round((float)$xy[0], 7) . ',' . round((float)$xy[1], 7);
    }
    if (count($pts) === 1) {
        fail('A route needs at least two points.');
    }
    return implode(';', $pts);
}

function ispnet_node_fields(): array
{
    $num = fn(string $k) => trim((string)p($k)) === '' ? null : (float)p($k);
    $type = (string)p('node_type');
    return [
        'node_type' => $type, 'code' => mb_substr(strtoupper(trim((string)p('code'))), 0, 40), 'name' => mb_substr(trim((string)p('name')), 0, 120),
        'parent_id' => pint('parent_id'), 'upstream_port_id' => pint('upstream_port_id'), 'area_id' => pint('area_id'), 'branch_id' => pint('branch_id'),
        'latitude' => $num('latitude'), 'longitude' => $num('longitude'), 'address' => mb_substr(trim((string)p('address')), 0, 255) ?: null,
        'vendor' => mb_substr(trim((string)p('vendor')), 0, 60) ?: null, 'model' => mb_substr(trim((string)p('model')), 0, 60) ?: null, 'serial_no' => mb_substr(trim((string)p('serial_no')), 0, 60) ?: null,
        'split_ratio' => mb_substr(trim((string)p('split_ratio')), 0, 10) ?: null, 'port_count' => max(0, min(512, (int)p('port_count'))), 'specs' => trim((string)p('specs')) ?: null,
        'device_id' => pint('device_id'), 'installed_on' => pdate('installed_on'), 'status' => isset(IspInfra::NODE_STATUSES[(string)p('status')]) ? (string)p('status') : 'active',
        'notes' => mb_substr(trim((string)p('notes')), 0, 255) ?: null,
    ];
}

handle_post(function () use ($pk) {
    $action = (string)p('action');
    $nodeId = (int)p('node_id');
    $segId = (int)p('segment_id');
    switch ($action) {
        case 'save_node':
            Access::require($pk, $nodeId ? 'edit' : 'create');
            $f = ispnet_node_fields();
            if ($nodeId && $f['parent_id'] === $nodeId) {
                fail('A node cannot be inside itself.');
            }
            if ($nodeId && $f['parent_id'] && in_array($f['parent_id'], IspInfra::downstreamNodes($nodeId), true)) {
                fail('The node chosen as its parent is below it in the network.');
            }
            if ($nodeId && $f['upstream_port_id'] && in_array((int)DB::value('SELECT node_id FROM isp_net_ports WHERE id = :p', ['p' => $f['upstream_port_id']]), IspInfra::downstreamNodes($nodeId), true)) {
                fail('The feeding port belongs to a node below this one.');
            }
            if ($nodeId) {
                $usedMax = (int)DB::value("SELECT COALESCE(MAX(port_no), 0) FROM isp_net_ports WHERE node_id = :n AND status <> 'free'", ['n' => $nodeId]);
                if ($f['port_count'] < $usedMax) {
                    fail('Port ' . $usedMax . ' is in use or reserved: the node needs at least ' . $usedMax . ' ports.');
                }
                $before = IspInfra::node($nodeId);
                if ($before && (int)$before['upstream_port_id'] && (int)$before['upstream_port_id'] !== (int)$f['upstream_port_id']) {
                    // the old feeding port is free again
                    DB::run("UPDATE isp_net_ports SET status = 'free', label = NULL WHERE id = :p AND account_id IS NULL", ['p' => $before['upstream_port_id']]);
                }
            }
            $id = IspInfra::saveNode($f, $nodeId ?: null);
            if ($nodeId) {
                // ports above the new count that are free are removed
                DB::run("DELETE FROM isp_net_ports WHERE node_id = :n AND port_no > :c AND status = 'free'", ['n' => $id, 'c' => $f['port_count']]);
            }
            redirect_after_save(url($pk, ['a' => 'node_new']), url($pk, ['node' => $id]));
            return;
        case 'port':
            Access::require($pk, 'edit');
            $port = DB::row('SELECT p.*, n.code FROM isp_net_ports p JOIN isp_net_nodes n ON n.id = p.node_id WHERE p.id = :p', ['p' => (int)p('port_id')]) ?? fail('Port not found.');
            $op = (string)p('op');
            $label = $port['code'] . ' port ' . $port['port_no'];
            if ($op === 'assign') {
                $acct = IspUi::postedAccount('account_id') ?? fail('Choose the subscriber account.');
                if (!ispnet_account_visible($acct)) {
                    fail('That account belongs to another branch.');
                }
                $a = IspAccounts::get($acct);
                $old = $a['port_id'] ? IspInfra::portLabel((int)$a['port_id']) : '';
                IspInfra::assignPort((int)$port['id'], $acct);
                IspAccounts::event($acct, 'network', 'Drop port set to ' . $label . ($old ? ' (was ' . $old . ')' : ''));
                Audit::log('edit', 'ispnet', 'isp_net_port', (int)$port['id'], $label, ['page_key' => $pk, 'reason' => 'Assigned to ' . $a['account_no']]);
                redirect(url($pk, ['node' => $port['node_id']]), $label . ' assigned to ' . $a['account_no'] . '.', 'success');
            }
            if ($op === 'faulty') {
                if ($port['account_id']) {
                    fail($label . ' serves a subscriber: move the subscriber to another port first.');
                }
                DB::update('isp_net_ports', ['status' => 'faulty', 'application_id' => null, 'label' => mb_substr(trim((string)p('label')) ?: 'Faulty', 0, 60), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $port['id']]);
            } elseif ($op === 'free') {
                if ($port['account_id']) {
                    $st = DB::value('SELECT service_status FROM isp_accounts WHERE id = :a', ['a' => $port['account_id']]);
                    if (!in_array($st, ['terminated', 'cancelled'], true)) {
                        fail($label . ' serves an account that is ' . $st . ': move it to another port instead.');
                    }
                }
                if (DB::value('SELECT id FROM isp_net_nodes WHERE upstream_port_id = :p', ['p' => $port['id']])) {
                    fail($label . ' feeds another node: change that node\'s feeding port first.');
                }
                IspInfra::releasePort((int)$port['id']);
                DB::run('UPDATE isp_net_ports SET label = NULL WHERE id = :p', ['p' => $port['id']]);
            } elseif ($op === 'label') {
                DB::update('isp_net_ports', ['label' => mb_substr(trim((string)p('label')), 0, 60) ?: null, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $port['id']]);
            } else {
                fail('Choose what to do with the port.');
            }
            Audit::log('edit', 'ispnet', 'isp_net_port', (int)$port['id'], $label, ['page_key' => $pk, 'reason' => $op]);
            redirect(url($pk, ['node' => $port['node_id']]), $label . ' updated.', 'success');
        case 'event':
            Access::require($pk, 'edit');
            $type = (string)p('event_type');
            if (!isset(ISPNET_EVENT_TYPES[$type]) || trim((string)p('remarks')) === '') {
                fail('Choose the kind of event and describe it.');
            }
            if (!$nodeId && !$segId) {
                fail('Choose the node or the cable segment.');
            }
            DB::insert('isp_node_events', ['node_id' => $nodeId ?: null, 'segment_id' => $segId ?: null, 'event_type' => $type, 'event_date' => pdate('event_date', today()),
                'remarks' => mb_substr(trim((string)p('remarks')), 0, 255), 'work_order_id' => pint('work_order_id'), 'user_id' => Auth::id()]);
            redirect(url($pk, $nodeId ? ['node' => $nodeId] : ['seg' => $segId]), 'Event recorded.', 'success');
        case 'save_segment':
            Access::require($pk, $segId ? 'edit' : 'create');
            $f = ['code' => mb_substr(strtoupper(trim((string)p('code'))), 0, 40), 'cable_type' => isset(IspInfra::CABLE_TYPES[(string)p('cable_type')]) ? (string)p('cable_type') : 'fiber',
                'core_count' => max(0, min(288, (int)p('core_count'))), 'from_node_id' => pint('from_node_id'), 'to_node_id' => pint('to_node_id'), 'length_m' => trim((string)p('length_m')),
                'path' => ispnet_norm_path((string)p('path')) ?: null, 'installed_on' => pdate('installed_on'), 'status' => isset(IspInfra::SEGMENT_STATUSES[(string)p('status')]) ? (string)p('status') : 'active',
                'replaced_by_id' => pint('replaced_by_id'), 'notes' => mb_substr(trim((string)p('notes')), 0, 255) ?: null];
            if ($f['from_node_id'] && $f['from_node_id'] === $f['to_node_id']) {
                fail('The segment starts and ends at the same node.');
            }
            if ($segId && $f['replaced_by_id'] === $segId) {
                fail('A segment cannot replace itself.');
            }
            if ($f['status'] === 'replaced' && !$f['replaced_by_id']) {
                fail('Choose the segment that replaces this one.');
            }
            if ($segId) {
                $usedCores = (int)DB::value("SELECT COALESCE(MAX(core_no), 0) FROM isp_cable_cores WHERE segment_id = :s AND status <> 'free'", ['s' => $segId]);
                if ($f['core_count'] < $usedCores) {
                    fail('Core ' . $usedCores . ' is in use: the cable needs at least ' . $usedCores . ' cores.');
                }
            }
            $id = IspInfra::saveSegment($f, $segId ?: null);
            if ($segId) {
                DB::run("DELETE FROM isp_cable_cores WHERE segment_id = :s AND core_no > :c AND status = 'free' AND id NOT IN (SELECT in_core_id FROM isp_splices) AND id NOT IN (SELECT out_core_id FROM isp_splices)",
                    ['s' => $id, 'c' => $f['core_count']]);
            }
            Audit::log($segId ? 'edit' : 'create', 'ispnet', 'isp_cable_segment', $id, $f['code'], ['page_key' => $pk]);
            redirect_after_save(url($pk, ['a' => 'seg_new']), url($pk, ['seg' => $id]));
            return;
        case 'core':
            Access::require($pk, 'edit');
            $c = DB::row('SELECT * FROM isp_cable_cores WHERE id = :c', ['c' => (int)p('core_id')]) ?? fail('Core not found.');
            $st = (string)p('status');
            if (!isset(ISPNET_CORE_STATUSES[$st])) {
                fail('Choose the status of the core.');
            }
            DB::update('isp_cable_cores', ['status' => $st, 'assignment' => mb_substr(trim((string)p('assignment')), 0, 160) ?: null], 'id = :id', ['id' => $c['id']]);
            redirect(url($pk, ['seg' => $c['segment_id']]), 'Core ' . $c['core_no'] . ' saved.', 'success');
        case 'splice':
            Access::require($pk, 'edit');
            $in = (int)p('in_core_id');
            $out = (int)p('out_core_id');
            $closure = (int)p('closure_id');
            if (!$in || !$out || $in === $out || !$closure) {
                fail('Choose the splice closure and two different cores.');
            }
            if (DB::value('SELECT id FROM isp_splices WHERE in_core_id IN (:a, :b) OR out_core_id IN (:c, :d)', ['a' => $in, 'b' => $out, 'c' => $in, 'd' => $out])) {
                fail('One of these cores is already spliced.');
            }
            DB::insert('isp_splices', ['closure_id' => $closure, 'in_core_id' => $in, 'out_core_id' => $out, 'loss_db' => trim((string)p('loss_db')) === '' ? null : (float)p('loss_db'),
                'spliced_on' => pdate('spliced_on', today()), 'notes' => mb_substr(trim((string)p('notes')), 0, 160) ?: null]);
            DB::run("UPDATE isp_cable_cores SET status = 'used' WHERE id IN (:a, :b) AND status = 'free'", ['a' => $in, 'b' => $out]);
            redirect(url($pk, ['seg' => $segId]), 'Splice recorded.', 'success');
        case 'unsplice':
            Access::require($pk, 'delete');
            $s = DB::row('SELECT * FROM isp_splices WHERE id = :s', ['s' => (int)p('splice_id')]) ?? fail('Splice not found.');
            DB::run('DELETE FROM isp_splices WHERE id = :s', ['s' => $s['id']]);
            Audit::log('delete', 'ispnet', 'isp_splice', (int)$s['id'], 'Splice ' . $s['in_core_id'] . '-' . $s['out_core_id'], ['page_key' => $pk]);
            redirect(url($pk, ['seg' => $segId]), 'Splice removed.', 'success');
        default:
            fail('Unknown action.');
    }
});

$mode = (string)g('a');
$nodeView = gint('node');
$segView = gint('seg');
$areas = DB::pairs("SELECT id, CONCAT(name, ' (', area_type, ')') FROM isp_areas WHERE is_active = 1 ORDER BY name");
$branches = IspSetup::branches();
$canEdit = Access::can($pk, 'edit');

// ------------------------------------------------------------------ node form
if ($mode === 'node_new' || ($mode === 'node_edit' && $nodeView)) {
    $n = $nodeView ? IspInfra::node($nodeView) : null;
    Access::require($pk, $n ? 'edit' : 'create');
    $parents = IspInfra::nodeOptions(['olt', 'cabinet', 'splice_closure', 'pole', 'fdp', 'patch_panel', 'switch', 'router']);
    unset($parents[(int)($n['id'] ?? 0)]);
    $feed = IspInfra::feedPorts($n ? (int)$n['upstream_port_id'] : null);
    if ($n) {
        foreach (DB::column('SELECT id FROM isp_net_ports WHERE node_id = :n', ['n' => $n['id']]) as $own) {
            unset($feed[(int)$own]);
        }
    }
    $devices = DB::pairs('SELECT id, name FROM isp_devices ORDER BY name');
    layout_start($n ? 'Edit ' . $n['code'] : 'New network node', ['actions' => btn_link(url($pk, $n ? ['node' => $n['id']] : []), 'Back', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <form method="post" class="card isp-card"><div class="card-body">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_node"><input type="hidden" name="node_id" value="<?= (int)($n['id'] ?? 0) ?>">
      <div class="row g-2">
        <?= select_field('node_type', 'Type', IspInfra::NODE_TYPES, fv('node_type', $n, 'nap'), ['class' => 'col-md-4', 'required' => true]) ?>
        <?= input_field('code', 'Code', fv('code', $n), ['class' => 'col-md-3', 'required' => true, 'extra' => 'maxlength="40" placeholder="NAP-BAG-01"']) ?>
        <?= input_field('name', 'Name', fv('name', $n), ['class' => 'col-md-5', 'required' => true, 'extra' => 'maxlength="120"']) ?>
        <?= select_field('upstream_port_id', 'Fed by the port', $feed, fv('upstream_port_id', $n), ['class' => 'col-md-6', 'blank' => 'None (the head of the network, or not traced)']) ?>
        <?= select_field('parent_id', 'Inside / mounted on', $parents, fv('parent_id', $n), ['class' => 'col-md-6', 'blank' => 'Nothing']) ?>
        <?= select_field('area_id', 'Service area', $areas, fv('area_id', $n), ['class' => 'col-md-4', 'blank' => 'Not set']) ?>
        <?= select_field('branch_id', 'Branch', $branches, fv('branch_id', $n), ['class' => 'col-md-4', 'blank' => 'Not set']) ?>
        <?= select_field('status', 'Status', IspInfra::NODE_STATUSES, fv('status', $n, 'active'), ['class' => 'col-md-4']) ?>
        <?= input_field('latitude', 'Latitude', fv('latitude', $n), ['type' => 'number', 'step' => '0.0000001', 'class' => 'col-md-3', 'extra' => 'min="-90" max="90"']) ?>
        <?= input_field('longitude', 'Longitude', fv('longitude', $n), ['type' => 'number', 'step' => '0.0000001', 'class' => 'col-md-3', 'extra' => 'min="-180" max="180"']) ?>
        <?= input_field('address', 'Location / address', fv('address', $n), ['class' => 'col-md-6', 'extra' => 'maxlength="255"']) ?>
        <?= input_field('vendor', 'Vendor', fv('vendor', $n), ['class' => 'col-md-3', 'extra' => 'maxlength="60"']) ?>
        <?= input_field('model', 'Model', fv('model', $n), ['class' => 'col-md-3', 'extra' => 'maxlength="60"']) ?>
        <?= input_field('serial_no', 'Serial no.', fv('serial_no', $n), ['class' => 'col-md-3', 'extra' => 'maxlength="60"']) ?>
        <?= input_field('installed_on', 'Installed on', fv('installed_on', $n), ['type' => 'date', 'class' => 'col-md-3']) ?>
        <?= input_field('port_count', 'Number of ports', fv('port_count', $n, '0'), ['type' => 'number', 'step' => '1', 'class' => 'col-md-3', 'extra' => 'min="0" max="512"']) ?>
        <?= input_field('split_ratio', 'Split ratio', fv('split_ratio', $n), ['class' => 'col-md-3', 'extra' => 'maxlength="10" placeholder="1:8"']) ?>
        <?= select_field('device_id', 'Managed by the device', $devices, fv('device_id', $n), ['class' => 'col-md-6', 'blank' => 'None']) ?>
        <div class="col-md-6"><label class="form-label">Specifications</label><textarea class="form-control form-control-sm" name="specs" rows="2"><?= h((string)fv('specs', $n)) ?></textarea></div>
        <?= input_field('notes', 'Notes', fv('notes', $n), ['class' => 'col-md-6', 'extra' => 'maxlength="255"']) ?>
      </div>
      <p class="small text-muted mt-2 mb-0">OLTs, cards, splitters, NAPs, FDPs, patch panels, switches, taps and amplifiers get their ports numbered 1 to N. Ports in use are never removed.</p>
      <div class="mt-3"><?= save_buttons('Save', url($pk, $n ? ['node' => $n['id']] : []), !$n) ?></div>
    </div></form>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ segment form
if ($mode === 'seg_new' || ($mode === 'seg_edit' && $segView)) {
    $s = $segView ? DB::row('SELECT * FROM isp_cable_segments WHERE id = :s', ['s' => $segView]) : null;
    Access::require($pk, $s ? 'edit' : 'create');
    $others = DB::pairs('SELECT id, code FROM isp_cable_segments WHERE id <> :s ORDER BY code', ['s' => (int)($s['id'] ?? 0)]);
    $path = str_replace(';', "\n", (string)fv('path', $s));
    layout_start($s ? 'Edit cable segment ' . $s['code'] : 'New cable segment', ['actions' => btn_link(url($pk, $s ? ['seg' => $s['id']] : ['tab' => 'segments']), 'Back', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <form method="post" class="card isp-card"><div class="card-body">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_segment"><input type="hidden" name="segment_id" value="<?= (int)($s['id'] ?? 0) ?>">
      <div class="row g-2">
        <?= input_field('code', 'Code', fv('code', $s), ['class' => 'col-md-3', 'required' => true, 'extra' => 'maxlength="40" placeholder="SEG-001"']) ?>
        <?= select_field('cable_type', 'Cable', IspInfra::CABLE_TYPES, fv('cable_type', $s, 'fiber'), ['class' => 'col-md-3']) ?>
        <?= input_field('core_count', 'Cores', fv('core_count', $s, '12'), ['type' => 'number', 'step' => '1', 'class' => 'col-md-2', 'extra' => 'min="0" max="288"']) ?>
        <?= select_field('status', 'Status', IspInfra::SEGMENT_STATUSES, fv('status', $s, 'active'), ['class' => 'col-md-4']) ?>
        <?= select_field('from_node_id', 'From node', IspInfra::nodeOptions(), fv('from_node_id', $s), ['class' => 'col-md-6', 'blank' => 'Not set']) ?>
        <?= select_field('to_node_id', 'To node', IspInfra::nodeOptions(), fv('to_node_id', $s), ['class' => 'col-md-6', 'blank' => 'Not set']) ?>
        <div class="col-md-6"><label class="form-label">Route (one "latitude, longitude" per line)</label><textarea class="form-control form-control-sm font-monospace" name="path" rows="6" placeholder="17.1490, 121.8890&#10;17.1475, 121.8880"><?= h($path) ?></textarea></div>
        <div class="col-md-6"><div class="row g-2">
          <?= input_field('length_m', 'Length (m)', fv('length_m', $s), ['type' => 'number', 'class' => 'col-6', 'extra' => 'min="0" placeholder="from the route"']) ?>
          <?= input_field('installed_on', 'Installed on', fv('installed_on', $s), ['type' => 'date', 'class' => 'col-6']) ?>
          <?= select_field('replaced_by_id', 'Replaced by (relocation / replacement)', $others, fv('replaced_by_id', $s), ['class' => 'col-12', 'blank' => 'None']) ?>
          <?= input_field('notes', 'Notes', fv('notes', $s), ['class' => 'col-12', 'extra' => 'maxlength="255"']) ?>
        </div><p class="small text-muted mt-2 mb-0">An empty length is measured along the route. A status change is written to the segment's history.</p></div>
      </div>
      <div class="mt-3"><?= save_buttons('Save', url($pk, $s ? ['seg' => $s['id']] : ['tab' => 'segments']), !$s) ?></div>
    </div></form>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ one node
if ($nodeView) {
    $n = DB::row('SELECT n.*, a.name AS area_name, d.name AS device_name, par.code AS parent_code, par.name AS parent_name FROM isp_net_nodes n LEFT JOIN isp_areas a ON a.id = n.area_id
                    LEFT JOIN isp_devices d ON d.id = n.device_id LEFT JOIN isp_net_nodes par ON par.id = n.parent_id WHERE n.id = :n', ['n' => $nodeView]);
    if (!$n) {
        redirect(url($pk), 'Node not found.', 'warning');
    }
    $feedNode = $n['upstream_port_id'] ? DB::row('SELECT n.id, n.code, n.name, p.port_no FROM isp_net_ports p JOIN isp_net_nodes n ON n.id = p.node_id WHERE p.id = :p', ['p' => $n['upstream_port_id']]) : null;
    $ports = DB::all('SELECT p.*, a.account_no, a.account_name, a.service_status, ap.application_no, fed.id AS fed_id, fed.code AS fed_code FROM isp_net_ports p LEFT JOIN isp_accounts a ON a.id = p.account_id
                       LEFT JOIN isp_applications ap ON ap.id = p.application_id LEFT JOIN isp_net_nodes fed ON fed.upstream_port_id = p.id WHERE p.node_id = :n ORDER BY p.port_no', ['n' => $n['id']]);
    $children = DB::all('SELECT n.id, n.code, n.name, n.node_type, n.status FROM isp_net_nodes n WHERE n.parent_id = :i OR n.upstream_port_id IN (SELECT id FROM isp_net_ports WHERE node_id = :i2) ORDER BY n.code',
        ['i' => $n['id'], 'i2' => $n['id']]);
    $affected = IspInfra::affectedAccounts((int)$n['id']);
    $segments = DB::all('SELECT s.*, f.code AS from_code, t.code AS to_code FROM isp_cable_segments s LEFT JOIN isp_net_nodes f ON f.id = s.from_node_id LEFT JOIN isp_net_nodes t ON t.id = s.to_node_id
                          WHERE s.from_node_id = :n OR s.to_node_id = :n2 ORDER BY s.code', ['n' => $n['id'], 'n2' => $n['id']]);
    $events = DB::all('SELECT e.*, u.full_name, w.wo_no FROM isp_node_events e LEFT JOIN users u ON u.id = e.user_id LEFT JOIN isp_work_orders w ON w.id = e.work_order_id WHERE e.node_id = :n ORDER BY e.event_date DESC, e.id DESC LIMIT 30', ['n' => $n['id']]);
    $outage = DB::row("SELECT id, outage_no, status FROM isp_outages WHERE node_id = :n AND status IN ('suspected', 'verified', 'in_repair') ORDER BY id DESC LIMIT 1", ['n' => $n['id']]);
    $counts = array_count_values(array_column($ports, 'status'));
    $actions = btn_link(url($pk), 'Nodes', 'btn-outline-secondary', 'arrow-left');
    if ($canEdit) {
        $actions .= ' ' . btn_link(url($pk, ['a' => 'node_edit', 'node' => $n['id']]), 'Edit', 'btn-outline-primary', 'pencil');
    }
    if (Access::can('ispnet.outages', 'create') && !$outage) {
        $actions .= ' ' . btn_link(url('ispnet.outages', ['a' => 'new', 'node_id' => $n['id']]), 'Declare outage here', 'btn-outline-danger', 'exclamation-triangle');
    }
    if (Access::page('ispnet.map') && $n['latitude'] !== null) {
        $actions .= ' ' . btn_link(url('ispnet.map', ['focus' => 'node:' . $n['id']]), 'Map', 'btn-outline-secondary', 'geo-alt');
    }
    layout_start($n['code'] . ' ' . $n['name'], ['actions' => $actions]);
    if ($outage): ?>
      <div class="alert alert-danger">Outage <?= safe_link('ispnet.outages', ['id' => $outage['id']], $outage['outage_no']) ?> is <?= h(strtolower(IspOutages::STATUSES[$outage['status']])) ?> on this node.</div>
    <?php endif; ?>
    <div class="row g-3 mb-3">
      <div class="col-lg-7"><div class="card isp-card h-100"><div class="card-body">
        <div class="isp-head"><div><h2 class="isp-head-title"><?= h(IspInfra::NODE_TYPES[$n['node_type']] ?? $n['node_type']) ?></h2><div class="isp-head-sub"><?= IspUi::status($n['status'], IspInfra::NODE_STATUSES) ?></div></div></div>
        <?= IspUi::dl([
            'Fed by' => $feedNode ? '<a href="' . h(url($pk, ['node' => $feedNode['id']])) . '">' . h($feedNode['code']) . '</a> port ' . (int)$feedNode['port_no'] : null,
            'Inside / on' => $n['parent_id'] ? '<a href="' . h(url($pk, ['node' => $n['parent_id']])) . '">' . h($n['parent_code'] . ' ' . $n['parent_name']) . '</a>' : null,
            'Area' => $n['area_name'] ? h($n['area_name']) : null,
            'Branch' => $n['branch_id'] ? h($branches[$n['branch_id']] ?? '') : null,
            'Location' => trim(h((string)$n['address']) . ($n['latitude'] !== null ? ' <span class="text-muted">' . h(rtrim(rtrim((string)$n['latitude'], '0'), '.') . ', ' . rtrim(rtrim((string)$n['longitude'], '0'), '.')) . '</span>' : '')) ?: null,
            'Equipment' => trim(h(trim($n['vendor'] . ' ' . $n['model'])) . ($n['serial_no'] ? ' <span class="text-muted">S/N ' . h($n['serial_no']) . '</span>' : '')) ?: null,
            'Split ratio' => $n['split_ratio'] ? h($n['split_ratio']) : null,
            'Device' => $n['device_name'] ? safe_link('ispnet.devices', ['id' => $n['device_id']], $n['device_name']) : null,
            'Installed' => $n['installed_on'] ? h(fdate($n['installed_on'])) : null,
            'Specifications' => $n['specs'] ? nl2br(h($n['specs'])) : null,
            'Notes' => $n['notes'] ? h($n['notes']) : null,
        ]) ?>
      </div></div></div>
      <div class="col-lg-5"><div class="card isp-card h-100"><div class="card-body">
        <h2 class="sec-title">Served from here</h2>
        <?= IspUi::dl(['Subscribers below' => number_format(count($affected)) . ' account(s)', 'Ports' => (int)$n['port_count'] ? h(count($ports) . ' total: ' . (int)($counts['used'] ?? 0) . ' used, ' . (int)($counts['reserved'] ?? 0) . ' reserved, ' . (int)($counts['free'] ?? 0) . ' free, ' . (int)($counts['faulty'] ?? 0) . ' faulty') : 'None']) ?>
        <?php if ($children): ?><div class="form-label mt-2">Nodes below</div>
          <?php foreach ($children as $c): ?><div class="d-flex justify-content-between border-bottom py-1 small"><a href="<?= h(url($pk, ['node' => $c['id']])) ?>"><?= h($c['code'] . ' ' . $c['name']) ?></a><span><?= h(IspInfra::NODE_TYPES[$c['node_type']] ?? $c['node_type']) ?> <?= IspUi::status($c['status'], IspInfra::NODE_STATUSES) ?></span></div><?php endforeach; ?>
        <?php endif; ?>
        <?php if ($segments): ?><div class="form-label mt-2">Cable segments</div>
          <?php foreach ($segments as $s): ?><div class="d-flex justify-content-between border-bottom py-1 small"><a href="<?= h(url($pk, ['seg' => $s['id']])) ?>"><?= h($s['code']) ?></a><span><?= h($s['from_code'] . ' to ' . $s['to_code']) ?>, <?= h(number_format((float)$s['length_m'])) ?> m <?= IspUi::status($s['status'], IspInfra::SEGMENT_STATUSES) ?></span></div><?php endforeach; ?>
        <?php endif; ?>
      </div></div></div>
    </div>

    <?php if ($ports): ?>
    <div class="card isp-card mb-3"><div class="card-body">
      <h2 class="sec-title">Ports</h2>
      <div class="row g-2 row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-xl-6">
      <?php foreach ($ports as $pt): ?>
        <div class="col"><div class="border rounded p-2 h-100 small isp-port">
          <div class="d-flex justify-content-between align-items-center"><strong><?= h(strtoupper((string)$pt['port_type'])) ?> <?= (int)$pt['port_no'] ?></strong><?= IspUi::status($pt['status'], IspInfra::PORT_STATUSES) ?></div>
          <div class="mt-1 text-truncate">
            <?php if ($pt['account_id']): ?><?= IspUi::acct($pt['account_id'], $pt['account_no']) ?> <?= IspUi::status($pt['service_status'], IspAccounts::SERVICE_STATUSES) ?>
            <?php elseif ($pt['fed_id']): ?>Feeds <a href="<?= h(url($pk, ['node' => $pt['fed_id']])) ?>"><?= h($pt['fed_code']) ?></a>
            <?php elseif ($pt['application_no']): ?>Reserved for <?= safe_link('isp.applications', ['id' => $pt['application_id']], $pt['application_no']) ?>
            <?php else: ?><span class="text-muted"><?= h($pt['label'] ?: 'Available') ?></span><?php endif; ?>
          </div>
          <?php if ($canEdit): ?><button type="button" class="btn btn-link btn-sm p-0 mt-1 no-print" data-bs-toggle="modal" data-bs-target="#portM" data-port="<?= (int)$pt['id'] ?>" data-label="<?= h($n['code'] . ' port ' . $pt['port_no']) ?>" data-text="<?= h((string)$pt['label']) ?>">Change</button><?php endif; ?>
        </div></div>
      <?php endforeach; ?>
      </div>
    </div></div>
    <?php endif; ?>

    <div class="card isp-card"><div class="card-body">
      <h2 class="sec-title">Maintenance history</h2>
      <?php if ($canEdit): ?>
      <form method="post" class="row g-2 mb-2 no-print"><?= csrf_field() ?><input type="hidden" name="action" value="event"><input type="hidden" name="node_id" value="<?= (int)$n['id'] ?>">
        <?= select_field('event_type', 'Event', ISPNET_EVENT_TYPES, 'inspected', ['class' => 'col-md-2']) ?>
        <?= input_field('event_date', 'Date', today(), ['type' => 'date', 'class' => 'col-md-2']) ?>
        <?= input_field('remarks', 'What was found / done', '', ['class' => 'col-md-6', 'required' => true, 'extra' => 'maxlength="255"']) ?>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-sm btn-outline-primary w-100">Record</button></div>
      </form>
      <?php endif; ?>
      <?php if (!$events): ?><?= IspUi::empty('Nothing recorded yet.') ?><?php else: ?>
      <div class="table-responsive"><table class="table table-sm align-middle isp-table"><thead><tr><th>Date</th><th>Event</th><th>Remarks</th><th>Work order</th><th>By</th></tr></thead><tbody>
        <?php foreach ($events as $e): ?><tr><td class="text-nowrap small"><?= h(fdate($e['event_date'])) ?></td><td><?= h(ISPNET_EVENT_TYPES[$e['event_type']] ?? ucfirst((string)$e['event_type'])) ?></td><td class="small"><?= h($e['remarks']) ?></td>
          <td class="small"><?= $e['wo_no'] ? safe_link('ispfield.workorders', ['id' => $e['work_order_id']], $e['wo_no']) : '' ?></td><td class="small"><?= h($e['full_name']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div></div>

    <?php if ($canEdit): ?>
    <div class="modal fade" id="portM" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="port"><input type="hidden" name="port_id" value="">
      <div class="modal-header"><h5 class="modal-title">Port <span data-label></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><div class="row g-2">
        <?= select_field('op', 'Do', ['assign' => 'Connect a subscriber to it (moves the subscriber from their old port)', 'label' => 'Change its label', 'faulty' => 'Mark it faulty', 'free' => 'Free it'], 'assign', ['class' => 'col-12', 'extra' => 'id="portOp"']) ?>
        <div class="col-12 p-assign"><div class="row g-2"><?= IspUi::accountField('account_id', null, 'Subscriber account', 'col-12', false) ?></div></div>
        <?= input_field('label', 'Label / reason', '', ['class' => 'col-12 p-label', 'extra' => 'maxlength="60" data-text']) ?>
      </div><p class="small text-muted mt-2 mb-0">A port serves one subscriber only; a port that serves an active subscriber cannot be freed or marked faulty.</p></div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button class="btn btn-primary">Save</button></div>
    </form></div></div>
    <script>
    (function () {
      var m = document.getElementById('portM'), op = document.getElementById('portOp');
      function show() { m.querySelectorAll('.p-assign').forEach(function (x) { x.hidden = op.value !== 'assign'; }); m.querySelectorAll('.p-label').forEach(function (x) { x.hidden = op.value === 'assign' || op.value === 'free'; }); }
      op.addEventListener('change', show);
      m.addEventListener('show.bs.modal', function (e) {
        var b = e.relatedTarget;
        m.querySelector('input[name=port_id]').value = b.getAttribute('data-port');
        m.querySelector('[data-label]').textContent = b.getAttribute('data-label');
        m.querySelector('[data-text]').value = b.getAttribute('data-text') || '';
        show();
      });
    })();
    </script>
    <?php endif; ?>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ one segment
if ($segView) {
    $s = DB::row('SELECT s.*, f.code AS from_code, f.name AS from_name, t.code AS to_code, t.name AS to_name, r.code AS repl_code FROM isp_cable_segments s LEFT JOIN isp_net_nodes f ON f.id = s.from_node_id
                    LEFT JOIN isp_net_nodes t ON t.id = s.to_node_id LEFT JOIN isp_cable_segments r ON r.id = s.replaced_by_id WHERE s.id = :s', ['s' => $segView]);
    if (!$s) {
        redirect(url($pk, ['tab' => 'segments']), 'Cable segment not found.', 'warning');
    }
    $cores = DB::all('SELECT * FROM isp_cable_cores WHERE segment_id = :s ORDER BY core_no', ['s' => $s['id']]);
    $splices = DB::all('SELECT sp.*, cl.code AS closure_code, ci.core_no AS in_no, si.code AS in_seg, co.core_no AS out_no, so.code AS out_seg, ci.segment_id AS in_seg_id, co.segment_id AS out_seg_id
                          FROM isp_splices sp JOIN isp_net_nodes cl ON cl.id = sp.closure_id JOIN isp_cable_cores ci ON ci.id = sp.in_core_id JOIN isp_cable_segments si ON si.id = ci.segment_id
                          JOIN isp_cable_cores co ON co.id = sp.out_core_id JOIN isp_cable_segments so ON so.id = co.segment_id
                         WHERE ci.segment_id = :s OR co.segment_id = :s2 ORDER BY sp.id', ['s' => $s['id'], 's2' => $s['id']]);
    $events = DB::all('SELECT e.*, u.full_name FROM isp_node_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.segment_id = :s ORDER BY e.event_date DESC, e.id DESC LIMIT 30', ['s' => $s['id']]);
    $replaces = DB::all('SELECT id, code, status FROM isp_cable_segments WHERE replaced_by_id = :s', ['s' => $s['id']]);
    $closures = IspInfra::nodeOptions(['splice_closure', 'fdp', 'cabinet', 'patch_panel']);
    $otherCores = DB::pairs("SELECT c.id, CONCAT(s.code, ' core ', c.core_no, ' ', COALESCE(c.color, '')) FROM isp_cable_cores c JOIN isp_cable_segments s ON s.id = c.segment_id
                              WHERE c.segment_id <> :s AND c.id NOT IN (SELECT in_core_id FROM isp_splices) AND c.id NOT IN (SELECT out_core_id FROM isp_splices) ORDER BY s.code, c.core_no", ['s' => $s['id']]);
    $ownFree = DB::pairs("SELECT id, CONCAT('Core ', core_no, ' ', COALESCE(color, '')) FROM isp_cable_cores WHERE segment_id = :s AND id NOT IN (SELECT in_core_id FROM isp_splices) AND id NOT IN (SELECT out_core_id FROM isp_splices) ORDER BY core_no", ['s' => $s['id']]);
    $pts = $s['path'] ? count(explode(';', (string)$s['path'])) : 0;
    $actions = btn_link(url($pk, ['tab' => 'segments']), 'Cable segments', 'btn-outline-secondary', 'arrow-left');
    if ($canEdit) {
        $actions .= ' ' . btn_link(url($pk, ['a' => 'seg_edit', 'seg' => $s['id']]), 'Edit', 'btn-outline-primary', 'pencil');
    }
    if (Access::page('ispnet.map') && $pts) {
        $actions .= ' ' . btn_link(url('ispnet.map', ['focus' => 'seg:' . $s['id']]), 'Map', 'btn-outline-secondary', 'geo-alt');
    }
    layout_start('Cable segment ' . $s['code'], ['actions' => $actions]);
    ?>
    <div class="card isp-card mb-3"><div class="card-body">
      <div class="isp-head"><div><h2 class="isp-head-title"><?= h(IspInfra::CABLE_TYPES[$s['cable_type']] ?? $s['cable_type']) ?>, <?= (int)$s['core_count'] ?> cores</h2><div class="isp-head-sub"><?= IspUi::status($s['status'], IspInfra::SEGMENT_STATUSES) ?></div></div></div>
      <?= IspUi::dl([
          'From' => $s['from_node_id'] ? '<a href="' . h(url($pk, ['node' => $s['from_node_id']])) . '">' . h($s['from_code'] . ' ' . $s['from_name']) . '</a>' : null,
          'To' => $s['to_node_id'] ? '<a href="' . h(url($pk, ['node' => $s['to_node_id']])) . '">' . h($s['to_code'] . ' ' . $s['to_name']) . '</a>' : null,
          'Length' => $s['length_m'] !== null ? h(number_format((float)$s['length_m'], 1) . ' m') . ($pts ? ' <span class="text-muted">route of ' . $pts . ' points</span>' : '') : null,
          'Installed' => $s['installed_on'] ? h(fdate($s['installed_on'])) : null,
          'Replaced by' => $s['replaced_by_id'] ? '<a href="' . h(url($pk, ['seg' => $s['replaced_by_id']])) . '">' . h($s['repl_code']) . '</a>' : null,
          'Replaces' => $replaces ? implode(', ', array_map(fn($r) => '<a href="' . h(url($pk, ['seg' => $r['id']])) . '">' . h($r['code']) . '</a>', $replaces)) : null,
          'Notes' => $s['notes'] ? h($s['notes']) : null,
      ]) ?>
    </div></div>
    <div class="row g-3 mb-3">
      <div class="col-lg-7"><div class="card isp-card h-100"><div class="card-body">
        <h2 class="sec-title">Cores</h2>
        <?php if (!$cores): ?><?= IspUi::empty('This cable has no cores recorded.') ?><?php else: ?>
        <div class="table-responsive"><table class="table table-sm align-middle isp-table"><thead><tr><th>Core</th><th>Color</th><th>Status</th><th>Carries</th><th class="no-print"></th></tr></thead><tbody>
          <?php foreach ($cores as $c): ?>
          <tr><?php if ($canEdit): ?><td><?= (int)$c['core_no'] ?></td><td class="small"><?= h($c['color']) ?></td>
            <td colspan="3"><form method="post" class="d-flex gap-1 flex-wrap"><?= csrf_field() ?><input type="hidden" name="action" value="core"><input type="hidden" name="core_id" value="<?= (int)$c['id'] ?>">
              <select class="form-select form-select-sm w-auto" name="status" aria-label="Status of core <?= (int)$c['core_no'] ?>"><?= options(ISPNET_CORE_STATUSES, $c['status']) ?></select>
              <input class="form-control form-control-sm flex-grow-1 w-auto" name="assignment" value="<?= h($c['assignment']) ?>" maxlength="160" placeholder="PON 1/1/3, backbone ..." aria-label="What core <?= (int)$c['core_no'] ?> carries">
              <button class="btn btn-sm btn-outline-secondary">Save</button></form></td>
            <?php else: ?><td><?= (int)$c['core_no'] ?></td><td class="small"><?= h($c['color']) ?></td><td><?= IspUi::status($c['status'], ISPNET_CORE_STATUSES) ?></td><td class="small"><?= h($c['assignment']) ?></td><td></td><?php endif; ?></tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
      </div></div></div>
      <div class="col-lg-5"><div class="card isp-card h-100"><div class="card-body">
        <h2 class="sec-title">Splices</h2>
        <?php if (!$splices): ?><p class="small text-muted">No core of this cable is spliced yet.</p><?php endif; ?>
        <?php foreach ($splices as $sp): ?>
          <div class="d-flex justify-content-between align-items-center border-bottom py-1 small gap-2">
            <span><a href="<?= h(url($pk, ['seg' => $sp['in_seg_id']])) ?>"><?= h($sp['in_seg']) ?></a> core <?= (int)$sp['in_no'] ?> to <a href="<?= h(url($pk, ['seg' => $sp['out_seg_id']])) ?>"><?= h($sp['out_seg']) ?></a> core <?= (int)$sp['out_no'] ?>
              <span class="text-muted">at <?= h($sp['closure_code']) ?><?= $sp['loss_db'] !== null ? ', ' . h(number_format((float)$sp['loss_db'], 3)) . ' dB' : '' ?><?= $sp['spliced_on'] ? ', ' . h(fdate($sp['spliced_on'])) : '' ?></span></span>
            <?php if (Access::can($pk, 'delete')): ?><?= IspUi::actionForm('unsplice', 'Remove', ['splice_id' => $sp['id'], 'segment_id' => $s['id']], 'btn-outline-secondary', '', 'Remove this splice?') ?><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if ($canEdit && $ownFree && $otherCores && $closures): ?>
        <form method="post" class="row g-2 mt-1"><?= csrf_field() ?><input type="hidden" name="action" value="splice"><input type="hidden" name="segment_id" value="<?= (int)$s['id'] ?>">
          <?= select_field('closure_id', 'At the closure', $closures, '', ['class' => 'col-12', 'blank' => 'Choose']) ?>
          <?= select_field('in_core_id', 'Core of this cable', $ownFree, '', ['class' => 'col-6']) ?>
          <?= select_field('out_core_id', 'Spliced to', $otherCores, '', ['class' => 'col-6']) ?>
          <?= input_field('loss_db', 'Loss (dB)', '', ['type' => 'number', 'step' => '0.001', 'class' => 'col-4']) ?>
          <?= input_field('spliced_on', 'Date', today(), ['type' => 'date', 'class' => 'col-4']) ?>
          <div class="col-4 d-flex align-items-end"><button class="btn btn-sm btn-outline-primary w-100">Add splice</button></div>
        </form>
        <?php endif; ?>
      </div></div></div>
    </div>
    <div class="card isp-card"><div class="card-body">
      <h2 class="sec-title">History</h2>
      <?php if ($canEdit): ?>
      <form method="post" class="row g-2 mb-2 no-print"><?= csrf_field() ?><input type="hidden" name="action" value="event"><input type="hidden" name="segment_id" value="<?= (int)$s['id'] ?>">
        <?= select_field('event_type', 'Event', ISPNET_EVENT_TYPES, 'inspected', ['class' => 'col-md-2']) ?>
        <?= input_field('event_date', 'Date', today(), ['type' => 'date', 'class' => 'col-md-2']) ?>
        <?= input_field('remarks', 'What was found / done', '', ['class' => 'col-md-6', 'required' => true, 'extra' => 'maxlength="255"']) ?>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-sm btn-outline-primary w-100">Record</button></div>
      </form>
      <?php endif; ?>
      <?php if (!$events): ?><?= IspUi::empty('Nothing recorded yet.') ?><?php else: ?>
      <div class="table-responsive"><table class="table table-sm align-middle isp-table"><thead><tr><th>Date</th><th>Event</th><th>Remarks</th><th>By</th></tr></thead><tbody>
        <?php foreach ($events as $e): ?><tr><td class="small text-nowrap"><?= h(fdate($e['event_date'])) ?></td><td><?= h(ISPNET_EVENT_TYPES[$e['event_type']] ?? ucfirst((string)$e['event_type'])) ?></td><td class="small"><?= h($e['remarks']) ?></td><td class="small"><?= h($e['full_name']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div></div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ the lists
$tabs = ['nodes' => 'Nodes', 'segments' => 'Cable segments', 'capacity' => 'Capacity', 'events' => 'Maintenance history', 'trace' => 'Trace a subscriber'];
$tab = (string)g('tab', 'nodes');
if (!isset($tabs[$tab])) {
    $tab = 'nodes';
}
$qtext = trim((string)g('q'));
$type = (string)g('type');
$status = (string)g('status');
$areaId = gint('area_id');
$actions = '';
if (Access::can($pk, 'create')) {
    $actions = btn_link(url($pk, ['a' => 'node_new']), 'New node', 'btn-primary', 'plus-lg') . ' ' . btn_link(url($pk, ['a' => 'seg_new']), 'New cable segment', 'btn-outline-primary', 'bezier2');
}
if (Access::page('ispnet.map')) {
    $actions .= ' ' . btn_link(url('ispnet.map'), 'Network map', 'btn-outline-secondary', 'map');
}
layout_start('Infrastructure & Ports', ['actions' => $actions]);
$tot = DB::row("SELECT COUNT(*) AS n, SUM(status = 'damaged') AS dmg, SUM(status = 'maintenance') AS mnt FROM isp_net_nodes");
$ptot = DB::row("SELECT COUNT(*) AS n, SUM(p.status = 'free') AS free, SUM(p.status = 'used') AS used FROM isp_net_ports p JOIN isp_net_nodes n ON n.id = p.node_id WHERE n.node_type = 'nap'");
$stot = DB::row("SELECT COUNT(*) AS n, COALESCE(SUM(length_m), 0) AS len, SUM(status IN ('damaged', 'cut')) AS bad FROM isp_cable_segments WHERE status <> 'retired'");
?>
<div class="kpi-grid mb-3">
  <?= IspUi::kpi('Network nodes', number_format((int)$tot['n']), (int)$tot['dmg'] . ' damaged, ' . (int)$tot['mnt'] . ' under maintenance', url($pk, ['tab' => 'nodes']), 'diagram-3', (int)$tot['dmg'] ? 'warn' : 'info') ?>
  <?= IspUi::kpi('Free drop ports', number_format((int)$ptot['free']), 'of ' . number_format((int)$ptot['n']) . ' NAP ports', url($pk, ['tab' => 'capacity', 'type' => 'nap']), 'ethernet', 'ok') ?>
  <?= IspUi::kpi('Cable in service', number_format((float)$stot['len'] / 1000, 2) . ' km', number_format((int)$stot['n']) . ' segments', url($pk, ['tab' => 'segments']), 'bezier2', 'info') ?>
  <?= IspUi::kpi('Damaged or cut', number_format((int)$stot['bad']), 'cable segments', url($pk, ['tab' => 'segments', 'status' => 'damaged']), 'scissors', (int)$stot['bad'] ? 'bad' : 'ok') ?>
</div>
<?= IspUi::tabs($tabs, $tab, $pk) ?>
<?php
if ($tab === 'nodes'):
    $params = [];
    $where = ' WHERE 1 = 1';
    $filtered = $qtext !== '' || $type !== '' || $status !== '' || $areaId;
    if ($qtext !== '') {
        $where .= ' AND (n.code LIKE :q1 OR n.name LIKE :q2 OR n.serial_no LIKE :q3 OR n.address LIKE :q4)';
        $params += ['q1' => '%' . $qtext . '%', 'q2' => '%' . $qtext . '%', 'q3' => $qtext . '%', 'q4' => '%' . $qtext . '%'];
    }
    if (isset(IspInfra::NODE_TYPES[$type])) {
        $where .= ' AND n.node_type = :t';
        $params['t'] = $type;
    }
    if (isset(IspInfra::NODE_STATUSES[$status])) {
        $where .= ' AND n.status = :s';
        $params['s'] = $status;
    }
    if ($areaId) {
        $where .= ' AND n.area_id = :ar';
        $params['ar'] = $areaId;
    }
    $rows = DB::all("SELECT n.*, a.name AS area_name, up.node_id AS up_node, (SELECT COUNT(*) FROM isp_net_ports p WHERE p.node_id = n.id) AS ports,
                            (SELECT COUNT(*) FROM isp_net_ports p WHERE p.node_id = n.id AND p.status = 'used') AS used,
                            (SELECT COUNT(*) FROM isp_net_ports p WHERE p.node_id = n.id AND p.account_id IS NOT NULL) AS subs
                       FROM isp_net_nodes n LEFT JOIN isp_areas a ON a.id = n.area_id LEFT JOIN isp_net_ports up ON up.id = n.upstream_port_id$where ORDER BY n.code LIMIT 2000", $params);
    // the tree: each node under the node that feeds it (or that it sits in)
    $ordered = [];
    if (!$filtered) {
        $byId = [];
        $kids = [];
        foreach ($rows as $r) {
            $byId[(int)$r['id']] = $r;
        }
        foreach ($rows as $r) {
            $parent = (int)($r['up_node'] ?: $r['parent_id']);
            $kids[isset($byId[$parent]) ? $parent : 0][] = (int)$r['id'];
        }
        $walk = function (int $pid, int $depth) use (&$walk, &$kids, &$byId, &$ordered) {
            foreach ($kids[$pid] ?? [] as $cid) {
                if (isset($ordered[$cid])) {
                    continue;
                }
                $ordered[$cid] = $byId[$cid] + ['_depth' => $depth];
                $walk($cid, $depth + 1);
            }
        };
        $walk(0, 0);
        foreach ($byId as $id => $r) {
            $ordered[$id] ??= $r + ['_depth' => 0];
        }
    } else {
        foreach ($rows as $r) {
            $ordered[(int)$r['id']] = $r + ['_depth' => 0];
        }
    }
    echo IspUi::filterStart($pk, ['tab' => $tab]) . IspUi::fSearch($qtext, 'Code, name, serial or address') . IspUi::fSelect('type', 'Type', IspInfra::NODE_TYPES, $type)
        . IspUi::fSelect('status', 'Status', IspInfra::NODE_STATUSES, $status) . IspUi::fSelect('area_id', 'Area', $areas, $areaId) . IspUi::filterEnd(url($pk));
    ?>
    <div class="card isp-card"><div class="card-body">
    <?php if (!$ordered): ?><?= IspUi::empty('No network node yet. Start with the OLT, then the splitters and NAPs it feeds.', 'diagram-3') ?><?php else: ?>
    <?php if (!$filtered): ?><p class="small text-muted">Each node is shown under the node that feeds it.</p><?php endif; ?>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
      <thead><tr><th>Node</th><th>Type</th><th>Area</th><th>Status</th><th class="num">Ports used</th><th class="num">Subscribers</th><th>Installed</th></tr></thead><tbody>
      <?php foreach ($ordered as $r): ?>
        <tr>
          <td><span style="padding-left:<?= min(8, (int)$r['_depth']) * 1.1 ?>rem"><?= (int)$r['_depth'] ? '<i class="bi bi-arrow-return-right text-muted"></i> ' : '' ?><a href="<?= h(url($pk, ['node' => $r['id']])) ?>"><?= h($r['code']) ?></a> <span class="text-muted"><?= h($r['name']) ?></span></span></td>
          <td class="small"><?= h(IspInfra::NODE_TYPES[$r['node_type']] ?? $r['node_type']) ?><?= $r['split_ratio'] ? ' ' . h($r['split_ratio']) : '' ?></td>
          <td class="small"><?= h($r['area_name']) ?></td>
          <td><?= IspUi::status($r['status'], IspInfra::NODE_STATUSES) ?></td>
          <td class="num"><?= (int)$r['ports'] ? (int)$r['used'] . ' / ' . (int)$r['ports'] : '' ?></td>
          <td class="num"><?= (int)$r['subs'] ?: '' ?></td>
          <td class="small"><?= h(fdate($r['installed_on'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
    </div></div>
<?php elseif ($tab === 'segments'):
    $params = [];
    $where = ' WHERE 1 = 1';
    if ($qtext !== '') {
        $where .= ' AND (s.code LIKE :q1 OR f.code LIKE :q2 OR t.code LIKE :q3)';
        $params += ['q1' => '%' . $qtext . '%', 'q2' => $qtext . '%', 'q3' => $qtext . '%'];
    }
    if ($status === 'damaged') {
        $where .= " AND s.status IN ('damaged', 'cut')";
    } elseif (isset(IspInfra::SEGMENT_STATUSES[$status])) {
        $where .= ' AND s.status = :s';
        $params['s'] = $status;
    }
    if (isset(IspInfra::CABLE_TYPES[$type])) {
        $where .= ' AND s.cable_type = :t';
        $params['t'] = $type;
    }
    [$per, $off, $page] = IspUi::paging(50);
    $total = (int)DB::value("SELECT COUNT(*) FROM isp_cable_segments s LEFT JOIN isp_net_nodes f ON f.id = s.from_node_id LEFT JOIN isp_net_nodes t ON t.id = s.to_node_id$where", $params);
    $rows = DB::all("SELECT s.*, f.code AS from_code, t.code AS to_code, (SELECT COUNT(*) FROM isp_cable_cores c WHERE c.segment_id = s.id AND c.status = 'used') AS used_cores
                       FROM isp_cable_segments s LEFT JOIN isp_net_nodes f ON f.id = s.from_node_id LEFT JOIN isp_net_nodes t ON t.id = s.to_node_id$where ORDER BY s.code LIMIT $per OFFSET $off", $params);
    echo IspUi::filterStart($pk, ['tab' => $tab]) . IspUi::fSearch($qtext, 'Segment or node code') . IspUi::fSelect('type', 'Cable', IspInfra::CABLE_TYPES, $type)
        . IspUi::fSelect('status', 'Status', IspInfra::SEGMENT_STATUSES, $status) . IspUi::filterEnd(url($pk, ['tab' => $tab]));
    ?>
    <div class="card isp-card"><div class="card-body">
    <?php if (!$rows): ?><?= IspUi::empty('No cable segment matches.', 'bezier2') ?><?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
      <thead><tr><th>Segment</th><th>Cable</th><th>From</th><th>To</th><th class="num">Length (m)</th><th class="num">Cores used</th><th>Status</th><th>Installed</th></tr></thead><tbody>
      <?php foreach ($rows as $s): ?>
        <tr><td><a href="<?= h(url($pk, ['seg' => $s['id']])) ?>"><?= h($s['code']) ?></a></td><td class="small"><?= h(IspInfra::CABLE_TYPES[$s['cable_type']] ?? $s['cable_type']) ?></td>
          <td class="small"><?= $s['from_node_id'] ? '<a href="' . h(url($pk, ['node' => $s['from_node_id']])) . '">' . h($s['from_code']) . '</a>' : '' ?></td>
          <td class="small"><?= $s['to_node_id'] ? '<a href="' . h(url($pk, ['node' => $s['to_node_id']])) . '">' . h($s['to_code']) . '</a>' : '' ?></td>
          <td class="num"><?= $s['length_m'] !== null ? h(number_format((float)$s['length_m'], 1)) : '' ?></td><td class="num"><?= (int)$s['used_cores'] ?> / <?= (int)$s['core_count'] ?></td>
          <td><?= IspUi::status($s['status'], IspInfra::SEGMENT_STATUSES) ?></td><td class="small"><?= h(fdate($s['installed_on'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?= IspUi::pager($total, $per, $page) ?>
    <?php endif; ?>
    </div></div>
<?php elseif ($tab === 'capacity'):
    $cap = IspInfra::capacity(isset(IspInfra::NODE_TYPES[$type]) ? $type : null);
    echo IspUi::filterStart($pk, ['tab' => $tab]) . IspUi::fSelect('type', 'Type', array_intersect_key(IspInfra::NODE_TYPES, IspInfra::PORT_TYPE), $type) . IspUi::filterEnd(url($pk, ['tab' => $tab]));
    ?>
    <div class="card isp-card"><div class="card-body">
    <?php if (!$cap): ?><?= IspUi::empty('No node with ports yet.', 'ethernet') ?><?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
      <thead><tr><th>Node</th><th>Type</th><th>Area</th><th class="num">Ports</th><th class="num">Used</th><th class="num">Reserved</th><th class="num">Free</th><th class="num">Faulty</th><th style="min-width:9rem">Utilization</th></tr></thead><tbody>
      <?php foreach ($cap as $c): $u = (float)$c['utilization']; ?>
        <tr><td><a href="<?= h(url($pk, ['node' => $c['id']])) ?>"><?= h($c['code']) ?></a> <span class="text-muted small"><?= h($c['name']) ?></span></td>
          <td class="small"><?= h(IspInfra::NODE_TYPES[$c['node_type']] ?? $c['node_type']) ?><?= $c['split_ratio'] ? ' ' . h($c['split_ratio']) : '' ?></td><td class="small"><?= h($c['area']) ?></td>
          <td class="num"><?= (int)$c['total'] ?></td><td class="num"><?= (int)$c['used'] ?></td><td class="num"><?= (int)$c['reserved'] ?></td><td class="num"><?= (int)$c['free'] ?></td><td class="num"><?= (int)$c['faulty'] ?: '' ?></td>
          <td><div class="d-flex align-items-center gap-2"><div class="progress flex-grow-1" style="height:.5rem" role="progressbar" aria-valuenow="<?= h((string)$u) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Utilization of <?= h($c['code']) ?>">
            <div class="progress-bar <?= $u >= 90 ? 'bg-danger' : ($u >= 75 ? 'bg-warning' : 'bg-success') ?>" style="width:<?= h((string)$u) ?>%"></div></div><span class="small"><?= h(number_format($u, 0)) ?>%</span></div></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <p class="small text-muted mb-0">Reserved ports count as taken. Nodes at 90% or more need a new splitter or NAP soon.</p>
    <?php endif; ?>
    </div></div>
<?php elseif ($tab === 'events'):
    [$per, $off, $page] = IspUi::paging(50);
    $total = (int)DB::value('SELECT COUNT(*) FROM isp_node_events');
    $rows = DB::all('SELECT e.*, n.code AS node_code, s.code AS seg_code, u.full_name FROM isp_node_events e LEFT JOIN isp_net_nodes n ON n.id = e.node_id LEFT JOIN isp_cable_segments s ON s.id = e.segment_id
                      LEFT JOIN users u ON u.id = e.user_id ORDER BY e.event_date DESC, e.id DESC LIMIT ' . $per . ' OFFSET ' . $off);
    ?>
    <div class="card isp-card"><div class="card-body">
    <?php if (!$rows): ?><?= IspUi::empty('Nothing recorded yet.') ?><?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table"><thead><tr><th>Date</th><th>Asset</th><th>Event</th><th>Remarks</th><th>By</th></tr></thead><tbody>
      <?php foreach ($rows as $e): ?><tr><td class="small text-nowrap"><?= h(fdate($e['event_date'])) ?></td>
        <td><?= $e['node_id'] ? '<a href="' . h(url($pk, ['node' => $e['node_id']])) . '">' . h($e['node_code']) . '</a>' : ($e['segment_id'] ? '<a href="' . h(url($pk, ['seg' => $e['segment_id']])) . '">' . h($e['seg_code']) . '</a>' : '') ?></td>
        <td><?= h(ISPNET_EVENT_TYPES[$e['event_type']] ?? ucfirst((string)$e['event_type'])) ?></td><td class="small"><?= h($e['remarks']) ?></td><td class="small"><?= h($e['full_name']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?= IspUi::pager($total, $per, $page) ?>
    <?php endif; ?>
    </div></div>
<?php else:
    $acct = IspUi::postedAccount('account_id');
    $chain = $acct ? IspInfra::trace($acct) : [];
    $a = $acct ? IspAccounts::get($acct) : null;
    ?>
    <form method="get" action="<?= h(base_url() . '/index.php') ?>" class="card isp-card mb-3 no-print"><div class="card-body"><input type="hidden" name="p" value="<?= h($pk) ?>"><input type="hidden" name="tab" value="trace">
      <div class="row g-2"><?= IspUi::accountField('account_id', $acct, 'Subscriber account', 'col-md-8') ?><div class="col-md-2 d-flex align-items-end"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-signpost-split"></i> Trace</button></div></div>
    </div></form>
    <?php if ($a): ?>
    <div class="card isp-card"><div class="card-body">
      <h2 class="sec-title"><?= IspUi::acct($a['id'], $a['account_no'], $a['account_name']) ?></h2>
      <?php if (!$chain): ?><?= IspUi::empty('This account has no drop port recorded, so it cannot be traced. Set its port from the NAP\'s page.', 'signpost-split') ?><?php else: ?>
      <ol class="list-group list-group-numbered">
        <li class="list-group-item small"><strong>Subscriber</strong> <?= IspUi::sensitive($a['install_address']) ?> <?= IspUi::status($a['service_status'], IspAccounts::SERVICE_STATUSES) ?></li>
        <?php foreach ($chain as $step): $nd = $step['node']; ?>
          <li class="list-group-item small d-flex justify-content-between flex-wrap gap-2"><span><a href="<?= h(url($pk, ['node' => $nd['id']])) ?>"><?= h($nd['code']) ?></a> <?= h($nd['name']) ?>
            <span class="text-muted"><?= h(IspInfra::NODE_TYPES[$nd['node_type']] ?? $nd['node_type']) ?><?= $step['port'] ? ', port ' . (int)$step['port']['port_no'] : '' ?></span></span><?= IspUi::status($nd['status'], IspInfra::NODE_STATUSES) ?></li>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>
    </div></div>
    <?php endif; ?>
<?php endif; ?>
<?php
layout_end();
