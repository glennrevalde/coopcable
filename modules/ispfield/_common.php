<?php
declare(strict_types=1);

/**
 * What the field pages share (My Tasks, Work Orders & Dispatch, Support Tickets): the work order card, the
 * completion form and its POST, the work-site photographs (stored under storage/isp with random names, never served
 * directly: only through an access-checked action of the page).
 */

const ISPF_PHOTO_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
const ISPF_PHOTO_MAX = 5242880;

/** May the user see this work order? Technicians only their own; supervisors (approve right) and admins every one */
function ispf_may_see_wo(array $w): bool
{
    if (Access::isAdmin() || Access::can('ispfield.workorders', 'approve') || Access::can('ispfield.workorders', 'create')) {
        return true;
    }
    $mine = IspSetup::myTechnicianId();
    if ($mine) {
        return (int)$w['technician_id'] === $mine;
    }
    return Access::page('ispfield.workorders');
}

/** The supervisor's view of the completion: labor costs, the account that paid them, completing with unticked items */
function ispf_supervisor(): bool
{
    return Access::isAdmin() || Access::can('ispfield.workorders', 'approve');
}

/** Stores the photographs posted in $_FILES[$field] for a work order; returns how many were kept */
function ispf_store_photos(string $field, string $entity, int $entityId, string $label = ''): int
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'])) {
        return 0;
    }
    $dir = Tenant::storage('isp/' . $entity);
    Master::protectDir(dirname($dir));
    Master::protectDir($dir);
    // every photograph is checked before any is kept: one bad file keeps none
    $ok = [];
    foreach ($_FILES[$field]['name'] as $i => $orig) {
        $err = (int)$_FILES[$field]['error'][$i];
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($err !== UPLOAD_ERR_OK) {
            fail('Photo ' . $orig . ' was not received (upload error ' . $err . '). The limit is 5 MB per photo.');
        }
        $tmp = (string)$_FILES[$field]['tmp_name'][$i];
        $size = (int)$_FILES[$field]['size'][$i];
        if ($size <= 0 || $size > ISPF_PHOTO_MAX) {
            fail('Photo ' . $orig . ' is larger than 5 MB.');
        }
        $info = @getimagesize($tmp);
        $mime = is_array($info) ? (string)$info['mime'] : '';
        if (!isset(ISPF_PHOTO_TYPES[$mime]) || !is_uploaded_file($tmp)) {
            fail('Photo ' . $orig . ' is not a JPG, PNG or WebP picture.');
        }
        $ok[] = [$tmp, (string)$orig, $size, $mime];
    }
    $n = 0;
    foreach ($ok as [$tmp, $orig, $size, $mime]) {
        $stored = bin2hex(random_bytes(16)) . '.' . ISPF_PHOTO_TYPES[$mime];
        if (!move_uploaded_file($tmp, $dir . '/' . $stored)) {
            fail('Photo ' . $orig . ' could not be saved. Make the storage folder writable.');
        }
        DB::insert('isp_files', ['entity_type' => $entity, 'entity_id' => $entityId, 'label' => $label !== '' ? mb_substr($label, 0, 120) : null, 'stored_name' => $entity . '/' . $stored,
            'original_name' => mb_substr(basename($orig), 0, 160), 'mime' => $mime, 'size_bytes' => $size, 'uploaded_by' => Auth::id()]);
        $n++;
    }
    return $n;
}

/** Sends a stored file (after the page checked that the user may see its record) and stops */
function ispf_send_file(array $f): never
{
    $path = Tenant::storage('isp') . '/' . str_replace(['..', '\\'], '', (string)$f['stored_name']);
    if (!is_file($path)) {
        http_response_code(404);
        exit('The file is no longer on the server.');
    }
    header('Content-Type: ' . $f['mime']);
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($f['original_name'] ?: basename($path))) . '"');
    header('Cache-Control: private, max-age=3600');
    readfile($path);
    exit;
}

function ispf_files(string $entity, int $id): array
{
    return DB::all('SELECT f.*, u.full_name FROM isp_files f LEFT JOIN users u ON u.id = f.uploaded_by WHERE f.entity_type = :e AND f.entity_id = :i ORDER BY f.id', ['e' => $entity, 'i' => $id]);
}

/** The photograph thumbnails of a record ($pk: the page that serves them with ?a=file&fid=) */
function ispf_gallery(array $files, string $pk, array $keep = []): string
{
    if (!$files) {
        return '<p class="small text-muted mb-0">No photographs yet.</p>';
    }
    $out = '<div class="d-flex flex-wrap gap-2">';
    foreach ($files as $f) {
        $u = url($pk, $keep + ['a' => 'file', 'fid' => (int)$f['id']]);
        $out .= '<a href="' . h($u) . '" target="_blank" rel="noopener" class="d-block border rounded overflow-hidden" style="width:96px;height:96px" title="' . h(($f['label'] ?: $f['original_name']) . ', ' . fdatetime($f['created_at'])) . '">'
            . '<img src="' . h($u) . '" alt="' . h((string)($f['label'] ?: 'Work-site photograph')) . '" loading="lazy" style="width:100%;height:100%;object-fit:cover"></a>';
    }
    return $out . '</div>';
}

