<?php
declare(strict_types=1);

/**
 * Applications & Activation (Workflow A): New Application -> For Verification -> Site Survey -> Approved ->
 * For Installation -> Installed -> For Activation -> Active, with the identity check, the coverage and site survey, the
 * reserved NAP port, the quotation, the contract acceptance, the approval (subscriber, account and installation work
 * order) and the activation. Rejected and cancelled applications stay on file with their reason.
 */
$pk = 'isp.applications';
$id = (int)g('id', (string)p('id'));
$action = (string)g('a');

// a sales agent works on own applications only
$myAgent = Auth::id() ? DB::row('SELECT * FROM isp_agents WHERE user_id = :u AND is_active = 1', ['u' => Auth::id()]) : null;
$agentOnly = $myAgent && !Access::can($pk, 'approve') && !Access::can($pk, 'edit');

$app = $id ? IspApplications::get($id) : null;
if ($app) {
    $myBranch = IspSetup::userBranch();
    if (($myBranch !== null && $app['branch_id'] !== null && (int)$app['branch_id'] !== $myBranch) || ($agentOnly && (int)$app['agent_id'] !== (int)$myAgent['id'])) {
        Access::denyPage($pk);
    }
}

$fileDir = function (): string {
    $dir = Tenant::storage('isp') . '/application';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    Master::protectDir(Tenant::storage('isp'));
    Master::protectDir($dir);
    return $dir;
};
if ($app && $action === 'file') {
    $f = DB::row("SELECT * FROM isp_files WHERE id = :f AND entity_type = 'application' AND entity_id = :a", ['f' => (int)g('fid'), 'a' => $id]);
    $path = $f ? $fileDir() . '/' . basename((string)$f['stored_name']) : '';
    if (!$f || !is_file($path)) {
        http_response_code(404);
        exit('File not found.');
    }
    header('Content-Type: ' . $f['mime']);
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($f['original_name'] ?: $f['stored_name'])) . '"');
    readfile($path);
    exit;
}

