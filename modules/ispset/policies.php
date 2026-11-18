<?php
declare(strict_types=1);

/**
 * Billing & Disconnection Policies: the billing, service-control, support and portal settings, the tax and document
 * settings of the Philippine invoices, and the disconnection rules. Regulatory settings are kept here so a change of
 * the rules does not need a change of the program. Every change is written to the audit trail.
 */
$pk = 'ispset.policies';
const ISPSET_WEEKDAYS = ['1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat', '7' => 'Sun'];

$yn = ['1' => 'Yes', '0' => 'No'];
$sites = DB::pairs("SELECT id, CONCAT(name, ' (', site_type, ')') FROM sites WHERE is_active = 1 ORDER BY name");
$units = BusinessUnits::ready() ? BusinessUnits::options() : [];
// key => [label, type, choices / [min, max], help]
$sections = [
    'billing' => ['Billing', [
        'isp_default_cycle_day' => ['Default billing cycle day', 'int', [1, 28], 'Day of the month new accounts are billed (fixed-cycle billing).'],
        'isp_billing_basis' => ['Billing basis of new accounts', 'select', IspAccounts::BILLING_BASES, ''],
        'isp_bill_timing' => ['Billing timing', 'select', IspAccounts::BILL_TIMINGS, 'In advance bills the coming period; in arrears the period just ended.'],
        'isp_due_days' => ['Days from the invoice date to the due date', 'int', [0, 90], ''],
        'isp_bill_lead_days' => ['Issue advance invoices this many days early', 'int', [0, 20], ''],
        'isp_prorate_first' => ['Prorate the first bill from the activation date', 'bool', $yn, ''],
        'isp_prorate_changes' => ['Prorate mid-cycle upgrades and downgrades', 'bool', $yn, 'A credit for the unused days of the old plan and a charge for the new plan.'],
        'isp_runs_need_approval' => ['Billing runs need approval before posting', 'bool', $yn, 'Draft invoices are reviewed and approved before they are numbered and posted.'],
        'isp_auto_apply_credit' => ['Apply advance payments to new invoices automatically', 'bool', $yn, ''],
        'isp_late_fee_after_days' => ['Days after the due date before the late charge', 'int', [0, 60], 'The plan decides the amount (fixed or percent).'],
        'isp_reminder_days' => ['Due-date reminder, days before the due date', 'int', [0, 30], ''],
        'isp_overdue_reminder_days' => ['Overdue reminder, days after the due date', 'int', [0, 60], ''],
        'isp_account_prefix' => ['Account number prefix', 'text', [0, 6], 'Letters put in front of new account numbers (e.g. a branch letter). Existing numbers do not change.'],
        'isp_stock_site_id' => ['Network stockroom (field materials are issued from it)', 'site', $sites, ''],
        'isp_business_unit_id' => ['Line of business of the ISP postings', 'unit', $units, 'Empty: the Cable & Internet line of business (code CI).'],
    ]],
    'service' => ['Service control', [
        'isp_auto_reconnect' => ['Reconnect automatically once the arrears are paid', 'bool', $yn, 'Only for overdue-payment disconnections; fraud, technical and administrative holds always need a person.'],
        'isp_partial_recon_pct' => ['Share of the qualifying arrears that must be paid to reconnect (%)', 'int', [1, 100], ''],
        'isp_recon_fee_policy' => ['Reconnection fee', 'select', ['next_bill' => 'Charged on the next invoice', 'prepay' => 'Must be paid before the reconnection', 'none' => 'Not charged'], ''],
        'isp_temp_recon_hours' => ['Temporary (promise-to-pay) reconnection lasts (hours)', 'int', [1, 336], ''],
        'isp_disc_retry_minutes' => ['Retry a failed network command after (minutes)', 'int', [1, 1440], ''],
    ]],
    'support' => ['Support & portal', [
        'isp_sla_critical_hours' => ['SLA of a critical ticket (hours)', 'int', [1, 720], ''],
        'isp_sla_high_hours' => ['SLA of a high-priority ticket (hours)', 'int', [1, 720], ''],
        'isp_sla_normal_hours' => ['SLA of a normal ticket (hours)', 'int', [1, 720], ''],
        'isp_sla_low_hours' => ['SLA of a low-priority ticket (hours)', 'int', [1, 720], ''],
        'isp_escalate_after_hours' => ['Next escalation of an overdue ticket after (hours)', 'int', [1, 720], ''],
        'isp_portal_enabled' => ['Customer portal is open', 'bool', $yn, ''],
        'isp_portal_self_register' => ['Subscribers may register their own portal log-in', 'bool', $yn, 'With the account number and the last four digits of the mobile number on file.'],
        'isp_portal_proof_upload' => ['Subscribers may upload a proof of payment', 'bool', $yn, 'The payment stays pending until a collection officer verifies it.'],
        'isp_map_tiles' => ['Map tile server (empty: the plain grid map)', 'text', [0, 255], 'A URL template with {z}/{x}/{y}, e.g. https://tile.openstreetmap.org/{z}/{x}/{y}.png (check the provider\'s usage terms).'],
    ]],
    'tax' => ['Tax & documents', [
        'isp_vat_registered' => ['VAT-registered', 'bool', $yn, 'Non-VAT companies print "Non-VAT Reg." and charge no output VAT.'],
        'isp_vat_rate' => ['VAT rate (%)', 'num', [0, 100], ''],
        'isp_invoice_title' => ['Title of the invoice', 'text', [1, 60], 'As registered with the BIR (e.g. SERVICE INVOICE).'],
        'isp_receipt_title' => ['Title of the payment receipt', 'text', [1, 60], ''],
        'isp_ack_title' => ['Title of the payment acknowledgment', 'text', [1, 80], 'For payment documents that are not the BIR-registered receipt.'],
        'isp_bir_atp' => ['Authority to Print / CAS permit no.', 'text', [0, 80], 'Printed on the invoices. Enter only what the BIR issued to the company.'],
        'isp_bir_series' => ['Approved serial range', 'text', [0, 80], 'e.g. SI-0000001 to SI-0500000.'],
        'isp_invoice_footer' => ['Invoice footer', 'textarea', [0, 600], ''],
        'isp_payment_instructions' => ['Payment instructions (invoices, statements, portal)', 'textarea', [0, 600], ''],
        'isp_privacy_notice' => ['Data privacy notice (applications, portal, contracts)', 'textarea', [0, 1000], ''],
    ]],
];
$tabs = array_map(fn($s) => $s[0], $sections) + ['rules' => 'Disconnection rules'];
$tab = isset($tabs[g('tab')]) ? (string)g('tab') : 'billing';

