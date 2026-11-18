<?php
declare(strict_types=1);

/**
 * Network Outages (Workflow H): an outage is registered (suspected or verified, planned maintenance or not), the
 * subscribers below the failed node or in the area are found from the topology, told once it is verified (and on
 * the portal), the repair is followed to the restoration, the downtime of each subscriber is set against the SLA and
 * the service credits the policy gives are proposed, approved and posted as credit adjustments.
 */
require_once __DIR__ . '/_common.php';

$pk = 'ispnet.outages';

handle_post(function () use ($pk) {
    $action = (string)p('action');
    $id = (int)p('id');
    if ($id && !IspOutages::get($id)) {
        fail('Outage not found.');
    }
    switch ($action) {
        case 'create':
            Access::require($pk, 'create');
            $f = ['title' => trim((string)p('title')), 'scope' => (string)p('scope'), 'node_id' => pint('node_id'), 'area_id' => pint('area_id'), 'is_planned' => p('is_planned') === '1',
                'verified' => p('verified') === '1', 'started_at' => (string)p('started_at'), 'sla_hours' => (string)p('sla_hours'), 'credit_policy' => (string)p('credit_policy'),
                'credit_fixed' => pnum('credit_fixed'), 'root_cause' => trim((string)p('root_cause'))];
            $newId = IspOutages::create($f);
            $o = IspOutages::get($newId);
            redirect(url($pk, ['id' => $newId]), 'Outage ' . $o['outage_no'] . ' registered: ' . (int)$o['affected_count'] . ' subscriber(s) affected' . ($o['status'] === 'verified' ? ' and told.' : '. They are told once it is verified.'), 'success');
        case 'move':
            Access::require($pk, 'edit');
            $to = (string)p('to');
            if (!isset(IspOutages::STATUSES[$to])) {
                fail('Choose the new status.');
            }
            IspOutages::move($id, $to, ['root_cause' => trim((string)p('root_cause')), 'repair_notes' => trim((string)p('repair_notes')), 'restored_at' => (string)p('restored_at')]);
            redirect(url($pk, ['id' => $id]), 'Outage now ' . IspOutages::STATUSES[$to] . '.', 'success');
        case 'identify':
            Access::require($pk, 'edit');
            $n = IspOutages::identify($id);
            redirect(url($pk, ['id' => $id]), $n . ' subscriber(s) affected after checking the topology again.', 'success');
        case 'announce':
            Access::require($pk, 'edit');
            $o = IspOutages::get($id);
            if ($o['status'] === 'suspected') {
                fail('A suspected outage is not announced: verify it first.');
            }
            $n = IspOutages::announce($id);
            redirect(url($pk, ['id' => $id]), $n . ' notification(s) queued; the portal shows the announcement.', 'success');
        case 'add_account':
            Access::require($pk, 'edit');
            $acct = IspUi::postedAccount('account_id') ?? fail('Choose the subscriber account.');
            if (DB::value('SELECT id FROM isp_outage_accounts WHERE outage_id = :o AND account_id = :a', ['o' => $id, 'a' => $acct])) {
                fail('That account is already on the outage.');
            }
            DB::insert('isp_outage_accounts', ['outage_id' => $id, 'account_id' => $acct, 'how_found' => 'manual']);
            DB::run('UPDATE isp_outages SET affected_count = (SELECT COUNT(*) FROM isp_outage_accounts WHERE outage_id = :o) WHERE id = :id', ['o' => $id, 'id' => $id]);
            IspAccounts::event($acct, 'outage', 'Affected by outage ' . IspOutages::get($id)['outage_no'], 'outage', $id);
            redirect(url($pk, ['id' => $id]), 'Account added to the outage.', 'success');
        case 'remove_account':
            Access::require($pk, 'edit');
            $oa = DB::row('SELECT * FROM isp_outage_accounts WHERE id = :x AND outage_id = :o', ['x' => (int)p('oa_id'), 'o' => $id]) ?? fail('Not on this outage.');
            if ($oa['adjustment_id']) {
                fail('Its service credit is posted: the account stays on the outage.');
            }
            DB::run('DELETE FROM isp_outage_accounts WHERE id = :x', ['x' => $oa['id']]);
            DB::run('UPDATE isp_outages SET affected_count = (SELECT COUNT(*) FROM isp_outage_accounts WHERE outage_id = :o) WHERE id = :id', ['o' => $id, 'id' => $id]);
            redirect(url($pk, ['id' => $id]), 'Account removed from the outage.', 'success');
        case 'note':
            Access::require($pk, 'edit');
            $note = trim((string)p('repair_notes'));
            if ($note === '') {
                fail('Write the note.');
            }
            $o = IspOutages::get($id);
            DB::update('isp_outages', ['repair_notes' => trim(($o['repair_notes'] ?? '') . "\n" . date('Y-m-d H:i') . ' ' . $note), 'root_cause' => trim((string)p('root_cause')) ?: $o['root_cause'],
                'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            Audit::log('note', 'ispnet', 'isp_outage', $id, (string)$o['outage_no'], ['page_key' => $pk, 'reason' => $note]);
            redirect(url($pk, ['id' => $id]), 'Note added.', 'success');
        case 'work_order':
            Access::require($pk, 'edit');
            $o = IspOutages::get($id);
            $wo = IspWorkOrders::create(['wo_type' => 'maintenance', 'outage_id' => $id, 'priority' => (string)p('priority', 'urgent'), 'area_id' => $o['area_id'] ?: ($o['node_id'] ? DB::value('SELECT area_id FROM isp_net_nodes WHERE id = :n', ['n' => $o['node_id']]) : null),
                'address' => $o['node_id'] ? (string)DB::value('SELECT address FROM isp_net_nodes WHERE id = :n', ['n' => $o['node_id']]) ?: null : null,
                'description' => mb_substr('Repair for outage ' . $o['outage_no'] . ': ' . $o['title'] . (trim((string)p('details')) !== '' ? '. ' . trim((string)p('details')) : ''), 0, 255)]);
            if (($o['status'] ?? '') === 'verified') {
                IspOutages::move($id, 'in_repair', ['repair_notes' => 'Repair work order ' . DB::value('SELECT wo_no FROM isp_work_orders WHERE id = :w', ['w' => $wo]) . ' created']);
            }
            redirect(url($pk, ['id' => $id]), 'Repair work order ' . DB::value('SELECT wo_no FROM isp_work_orders WHERE id = :w', ['w' => $wo]) . ' created.', 'success');
        case 'compute':
            Access::require($pk, 'edit');
            IspOutages::computeCredits($id);
            redirect(url($pk, ['id' => $id]), 'Downtime and service credits computed again.', 'success');
        case 'post_credits':
            Access::require($pk, 'approve');
            $n = IspOutages::postCredits($id);
            redirect(url($pk, ['id' => $id]), $n . ' service credit(s) posted.', 'success');
        case 'announcement':
            Access::require($pk, 'edit');
            $an = DB::row('SELECT * FROM isp_announcements WHERE outage_id = :o', ['o' => $id]) ?? fail('This outage has no announcement yet: announce it first.');
            $title = trim((string)p('title'));
            $body = trim((string)p('body'));
            if ($title === '' || $body === '') {
                fail('Enter the title and the text of the announcement.');
            }
            $ends = trim((string)p('ends_at'));
            DB::update('isp_announcements', ['title' => mb_substr($title, 0, 160), 'body' => $body, 'is_active' => p('is_active') === '1' ? 1 : 0,
                'ends_at' => $ends !== '' ? date('Y-m-d H:i:s', strtotime($ends)) : null], 'id = :id', ['id' => $an['id']]);
            Audit::log('edit', 'ispnet', 'isp_announcement', (int)$an['id'], $title, ['page_key' => $pk]);
            redirect(url($pk, ['id' => $id]), 'Announcement saved.', 'success');
        default:
            fail('Unknown action.');
    }
});

$id = gint('id');
$areas = DB::pairs("SELECT id, CONCAT(name, ' (', area_type, ')') FROM isp_areas WHERE is_active = 1 ORDER BY area_type = 'province' DESC, area_type = 'municipality' DESC, name");
$nodes = IspInfra::nodeOptions();
$mode = (string)g('a');

// ------------------------------------------------------------------ declaring an outage
if ($mode === 'new') {
    Access::require($pk, 'create');
    $nodeId = gint('node_id');
    $preview = $nodeId ? count(IspInfra::affectedAccounts($nodeId)) : null;
    layout_start('Declare an outage', ['actions' => btn_link(url($pk), 'Outages', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <form method="post" class="card isp-card"><div class="card-body">
      <?= csrf_field() ?><input type="hidden" name="action" value="create">
      <div class="row g-2">
        <?= input_field('title', 'What is down', fv('title', null, $nodeId ? 'Service interruption at ' . ($nodes[$nodeId] ?? '') : ''), ['required' => true, 'class' => 'col-md-8', 'extra' => 'maxlength="160" placeholder="Fiber cut along Rizal St."']) ?>
        <?= select_field('scope', 'Scope', IspOutages::SCOPES, fv('scope', null, $nodeId ? 'node' : 'node'), ['class' => 'col-md-4', 'extra' => 'id="oScope"']) ?>
        <?= select_field('node_id', 'Network node', $nodes, fv('node_id', null, $nodeId), ['class' => 'col-md-6 o-node', 'blank' => 'Choose the node']) ?>
        <?= select_field('area_id', 'Service area', $areas, fv('area_id'), ['class' => 'col-md-6 o-area', 'blank' => 'Choose the area']) ?>
        <div class="col-md-4"><label class="form-label">Started</label><input class="form-control form-control-sm" type="datetime-local" name="started_at" value="<?= h((string)fv('started_at', null, date('Y-m-d\TH:i'))) ?>"></div>
        <?= input_field('sla_hours', 'SLA (hours of downtime before credits)', fv('sla_hours', null, '24'), ['type' => 'number', 'step' => '0.5', 'class' => 'col-md-4', 'extra' => 'min="0"']) ?>
        <?= select_field('credit_policy', 'Service credit', IspOutages::CREDIT_POLICIES, fv('credit_policy', null, 'prorated'), ['class' => 'col-md-4']) ?>
        <?= input_field('credit_fixed', 'Fixed credit per account', fv('credit_fixed', null, ''), ['type' => 'number', 'class' => 'col-md-4', 'extra' => 'min="0"']) ?>
        <?= input_field('root_cause', 'Root cause (if known)', fv('root_cause'), ['class' => 'col-md-8', 'extra' => 'maxlength="255"']) ?>
        <?= check_field('verified', 'Verified (confirmed down): tell the subscribers now', (string)fv('verified', null, '0') === '1', 'col-md-6') ?>
        <?= check_field('is_planned', 'Planned maintenance (no service credits)', (string)fv('is_planned', null, '0') === '1', 'col-md-6') ?>
      </div>
      <?php if ($preview !== null): ?><p class="small text-muted mt-2 mb-0">The topology below <?= h($nodes[$nodeId] ?? '') ?> serves <?= number_format($preview) ?> subscriber(s).</p><?php endif; ?>
      <p class="small text-muted mt-2">A suspected outage is not announced: a single subscriber's problem stays a support ticket. Declaring it on a node marks the node damaged until it is restored.</p>
      <div class="mt-2"><button class="btn btn-sm btn-primary"><i class="bi bi-exclamation-triangle"></i> Register the outage</button> <a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pk)) ?>">Cancel</a></div>
    </div></form>
    <script>
    (function () {
      var s = document.getElementById('oScope');
      function show() {
        document.querySelectorAll('.o-node').forEach(function (e) { e.hidden = s.value !== 'node'; });
        document.querySelectorAll('.o-area').forEach(function (e) { e.hidden = s.value !== 'area'; });
      }
      s.addEventListener('change', show);
      show();
    })();
    </script>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ one outage
if ($id) {
    $o = IspOutages::get($id);
    if (!$o) {
        redirect(url($pk), 'Outage not found.', 'warning');
    }
    $end = $o['restored_at'] ?: date('Y-m-d H:i:s');
    $hours = round((strtotime($end) - strtotime($o['started_at'])) / 3600, 2);
    $breach = (int)$o['is_planned'] !== 1 && $hours > (float)$o['sla_hours'];
    [$per, $off, $page] = IspUi::paging(100);
    $total = (int)DB::value('SELECT COUNT(*) FROM isp_outage_accounts WHERE outage_id = :o', ['o' => $id]);
    $affected = DB::all("SELECT oa.*, a.account_no, a.account_name, a.service_status, a.install_address, j.adj_no, j.status AS adj_status FROM isp_outage_accounts oa JOIN isp_accounts a ON a.id = oa.account_id
                          LEFT JOIN isp_adjustments j ON j.id = oa.adjustment_id WHERE oa.outage_id = :o ORDER BY a.account_no LIMIT $per OFFSET $off", ['o' => $id]);
    $cred = DB::row('SELECT COALESCE(SUM(credit_amount), 0) AS total, SUM(credit_amount > 0) AS n, SUM(notified_at IS NOT NULL) AS told FROM isp_outage_accounts WHERE outage_id = :o', ['o' => $id]);
    $tickets = DB::all('SELECT t.id, t.ticket_no, t.subject, t.status, a.account_no FROM isp_tickets t LEFT JOIN isp_accounts a ON a.id = t.account_id WHERE t.outage_id = :o ORDER BY t.id DESC', ['o' => $id]);
    $wos = DB::all('SELECT w.id, w.wo_no, w.status, w.description, t.name AS tech FROM isp_work_orders w LEFT JOIN isp_technicians t ON t.id = w.technician_id WHERE w.outage_id = :o ORDER BY w.id DESC', ['o' => $id]);
    $an = DB::row('SELECT * FROM isp_announcements WHERE outage_id = :o', ['o' => $id]);
    $moves = ['suspected' => ['verified' => 'Verify (confirmed down)', 'false_alarm' => 'False alarm'], 'verified' => ['in_repair' => 'Repair started', 'restored' => 'Restored'],
        'in_repair' => ['restored' => 'Restored'], 'restored' => ['closed' => 'Close', 'in_repair' => 'Reopen (down again)'], 'closed' => [], 'false_alarm' => []][$o['status']] ?? [];
    $canEdit = Access::can($pk, 'edit');
    layout_start('Outage ' . $o['outage_no'], ['actions' => btn_link(url($pk), 'Outages', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <div class="card isp-card mb-3"><div class="card-body">
      <div class="isp-head">
        <div>
          <h2 class="isp-head-title"><?= h($o['title']) ?></h2>
          <div class="isp-head-sub"><?= IspUi::status($o['status'], IspOutages::STATUSES) ?> <span class="text-muted"><?= (int)$o['is_planned'] ? 'Planned maintenance' : 'Unplanned' ?></span></div>
        </div>
        <div class="isp-head-actions">
          <?php if ($canEdit): foreach ($moves as $to => $label): ?>
            <button class="btn btn-sm <?= in_array($to, ['false_alarm', 'in_repair'], true) && $o['status'] !== 'verified' ? 'btn-outline-secondary' : 'btn-primary' ?>" data-bs-toggle="modal" data-bs-target="#moveO" data-to="<?= h($to) ?>" data-label="<?= h($label) ?>"><?= h($label) ?></button>
          <?php endforeach; endif; ?>
          <?php if ($canEdit && in_array($o['status'], ['suspected', 'verified', 'in_repair'], true)): ?>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#woO"><i class="bi bi-tools"></i> Repair work order</button>
          <?php endif; ?>
        </div>
      </div>
      <div class="row g-3">
        <div class="col-lg-6"><?= IspUi::dl([
            'Outage no.' => h($o['outage_no']),
            'Scope' => h(IspOutages::SCOPES[$o['scope']] ?? $o['scope']),
            'Node' => $o['node_id'] ? (Access::page('ispnet.infra') ? '<a href="' . h(url('ispnet.infra', ['node' => $o['node_id']])) . '">' . h($o['node_code'] . ' ' . $o['node_name']) . '</a>' : h($o['node_code'] . ' ' . $o['node_name'])) : null,
            'Area' => $o['area_id'] ? h($o['area_name']) : null,
            'Started' => h(fdatetime($o['started_at'])),
            'Verified' => $o['verified_at'] ? h(fdatetime($o['verified_at'])) : null,
            'Restored' => $o['restored_at'] ? h(fdatetime($o['restored_at'])) : null,
            'Closed' => $o['closed_at'] ? h(fdatetime($o['closed_at'])) : null,
        ]) ?></div>
        <div class="col-lg-6"><?= IspUi::dl([
            'Downtime' => h(number_format($hours, 1) . ' hours' . ($o['restored_at'] ? '' : ' so far')),
            'SLA' => h(number_format((float)$o['sla_hours'], 1) . ' hours') . ' ' . ((int)$o['is_planned'] ? '<span class="text-muted">planned: no credit</span>' : ($breach ? '<span class="text-danger">Breached by ' . h(number_format($hours - (float)$o['sla_hours'], 1)) . ' h</span>' : '<span class="text-success">Within the SLA</span>')),
            'Credit policy' => h(IspOutages::CREDIT_POLICIES[$o['credit_policy']] ?? $o['credit_policy']) . ($o['credit_policy'] === 'fixed' ? ' ' . h(money($o['credit_fixed'])) : ''),
            'Credits' => h(($o['credits_status'] ?: 'none') === 'none' ? 'None' : ucfirst((string)$o['credits_status'])) . ((float)$cred['total'] > 0 ? ': ' . h(money($cred['total']) . ' to ' . (int)$cred['n'] . ' account(s)') : ''),
            'Subscribers' => h(number_format((int)$o['affected_count']) . ' affected, ' . number_format((int)$cred['told']) . ' told'),
            'Root cause' => $o['root_cause'] ? h($o['root_cause']) : '<span class="text-muted">Not recorded yet</span>',
        ]) ?></div>
      </div>
      <?php if ($o['repair_notes']): ?><div class="form-label mt-2">Repair log</div><pre class="isp-pre small mb-0"><?= h(trim((string)$o['repair_notes'])) ?></pre><?php endif; ?>
      <?php if ($canEdit): ?>
      <form method="post" class="row g-2 mt-2 no-print"><?= csrf_field() ?><input type="hidden" name="action" value="note"><input type="hidden" name="id" value="<?= $id ?>">
        <?= input_field('repair_notes', 'Add to the repair log', '', ['class' => 'col-md-6', 'extra' => 'maxlength="250"']) ?>
        <?= input_field('root_cause', 'Root cause', $o['root_cause'], ['class' => 'col-md-4', 'extra' => 'maxlength="255"']) ?>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-sm btn-outline-primary w-100">Add</button></div>
      </form>
      <?php endif; ?>
    </div></div>

    <div class="row g-3 mb-3">
      <div class="col-lg-6"><div class="card isp-card h-100"><div class="card-body">
        <h2 class="sec-title">Repair work and tickets</h2>
        <?php if (!$wos && !$tickets): ?><?= IspUi::empty('No work order or ticket is linked yet.', 'tools') ?><?php endif; ?>
        <?php foreach ($wos as $w): ?><div class="d-flex justify-content-between gap-2 border-bottom py-1"><span><?= safe_link('ispfield.workorders', ['id' => $w['id']], $w['wo_no']) ?> <span class="small text-muted"><?= h(mb_strimwidth((string)$w['description'], 0, 70, '...')) ?><?= $w['tech'] ? ', ' . h($w['tech']) : '' ?></span></span><?= IspUi::status($w['status'], IspWorkOrders::STATUSES) ?></div><?php endforeach; ?>
        <?php foreach ($tickets as $t): ?><div class="d-flex justify-content-between gap-2 border-bottom py-1"><span><?= safe_link('ispfield.tickets', ['id' => $t['id']], $t['ticket_no']) ?> <span class="small text-muted"><?= h($t['account_no'] . ' ' . mb_strimwidth((string)$t['subject'], 0, 60, '...')) ?></span></span><?= IspUi::status($t['status'], IspTickets::STATUSES) ?></div><?php endforeach; ?>
      </div></div></div>
      <div class="col-lg-6"><div class="card isp-card h-100"><div class="card-body">
        <h2 class="sec-title">Portal announcement</h2>
        <?php if (!$an): ?>
          <p class="small text-muted"><?= $o['status'] === 'suspected' ? 'Shown on the portal once the outage is verified.' : 'Not announced yet.' ?></p>
          <?php if ($canEdit && !in_array($o['status'], ['suspected', 'false_alarm'], true)): ?><?= IspUi::actionForm('announce', 'Announce now', ['id' => $id], 'btn-primary', 'megaphone') ?><?php endif; ?>
        <?php else: ?>
          <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="announcement"><input type="hidden" name="id" value="<?= $id ?>">
            <?= input_field('title', 'Title', $an['title'], ['class' => 'col-12', 'required' => true, 'extra' => 'maxlength="160"' . ($canEdit ? '' : ' disabled')]) ?>
            <div class="col-12"><label class="form-label">Text</label><textarea class="form-control form-control-sm" name="body" rows="3" required <?= $canEdit ? '' : 'disabled' ?>><?= h($an['body']) ?></textarea></div>
            <div class="col-md-6"><label class="form-label">Shown until</label><input class="form-control form-control-sm" type="datetime-local" name="ends_at" value="<?= h($an['ends_at'] ? date('Y-m-d\TH:i', strtotime((string)$an['ends_at'])) : '') ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
            <?= check_field('is_active', 'Shown on the portal', (int)$an['is_active'] === 1, 'col-md-6') ?>
            <?php if ($canEdit): ?><div class="col-12 d-flex gap-2"><button class="btn btn-sm btn-outline-primary">Save the announcement</button></div><?php endif; ?>
          </form>
          <?php if ($canEdit && (int)$cred['told'] < (int)$o['affected_count']): ?><div class="mt-2"><?= IspUi::actionForm('announce', 'Tell the ' . ((int)$o['affected_count'] - (int)$cred['told']) . ' not yet told', ['id' => $id], 'btn-outline-secondary', 'megaphone') ?></div><?php endif; ?>
        <?php endif; ?>
      </div></div></div>
    </div>

    <div class="card isp-card"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <h2 class="sec-title mb-0">Affected subscribers</h2>
        <div class="d-flex gap-1 flex-wrap no-print">
          <?php if ($canEdit && !in_array($o['status'], ['closed', 'false_alarm'], true)): ?><?= IspUi::actionForm('identify', 'Check the topology again', ['id' => $id], 'btn-outline-secondary', 'diagram-3') ?><?php endif; ?>
          <?php if ($canEdit && in_array($o['status'], ['restored', 'closed'], true) && $o['credits_status'] !== 'posted'): ?><?= IspUi::actionForm('compute', 'Compute credits', ['id' => $id], 'btn-outline-primary', 'calculator') ?><?php endif; ?>
          <?php if (Access::can($pk, 'approve') && $o['credits_status'] === 'proposed' && Access::special('isp_write_off')): ?><?= IspUi::actionForm('post_credits', 'Approve and post credits', ['id' => $id], 'btn-primary', 'check2-square', 'Post ' . money($cred['total']) . ' of service credits to ' . (int)$cred['n'] . ' account(s)?') ?><?php endif; ?>
        </div>
      </div>
      <?php if ($canEdit && !in_array($o['status'], ['closed', 'false_alarm'], true)): ?>
      <form method="post" class="row g-2 mb-2 no-print"><?= csrf_field() ?><input type="hidden" name="action" value="add_account"><input type="hidden" name="id" value="<?= $id ?>">
        <?= IspUi::accountField('account_id', null, 'Add an account not found by the topology', 'col-md-8') ?>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-sm btn-outline-primary w-100">Add</button></div>
      </form>
      <?php endif; ?>
      <?php if (!$affected): ?><?= IspUi::empty('No subscriber found below this node or in this area.', 'people') ?><?php else: ?>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
        <thead><tr><th>Account</th><th>Address</th><th>Found by</th><th>Told</th><th class="num">Down (h)</th><th class="num">Credit</th><th>Adjustment</th><th class="no-print"></th></tr></thead><tbody>
        <?php foreach ($affected as $x): ?>
          <tr><td><?= IspUi::acct($x['account_id'], $x['account_no'], $x['account_name']) ?></td><td class="small"><?= IspUi::sensitive($x['install_address']) ?></td>
            <td class="small"><?= h(['topology' => 'Topology', 'area' => 'Area', 'manual' => 'Added by hand'][$x['how_found']] ?? $x['how_found']) ?></td>
            <td class="small"><?= $x['notified_at'] ? h(fdatetime($x['notified_at'])) : '<span class="text-muted">Not yet</span>' ?></td>
            <td class="num"><?= $x['downtime_hours'] !== null ? h(number_format((float)$x['downtime_hours'], 1)) : '' ?></td>
            <td class="num"><?= h(money($x['credit_amount'], true)) ?></td>
            <td class="small"><?= $x['adj_no'] ? safe_link('ispbill.adjustments', ['id' => $x['adjustment_id']], $x['adj_no']) . ' ' . IspUi::status($x['adj_status'], IspCredit::ADJ_STATUSES) : '' ?></td>
            <td class="text-end no-print"><?php if ($canEdit && !$x['adjustment_id'] && !in_array($o['status'], ['closed'], true)): ?><?= IspUi::actionForm('remove_account', 'Remove', ['id' => $id, 'oa_id' => $x['id']], 'btn-outline-secondary', '', 'Remove ' . $x['account_no'] . ' from this outage?') ?><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?= IspUi::pager($total, $per, $page) ?>
      <?php endif; ?>
    </div></div>

    <?php if ($canEdit): ?>
    <div class="modal fade" id="moveO" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="to" value="">
      <div class="modal-header"><h5 class="modal-title" data-title>Change the status</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><div class="row g-2">
        <?= input_field('root_cause', 'Root cause (needed to restore)', $o['root_cause'], ['class' => 'col-12', 'extra' => 'maxlength="255"']) ?>
        <div class="col-md-6 m-restored"><label class="form-label">Restored at</label><input class="form-control form-control-sm" type="datetime-local" name="restored_at" value="<?= h(date('Y-m-d\TH:i')) ?>"></div>
        <div class="col-12"><label class="form-label">Note for the repair log</label><textarea class="form-control form-control-sm" name="repair_notes" rows="2"></textarea></div>
      </div><p class="small text-muted mt-2 mb-0 m-restored">Restoring tells the affected subscribers, computes each one's downtime and proposes the service credits the policy gives.</p></div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button class="btn btn-primary" data-ok>Save</button></div>
    </form></div></div>
    <div class="modal fade" id="woO" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="work_order"><input type="hidden" name="id" value="<?= $id ?>">
      <div class="modal-header"><h5 class="modal-title">Repair work order</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><div class="row g-2">
        <?= select_field('priority', 'Priority', IspWorkOrders::PRIORITIES, 'urgent', ['class' => 'col-md-5']) ?>
        <div class="col-12"><label class="form-label">Details for the crew</label><textarea class="form-control form-control-sm" name="details" rows="2"></textarea></div>
      </div><p class="small text-muted mt-2 mb-0">The technical supervisor schedules it and assigns the crew from Work Orders.</p></div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button class="btn btn-primary">Create</button></div>
    </form></div></div>
    <script>
    (function () {
      var m = document.getElementById('moveO');
      m.addEventListener('show.bs.modal', function (e) {
        var b = e.relatedTarget, to = b.getAttribute('data-to');
        m.querySelector('input[name=to]').value = to;
        m.querySelector('[data-title]').textContent = b.getAttribute('data-label');
        m.querySelector('[data-ok]').textContent = b.getAttribute('data-label');
        m.querySelectorAll('.m-restored').forEach(function (x) { x.hidden = to !== 'restored'; });
      });
    })();
    </script>
    <?php endif; ?>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ the list
$tabs = ['active' => 'Active', 'restored' => 'Restored', 'closed' => 'Closed', 'false_alarm' => 'False alarms', 'all' => 'All'];
$tab = (string)g('tab', 'active');
if (!isset($tabs[$tab])) {
    $tab = 'active';
}
$qtext = trim((string)g('q'));
$from = gdate('from');
$to = gdate('to');
$planned = (string)g('planned');
$params = [];
$where = ' WHERE 1 = 1' . match ($tab) {
    'active' => " AND o.status IN ('suspected', 'verified', 'in_repair')",
    'all' => '',
    default => ' AND o.status = ' . DB::pdo()->quote($tab),
};
if ($qtext !== '') {
    $where .= ' AND (o.outage_no LIKE :q1 OR o.title LIKE :q2 OR n.code LIKE :q3)';
    $params += ['q1' => $qtext . '%', 'q2' => '%' . $qtext . '%', 'q3' => $qtext . '%'];
}
if ($planned === '1' || $planned === '0') {
    $where .= ' AND o.is_planned = :pl';
    $params['pl'] = (int)$planned;
}
if ($from) {
    $where .= ' AND o.started_at >= :f';
    $params['f'] = $from . ' 00:00:00';
}
if ($to) {
    $where .= ' AND o.started_at <= :t';
    $params['t'] = $to . ' 23:59:59';
}
[$per, $off, $page] = IspUi::paging(50);
$total = (int)DB::value("SELECT COUNT(*) FROM isp_outages o LEFT JOIN isp_net_nodes n ON n.id = o.node_id$where", $params);
$rows = DB::all("SELECT o.*, n.code AS node_code, ar.name AS area_name, (SELECT COALESCE(SUM(credit_amount), 0) FROM isp_outage_accounts x WHERE x.outage_id = o.id) AS credits
                   FROM isp_outages o LEFT JOIN isp_net_nodes n ON n.id = o.node_id LEFT JOIN isp_areas ar ON ar.id = o.area_id$where ORDER BY o.started_at DESC LIMIT $per OFFSET $off", $params);
$sum = DB::row("SELECT SUM(status IN ('suspected', 'verified', 'in_repair')) AS active, SUM(CASE WHEN status IN ('suspected', 'verified', 'in_repair') THEN affected_count ELSE 0 END) AS affected,
                       SUM(credits_status = 'proposed') AS proposed, SUM(status IN ('restored', 'closed') AND started_at >= :m) AS month FROM isp_outages", ['m' => date('Y-m-01') . ' 00:00:00']);
layout_start('Network Outages', ['actions' => page_link($pk, ['a' => 'new'], 'Declare an outage', 'btn-primary', 'exclamation-triangle', 'create')]);
?>
<div class="kpi-grid mb-3">
  <?= IspUi::kpi('Active outages', number_format((int)$sum['active']), 'suspected, verified or in repair', url($pk, ['tab' => 'active']), 'exclamation-triangle', (int)$sum['active'] ? 'bad' : 'ok') ?>
  <?= IspUi::kpi('Subscribers affected', number_format((int)$sum['affected']), 'by the active outages', null, 'people', 'warn') ?>
  <?= IspUi::kpi('Credits to approve', number_format((int)$sum['proposed']), 'restored outages with proposed credits', url($pk, ['tab' => 'restored']), 'cash-coin', 'info') ?>
  <?= IspUi::kpi('Restored this month', number_format((int)$sum['month']), 'outages', url($pk, ['tab' => 'all', 'from' => date('Y-m-01')]), 'check2-circle', 'ok') ?>
</div>
<?= IspUi::tabs($tabs, $tab, $pk, array_filter(['q' => $qtext, 'planned' => $planned, 'from' => $from, 'to' => $to])) ?>
<?= IspUi::filterStart($pk, ['tab' => $tab]) ?>
  <?= IspUi::fSearch($qtext, 'Outage no., title or node') ?>
  <?= IspUi::fSelect('planned', 'Kind', ['0' => 'Unplanned', '1' => 'Planned maintenance'], $planned) ?>
  <?= IspUi::fDate('from', 'Started from', $from) ?>
  <?= IspUi::fDate('to', 'To', $to) ?>
<?= IspUi::filterEnd(url($pk, ['tab' => $tab])) ?>
<div class="card isp-card"><div class="card-body">
<?php if (!$rows): ?>
  <?= IspUi::empty($tab === 'active' ? 'No active outage. The network is up.' : 'No outage in this list.', 'broadcast-pin') ?>
<?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
    <thead><tr><th>Outage</th><th>What is down</th><th>Where</th><th>Status</th><th>Started</th><th class="num">Hours</th><th class="num">Affected</th><th class="num">Credits</th></tr></thead><tbody>
    <?php foreach ($rows as $r):
        $hrs = round((strtotime($r['restored_at'] ?: date('Y-m-d H:i:s')) - strtotime($r['started_at'])) / 3600, 1); ?>
      <tr>
        <td><a href="<?= h(url($pk, ['id' => $r['id']])) ?>"><?= h($r['outage_no']) ?></a></td>
        <td><?= h($r['title']) ?><?= (int)$r['is_planned'] ? '<div class="small text-muted">Planned maintenance</div>' : '' ?></td>
        <td class="small"><?= h($r['scope'] === 'node' ? (string)$r['node_code'] : ($r['scope'] === 'area' ? (string)$r['area_name'] : 'Whole network')) ?></td>
        <td><?= IspUi::status($r['status'], IspOutages::STATUSES) ?></td>
        <td class="small text-nowrap"><?= h(fdatetime($r['started_at'])) ?></td>
        <td class="num<?= !(int)$r['is_planned'] && $hrs > (float)$r['sla_hours'] ? ' text-danger' : '' ?>"><?= h(number_format($hrs, 1)) ?></td>
        <td class="num"><?= number_format((int)$r['affected_count']) ?></td>
        <td class="num"><?= h(money($r['credits'], true)) ?><?= $r['credits_status'] === 'proposed' ? '<div class="small text-muted">to approve</div>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?= IspUi::pager($total, $per, $page) ?>
<?php endif; ?>
</div></div>
<?php
layout_end();