handle_post(function () use ($pk, $id, $app, $myAgent, $agentOnly, $fileDir) {
    $act = (string)p('action');
    $reason = trim((string)p('reason'));
    if ($act === 'create') {
        Access::require($pk, 'create');
        $f = [];
        foreach (['classification', 'last_name', 'first_name', 'middle_name', 'business_name', 'mobile', 'email', 'billing_address', 'install_address', 'landmark',
                  'latitude', 'longitude', 'id_type', 'id_number', 'preferred_date', 'notes'] as $k) {
            $f[$k] = trim((string)p($k));
        }
        if (!isset(IspAccounts::CLASSIFICATIONS[$f['classification']])) {
            $f['classification'] = 'residential';
        }
        if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
            fail('The e-mail address is not valid.');
        }
        if ($f['preferred_date'] !== '' && !valid_date($f['preferred_date'])) {
            fail('The preferred installation date is not valid.');
        }
        foreach (['latitude', 'longitude'] as $k) {
            if ($f[$k] !== '' && !is_numeric($f[$k])) {
                fail('The ' . $k . ' is not valid.');
            }
        }
        $f['party_id'] = $agentOnly ? null : pint('party_id');
        $f['plan_id'] = pint('plan_id');
        $f['area_id'] = pint('area_id');
        $f['branch_id'] = pint('branch_id');
        $f['agent_id'] = $agentOnly ? (int)$myAgent['id'] : pint('agent_id');
        $f['privacy_consent'] = p('privacy_consent') === '1';
        $channel = $agentOnly ? 'agent' : (string)p('channel');
        if (!$f['area_id']) {
            fail('Choose the service area of the installation address.');
        }
        $newId = IspApplications::create($f, isset(IspApplications::CHANNELS[$channel]) && $channel !== 'online' ? $channel : 'walkin');
        redirect_after_save(url($pk, ['a' => 'new']), url($pk, ['id' => $newId]));
    }
    if (!$app) {
        fail('Application not found.');
    }
    switch ($act) {
        case 'start':
            Access::require($pk, 'edit');
            IspApplications::move($id, 'for_verification', $reason ?: 'Documents being checked');
            redirect(url($pk, ['id' => $id]), 'The application is for verification.', 'success');
        case 'verify':
            Access::require($pk, 'edit');
            IspApplications::verifyIdentity($id, trim((string)p('id_type')), trim((string)p('id_number')));
            redirect(url($pk, ['id' => $id]), 'Identity verified; the application is at the site survey.', 'success');
        case 'survey':
            Access::require($pk, 'edit');
            $sd = pdate('survey_date', today());
            foreach (['est_cable_m', 'quote_installation', 'quote_materials', 'quote_deposit', 'latitude', 'longitude'] as $k) {
                $v = trim((string)p($k));
                if ($v !== '' && !is_numeric($v)) {
                    fail('Enter a number for ' . str_replace(['quote_', '_'], ['', ' '], $k) . '.');
                }
                if ($v !== '' && in_array($k, ['est_cable_m', 'quote_installation', 'quote_materials', 'quote_deposit'], true) && (float)$v < 0) {
                    fail('The amounts cannot be negative.');
                }
            }
            IspApplications::survey($id, ['coverage_ok' => p('coverage_ok') === '1', 'survey_feasible' => p('survey_feasible') === '1', 'survey_notes' => trim((string)p('survey_notes')),
                'survey_date' => $sd, 'est_cable_m' => trim((string)p('est_cable_m')), 'port_id' => pint('port_id'), 'quote_installation' => trim((string)p('quote_installation')),
                'quote_materials' => trim((string)p('quote_materials')), 'quote_deposit' => trim((string)p('quote_deposit')), 'latitude' => trim((string)p('latitude')), 'longitude' => trim((string)p('longitude'))]);
            redirect(url($pk, ['id' => $id]), 'Site survey and quotation saved.', 'success');
        case 'accept':
            Access::require($pk, 'edit');
            if (p('agree') !== '1') {
                fail('Tick that the applicant read and accepted the terms.');
            }
            IspApplications::acceptContract($id, trim((string)p('accepted_name')));
            redirect(url($pk, ['id' => $id]), 'The contract acceptance is recorded.', 'success');
        case 'approve':
            Access::require($pk, 'approve');
            $r = IspApplications::approve($id, $reason);
            redirect(url($pk, ['id' => $id]), 'Approved: account ' . DB::value('SELECT account_no FROM isp_accounts WHERE id = :i', ['i' => $r['account_id']]) . ' and installation work order '
                . DB::value('SELECT wo_no FROM isp_work_orders WHERE id = :i', ['i' => $r['work_order_id']]) . ' were created.', 'success');
        case 'activate':
            Access::require($pk, 'approve');
            if (!$app['account_id']) {
                fail('The application has no account yet.');
            }
            $date = pdate('activation_date', today()) ?? today();
            if ($date > today()) {
                fail('The activation date cannot be in the future.');
            }
            $r = IspAccounts::activate((int)$app['account_id'], $date, $pk);
            redirect(url($pk, ['id' => $id]), 'The service is active' . (!empty($r['invoice_id']) ? '; the first invoice ' . DB::value('SELECT invoice_no FROM isp_invoices WHERE id = :i', ['i' => $r['invoice_id']]) . ' was issued' : '') . '.', 'success');
        case 'reject':
            Access::require($pk, 'approve');
            IspApplications::move($id, 'rejected', $reason);
            Audit::log('reject', 'isp', 'isp_application', $id, (string)$app['application_no'], ['reason' => $reason]);
            redirect(url($pk, ['id' => $id]), 'The application was rejected; it stays on file with the reason.', 'success');
        case 'cancel':
            Access::require($pk, 'edit');
            if ($app['account_id'] && in_array($app['status'], ['approved', 'for_installation', 'for_activation'], true)) {
                $acc = IspAccounts::get((int)$app['account_id']);
                if ($acc && $acc['service_status'] === 'pending') {
                    DB::tx(function () use ($app, $acc, $reason) {
                        IspApplications::move((int)$app['id'], 'cancelled', $reason);
                        if ($app['work_order_id']) {
                            $wo = IspWorkOrders::get((int)$app['work_order_id']);
                            if ($wo && !in_array($wo['status'], ['completed', 'verified', 'cancelled'], true)) {
                                IspWorkOrders::cancel((int)$wo['id'], 'Application cancelled: ' . $reason);
                            }
                        }
                        IspAccounts::setStatus((int)$acc['id'], 'cancelled', 'Application cancelled: ' . $reason);
                        if ($acc['port_id']) {
                            IspInfra::releasePort((int)$acc['port_id']);
                        }
                    });
                    Audit::log('cancel', 'isp', 'isp_application', $id, (string)$app['application_no'], ['reason' => $reason]);
                    redirect(url($pk, ['id' => $id]), 'The application and its pending account were cancelled.', 'success');
                }
            }
            IspApplications::move($id, 'cancelled', $reason);
            Audit::log('cancel', 'isp', 'isp_application', $id, (string)$app['application_no'], ['reason' => $reason]);
            redirect(url($pk, ['id' => $id]), 'The application was cancelled; it stays on file with the reason.', 'success');
        case 'upload':
            Access::require($pk, 'edit');
            $up = $_FILES['file'] ?? null;
            if (!$up || !is_array($up) || (int)($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                fail('Choose the file to upload.');
            }
            if ((int)$up['error'] !== UPLOAD_ERR_OK || (int)$up['size'] > 5 * 1024 * 1024) {
                fail('The upload failed or the file is larger than 5 MB.');
            }
            $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$up['tmp_name']);
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime] ?? null;
            if (!$ext) {
                fail('Only JPG, PNG, WEBP images and PDF files can be uploaded.');
            }
            $stored = bin2hex(random_bytes(16)) . '.' . $ext;
            if (!is_uploaded_file((string)$up['tmp_name']) || !move_uploaded_file((string)$up['tmp_name'], $fileDir() . '/' . $stored)) {
                fail('The file could not be stored on the server.');
            }
            $label = trim((string)p('label')) ?: 'Document';
            $fid = DB::insert('isp_files', ['entity_type' => 'application', 'entity_id' => $id, 'label' => mb_substr($label, 0, 120), 'stored_name' => $stored,
                'original_name' => mb_substr(basename((string)$up['name']), 0, 160), 'mime' => $mime, 'size_bytes' => (int)$up['size'], 'uploaded_by' => Auth::id()]);
            DB::insert('isp_application_log', ['application_id' => $id, 'from_status' => $app['status'], 'to_status' => $app['status'], 'remarks' => 'Document attached: ' . $label, 'user_id' => Auth::id()]);
            Audit::log('create', 'isp', 'isp_file', $fid, $label);
            redirect(url($pk, ['id' => $id]), 'Document attached.', 'success');
    }
    fail('Unknown action.');
});