handle_post(function () use ($pk, $sections) {
    $action = (string)p('action');
    if ($action === 'settings') {
        Access::require($pk, 'edit');
        $sec = (string)p('section');
        if (!isset($sections[$sec])) {
            fail('Unknown section.');
        }
        $before = [];
        $after = [];
        foreach ($sections[$sec][1] as $key => [$label, $type, $opt]) {
            $raw = trim((string)p($key));
            switch ($type) {
                case 'int':
                    if ($raw === '' || !preg_match('/^-?\d+$/', $raw) || (int)$raw < $opt[0] || (int)$raw > $opt[1]) {
                        fail($label . ': enter a whole number from ' . $opt[0] . ' to ' . $opt[1] . '.');
                    }
                    $val = (string)(int)$raw;
                    break;
                case 'num':
                    if ($raw === '' || !is_numeric($raw) || (float)$raw < $opt[0] || (float)$raw > $opt[1]) {
                        fail($label . ': enter a number from ' . $opt[0] . ' to ' . $opt[1] . '.');
                    }
                    $val = (string)round((float)$raw, 4);
                    break;
                case 'bool':
                    $val = $raw === '1' ? '1' : '0';
                    break;
                case 'select':
                case 'site':
                case 'unit':
                    if ($raw !== '' && !isset($opt[$raw])) {
                        fail($label . ': choose one of the choices.');
                    }
                    if ($raw === '' && $type === 'select') {
                        fail($label . ': choose one of the choices.');
                    }
                    $val = $raw;
                    break;
                default:
                    if (mb_strlen($raw) < $opt[0] || mb_strlen($raw) > $opt[1]) {
                        fail($label . ($opt[0] > 0 ? ' is required and' : '') . ' is at most ' . $opt[1] . ' characters.');
                    }
                    $val = $type === 'textarea' ? str_replace("\r\n", "\n", (string)p($key)) : $raw;
                    $val = trim($val);
            }
            if ($key === 'isp_account_prefix' && $val !== '' && !preg_match('/^[A-Z0-9-]{1,6}$/i', $val)) {
                fail('The account number prefix is letters, digits or a dash, up to 6 characters.');
            }
            if ($key === 'isp_map_tiles' && $val !== '' && (!preg_match('#^https?://#i', $val) || !str_contains($val, '{z}') || !str_contains($val, '{x}') || !str_contains($val, '{y}'))) {
                fail('The map tile server is an http(s) URL with {z}, {x} and {y}.');
            }
            $old = IspSetup::get($key);
            if ($old !== $val) {
                $before[$key] = $old;
                $after[$key] = $val;
            }
        }
        if (!$after) {
            redirect(url($pk, ['tab' => $sec]), 'Nothing was changed.', 'info');
        }
        DB::tx(function () use ($after, $before, $sec, $sections) {
            foreach ($after as $k => $v) {
                Settings::set($k, $v);
            }
            Audit::log('edit', 'ispset', 'settings', null, 'ISP policies: ' . $sections[$sec][0], ['before' => $before, 'after' => $after]);
        });
        redirect(url($pk, ['tab' => $sec]), count($after) . ' setting' . (count($after) === 1 ? '' : 's') . ' saved.', 'success');
    }
    if ($action === 'rule') {
        Access::require($pk, 'edit');
        $id = (int)p('id');
        $r = DB::row('SELECT * FROM isp_disc_rules WHERE id = :id', ['id' => $id]) ?? fail('Rule not found.');
        $days = array_values(array_intersect(array_keys(ISPSET_WEEKDAYS), array_map('strval', (array)($_POST['run_days'] ?? []))));
        $f = ['name' => trim((string)p('name')), 'grace_days' => (int)p('grace_days'), 'min_overdue_amount' => r2(to_num(p('min_overdue_amount'))),
            'min_overdue_invoices' => max(1, (int)p('min_overdue_invoices')), 'final_notice_hours' => (int)p('final_notice_hours'), 'require_approval' => p('require_approval') === '1' ? 1 : 0,
            'auto_execute' => p('auto_execute') === '1' ? 1 : 0, 'run_days' => implode(',', $days), 'run_from_hour' => (int)p('run_from_hour'), 'run_to_hour' => (int)p('run_to_hour'),
            'exclude_types' => implode(',', array_values(array_intersect(array_keys(IspBilling::LINE_TYPES), array_map('strval', (array)($_POST['exclude_types'] ?? []))))),
            'protect_disputes' => p('protect_disputes') === '1' ? 1 : 0, 'protect_arrangements' => p('protect_arrangements') === '1' ? 1 : 0,
            'reconnection_fee' => trim((string)p('reconnection_fee')) === '' ? null : r2(to_num(p('reconnection_fee'))), 'is_active' => p('is_active') === '1' ? 1 : 0];
        if ($f['name'] === '') {
            fail('Enter the name of the rule.');
        }
        if ($f['grace_days'] < 0 || $f['grace_days'] > 365 || $f['final_notice_hours'] < 0 || $f['final_notice_hours'] > 720 || $f['min_overdue_amount'] < 0) {
            fail('Check the grace days (0 to 365), the notice hours (0 to 720) and the minimum amount.');
        }
        if (!$days) {
            fail('Tick at least one day the disconnections may run.');
        }
        if ($f['run_from_hour'] < 0 || $f['run_to_hour'] > 23 || $f['run_from_hour'] > $f['run_to_hour']) {
            fail('The hours window runs from 0 to 23, the start before the end.');
        }
        if ($f['reconnection_fee'] !== null && $f['reconnection_fee'] < 0) {
            fail('The reconnection fee cannot be negative.');
        }
        if ($f['auto_execute'] && !$f['require_approval'] && $r['rule_type'] !== 'overdue') {
            fail('A ' . strtolower(IspDisconnect::REASON_TYPES[$r['rule_type']] ?? $r['rule_type']) . ' disconnection is never executed without approval.');
        }
        DB::update('isp_disc_rules', $f, 'id = :id', ['id' => $id]);
        Audit::log('edit', 'ispset', 'isp_disc_rule', $id, (string)$r['code'], ['before' => $r, 'after' => $f]);
        redirect(url($pk, ['tab' => 'rules', 'rule' => $id]), 'Rule ' . $r['code'] . ' saved.', 'success');
    }
    fail('Unknown action.');
});

