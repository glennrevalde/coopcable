<?php
declare(strict_types=1);

/**
 * Templates & Announcements: the message templates of every notification event and channel, the announcements the
 * subscribers see on the portal (with an optional notice to the subscribers of the area), and the subscription
 * agreement templates.
 */
$pk = 'ispset.templates';
$tabs = ['messages' => 'Message templates', 'announcements' => 'Announcements', 'contracts' => 'Contract templates'];
$tab = isset($tabs[g('tab')]) ? (string)g('tab') : 'messages';
$areas = DB::pairs("SELECT id, CONCAT(name, ' (', area_type, ')') FROM isp_areas WHERE is_active = 1 ORDER BY name");
const ISPSET_BASE_VARS = ['name' => 'Juan Dela Cruz', 'company' => '', 'account_no' => '1000123', 'plan' => 'Fiber 50', 'customer_no' => 'CUS-000123', 'date' => ''];
const ISPSET_SAMPLE = ['application_no' => 'APP-000045', 'wo_no' => 'WO-000210', 'slot' => 'Morning (8-12)', 'technician' => 'R. Santos', 'invoice_no' => 'SI-0001024', 'period' => 'Nov 1 - Nov 30',
    'amount' => '1,299.00', 'due_date' => '11/15/2026', 'reason' => 'overdue payment', 'receipt_no' => 'CR-0000877', 'balance' => '0.00', 'ticket_no' => 'TKT-000301',
    'resolution' => 'Replaced the fiber connector', 'area' => 'San Fermin', 'title' => 'Fiber cut along the national road', 'reference' => '7012 345 678'];
const ISPSET_CONTRACT_VARS = ['date', 'company', 'company_address', 'name', 'address', 'account_no', 'plan', 'download', 'upload', 'fair_use', 'monthly_fee', 'vat_note', 'billing', 'due_days',
    'installation_fee', 'deposit', 'start_date', 'months', 'lock_in', 'etf', 'grace_days', 'reconnection_fee', 'sla_hours', 'privacy'];

/** The placeholders an event's message may use */
function ispset_event_vars(string $event): array
{
    $vars = array_keys(ISPSET_BASE_VARS);
    $def = IspSetup::TEMPLATES[$event] ?? null;
    if ($def && preg_match_all('/\{([a-z_]+)\}/', $def[0] . ' ' . $def[1], $m)) {
        $vars = array_merge($vars, $m[1]);
    }
    return array_values(array_unique($vars));
}

function ispset_sample_vars(): array
{
    return array_merge(ISPSET_BASE_VARS, ['company' => Settings::get('company_name'), 'date' => fdate(today())], ISPSET_SAMPLE);
}