$areaOpts = DB::pairs("SELECT a.id, CONCAT(a.name, CASE WHEN p.name IS NULL THEN '' ELSE CONCAT(', ', p.name) END, CASE WHEN a.is_serviceable = 1 THEN '' ELSE ' (no coverage)' END)
    FROM isp_areas a LEFT JOIN isp_areas p ON p.id = a.parent_id WHERE a.is_active = 1 ORDER BY p.name, a.name");

// ================================================================ NEW APPLICATION
if ($action === 'new') {
    Access::require($pk, 'create');
    $plans = DB::all("SELECT * FROM isp_plans WHERE status = 'active' ORDER BY sort_order, monthly_fee, name");
    $planAreas = [];
    foreach (DB::all('SELECT plan_id, area_id FROM isp_plan_areas') as $r) {
        $planAreas[(int)$r['plan_id']][] = (int)$r['area_id'];
    }
    // a plan limited to some areas is offered in those areas and the areas inside them
    $areaParents = DB::pairs('SELECT id, parent_id FROM isp_areas');
    $agents = DB::pairs("SELECT id, CONCAT(code, ' ', name) FROM isp_agents WHERE is_active = 1 ORDER BY name");
    $branches = IspSetup::branches();
    layout_start('New Application', ['actions' => btn_link(url($pk), 'Applications', 'btn-outline-secondary', 'arrow-left')]);
    ?>
<form method="post" class="card isp-card"><div class="card-body">
  <?= csrf_field() ?><input type="hidden" name="action" value="create">
  <h2 class="sec-title">Applicant</h2>
  <div class="row g-2">
    <?php if (Party::ready() && !$agentOnly): ?>
      <?= select_field('party_id', 'Person or organization already on file (a cooperative member keeps the same record)', Party::options(pint('party_id')), fv('party_id'), ['class' => 'col-md-8', 'blank' => 'A new person (from the details below)']) ?>
    <?php endif; ?>
    <?= select_field('classification', 'Classification', IspAccounts::CLASSIFICATIONS, fv('classification', null, 'residential'), ['class' => 'col-md-4']) ?>
    <?= input_field('last_name', 'Last name', fv('last_name'), ['class' => 'col-md-4']) ?>
    <?= input_field('first_name', 'First name', fv('first_name'), ['class' => 'col-md-4']) ?>
    <?= input_field('middle_name', 'Middle name', fv('middle_name'), ['class' => 'col-md-4']) ?>
    <?= input_field('business_name', 'Registered business / organization name (non-residential)', fv('business_name'), ['class' => 'col-md-8']) ?>
    <?= input_field('mobile', 'Mobile', fv('mobile'), ['class' => 'col-md-4', 'extra' => 'placeholder="09XX XXX XXXX" inputmode="tel"']) ?>
    <?= input_field('email', 'E-mail', fv('email'), ['class' => 'col-md-4', 'type' => 'email']) ?>
    <?= select_field('id_type', 'ID presented', array_combine(IspApplications::ID_TYPES, IspApplications::ID_TYPES), fv('id_type'), ['class' => 'col-md-4', 'blank' => '']) ?>
    <?= input_field('id_number', 'ID number', fv('id_number'), ['class' => 'col-md-4']) ?>
    <?= input_field('billing_address', 'Billing address (if not the installation address)', fv('billing_address'), ['class' => 'col-12']) ?>
  </div>
  <h2 class="sec-title mt-4">Installation and plan</h2>
  <div class="row g-2">
    <?= input_field('install_address', 'Installation address', fv('install_address'), ['class' => 'col-md-8', 'required' => true, 'extra' => 'placeholder="House no., street, purok / sitio, barangay, town"']) ?>
    <?= select_field('area_id', 'Service area (barangay)', $areaOpts, fv('area_id'), ['class' => 'col-md-4', 'blank' => '', 'required' => true, 'extra' => 'id="appArea"']) ?>
    <?= input_field('landmark', 'Landmark', fv('landmark'), ['class' => 'col-md-6']) ?>
    <?= input_field('latitude', 'Latitude (optional)', fv('latitude'), ['class' => 'col-md-3', 'extra' => 'inputmode="decimal"']) ?>
    <?= input_field('longitude', 'Longitude (optional)', fv('longitude'), ['class' => 'col-md-3', 'extra' => 'inputmode="decimal"']) ?>
    <div class="col-md-8"><label class="form-label">Plan</label>
      <select class="form-select form-select-sm" name="plan_id" id="appPlan" required><option value=""></option>
        <?php foreach ($plans as $pl): if (!IspPlans::offered($pl, today())) { continue; } ?>
          <option value="<?= (int)$pl['id'] ?>" data-areas="<?= h(implode(',', $planAreas[(int)$pl['id']] ?? [])) ?>"<?= (string)fv('plan_id') === (string)$pl['id'] ? ' selected' : '' ?>>
            <?= h($pl['name'] . ' (' . IspPlans::speed($pl) . ') ' . money($pl['monthly_fee']) . '/mo' . ($pl['member_monthly_fee'] !== null ? ', members ' . money($pl['member_monthly_fee']) : '') . ', installation ' . money($pl['installation_fee'])) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-text" id="appPlanNote"></div></div>
    <?= input_field('preferred_date', 'Preferred installation date', fv('preferred_date'), ['class' => 'col-md-4', 'type' => 'date']) ?>
    <?php if (!$agentOnly): ?>
      <?= select_field('channel', 'Received through', array_diff_key(IspApplications::CHANNELS, ['online' => 1]), fv('channel', null, 'walkin'), ['class' => 'col-md-4']) ?>
      <?= select_field('agent_id', 'Sales agent (referral)', $agents, fv('agent_id'), ['class' => 'col-md-4', 'blank' => 'None']) ?>
      <?php if (count($branches) > 1): ?><?= select_field('branch_id', 'Branch', $branches, fv('branch_id', null, IspSetup::userBranch()), ['class' => 'col-md-4', 'blank' => 'The branch of the area']) ?><?php endif; ?>
    <?php endif; ?>
    <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control form-control-sm" name="notes" rows="2"><?= h(fv('notes')) ?></textarea></div>
  </div>
  <div class="alert alert-light border mt-3 mb-2 small"><strong>Data privacy notice.</strong> <?= h(IspSetup::get('isp_privacy_notice')) ?></div>
  <?= check_field('privacy_consent', 'The applicant read the data privacy notice and agrees to it', fv('privacy_consent'), 'col-12 mb-3') ?>
  <?= save_buttons('Save application', url($pk)) ?>
</div></form>
<script>
(function () {
  var parents = <?= json_encode(array_map('intval', array_filter($areaParents)), JSON_FORCE_OBJECT) ?>;
  var area = document.getElementById('appArea'), plan = document.getElementById('appPlan'), note = document.getElementById('appPlanNote');
  function chain(a) { var out = [], g = 0; while (a && g++ < 20) { out.push(String(a)); a = parents[a]; } return out; }
  function apply() {
    var c = chain(area.value), hidden = 0;
    Array.prototype.forEach.call(plan.options, function (o) {
      if (!o.value) return;
      var limited = o.getAttribute('data-areas');
      var ok = !limited || !area.value || limited.split(',').some(function (x) { return c.indexOf(x) >= 0; });
      o.hidden = !ok; o.disabled = !ok; if (!ok) hidden++;
      if (!ok && o.selected) plan.value = '';
    });
    note.textContent = hidden ? hidden + ' plan(s) are not offered in this area.' : '';
  }
  area.addEventListener('change', apply); apply();
})();
</script>
    <?php
    layout_end();
    return;
}

// ================================================================ ONE APPLICATION
if ($app) {
    $plan = IspPlans::get((int)$app['plan_id']);
    $name = IspApplications::name($app);
    $log = DB::all('SELECT l.*, u.full_name FROM isp_application_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.application_id = :a ORDER BY l.id', ['a' => $id]);
    $port = $app['port_id'] ? IspInfra::portLabel((int)$app['port_id']) : '';
    $wo = $app['work_order_id'] ? IspWorkOrders::get((int)$app['work_order_id']) : null;
    $acct = $app['account_id'] ? IspAccounts::get((int)$app['account_id']) : null;
    $area = $app['area_id'] ? DB::row('SELECT a.*, p.name AS parent FROM isp_areas a LEFT JOIN isp_areas p ON p.id = a.parent_id WHERE a.id = :i', ['i' => $app['area_id']]) : null;
    // the contract as the applicant will sign it (the account number is given at approval)
    $tpl = DB::row('SELECT * FROM isp_contract_templates WHERE is_active = 1 ORDER BY is_default DESC, id LIMIT 1');
    $pseudo = ['plan_id' => $app['plan_id'], 'account_name' => $name, 'install_address' => $app['install_address'], 'account_no' => $acct['account_no'] ?? '(given at approval)',
        'bill_timing' => IspSetup::get('isp_bill_timing'), 'due_days' => IspSetup::int('isp_due_days'), 'security_deposit' => $app['quote_deposit'] ?? $plan['equipment_deposit'],
        'member_price' => $app['party_id'] && Party::isActiveMember((int)$app['party_id']) && $plan['member_monthly_fee'] !== null ? 1 : 0];
    $contractText = $tpl ? IspContracts::render((string)$tpl['body'], $pseudo, today()) : '';

    if ($action === 'print') {
        Access::require($pk, 'print');
        layout_start('Application ' . $app['application_no'], ['bare' => true, 'no_title' => true]);
        ?>
<div class="no-print mb-3 d-flex gap-2"><button class="btn btn-sm btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
  <?= btn_link(url($pk, ['id' => $id]), 'Back', 'btn-outline-secondary', 'arrow-left') ?></div>
<div class="isp-doc">
  <?= IspUi::letterhead('APPLICATION FOR SERVICE', '<div>No. ' . h($app['application_no']) . '</div><div>Date ' . h(fdate($app['created_at'])) . '</div>') ?>
  <h3 class="h6 mt-3">Applicant</h3>
  <table class="table table-sm table-bordered isp-table"><tbody>
    <tr><th style="width:28%">Name</th><td><?= h($name) ?></td><th style="width:18%">Classification</th><td><?= h(IspAccounts::CLASSIFICATIONS[$app['classification']] ?? $app['classification']) ?></td></tr>
    <tr><th>Mobile</th><td><?= h((string)$app['mobile']) ?></td><th>E-mail</th><td><?= h((string)$app['email']) ?></td></tr>
    <tr><th>Installation address</th><td colspan="3"><?= h($app['install_address']) ?><?= $app['landmark'] ? ' (' . h($app['landmark']) . ')' : '' ?><?= $area ? ', ' . h($area['name'] . ($area['parent'] ? ', ' . $area['parent'] : '')) : '' ?></td></tr>
    <tr><th>Billing address</th><td colspan="3"><?= h((string)($app['billing_address'] ?: $app['install_address'])) ?></td></tr>
    <tr><th>ID presented</th><td><?= h(trim($app['id_type'] . ' ' . $app['id_number'])) ?></td><th>Channel</th><td><?= h(IspApplications::CHANNELS[$app['channel']] ?? $app['channel']) ?><?= $app['agent_name'] ? ', agent ' . h($app['agent_name']) : '' ?></td></tr>
  </tbody></table>
  <h3 class="h6 mt-3">Plan</h3>
  <p><?= h($plan['name']) ?>, <?= h(IspPlans::speed($plan)) ?>, <?= money(IspPlans::priceFor($plan, $pseudo)) ?> a month<?= IspSetup::vatRegistered() && $plan['vat_mode'] === 'inclusive' ? ' (VAT inclusive)' : '' ?>,
    <?= (int)$plan['contract_months'] ? (int)$plan['contract_months'] . '-month contract' : 'no fixed term' ?><?= (int)$plan['lock_in_months'] ? ', lock-in ' . (int)$plan['lock_in_months'] . ' months' : '' ?>.</p>
  <h3 class="h6 mt-3">Installation quotation</h3>
  <table class="table table-sm table-bordered isp-table"><tbody>
    <tr><td>Installation / activation fee</td><td class="num"><?= money($app['quote_installation'] ?? $plan['installation_fee']) ?></td></tr>
    <tr><td>Materials<?= $app['est_cable_m'] !== null ? ' (estimated ' . h(qty($app['est_cable_m'])) . ' m drop cable)' : '' ?></td><td class="num"><?= money($app['quote_materials'] ?? 0) ?></td></tr>
    <tr><td>Refundable security deposit</td><td class="num"><?= money($app['quote_deposit'] ?? $plan['equipment_deposit']) ?></td></tr>
    <tr><th>Total due on activation (with the first month, billed on the first invoice)</th><th class="num"><?= money($app['quote_total'] ?? ((float)$plan['installation_fee'] + (float)$plan['equipment_deposit'])) ?></th></tr>
  </tbody></table>
  <?php if ($app['survey_date']): ?><p class="small">Site survey <?= h(fdate($app['survey_date'])) ?>: <?= (int)$app['survey_feasible'] ? 'feasible' : 'not feasible' ?><?= $port ? ', NAP ' . h($port) : '' ?>.<?= $app['survey_notes'] ? ' ' . h($app['survey_notes']) : '' ?></p><?php endif; ?>
  <p class="small mt-3"><?= h(IspSetup::get('isp_privacy_notice')) ?> Consent given: <?= (int)$app['privacy_consent'] ? 'yes' : 'no' ?>.</p>
  <?php if ($contractText !== ''): ?>
    <div class="isp-doc-break"></div>
    <h3 class="h6 mt-4">Terms of service</h3>
    <div class="small" style="white-space:pre-wrap"><?= h($contractText) ?></div>
  <?php endif; ?>
  <div class="row mt-5 small">
    <div class="col-6"><div class="border-top pt-1">Applicant's signature over printed name<?= $app['contract_accepted_name'] ? ': ' . h($app['contract_accepted_name']) : '' ?></div></div>
    <div class="col-6"><div class="border-top pt-1">Received and evaluated by</div></div>
  </div>
</div>
        <?php
        layout_end();
        return;
    }

    $steps = ['new', 'for_verification', 'site_survey', 'approved', 'for_installation', 'installed', 'for_activation', 'active'];
    $cur = array_search($app['status'], $steps, true);
    $files = DB::all("SELECT * FROM isp_files WHERE entity_type = 'application' AND entity_id = :a ORDER BY id DESC", ['a' => $id]);
    $canEdit = Access::can($pk, 'edit');
    $canApprove = Access::can($pk, 'approve');
    $head = (Access::can($pk, 'print') ? btn_link(url($pk, ['id' => $id, 'a' => 'print']), 'Print form and quotation', 'btn-outline-secondary', 'printer') . ' ' : '')
        . btn_link(url($pk), 'Applications', 'btn-outline-secondary', 'arrow-left');
    layout_start('Application ' . $app['application_no'], ['actions' => $head]);
    ?>
<div class="card isp-card"><div class="card-body">
  <div class="isp-head">
    <div>
      <h2 class="isp-head-title"><?= h($name) ?></h2>
      <div class="isp-head-sub"><?= h($plan['name']) ?> (<?= h(IspPlans::speed($plan)) ?>), <?= h($app['install_address']) ?><?= $area ? ', ' . h($area['name']) : '' ?>.
        Received <?= h(fdate($app['created_at'])) ?> by <?= h(strtolower(IspApplications::CHANNELS[$app['channel']] ?? $app['channel'])) ?><?= $app['agent_name'] ? ', agent ' . h($app['agent_name']) : '' ?>.</div>
    </div>
    <div class="isp-head-actions"><?= IspUi::status($app['status'], IspApplications::STATUSES) ?></div>
  </div>
  <?php if ($cur !== false): ?>
  <ol class="isp-steps mt-3 mb-0">
    <?php foreach ($steps as $i => $s): ?>
      <li class="<?= $i < $cur ? 'is-done' : ($i === $cur ? 'is-current' : '') ?>"><?= h(IspApplications::STATUSES[$s]) ?></li>
    <?php endforeach; ?>
  </ol>
  <?php else: ?>
    <div class="alert alert-secondary mt-3 mb-0 py-2"><?= h(IspApplications::STATUSES[$app['status']]) ?>: <?= h((string)$app['rejected_reason']) ?></div>
  <?php endif; ?>
</div></div>

<div class="row g-3 mt-0">
  <div class="col-lg-7">
    <?php // ---------------- the step to do now
    if (in_array($app['status'], ['new', 'for_verification'], true) && $canEdit): ?>
    <div class="card isp-card"><div class="card-body">
      <h2 class="sec-title">Identity verification</h2>
      <p class="small text-muted">Check the ID presented against the applicant (and a business permit or SEC / DTI registration for businesses). The application then goes to the site survey.</p>
      <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="verify">
        <?= select_field('id_type', 'ID presented', array_combine(IspApplications::ID_TYPES, IspApplications::ID_TYPES), fv('id_type', $app), ['class' => 'col-md-6', 'blank' => '', 'required' => true]) ?>
        <?= input_field('id_number', 'ID number', fv('id_number', $app), ['class' => 'col-md-6', 'required' => true]) ?>
        <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-person-check"></i> Identity verified</button></div>
      </form>
      <?php if ($app['status'] === 'new'): ?>
      <div class="mt-2"><?= IspUi::actionForm('start', 'Mark as for verification (documents still to come)', [], 'btn-outline-secondary', 'hourglass') ?></div>
      <?php endif; ?>
    </div></div>
    <?php endif; ?>

    <?php if ($app['status'] === 'site_survey' && $canEdit):
        $ports = IspInfra::freePorts($app['area_id'] ? (int)$app['area_id'] : null, $app['port_id'] ? (int)$app['port_id'] : null);
        $ready = (int)$app['coverage_ok'] === 1 && (int)$app['survey_feasible'] === 1 && $app['quote_total'] !== null && $app['contract_accepted_at'] && ($plan['technology'] !== 'fiber' || $app['port_id']); ?>
    <div class="card isp-card"><div class="card-body">
      <h2 class="sec-title">Site survey and quotation</h2>
      <?php if ($area && !(int)$area['is_serviceable']): ?><div class="alert alert-warning py-2 small">The area <?= h($area['name']) ?> is marked as outside the coverage.</div><?php endif; ?>
      <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="survey">
        <?= check_field('coverage_ok', 'Within the service coverage', fv('coverage_ok', $app), 'col-md-6') ?>
        <?= check_field('survey_feasible', 'Installation is feasible', fv('survey_feasible', $app), 'col-md-6') ?>
        <?= input_field('survey_date', 'Survey date', fv('survey_date', $app, today()), ['class' => 'col-md-4', 'type' => 'date']) ?>
        <?= select_field('port_id', 'NAP port to reserve' . ($plan['technology'] === 'fiber' ? '' : ' (optional)'), $ports, fv('port_id', $app), ['class' => 'col-md-8', 'blank' => $ports ? '' : 'No free port: add capacity in Infrastructure']) ?>
        <?= input_field('est_cable_m', 'Estimated drop cable (m)', fv('est_cable_m', $app), ['class' => 'col-md-4', 'type' => 'number', 'step' => '1']) ?>
        <?= input_field('latitude', 'Latitude', fv('latitude', $app), ['class' => 'col-md-4']) ?>
        <?= input_field('longitude', 'Longitude', fv('longitude', $app), ['class' => 'col-md-4']) ?>
        <?= input_field('quote_installation', 'Installation fee', fv('quote_installation', $app, $plan['installation_fee']), ['class' => 'col-md-4', 'type' => 'number']) ?>
        <?= input_field('quote_materials', 'Materials', fv('quote_materials', $app, '0'), ['class' => 'col-md-4', 'type' => 'number']) ?>
        <?= input_field('quote_deposit', 'Security deposit', fv('quote_deposit', $app, $plan['equipment_deposit']), ['class' => 'col-md-4', 'type' => 'number']) ?>
        <div class="col-12"><label class="form-label">Survey notes</label><textarea class="form-control form-control-sm" name="survey_notes" rows="2"><?= h(fv('survey_notes', $app)) ?></textarea></div>
        <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-clipboard-check"></i> Save survey and quotation</button>
          <?php if ($app['quote_total'] !== null): ?><span class="small text-muted ms-2">Quotation total <?= money($app['quote_total']) ?>.</span><?php endif; ?></div>
      </form>
    </div></div>

    <div class="card isp-card mt-3"><div class="card-body">
      <h2 class="sec-title">Contract acceptance</h2>
      <?php if ($app['contract_accepted_at']): ?>
        <p class="mb-2">Accepted by <strong><?= h($app['contract_accepted_name']) ?></strong> on <?= h(fdatetime($app['contract_accepted_at'])) ?>.</p>
      <?php else: ?>
        <?php if ($contractText !== ''): ?><details class="mb-2"><summary class="small">Read the terms</summary><div class="small border rounded p-2 mt-1" style="white-space:pre-wrap;max-height:320px;overflow:auto"><?= h($contractText) ?></div></details><?php endif; ?>
        <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="accept">
          <?= input_field('accepted_name', 'Accepted by (full name)', fv('accepted_name', null, $name), ['class' => 'col-md-7', 'required' => true]) ?>
          <?= check_field('agree', 'The applicant read and accepted the terms', fv('agree'), 'col-md-5') ?>
          <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-pen"></i> Record acceptance</button></div>
        </form>
      <?php endif; ?>
    </div></div>

    <?php if ($canApprove): ?>
    <div class="card isp-card mt-3"><div class="card-body">
      <h2 class="sec-title">Approval</h2>
      <?php if (!$ready): ?>
        <p class="small text-muted mb-2">Approval needs: <?= h(implode(', ', array_filter([(int)$app['coverage_ok'] !== 1 ? 'the coverage' : '', (int)$app['survey_feasible'] !== 1 ? 'a feasible survey' : '',
            $plan['technology'] === 'fiber' && !$app['port_id'] ? 'a reserved NAP port' : '', $app['quote_total'] === null ? 'the quotation' : '', !$app['contract_accepted_at'] ? 'the contract acceptance' : '']))) ?>.</p>
      <?php endif; ?>
      <form method="post" class="row g-2" data-confirm="Approve the application? The subscriber, the account and the installation work order are created."><?= csrf_field() ?><input type="hidden" name="action" value="approve">
        <?= input_field('reason', 'Remarks', '', ['class' => 'col-md-8']) ?>
        <div class="col-md-4 d-flex align-items-end"><button class="btn btn-sm btn-success"<?= $ready ? '' : ' disabled' ?>><i class="bi bi-check2-circle"></i> Approve</button></div>
      </form>
    </div></div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (in_array($app['status'], ['approved', 'for_installation', 'installed', 'for_activation', 'active'], true)): ?>
    <div class="card isp-card<?= $app['status'] === 'site_survey' ? ' mt-3' : '' ?>"><div class="card-body">
      <h2 class="sec-title">Installation and activation</h2>
      <?= IspUi::dl([
          'Account' => $acct ? IspUi::acct($acct['id'], $acct['account_no'], null) . ' ' . IspUi::status($acct['service_status'], IspAccounts::SERVICE_STATUSES) : null,
          'Work order' => $wo ? safe_link('ispfield.workorders', ['id' => $wo['id']], $wo['wo_no']) . ' ' . IspUi::status($wo['status'], IspWorkOrders::STATUSES)
              . ($wo['scheduled_date'] ? ' <span class="small text-muted">' . h(fdate($wo['scheduled_date']) . ' ' . $wo['scheduled_slot']) . '</span>' : '') : null,
          'Technician' => $wo && $wo['technician_id'] ? h((string)DB::value('SELECT name FROM isp_technicians WHERE id = :t', ['t' => $wo['technician_id']])) : null,
          'NAP port' => $port !== '' ? h($port) : null,
          'Approved' => $app['approved_at'] ? h(fdatetime($app['approved_at'])) . ' ' . h((string)DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => (int)$app['approved_by']])) : null,
      ]) ?>
      <?php if (in_array($app['status'], ['approved', 'for_installation'], true)): ?>
        <p class="small text-muted mb-0">The technician completes the installation work order (materials, ONU serial, signal reading, the subscriber's acceptance); the application then waits for activation.
          <?= $wo && Access::page('ispfield.workorders') ? '<a href="' . h(url('ispfield.workorders', ['id' => $wo['id']])) . '">Schedule or open the work order</a>.' : '' ?></p>
      <?php elseif ($app['status'] === 'for_activation' && $canApprove && $acct && $acct['service_status'] === 'pending'): ?>
        <form method="post" class="row g-2 mt-1" data-confirm="Activate the service? The billing schedule starts, the first invoice is issued and the network enables the subscriber."><?= csrf_field() ?>
          <input type="hidden" name="action" value="activate">
          <?= input_field('activation_date', 'Activation date', today(), ['class' => 'col-md-5', 'type' => 'date', 'extra' => 'max="' . h(today()) . '"']) ?>
          <div class="col-md-7 d-flex align-items-end"><button class="btn btn-sm btn-success"><i class="bi bi-power"></i> Activate the service</button></div>
        </form>
      <?php endif; ?>
    </div></div>
    <?php endif; ?>

    <?php // reject / cancel
    $canReject = $canApprove && IspApplications::canMove($app['status'], 'rejected');
    $canCancel = $canEdit && IspApplications::canMove($app['status'], 'cancelled');
    if ($canReject || $canCancel): ?>
    <div class="card isp-card mt-3"><div class="card-body">
      <h2 class="sec-title">Reject or cancel</h2>
      <form method="post" class="row g-2"><?= csrf_field() ?>
        <?= input_field('reason', 'Reason (kept with the application)', '', ['class' => 'col-md-8', 'required' => true, 'extra' => 'placeholder="e.g. outside the coverage, no free port, withdrawn by the applicant"']) ?>
        <div class="col-md-4 d-flex align-items-end gap-2">
          <?php if ($canReject): ?><button class="btn btn-sm btn-outline-danger" name="action" value="reject" data-confirm="Reject the application?">Reject</button><?php endif; ?>
          <?php if ($canCancel): ?><button class="btn btn-sm btn-outline-secondary" name="action" value="cancel" data-confirm="Cancel the application?">Cancel</button><?php endif; ?>
        </div>
      </form>
    </div></div>
    <?php endif; ?>
  </div>

  <div class="col-lg-5">
    <div class="card isp-card"><div class="card-body">
      <h2 class="sec-title">Details</h2>
      <?= IspUi::dl([
          'Classification' => h(IspAccounts::CLASSIFICATIONS[$app['classification']] ?? $app['classification']),
          'On file as' => $app['party_id'] ? (Access::page('party.center') ? '<a href="' . h(url('party.center', ['id' => $app['party_id']])) . '">Party record</a>' : 'Existing party')
              . (Party::isActiveMember((int)$app['party_id']) ? ', cooperative member' : '') : 'New person',
          'Mobile' => IspUi::sensitive($app['mobile']),
          'E-mail' => IspUi::sensitive($app['email']),
          'ID' => $app['id_type'] ? h($app['id_type']) . ' ' . IspUi::sensitive($app['id_number']) . ((int)$app['id_verified'] ? ', verified' : ', not verified') : null,
          'Billing address' => $app['billing_address'] ? IspUi::sensitive($app['billing_address']) : null,
          'Landmark' => $app['landmark'] ? h($app['landmark']) : null,
          'Coordinates' => $app['latitude'] !== null ? h((float)$app['latitude'] . ', ' . (float)$app['longitude']) : null,
          'Coverage' => $app['coverage_ok'] === null ? 'Not checked' : ((int)$app['coverage_ok'] ? 'Covered' : 'Not covered'),
          'Survey' => $app['survey_date'] ? h(fdate($app['survey_date'])) . ', ' . ((int)$app['survey_feasible'] ? 'feasible' : 'not feasible') . ($app['survey_notes'] ? '<div class="small text-muted">' . h($app['survey_notes']) . '</div>' : '') : null,
          'NAP port' => $port !== '' ? h($port) : null,
          'Quotation' => $app['quote_total'] !== null ? money($app['quote_total']) . ' <span class="small text-muted">(installation ' . money($app['quote_installation']) . ', materials ' . money($app['quote_materials']) . ', deposit ' . money($app['quote_deposit']) . ')</span>' : null,
          'Preferred date' => $app['preferred_date'] ? h(fdate($app['preferred_date'])) : null,
          'Data privacy consent' => (int)$app['privacy_consent'] ? 'Given' : 'Not given',
          'Notes' => $app['notes'] ? nl2br(h($app['notes'])) : null,
      ]) ?>
    </div></div>

    <div class="card isp-card mt-3"><div class="card-body">
      <h2 class="sec-title">Documents</h2>
      <?php if (!$files): ?><p class="small text-muted">No document attached.</p><?php else: ?>
      <ul class="list-unstyled mb-2">
        <?php foreach ($files as $f): ?><li><a href="<?= h(url($pk, ['id' => $id, 'a' => 'file', 'fid' => $f['id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-paperclip"></i> <?= h((string)$f['label']) ?></a>
          <span class="small text-muted"><?= h((string)$f['original_name']) ?>, <?= h(fdate($f['created_at'])) ?></span></li><?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <?php if ($canEdit && !in_array($app['status'], ['rejected', 'cancelled'], true)): ?>
      <form method="post" enctype="multipart/form-data" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="upload">
        <?= input_field('label', 'Document', '', ['class' => 'col-md-5', 'extra' => 'placeholder="e.g. Valid ID"']) ?>
        <div class="col-md-7"><label class="form-label">File (JPG, PNG, WEBP, PDF up to 5 MB)</label><input class="form-control form-control-sm" type="file" name="file" accept=".jpg,.jpeg,.png,.webp,.pdf" required></div>
        <div class="col-12"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-upload"></i> Attach</button></div>
      </form>
      <?php endif; ?>
    </div></div>

    <div class="card isp-card mt-3"><div class="card-body">
      <h2 class="sec-title">Status history</h2>
      <ul class="isp-timeline list-unstyled mb-0">
        <?php foreach ($log as $l): ?>
          <li class="isp-timeline-item"><div class="small text-muted"><?= h(fdatetime($l['created_at'])) ?><?= $l['full_name'] ? ', ' . h($l['full_name']) : '' ?></div>
            <div><?= $l['from_status'] !== $l['to_status'] ? '<strong>' . h(IspApplications::STATUSES[$l['to_status']] ?? $l['to_status']) . '</strong> ' : '' ?><?= h((string)$l['remarks']) ?></div></li>
        <?php endforeach; ?>
      </ul>
    </div></div>
  </div>
</div>
    <?php
    layout_end();
    return;
}
if ($id) {
    layout_start('Applications & Activation');
    echo IspUi::empty('The application was not found.', 'search');
    layout_end();
    return;
}

