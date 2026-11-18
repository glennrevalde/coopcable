<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

/** Work Orders & Dispatch: surveys, installations, repairs, relocations, replacements, recoveries and network work */
$pk = 'ispfield.workorders';
$me = IspSetup::myTechnicianId();
// a technician without the supervisor's rights sees and works on own jobs only
$onlyMine = $me && !Access::can($pk, 'create') && !Access::can($pk, 'approve') && !Access::isAdmin();
$dispatcher = !$onlyMine && Access::can($pk, 'edit');

if (g('a') === 'file') {
    $f = DB::row("SELECT * FROM isp_files WHERE id = :f AND entity_type = 'work_order'", ['f' => (int)g('fid')]);
    $w = $f ? IspWorkOrders::get((int)$f['entity_id']) : null;
    if (!$f || !$w || !ispf_may_see_wo($w)) {
        Access::denyPage($pk, 'file');
    }
    ispf_send_file($f);
}

$technicians = DB::pairs("SELECT id, CONCAT(name, IF(team IS NULL OR team = '', '', CONCAT(' (', team, ')'))) FROM isp_technicians WHERE is_active = 1 ORDER BY name");
$areas = DB::pairs("SELECT id, CONCAT(name, ' (', area_type, ')') FROM isp_areas WHERE is_active = 1 ORDER BY name");

