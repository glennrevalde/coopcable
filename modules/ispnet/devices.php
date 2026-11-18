<?php
declare(strict_types=1);

/**
 * Network Devices & Integrations: the routers, RADIUS servers, OLT platforms and TR-069 servers the system sends
 * subscriber commands to, each through the adapter written for it. Credentials are encrypted and write-only: a
 * secret entered here is never shown again, only that one is set.
 */
require_once __DIR__ . '/_common.php';

$pk = 'ispnet.devices';
$GLOBALS['ISPNET_TEST'] = null;

const ISPNET_ADAPTER_HELP = [
    'mikrotik' => [
        'about' => 'A MikroTik router running the PPPoE server, reached through the RouterOS API (port 8728, or 8729 with TLS). Use an API user of its own with only the ppp and firewall rights it needs.',
        'options' => "method = ppp_secret\nprofile_default = default\n# local_address = 10.0.0.1\n# list = isp-blocked\ntimeout = 8\n# verify_tls = 0",
        'notes' => 'method: ppp_secret (one PPP secret per subscriber) or address_list (static-IP subscribers blocked through a firewall address list). profile_default: the PPP profile when a plan names none (the plan\'s speed lives in its PPP profile). verify_tls = 0 accepts the router\'s self-signed certificate.',
    ],
    'radius' => [
        'about' => 'FreeRADIUS with its SQL module: the system writes radcheck, radreply and radusergroup and reads radacct, in the RADIUS server\'s own database. The user here must only be able to write those tables.',
        'options' => "database = radius\nrate_attr = Mikrotik-Rate-Limit\n# group_prefix = plan_\n# nas_device_id = 1\ncharset = utf8",
        'notes' => 'database: the RADIUS database name. rate_attr: the reply attribute carrying the speed (empty: none). nas_device_id: a MikroTik device here that closes the open session of a disabled subscriber.',
    ],
    'olt_http' => [
        'about' => 'An OLT / ONU management platform reached through its own documented HTTP API. No vendor command is assumed: write the URLs of the platform\'s operations. Placeholders: {base} {serial} {onu_id} {port} {account_no} {profile} {down} {up} {secret}.',
        'options' => "base_url = https://ems.example.net/api\nauth_header = Authorization: Bearer {secret}\nenable_url = POST {base}/onu/{serial}/activate\ndisable_url = POST {base}/onu/{serial}/deactivate\nstatus_url = GET {base}/onu/{serial}\nstate_field = admin_state\nenabled_value = enabled",
        'notes' => 'A 2xx answer means accepted; the adapter then reads status_url back and only a matching state counts as confirmed. provision_url / provision_body add an ONU with its profile.',
    ],
    'tr069' => [
        'about' => 'A TR-069 auto-configuration server (GenieACS northbound API, port 7557). It reads the subscriber\'s device and can reboot it; it does not enable or disable internet access, so pair it with a MikroTik or RADIUS device.',
        'options' => "base_url = http://acs.local:7557\nserial_param = _deviceId._SerialNumber\n# auth_header = Authorization: Basic {secret}",
        'notes' => 'The device is found by the ONU / router serial recorded on the subscriber\'s installation work order.',
    ],
    'manual' => [
        'about' => 'No network device: every activation, disconnection and reconnection becomes a work order, and is confirmed only when the person who did it completes the work order.',
        'options' => '',
        'notes' => 'Use it for areas whose equipment the system cannot reach.',
    ],
    'simulator' => [
        'about' => 'FOR TESTING ONLY. The simulator answers like a device but sends nothing to any network; every result it gives is marked "Simulated: no real device was changed".',
        'options' => "# fail_accounts = 1000004, 1000007\n# fail_ops = disable\n# fail_rate = 0.1\n# unconfirmed = 1",
        'notes' => 'fail_accounts / fail_ops / fail_rate make operations fail on purpose; unconfirmed = 1 accepts commands without ever confirming them (tests the verification step).',
    ],
];

