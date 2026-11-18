<?php
declare(strict_types=1);

/** Technicians & Teams: profiles, skills and service areas, the van stock site, work days and the workload */
$pk = 'ispfield.technicians';
const ISPF_TECH_ROLES = ['installer' => 'Installer', 'maintenance' => 'Maintenance / repair', 'splicer' => 'Fiber splicer', 'supervisor' => 'Supervisor'];
const ISPF_DAYS = ['1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat', '7' => 'Sun'];

$areas = DB::pairs("SELECT id, CONCAT(name, ' (', area_type, ')') FROM isp_areas WHERE is_active = 1 ORDER BY name");
$users = DB::pairs("SELECT id, CONCAT(full_name, ' (', username, ')') FROM users WHERE is_platform = 0 AND created_via <> 'system' AND status <> 'locked' ORDER BY full_name");
$sites = DB::pairs("SELECT id, CONCAT(name, ' (', site_type, ')') FROM sites WHERE is_active = 1 ORDER BY site_type = 'van' DESC, name");

handle_post(function () use ($pk) {
    $action = (string)p('action');
    if ($action === 'save') {
        $id = pint('id');
        Access::require($pk, $id ? 'edit' : 'create');
        $before = $id ? DB::row('SELECT * FROM isp_technicians WHERE id = :id', ['id' => $id]) : null;
        if ($id && !$before) {
            fail('Technician not found.');
        }
        $f = [
            'code' => strtoupper(trim((string)p('code'))),
            'name' => trim((string)p('name')),
            'user_id' => pint('user_id'),
            'team' => trim((string)p('team')) ?: null,
            'role' => isset(ISPF_TECH_ROLES[p('role')]) ? (string)p('role') : 'installer',
            'skills' => mb_substr(trim((string)p('skills')), 0, 255) ?: null,
            'area_ids' => implode(',', array_map('intval', (array)($_POST['area_ids'] ?? []))) ?: null,
            'mobile' => trim((string)p('mobile')) ?: null,
            'site_id' => pint('site_id'),
            'work_days' => implode(',', array_values(array_intersect(array_keys(ISPF_DAYS), array_map('strval', (array)($_POST['work_days'] ?? []))))) ?: '1,2,3,4,5,6',
            'is_active' => p('is_active') === '1' ? 1 : 0,
        ];
        if ($f['name'] === '') {
            fail('Enter the name of the technician.');
        }
        if ($f['code'] === '') {
            $f['code'] = $before['code'] ?? 'TEC-' . str_pad((string)((int)DB::value('SELECT COALESCE(MAX(id), 0) FROM isp_technicians') + 1), 3, '0', STR_PAD_LEFT);
        }
        if (DB::value('SELECT id FROM isp_technicians WHERE code = :c AND id <> :id', ['c' => $f['code'], 'id' => (int)$id])) {
            fail('Another technician already has the code ' . $f['code'] . '.');
        }
        if ($f['user_id'] && DB::value('SELECT id FROM isp_technicians WHERE user_id = :u AND id <> :id', ['u' => $f['user_id'], 'id' => (int)$id])) {
            fail('That user is already linked to another technician.');
        }
        if ($f['site_id'] && !DB::value('SELECT id FROM sites WHERE id = :s AND is_active = 1', ['s' => $f['site_id']])) {
            fail('Choose an active stock site.');
        }
        if ($id) {
            DB::update('isp_technicians', $f, 'id = :id', ['id' => $id]);
            Audit::log('edit', 'ispfield', 'isp_technician', $id, $f['code'], ['before' => $before, 'after' => $f]);
        } else {
            $id = DB::insert('isp_technicians', $f);
            Audit::log('create', 'ispfield', 'isp_technician', $id, $f['code'], ['after' => $f]);
        }
        redirect_after_save(url($pk, ['a' => 'new']), url($pk, ['id' => $id]));
    }
    fail('Unknown action.');
});