layout_start('Billing & Disconnection Policies');
echo IspUi::tabs($tabs, $tab, $pk);
$canEdit = Access::can($pk, 'edit');

if ($tab !== 'rules') {
    [$title, $fields] = $sections[$tab];
    ?>
    <div class="card isp-card"><div class="card-body">
      <form method="post" class="row g-3"><?= csrf_field() ?><input type="hidden" name="action" value="settings"><input type="hidden" name="section" value="<?= h($tab) ?>">
      <?php foreach ($fields as $key => [$label, $type, $opt, $help]):
          $val = is_post() ? (string)p($key) : IspSetup::get($key); ?>
        <div class="<?= $type === 'textarea' ? 'col-12' : 'col-md-6' ?>">
          <label class="form-label" for="f_<?= h($key) ?>"><?= h($label) ?></label>
          <?php if ($type === 'bool' || $type === 'select'): ?>
            <select class="form-select form-select-sm" name="<?= h($key) ?>" id="f_<?= h($key) ?>"<?= $canEdit ? '' : ' disabled' ?>><?= options($opt, $val) ?></select>
          <?php elseif ($type === 'site' || $type === 'unit'): ?>
            <select class="form-select form-select-sm" name="<?= h($key) ?>" id="f_<?= h($key) ?>"<?= $canEdit ? '' : ' disabled' ?>><?= options($opt, $val, '(automatic)') ?></select>
          <?php elseif ($type === 'textarea'): ?>
            <textarea class="form-control form-control-sm" name="<?= h($key) ?>" id="f_<?= h($key) ?>" rows="3" maxlength="<?= (int)$opt[1] ?>"<?= $canEdit ? '' : ' readonly' ?>><?= h($val) ?></textarea>
          <?php else: ?>
            <input class="form-control form-control-sm" name="<?= h($key) ?>" id="f_<?= h($key) ?>" value="<?= h($val) ?>"<?= $type === 'int' || $type === 'num' ? ' type="number" step="' . ($type === 'int' ? '1' : '0.01') . '" min="' . h((string)$opt[0]) . '" max="' . h((string)$opt[1]) . '"' : ' maxlength="' . (int)$opt[1] . '"' ?><?= $canEdit ? '' : ' readonly' ?>>
          <?php endif; ?>
          <?php if ($help !== ''): ?><div class="form-text"><?= h($help) ?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($tab === 'tax'): ?>
        <div class="col-12 small text-muted">These settings make the invoices and receipts follow the company's BIR registration. Printing a document does not make it BIR-accredited: the registration and the permits are the company's to secure and keep current.</div>
      <?php endif; ?>
      <?php if ($canEdit): ?><div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> Save <?= h(strtolower($title)) ?> settings</button></div><?php endif; ?>
      </form>
    </div></div>
    <?php
    layout_end();
    exit;
}