// ================================================================ THE LIST
$tabs = ['open' => 'In progress', 'new' => 'New', 'for_verification' => 'For verification', 'site_survey' => 'Site survey', 'for_installation' => 'For installation',
    'for_activation' => 'For activation', 'active' => 'Active', 'closed' => 'Rejected / cancelled', 'all' => 'All'];
$tab = isset($tabs[(string)g('tab')]) ? (string)g('tab') : 'open';
$q = trim((string)g('q'));
$channel = (string)g('channel');
$areaId = gint('area_id');
$agentId = gint('agent_id');

$params = [];
$base = ' FROM isp_applications x LEFT JOIN isp_plans p ON p.id = x.plan_id LEFT JOIN isp_areas ar ON ar.id = x.area_id LEFT JOIN isp_agents ag ON ag.id = x.agent_id WHERE 1 = 1'
    . IspSetup::branchSql('x.branch_id', $params);
if ($agentOnly) {
    $base .= ' AND x.agent_id = :mine';
    $params['mine'] = (int)$myAgent['id'];
}
$counts = DB::pairs('SELECT x.status, COUNT(*)' . $base . ' GROUP BY x.status', $params);
$where = $base;
$open = ['new', 'for_verification', 'site_survey', 'approved', 'for_installation', 'installed', 'for_activation'];
if ($tab === 'open') {
    [$in, $inP] = DB::in('os', $open);
    $where .= " AND x.status IN $in";
    $params += $inP;
} elseif ($tab === 'closed') {
    $where .= " AND x.status IN ('rejected', 'cancelled')";
} elseif ($tab === 'for_installation') {
    $where .= " AND x.status IN ('approved', 'for_installation', 'installed')";
} elseif ($tab !== 'all') {
    $where .= ' AND x.status = :st';
    $params['st'] = $tab;
}
if ($q !== '') {
    $where .= ' AND (x.application_no LIKE :q1 OR x.last_name LIKE :q2 OR x.first_name LIKE :q3 OR x.business_name LIKE :q4 OR x.mobile LIKE :q5 OR x.install_address LIKE :q6)';
    $params += ['q1' => $q . '%', 'q2' => $q . '%', 'q3' => $q . '%', 'q4' => '%' . $q . '%', 'q5' => '%' . $q . '%', 'q6' => '%' . $q . '%'];
}
if (isset(IspApplications::CHANNELS[$channel])) {
    $where .= ' AND x.channel = :ch';
    $params['ch'] = $channel;
}
if ($areaId) {
    [$in, $inP] = DB::in('ar', IspOutages::areaTree($areaId));
    $where .= " AND x.area_id IN $in";
    $params += $inP;
}
if ($agentId && !$agentOnly) {
    $where .= ' AND x.agent_id = :ag';
    $params['ag'] = $agentId;
}
[$per, $off, $page] = IspUi::paging(50);
$total = (int)DB::value('SELECT COUNT(*)' . $where, $params);
$rows = DB::all('SELECT x.*, p.name AS plan_name, ar.name AS area_name, ag.name AS agent_name' . $where . ' ORDER BY x.id DESC LIMIT ' . $per . ' OFFSET ' . $off, $params);

