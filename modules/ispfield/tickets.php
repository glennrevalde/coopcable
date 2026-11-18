<?php
declare(strict_types=1);

/**
 * Support Tickets (Workflow E): New, Classified, Assigned, In progress, On hold, Resolved, Verified, Closed; SLA
 * deadlines by priority, escalation, dispatch of a repair work order, root cause and resolution, the subscriber's
 * rating, NTC complaints flagged.
 */
$pk = 'ispfield.tickets';
$me = IspSetup::myTechnicianId();
$onlyMine = $me && !Access::can($pk, 'create') && !Access::isAdmin();
$technicians = DB::pairs("SELECT id, CONCAT(name, IF(team IS NULL OR team = '', '', CONCAT(' (', team, ')'))) FROM isp_technicians WHERE is_active = 1 ORDER BY name");
$staff = DB::pairs("SELECT id, full_name FROM users WHERE status = 'active' AND is_platform = 0 AND created_via <> 'system' ORDER BY full_name");

/** May the user see the ticket? */
$maySee = function (array $t) use ($onlyMine, $me): bool {
    return !$onlyMine || (int)$t['technician_id'] === (int)$me;
};

handle_post(function () use ($pk, $maySee, $onlyMine) {
    $action = (string)p('action');
    if ($action === 'create') {
        Access::require($pk, 'create');
        $accountId = IspUi::postedAccount('account_id');
        if (trim((string)p('account_id_text')) !== '' && !$accountId) {
            fail('The subscriber account was not found. Pick it from the list, or leave it empty.');
        }
        $id = IspTickets::create(['account_id' => $accountId, 'ticket_type' => (string)p('ticket_type'), 'subject' => (string)p('subject'), 'description' => trim((string)p('description')),
            'priority' => (string)p('priority'), 'source' => (string)p('source'), 'ntc_complaint' => p('ntc_complaint') === '1']);
        redirect_after_save(url($pk, ['a' => 'new']), url($pk, ['id' => $id]));
    }
    $id = (int)p('id');
    $t = IspTickets::get($id) ?? fail('Ticket not found.');
    if (!$maySee($t)) {
        fail('Ticket ' . $t['ticket_no'] . ' is assigned to another technician.');
    }
    $back = url($pk, ['id' => $id]);
    switch ($action) {
        case 'move':
            Access::require($pk, 'edit');
            $to = (string)p('to');
            if ($onlyMine && in_array($to, ['assigned', 'verified', 'closed'], true)) {
                fail('The supervisor or the support desk assigns, verifies and closes tickets.');
            }
            IspTickets::move($id, $to, ['note' => trim((string)p('note')), 'root_cause' => trim((string)p('root_cause')), 'resolution' => trim((string)p('resolution')),
                'technician_id' => pint('technician_id'), 'assigned_user_id' => pint('assigned_user_id'), 'ticket_type' => (string)p('ticket_type'), 'priority' => (string)p('priority'),
                'rating' => pint('rating'), 'feedback' => trim((string)p('feedback'))]);
            Audit::log('status', 'ispfield', 'isp_ticket', $id, (string)$t['ticket_no'], ['reason' => $t['status'] . ' -> ' . $to]);
            redirect($back, 'Ticket ' . strtolower(IspTickets::STATUSES[$to] ?? $to) . '.', 'success');
        case 'note':
            Access::require($pk, 'edit');
            $text = trim((string)p('note'));
            if ($text === '') {
                fail('Write the note.');
            }
            IspTickets::note($id, $text, null, p('is_public') === '1');
            redirect($back, 'Note added.', 'success');
        case 'dispatch':
            Access::require($pk, 'edit');
            if ($onlyMine) {
                fail('The supervisor dispatches the work orders.');
            }
            if (!$t['account_id']) {
                fail('A repair work order needs the subscriber account: this ticket has none.');
            }
            $wo = DB::tx(fn() => IspTickets::dispatch($id, pint('technician_id'), pdate('scheduled_date'), (string)p('scheduled_slot', 'AM')));
            redirect($back, 'Work order ' . DB::value('SELECT wo_no FROM isp_work_orders WHERE id = :w', ['w' => $wo]) . ' created.', 'success');
        case 'escalate':
            Access::require($pk, 'edit');
            if (in_array($t['status'], ['resolved', 'verified', 'closed'], true)) {
                fail('A ' . strtolower(IspTickets::STATUSES[$t['status']]) . ' ticket is not escalated.');
            }
            $why = trim((string)p('reason'));
            if ($why === '') {
                fail('Enter why the ticket is escalated.');
            }
            DB::tx(function () use ($id, $t, $why) {
                $prio = ['low' => 'normal', 'normal' => 'high', 'high' => 'critical', 'critical' => 'critical'][$t['priority']] ?? 'high';
                DB::update('isp_tickets', ['escalation_level' => (int)$t['escalation_level'] + 1, 'priority' => $prio,
                    'sla_due_at' => date('Y-m-d H:i:s', time() + IspTickets::slaHours($prio) * 3600), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
                IspTickets::note($id, 'Escalated to level ' . ((int)$t['escalation_level'] + 1) . ' (' . IspTickets::PRIORITIES[$prio] . '): ' . $why, null, false);
                Audit::log('escalate', 'ispfield', 'isp_ticket', $id, (string)$t['ticket_no'], ['reason' => $why]);
            });
            redirect($back, 'Ticket escalated.', 'success');
        case 'edit':
            Access::require($pk, 'edit');
            if (in_array($t['status'], ['closed'], true)) {
                fail('A closed ticket is not changed.');
            }
            $set = ['subject' => mb_substr(trim((string)p('subject')), 0, 160), 'description' => trim((string)p('description')) ?: null, 'ntc_complaint' => p('ntc_complaint') === '1' ? 1 : 0];
            if ($set['subject'] === '') {
                fail('Enter the subject.');
            }
            DB::update('isp_tickets', $set + ['updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            Audit::log('edit', 'ispfield', 'isp_ticket', $id, (string)$t['ticket_no'], ['before' => array_intersect_key($t, $set), 'after' => $set]);
            redirect($back, 'Ticket saved.', 'success');
    }
    fail('Unknown action.');
});

// ------------------------------------------------------------------ new ticket
if (g('a') === 'new') {
    Access::require($pk, 'create');
    layout_start('New support ticket', ['actions' => btn_link(url($pk), 'Tickets', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <div class="card isp-card"><div class="card-body">
    <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="create">
      <?= IspUi::accountField('account_id', IspUi::postedAccount('account_id') ?? gint('account_id'), 'Subscriber account', 'col-md-6', false) ?>
      <?= select_field('ticket_type', 'Concern', IspTickets::TYPES, fv('ticket_type', null, g('type', 'no_internet')), ['class' => 'col-md-3', 'required' => true]) ?>
      <?= select_field('source', 'Received by', IspTickets::SOURCES, fv('source', null, 'phone'), ['class' => 'col-md-3']) ?>
      <?= input_field('subject', 'Subject', fv('subject'), ['class' => 'col-md-9', 'required' => true, 'extra' => 'maxlength="160"']) ?>
      <?= select_field('priority', 'Priority', IspTickets::PRIORITIES, fv('priority'), ['class' => 'col-md-3', 'blank' => '(by the kind of concern)']) ?>
      <div class="col-12"><label class="form-label">What the subscriber reports</label><textarea class="form-control form-control-sm" name="description" rows="3"><?= h((string)fv('description')) ?></textarea></div>
      <?= check_field('ntc_complaint', 'Consumer complaint to be reported (NTC)', fv('ntc_complaint') === '1', 'col-md-6') ?>
      <div class="col-12 small text-muted">The SLA deadline follows the priority (ISP Setup &gt; Billing &amp; Disconnection Policies). A third ticket of the same account within 30 days is escalated at once; an account inside a network outage is linked to it.</div>
      <div class="col-12 mt-2"><?= save_buttons('Create ticket', url($pk)) ?></div>
    </form>
    </div></div>
    <?php
    layout_end();
    exit;
}

// ------------------------------------------------------------------ one ticket
if ($id = gint('id')) {
    $t = IspTickets::get($id);
    if (!$t || !$maySee($t)) {
        Access::denyPage($pk, 'view');
    }
    $notes = DB::all('SELECT n.*, u.full_name FROM isp_ticket_notes n LEFT JOIN users u ON u.id = n.user_id WHERE n.ticket_id = :t ORDER BY n.id DESC', ['t' => $id]);
    $wo = $t['work_order_id'] ? DB::row('SELECT id, wo_no, status, scheduled_date, technician_id FROM isp_work_orders WHERE id = :w', ['w' => $t['work_order_id']]) : null;
    $outage = $t['outage_id'] ? DB::row('SELECT id, outage_no, title, status FROM isp_outages WHERE id = :o', ['o' => $t['outage_id']]) : null;
    $moves = IspTickets::moves((string)$t['status']);
    if ($onlyMine) {
        $moves = array_values(array_diff($moves, ['assigned', 'verified', 'closed']));
    }
    $active = !in_array($t['status'], ['resolved', 'verified', 'closed'], true);
    $breached = $active && $t['sla_due_at'] && strtotime((string)$t['sla_due_at']) < time();
    $to = (string)(is_post() && p('action') === 'move' ? p('to') : ($moves[0] ?? ''));
    layout_start($t['ticket_no'] . ' ' . $t['subject'], ['actions' => btn_link(url($pk), 'Tickets', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <div class="isp-head">
      <div>
        <h2 class="isp-head-title"><?= $t['account_id'] ? IspUi::acct($t['account_id'], $t['account_no'], $t['account_name']) : 'No account' ?></h2>
        <div class="isp-head-sub"><?= IspUi::status($t['status'], IspTickets::STATUSES) ?> <?= IspUi::status($t['priority'], IspTickets::PRIORITIES) ?>
          <?= h(IspTickets::TYPES[$t['ticket_type']] ?? '') ?><?= (int)$t['escalation_level'] > 0 ? ', escalation level ' . (int)$t['escalation_level'] : '' ?>
          <?php if ($active && $t['sla_due_at']): ?>, SLA <?= $breached ? IspUi::status('overdue', [], 'bad') . ' since ' . h(fdatetime($t['sla_due_at'])) : 'due ' . h(fdatetime($t['sla_due_at'])) . ' (' . h(IspUi::ago($t['sla_due_at'])) . ')' ?><?php endif; ?></div>
      </div>
      <div class="isp-head-actions">
        <?php if ($active && Access::can($pk, 'edit')): ?><button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#escalate"><i class="bi bi-arrow-up-circle"></i> Escalate</button><?php endif; ?>
      </div>
    </div>
    <div class="row g-3">
      <div class="col-lg-5">
        <div class="card isp-card"><div class="card-body">
          <h2 class="sec-title">Details</h2>
          <?= IspUi::dl([
              'Reported' => h(fdatetime($t['created_at']) . ', ' . (IspTickets::SOURCES[$t['source']] ?? $t['source'])),
              'Description' => nl2br(h((string)$t['description'])),
              'Address' => h((string)$t['install_address']),
              'NTC complaint' => (int)$t['ntc_complaint'] === 1 ? 'Yes' : '',
              'Technician' => h((string)$t['technician_name']),
              'Assigned to' => h((string)$t['assigned_name']),
              'Work order' => $wo ? safe_link('ispfield.workorders', ['id' => (int)$wo['id']], $wo['wo_no']) . ' ' . IspUi::status($wo['status'], IspWorkOrders::STATUSES) : '',
              'Network outage' => $outage ? safe_link('ispnet.outages', ['id' => (int)$outage['id']], $outage['outage_no'] . ' ' . $outage['title']) . ' ' . IspUi::status($outage['status'], IspOutages::STATUSES) : '',
              'Root cause' => nl2br(h((string)$t['root_cause'])),
              'Resolution' => nl2br(h((string)$t['resolution'])),
              'Resolved' => h(fdatetime($t['resolved_at'])),
              'Closed' => h(fdatetime($t['closed_at'])),
              'Rating' => $t['feedback_rating'] ? str_repeat('&#9733;', (int)$t['feedback_rating']) . str_repeat('&#9734;', 5 - (int)$t['feedback_rating']) . ' ' . h((string)$t['feedback_text']) : '',
          ]) ?>
        </div></div>
        <?php if (Access::can($pk, 'edit') && $t['status'] !== 'closed'): ?>
        <div class="card isp-card mt-3"><div class="card-body">
          <h2 class="sec-title">Edit</h2>
          <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?= $id ?>">
            <?= input_field('subject', 'Subject', fv('subject', $t), ['class' => 'col-12', 'required' => true]) ?>
            <div class="col-12"><label class="form-label">Description</label><textarea class="form-control form-control-sm" name="description" rows="2"><?= h((string)fv('description', $t)) ?></textarea></div>
            <?= check_field('ntc_complaint', 'Consumer complaint to be reported (NTC)', (string)fv('ntc_complaint', $t) === '1', 'col-12') ?>
            <div class="col-12"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-check-lg"></i> Save</button></div>
          </form>
        </div></div>
        <?php endif; ?>
      </div>
      <div class="col-lg-7">
        <?php if ($moves && Access::can($pk, 'edit')): ?>
        <div class="card isp-card"><div class="card-body">
          <h2 class="sec-title">Move the ticket</h2>
          <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= $id ?>">
            <div class="col-md-4"><label class="form-label">Next status</label><select class="form-select form-select-sm" name="to" id="tkTo"><?= options(array_intersect_key(IspTickets::STATUSES, array_flip($moves)), $to) ?></select></div>
            <?= select_field('priority', 'Priority', IspTickets::PRIORITIES, fv('priority', $t), ['class' => 'col-md-4']) ?>
            <?= select_field('ticket_type', 'Concern', IspTickets::TYPES, fv('ticket_type', $t), ['class' => 'col-md-4']) ?>
            <div class="col-12 tk-for" data-for="assigned"><div class="row g-2">
              <?= select_field('technician_id', 'Technician', $technicians, fv('technician_id', $t), ['blank' => '', 'class' => 'col-md-6']) ?>
              <?= select_field('assigned_user_id', 'Or a staff member', $staff, fv('assigned_user_id', $t), ['blank' => '', 'class' => 'col-md-6']) ?></div></div>
            <div class="col-12 tk-for" data-for="resolved"><div class="row g-2">
              <div class="col-md-6"><label class="form-label">Root cause<?= in_array($t['ticket_type'], IspTickets::TECHNICAL, true) ? ' (required)' : '' ?></label><textarea class="form-control form-control-sm" name="root_cause" rows="2"><?= h((string)fv('root_cause', $t)) ?></textarea></div>
              <div class="col-md-6"><label class="form-label">Resolution: what was done (required)</label><textarea class="form-control form-control-sm" name="resolution" rows="2"><?= h((string)fv('resolution', $t)) ?></textarea></div></div></div>
            <div class="col-12 tk-for" data-for="closed"><div class="row g-2">
              <?= select_field('rating', 'Subscriber rating', ['5' => '5 Excellent', '4' => '4 Good', '3' => '3 Fair', '2' => '2 Poor', '1' => '1 Very poor'], fv('rating'), ['blank' => '(not asked)', 'class' => 'col-md-4']) ?>
              <?= input_field('feedback', 'Subscriber feedback', fv('feedback'), ['class' => 'col-md-8']) ?></div></div>
            <?= input_field('note', 'Note for the history', fv('note'), ['class' => 'col-12']) ?>
            <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-arrow-right-circle"></i> Move</button></div>
          </form>
          <script>(function () { var s = document.getElementById('tkTo'); function f() { document.querySelectorAll('.tk-for').forEach(function (d) { d.hidden = d.getAttribute('data-for') !== s.value; }); } s.addEventListener('change', f); f(); })();</script>
        </div></div>
        <?php endif; ?>

        <?php if ($active && Access::can($pk, 'edit') && !$onlyMine && $t['account_id'] && (!$wo || $wo['status'] === 'cancelled')): ?>
        <div class="card isp-card mt-3"><div class="card-body">
          <h2 class="sec-title">Dispatch a technician</h2>
          <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="dispatch"><input type="hidden" name="id" value="<?= $id ?>">
            <?= select_field('technician_id', 'Technician', $technicians, fv('technician_id', $t), ['blank' => '(assign later)', 'class' => 'col-md-5']) ?>
            <?= input_field('scheduled_date', 'Date', fv('scheduled_date', null, today()), ['type' => 'date', 'class' => 'col-md-4']) ?>
            <?= select_field('scheduled_slot', 'Slot', IspWorkOrders::SLOTS, fv('scheduled_slot', null, 'AM'), ['class' => 'col-md-3']) ?>
            <div class="col-12"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-truck"></i> Create the repair work order</button></div>
          </form>
        </div></div>
        <?php endif; ?>

        <div class="card isp-card mt-3"><div class="card-body">
          <h2 class="sec-title">History and notes</h2>
          <?php if (Access::can($pk, 'edit') && $t['status'] !== 'closed'): ?>
          <form method="post" class="row g-2 mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="note"><input type="hidden" name="id" value="<?= $id ?>">
            <div class="col-12"><textarea class="form-control form-control-sm" name="note" rows="2" placeholder="Add a note" required></textarea></div>
            <div class="col-md-8 d-flex align-items-center"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_public" value="1" id="pubNote"><label class="form-check-label small" for="pubNote">Visible to the subscriber on the portal</label></div></div>
            <div class="col-md-4 text-md-end"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-chat-left-text"></i> Add note</button></div>
          </form>
          <?php endif; ?>
          <?php if (!$notes): ?><p class="small text-muted mb-0">No notes yet.</p><?php endif; ?>
          <ul class="list-unstyled mb-0">
          <?php foreach ($notes as $n): ?>
            <li class="border-bottom py-2"><div class="small text-muted"><?= h(fdatetime($n['created_at'])) ?>, <?= h($n['portal_user_id'] ? 'the subscriber (portal)' : (string)($n['full_name'] ?: 'System')) ?>
              <?= $n['to_status'] ? ', moved to ' . h(IspTickets::STATUSES[$n['to_status']] ?? $n['to_status']) : '' ?><?= (int)$n['is_public'] === 1 ? ', visible to the subscriber' : ', internal' ?></div>
              <div><?= nl2br(h($n['note'])) ?></div></li>
          <?php endforeach; ?>
          </ul>
        </div></div>
      </div>
    </div>
    <?php if ($active && Access::can($pk, 'edit')): ?>
      <div class="modal fade" id="escalate" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="escalate"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="modal-header"><h5 class="modal-title">Escalate the ticket</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><p class="small">The priority goes up one step and a new SLA deadline starts.</p><label class="form-label">Why</label><textarea class="form-control" name="reason" rows="2" required></textarea></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-warning">Escalate</button></div></form></div></div>
    <?php endif; ?>
    <?php
    layout_end();
    exit;
}

// ------------------------------------------------------------------ the list
$q = trim((string)g('q'));
$status = (string)g('status', 'open');
$prio = isset(IspTickets::PRIORITIES[g('priority')]) ? (string)g('priority') : '';
$type = isset(IspTickets::TYPES[g('type')]) ? (string)g('type') : '';
$sla = g('sla') === 'breached' ? 'breached' : '';
$tech = gint('technician_id');
$source = isset(IspTickets::SOURCES[g('source')]) ? (string)g('source') : '';
$p = [];
$where = ['1 = 1'];
if ($onlyMine) {
    $where[] = 't.technician_id = :me';
    $p['me'] = $me;
}
if ($q !== '') {
    $where[] = '(t.ticket_no LIKE :q1 OR t.subject LIKE :q2 OR a.account_no LIKE :q3 OR a.account_name LIKE :q4)';
    $p += ['q1' => "%$q%", 'q2' => "%$q%", 'q3' => "$q%", 'q4' => "%$q%"];
}
if ($status === 'open') {
    $where[] = "t.status NOT IN ('resolved','verified','closed')";
} elseif (isset(IspTickets::STATUSES[$status])) {
    $where[] = 't.status = :st';
    $p['st'] = $status;
}
if ($prio !== '') {
    $where[] = 't.priority = :pr';
    $p['pr'] = $prio;
}
if ($type !== '') {
    $where[] = 't.ticket_type = :ty';
    $p['ty'] = $type;
}
if ($sla === 'breached') {
    $where[] = "t.status NOT IN ('resolved','verified','closed') AND t.sla_due_at < :now";
    $p['now'] = date('Y-m-d H:i:s');
}
if ($tech) {
    $where[] = 't.technician_id = :te';
    $p['te'] = $tech;
}
if ($source !== '') {
    $where[] = 't.source = :so';
    $p['so'] = $source;
}
$b = IspSetup::branchSql('a.branch_id', $p);
$from = 'FROM isp_tickets t LEFT JOIN isp_accounts a ON a.id = t.account_id LEFT JOIN isp_technicians tc ON tc.id = t.technician_id WHERE ' . implode(' AND ', $where) . $b;
[$per, $off, $page] = IspUi::paging(50);
$total = (int)DB::value("SELECT COUNT(*) $from", $p);
$rows = DB::all("SELECT t.*, a.account_no, a.account_name, tc.name AS technician_name $from
    ORDER BY t.status IN ('resolved','verified','closed'), FIELD(t.priority, 'critical', 'high', 'normal', 'low'), t.sla_due_at, t.id DESC LIMIT $per OFFSET $off", $p);
$pk2 = ['now' => date('Y-m-d H:i:s'), 'd' => today() . ' 00:00:00'];
$bk = IspSetup::branchSql('a.branch_id', $pk2);
$k = DB::row("SELECT SUM(t.status NOT IN ('resolved','verified','closed')) AS open_n,
        SUM(t.status NOT IN ('resolved','verified','closed') AND t.sla_due_at < :now) AS breached_n,
        SUM(t.status NOT IN ('resolved','verified','closed') AND t.priority IN ('critical','high')) AS urgent_n,
        SUM(t.resolved_at >= :d) AS resolved_today
    FROM isp_tickets t LEFT JOIN isp_accounts a ON a.id = t.account_id WHERE 1 = 1" . ($onlyMine ? ' AND t.technician_id = ' . (int)$me : '') . $bk, $pk2);
$avg = DB::value("SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) FROM isp_tickets WHERE resolved_at >= :d", ['d' => date('Y-m-d', strtotime('-30 days'))]);
layout_start('Support Tickets', ['actions' => page_link($pk, ['a' => 'new'], 'New ticket', 'btn-primary', 'plus-lg', 'create')]);
?>
<div class="kpi-grid">
  <?= IspUi::kpi('Open tickets', number_format((int)$k['open_n']), 'not yet resolved', url($pk, ['status' => 'open']), 'life-preserver', 'info') ?>
  <?= IspUi::kpi('Past the SLA', number_format((int)$k['breached_n']), 'deadline passed', url($pk, ['sla' => 'breached', 'status' => 'open']), 'alarm', (int)$k['breached_n'] ? 'bad' : 'ok') ?>
  <?= IspUi::kpi('Critical and high', number_format((int)$k['urgent_n']), 'open', url($pk, ['status' => 'open', 'priority' => 'high']), 'exclamation-triangle', 'warn') ?>
  <?= IspUi::kpi('Resolved today', number_format((int)$k['resolved_today']), $avg !== null ? 'average ' . round((float)$avg / 60, 1) . ' h to resolve (30 days)' : '', url($pk, ['status' => 'resolved']), 'check2-circle', 'ok') ?>
</div>
<?= IspUi::filterStart($pk) ?>
  <?= IspUi::fSearch($q, 'Ticket no., subject, account') ?>
  <?= IspUi::fSelect('status', 'Status', ['open' => 'Open (not resolved)'] + IspTickets::STATUSES, $status, 'All') ?>
  <?= IspUi::fSelect('priority', 'Priority', IspTickets::PRIORITIES, $prio) ?>
  <?= IspUi::fSelect('type', 'Concern', IspTickets::TYPES, $type) ?>
  <?= IspUi::fSelect('sla', 'SLA', ['breached' => 'Past the deadline'], $sla) ?>
  <?php if (!$onlyMine): ?><?= IspUi::fSelect('technician_id', 'Technician', $technicians, $tech) ?><?php endif; ?>
  <?= IspUi::fSelect('source', 'Received by', IspTickets::SOURCES, $source) ?>
<?= IspUi::filterEnd(url($pk)) ?>
<div class="card isp-card"><div class="card-body">
<?php if (!$rows): ?>
  <?= IspUi::empty('No ticket matches.', 'life-preserver') ?>
<?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
    <thead><tr><th>Ticket</th><th>Account</th><th>Concern</th><th>Priority</th><th>Status</th><th>SLA deadline</th><th>Technician</th></tr></thead><tbody>
    <?php foreach ($rows as $r): $act = !in_array($r['status'], ['resolved', 'verified', 'closed'], true); $late = $act && $r['sla_due_at'] && strtotime((string)$r['sla_due_at']) < time(); ?>
      <tr><td><a href="<?= h(url($pk, ['id' => (int)$r['id']])) ?>"><?= h($r['ticket_no']) ?></a><div class="small text-muted"><?= h(mb_substr($r['subject'], 0, 50)) ?></div></td>
        <td><?= h(trim($r['account_no'] . ' ' . $r['account_name'])) ?></td><td><?= h(IspTickets::TYPES[$r['ticket_type']] ?? '') ?><?= (int)$r['ntc_complaint'] === 1 ? '<div class="small text-muted">NTC complaint</div>' : '' ?></td>
        <td><?= IspUi::status($r['priority'], IspTickets::PRIORITIES) ?><?= (int)$r['escalation_level'] > 0 ? '<div class="small text-muted">Escalated ' . (int)$r['escalation_level'] . '</div>' : '' ?></td>
        <td><?= IspUi::status($r['status'], IspTickets::STATUSES) ?></td>
        <td class="text-nowrap small"><?= $act ? ($late ? IspUi::status('overdue', ['overdue' => 'Overdue ' . IspUi::ago($r['sla_due_at'])], 'bad') : h(fdatetime($r['sla_due_at']))) : h('Resolved ' . fdatetime($r['resolved_at'])) ?></td>
        <td><?= h((string)$r['technician_name']) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?= IspUi::pager($total, $per, $page) ?>
<?php endif; ?>
</div></div>
<?php
layout_end();
