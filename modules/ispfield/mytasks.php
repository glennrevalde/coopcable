<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

/** My Tasks Today: the technician's own jobs, made for a phone in the field */
$pk = 'ispfield.mytasks';
$me = IspSetup::myTechnicianId();
$tech = $me ? DB::row('SELECT * FROM isp_technicians WHERE id = :t', ['t' => $me]) : null;

// a photograph of one of my jobs
if (g('a') === 'file') {
    $f = DB::row("SELECT * FROM isp_files WHERE id = :f AND entity_type = 'work_order'", ['f' => (int)g('fid')]);
    $w = $f ? IspWorkOrders::get((int)$f['entity_id']) : null;
    if (!$f || !$w || !ispf_may_see_wo($w)) {
        Access::denyPage($pk, 'file');
    }
    ispf_send_file($f);
}

handle_post(function () use ($pk, $me) {
    $action = (string)p('action');
    $id = (int)p('id');
    $w = IspWorkOrders::get($id) ?? fail('Work order not found.');
    if (!ispf_may_see_wo($w) || (!$me && !ispf_supervisor())) {
        fail('Work order ' . $w['wo_no'] . ' is assigned to another technician.');
    }
    $back = url($pk, ['id' => $id]);
    if ($action === 'accept' || $action === 'start') {
        Access::require($pk, 'edit');
        IspWorkOrders::step($id, $action === 'accept' ? 'accepted' : 'in_progress');
        Audit::log($action, 'ispfield', 'isp_work_order', $id, (string)$w['wo_no'], ['page_key' => $pk]);
        redirect($back, $action === 'accept' ? 'Job accepted.' : 'Job started: the time was recorded.', 'success');
    }
    if ($action === 'complete') {
        Access::require($pk, 'edit');
        $n = ispf_do_complete($pk);
        redirect(url($pk), 'Work order ' . $w['wo_no'] . ' completed' . ($n ? ' with ' . $n . ' photograph' . ($n === 1 ? '' : 's') : '') . '. The supervisor verifies it next.', 'success');
    }
    if ($action === 'photos') {
        Access::require($pk, 'edit');
        if (in_array($w['status'], ['verified', 'cancelled'], true)) {
            fail('Photographs are added to open or completed jobs only.');
        }
        $n = DB::tx(fn() => ispf_store_photos('photos', 'work_order', $id, trim((string)p('label'))));
        if (!$n) {
            fail('Choose the photographs to upload.');
        }
        Audit::log('upload', 'ispfield', 'isp_work_order', $id, (string)$w['wo_no'], ['reason' => $n . ' photograph(s)']);
        redirect($back, $n . ' photograph' . ($n === 1 ? '' : 's') . ' added.', 'success');
    }
    fail('Unknown action.');
});