$label = function (string $k) use ($tabs, $counts, $open): string {
    $n = match ($k) {
        'open' => array_sum(array_map(fn($s) => (int)($counts[$s] ?? 0), $open)),
        'closed' => (int)($counts['rejected'] ?? 0) + (int)($counts['cancelled'] ?? 0),
        'for_installation' => (int)($counts['approved'] ?? 0) + (int)($counts['for_installation'] ?? 0) + (int)($counts['installed'] ?? 0),
        'all' => (int)array_sum($counts),
        default => (int)($counts[$k] ?? 0),
    };
    return h($tabs[$k]) . ($n ? ' (' . $n . ')' : '');
};
$tabLabels = [];
foreach (array_keys($tabs) as $k) {
    $tabLabels[$k] = $label($k);
}
layout_start($agentOnly ? 'My Applications' : 'Applications & Activation', ['actions' => page_link($pk, ['a' => 'new'], 'New application', 'btn-primary', 'person-plus', 'create')]);
echo IspUi::tabs($tabLabels, $tab, $pk, array_filter(['q' => $q, 'channel' => $channel, 'area_id' => $areaId, 'agent_id' => $agentId]));
?>
<?= IspUi::filterStart($pk, ['tab' => $tab]) ?>
  <?= IspUi::fSearch($q, 'Application no., name, mobile, address') ?>
  <?= IspUi::fSelect('channel', 'Channel', IspApplications::CHANNELS, $channel) ?>
  <?= IspUi::fSelect('area_id', 'Area', $areaOpts, $areaId) ?>
  <?php if (!$agentOnly): ?><?= IspUi::fSelect('agent_id', 'Agent', DB::pairs("SELECT id, name FROM isp_agents ORDER BY name"), $agentId) ?><?php endif; ?>
