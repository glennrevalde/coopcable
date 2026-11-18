<?php
declare(strict_types=1);

/**
 * Network Map: the service areas, network nodes, cable routes and subscriber locations on a map drawn in the browser
 * (SVG, no outside library). Pan and zoom with the mouse or the fingers; layers can be switched off; a click shows the
 * record with its links; a subscriber can be traced to its NAP, splitter and OLT. Tiles of a map provider are drawn
 * behind it only when the setting "Map tile server" is filled in; offices without geographic data use the tables of
 * Infrastructure & Ports instead.
 */
require_once __DIR__ . '/_common.php';

$pk = 'ispnet.map';
$a = (string)g('a');

if ($a === 'data') {
    $ll = fn($v) => $v === null ? null : round((float)$v, 7);
    $nodes = [];
    foreach (DB::all('SELECT n.id, n.code, n.name, n.node_type, n.status, n.latitude, n.longitude, n.split_ratio, a.name AS area,
                             (SELECT COUNT(*) FROM isp_net_ports p WHERE p.node_id = n.id) AS ports, (SELECT COUNT(*) FROM isp_net_ports p WHERE p.node_id = n.id AND p.status = \'free\') AS free_ports,
                             (SELECT COUNT(*) FROM isp_net_ports p WHERE p.node_id = n.id AND p.account_id IS NOT NULL) AS subs
                        FROM isp_net_nodes n LEFT JOIN isp_areas a ON a.id = n.area_id WHERE n.latitude IS NOT NULL AND n.longitude IS NOT NULL AND n.status <> \'retired\'') as $n) {
        $nodes[] = ['id' => (int)$n['id'], 'code' => $n['code'], 'name' => $n['name'], 'type' => $n['node_type'], 'typeLabel' => IspInfra::NODE_TYPES[$n['node_type']] ?? $n['node_type'],
            'status' => $n['status'], 'statusLabel' => IspInfra::NODE_STATUSES[$n['status']] ?? $n['status'], 'lat' => $ll($n['latitude']), 'lng' => $ll($n['longitude']), 'area' => (string)$n['area'],
            'split' => (string)$n['split_ratio'], 'ports' => (int)$n['ports'], 'free' => (int)$n['free_ports'], 'subs' => (int)$n['subs']];
    }
    $segs = [];
    foreach (DB::all("SELECT s.id, s.code, s.cable_type, s.core_count, s.status, s.path, s.length_m, s.from_node_id, s.to_node_id, f.code AS from_code, t.code AS to_code,
                             f.latitude AS flat, f.longitude AS flng, t.latitude AS tlat, t.longitude AS tlng
                        FROM isp_cable_segments s LEFT JOIN isp_net_nodes f ON f.id = s.from_node_id LEFT JOIN isp_net_nodes t ON t.id = s.to_node_id WHERE s.status <> 'retired'") as $s) {
        $pts = [];
        foreach (explode(';', (string)$s['path']) as $p) {
            $xy = explode(',', $p);
            if (count($xy) === 2 && is_numeric($xy[0]) && is_numeric($xy[1])) {
                $pts[] = [round((float)$xy[0], 7), round((float)$xy[1], 7)];
            }
        }
        if (count($pts) < 2 && $s['flat'] !== null && $s['tlat'] !== null) {
            $pts = [[$ll($s['flat']), $ll($s['flng'])], [$ll($s['tlat']), $ll($s['tlng'])]];
        }
        if (count($pts) < 2) {
            continue;
        }
        $segs[] = ['id' => (int)$s['id'], 'code' => $s['code'], 'type' => $s['cable_type'], 'typeLabel' => IspInfra::CABLE_TYPES[$s['cable_type']] ?? $s['cable_type'], 'cores' => (int)$s['core_count'],
            'status' => $s['status'], 'statusLabel' => IspInfra::SEGMENT_STATUSES[$s['status']] ?? $s['status'], 'pts' => $pts, 'length' => $s['length_m'] !== null ? (float)$s['length_m'] : null,
            'from' => $s['from_node_id'] ? (int)$s['from_node_id'] : null, 'to' => $s['to_node_id'] ? (int)$s['to_node_id'] : null, 'fromCode' => (string)$s['from_code'], 'toCode' => (string)$s['to_code']];
    }
    $params = [];
    $b = IspSetup::branchSql('a.branch_id', $params);
    $subs = [];
    foreach (DB::all("SELECT a.id, a.account_no, a.account_name, a.service_status, a.network_state, a.latitude, a.longitude, a.install_address, p.name AS plan, pt.node_id, n.code AS nap
                        FROM isp_accounts a JOIN isp_plans p ON p.id = a.plan_id LEFT JOIN isp_net_ports pt ON pt.id = a.port_id LEFT JOIN isp_net_nodes n ON n.id = pt.node_id
                       WHERE a.latitude IS NOT NULL AND a.longitude IS NOT NULL AND a.service_status IN ('pending', 'active', 'suspended', 'disconnected')$b LIMIT 20000", $params) as $s) {
        $subs[] = ['id' => (int)$s['id'], 'no' => $s['account_no'], 'name' => $s['account_name'], 'status' => $s['service_status'], 'statusLabel' => IspAccounts::SERVICE_STATUSES[$s['service_status']] ?? $s['service_status'],
            'net' => IspAccounts::NETWORK_STATES[$s['network_state']] ?? $s['network_state'], 'lat' => $ll($s['latitude']), 'lng' => $ll($s['longitude']), 'plan' => (string)$s['plan'],
            'address' => Access::special('isp_view_sensitive') || Access::special('coop_view_confidential') ? (string)$s['install_address'] : Party::mask((string)$s['install_address']),
            'nap' => (string)$s['nap'], 'napId' => $s['node_id'] ? (int)$s['node_id'] : null];
    }
    $areas = [];
    foreach (DB::all('SELECT id, name, area_type, latitude, longitude, is_serviceable FROM isp_areas WHERE is_active = 1 AND latitude IS NOT NULL AND longitude IS NOT NULL') as $ar) {
        $areas[] = ['id' => (int)$ar['id'], 'name' => $ar['name'], 'type' => $ar['area_type'], 'lat' => $ll($ar['latitude']), 'lng' => $ll($ar['longitude']), 'serviceable' => (int)$ar['is_serviceable'] === 1];
    }
    $outNodes = array_map('intval', DB::column("SELECT node_id FROM isp_outages WHERE node_id IS NOT NULL AND status IN ('suspected', 'verified', 'in_repair')"));
    json_out(['nodes' => $nodes, 'segments' => $segs, 'subscribers' => $subs, 'areas' => $areas, 'outageNodes' => $outNodes]);
}

if ($a === 'trace') {
    $acct = (int)g('account');
    if (!$acct || !ispnet_account_visible($acct)) {
        json_out(['nodes' => []]);
    }
    json_out(['nodes' => array_values(array_map(fn($s) => (int)$s['node']['id'], IspInfra::trace($acct)))]);
}

$tiles = IspSetup::get('isp_map_tiles');
$haveData = (int)DB::value('SELECT COUNT(*) FROM isp_net_nodes WHERE latitude IS NOT NULL') + (int)DB::value('SELECT COUNT(*) FROM isp_accounts WHERE latitude IS NOT NULL')
    + (int)DB::value('SELECT COUNT(*) FROM isp_areas WHERE latitude IS NOT NULL');
$cfg = [
    'data' => url($pk, ['a' => 'data']),
    'trace' => url($pk, ['a' => 'trace']),
    'tiles' => preg_match('#^https?://#i', $tiles) ? $tiles : '',
    'focus' => (string)g('focus'),
    'links' => [
        'node' => Access::page('ispnet.infra') ? url('ispnet.infra', ['node' => '__ID__']) : '',
        'seg' => Access::page('ispnet.infra') ? url('ispnet.infra', ['seg' => '__ID__']) : '',
        'acct' => Access::page('isp.account') ? url('isp.account', ['id' => '__ID__']) : '',
        'outage' => Access::can('ispnet.outages', 'create') ? url('ispnet.outages', ['a' => 'new', 'node_id' => '__ID__']) : '',
    ],
];
$actions = Access::page('ispnet.infra') ? btn_link(url('ispnet.infra'), 'Tables', 'btn-outline-secondary', 'table') : '';
layout_start('Network Map', ['actions' => $actions]);
?>
<style>
.isp-map { position: relative; height: 72vh; min-height: 420px; border-radius: .75rem; overflow: hidden; background: #0b1630; touch-action: none; user-select: none; -webkit-user-select: none; }
.isp-map .isp-map-tiles { position: absolute; inset: 0; overflow: hidden; }
.isp-map .isp-map-tiles img { position: absolute; width: 256px; height: 256px; max-width: none; pointer-events: none; }
.isp-map svg { position: absolute; inset: 0; width: 100%; height: 100%; cursor: grab; }
.isp-map.is-dragging svg { cursor: grabbing; }
.isp-map .grid line { stroke: rgba(148, 197, 255, .08); stroke-width: 1; }
.isp-map .grid text { fill: rgba(148, 197, 255, .35); font-size: 10px; }
.isp-map.has-tiles .grid { display: none; }
.isp-map .area circle { fill: rgba(34, 211, 238, .06); stroke: rgba(34, 211, 238, .35); stroke-dasharray: 4 4; }
.isp-map .area.no-service circle { fill: rgba(239, 68, 68, .05); stroke: rgba(239, 68, 68, .35); }
.isp-map .area text { fill: #9fd8ff; font-size: 11px; paint-order: stroke; stroke: rgba(11, 22, 48, .85); stroke-width: 3px; }
.isp-map .seg { fill: none; stroke-linecap: round; stroke-linejoin: round; cursor: pointer; }
.isp-map .seg.fiber { stroke: #22d3ee; stroke-width: 3; filter: drop-shadow(0 0 3px rgba(34, 211, 238, .55)); }
.isp-map .seg.drop { stroke: #93c5fd; stroke-width: 1.6; }
.isp-map .seg.coax { stroke: #f59e0b; stroke-width: 2.4; }
.isp-map .seg.utp { stroke: #a78bfa; stroke-width: 1.8; }
.isp-map .seg.st-planned { stroke-dasharray: 2 6; opacity: .6; }
.isp-map .seg.st-damaged, .isp-map .seg.st-cut { stroke: #ef4444; stroke-dasharray: 8 6; filter: drop-shadow(0 0 4px rgba(239, 68, 68, .7)); animation: ispMapDash 1.2s linear infinite; }
.isp-map .seg.st-relocated, .isp-map .seg.st-replaced { opacity: .35; stroke-dasharray: 3 5; }
.isp-map .seg.is-trace { stroke: #facc15 !important; stroke-width: 5; opacity: 1; }
@keyframes ispMapDash { to { stroke-dashoffset: -28; } }
.isp-map .node { cursor: pointer; }
.isp-map .node .dot { stroke: #0b1630; stroke-width: 2; transition: r .15s; }
.isp-map .node:hover .dot { r: 9; }
.isp-map .node text { fill: #e2f3ff; font-size: 10px; paint-order: stroke; stroke: rgba(11, 22, 48, .9); stroke-width: 3px; pointer-events: none; }
.isp-map .node.st-damaged .dot, .isp-map .node.is-outage .dot { stroke: #ef4444; stroke-width: 3; }
.isp-map .node.is-outage .ring { fill: none; stroke: #ef4444; stroke-width: 2; animation: ispMapPulse 1.6s ease-out infinite; transform-box: fill-box; transform-origin: center; }
.isp-map .node.is-trace .dot { stroke: #facc15; stroke-width: 3.5; }
@keyframes ispMapPulse { from { opacity: .9; transform: scale(.6); } to { opacity: 0; transform: scale(2.4); } }
.isp-map .sub { cursor: pointer; stroke: rgba(11, 22, 48, .85); stroke-width: 1.2; }
.isp-map .sub.s-active { fill: #10b981; } .isp-map .sub.s-disconnected { fill: #ef4444; } .isp-map .sub.s-suspended { fill: #f59e0b; } .isp-map .sub.s-pending { fill: #60a5fa; }
.isp-map .sub.is-sel { stroke: #facc15; stroke-width: 3; }
.isp-map .drop-link { stroke: #facc15; stroke-width: 1.5; stroke-dasharray: 3 3; fill: none; }
.isp-map .isp-map-ctl { position: absolute; right: .75rem; top: .75rem; display: flex; flex-direction: column; gap: .35rem; z-index: 2; }
.isp-map .isp-map-ctl button { width: 2.25rem; height: 2.25rem; border-radius: .6rem; border: 1px solid rgba(148, 197, 255, .25); background: rgba(11, 22, 48, .82); color: #e2f3ff; backdrop-filter: blur(6px); }
.isp-map .isp-map-ctl button:hover { background: rgba(34, 211, 238, .25); }
.isp-map .isp-map-scale { position: absolute; left: .75rem; bottom: .6rem; color: #cde9ff; font-size: .72rem; z-index: 2; }
.isp-map .isp-map-scale span { display: block; height: 4px; border: 1px solid #cde9ff; border-top: 0; margin-top: 2px; }
.isp-map .isp-map-attr { position: absolute; right: .5rem; bottom: .3rem; color: rgba(205, 233, 255, .7); font-size: .65rem; z-index: 2; }
.isp-map .isp-map-empty { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; text-align: center; color: #cde9ff; padding: 2rem; z-index: 3; }
.isp-map-legend { display: flex; flex-wrap: wrap; gap: .25rem 1rem; font-size: .8rem; }
.isp-map-legend i { display: inline-block; width: .7rem; height: .7rem; border-radius: 50%; margin-right: .3rem; vertical-align: -1px; }
.isp-map-legend .ln { width: 1.2rem; height: 3px; border-radius: 2px; }
.isp-map-panel dl { margin: 0; }
@media (max-width: 991.98px) { .isp-map { height: 60vh; min-height: 340px; } }
@media (prefers-reduced-motion: reduce) { .isp-map .seg.st-damaged, .isp-map .seg.st-cut, .isp-map .node.is-outage .ring { animation: none; } }
@media print { .isp-map { height: 18cm; } .isp-map .isp-map-ctl { display: none; } }
</style>
<div class="row g-3">
  <div class="col-xl-9">
    <div class="card isp-card"><div class="card-body p-2">
      <div class="d-flex flex-wrap gap-2 align-items-center mb-2 no-print">
        <div class="input-group input-group-sm" style="max-width:20rem">
          <input class="form-control" type="search" id="mapFind" placeholder="Account no., node or segment code" aria-label="Find on the map">
          <button class="btn btn-outline-secondary" type="button" id="mapFindBtn"><i class="bi bi-search"></i></button>
        </div>
        <div class="d-flex flex-wrap gap-3 small" role="group" aria-label="Layers">
          <label class="form-check-label"><input class="form-check-input me-1" type="checkbox" data-layer="areas" checked>Areas</label>
          <label class="form-check-label"><input class="form-check-input me-1" type="checkbox" data-layer="segments" checked>Cables</label>
          <label class="form-check-label"><input class="form-check-input me-1" type="checkbox" data-layer="nodes" checked>Nodes</label>
          <label class="form-check-label"><input class="form-check-input me-1" type="checkbox" data-layer="labels" checked>Labels</label>
          <label class="form-check-label"><input class="form-check-input me-1" type="checkbox" data-layer="subs" checked>Subscribers</label>
          <label class="form-check-label"><input class="form-check-input me-1" type="checkbox" data-layer="onlyproblems">Only problems</label>
        </div>
      </div>
      <div class="isp-map<?= $cfg['tiles'] ? ' has-tiles' : '' ?>" id="ispMap">
        <div class="isp-map-tiles" id="mapTiles"></div>
        <svg id="mapSvg" xmlns="http://www.w3.org/2000/svg" aria-label="Network map" role="img">
          <g class="grid" id="gGrid"></g><g id="gAreas"></g><g id="gSegs"></g><g id="gLinks"></g><g id="gSubs"></g><g id="gNodes"></g>
        </svg>
        <div class="isp-map-ctl no-print">
          <button type="button" id="mapIn" title="Zoom in" aria-label="Zoom in"><i class="bi bi-plus-lg"></i></button>
          <button type="button" id="mapOut" title="Zoom out" aria-label="Zoom out"><i class="bi bi-dash-lg"></i></button>
          <button type="button" id="mapFit" title="Show everything" aria-label="Show everything"><i class="bi bi-arrows-fullscreen"></i></button>
        </div>
        <div class="isp-map-scale" id="mapScale"></div>
        <?php if ($cfg['tiles']): ?><div class="isp-map-attr">Map tiles: <?= h(parse_url($cfg['tiles'], PHP_URL_HOST) ?: 'provider') ?></div><?php endif; ?>
        <?php if (!$haveData): ?><div class="isp-map-empty"><div><i class="bi bi-geo fs-1 d-block mb-2"></i>No coordinates are recorded yet for the areas, network nodes or subscribers.<br>
          Enter latitude and longitude on the nodes and accounts, or work from <?= Access::page('ispnet.infra') ? '<a class="link-info" href="' . h(url('ispnet.infra')) . '">the infrastructure tables</a>' : 'the infrastructure tables' ?>.</div></div><?php endif; ?>
      </div>
      <div class="isp-map-legend mt-2 px-1">
        <span><i style="background:#a78bfa"></i>OLT</span><span><i style="background:#22d3ee"></i>Splitter</span><span><i style="background:#3b82f6"></i>NAP</span><span><i style="background:#fb923c"></i>Closure / cabinet</span><span><i style="background:#94a3b8"></i>Pole / other</span>
        <span><i class="ln" style="background:#22d3ee"></i>Fiber</span><span><i class="ln" style="background:#ef4444"></i>Damaged / cut</span>
        <span><i style="background:#10b981"></i>Active</span><span><i style="background:#f59e0b"></i>Suspended</span><span><i style="background:#ef4444"></i>Disconnected</span><span><i style="background:#60a5fa"></i>Pending</span>
      </div>
    </div></div>
  </div>
  <div class="col-xl-3">
    <div class="card isp-card isp-map-panel"><div class="card-body" id="mapPanel">
      <h2 class="sec-title">Details</h2>
      <p class="small text-muted mb-2">Click a node, a cable or a subscriber. Drag to move, scroll or pinch to zoom.</p>
      <div id="mapStats" class="small"></div>
    </div></div>
  </div>
</div>
<script>
(function () {
  'use strict';
  var CFG = <?= json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?>;
  var NS = 'http://www.w3.org/2000/svg';
  var box = document.getElementById('ispMap'), svg = document.getElementById('mapSvg'), tilesEl = document.getElementById('mapTiles');
  var G = { grid: gid('gGrid'), areas: gid('gAreas'), segs: gid('gSegs'), links: gid('gLinks'), subs: gid('gSubs'), nodes: gid('gNodes') };
  var panel = document.getElementById('mapPanel');
  var data = { nodes: [], segments: [], subscribers: [], areas: [], outageNodes: [] };
  var view = { cx: 128, cy: 128, z: 3 };
  var layers = { areas: true, segments: true, nodes: true, labels: true, subs: true, onlyproblems: false };
  var els = { nodes: [], segs: [], subs: [], areas: [] };
  var traceSet = {}, selSub = null, raf = 0;
  var NODE_COLORS = { olt: '#a78bfa', olt_card: '#a78bfa', splitter: '#22d3ee', nap: '#3b82f6', fdp: '#38bdf8', cabinet: '#fb923c', splice_closure: '#fb923c', patch_panel: '#fb923c',
    pole: '#94a3b8', switch: '#e879f9', router: '#e879f9', coax_amp: '#f59e0b', tap: '#f59e0b' };
  var NODE_R = { olt: 9, olt_card: 7, splitter: 7, nap: 6, fdp: 6, cabinet: 7, splice_closure: 5, pole: 3.5, patch_panel: 6, switch: 7, router: 8, coax_amp: 5, tap: 4 };

  function gid(i) { return document.getElementById(i); }
  function el(tag, attrs, parent) { var e = document.createElementNS(NS, tag); for (var k in attrs) e.setAttribute(k, attrs[k]); if (parent) parent.appendChild(e); return e; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function link(kind, id, text) { var u = CFG.links[kind]; return u ? '<a href="' + esc(u.replace('__ID__', id)) + '">' + esc(text) + '</a>' : esc(text); }

  // web mercator: world pixels at zoom 0 (0..256)
  function project(lat, lng) {
    var s = Math.sin(Math.max(-85.05, Math.min(85.05, lat)) * Math.PI / 180);
    return [(lng + 180) / 360 * 256, (0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI)) * 256];
  }
  function unproject(x, y) {
    var n = Math.PI - 2 * Math.PI * y / 256;
    return [180 / Math.PI * Math.atan(0.5 * (Math.exp(n) - Math.exp(-n))), x / 256 * 360 - 180];
  }
  function size() { return [box.clientWidth, box.clientHeight]; }
  function toScreen(p) { var k = Math.pow(2, view.z), s = size(); return [(p[0] - view.cx) * k + s[0] / 2, (p[1] - view.cy) * k + s[1] / 2]; }
  function toWorld(x, y) { var k = Math.pow(2, view.z), s = size(); return [(x - s[0] / 2) / k + view.cx, (y - s[1] / 2) / k + view.cy]; }

  function problem(kind, o) {
    if (kind === 'sub') return o.status !== 'active';
    if (kind === 'node') return o.status === 'damaged' || o.status === 'maintenance' || data.outageNodes.indexOf(o.id) >= 0 || (o.ports > 0 && o.free === 0);
    if (kind === 'seg') return ['damaged', 'cut'].indexOf(o.status) >= 0;
    return false;
  }

  function build() {
    Object.keys(G).forEach(function (k) { if (k !== 'grid') G[k].innerHTML = ''; });
    els = { nodes: [], segs: [], subs: [], areas: [] };
    data.areas.forEach(function (a) {
      var g = el('g', { 'class': 'area' + (a.serviceable ? '' : ' no-service') }, G.areas);
      var c = el('circle', { r: 10 }, g), t = el('text', { 'text-anchor': 'middle' }, g);
      t.textContent = a.name;
      els.areas.push({ o: a, g: g, c: c, t: t, p: project(a.lat, a.lng) });
    });
    data.segments.forEach(function (s) {
      var pl = el('polyline', { 'class': 'seg ' + s.type + ' st-' + s.status, 'data-id': s.id }, G.segs);
      var t = el('title', {}, pl); t.textContent = s.code + ' (' + s.statusLabel + ')';
      pl.addEventListener('click', function (e) { e.stopPropagation(); showSeg(s); });
      els.segs.push({ o: s, e: pl, p: s.pts.map(function (q) { return project(q[0], q[1]); }) });
    });
    data.subscribers.forEach(function (s) {
      var c = el('circle', { 'class': 'sub s-' + s.status, r: 4 }, G.subs);
      var t = el('title', {}, c); t.textContent = s.no + ' ' + s.name;
      c.addEventListener('click', function (e) { e.stopPropagation(); showSub(s); });
      els.subs.push({ o: s, e: c, p: project(s.lat, s.lng) });
    });
    data.nodes.forEach(function (n) {
      var out = data.outageNodes.indexOf(n.id) >= 0;
      var g = el('g', { 'class': 'node t-' + n.type + ' st-' + n.status + (out ? ' is-outage' : '') }, G.nodes);
      var ring = out ? el('circle', { 'class': 'ring', r: NODE_R[n.type] || 5 }, g) : null;
      var c = el('circle', { 'class': 'dot', r: NODE_R[n.type] || 5, fill: NODE_COLORS[n.type] || '#94a3b8' }, g);
      var t = el('text', { dx: 10, dy: 4 }, g); t.textContent = n.code;
      var ti = el('title', {}, g); ti.textContent = n.code + ' ' + n.name + ' (' + n.typeLabel + ')';
      g.addEventListener('click', function (e) { e.stopPropagation(); showNode(n); });
      els.nodes.push({ o: n, g: g, c: c, ring: ring, t: t, p: project(n.lat, n.lng) });
    });
    stats();
  }

  function draw() {
    raf = 0;
    var s = size(), k = Math.pow(2, view.z), only = layers.onlyproblems;
    var pad = 40;
    function vis(p) { var q = toScreen(p); return q[0] > -pad && q[1] > -pad && q[0] < s[0] + pad && q[1] < s[1] + pad ? q : null; }
    G.areas.style.display = layers.areas ? '' : 'none';
    G.segs.style.display = layers.segments ? '' : 'none';
    G.nodes.style.display = layers.nodes ? '' : 'none';
    G.subs.style.display = layers.subs ? '' : 'none';
    els.areas.forEach(function (a) {
      var q = toScreen(a.p), r = Math.max(14, Math.min(220, (a.o.type === 'province' ? 0.25 : a.o.type === 'municipality' ? 0.04 : 0.008) * k * 256 / 360 * 8));
      a.c.setAttribute('cx', q[0]); a.c.setAttribute('cy', q[1]); a.c.setAttribute('r', r);
      a.t.setAttribute('x', q[0]); a.t.setAttribute('y', q[1] - r - 4);
      a.g.style.display = (a.o.type === 'province' && view.z > 11) || (a.o.type === 'barangay' && view.z < 11) || (a.o.type === 'zone' && view.z < 14) ? 'none' : '';
    });
    els.segs.forEach(function (sg) {
      var hide = only && !problem('seg', sg.o) && !traceSet['s' + sg.o.id];
      sg.e.style.display = hide ? 'none' : '';
      if (!hide) sg.e.setAttribute('points', sg.p.map(function (p) { var q = toScreen(p); return q[0].toFixed(1) + ',' + q[1].toFixed(1); }).join(' '));
      sg.e.classList.toggle('is-trace', !!traceSet['s' + sg.o.id]);
    });
    var showLabels = layers.labels && view.z >= 14;
    els.nodes.forEach(function (n) {
      var q = vis(n.p), hide = !q || (only && !problem('node', n.o) && !traceSet['n' + n.o.id]) || (n.o.type === 'pole' && view.z < 15);
      n.g.style.display = hide ? 'none' : '';
      if (hide) return;
      n.c.setAttribute('cx', q[0]); n.c.setAttribute('cy', q[1]);
      if (n.ring) { n.ring.setAttribute('cx', q[0]); n.ring.setAttribute('cy', q[1]); }
      n.t.setAttribute('x', q[0]); n.t.setAttribute('y', q[1]);
      n.t.style.display = showLabels || n.o.type === 'olt' ? '' : 'none';
      n.g.classList.toggle('is-trace', !!traceSet['n' + n.o.id]);
    });
    var rSub = view.z >= 17 ? 5.5 : view.z >= 15 ? 4.5 : 3;
    els.subs.forEach(function (sb) {
      var q = vis(sb.p), hide = !q || (only && !problem('sub', sb.o) && selSub !== sb.o);
      sb.e.style.display = hide ? 'none' : '';
      if (hide) return;
      sb.e.setAttribute('cx', q[0]); sb.e.setAttribute('cy', q[1]); sb.e.setAttribute('r', rSub);
      sb.e.classList.toggle('is-sel', selSub === sb.o);
    });
    // the dashed drop from the selected subscriber to its NAP
    G.links.innerHTML = '';
    if (selSub && selSub.napId) {
      var nap = els.nodes.filter(function (n) { return n.o.id === selSub.napId; })[0];
      if (nap) {
        var a = toScreen(project(selSub.lat, selSub.lng)), b = toScreen(nap.p);
        el('line', { 'class': 'drop-link', x1: a[0], y1: a[1], x2: b[0], y2: b[1] }, G.links);
      }
    }
    grid(s, k);
    tiles(s);
    scale(s);
  }
  function redraw() { if (!raf) raf = requestAnimationFrame(draw); }

  function grid(s, k) {
    G.grid.innerHTML = '';
    if (CFG.tiles) return;
    var tl = unproject.apply(null, toWorld(0, 0)), br = unproject.apply(null, toWorld(s[0], s[1]));
    var span = Math.abs(br[1] - tl[1]), step = [10, 5, 2, 1, .5, .2, .1, .05, .02, .01, .005, .002, .001, .0005].filter(function (x) { return span / x <= 12; })[0] || 10;
    for (var lng = Math.ceil(tl[1] / step) * step; lng <= br[1]; lng += step) {
      var x = toScreen(project(0, lng))[0];
      el('line', { x1: x, y1: 0, x2: x, y2: s[1] }, G.grid);
      el('text', { x: x + 3, y: s[1] - 22 }, G.grid).textContent = lng.toFixed(step < .01 ? 4 : step < 1 ? 2 : 0) + '°';
    }
    for (var lat = Math.ceil(br[0] / step) * step; lat <= tl[0]; lat += step) {
      var y = toScreen(project(lat, 0))[1];
      el('line', { x1: 0, y1: y, x2: s[0], y2: y }, G.grid);
      el('text', { x: 4, y: y - 3 }, G.grid).textContent = lat.toFixed(step < .01 ? 4 : step < 1 ? 2 : 0) + '°';
    }
  }

  var tileCache = {};
  function tiles(s) {
    if (!CFG.tiles) return;
    var zi = Math.max(0, Math.min(19, Math.round(view.z))), f = Math.pow(2, view.z - zi), n = Math.pow(2, zi), want = {};
    var tl = toWorld(0, 0), br = toWorld(s[0], s[1]), ts = 256 / n;
    for (var tx = Math.floor(tl[0] / ts); tx <= Math.floor(br[0] / ts); tx++) {
      for (var ty = Math.max(0, Math.floor(tl[1] / ts)); ty <= Math.min(n - 1, Math.floor(br[1] / ts)); ty++) {
        var wx = ((tx % n) + n) % n, key = zi + '/' + wx + '/' + ty, img = tileCache[key];
        if (!img) {
          img = document.createElement('img');
          img.alt = '';
          img.decoding = 'async';
          img.src = CFG.tiles.replace('{z}', zi).replace('{x}', wx).replace('{y}', ty).replace('{s}', 'abc'[(wx + ty) % 3]);
          tileCache[key] = img;
        }
        var q = toScreen([tx * ts, ty * ts]);
        img.style.transform = 'translate(' + q[0].toFixed(1) + 'px,' + q[1].toFixed(1) + 'px) scale(' + f + ')';
        img.style.transformOrigin = '0 0';
        if (img.parentNode !== tilesEl) tilesEl.appendChild(img);
        want[key] = 1;
      }
    }
    Object.keys(tileCache).forEach(function (k) { if (!want[k] && tileCache[k].parentNode) tilesEl.removeChild(tileCache[k]); });
  }

  function scale(s) {
    var mid = unproject.apply(null, toWorld(s[0] / 2, s[1] / 2));
    var mPerPx = 156543.03392 * Math.cos(mid[0] * Math.PI / 180) / Math.pow(2, view.z);
    var target = mPerPx * 120, nice = [1, 2, 5, 10, 20, 50, 100, 200, 500, 1000, 2000, 5000, 10000, 20000, 50000, 100000, 200000, 500000].filter(function (x) { return x <= target; }).pop() || 1;
    document.getElementById('mapScale').innerHTML = (nice >= 1000 ? nice / 1000 + ' km' : nice + ' m') + '<span style="width:' + (nice / mPerPx).toFixed(0) + 'px"></span>';
  }

  // ---------------------------------------------------------------- details
  function dl(rows) { return '<dl class="isp-dl">' + rows.filter(function (r) { return r[1] !== '' && r[1] != null; }).map(function (r) { return '<div><dt>' + esc(r[0]) + '</dt><dd>' + r[1] + '</dd></div>'; }).join('') + '</dl>'; }
  function head(title, sub) { return '<h2 class="sec-title">' + title + '</h2>' + (sub ? '<div class="small text-muted mb-2">' + sub + '</div>' : ''); }
  function clearTrace() { traceSet = {}; selSub = null; }
  function showNode(n) {
    clearTrace();
    var out = data.outageNodes.indexOf(n.id) >= 0;
    panel.innerHTML = head(link('node', n.id, n.code), esc(n.name)) + dl([
      ['Type', esc(n.typeLabel + (n.split ? ' ' + n.split : ''))], ['Status', esc(n.statusLabel) + (out ? ' <span class="text-danger">Outage in progress</span>' : '')], ['Area', esc(n.area)],
      ['Ports', n.ports ? esc(n.ports + ' total, ' + n.free + ' free') : ''], ['Subscribers on it', n.subs ? esc(n.subs) : ''], ['Coordinates', esc(n.lat + ', ' + n.lng)]
    ]) + (CFG.links.outage && !out ? '<a class="btn btn-sm btn-outline-danger mt-2" href="' + esc(CFG.links.outage.replace('__ID__', n.id)) + '"><i class="bi bi-exclamation-triangle"></i> Declare outage here</a>' : '');
    redraw();
  }
  function showSeg(s) {
    clearTrace();
    traceSet['s' + s.id] = 1;
    panel.innerHTML = head(link('seg', s.id, s.code), esc(s.typeLabel + ', ' + s.cores + ' cores')) + dl([
      ['Status', esc(s.statusLabel)], ['From', s.from ? link('node', s.from, s.fromCode) : ''], ['To', s.to ? link('node', s.to, s.toCode) : ''],
      ['Length', s.length != null ? esc(s.length.toLocaleString('en-US', { maximumFractionDigits: 1 }) + ' m') : ''], ['Route points', esc(s.pts.length)]
    ]);
    redraw();
  }
  function showSub(s) {
    clearTrace();
    selSub = s;
    panel.innerHTML = head(link('acct', s.id, s.no), esc(s.name)) + dl([
      ['Service', esc(s.statusLabel)], ['Network', esc(s.net)], ['Plan', esc(s.plan)], ['Address', esc(s.address)], ['NAP', s.napId ? link('node', s.napId, s.nap) : '<span class="text-muted">No port recorded</span>']
    ]) + (s.napId ? '<button type="button" class="btn btn-sm btn-outline-primary mt-2" id="mapTrace"><i class="bi bi-signpost-split"></i> Trace to the OLT</button><div class="small mt-2" id="mapTraceOut"></div>' : '');
    var b = document.getElementById('mapTrace');
    if (b) b.addEventListener('click', function () { trace(s); });
    redraw();
  }
  function trace(s) {
    fetch(CFG.trace + '&account=' + s.id, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      var ids = j.nodes || [];
      traceSet = {};
      ids.forEach(function (id, i) {
        traceSet['n' + id] = 1;
        if (i > 0) data.segments.forEach(function (sg) { if ((sg.from === id && sg.to === ids[i - 1]) || (sg.to === id && sg.from === ids[i - 1])) traceSet['s' + sg.id] = 1; });
      });
      var names = ids.map(function (id) { var n = data.nodes.filter(function (x) { return x.id === id; })[0]; return n ? link('node', n.id, n.code) : '#' + id; });
      var out = document.getElementById('mapTraceOut');
      if (out) out.innerHTML = names.length ? 'Subscriber, then ' + names.join(', then ') : '<span class="text-muted">No upstream chain recorded.</span>';
      redraw();
    });
  }
  function stats() {
    var c = { active: 0, disconnected: 0, suspended: 0, pending: 0 };
    data.subscribers.forEach(function (s) { c[s.status] = (c[s.status] || 0) + 1; });
    var bad = data.segments.filter(function (s) { return problem('seg', s); }).length;
    var full = data.nodes.filter(function (n) { return n.ports > 0 && n.free === 0; }).length;
    document.getElementById('mapStats').innerHTML = dl([
      ['Nodes on the map', esc(data.nodes.length)], ['Cable segments', esc(data.segments.length)], ['Subscribers located', esc(data.subscribers.length)],
      ['Active / suspended', esc(c.active + ' / ' + c.suspended)], ['Disconnected', esc(c.disconnected)], ['Damaged or cut cables', bad ? '<span class="text-danger">' + bad + '</span>' : '0'],
      ['Nodes with no free port', full ? esc(full) : '0'], ['Active outages', data.outageNodes.length ? '<span class="text-danger">' + data.outageNodes.length + '</span>' : '0']
    ]);
  }

  // ---------------------------------------------------------------- view
  function fit(points) {
    var s = size();
    if (!points.length) { view = { cx: project(12.8797, 121.774)[0], cy: project(12.8797, 121.774)[1], z: 5 }; redraw(); return; }
    var xs = points.map(function (p) { return p[0]; }), ys = points.map(function (p) { return p[1]; });
    var minx = Math.min.apply(null, xs), maxx = Math.max.apply(null, xs), miny = Math.min.apply(null, ys), maxy = Math.max.apply(null, ys);
    var w = Math.max(maxx - minx, 1e-6), h = Math.max(maxy - miny, 1e-6);
    view.cx = (minx + maxx) / 2; view.cy = (miny + maxy) / 2;
    view.z = Math.max(2, Math.min(18, Math.log2(Math.min((s[0] - 80) / w, (s[1] - 80) / h))));
    redraw();
  }
  function allPoints() {
    var pts = [];
    els.nodes.forEach(function (n) { pts.push(n.p); });
    els.subs.forEach(function (n) { pts.push(n.p); });
    els.segs.forEach(function (n) { pts = pts.concat(n.p); });
    if (!pts.length) els.areas.forEach(function (a) { pts.push(a.p); });
    return pts;
  }
  function zoomAt(dz, x, y) {
    var before = toWorld(x, y);
    view.z = Math.max(2, Math.min(20, view.z + dz));
    var after = toWorld(x, y);
    view.cx += before[0] - after[0]; view.cy += before[1] - after[1];
    redraw();
  }
  function focusOn(kind, id) {
    var hit = null;
    if (kind === 'node') hit = els.nodes.filter(function (n) { return n.o.id === id; })[0];
    if (kind === 'acct') hit = els.subs.filter(function (n) { return n.o.id === id; })[0];
    if (kind === 'seg') hit = els.segs.filter(function (n) { return n.o.id === id; })[0];
    if (!hit) return false;
    if (kind === 'seg') { fit(hit.p); showSeg(hit.o); return true; }
    view.cx = hit.p[0]; view.cy = hit.p[1]; view.z = Math.max(view.z, 17);
    if (kind === 'node') showNode(hit.o); else showSub(hit.o);
    return true;
  }
  function find(q) {
    q = q.trim().toLowerCase();
    if (!q) return;
    var n = data.nodes.filter(function (x) { return x.code.toLowerCase() === q || x.name.toLowerCase().indexOf(q) >= 0; })[0];
    if (n && focusOn('node', n.id)) return;
    var s = data.subscribers.filter(function (x) { return x.no.toLowerCase() === q || x.name.toLowerCase().indexOf(q) >= 0; })[0];
    if (s && focusOn('acct', s.id)) return;
    var g = data.segments.filter(function (x) { return x.code.toLowerCase() === q; })[0];
    if (g && focusOn('seg', g.id)) return;
    panel.innerHTML = head('Not found', '') + '<p class="small text-muted">Nothing on the map matches "' + esc(q) + '". It may have no coordinates.</p>';
  }

  // pointers: drag to pan, two fingers to pinch
  var pts = {}, last = null, pinch = null, moved = false;
  svg.addEventListener('pointerdown', function (e) {
    pts[e.pointerId] = [e.clientX, e.clientY];
    moved = false;
    var ids = Object.keys(pts);
    if (ids.length === 2) {
      var a = pts[ids[0]], b = pts[ids[1]];
      pinch = { d: Math.hypot(a[0] - b[0], a[1] - b[1]), z: view.z };
    } else last = [e.clientX, e.clientY];
    box.classList.add('is-dragging');
  });
  svg.addEventListener('pointermove', function (e) {
    if (!pts[e.pointerId]) return;
    pts[e.pointerId] = [e.clientX, e.clientY];
    var ids = Object.keys(pts), r = box.getBoundingClientRect();
    if (ids.length === 2 && pinch) {
      var a = pts[ids[0]], b = pts[ids[1]], d = Math.hypot(a[0] - b[0], a[1] - b[1]);
      var mx = (a[0] + b[0]) / 2 - r.left, my = (a[1] + b[1]) / 2 - r.top;
      zoomAt(pinch.z + Math.log2(d / pinch.d) - view.z, mx, my);
      moved = true;
      ids.forEach(function (id) { if (!svg.hasPointerCapture(+id)) svg.setPointerCapture(+id); });
      return;
    }
    if (!last) return;
    var dx = e.clientX - last[0], dy = e.clientY - last[1];
    if (Math.abs(dx) + Math.abs(dy) > 2) moved = true;
    // captured only once it is a drag, so a plain click still reaches the node or subscriber under the pointer
    if (moved && !svg.hasPointerCapture(e.pointerId)) svg.setPointerCapture(e.pointerId);
    var k = Math.pow(2, view.z);
    view.cx -= dx / k; view.cy -= dy / k;
    last = [e.clientX, e.clientY];
    redraw();
  });
  function up(e) {
    delete pts[e.pointerId];
    if (Object.keys(pts).length < 2) pinch = null;
    last = Object.keys(pts).length === 1 ? pts[Object.keys(pts)[0]] : null;
    if (!Object.keys(pts).length) box.classList.remove('is-dragging');
  }
  svg.addEventListener('pointerup', up);
  svg.addEventListener('pointercancel', up);
  svg.addEventListener('click', function (e) { if (!moved && e.target === svg) { clearTrace(); redraw(); } }, true);
  svg.addEventListener('wheel', function (e) {
    e.preventDefault();
    var r = box.getBoundingClientRect();
    zoomAt(-e.deltaY * (e.deltaMode === 1 ? 0.05 : 0.0025), e.clientX - r.left, e.clientY - r.top);
  }, { passive: false });
  svg.addEventListener('dblclick', function (e) { var r = box.getBoundingClientRect(); zoomAt(1, e.clientX - r.left, e.clientY - r.top); });
  document.getElementById('mapIn').addEventListener('click', function () { var s = size(); zoomAt(1, s[0] / 2, s[1] / 2); });
  document.getElementById('mapOut').addEventListener('click', function () { var s = size(); zoomAt(-1, s[0] / 2, s[1] / 2); });
  document.getElementById('mapFit').addEventListener('click', function () { fit(allPoints()); });
  document.getElementById('mapFindBtn').addEventListener('click', function () { find(document.getElementById('mapFind').value); });
  document.getElementById('mapFind').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); find(this.value); } });
  document.querySelectorAll('[data-layer]').forEach(function (cb) {
    cb.addEventListener('change', function () { layers[cb.getAttribute('data-layer')] = cb.checked; redraw(); });
  });
  window.addEventListener('resize', redraw);

  fetch(CFG.data, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
    data = j;
    build();
    fit(allPoints());
    if (CFG.focus) {
      var m = CFG.focus.match(/^(node|acct|seg):(\d+)$/);
      if (m) focusOn(m[1], +m[2]);
    }
  }).catch(function () {
    panel.innerHTML = head('Map data', '') + '<p class="small text-danger">The map data could not be loaded. Reload the page.</p>';
  });
})();
</script>
<?php
layout_end();