// ------------------------------------------------------------------ one job
$id = gint('id');
if ($id) {
    $w = IspWorkOrders::get($id);
    if (!$w || !ispf_may_see_wo($w)) {
        Access::denyPage($pk, 'view');
    }
    $acct = $w['account_id'] ? IspAccounts::get((int)$w['account_id']) : null;
    $cust = $acct ? IspAccounts::customer((int)$acct['isp_customer_id']) : null;
    $ticket = $w['ticket_id'] ? DB::row('SELECT ticket_no, ticket_type, subject, description FROM isp_tickets WHERE id = :t', ['t' => $w['ticket_id']]) : null;
    $open = in_array($w['status'], ['open', 'scheduled', 'accepted', 'in_progress'], true);
    layout_start($w['wo_no'] . ' ' . (IspWorkOrders::TYPES[$w['wo_type']] ?? ''), ['actions' => btn_link(url($pk), 'My tasks', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <div class="isp-head">
      <div>
        <h2 class="isp-head-title"><?= h($acct ? $acct['account_name'] : ($w['address'] ?: $w['description'])) ?></h2>
        <div class="isp-head-sub"><?= IspUi::status($w['status'], IspWorkOrders::STATUSES) ?> <?= IspUi::status($w['priority'], IspWorkOrders::PRIORITIES) ?>
          <?= $w['scheduled_date'] ? h(fdate($w['scheduled_date']) . ' ' . (IspWorkOrders::SLOTS[$w['scheduled_slot']] ?? (string)$w['scheduled_slot'])) : 'Not scheduled' ?></div>
      </div>
      <div class="isp-head-actions">
        <?php if (Access::can($pk, 'edit') && in_array($w['status'], ['open', 'scheduled'], true)): ?>
          <?= IspUi::actionForm('accept', 'Accept job', ['id' => $w['id']], 'btn-outline-primary', 'hand-thumbs-up') ?>
        <?php endif; ?>
        <?php if (Access::can($pk, 'edit') && in_array($w['status'], ['open', 'scheduled', 'accepted'], true)): ?>
          <?= IspUi::actionForm('start', 'Start work', ['id' => $w['id']], 'btn-primary', 'play-fill') ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="row g-3">
      <div class="col-lg-5">
        <div class="card isp-card"><div class="card-body">
          <h2 class="sec-title">Where and who</h2>
          <?= IspUi::dl([
              'Account' => $acct ? h($acct['account_no']) : '',
              'Subscriber' => $cust ? h($cust['name']) : '',
              'Address' => h((string)$w['address']),
              'Landmark' => $acct ? h((string)$acct['landmark']) : '',
              'Mobile' => $cust && $cust['mobile'] ? '<a href="tel:' . h(preg_replace('/[^0-9+]/', '', (string)$cust['mobile'])) . '">' . h($cust['mobile']) . '</a>' : '',
              'Location' => $acct && $acct['latitude'] !== null ? '<a href="https://www.google.com/maps?q=' . h($acct['latitude'] . ',' . $acct['longitude']) . '" target="_blank" rel="noopener">' . h($acct['latitude'] . ', ' . $acct['longitude']) . '</a>' : '',
              'Plan' => $acct ? h((string)DB::value('SELECT name FROM isp_plans WHERE id = :p', ['p' => $acct['plan_id']])) : '',
              'Drop port' => h(IspInfra::portLabel($w['port_id'] ? (int)$w['port_id'] : ($acct['port_id'] ?? null))),
              'PPPoE user' => $acct ? h((string)$acct['pppoe_username']) : '',
          ]) ?>
        </div></div>
        <div class="card isp-card mt-3"><div class="card-body">
          <h2 class="sec-title">The job</h2>
          <p class="mb-2"><?= nl2br(h($w['description'])) ?></p>
          <?php if ($ticket): ?>
            <p class="small mb-2">Ticket <?= h($ticket['ticket_no']) ?>: <?= h(IspTickets::TYPES[$ticket['ticket_type']] ?? '') ?>. <?= h((string)$ticket['description']) ?></p>
          <?php endif; ?>
          <?php $chk = IspWorkOrders::checklist($w); if ($chk && !$open): ?>
            <ul class="list-unstyled small mb-2"><?php foreach ($chk as [$done, $text]): ?><li><i class="bi bi-<?= $done ? 'check2-square text-success' : 'square text-muted' ?>"></i> <?= h($text) ?></li><?php endforeach; ?></ul>
          <?php endif; ?>
          <?php $mats = ispf_materials((int)$w['id']); if ($mats): ?>
            <h3 class="h6 mt-2">Materials</h3>
            <ul class="small mb-0"><?php foreach ($mats as $m): ?><li><?= h($m['code'] . ' ' . $m['name']) ?>: issued <?= h(qty($m['qty_issued'])) ?><?= $m['status'] !== 'issued' ? ', used ' . h(qty($m['qty_used'])) . ', returned ' . h(qty($m['qty_returned'])) : '' ?><?= $m['serial_no'] ? ' (' . h($m['serial_no']) . ')' : '' ?></li><?php endforeach; ?></ul>
          <?php endif; ?>
          <?php if (!$open): ?>
            <?= IspUi::dl(['Findings' => nl2br(h((string)$w['findings'])), 'Accepted by' => h((string)$w['customer_accepted_by']), 'Completed' => h(fdatetime($w['completed_at'])),
                'Rx power' => $w['rx_power_dbm'] !== null ? h($w['rx_power_dbm'] . ' dBm') : '', 'Speed test' => $w['speed_test_down'] !== null ? h(qty($w['speed_test_down']) . ' / ' . qty($w['speed_test_up']) . ' Mbps') : '']) ?>
          <?php endif; ?>
        </div></div>
        <div class="card isp-card mt-3"><div class="card-body">
          <h2 class="sec-title">Photographs</h2>
          <?= ispf_gallery(ispf_files('work_order', (int)$w['id']), $pk) ?>
          <?php if (Access::can($pk, 'edit') && !in_array($w['status'], ['verified', 'cancelled'], true)): ?>
          <form method="post" enctype="multipart/form-data" class="row g-2 mt-2">
            <?= csrf_field() ?><input type="hidden" name="action" value="photos"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
            <div class="col-12"><input class="form-control form-control-sm" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" capture="environment" multiple required></div>
            <div class="col-8"><input class="form-control form-control-sm" name="label" placeholder="What the photo shows (optional)" maxlength="120"></div>
            <div class="col-4"><button class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-camera"></i> Upload</button></div>
          </form>
          <?php endif; ?>
        </div></div>
      </div>
      <div class="col-lg-7">
        <div class="card isp-card"><div class="card-body">
          <h2 class="sec-title">Complete the job</h2>
          <?php if ($open && Access::can($pk, 'edit')): ?>
            <?= ispf_complete_form($w, $pk) ?>
          <?php elseif ($open): ?>
            <p class="text-muted mb-0">You may view this job but not complete it.</p>
          <?php else: ?>
            <p class="text-muted mb-0">This job is <?= h(strtolower(IspWorkOrders::STATUSES[$w['status']] ?? $w['status'])) ?>.</p>
          <?php endif; ?>
        </div></div>
      </div>
    </div>
    <?php
    layout_end();
    exit;
}

// ------------------------------------------------------------------ my list
$tab = in_array(g('tab'), ['today', 'upcoming', 'unscheduled', 'done'], true) ? (string)g('tab') : 'today';
layout_start('My Tasks Today');
if (!$tech) {
    echo '<div class="card isp-card"><div class="card-body">' . IspUi::empty('Your user account is not linked to a technician record, so no job is assigned to you. Ask the technical supervisor to link your user on Technicians & Teams.', 'person-badge')
        . (Access::page('ispfield.workorders') ? '<div class="text-center mt-2">' . btn_link(url('ispfield.workorders'), 'Open Work Orders & Dispatch', 'btn-outline-primary', 'clipboard-check') . '</div>' : '') . '</div></div>';
    layout_end();
    exit;
}
$counts = DB::row("SELECT
        SUM(CASE WHEN status IN ('scheduled','accepted','in_progress') AND scheduled_date <= :d1 THEN 1 ELSE 0 END) AS today,
        SUM(CASE WHEN status IN ('scheduled','accepted','in_progress') AND scheduled_date > :d2 THEN 1 ELSE 0 END) AS upcoming,
        SUM(CASE WHEN status IN ('open','scheduled','accepted','in_progress') AND scheduled_date IS NULL THEN 1 ELSE 0 END) AS unscheduled,
        SUM(CASE WHEN status IN ('completed','verified') AND completed_at >= :d3 THEN 1 ELSE 0 END) AS done
    FROM isp_work_orders WHERE technician_id = :t", ['d1' => today(), 'd2' => today(), 'd3' => date('Y-m-d 00:00:00', strtotime('-14 days')), 't' => $me]);
$where = match ($tab) {
    'today' => "w.status IN ('scheduled','accepted','in_progress') AND w.scheduled_date <= :d",
    'upcoming' => "w.status IN ('scheduled','accepted','in_progress') AND w.scheduled_date > :d",
    'unscheduled' => "w.status IN ('open','scheduled','accepted','in_progress') AND w.scheduled_date IS NULL AND :d = :d",
    default => "w.status IN ('completed','verified') AND w.completed_at >= :d",
};
$p = ['t' => $me, 'd' => $tab === 'done' ? date('Y-m-d 00:00:00', strtotime('-14 days')) : today()];
if ($tab === 'unscheduled') {
    $where = "w.status IN ('open','scheduled','accepted','in_progress') AND w.scheduled_date IS NULL";
    unset($p['d']);
}
$rows = DB::all("SELECT w.*, a.account_no, a.account_name FROM isp_work_orders w LEFT JOIN isp_accounts a ON a.id = w.account_id WHERE w.technician_id = :t AND $where
    ORDER BY w.scheduled_date, FIELD(w.scheduled_slot, 'AM', 'PM', 'EVE'), w.route_seq, FIELD(w.priority, 'urgent', 'high', 'normal', 'low'), w.id" . ($tab === 'done' ? ' DESC' : '') . ' LIMIT 200', $p);
?>
<div class="isp-head">
  <div><h2 class="isp-head-title"><?= h($tech['name']) ?></h2><div class="isp-head-sub"><?= h(trim(($tech['team'] ?: '') . ' ' . ucfirst((string)$tech['role']))) ?>, <?= h(date('l, F j, Y')) ?></div></div>
</div>
<?= IspUi::tabs(['today' => 'Today and overdue (' . (int)$counts['today'] . ')', 'upcoming' => 'Upcoming (' . (int)$counts['upcoming'] . ')', 'unscheduled' => 'Not yet scheduled (' . (int)$counts['unscheduled'] . ')',
    'done' => 'Completed, last 14 days (' . (int)$counts['done'] . ')'], $tab, $pk) ?>
<?php if (!$rows): ?>
  <div class="card isp-card"><div class="card-body"><?= IspUi::empty($tab === 'today' ? 'No job for today. Enjoy the quiet, or check the upcoming jobs.' : 'Nothing here.', 'clipboard-check') ?></div></div>
<?php else: ?>
  <div class="row g-3">
  <?php foreach ($rows as $w): ?>
    <div class="col-md-6 col-xl-4"><?= ispf_wo_card($w, $pk) ?></div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php
layout_end();