<?= IspUi::filterEnd(url($pk, ['tab' => $tab])) ?>
<div class="card isp-card"><div class="card-body p-0">
  <?php if (!$rows): ?><?= IspUi::empty('No application here.', 'inbox') ?><?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table mb-0">
    <thead><tr><th>Application</th><th>Applicant</th><th>Plan</th><th class="d-none d-md-table-cell">Area</th><th class="d-none d-lg-table-cell">Channel</th><th>Status</th><th class="d-none d-md-table-cell">Waiting</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td class="text-nowrap"><a href="<?= h(url($pk, ['id' => $r['id']])) ?>"><strong><?= h($r['application_no']) ?></strong></a><div class="small text-muted"><?= h(fdate($r['created_at'])) ?></div></td>
        <td><?= h(IspApplications::name($r)) ?><div class="small text-muted"><?= IspUi::sensitive($r['mobile']) ?></div></td>
        <td><?= h((string)$r['plan_name']) ?></td><td class="d-none d-md-table-cell"><?= h((string)$r['area_name']) ?></td>
        <td class="d-none d-lg-table-cell small"><?= h(IspApplications::CHANNELS[$r['channel']] ?? $r['channel']) ?><?= $r['agent_name'] ? '<div class="text-muted">' . h($r['agent_name']) . '</div>' : '' ?></td>
        <td><?= IspUi::status($r['status'], IspApplications::STATUSES) ?><?= in_array($r['status'], ['rejected', 'cancelled'], true) && $r['rejected_reason'] ? '<div class="small text-muted">' . h($r['rejected_reason']) . '</div>' : '' ?></td>
        <td class="d-none d-md-table-cell small"><?= in_array($r['status'], $open, true) ? h(IspUi::ago($r['updated_at'] ?: $r['created_at'])) : '' ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div></div>
<?= IspUi::pager($total, $per, $page) ?>
<?php
layout_end();