handle_post(function () use ($pk, $dispatcher, $onlyMine) {
    $action = (string)p('action');
    $id = (int)p('id');
    if ($action === 'create') {
        Access::require($pk, 'create');
        $accountId = IspUi::postedAccount('account_id');
        if (trim((string)p('account_id_text')) !== '' && !$accountId) {
            fail('The subscriber account was not found. Pick it from the list, or leave it empty for network work.');
        }
        $f = ['wo_type' => (string)p('wo_type'), 'description' => trim((string)p('description')), 'account_id' => $accountId, 'priority' => (string)p('priority'),
            'address' => trim((string)p('address')) ?: null, 'area_id' => pint('area_id')];
        if (!$accountId && !$f['address'] && !$f['area_id']) {
            fail('Choose the subscriber account, or enter the address or the area of the work.');
        }
        $techId = pint('technician_id');
        $date = pdate('scheduled_date');
        $id = DB::tx(function () use ($f, $techId, $date) {
            $id = IspWorkOrders::create($f);
            if ($techId && $date) {
                IspWorkOrders::schedule($id, $techId, $date, (string)p('scheduled_slot', 'AM'), pint('route_seq'));
            }
            return $id;
        });
        redirect_after_save(url($pk, ['a' => 'new']), url($pk, ['id' => $id]));
    }
    $w = IspWorkOrders::get($id) ?? fail('Work order not found.');
    if (!ispf_may_see_wo($w)) {
        fail('Work order ' . $w['wo_no'] . ' is assigned to another technician.');
    }
    $back = url($pk, ['id' => $id]);
    switch ($action) {
        case 'schedule':
            Access::require($pk, 'edit');
            if (!$dispatcher) {
                fail('Only the dispatcher or supervisor assigns jobs.');
            }
            $tid = pint('technician_id') ?? fail('Choose the technician.');
            IspWorkOrders::schedule($id, $tid, (string)pdate('scheduled_date', ''), (string)p('scheduled_slot', 'AM'), pint('route_seq'));
            if (p('back') === 'board') {
                redirect(url($pk, ['view' => 'board', 'date' => (string)p('board_date')]), 'Work order ' . $w['wo_no'] . ' assigned.', 'success');
            }
            redirect($back, 'Work order scheduled. The subscriber is notified.', 'success');
        case 'issue':
            Access::require($pk, 'edit');
            if (!$dispatcher) {
                fail('Only the dispatcher, supervisor or stockroom issues materials.');
            }
            $lines = [];
            foreach ((array)($_POST['item'] ?? []) as $i => $item) {
                $qty = to_num($_POST['qty'][$i] ?? 0);
                if ((int)$item > 0 && $qty > 0) {
                    $lines[] = [(int)$item, $qty, trim((string)($_POST['serial'][$i] ?? ''))];
                } elseif ((int)$item > 0 || $qty > 0) {
                    fail('Each material line needs both the item and the quantity.');
                }
            }
            IspWorkOrders::issue($id, $lines, pdate('issue_date', today()));
            redirect($back, count($lines) . ' material line' . (count($lines) === 1 ? '' : 's') . ' issued to ' . $w['technician_name'] . '.', 'success');
        case 'accept':
        case 'start':
            Access::require($pk, 'edit');
            IspWorkOrders::step($id, $action === 'accept' ? 'accepted' : 'in_progress');
            redirect($back, $action === 'accept' ? 'Job accepted.' : 'Job started.', 'success');
        case 'complete':
            Access::require($pk, 'edit');
            $n = ispf_do_complete($pk);
            redirect($back, 'Work order completed' . ($n ? ' with ' . $n . ' photograph' . ($n === 1 ? '' : 's') : '') . '.', 'success');
        case 'verify':
            Access::require($pk, 'approve');
            IspWorkOrders::verify($id, trim((string)p('note')));
            redirect($back, 'Work order verified.', 'success');
        case 'cancel':
            Access::require($pk, 'delete');
            if ($onlyMine) {
                fail('A technician does not cancel jobs: ask the supervisor.');
            }
            IspWorkOrders::cancel($id, trim((string)p('reason')));
            redirect($back, 'Work order cancelled. Issued materials went back to the stockroom.', 'success');
        case 'photos':
            Access::require($pk, 'edit');
            $n = DB::tx(fn() => ispf_store_photos('photos', 'work_order', $id, trim((string)p('label'))));
            if (!$n) {
                fail('Choose the photographs to upload.');
            }
            Audit::log('upload', 'ispfield', 'isp_work_order', $id, (string)$w['wo_no'], ['reason' => $n . ' photograph(s)']);
            redirect($back, $n . ' photograph' . ($n === 1 ? '' : 's') . ' added.', 'success');
        case 'edit':
            Access::require($pk, 'edit');
            if (!$dispatcher) {
                fail('Only the dispatcher or supervisor changes the job.');
            }
            if (in_array($w['status'], ['completed', 'verified', 'cancelled'], true)) {
                fail('A ' . $w['status'] . ' work order is not changed.');
            }
            $set = ['description' => mb_substr(trim((string)p('description')), 0, 255), 'priority' => isset(IspWorkOrders::PRIORITIES[p('priority')]) ? (string)p('priority') : $w['priority'],
                'address' => trim((string)p('address')) ?: null, 'area_id' => pint('area_id'), 'helper_ids' => implode(',', array_map('intval', (array)($_POST['helper_ids'] ?? []))) ?: null];
            if ($set['description'] === '') {
                fail('Describe the work to do.');
            }
            DB::update('isp_work_orders', $set + ['updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            Audit::log('edit', 'ispfield', 'isp_work_order', $id, (string)$w['wo_no'], ['before' => array_intersect_key($w, $set), 'after' => $set]);
            redirect($back, 'Work order saved.', 'success');
    }
    fail('Unknown action.');
});

// ------------------------------------------------------------------ printable job order
if (g('a') === 'print' && ($id = gint('id'))) {
    Access::require($pk, 'print');
    $w = IspWorkOrders::get($id);
    if (!$w || !ispf_may_see_wo($w)) {
        Access::denyPage($pk, 'print');
    }
    $acct = $w['account_id'] ? IspAccounts::get((int)$w['account_id']) : null;
    $cust = $acct ? IspAccounts::customer((int)$acct['isp_customer_id']) : null;
    Audit::log('print', 'ispfield', 'isp_work_order', $id, (string)$w['wo_no']);
    layout_start('Job order ' . $w['wo_no'], ['bare' => true, 'no_title' => true]);
    ?>
    <div class="no-print d-flex gap-2 mb-3"><button class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button><?= btn_link(url($pk, ['id' => $id]), 'Back', 'btn-outline-secondary', 'arrow-left') ?></div>
    <div class="isp-doc">
      <?= IspUi::letterhead('JOB ORDER', '<div><strong>' . h($w['wo_no']) . '</strong></div><div>' . h(IspWorkOrders::TYPES[$w['wo_type']] ?? '') . '</div><div>Priority: ' . h(IspWorkOrders::PRIORITIES[$w['priority']] ?? '') . '</div>') ?>
      <table class="table table-sm table-bordered mt-3">
        <tr><th style="width:22%">Account</th><td><?= h($acct ? $acct['account_no'] . ' ' . $acct['account_name'] : '') ?></td><th style="width:18%">Schedule</th><td><?= h(fdate($w['scheduled_date']) . ' ' . (IspWorkOrders::SLOTS[$w['scheduled_slot']] ?? (string)$w['scheduled_slot'])) ?></td></tr>
        <tr><th>Address</th><td><?= h((string)$w['address']) ?><?= $acct && $acct['landmark'] ? '<br><span class="small">' . h($acct['landmark']) . '</span>' : '' ?></td><th>Technician</th><td><?= h((string)$w['technician_name']) ?></td></tr>
        <tr><th>Contact</th><td><?= h($cust ? (string)$cust['mobile'] : '') ?></td><th>Drop port</th><td><?= h(IspInfra::portLabel($w['port_id'] ? (int)$w['port_id'] : ($acct['port_id'] ?? null))) ?></td></tr>
        <tr><th>Work to do</th><td colspan="3"><?= nl2br(h($w['description'])) ?></td></tr>
      </table>
      <h3 class="h6">Checklist</h3>
      <ul class="list-unstyled"><?php foreach (IspWorkOrders::checklist($w) as [$done, $text]): ?><li><?= $done ? '&#9745;' : '&#9744;' ?> <?= h($text) ?></li><?php endforeach; ?></ul>
      <h3 class="h6">Materials</h3>
      <table class="table table-sm table-bordered"><thead><tr><th>Item</th><th>Serial</th><th class="num">Issued</th><th class="num">Used</th><th class="num">Returned</th></tr></thead><tbody>
      <?php $mats = ispf_materials($id); foreach ($mats as $m): ?>
        <tr><td><?= h($m['code'] . ' ' . $m['name']) ?></td><td><?= h((string)$m['serial_no']) ?></td><td class="num"><?= h(qty($m['qty_issued'])) ?></td><td class="num"><?= $m['status'] === 'issued' ? '' : h(qty($m['qty_used'])) ?></td><td class="num"><?= $m['status'] === 'issued' ? '' : h(qty($m['qty_returned'])) ?></td></tr>
      <?php endforeach; ?>
      <?php for ($i = count($mats); $i < 5; $i++): ?><tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr><?php endfor; ?>
      </tbody></table>
      <table class="table table-sm table-bordered">
        <tr><th style="width:22%">Cable used (m)</th><td><?= h($w['cable_used_m'] !== null ? qty($w['cable_used_m']) : '') ?></td><th style="width:18%">Rx / Tx (dBm)</th><td><?= h($w['rx_power_dbm'] !== null ? $w['rx_power_dbm'] . ' / ' . $w['tx_power_dbm'] : '') ?></td></tr>
        <tr><th>ONU / router serial</th><td><?= h(trim($w['onu_serial'] . ' ' . $w['router_serial'])) ?></td><th>Speed test (Mbps)</th><td><?= h($w['speed_test_down'] !== null ? qty($w['speed_test_down']) . ' / ' . qty($w['speed_test_up']) : '') ?></td></tr>
        <tr><th>Findings</th><td colspan="3" style="height:4rem"><?= nl2br(h((string)$w['findings'])) ?></td></tr>
      </table>
      <div class="row mt-4 small">
        <div class="col-4 text-center"><div style="border-top:1px solid #333;padding-top:4px"><?= h((string)$w['technician_name']) ?: '&nbsp;' ?></div>Technician</div>
        <div class="col-4 text-center"><div style="border-top:1px solid #333;padding-top:4px"><?= h((string)$w['customer_accepted_by']) ?: '&nbsp;' ?></div>Subscriber acceptance</div>
        <div class="col-4 text-center"><div style="border-top:1px solid #333;padding-top:4px">&nbsp;</div>Verified by (supervisor)</div>
      </div>
    </div>
    <?php
    layout_end();
    exit;
}

// ------------------------------------------------------------------ new work order
if (g('a') === 'new') {
    Access::require($pk, 'create');
    layout_start('New work order', ['actions' => btn_link(url($pk), 'Work orders', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <div class="card isp-card"><div class="card-body">
    <form method="post" class="row g-2">
      <?= csrf_field() ?><input type="hidden" name="action" value="create">
      <?= select_field('wo_type', 'Kind of work', IspWorkOrders::TYPES, fv('wo_type', null, g('type', 'repair')), ['class' => 'col-md-4', 'required' => true]) ?>
      <?= select_field('priority', 'Priority', IspWorkOrders::PRIORITIES, fv('priority', null, 'normal'), ['class' => 'col-md-2']) ?>
      <?= IspUi::accountField('account_id', IspUi::postedAccount('account_id') ?? gint('account_id'), 'Subscriber account (empty for network work)', 'col-md-6', false) ?>
      <?= input_field('address', 'Address of the work (when no account)', fv('address'), ['class' => 'col-md-8']) ?>
      <?= select_field('area_id', 'Area', $areas, fv('area_id'), ['blank' => '', 'class' => 'col-md-4']) ?>
      <div class="col-12"><label class="form-label">Work to do</label><textarea class="form-control form-control-sm" name="description" rows="2" maxlength="255" required><?= h((string)fv('description')) ?></textarea></div>
      <div class="col-12"><h3 class="h6 mt-2 mb-0">Schedule now (optional)</h3></div>
      <?= select_field('technician_id', 'Technician', $technicians, fv('technician_id'), ['blank' => '(assign later)', 'class' => 'col-md-4']) ?>
      <?= input_field('scheduled_date', 'Date', fv('scheduled_date'), ['type' => 'date', 'class' => 'col-md-3']) ?>
      <?= select_field('scheduled_slot', 'Time slot', IspWorkOrders::SLOTS, fv('scheduled_slot', null, 'AM'), ['class' => 'col-md-3']) ?>
      <?= input_field('route_seq', 'Route stop no.', fv('route_seq'), ['type' => 'number', 'step' => '1', 'class' => 'col-md-2']) ?>
      <div class="col-12 mt-3"><?= save_buttons('Create work order', url($pk)) ?></div>
    </form>
    </div></div>
    <?php
    layout_end();
    exit;
}

// ------------------------------------------------------------------ one work order
if ($id = gint('id')) {
    $w = IspWorkOrders::get($id);
    if (!$w || !ispf_may_see_wo($w)) {
        Access::denyPage($pk, 'view');
    }
    $acct = $w['account_id'] ? IspAccounts::get((int)$w['account_id']) : null;
    $open = in_array($w['status'], ['open', 'scheduled', 'accepted', 'in_progress'], true);
    $mats = ispf_materials($id);
    $actions = '';
    if (Access::can($pk, 'print')) {
        $actions .= btn_link(url($pk, ['a' => 'print', 'id' => $id]), 'Job order', 'btn-outline-secondary', 'printer') . ' ';
    }
    $actions .= btn_link(url($pk), 'Work orders', 'btn-outline-secondary', 'arrow-left');
    layout_start($w['wo_no'] . ' ' . (IspWorkOrders::TYPES[$w['wo_type']] ?? ''), ['actions' => $actions]);
    ?>
    <div class="isp-head">
      <div>
        <h2 class="isp-head-title"><?= $acct ? IspUi::acct($acct['id'], $acct['account_no'], $acct['account_name']) : h((string)($w['address'] ?: 'Network work')) ?></h2>
        <div class="isp-head-sub"><?= IspUi::status($w['status'], IspWorkOrders::STATUSES) ?> <?= IspUi::status($w['priority'], IspWorkOrders::PRIORITIES) ?>
          <?= $w['technician_name'] ? h($w['technician_name']) . ', ' : '' ?><?= $w['scheduled_date'] ? h(fdate($w['scheduled_date']) . ' ' . (IspWorkOrders::SLOTS[$w['scheduled_slot']] ?? (string)$w['scheduled_slot'])) : 'not scheduled' ?></div>
      </div>
      <div class="isp-head-actions">
        <?php if ($open && Access::can($pk, 'edit') && in_array($w['status'], ['open', 'scheduled'], true) && $w['technician_id']): ?><?= IspUi::actionForm('accept', 'Accept', ['id' => $id], 'btn-outline-primary', 'hand-thumbs-up') ?><?php endif; ?>
        <?php if ($open && Access::can($pk, 'edit') && in_array($w['status'], ['open', 'scheduled', 'accepted'], true) && $w['technician_id']): ?><?= IspUi::actionForm('start', 'Start', ['id' => $id], 'btn-outline-primary', 'play-fill') ?><?php endif; ?>
        <?php if ($w['status'] === 'completed' && Access::can($pk, 'approve')): ?>
          <form method="post" class="d-inline-flex gap-1" data-confirm="Verify the completed work of <?= h($w['wo_no']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="verify"><input type="hidden" name="id" value="<?= $id ?>">
            <input class="form-control form-control-sm" name="note" placeholder="Verification note" style="max-width:14rem"><button class="btn btn-sm btn-primary"><i class="bi bi-patch-check"></i> Verify</button></form>
        <?php endif; ?>
        <?php if ($open && Access::can($pk, 'delete') && !$onlyMine): ?>
          <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelWo"><i class="bi bi-x-circle"></i> Cancel</button>
        <?php endif; ?>
      </div>
    </div>
    <div class="row g-3">
      <div class="col-lg-5">
        <div class="card isp-card"><div class="card-body">
          <h2 class="sec-title">Details</h2>
          <?= IspUi::dl([
              'Work to do' => nl2br(h($w['description'])),
              'Address' => h((string)$w['address']),
              'Area' => h((string)DB::value('SELECT name FROM isp_areas WHERE id = :a', ['a' => (int)$w['area_id']])),
              'Application' => $w['application_id'] ? safe_link('isp.applications', ['id' => (int)$w['application_id']], (string)DB::value('SELECT application_no FROM isp_applications WHERE id = :a', ['a' => $w['application_id']])) : '',
              'Ticket' => $w['ticket_id'] ? safe_link('ispfield.tickets', ['id' => (int)$w['ticket_id']], (string)DB::value('SELECT ticket_no FROM isp_tickets WHERE id = :t', ['t' => $w['ticket_id']])) : '',
              'Outage' => $w['outage_id'] ? safe_link('ispnet.outages', ['id' => (int)$w['outage_id']], (string)DB::value('SELECT outage_no FROM isp_outages WHERE id = :o', ['o' => $w['outage_id']])) : '',
              'Network operation' => $w['net_op_id'] ? safe_link('ispnet.operations', ['id' => (int)$w['net_op_id']], (string)DB::value('SELECT op_no FROM isp_net_ops WHERE id = :o', ['o' => $w['net_op_id']])) : '',
              'Drop port' => h(IspInfra::portLabel($w['port_id'] ? (int)$w['port_id'] : ($acct['port_id'] ?? null))),
              'Created' => h(fdatetime($w['created_at'])),
              'Accepted' => h(fdatetime($w['accepted_at'])),
              'Started' => h(fdatetime($w['started_at'])),
              'Completed' => h(fdatetime($w['completed_at'])),
              'Verified' => $w['verified_at'] ? h(fdatetime($w['verified_at']) . ' by ' . DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => $w['verified_by']])) : '',
              'Cancelled' => $w['cancel_reason'] ? h($w['cancel_reason']) : '',
          ]) ?>
          <?php if (!$open && $w['status'] !== 'cancelled'): ?>
            <h3 class="h6 mt-3">Result</h3>
            <?= IspUi::dl(['Findings' => nl2br(h((string)$w['findings'])), 'Remarks' => nl2br(h((string)$w['remarks'])), 'Cable used' => $w['cable_used_m'] !== null ? h(qty($w['cable_used_m']) . ' m') : '',
                'Rx / Tx power' => $w['rx_power_dbm'] !== null ? h($w['rx_power_dbm'] . ' / ' . $w['tx_power_dbm'] . ' dBm') : '', 'Speed test' => $w['speed_test_down'] !== null ? h(qty($w['speed_test_down']) . ' down, ' . qty($w['speed_test_up']) . ' up Mbps') : '',
                'ONU / ONT' => h((string)$w['onu_serial']), 'Router' => h((string)$w['router_serial']), 'Labor' => $w['labor_hours'] !== null ? h(qty($w['labor_hours']) . ' h') : '',
                'Labor and travel paid' => ispf_supervisor() && ((float)$w['labor_cost'] + (float)$w['travel_expense']) > 0 ? h(money((float)$w['labor_cost'] + (float)$w['travel_expense'])) : '',
                'Accepted by' => $w['customer_accepted_by'] ? h($w['customer_accepted_by'] . ', ' . fdatetime($w['customer_accepted_at'])) : '']) ?>
            <ul class="list-unstyled small mb-0"><?php foreach (IspWorkOrders::checklist($w) as [$done, $text]): ?><li><i class="bi bi-<?= $done ? 'check2-square text-success' : 'square text-muted' ?>"></i> <?= h($text) ?></li><?php endforeach; ?></ul>
          <?php endif; ?>
        </div></div>

        <?php if ($dispatcher && $open): ?>
        <div class="card isp-card mt-3"><div class="card-body">
          <h2 class="sec-title">Assign and schedule</h2>
          <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="schedule"><input type="hidden" name="id" value="<?= $id ?>">
            <?= select_field('technician_id', 'Technician', $technicians, fv('technician_id', $w), ['blank' => '', 'class' => 'col-md-6', 'required' => true]) ?>
            <?= input_field('scheduled_date', 'Date', fv('scheduled_date', $w, today()), ['type' => 'date', 'class' => 'col-md-6', 'required' => true]) ?>
            <?= select_field('scheduled_slot', 'Time slot', IspWorkOrders::SLOTS, fv('scheduled_slot', $w, 'AM'), ['class' => 'col-md-6']) ?>
            <?= input_field('route_seq', 'Route stop no.', fv('route_seq', $w), ['type' => 'number', 'step' => '1', 'class' => 'col-md-6']) ?>
            <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-calendar-check"></i> Save schedule</button></div>
          </form>
        </div></div>
        <div class="card isp-card mt-3"><div class="card-body">
          <h2 class="sec-title">Edit the job</h2>
          <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?= $id ?>">
            <div class="col-12"><label class="form-label">Work to do</label><textarea class="form-control form-control-sm" name="description" rows="2" maxlength="255" required><?= h((string)fv('description', $w)) ?></textarea></div>
            <?= select_field('priority', 'Priority', IspWorkOrders::PRIORITIES, fv('priority', $w), ['class' => 'col-md-4']) ?>
            <?= select_field('area_id', 'Area', $areas, fv('area_id', $w), ['blank' => '', 'class' => 'col-md-8']) ?>
            <?= input_field('address', 'Address', fv('address', $w), ['class' => 'col-12']) ?>
            <?= multi_check_field('helper_ids', 'Helpers', $technicians, array_filter(explode(',', (string)$w['helper_ids'])), ['class' => 'col-12', 'all' => 'No helper', 'noun' => 'technicians']) ?>
            <div class="col-12"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-check-lg"></i> Save</button></div>
          </form>
        </div></div>
        <?php endif; ?>
      </div>

      <div class="col-lg-7">
        <div class="card isp-card"><div class="card-body">
          <h2 class="sec-title">Materials</h2>
          <?php if ($mats): ?>
          <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
            <thead><tr><th>Item</th><th>Serial</th><th class="num">Issued</th><th class="num">Used</th><th class="num">Returned</th><?= Access::viewCosts() ? '<th class="num">Unit cost</th>' : '' ?><th>Status</th></tr></thead><tbody>
            <?php foreach ($mats as $m): ?>
              <tr><td><?= h($m['code'] . ' ' . $m['name']) ?></td><td><?= h((string)$m['serial_no']) ?></td><td class="num"><?= h(qty($m['qty_issued'])) ?></td>
                <td class="num"><?= $m['status'] === 'issued' ? '' : h(qty($m['qty_used'])) ?></td><td class="num"><?= $m['status'] === 'issued' ? '' : h(qty($m['qty_returned'])) ?></td>
                <?= Access::viewCosts() ? '<td class="num">' . h(money($m['unit_cost'])) . '</td>' : '' ?><td><?= IspUi::status($m['status']) ?></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <?php else: ?><p class="small text-muted">No materials issued yet.</p><?php endif; ?>
          <?php if ($dispatcher && $open): ?>
            <?php $stock = ispf_stock_items(); ?>
            <h3 class="h6 mt-3">Issue materials to <?= h((string)($w['technician_name'] ?: 'the technician')) ?></h3>
            <?php if (!$w['technician_id']): ?><p class="small text-muted mb-0">Assign the technician first: the materials are issued to the technician's van.</p><?php else: ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="issue"><input type="hidden" name="id" value="<?= $id ?>">
              <div class="table-responsive"><table class="table table-sm align-middle isp-table"><thead><tr><th>Item</th><th style="width:7rem">Quantity</th><th style="width:11rem">Serial (ONU / router)</th></tr></thead><tbody>
              <?php for ($i = 0; $i < 5; $i++): ?>
                <tr><td><select class="form-select form-select-sm" name="item[<?= $i ?>]"><?= options($stock, $_POST['item'][$i] ?? null, '') ?></select></td>
                  <td><input class="form-control form-control-sm text-end" type="number" step="0.01" min="0" name="qty[<?= $i ?>]" value="<?= h((string)($_POST['qty'][$i] ?? '')) ?>"></td>
                  <td><input class="form-control form-control-sm" name="serial[<?= $i ?>]" value="<?= h((string)($_POST['serial'][$i] ?? '')) ?>" placeholder="one unit per line"></td></tr>
              <?php endfor; ?>
              </tbody></table></div>
              <div class="d-flex gap-2 align-items-end"><div><label class="form-label">Date</label><input class="form-control form-control-sm" type="date" name="issue_date" value="<?= h((string)fv('issue_date', null, today())) ?>"></div>
                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-box-arrow-right"></i> Issue from the stockroom</button></div>
              <p class="small text-muted mt-2 mb-0">A serialized unit is entered one per line with quantity 1; it moves in the equipment register with the technician.</p>
            </form>
            <?php endif; ?>
          <?php endif; ?>
        </div></div>

        <?php if ($open && Access::can($pk, 'edit')): ?>
        <div class="card isp-card mt-3"><div class="card-body">
          <h2 class="sec-title">Complete the job</h2>
          <?= ispf_complete_form($w, $pk) ?>
        </div></div>
        <?php endif; ?>

        <div class="card isp-card mt-3"><div class="card-body">
          <h2 class="sec-title">Photographs</h2>
          <?= ispf_gallery(ispf_files('work_order', $id), $pk) ?>
          <?php if (Access::can($pk, 'edit') && $w['status'] !== 'cancelled'): ?>
          <form method="post" enctype="multipart/form-data" class="row g-2 mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="photos"><input type="hidden" name="id" value="<?= $id ?>">
            <div class="col-md-5"><input class="form-control form-control-sm" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required></div>
            <div class="col-md-4"><input class="form-control form-control-sm" name="label" placeholder="What the photo shows" maxlength="120"></div>
            <div class="col-md-3"><button class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-camera"></i> Upload</button></div>
          </form>
          <?php endif; ?>
        </div></div>
      </div>
    </div>
    <?php if ($open && Access::can($pk, 'delete') && !$onlyMine): ?>
      <?= reason_modal('cancelWo', 'Cancel work order', url($pk, ['id' => $id]), 'btn-danger', '<input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="' . $id . '">') ?>
    <?php endif; ?>
    <?php
    layout_end();
    exit;
}

// ------------------------------------------------------------------ dispatch board
$view = g('view') === 'board' && !$onlyMine ? 'board' : 'list';
$actions = page_link($pk, ['a' => 'new'], 'New work order', 'btn-primary', 'plus-lg', 'create');
layout_start('Work Orders & Dispatch', ['actions' => $actions]);
if (!$onlyMine) {
    echo IspUi::tabs(['list' => 'List', 'board' => 'Dispatch board'], $view, $pk, [], 'view');
}

if ($view === 'board') {
    $start = gdate('date', today());
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $days[] = date('Y-m-d', strtotime($start . " +$i days"));
    }
    $p = ['f' => $days[0], 't' => $days[6]];
    $b = IspSetup::branchSql('a.branch_id', $p);
    $jobs = DB::all("SELECT w.*, a.account_no, a.account_name FROM isp_work_orders w LEFT JOIN isp_accounts a ON a.id = w.account_id
        WHERE w.status IN ('scheduled','accepted','in_progress','completed') AND w.scheduled_date BETWEEN :f AND :t$b ORDER BY w.scheduled_date, FIELD(w.scheduled_slot, 'AM', 'PM', 'EVE'), w.route_seq, w.id", $p);
    $grid = [];
    foreach ($jobs as $j) {
        $grid[(int)$j['technician_id']][$j['scheduled_date']][] = $j;
    }
    $pu = [];
    $bu = IspSetup::branchSql('a.branch_id', $pu);
    $unassigned = DB::all("SELECT w.*, a.account_no, a.account_name FROM isp_work_orders w LEFT JOIN isp_accounts a ON a.id = w.account_id
        WHERE w.status = 'open' AND (w.technician_id IS NULL OR w.scheduled_date IS NULL)$bu ORDER BY FIELD(w.priority, 'urgent', 'high', 'normal', 'low'), w.id LIMIT 60", $pu);
    ?>
    <form class="isp-filter no-print" method="get" action="<?= h(base_url() . '/index.php') ?>"><input type="hidden" name="p" value="<?= h($pk) ?>"><input type="hidden" name="view" value="board">
      <?= IspUi::fDate('date', 'Week starting', $start) ?>
      <div class="isp-filter-actions"><button class="btn btn-sm btn-primary"><i class="bi bi-calendar-week"></i> Show</button>
        <a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pk, ['view' => 'board', 'date' => date('Y-m-d', strtotime($start . ' -7 days'))])) ?>"><i class="bi bi-chevron-left"></i></a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pk, ['view' => 'board', 'date' => date('Y-m-d', strtotime($start . ' +7 days'))])) ?>"><i class="bi bi-chevron-right"></i></a></div>
    </form>
    <?php if ($unassigned): ?>
    <div class="card isp-card mb-3"><div class="card-body">
      <h2 class="sec-title">Waiting for a technician (<?= count($unassigned) ?>)</h2>
      <div class="table-responsive"><table class="table table-sm align-middle isp-table"><thead><tr><th>Work order</th><th>Account / address</th><th>Priority</th><th>Created</th><?= Access::can($pk, 'edit') ? '<th style="min-width:26rem">Assign</th>' : '' ?></tr></thead><tbody>
      <?php foreach ($unassigned as $u): ?>
        <tr><td><a href="<?= h(url($pk, ['id' => (int)$u['id']])) ?>"><?= h($u['wo_no']) ?></a><div class="small text-muted"><?= h(IspWorkOrders::TYPES[$u['wo_type']] ?? '') ?></div></td>
          <td><?= h(trim($u['account_no'] . ' ' . $u['account_name'])) ?><div class="small text-muted"><?= h((string)$u['address']) ?></div></td>
          <td><?= IspUi::status($u['priority'], IspWorkOrders::PRIORITIES) ?></td><td class="small"><?= h(IspUi::ago($u['created_at'])) ?></td>
          <?php if (Access::can($pk, 'edit')): ?>
          <td><form method="post" class="d-flex gap-1 flex-wrap"><?= csrf_field() ?><input type="hidden" name="action" value="schedule"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <input type="hidden" name="back" value="board"><input type="hidden" name="board_date" value="<?= h($start) ?>">
            <select class="form-select form-select-sm" name="technician_id" required style="max-width:12rem"><?= options($technicians, null, 'Technician') ?></select>
            <input class="form-control form-control-sm" type="date" name="scheduled_date" value="<?= h($start) ?>" required style="max-width:9.5rem">
            <select class="form-select form-select-sm" name="scheduled_slot" style="max-width:8rem"><?= options(IspWorkOrders::SLOTS, 'AM') ?></select>
            <button class="btn btn-sm btn-primary"><i class="bi bi-person-check"></i></button></form></td>
          <?php endif; ?></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    </div></div>
    <?php endif; ?>
    <div class="card isp-card"><div class="card-body">
      <h2 class="sec-title">Jobs by technician, <?= h(fdate($days[0]) . ' to ' . fdate($days[6])) ?></h2>
      <div class="table-responsive"><table class="table table-sm table-bordered align-top isp-table isp-board">
        <thead><tr><th style="min-width:9rem">Technician</th><?php foreach ($days as $d): ?><th style="min-width:11rem"<?= $d === today() ? ' class="table-active"' : '' ?>><?= h(date('D m/d', strtotime($d))) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php $shown = 0; foreach ($technicians as $tid => $tname): $shown++; ?>
          <tr><th class="small"><?= h($tname) ?><div class="text-muted fw-normal"><?= array_sum(array_map('count', $grid[(int)$tid] ?? [])) ?> job(s)</div></th>
          <?php foreach ($days as $d): ?><td>
            <?php foreach ($grid[(int)$tid][$d] ?? [] as $j): ?>
              <a class="d-block small border rounded px-2 py-1 mb-1 text-decoration-none" href="<?= h(url($pk, ['id' => (int)$j['id']])) ?>">
                <span class="fw-semibold"><?= h((string)$j['scheduled_slot']) ?> <?= h($j['wo_no']) ?></span><br><?= h(IspWorkOrders::TYPES[$j['wo_type']] ?? '') ?><br>
                <span class="text-muted"><?= h(mb_substr(trim((string)($j['account_name'] ?: $j['address'])), 0, 34)) ?></span><br><?= IspUi::status($j['status'], IspWorkOrders::STATUSES) ?></a>
            <?php endforeach; ?>
          </td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        <?php if (!$shown): ?><tr><td colspan="8"><?= IspUi::empty('No active technician yet: add them on Technicians & Teams.', 'person-badge') ?></td></tr><?php endif; ?>
      </tbody></table></div>
    </div></div>
    <?php
    layout_end();
    exit;
}

// ------------------------------------------------------------------ the list
$q = trim((string)g('q'));
$type = isset(IspWorkOrders::TYPES[g('type')]) ? (string)g('type') : '';
$status = (string)g('status', 'active');
$tech = gint('technician_id');
$date = gdate('date');
$area = gint('area_id');
$p = [];
$where = ['1 = 1'];
if ($onlyMine) {
    $where[] = 'w.technician_id = :me';
    $p['me'] = $me;
}
if ($q !== '') {
    $where[] = '(w.wo_no LIKE :q1 OR a.account_no LIKE :q2 OR a.account_name LIKE :q3 OR w.address LIKE :q4 OR w.description LIKE :q5)';
    $p += ['q1' => "%$q%", 'q2' => "$q%", 'q3' => "%$q%", 'q4' => "%$q%", 'q5' => "%$q%"];
}
if ($type !== '') {
    $where[] = 'w.wo_type = :ty';
    $p['ty'] = $type;
}
if ($status === 'active') {
    $where[] = "w.status IN ('open','scheduled','accepted','in_progress')";
} elseif (isset(IspWorkOrders::STATUSES[$status])) {
    $where[] = 'w.status = :st';
    $p['st'] = $status;
}
if ($tech) {
    $where[] = 'w.technician_id = :te';
    $p['te'] = $tech;
}
if ($date) {
    $where[] = 'w.scheduled_date = :dt';
    $p['dt'] = $date;
}
if ($area) {
    $where[] = 'w.area_id = :ar';
    $p['ar'] = $area;
}
$b = IspSetup::branchSql('a.branch_id', $p);
$sqlFrom = 'FROM isp_work_orders w LEFT JOIN isp_accounts a ON a.id = w.account_id LEFT JOIN isp_technicians t ON t.id = w.technician_id WHERE ' . implode(' AND ', $where) . $b;
[$per, $off, $page] = IspUi::paging(50);
$total = (int)DB::value("SELECT COUNT(*) $sqlFrom", $p);
$rows = DB::all("SELECT w.*, a.account_no, a.account_name, t.name AS technician_name $sqlFrom
    ORDER BY FIELD(w.status, 'in_progress', 'accepted', 'scheduled', 'open', 'completed', 'verified', 'cancelled'), w.scheduled_date IS NULL, w.scheduled_date, FIELD(w.priority, 'urgent', 'high', 'normal', 'low'), w.id DESC LIMIT $per OFFSET $off", $p);
$pc = [];
$bc = IspSetup::branchSql('a.branch_id', $pc);
$k = DB::row("SELECT SUM(w.status = 'open') AS open_n, SUM(w.status IN ('scheduled','accepted') AND w.scheduled_date = CURDATE()) AS today_n, SUM(w.status = 'in_progress') AS prog_n,
    SUM(w.status = 'completed') AS verify_n FROM isp_work_orders w LEFT JOIN isp_accounts a ON a.id = w.account_id WHERE 1 = 1" . ($onlyMine ? ' AND w.technician_id = ' . (int)$me : '') . $bc, $pc);
?>
<div class="kpi-grid">
  <?= IspUi::kpi('Waiting for a technician', number_format((int)$k['open_n']), 'open work orders', url($pk, ['status' => 'open']), 'inbox', 'warn') ?>
  <?= IspUi::kpi('Scheduled today', number_format((int)$k['today_n']), fdate(today()), url($pk, ['status' => 'active', 'date' => today()]), 'calendar-event', 'info') ?>
  <?= IspUi::kpi('In progress', number_format((int)$k['prog_n']), 'on site now', url($pk, ['status' => 'in_progress']), 'tools', 'info') ?>
  <?= IspUi::kpi('To verify', number_format((int)$k['verify_n']), 'completed, not yet verified', url($pk, ['status' => 'completed']), 'patch-check', 'ok') ?>
</div>
<?= IspUi::filterStart($pk) ?>
  <?= IspUi::fSearch($q, 'WO no., account, address') ?>
  <?= IspUi::fSelect('type', 'Kind', IspWorkOrders::TYPES, $type) ?>
  <?= IspUi::fSelect('status', 'Status', ['active' => 'Open and in progress'] + IspWorkOrders::STATUSES, $status, 'All') ?>
  <?php if (!$onlyMine): ?><?= IspUi::fSelect('technician_id', 'Technician', $technicians, $tech) ?><?php endif; ?>
  <?= IspUi::fDate('date', 'Scheduled on', $date) ?>
  <?= IspUi::fSelect('area_id', 'Area', $areas, $area) ?>
<?= IspUi::filterEnd(url($pk)) ?>
<div class="card isp-card"><div class="card-body">
<?php if (!$rows): ?>
  <?= IspUi::empty('No work order matches.', 'clipboard') ?>
<?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
    <thead><tr><th>Work order</th><th>Kind</th><th>Account / address</th><th>Technician</th><th>Schedule</th><th>Priority</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><a href="<?= h(url($pk, ['id' => (int)$r['id']])) ?>"><?= h($r['wo_no']) ?></a></td><td><?= h(IspWorkOrders::TYPES[$r['wo_type']] ?? $r['wo_type']) ?></td>
        <td><?= $r['account_id'] ? h($r['account_no'] . ' ' . $r['account_name']) : '' ?><div class="small text-muted"><?= h(mb_substr((string)$r['address'], 0, 60)) ?></div></td>
        <td><?= h((string)$r['technician_name']) ?></td><td class="text-nowrap"><?= h(fdate($r['scheduled_date'])) ?> <?= h((string)$r['scheduled_slot']) ?></td>
        <td><?= IspUi::status($r['priority'], IspWorkOrders::PRIORITIES) ?></td><td><?= IspUi::status($r['status'], IspWorkOrders::STATUSES) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?= IspUi::pager($total, $per, $page) ?>
<?php endif; ?>
</div></div>
<?php
layout_end();