/** The posted device fields, limited to the columns of isp_devices */
function ispnet_device_fields(): array
{
    $adapter = (string)p('adapter');
    $port = trim((string)p('port'));
    return [
        'name' => mb_substr(trim((string)p('name')), 0, 80),
        'adapter' => $adapter,
        'host' => mb_substr(trim((string)p('host')), 0, 120) ?: null,
        'port' => $port === '' ? null : max(1, min(65535, (int)$port)),
        'use_tls' => p('use_tls') === '1' ? 1 : 0,
        'username' => mb_substr(trim((string)p('username')), 0, 80) ?: null,
        'options' => trim((string)p('options')) ?: null,
        'branch_id' => pint('branch_id') ?: null,
        'is_default' => p('is_default') === '1' ? 1 : 0,
        'is_active' => p('is_active') === '1' ? 1 : 0,
    ];
}

handle_post(function () use ($pk) {
    $action = (string)p('action');
    $id = (int)p('id');
    switch ($action) {
        case 'save':
            Access::require($pk, $id ? 'edit' : 'create');
            ispnet_require_admin();
            $f = ispnet_device_fields();
            if ($f['adapter'] === 'simulator' && $f['is_default'] && !p('confirm_sim')) {
                fail('The simulator changes no real device. Tick "I understand this is for testing" to make it the default device.');
            }
            $secret = (string)($_POST['secret'] ?? '');
            if (p('clear_secret') === '1') {
                $f['secret_enc'] = null;
                $secret = '';
            }
            $newId = IspNetwork::saveDevice($f, $id ?: null, $secret !== '' ? $secret : null);
            redirect_after_save(url($pk, ['a' => 'new']), url($pk, ['id' => $newId]));
            return;
        case 'test':
            Access::require($pk, 'edit');
            ispnet_require_admin();
            $d = IspNetwork::device($id) ?? fail('Device not found.');
            $ad = IspNetwork::adapter(array_merge($d, ['is_active' => 1]));
            try {
                $r = $ad->test();
            } catch (Throwable $e) {
                $r = ['ok' => false, 'confirmed' => false, 'message' => 'Adapter error: ' . $e->getMessage(), 'log' => []];
            }
            if ($r['ok']) {
                DB::update('isp_devices', ['last_ok_at' => date('Y-m-d H:i:s'), 'last_error' => null], 'id = :id', ['id' => $id]);
            } else {
                DB::update('isp_devices', ['last_error' => mb_substr((string)$r['message'], 0, 255)], 'id = :id', ['id' => $id]);
            }
            Audit::log('test', 'ispnet', 'isp_device', $id, (string)$d['name'], ['page_key' => $pk, 'reason' => ($r['ok'] ? 'OK: ' : 'FAILED: ') . mb_substr(ispnet_scrub((string)$r['message']), 0, 200)]);
            $GLOBALS['ISPNET_TEST'] = $r + ['simulated' => $ad->simulated(), 'label' => $ad->label()];
            $_GET['id'] = (string)$id;
            return;
        case 'activate':
        case 'deactivate':
            Access::require($pk, 'edit');
            ispnet_require_admin();
            $d = IspNetwork::device($id) ?? fail('Device not found.');
            $on = $action === 'activate' ? 1 : 0;
            DB::update('isp_devices', ['is_active' => $on, 'is_default' => $on ? (int)$d['is_default'] : 0, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            Audit::log('edit', 'ispnet', 'isp_device', $id, (string)$d['name'], ['page_key' => $pk, 'reason' => $on ? 'Device switched on' : 'Device switched off: its subscribers go to the manual flow']);
            redirect(url($pk, ['id' => $id]), $on ? 'Device switched on.' : 'Device switched off. Its subscribers\' operations become work orders until another device takes them.', 'success');
        case 'default':
            Access::require($pk, 'edit');
            ispnet_require_admin();
            $d = IspNetwork::device($id) ?? fail('Device not found.');
            if (!(int)$d['is_active']) {
                fail('Switch the device on first.');
            }
            DB::run('UPDATE isp_devices SET is_default = 0');
            DB::update('isp_devices', ['is_default' => 1], 'id = :id', ['id' => $id]);
            Audit::log('edit', 'ispnet', 'isp_device', $id, (string)$d['name'], ['page_key' => $pk, 'reason' => 'Made the default device of new accounts']);
            redirect(url($pk, ['id' => $id]), $d['name'] . ' is now the default device of new accounts.', 'success');
        case 'move_accounts':
            Access::require($pk, 'edit');
            ispnet_require_admin();
            $d = IspNetwork::device($id) ?? fail('Device not found.');
            $to = (int)p('to_device_id');
            $target = $to ? IspNetwork::device($to) : null;
            if ($to && !$target) {
                fail('Choose the device that takes the accounts.');
            }
            if ($to === $id) {
                fail('Choose another device.');
            }
            $n = DB::run('UPDATE isp_accounts SET device_id = :t, updated_at = NOW() WHERE device_id = :f', ['t' => $to ?: null, 'f' => $id])->rowCount();
            Audit::log('edit', 'ispnet', 'isp_device', $id, (string)$d['name'], ['page_key' => $pk, 'reason' => $n . ' account(s) moved to ' . ($target['name'] ?? 'the default device')]);
            redirect(url($pk, ['id' => $id]), $n . ' account(s) moved to ' . ($target['name'] ?? 'the default device') . '. Provision them on the new device from Network Operations if it does not have them yet.', 'success');
        case 'delete':
            Access::require($pk, 'delete');
            ispnet_require_admin();
            $d = IspNetwork::device($id) ?? fail('Device not found.');
            $used = (int)DB::value('SELECT COUNT(*) FROM isp_accounts WHERE device_id = :d', ['d' => $id]) + (int)DB::value('SELECT COUNT(*) FROM isp_net_ops WHERE device_id = :d', ['d' => $id]);
            if ($used) {
                fail('The device has accounts or operations on record: switch it off instead of deleting it.');
            }
            DB::run('DELETE FROM isp_devices WHERE id = :id', ['id' => $id]);
            Audit::log('delete', 'ispnet', 'isp_device', $id, (string)$d['name'], ['page_key' => $pk]);
            redirect(url($pk), 'Device deleted.', 'success');
        default:
            fail('Unknown action.');
    }
});

$admin = (bool)Access::special('isp_network_admin');
$id = gint('id');
$mode = (string)g('a');
$branches = IspSetup::branches();

// ------------------------------------------------------------------ the form
if ($mode === 'new' || ($mode === 'edit' && $id)) {
    $d = $id ? IspNetwork::device($id) : null;
    if ($id && !$d) {
        redirect(url($pk), 'Device not found.', 'warning');
    }
    Access::require($pk, $d ? 'edit' : 'create');
    $adapter = (string)fv('adapter', $d, 'mikrotik');
    layout_start($d ? 'Edit device ' . $d['name'] : 'New network device', ['actions' => btn_link(url($pk, $d ? ['id' => $d['id']] : []), 'Back', 'btn-outline-secondary', 'arrow-left')]);
    if (!$admin) {
        echo '<div class="alert alert-warning">Saving needs the permission "Cable & Internet: can configure network devices and run network commands".</div>';
    }
    ?>
    <form method="post" class="card isp-card" autocomplete="off"><div class="card-body">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($d['id'] ?? 0) ?>">
      <div class="row g-2">
        <?= input_field('name', 'Name', fv('name', $d), ['required' => true, 'class' => 'col-md-5', 'extra' => 'maxlength="80" placeholder="Main PPPoE router"']) ?>
        <?= select_field('adapter', 'Adapter', IspNetwork::ADAPTER_LABELS, $adapter, ['class' => 'col-md-4', 'extra' => 'id="devAdapter"']) ?>
        <?= select_field('branch_id', 'Branch', $branches, fv('branch_id', $d), ['class' => 'col-md-3', 'blank' => 'Every branch']) ?>
        <?= input_field('host', 'Host / IP address', fv('host', $d), ['class' => 'col-md-5', 'extra' => 'maxlength="120" placeholder="10.0.0.1"']) ?>
        <?= input_field('port', 'Port', fv('port', $d), ['type' => 'number', 'step' => '1', 'class' => 'col-md-2', 'extra' => 'min="1" max="65535"']) ?>
        <?= check_field('use_tls', 'Encrypted connection (TLS)', (int)fv('use_tls', $d, 1) === 1, 'col-md-5') ?>
        <?= input_field('username', 'User name', fv('username', $d), ['class' => 'col-md-5', 'extra' => 'maxlength="80" autocomplete="off"']) ?>
        <div class="col-md-5"><label class="form-label">Password / API token</label>
          <input class="form-control form-control-sm" type="password" name="secret" value="" autocomplete="new-password" placeholder="<?= $d && $d['secret_enc'] ? 'Set: leave empty to keep it' : 'Not set' ?>">
          <?php if ($d && $d['secret_enc']): ?><div class="small text-muted mt-1">Stored encrypted: <?= h(IspCrypto::masked($d['secret_enc'])) ?></div><?php endif; ?></div>
        <?php if ($d && $d['secret_enc']): ?><?= check_field('clear_secret', 'Remove the stored secret', false, 'col-md-2') ?><?php endif; ?>
        <div class="col-12"><label class="form-label">Options (one "key = value" per line)</label>
          <textarea class="form-control form-control-sm font-monospace" name="options" rows="7" id="devOptions"><?= h((string)fv('options', $d)) ?></textarea></div>
        <div class="col-12"><div class="isp-adapter-help small text-muted" id="devHelp"></div></div>
        <?= check_field('is_active', 'Switched on', (int)fv('is_active', $d, 1) === 1, 'col-md-3') ?>
        <?= check_field('is_default', 'Default device of new accounts', (int)fv('is_default', $d, 0) === 1, 'col-md-4') ?>
        <?= check_field('confirm_sim', 'I understand the simulator is for testing', false, 'col-md-5') ?>
      </div>
      <div class="mt-3"><?= save_buttons('Save', url($pk, $d ? ['id' => $d['id']] : []), !$d) ?></div>
    </div></form>
    <script>
    (function () {
      var help = <?= json_encode(ISPNET_ADAPTER_HELP, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
      var sel = document.getElementById('devAdapter'), box = document.getElementById('devHelp'), opt = document.getElementById('devOptions');
      function esc(s) { return String(s).replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }); }
      function show() {
        var h = help[sel.value] || { about: '', options: '', notes: '' };
        box.innerHTML = '<p class="mb-1">' + esc(h.about) + '</p>' + (h.notes ? '<p class="mb-1">' + esc(h.notes) + '</p>' : '')
          + (h.options ? '<div>Example options:</div><pre class="isp-pre mb-1">' + esc(h.options) + '</pre><button type="button" class="btn btn-link btn-sm p-0" id="devUseEx">Put the example in the options</button>' : '');
        var b = document.getElementById('devUseEx');
        if (b) b.addEventListener('click', function () { if (!opt.value.trim() || confirm('Replace the options with the example?')) opt.value = h.options; });
      }
      sel.addEventListener('change', show);
      show();
    })();
    </script>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ one device
if ($id) {
    $d = IspNetwork::device($id);
    if (!$d) {
        redirect(url($pk), 'Device not found.', 'warning');
    }
    $nAcct = (int)DB::value('SELECT COUNT(*) FROM isp_accounts WHERE device_id = :d', ['d' => $id]);
    $byStatus = DB::pairs('SELECT network_state, COUNT(*) FROM isp_accounts WHERE device_id = :d GROUP BY network_state', ['d' => $id]);
    $opStats = DB::row("SELECT COUNT(*) AS n, SUM(state = 'confirmed') AS ok, SUM(state IN ('failed', 'manual')) AS bad, MAX(created_at) AS last FROM isp_net_ops WHERE device_id = :d", ['d' => $id]);
    $recent = DB::all('SELECT o.id, o.op_no, o.op_type, o.state, o.simulated, o.created_at, a.id AS account_id, a.account_no FROM isp_net_ops o JOIN isp_accounts a ON a.id = o.account_id
                        WHERE o.device_id = :d ORDER BY o.id DESC LIMIT 10', ['d' => $id]);
    $others = DB::pairs('SELECT id, name FROM isp_devices WHERE id <> :d AND is_active = 1 ORDER BY name', ['d' => $id]);
    $test = $GLOBALS['ISPNET_TEST'];
    $actions = btn_link(url($pk), 'All devices', 'btn-outline-secondary', 'arrow-left');
    if ($admin && Access::can($pk, 'edit')) {
        $actions .= ' ' . btn_link(url($pk, ['id' => $id, 'a' => 'edit']), 'Edit', 'btn-outline-primary', 'pencil');
    }
    layout_start($d['name'], ['actions' => $actions]);
    if ($d['adapter'] === 'simulator') {
        echo '<div class="alert alert-warning d-flex gap-2"><i class="bi bi-cpu"></i><div><strong>This is the integration simulator.</strong> It contacts no device. Every result it gives is marked "Simulated: no real device was changed". Do not use it for live subscribers.</div></div>';
    }
    if ($test !== null): ?>
      <div class="alert alert-<?= $test['ok'] ? 'success' : 'danger' ?>">
        <strong><?= $test['ok'] ? 'Connection test passed' : 'Connection test FAILED' ?>:</strong> <?= h(ispnet_scrub((string)$test['message'])) ?> <?= $test['simulated'] ? ispnet_sim_label(1) : '' ?>
        <?php if (!empty($test['log'])): ?><ul class="small mb-0 mt-1"><?php foreach ($test['log'] as [$c, $r, $ok]): ?><li><code><?= h(ispnet_scrub((string)$c)) ?></code>: <?= h(ispnet_scrub((string)$r)) ?></li><?php endforeach; ?></ul><?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="row g-3">
      <div class="col-lg-7"><div class="card isp-card h-100"><div class="card-body">
        <div class="isp-head">
          <div><h2 class="isp-head-title"><?= h(IspNetwork::ADAPTER_LABELS[$d['adapter']] ?? $d['adapter']) ?></h2>
            <div class="isp-head-sub"><?= IspUi::status((int)$d['is_active'] ? 'active' : 'inactive', ['active' => 'Switched on', 'inactive' => 'Switched off']) ?><?= (int)$d['is_default'] ? ' <span class="text-muted">Default device of new accounts</span>' : '' ?></div></div>
          <div class="isp-head-actions">
            <?php if ($admin && Access::can($pk, 'edit')): ?>
              <?= IspUi::actionForm('test', 'Test connection', ['id' => $id], 'btn-primary', 'activity') ?>
              <?php if ((int)$d['is_active'] && !(int)$d['is_default']): ?><?= IspUi::actionForm('default', 'Make default', ['id' => $id], 'btn-outline-primary', 'star', 'Make ' . $d['name'] . ' the default device of new accounts?') ?><?php endif; ?>
              <?= (int)$d['is_active'] ? IspUi::actionForm('deactivate', 'Switch off', ['id' => $id], 'btn-outline-danger', 'power', 'Switch off ' . $d['name'] . '? Its subscribers\' operations become work orders.') : IspUi::actionForm('activate', 'Switch on', ['id' => $id], 'btn-outline-success', 'power') ?>
            <?php endif; ?>
            <?php if ($admin && Access::can($pk, 'delete') && !$nAcct && !(int)$opStats['n']): ?>
              <?= IspUi::actionForm('delete', 'Delete', ['id' => $id], 'btn-outline-danger', 'trash', 'Delete device ' . $d['name'] . '?') ?>
            <?php endif; ?>
          </div>
        </div>
        <?= IspUi::dl([
            'Host' => $d['host'] ? h($d['host'] . ($d['port'] ? ':' . $d['port'] : '')) . ((int)$d['use_tls'] ? ' <span class="text-muted">TLS</span>' : ' <span class="text-warning-emphasis">not encrypted</span>') : null,
            'User name' => $d['username'] ? h($d['username']) : null,
            'Secret' => $d['secret_enc'] ? h(IspCrypto::masked($d['secret_enc'])) . ' <span class="text-muted">encrypted, write-only</span>' : 'Not set',
            'Branch' => h($branches[$d['branch_id']] ?? 'Every branch'),
            'Last good contact' => $d['last_ok_at'] ? h(fdatetime($d['last_ok_at'])) : 'Never',
            'Last error' => $d['last_error'] ? '<span class="text-danger">' . h(ispnet_scrub($d['last_error'])) . '</span>' : null,
        ]) ?>
        <?php if ($d['options']): ?><div class="form-label mt-2">Options</div><pre class="isp-pre small mb-0"><?= h(ispnet_scrub($d['options'])) ?></pre><?php endif; ?>
      </div></div></div>
      <div class="col-lg-5"><div class="card isp-card h-100"><div class="card-body">
        <h2 class="sec-title">Subscribers on this device</h2>
        <?= IspUi::dl(['Accounts' => number_format($nAcct)] + array_combine(array_map(fn($k) => IspAccounts::NETWORK_STATES[$k] ?? $k, array_keys($byStatus)), array_map(fn($v) => number_format((int)$v), array_values($byStatus)))) ?>
        <?= IspUi::dl(['Operations' => number_format((int)$opStats['n']), 'Confirmed' => number_format((int)$opStats['ok']), 'Failed / manual' => number_format((int)$opStats['bad']), 'Last' => $opStats['last'] ? h(fdatetime($opStats['last'])) : null]) ?>
        <?php if ($admin && Access::can($pk, 'edit') && $nAcct): ?>
          <form method="post" class="row g-2 mt-1" data-confirm="Move every account of <?= h($d['name']) ?> to the chosen device?"><?= csrf_field() ?><input type="hidden" name="action" value="move_accounts"><input type="hidden" name="id" value="<?= $id ?>">
            <?= select_field('to_device_id', 'Move its accounts to', $others, '', ['class' => 'col-8', 'blank' => 'The default device']) ?>
            <div class="col-4 d-flex align-items-end"><button class="btn btn-sm btn-outline-primary w-100">Move</button></div></form>
        <?php endif; ?>
      </div></div></div>
    </div>
    <div class="card isp-card mt-3"><div class="card-body">
      <h2 class="sec-title">Latest operations</h2>
      <?php if (!$recent): ?><?= IspUi::empty('No operation went through this device yet.') ?><?php else: ?>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table"><thead><tr><th>Operation</th><th>Account</th><th>Kind</th><th>State</th><th>When</th></tr></thead><tbody>
        <?php foreach ($recent as $r): ?><tr><td><?= safe_link('ispnet.operations', ['id' => $r['id']], $r['op_no']) ?></td><td><?= IspUi::acct($r['account_id'], $r['account_no']) ?></td>
          <td><?= h(IspNetwork::OP_TYPES[$r['op_type']] ?? $r['op_type']) ?></td><td><?= IspUi::status($r['state'], IspNetwork::STATES) ?> <?= ispnet_sim_label($r['simulated']) ?></td><td class="small"><?= h(fdatetime($r['created_at'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div></div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ the list
$rows = DB::all('SELECT d.*, (SELECT COUNT(*) FROM isp_accounts a WHERE a.device_id = d.id) AS accounts,
                        (SELECT COUNT(*) FROM isp_net_ops o WHERE o.device_id = d.id AND o.state IN (\'failed\', \'manual\')) AS problems
                   FROM isp_devices d ORDER BY d.is_active DESC, d.is_default DESC, d.name');
$noDevice = (int)DB::value('SELECT COUNT(*) FROM isp_accounts WHERE device_id IS NULL AND service_status IN (\'pending\', \'active\', \'suspended\', \'disconnected\')');
$actions = $admin && Access::can($pk, 'create') ? btn_link(url($pk, ['a' => 'new']), 'New device', 'btn-primary', 'plus-lg') : '';
layout_start('Network Devices & Integrations', ['actions' => $actions]);
echo ispnet_sim_notice();
if (!DB::value('SELECT id FROM isp_devices WHERE is_active = 1')) {
    echo '<div class="alert alert-info">No device is switched on: activations, disconnections and reconnections become work orders (the manual flow) until a device is added.</div>';
}
?>
<div class="card isp-card"><div class="card-body">
<?php if (!$rows): ?>
  <?= IspUi::empty('No network device yet. Add the PPPoE router, the RADIUS server or the OLT platform the subscribers are on.', 'hdd-network') ?>
<?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
    <thead><tr><th>Device</th><th>Adapter</th><th>Host</th><th>Secret</th><th>Branch</th><th class="num">Accounts</th><th>Status</th><th>Last contact</th><th class="no-print"></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $d): ?>
      <tr>
        <td><a href="<?= h(url($pk, ['id' => $d['id']])) ?>"><?= h($d['name']) ?></a><?= (int)$d['is_default'] ? '<div class="small text-muted">Default device</div>' : '' ?></td>
        <td class="small"><?= h(IspNetwork::ADAPTER_LABELS[$d['adapter']] ?? $d['adapter']) ?><?= $d['adapter'] === 'simulator' ? '<div>' . ispnet_sim_label(1) . '</div>' : '' ?></td>
        <td class="small"><?= h($d['host'] ? $d['host'] . ($d['port'] ? ':' . $d['port'] : '') : '') ?></td>
        <td class="small"><?= $d['secret_enc'] ? h(IspCrypto::masked($d['secret_enc'])) : '<span class="text-muted">Not set</span>' ?></td>
        <td class="small"><?= h($branches[$d['branch_id']] ?? 'Every branch') ?></td>
        <td class="num"><?= number_format((int)$d['accounts']) ?><?= (int)$d['problems'] ? '<div class="small text-danger">' . (int)$d['problems'] . ' failed / manual</div>' : '' ?></td>
        <td><?= IspUi::status((int)$d['is_active'] ? 'active' : 'inactive', ['active' => 'On', 'inactive' => 'Off']) ?></td>
        <td class="small"><?= $d['last_error'] ? '<span class="text-danger">' . h(mb_strimwidth(ispnet_scrub($d['last_error']), 0, 60, '...')) . '</span>' : h($d['last_ok_at'] ? fdatetime($d['last_ok_at']) : 'Never tested') ?></td>
        <td class="text-end no-print text-nowrap">
          <?php if ($admin && Access::can($pk, 'edit')): ?><?= IspUi::actionForm('test', 'Test', ['id' => $d['id']], 'btn-outline-primary', 'activity') ?><?php endif; ?>
          <a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pk, ['id' => $d['id']])) ?>">Open</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
<?php if ($noDevice): ?><p class="small text-muted mt-2 mb-0"><?= number_format($noDevice) ?> account(s) name no device: they use the default device<?= DB::value('SELECT name FROM isp_devices WHERE is_active = 1 ORDER BY is_default DESC, id LIMIT 1') ? ' (' . h((string)DB::value('SELECT name FROM isp_devices WHERE is_active = 1 ORDER BY is_default DESC, id LIMIT 1')) . ')' : ', or the manual flow' ?>.</p><?php endif; ?>
</div></div>
<?php
layout_end();