// ------------------------------------------------------------------ the form
if (g('a') === 'new' || ($id = gint('id'))) {
    $id = gint('id');
    $t = $id ? DB::row('SELECT * FROM isp_technicians WHERE id = :id', ['id' => $id]) : null;
    if ($id && !$t) {
        Access::denyPage($pk, 'view');
    }
    $canSave = Access::can($pk, $t ? 'edit' : 'create');
    layout_start($t ? $t['name'] : 'New technician', ['actions' => btn_link(url($pk), 'Technicians', 'btn-outline-secondary', 'arrow-left')]);
    $workDays = is_post() ? array_map('strval', (array)($_POST['work_days'] ?? [])) : explode(',', (string)($t['work_days'] ?? '1,2,3,4,5,6'));
    ?>
    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card isp-card"><div class="card-body">
          <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$id ?>">
            <?= input_field('code', 'Code', fv('code', $t), ['class' => 'col-md-3', 'extra' => 'placeholder="automatic" maxlength="20"']) ?>
            <?= input_field('name', 'Name', fv('name', $t), ['class' => 'col-md-5', 'required' => true]) ?>
            <?= select_field('role', 'Role', ISPF_TECH_ROLES, fv('role', $t, 'installer'), ['class' => 'col-md-4']) ?>
            <?= input_field('team', 'Team', fv('team', $t), ['class' => 'col-md-4', 'extra' => 'placeholder="e.g. Team A, North crew"']) ?>
            <?= input_field('mobile', 'Mobile', fv('mobile', $t), ['class' => 'col-md-4', 'type' => 'tel']) ?>
            <?= select_field('user_id', 'Log-in user (sees My Tasks Today)', $users, fv('user_id', $t), ['blank' => '(none)', 'class' => 'col-md-4']) ?>
            <?= select_field('site_id', 'Van / stock site of the technician', $sites, fv('site_id', $t), ['blank' => '(works from the stockroom)', 'class' => 'col-md-6']) ?>
            <?= input_field('skills', 'Skills', fv('skills', $t), ['class' => 'col-md-6', 'extra' => 'placeholder="FTTH install, splicing, OTDR, MikroTik"']) ?>
            <?= multi_check_field('area_ids', 'Service areas', $areas, is_post() ? (array)($_POST['area_ids'] ?? []) : array_filter(explode(',', (string)($t['area_ids'] ?? ''))), ['class' => 'col-md-6', 'all' => 'Every area', 'noun' => 'areas']) ?>
            <div class="col-md-6"><label class="form-label">Work days</label><div class="d-flex flex-wrap gap-2">
              <?php foreach (ISPF_DAYS as $k => $d): ?><label class="form-check form-check-inline m-0"><input class="form-check-input" type="checkbox" name="work_days[]" value="<?= $k ?>"<?= in_array($k, $workDays, true) ? ' checked' : '' ?>> <?= $d ?></label><?php endforeach; ?></div></div>
            <?= check_field('is_active', 'Active (can be assigned jobs)', (string)fv('is_active', $t, '1') === '1', 'col-md-6') ?>
            <div class="col-12 small text-muted">Give the linked user the Installation Technician or Maintenance Technician role (System Administration &gt; Users) so the user opens only the field pages, never billing or financial editing.</div>
            <?php if ($canSave): ?><div class="col-12 mt-2"><?= save_buttons('Save technician', url($pk), !$t) ?></div><?php endif; ?>
          </form>
        </div></div>
      </div>
      <?php if ($t): ?>
      <div class="col-lg-5">
        <div class="card isp-card"><div class="card-body">
          <h2 class="sec-title">Workload</h2>
          <?php $w = DB::row("SELECT SUM(status IN ('open','scheduled','accepted','in_progress')) AS open_n, SUM(status IN ('scheduled','accepted','in_progress') AND scheduled_date = CURDATE()) AS today_n,
                SUM(status IN ('completed','verified') AND completed_at >= :d) AS done_n, AVG(CASE WHEN completed_at >= :d2 AND started_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, started_at, completed_at) END) AS avg_min,
                SUM(CASE WHEN completed_at >= :d3 THEN cable_used_m ELSE 0 END) AS cable FROM isp_work_orders WHERE technician_id = :t",
                ['d' => date('Y-m-d', strtotime('-30 days')), 'd2' => date('Y-m-d', strtotime('-30 days')), 'd3' => date('Y-m-d', strtotime('-30 days')), 't' => $id]); ?>
          <?= IspUi::dl(['Open jobs' => (int)$w['open_n'], 'Jobs today' => (int)$w['today_n'], 'Completed, last 30 days' => (int)$w['done_n'],
              'Average time on site' => $w['avg_min'] !== null ? h(round((float)$w['avg_min'] / 60, 1) . ' h') : '', 'Drop cable laid, 30 days' => h(qty((float)$w['cable']) . ' m'),
              'Open tickets' => (int)DB::value("SELECT COUNT(*) FROM isp_tickets WHERE technician_id = :t AND status NOT IN ('resolved','verified','closed')", ['t' => $id]),
              'Units in hand' => (int)DB::value("SELECT COUNT(*) FROM isp_equipment WHERE technician_id = :t AND status = 'with_technician'", ['t' => $id])]) ?>
          <div class="mt-2"><?= safe_link('ispfield.workorders', ['technician_id' => $id], 'Open the jobs of ' . $t['name']) ?></div>
        </div></div>
      </div>
      <?php endif; ?>
    </div>
    <?php
    layout_end();
    exit;
}