/** The work order's materials, with what was used and returned */
function ispf_materials(int $woId): array
{
    return DB::all('SELECT m.*, i.code, i.name, i.unit FROM isp_wo_materials m JOIN items i ON i.id = m.item_id WHERE m.work_order_id = :w ORDER BY m.id', ['w' => $woId]);
}

/** Stocked items for the material lines: id => label */
function ispf_stock_items(): array
{
    return Lookup::itemPairs("is_active = 1 AND item_type IN ('inventory', 'assembly')");
}

/** One work order as a card (task lists and the dispatch board) */
function ispf_wo_card(array $w, string $pk, bool $compact = false): string
{
    $title = h($w['wo_no']) . ' <span class="text-muted fw-normal">' . h(IspWorkOrders::TYPES[$w['wo_type']] ?? $w['wo_type']) . '</span>';
    $who = $w['account_id'] ? h(trim($w['account_no'] . ' ' . $w['account_name'])) : '';
    $out = '<div class="card isp-card h-100"><div class="card-body p-3">'
        . '<div class="d-flex justify-content-between gap-2 align-items-start"><div><a class="fw-semibold" href="' . h(url($pk, ['id' => (int)$w['id']])) . '">' . $title . '</a>'
        . ($who !== '' ? '<div class="small">' . $who . '</div>' : '') . '</div><div class="text-end small text-nowrap">' . IspUi::status($w['status'], IspWorkOrders::STATUSES)
        . '<div>' . IspUi::status($w['priority'], IspWorkOrders::PRIORITIES) . '</div></div></div>';
    if (!$compact) {
        $out .= '<div class="small mt-2">' . h($w['description']) . '</div>';
    }
    $out .= '<div class="small text-muted mt-2"><i class="bi bi-geo-alt"></i> ' . h((string)($w['address'] ?: 'No address')) . '</div>';
    if ($w['scheduled_date']) {
        $out .= '<div class="small text-muted"><i class="bi bi-calendar-event"></i> ' . h(fdate($w['scheduled_date'])) . ' ' . h(IspWorkOrders::SLOTS[$w['scheduled_slot']] ?? (string)$w['scheduled_slot'])
            . ($w['route_seq'] ? ', stop ' . (int)$w['route_seq'] : '') . '</div>';
    }
    return $out . '</div></div>';
}

/** The completion data of the form posted by ispf_complete_form() */
function ispf_completion_input(array $w): array
{
    $d = [];
    $d['checklist'] = array_map('intval', array_keys(array_filter((array)($_POST['checklist'] ?? []), fn($v) => (string)$v === '1')));
    $d['partial_ok'] = ispf_supervisor() && p('partial_ok') === '1';
    foreach (['cable_used_m', 'onu_serial', 'router_serial', 'rx_power_dbm', 'tx_power_dbm', 'speed_test_down', 'speed_test_up', 'labor_hours', 'findings', 'remarks', 'customer_accepted_by'] as $k) {
        $d[$k] = trim((string)p($k));
    }
    foreach (['cable_used_m', 'rx_power_dbm', 'tx_power_dbm', 'speed_test_down', 'speed_test_up', 'labor_hours'] as $k) {
        if ($d[$k] !== '' && !is_numeric($d[$k])) {
            fail('Enter a number for ' . str_replace('_', ' ', $k) . '.');
        }
    }
    if (ispf_supervisor()) {
        $d['labor_cost'] = pnum('labor_cost');
        $d['travel_expense'] = pnum('travel_expense');
        $d['expense_account_id'] = pint('expense_account_id');
        if (($d['labor_cost'] + $d['travel_expense']) > 0 && !$d['expense_account_id']) {
            fail('Choose the account the labor and travel were paid from, or leave the amounts at zero.');
        }
    }
    $d['port_id'] = pint('port_id');
    $d['materials'] = [];
    foreach ((array)($_POST['used'] ?? []) as $mid => $used) {
        $d['materials'][(int)$mid] = [is_scalar($used) && $used !== '' ? (float)$used : null, null];
    }
    $d['recovered'] = [];
    foreach ((array)($_POST['recovered'] ?? []) as $eq => $how) {
        if (is_string($how) && in_array($how, ['recovered', 'damaged', 'missing'], true)) {
            $d['recovered'][(int)$eq] = $how;
        }
    }
    if ($w['net_op_id']) {
        $d['net_done'] = p('net_done') === '0' ? '0' : '1';
    }
    return $d;
}