// ------------------------------------------------------------------ disconnection rules
$rules = DB::all('SELECT r.*, (SELECT COUNT(*) FROM isp_disc_queue q WHERE q.rule_id = r.id) AS used_n FROM isp_disc_rules r ORDER BY r.id');
$ruleId = gint('rule');
$rule = $ruleId ? DB::row('SELECT * FROM isp_disc_rules WHERE id = :id', ['id' => $ruleId]) : null;
?>
<div class="row g-3">
  <div class="col-lg-<?= $rule ? '5' : '12' ?>">
    <div class="card isp-card"><div class="card-body">
      <h2 class="sec-title">Rules</h2>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
        <thead><tr><th>Rule</th><th class="num">Grace</th><th class="num">Minimum</th><th>Approval</th><th>Runs</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($rules as $r): ?>
          <tr<?= $rule && (int)$rule['id'] === (int)$r['id'] ? ' class="table-active"' : '' ?>><td><a href="<?= h(url($pk, ['tab' => 'rules', 'rule' => (int)$r['id']])) ?>"><?= h($r['name']) ?></a><div class="small text-muted"><?= h($r['code']) ?>, <?= h(IspDisconnect::REASON_TYPES[$r['rule_type']] ?? $r['rule_type']) ?></div></td>
            <td class="num"><?= (int)$r['grace_days'] ?> d</td><td class="num"><?= h(money($r['min_overdue_amount'], true)) ?></td>
            <td class="small"><?= (int)$r['require_approval'] ? 'Approved by a supervisor' : 'No approval' ?><?= (int)$r['auto_execute'] ? ', executed by the scheduler' : ', executed by a person' ?></td>
            <td class="small"><?= h(implode(' ', array_map(fn($d) => ISPSET_WEEKDAYS[$d] ?? '', explode(',', (string)$r['run_days'])))) ?>, <?= (int)$r['run_from_hour'] ?>:00-<?= (int)$r['run_to_hour'] ?>:59</td>
            <td><?= IspUi::status((int)$r['is_active'] ? 'active' : 'inactive', ['active' => 'Active', 'inactive' => 'Inactive']) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <p class="small text-muted mb-0">An overdue account is queued only after the grace days and above the minimum. The final notice goes out the set hours before; every execution rechecks the balance under a lock, so a payment made in the meantime cancels it. Accounts with a dispute or an active arrangement are skipped when the rule protects them.</p>
    </div></div>
  </div>
  <?php if ($rule):
      $days = is_post() ? array_map('strval', (array)($_POST['run_days'] ?? [])) : explode(',', (string)$rule['run_days']);
      $excl = is_post() ? array_map('strval', (array)($_POST['exclude_types'] ?? [])) : array_filter(explode(',', (string)$rule['exclude_types'])); ?>
  <div class="col-lg-7">
    <div class="card isp-card"><div class="card-body">
      <h2 class="sec-title"><?= h($rule['code']) ?>: <?= h(IspDisconnect::REASON_TYPES[$rule['rule_type']] ?? $rule['rule_type']) ?></h2>
      <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="rule"><input type="hidden" name="id" value="<?= (int)$rule['id'] ?>">
        <?= input_field('name', 'Name', fv('name', $rule), ['class' => 'col-12', 'required' => true]) ?>
        <?php if ($rule['rule_type'] === 'overdue'): ?>
          <?= input_field('grace_days', 'Grace days after the due date', fv('grace_days', $rule), ['type' => 'number', 'step' => '1', 'class' => 'col-md-4']) ?>
          <?= input_field('min_overdue_amount', 'Minimum overdue amount', fv('min_overdue_amount', $rule), ['type' => 'number', 'class' => 'col-md-4']) ?>
          <?= input_field('min_overdue_invoices', 'Minimum overdue invoices', fv('min_overdue_invoices', $rule), ['type' => 'number', 'step' => '1', 'class' => 'col-md-4']) ?>
          <?= multi_check_field('exclude_types', 'Invoice lines not counted as arrears', IspBilling::LINE_TYPES, $excl, ['class' => 'col-md-8', 'all' => 'Every line counts', 'noun' => 'kinds']) ?>
        <?php else: ?>
          <input type="hidden" name="grace_days" value="<?= (int)$rule['grace_days'] ?>"><input type="hidden" name="min_overdue_amount" value="<?= h((string)$rule['min_overdue_amount']) ?>"><input type="hidden" name="min_overdue_invoices" value="<?= (int)$rule['min_overdue_invoices'] ?>">
          <?php foreach ($excl as $x): ?><input type="hidden" name="exclude_types[]" value="<?= h($x) ?>"><?php endforeach; ?>
        <?php endif; ?>
        <?= input_field('final_notice_hours', 'Final notice before execution (hours, 0: none)', fv('final_notice_hours', $rule), ['type' => 'number', 'step' => '1', 'class' => 'col-md-4']) ?>
        <?= input_field('reconnection_fee', 'Reconnection fee (empty: the plan\'s)', fv('reconnection_fee', $rule), ['type' => 'number', 'class' => 'col-md-4']) ?>
        <?= check_field('require_approval', 'A supervisor approves the queue', (string)fv('require_approval', $rule) === '1', 'col-md-6') ?>
        <?= check_field('auto_execute', 'The scheduler executes approved items', (string)fv('auto_execute', $rule) === '1', 'col-md-6') ?>
        <?php if ($rule['rule_type'] === 'overdue'): ?>
          <?= check_field('protect_disputes', 'Skip accounts with a billing dispute', (string)fv('protect_disputes', $rule) === '1', 'col-md-6') ?>
          <?= check_field('protect_arrangements', 'Skip accounts with an active arrangement', (string)fv('protect_arrangements', $rule) === '1', 'col-md-6') ?>
        <?php else: ?>
          <input type="hidden" name="protect_disputes" value="<?= (int)$rule['protect_disputes'] ?>"><input type="hidden" name="protect_arrangements" value="<?= (int)$rule['protect_arrangements'] ?>">
        <?php endif; ?>
        <div class="col-md-6"><label class="form-label">Days the executions may run</label><div class="d-flex flex-wrap gap-2">
          <?php foreach (ISPSET_WEEKDAYS as $k => $d): ?><label class="form-check form-check-inline m-0"><input class="form-check-input" type="checkbox" name="run_days[]" value="<?= $k ?>"<?= in_array($k, $days, true) ? ' checked' : '' ?>> <?= $d ?></label><?php endforeach; ?></div></div>
        <?= input_field('run_from_hour', 'From hour (0-23)', fv('run_from_hour', $rule), ['type' => 'number', 'step' => '1', 'class' => 'col-md-3']) ?>
        <?= input_field('run_to_hour', 'To hour (0-23)', fv('run_to_hour', $rule), ['type' => 'number', 'step' => '1', 'class' => 'col-md-3']) ?>
        <?= check_field('is_active', 'Active', (string)fv('is_active', $rule) === '1', 'col-12') ?>
        <?php if ($canEdit): ?><div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> Save rule</button> <?= btn_link(url($pk, ['tab' => 'rules']), 'Close') ?></div><?php endif; ?>
      </form>
      <?php if ((int)DB::value('SELECT COUNT(*) FROM isp_disc_queue WHERE rule_id = :r', ['r' => $rule['id']])): ?>
        <p class="small text-muted mt-2 mb-0">The rule is used by queued and past disconnections; a change applies to the evaluations from now on.</p>
      <?php endif; ?>
    </div></div>
  </div>
  <?php endif; ?>
</div>
<?php
layout_end();