handle_post(function () use ($pk, $areas) {
    $action = (string)p('action');
    switch ($action) {
        case 'tpl_save':
            Access::require($pk, 'edit');
            $id = (int)p('id');
            $t = DB::row('SELECT * FROM isp_notif_templates WHERE id = :id', ['id' => $id]) ?? fail('Template not found.');
            $body = trim(str_replace("\r\n", "\n", (string)p('body')));
            $subject = trim((string)p('subject'));
            if ($body === '') {
                fail('Write the message.');
            }
            if ($t['channel'] === 'sms' && mb_strlen($body) > 480) {
                fail('An SMS template is at most 480 characters (three messages).');
            }
            if ($t['channel'] === 'email' && $subject === '') {
                fail('An e-mail needs a subject.');
            }
            $unknown = array_diff(preg_match_all('/\{([a-z_]+)\}/', $subject . ' ' . $body, $m) ? $m[1] : [], ispset_event_vars((string)$t['event_code']));
            if ($unknown) {
                fail('Unknown placeholder' . (count($unknown) > 1 ? 's' : '') . ': {' . implode('}, {', array_unique($unknown)) . '}. Use the ones listed beside the form.');
            }
            $set = ['subject' => $t['channel'] === 'sms' ? null : mb_substr($subject, 0, 160), 'body' => $body, 'is_active' => p('is_active') === '1' ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')];
            DB::update('isp_notif_templates', $set, 'id = :id', ['id' => $id]);
            Audit::log('edit', 'ispset', 'isp_notif_template', $id, $t['event_code'] . ' ' . $t['channel'], ['before' => array_intersect_key($t, $set), 'after' => $set]);
            redirect(url($pk, ['tpl' => $id]), 'Template saved.', 'success');
        case 'tpl_default':
            Access::require($pk, 'edit');
            $id = (int)p('id');
            $t = DB::row('SELECT * FROM isp_notif_templates WHERE id = :id', ['id' => $id]) ?? fail('Template not found.');
            [$subject, $body] = IspSetup::TEMPLATES[$t['event_code']] ?? fail('This event has no installed default.');
            $set = ['subject' => $t['channel'] === 'sms' ? null : $subject, 'body' => $t['channel'] === 'email' ? str_replace(' - {company}', "\n\n{company}", $body) : $body, 'updated_at' => date('Y-m-d H:i:s')];
            DB::update('isp_notif_templates', $set, 'id = :id', ['id' => $id]);
            Audit::log('edit', 'ispset', 'isp_notif_template', $id, $t['event_code'] . ' ' . $t['channel'], ['reason' => 'Restored the installed default']);
            redirect(url($pk, ['tpl' => $id]), 'The installed default was restored.', 'success');
        case 'tpl_add':
            Access::require($pk, 'create');
            $ev = (string)p('event_code');
            $ch = (string)p('channel');
            if (!isset(IspNotify::EVENTS[$ev]) || !isset(IspNotify::CHANNELS[$ch])) {
                fail('Choose the event and the channel.');
            }
            if ($have = DB::value('SELECT id FROM isp_notif_templates WHERE event_code = :e AND channel = :c', ['e' => $ev, 'c' => $ch])) {
                redirect(url($pk, ['tpl' => $have]));
            }
            [$subject, $body] = IspSetup::TEMPLATES[$ev] ?? [IspNotify::EVENTS[$ev], IspNotify::EVENTS[$ev] . ': account {account_no}. - {company}'];
            $id = DB::insert('isp_notif_templates', ['event_code' => $ev, 'channel' => $ch, 'subject' => $ch === 'sms' ? null : $subject, 'body' => $body, 'is_active' => 1]);
            Audit::log('create', 'ispset', 'isp_notif_template', $id, $ev . ' ' . $ch);
            redirect(url($pk, ['tpl' => $id]), 'Template added.', 'success');

        case 'ann_save':
            $id = pint('id');
            Access::require($pk, $id ? 'edit' : 'create');
            $before = $id ? DB::row('SELECT * FROM isp_announcements WHERE id = :id', ['id' => $id]) : null;
            if ($id && !$before) {
                fail('Announcement not found.');
            }
            $starts = trim((string)p('starts_at'));
            $ends = trim((string)p('ends_at'));
            $f = ['title' => mb_substr(trim((string)p('title')), 0, 160), 'body' => trim((string)p('body')), 'area_id' => pint('area_id'),
                'starts_at' => $starts !== '' ? date('Y-m-d H:i:s', strtotime($starts)) : date('Y-m-d H:i:s'), 'ends_at' => $ends !== '' ? date('Y-m-d H:i:s', strtotime($ends)) : null,
                'is_active' => p('is_active') === '1' ? 1 : 0];
            if ($f['title'] === '' || $f['body'] === '') {
                fail('Enter the title and the text of the announcement.');
            }
            if (($starts !== '' && strtotime($starts) === false) || ($ends !== '' && strtotime($ends) === false)) {
                fail('Enter valid dates and times.');
            }
            if ($f['ends_at'] && $f['ends_at'] < $f['starts_at']) {
                fail('The announcement ends before it starts.');
            }
            if ($f['area_id'] && !isset($areas[$f['area_id']])) {
                fail('Choose an active area.');
            }
            $notify = p('notify') === '1';
            $sent = 0;
            DB::tx(function () use (&$id, $f, $before, $notify, &$sent) {
                if ($id) {
                    DB::update('isp_announcements', $f, 'id = :id', ['id' => $id]);
                    Audit::log('edit', 'ispset', 'isp_announcement', $id, $f['title'], ['before' => $before, 'after' => $f]);
                } else {
                    $id = DB::insert('isp_announcements', $f + ['created_by' => Auth::id()]);
                    Audit::log('create', 'ispset', 'isp_announcement', $id, $f['title'], ['after' => $f]);
                }
                if ($notify && $f['is_active']) {
                    $p = [];
                    $areaSql = '';
                    if ($f['area_id']) {
                        [$in, $p] = DB::in('ar', IspOutages::areaTree((int)$f['area_id']));
                        $areaSql = " AND area_id IN $in";
                    }
                    $ids = DB::column("SELECT id FROM isp_accounts WHERE service_status IN ('active', 'suspended', 'disconnected')$areaSql", $p);
                    $areaName = $f['area_id'] ? (string)DB::value('SELECT name FROM isp_areas WHERE id = :a', ['a' => $f['area_id']]) : 'all service areas';
                    $sent = IspNotify::broadcast('service_maintenance', array_map('intval', $ids), ['area' => $areaName, 'date' => fdatetime($f['starts_at']), 'title' => $f['title']], 'ANN-' . $id);
                }
            });
            redirect(url($pk, ['tab' => 'announcements']), 'Announcement saved.' . ($notify ? ' ' . $sent . ' message' . ($sent === 1 ? '' : 's') . ' queued for the subscribers.' : ''), 'success');
        case 'ann_end':
            Access::require($pk, 'edit');
            $id = (int)p('id');
            $a = DB::row('SELECT * FROM isp_announcements WHERE id = :id', ['id' => $id]) ?? fail('Announcement not found.');
            DB::update('isp_announcements', ['is_active' => 0, 'ends_at' => $a['ends_at'] && $a['ends_at'] < date('Y-m-d H:i:s') ? $a['ends_at'] : date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            Audit::log('edit', 'ispset', 'isp_announcement', $id, (string)$a['title'], ['reason' => 'Taken down']);
            redirect(url($pk, ['tab' => 'announcements']), 'Announcement taken down.', 'success');

        case 'ct_save':
            $id = pint('id');
            Access::require($pk, $id ? 'edit' : 'create');
            $before = $id ? DB::row('SELECT * FROM isp_contract_templates WHERE id = :id', ['id' => $id]) : null;
            if ($id && !$before) {
                fail('Template not found.');
            }
            $f = ['name' => mb_substr(trim((string)p('name')), 0, 160), 'body' => trim(str_replace("\r\n", "\n", (string)p('body'))), 'is_active' => p('is_active') === '1' ? 1 : 0];
            if ($f['name'] === '' || $f['body'] === '') {
                fail('Enter the name and the text of the agreement.');
            }
            $unknown = array_diff(preg_match_all('/\{([a-z_]+)\}/', $f['body'], $m) ? $m[1] : [], ISPSET_CONTRACT_VARS);
            if ($unknown) {
                fail('Unknown placeholder' . (count($unknown) > 1 ? 's' : '') . ': {' . implode('}, {', array_unique($unknown)) . '}.');
            }
            $default = p('is_default') === '1';
            DB::tx(function () use (&$id, $f, $before, $default) {
                if ($default) {
                    DB::run('UPDATE isp_contract_templates SET is_default = 0');
                    $f['is_default'] = 1;
                    $f['is_active'] = 1;
                }
                if ($id) {
                    DB::update('isp_contract_templates', $f, 'id = :id', ['id' => $id]);
                    Audit::log('edit', 'ispset', 'isp_contract_template', $id, $f['name'], ['before' => $before, 'after' => $f]);
                } else {
                    $id = DB::insert('isp_contract_templates', $f);
                    Audit::log('create', 'ispset', 'isp_contract_template', $id, $f['name']);
                }
                if (!DB::value('SELECT id FROM isp_contract_templates WHERE is_default = 1 AND is_active = 1')) {
                    DB::run('UPDATE isp_contract_templates SET is_default = 1 WHERE is_active = 1 ORDER BY id LIMIT 1');
                }
            });
            redirect(url($pk, ['tab' => 'contracts', 'ct' => $id]), 'Contract template saved. New contracts use the default template; contracts already made keep their own text.', 'success');
    }
    fail('Unknown action.');
});

layout_start('Templates & Announcements');
echo IspUi::tabs($tabs, $tab, $pk);
$canEdit = Access::can($pk, 'edit');

// ------------------------------------------------------------------ message templates
if ($tab === 'messages') {
    $tplId = gint('tpl');
    $t = $tplId ? DB::row('SELECT * FROM isp_notif_templates WHERE id = :id', ['id' => $tplId]) : null;
    if ($t) {
        $vars = ispset_event_vars((string)$t['event_code']);
        $sample = ispset_sample_vars();
        $subject = (string)fv('subject', $t);
        $body = (string)fv('body', $t);
        ?>
        <div class="row g-3">
          <div class="col-lg-7"><div class="card isp-card"><div class="card-body">
            <h2 class="sec-title"><?= h(IspNotify::EVENTS[$t['event_code']] ?? $t['event_code']) ?>, <?= h(IspNotify::CHANNELS[$t['channel']] ?? $t['channel']) ?></h2>
            <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="tpl_save"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <?php if ($t['channel'] !== 'sms'): ?><?= input_field('subject', 'Subject', $subject, ['class' => 'col-12', 'extra' => 'maxlength="160"']) ?><?php endif; ?>
              <div class="col-12"><label class="form-label">Message<?= $t['channel'] === 'sms' ? ' (up to 480 characters)' : '' ?></label>
                <textarea class="form-control form-control-sm" name="body" rows="<?= $t['channel'] === 'email' ? 8 : 4 ?>" <?= $t['channel'] === 'sms' ? 'maxlength="480"' : '' ?> required<?= $canEdit ? '' : ' readonly' ?>><?= h($body) ?></textarea></div>
              <?= check_field('is_active', 'Active (sent for this event)', (string)fv('is_active', $t) === '1', 'col-12') ?>
              <?php if ($canEdit): ?><div class="col-12 d-flex gap-2"><button class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> Save template</button> <?= btn_link(url($pk), 'Back to the list') ?></div><?php endif; ?>
            </form>
            <?php if ($canEdit && isset(IspSetup::TEMPLATES[$t['event_code']])): ?>
              <div class="mt-2"><?= IspUi::actionForm('tpl_default', 'Restore the installed default', ['id' => $t['id']], 'btn-outline-secondary', 'arrow-counterclockwise', 'Replace this template with the installed default text?') ?></div>
            <?php endif; ?>
          </div></div></div>
          <div class="col-lg-5">
            <div class="card isp-card"><div class="card-body">
              <h2 class="sec-title">Placeholders</h2>
              <p class="small mb-1">Type them with their braces; each is replaced with the subscriber's own value.</p>
              <div class="d-flex flex-wrap gap-1"><?php foreach ($vars as $vname): ?><code class="me-1">{<?= h($vname) ?>}</code><?php endforeach; ?></div>
            </div></div>
            <div class="card isp-card mt-3"><div class="card-body">
              <h2 class="sec-title">Preview with sample values</h2>
              <?php if ($t['channel'] !== 'sms'): ?><div class="small fw-semibold mb-1"><?= h(IspNotify::render($subject, $sample)) ?></div><?php endif; ?>
              <div class="small" style="white-space:pre-wrap"><?= h(IspNotify::render($body, $sample)) ?></div>
              <?php if ($t['channel'] === 'sms'): $len = mb_strlen(IspNotify::render($body, $sample)); ?><div class="small text-muted mt-1"><?= $len ?> characters, <?= max(1, (int)ceil($len / 160)) ?> SMS</div><?php endif; ?>
            </div></div>
          </div>
        </div>
        <?php
        layout_end();
        exit;
    }
    $all = DB::all('SELECT * FROM isp_notif_templates ORDER BY event_code, FIELD(channel, \'sms\', \'email\', \'inapp\')');
    $grid = [];
    foreach ($all as $r) {
        $grid[$r['event_code']][$r['channel']] = $r;
    }
    ?>
    <div class="card isp-card"><div class="card-body">
      <p class="small text-muted">Each event sends on the channels the subscriber accepts: SMS and e-mail by the subscriber's preference, in-app always (the portal inbox). An inactive template is not sent.</p>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
        <thead><tr><th>Event</th><?php foreach (IspNotify::CHANNELS as $label): ?><th><?= h($label) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach (IspNotify::EVENTS as $ev => $label): ?>
          <tr><td class="fw-semibold small"><?= h($label) ?></td>
          <?php foreach (array_keys(IspNotify::CHANNELS) as $ch): $r = $grid[$ev][$ch] ?? null; ?>
            <td class="small" style="max-width:20rem">
              <?php if ($r): ?><a href="<?= h(url($pk, ['tpl' => (int)$r['id']])) ?>"><?= h(mb_substr((string)($r['subject'] ?: $r['body']), 0, 70)) ?><?= mb_strlen((string)($r['subject'] ?: $r['body'])) > 70 ? '...' : '' ?></a>
                <div><?= IspUi::status((int)$r['is_active'] ? 'active' : 'inactive', ['active' => 'Active', 'inactive' => 'Off']) ?></div>
              <?php elseif (Access::can($pk, 'create')): ?><?= IspUi::actionForm('tpl_add', 'Add', ['event_code' => $ev, 'channel' => $ch], 'btn-outline-secondary', 'plus') ?>
              <?php else: ?><span class="text-muted">None</span><?php endif; ?></td>
          <?php endforeach; ?></tr>
        <?php endforeach; ?>
      </tbody></table></div>
    </div></div>
    <?php
    layout_end();
    exit;
}

// ------------------------------------------------------------------ announcements
if ($tab === 'announcements') {
    $annId = gint('ann');
    $editing = g('a') === 'new' || $annId;
    $a = $annId ? DB::row('SELECT * FROM isp_announcements WHERE id = :id', ['id' => $annId]) : null;
    if ($editing && ($a || g('a') === 'new') && Access::can($pk, $a ? 'edit' : 'create')) {
        $dtv = fn($v) => $v ? date('Y-m-d\TH:i', strtotime((string)$v)) : '';
        ?>
        <div class="card isp-card"><div class="card-body">
          <h2 class="sec-title"><?= $a ? 'Edit announcement' : 'New announcement' ?></h2>
          <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="ann_save"><input type="hidden" name="id" value="<?= (int)($a['id'] ?? 0) ?>">
            <?= input_field('title', 'Title', fv('title', $a), ['class' => 'col-md-8', 'required' => true, 'extra' => 'maxlength="160"']) ?>
            <?= select_field('area_id', 'For the subscribers of', $areas, fv('area_id', $a), ['blank' => '(every area)', 'class' => 'col-md-4']) ?>
            <div class="col-12"><label class="form-label">Text</label><textarea class="form-control form-control-sm" name="body" rows="4" required><?= h((string)fv('body', $a)) ?></textarea></div>
            <?= input_field('starts_at', 'Shown from', is_post() ? (string)p('starts_at') : $dtv($a['starts_at'] ?? date('Y-m-d H:i')), ['type' => 'datetime-local', 'class' => 'col-md-4']) ?>
            <?= input_field('ends_at', 'Shown until (empty: until taken down)', is_post() ? (string)p('ends_at') : $dtv($a['ends_at'] ?? null), ['type' => 'datetime-local', 'class' => 'col-md-4']) ?>
            <?= check_field('is_active', 'Active', (string)fv('is_active', $a, '1') === '1', 'col-md-4') ?>
            <?= check_field('notify', 'Also send it to the subscribers of the area (Scheduled maintenance message, each subscriber once)', false, 'col-12') ?>
            <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-megaphone"></i> Save announcement</button> <?= btn_link(url($pk, ['tab' => 'announcements']), 'Cancel') ?></div>
          </form>
        </div></div>
        <?php
        layout_end();
        exit;
    }
    $rows = DB::all('SELECT n.*, ar.name AS area_name, o.outage_no, u.full_name FROM isp_announcements n LEFT JOIN isp_areas ar ON ar.id = n.area_id LEFT JOIN isp_outages o ON o.id = n.outage_id
        LEFT JOIN users u ON u.id = n.created_by ORDER BY n.is_active DESC, n.starts_at DESC LIMIT 200');
    $now = date('Y-m-d H:i:s');
    ?>
    <div class="d-flex justify-content-end mb-2"><?= page_link($pk, ['tab' => 'announcements', 'a' => 'new'], 'New announcement', 'btn-primary', 'plus-lg', 'create') ?></div>
    <div class="card isp-card"><div class="card-body">
    <?php if (!$rows): ?>
      <?= IspUi::empty('No announcement yet. Announcements show on the customer portal; outage notices are added from Network Outages.', 'megaphone') ?>
    <?php else: ?>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
        <thead><tr><th>Announcement</th><th>Area</th><th>Shown</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $r): $live = (int)$r['is_active'] && $r['starts_at'] <= $now && (!$r['ends_at'] || $r['ends_at'] >= $now); ?>
          <tr><td><a href="<?= h(url($pk, ['tab' => 'announcements', 'ann' => (int)$r['id']])) ?>"><?= h($r['title']) ?></a><div class="small text-muted"><?= h(mb_substr($r['body'], 0, 120)) ?></div>
              <?= $r['outage_no'] ? '<div class="small">Outage ' . h($r['outage_no']) . '</div>' : '' ?></td>
            <td class="small"><?= h((string)($r['area_name'] ?: 'Every area')) ?></td>
            <td class="small text-nowrap"><?= h(fdatetime($r['starts_at'])) ?><br><?= $r['ends_at'] ? 'to ' . h(fdatetime($r['ends_at'])) : 'until taken down' ?></td>
            <td><?= IspUi::status($live ? 'active' : ((int)$r['is_active'] && $r['starts_at'] > $now ? 'scheduled' : 'inactive'), ['active' => 'Showing', 'scheduled' => 'Scheduled', 'inactive' => 'Ended']) ?></td>
            <td class="text-end"><?php if ((int)$r['is_active'] && $canEdit): ?><?= IspUi::actionForm('ann_end', 'Take down', ['id' => $r['id']], 'btn-outline-secondary', 'eye-slash', 'Take this announcement down from the portal?') ?><?php endif; ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
    </div></div>
    <?php
    layout_end();
    exit;
}

// ------------------------------------------------------------------ contract templates
$ctId = gint('ct');
$ct = $ctId ? DB::row('SELECT * FROM isp_contract_templates WHERE id = :id', ['id' => $ctId]) : null;
if ($ct || g('a') === 'new') {
    $canSave = Access::can($pk, $ct ? 'edit' : 'create');
    ?>
    <div class="row g-3">
      <div class="col-lg-8"><div class="card isp-card"><div class="card-body">
        <h2 class="sec-title"><?= $ct ? h($ct['name']) : 'New contract template' ?></h2>
        <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="ct_save"><input type="hidden" name="id" value="<?= (int)($ct['id'] ?? 0) ?>">
          <?= input_field('name', 'Name', fv('name', $ct), ['class' => 'col-12', 'required' => true, 'extra' => 'maxlength="160"']) ?>
          <div class="col-12"><label class="form-label">Agreement text</label><textarea class="form-control form-control-sm font-monospace" name="body" rows="18" required<?= $canSave ? '' : ' readonly' ?>><?= h((string)fv('body', $ct)) ?></textarea></div>
          <?= check_field('is_default', 'Default template for new contracts', (string)fv('is_default', $ct, '0') === '1', 'col-md-6') ?>
          <?= check_field('is_active', 'Active', (string)fv('is_active', $ct, '1') === '1', 'col-md-6') ?>
          <?php if ($canSave): ?><div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> Save template</button> <?= btn_link(url($pk, ['tab' => 'contracts']), 'Cancel') ?></div><?php endif; ?>
        </form>
      </div></div></div>
      <div class="col-lg-4"><div class="card isp-card"><div class="card-body">
        <h2 class="sec-title">Placeholders</h2>
        <div class="d-flex flex-wrap gap-1 mb-2"><?php foreach (ISPSET_CONTRACT_VARS as $vname): ?><code class="me-1">{<?= h($vname) ?>}</code><?php endforeach; ?></div>
        <p class="small text-muted mb-0">Each contract keeps the text it was made with, so changing a template never changes a contract already signed. Have the agreement reviewed by counsel; the subscriber disclosures (fees, lock-in, termination, interruptions, privacy, complaints) belong in it.</p>
      </div></div></div>
    </div>
    <?php
    layout_end();
    exit;
}
$rows = DB::all('SELECT t.*, (SELECT COUNT(*) FROM isp_contracts c WHERE c.template_id = t.id) AS used_n FROM isp_contract_templates t ORDER BY t.is_default DESC, t.name');
?>
<div class="d-flex justify-content-end mb-2"><?= page_link($pk, ['tab' => 'contracts', 'a' => 'new'], 'New contract template', 'btn-primary', 'plus-lg', 'create') ?></div>
<div class="card isp-card"><div class="card-body">
<?php if (!$rows): ?>
  <?= IspUi::empty('No contract template yet.', 'file-earmark-text') ?>
<?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
    <thead><tr><th>Template</th><th class="num">Contracts made with it</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><a href="<?= h(url($pk, ['tab' => 'contracts', 'ct' => (int)$r['id']])) ?>"><?= h($r['name']) ?></a><?= (int)$r['is_default'] ? '<div class="small text-muted">Default for new contracts</div>' : '' ?></td>
        <td class="num"><?= (int)$r['used_n'] ?></td><td><?= IspUi::status((int)$r['is_active'] ? 'active' : 'inactive', ['active' => 'Active', 'inactive' => 'Inactive']) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
<?php endif; ?>
</div></div>
<?php
layout_end();