// ------------------------------------------------------------------ the list
$q = trim((string)g('q'));
$show = g('show') === 'all' ? 'all' : 'active';
$p = ['d' => date('Y-m-d', strtotime('-30 days'))];
$where = $show === 'active' ? 't.is_active = 1' : '1 = 1';
if ($q !== '') {
    $where .= ' AND (t.name LIKE :q1 OR t.code LIKE :q2 OR t.team LIKE :q3 OR t.skills LIKE :q4)';
    $p += ['q1' => "%$q%", 'q2' => "$q%", 'q3' => "%$q%", 'q4' => "%$q%"];
}
$rows = DB::all("SELECT t.*, u.username, s.name AS site_name,
        (SELECT COUNT(*) FROM isp_work_orders w WHERE w.technician_id = t.id AND w.status IN ('open','scheduled','accepted','in_progress')) AS open_n,
        (SELECT COUNT(*) FROM isp_work_orders w WHERE w.technician_id = t.id AND w.status IN ('scheduled','accepted','in_progress') AND w.scheduled_date = CURDATE()) AS today_n,
        (SELECT COUNT(*) FROM isp_work_orders w WHERE w.technician_id = t.id AND w.status IN ('completed','verified') AND w.completed_at >= :d) AS done_n
    FROM isp_technicians t LEFT JOIN users u ON u.id = t.user_id LEFT JOIN sites s ON s.id = t.site_id WHERE $where ORDER BY t.is_active DESC, t.team, t.name", $p);
layout_start('Technicians & Teams', ['actions' => page_link($pk, ['a' => 'new'], 'New technician', 'btn-primary', 'plus-lg', 'create')]);
?>
<?= IspUi::filterStart($pk) ?>
  <?= IspUi::fSearch($q, 'Name, code, team, skill') ?>
  <div class="isp-f"><label class="form-label">Show</label><select class="form-select form-select-sm" name="show"><?= options(['active' => 'Active only', 'all' => 'Active and inactive'], $show) ?></select></div>
<?= IspUi::filterEnd(url($pk)) ?>
<div class="card isp-card"><div class="card-body">
<?php if (!$rows): ?>
  <?= IspUi::empty('No technician yet. Add the field team to assign installations and repairs.', 'person-badge') ?>
<?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
    <thead><tr><th>Technician</th><th>Team / role</th><th>Areas</th><th>Work days</th><th>Van site</th><th>Log-in</th><th class="num">Open jobs</th><th class="num">Today</th><th class="num">Done (30 days)</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($rows as $r):
        $an = array_values(array_filter(array_map(fn($a) => $areas[(int)$a] ?? null, explode(',', (string)$r['area_ids'])))); ?>
      <tr><td><a href="<?= h(url($pk, ['id' => (int)$r['id']])) ?>"><?= h($r['name']) ?></a><div class="small text-muted"><?= h($r['code']) ?><?= $r['mobile'] ? ', ' . h($r['mobile']) : '' ?></div></td>
        <td><?= h((string)$r['team']) ?><div class="small text-muted"><?= h(ISPF_TECH_ROLES[$r['role']] ?? $r['role']) ?></div></td>
        <td class="small"><?= $an ? h(count($an) > 2 ? $an[0] . ', ' . $an[1] . ' and ' . (count($an) - 2) . ' more' : implode(', ', $an)) : 'Every area' ?></td>
        <td class="small"><?= h(implode(' ', array_map(fn($d) => ISPF_DAYS[$d] ?? '', explode(',', (string)$r['work_days'])))) ?></td>
        <td class="small"><?= h((string)$r['site_name']) ?></td><td class="small"><?= h((string)$r['username']) ?></td>
        <td class="num"><?= (int)$r['open_n'] ?></td><td class="num"><?= (int)$r['today_n'] ?></td><td class="num"><?= (int)$r['done_n'] ?></td>
        <td><?= IspUi::status($r['is_active'] ? 'active' : 'inactive', ['active' => 'Active', 'inactive' => 'Inactive']) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
<?php endif; ?>
</div></div>
<?php
layout_end();