/** The completion form of a work order (the technician on site, or the supervisor recording it) */
function ispf_complete_form(array $w, string $pk): string
{
    $items = IspWorkOrders::CHECKLISTS[$w['wo_type']] ?? [];
    $ticked = $_POST['checklist'] ?? [];
    $o = '<form method="post" enctype="multipart/form-data" class="isp-complete" data-confirm="Complete work order ' . h($w['wo_no']) . '? Materials used are taken out of the stock.">'
        . csrf_field() . '<input type="hidden" name="action" value="complete"><input type="hidden" name="id" value="' . (int)$w['id'] . '">';
    if ($items) {
        $o .= '<h3 class="h6 mt-1">Checklist</h3><div class="row g-1 mb-2">';
        foreach ($items as $i => $text) {
            $o .= '<div class="col-md-6"><label class="form-check d-flex gap-2 align-items-center py-1"><input class="form-check-input mt-0" type="checkbox" name="checklist[' . $i . ']" value="1"'
                . (!empty($ticked[$i]) ? ' checked' : '') . '><span>' . h($text) . '</span></label></div>';
        }
        $o .= '</div>';
        if (ispf_supervisor()) {
            $o .= '<div class="form-check small mb-2"><input class="form-check-input" type="checkbox" name="partial_ok" value="1" id="partial_ok"' . (p('partial_ok') === '1' ? ' checked' : '')
                . '><label class="form-check-label" for="partial_ok">Complete with unticked items (say why in the remarks)</label></div>';
        }
    }
    $o .= '<h3 class="h6 mt-3">Readings and units</h3><div class="row g-2">';
    if (in_array($w['wo_type'], ['installation', 'relocation', 'repair', 'replacement'], true)) {
        $o .= input_field('cable_used_m', 'Drop cable used (m)' . ($w['wo_type'] === 'installation' ? ' *' : ''), fv('cable_used_m', $w), ['type' => 'number', 'step' => '0.1', 'class' => 'col-6 col-md-3', 'extra' => 'inputmode="decimal"'])
            . input_field('rx_power_dbm', 'Rx power (dBm)', fv('rx_power_dbm', $w), ['type' => 'number', 'step' => '0.01', 'class' => 'col-6 col-md-3', 'extra' => 'inputmode="decimal" placeholder="-8 to -27"'])
            . input_field('tx_power_dbm', 'Tx power (dBm)', fv('tx_power_dbm', $w), ['type' => 'number', 'step' => '0.01', 'class' => 'col-6 col-md-3', 'extra' => 'inputmode="decimal"'])
            . input_field('speed_test_down', 'Speed test down (Mbps)', fv('speed_test_down', $w), ['type' => 'number', 'step' => '0.01', 'class' => 'col-6 col-md-3', 'extra' => 'inputmode="decimal"'])
            . input_field('speed_test_up', 'Speed test up (Mbps)', fv('speed_test_up', $w), ['type' => 'number', 'step' => '0.01', 'class' => 'col-6 col-md-3', 'extra' => 'inputmode="decimal"'])
            . input_field('onu_serial', 'ONU / ONT serial', fv('onu_serial', $w), ['class' => 'col-6 col-md-3', 'extra' => 'autocapitalize="characters"'])
            . input_field('router_serial', 'Router serial', fv('router_serial', $w), ['class' => 'col-6 col-md-3', 'extra' => 'autocapitalize="characters"']);
        if ($w['account_id'] && in_array($w['wo_type'], ['installation', 'relocation'], true)) {
            $acct = IspAccounts::get((int)$w['account_id']);
            $keep = $w['port_id'] ?: ($acct['port_id'] ?? null);
            $o .= select_field('port_id', 'NAP drop port', IspInfra::freePorts($acct['area_id'] ?? null, $keep ? (int)$keep : null), fv('port_id', null, $keep), ['blank' => '(not changed)', 'class' => 'col-md-6']);
        }
    }
    $o .= input_field('labor_hours', 'Labor hours', fv('labor_hours', $w), ['type' => 'number', 'step' => '0.25', 'class' => 'col-6 col-md-3', 'extra' => 'inputmode="decimal"']);
    if (ispf_supervisor()) {
        $o .= input_field('labor_cost', 'Labor cost paid', fv('labor_cost', $w, ''), ['type' => 'number', 'class' => 'col-6 col-md-3'])
            . input_field('travel_expense', 'Travel expense paid', fv('travel_expense', $w, ''), ['type' => 'number', 'class' => 'col-6 col-md-3'])
            . select_field('expense_account_id', 'Paid from', Accts::ofType(['Bank', 'Other Current Asset', 'Other Current Liability', 'Accounts Payable']), fv('expense_account_id'), ['blank' => '(not paid here)', 'class' => 'col-md-6']);
    }
    $o .= '</div>';

    // materials issued to the job
    $mats = array_values(array_filter(ispf_materials((int)$w['id']), fn($m) => $m['status'] === 'issued'));
    if ($mats) {
        $o .= '<h3 class="h6 mt-3">Materials issued: quantity used (the rest goes back to the stockroom)</h3><div class="table-responsive"><table class="table table-sm align-middle isp-table"><thead><tr><th>Item</th><th>Serial</th><th class="num">Issued</th><th class="num" style="width:9rem">Used</th></tr></thead><tbody>';
        foreach ($mats as $m) {
            $o .= '<tr><td>' . h($m['code'] . ' ' . $m['name']) . '</td><td>' . h((string)$m['serial_no']) . '</td><td class="num">' . h(qty($m['qty_issued'])) . ' ' . h((string)$m['unit']) . '</td>'
                . '<td><input class="form-control form-control-sm text-end" type="number" step="0.01" min="0" max="' . h((string)(float)$m['qty_issued']) . '" name="used[' . (int)$m['id'] . ']" value="'
                . h((string)($_POST['used'][$m['id']] ?? qty($m['qty_issued']))) . '" inputmode="decimal"></td></tr>';
        }
        $o .= '</tbody></table></div>';
    } else {
        $o .= '<p class="small text-muted mt-3 mb-0">No materials were issued to this job. A supervisor issues them on Work Orders &amp; Dispatch before the work starts.</p>';
    }

    // equipment to collect
    if ($w['wo_type'] === 'recovery' && $w['account_id']) {
        $units = array_filter(IspEquipment::ofAccount((int)$w['account_id']), fn($e) => in_array($e['status'], ['installed', 'for_recovery'], true) && $e['ownership'] === 'company');
        $o .= '<h3 class="h6 mt-3">Equipment to recover</h3>';
        if (!$units) {
            $o .= '<p class="small text-muted">No company-owned unit is recorded at this subscriber.</p>';
        }
        foreach ($units as $e) {
            $o .= '<div class="row g-2 align-items-center mb-1"><div class="col-md-6 small">' . h($e['item_code'] . ' ' . $e['item_name'] . ', serial ' . $e['serial_no']) . '</div><div class="col-md-6">'
                . '<select class="form-select form-select-sm" name="recovered[' . (int)$e['id'] . ']">' . options(['recovered' => 'Recovered in good condition', 'damaged' => 'Recovered damaged', 'missing' => 'Not returned (charged to the subscriber)'], $_POST['recovered'][$e['id']] ?? null, '(not collected)') . '</select></div></div>';
        }
    }
    if ($w['net_op_id']) {
        $o .= '<div class="row g-2 mt-2">' . select_field('net_done', 'The network work (' . h(IspNetwork::OP_TYPES[(string)DB::value('SELECT op_type FROM isp_net_ops WHERE id = :o', ['o' => $w['net_op_id']])] ?? 'operation') . ') was done', ['1' => 'Yes, done on site', '0' => 'No, could not be done'], fv('net_done', null, '1'), ['class' => 'col-md-6']) . '</div>';
    }
    $o .= '<h3 class="h6 mt-3">Findings and acceptance</h3><div class="row g-2">'
        . '<div class="col-md-6"><label class="form-label">Findings / work done</label><textarea class="form-control form-control-sm" name="findings" rows="2">' . h((string)fv('findings', $w)) . '</textarea></div>'
        . '<div class="col-md-6"><label class="form-label">Remarks</label><textarea class="form-control form-control-sm" name="remarks" rows="2">' . h((string)p('remarks')) . '</textarea></div>'
        . input_field('customer_accepted_by', 'Accepted by (subscriber or representative)' . ($w['wo_type'] === 'installation' ? ' *' : ''), fv('customer_accepted_by', $w), ['class' => 'col-md-6'])
        . '<div class="col-md-6"><label class="form-label">Work-site photographs (JPG, PNG or WebP, up to 5 MB each)</label><input class="form-control form-control-sm" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" capture="environment" multiple></div>'
        . '</div><div class="mt-3 d-flex gap-2 flex-wrap"><button class="btn btn-primary btn-sm"><i class="bi bi-check2-circle"></i> Complete work order</button></div></form>';
    return $o;
}

/** Saves the posted completion of a work order (and its photographs) */
function ispf_do_complete(string $pk): int
{
    $id = (int)p('id');
    $w = IspWorkOrders::get($id) ?? fail('Work order not found.');
    if (!ispf_may_see_wo($w)) {
        fail('Work order ' . $w['wo_no'] . ' is assigned to another technician.');
    }
    $d = ispf_completion_input($w);
    return DB::tx(function () use ($id, $d, $w) {
        IspWorkOrders::complete($id, $d);
        return ispf_store_photos('photos', 'work_order', $id, 'Completion ' . $w['wo_no']);
    });
}
