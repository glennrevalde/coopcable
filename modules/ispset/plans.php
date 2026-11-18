<?php
declare(strict_types=1);

/**
 * Internet Plans & Packages: residential, commercial, dedicated, bundles, prepaid and postpaid, with every charge and
 * policy the billing engine applies. A price change applies to the invoices issued after it; the invoices already
 * issued keep the price they were billed at (each invoice line stores its own price).
 */
$pk = 'ispset.plans';
$areas = DB::pairs("SELECT id, CONCAT(name, ' (', area_type, ')') FROM isp_areas WHERE is_active = 1 ORDER BY name");
$incomeAccts = Accts::ofType(['Income', 'Other Income']);

/** The plan columns posted by the form */
function ispset_plan_input(): array
{
    $num = fn(string $k) => r2(to_num(p($k)));
    $nullNum = fn(string $k) => trim((string)p($k)) === '' ? null : r2(to_num(p($k)));
    $f = [
        'code' => strtoupper(trim((string)p('code'))),
        'name' => trim((string)p('name')),
        'description' => mb_substr(trim((string)p('description')), 0, 255) ?: null,
        'category' => isset(IspPlans::CATEGORIES[p('category')]) ? (string)p('category') : 'residential',
        'service_type' => isset(IspPlans::SERVICE_TYPES[p('service_type')]) ? (string)p('service_type') : 'postpaid',
        'technology' => isset(IspPlans::TECHNOLOGIES[p('technology')]) ? (string)p('technology') : 'fiber',
        'includes_catv' => p('includes_catv') === '1' ? 1 : 0,
        'monthly_fee' => $num('monthly_fee'),
        'member_monthly_fee' => $nullNum('member_monthly_fee'),
        'download_mbps' => $num('download_mbps'),
        'upload_mbps' => $num('upload_mbps'),
        'data_cap_gb' => $nullNum('data_cap_gb'),
        'fair_use_policy' => mb_substr(trim((string)p('fair_use_policy')), 0, 255) ?: null,
        'installation_fee' => $num('installation_fee'),
        'router_rental_fee' => $num('router_rental_fee'),
        'static_ip_fee' => $num('static_ip_fee'),
        'equipment_deposit' => $num('equipment_deposit'),
        'contract_months' => max(0, (int)p('contract_months')),
        'lock_in_months' => max(0, (int)p('lock_in_months')),
        'early_termination_fee' => $num('early_termination_fee'),
        'vat_mode' => isset(IspPlans::VAT_MODES[p('vat_mode')]) ? (string)p('vat_mode') : 'inclusive',
        'late_fee_type' => isset(IspPlans::LATE_FEE_TYPES[p('late_fee_type')]) ? (string)p('late_fee_type') : 'none',
        'late_fee_value' => round(to_num(p('late_fee_value')), 4),
        'reconnection_fee' => $num('reconnection_fee'),
        'promo_discount_pct' => round(to_num(p('promo_discount_pct')), 4),
        'promo_months' => max(0, (int)p('promo_months')),
        'available_from' => pdate('available_from'),
        'available_to' => pdate('available_to'),
        'network_profile' => mb_substr(trim((string)p('network_profile')), 0, 60) ?: null,
        'income_account_id' => pint('income_account_id'),
        'status' => isset(IspPlans::STATUSES[p('status')]) ? (string)p('status') : 'active',
        'sort_order' => (int)p('sort_order'),
        'area_ids' => array_map('intval', (array)($_POST['area_ids'] ?? [])),
    ];
    foreach (['monthly_fee', 'installation_fee', 'router_rental_fee', 'static_ip_fee', 'equipment_deposit', 'early_termination_fee', 'reconnection_fee', 'late_fee_value'] as $k) {
        if ($f[$k] < 0) {
            fail('Amounts cannot be negative (' . str_replace('_', ' ', $k) . ').');
        }
    }
    if ($f['member_monthly_fee'] !== null && $f['member_monthly_fee'] < 0) {
        fail('The member price cannot be negative.');
    }
    if ($f['promo_discount_pct'] < 0 || $f['promo_discount_pct'] > 100) {
        fail('The promo discount is a percent from 0 to 100.');
    }
    if ($f['late_fee_type'] === 'percent' && $f['late_fee_value'] > 100) {
        fail('A percent late charge is at most 100.');
    }
    if ($f['lock_in_months'] > $f['contract_months'] && $f['contract_months'] > 0) {
        fail('The lock-in period is longer than the contract.');
    }
    if ($f['promo_discount_pct'] > 0 && $f['promo_months'] === 0) {
        fail('Enter for how many billed months the promo discount applies.');
    }
    if (strlen($f['code']) > 20) {
        fail('The plan code is at most 20 characters.');
    }
    return $f;
}

handle_post(function () use ($pk) {
    $action = (string)p('action');
    if ($action === 'save') {
        $id = pint('id');
        Access::require($pk, $id ? 'edit' : 'create');
        if ($id && !IspPlans::get($id)) {
            fail('Plan not found.');
        }
        $id = IspPlans::save(ispset_plan_input(), $id);
        redirect_after_save(url($pk, ['a' => 'new']), url($pk, ['id' => $id]));
    }
    if ($action === 'delete') {
        Access::require($pk, 'delete');
        $id = (int)p('id');
        $pl = IspPlans::get($id) ?? fail('Plan not found.');
        $used = (int)DB::value('SELECT COUNT(*) FROM isp_accounts WHERE plan_id = :p', ['p' => $id]) + (int)DB::value('SELECT COUNT(*) FROM isp_applications WHERE plan_id = :p', ['p' => $id])
            + (int)DB::value('SELECT COUNT(*) FROM isp_invoice_lines WHERE plan_id = :p', ['p' => $id]) + (int)DB::value('SELECT COUNT(*) FROM isp_account_plans WHERE plan_id = :p', ['p' => $id]);
        if ($used) {
            fail('Plan ' . $pl['code'] . ' has subscribers, applications or invoices: set it to Retired or Not offered instead.');
        }
        DB::tx(function () use ($id, $pl) {
            DB::run('DELETE FROM isp_plan_areas WHERE plan_id = :p', ['p' => $id]);
            DB::run('DELETE FROM isp_plans WHERE id = :p', ['p' => $id]);
            Audit::log('delete', 'ispset', 'isp_plan', $id, (string)$pl['code'], ['before' => $pl]);
        });
        IspPlans::flush();
        redirect(url($pk), 'Plan ' . $pl['code'] . ' deleted.', 'success');
    }
    fail('Unknown action.');
});

// ------------------------------------------------------------------ the form
if (g('a') === 'new' || gint('id')) {
    $id = gint('id');
    $pl = $id ? IspPlans::get($id) : null;
    if ($id && !$pl) {
        Access::denyPage($pk, 'view');
    }
    if (!$pl && g('copy') && ($src = IspPlans::get((int)g('copy')))) {
        $pl = $src;
        $pl['code'] = '';
        $pl['name'] .= ' (copy)';
        $copyAreas = DB::column('SELECT area_id FROM isp_plan_areas WHERE plan_id = :p', ['p' => $src['id']]);
        $id = null;
    }
    $canSave = Access::can($pk, $id ? 'edit' : 'create');
    $planAreas = is_post() ? array_map('intval', (array)($_POST['area_ids'] ?? [])) : ($id ? array_map('intval', DB::column('SELECT area_id FROM isp_plan_areas WHERE plan_id = :p', ['p' => $id])) : ($copyAreas ?? []));
    $actions = btn_link(url($pk), 'Plans', 'btn-outline-secondary', 'arrow-left');
    if ($id && Access::can($pk, 'create')) {
        $actions = btn_link(url($pk, ['a' => 'new', 'copy' => $id]), 'Copy as new plan', 'btn-outline-secondary', 'files') . ' ' . $actions;
    }
    layout_start($id ? $pl['name'] : 'New internet plan', ['actions' => $actions]);
    $v = fn(string $k, $d = '') => fv($k, $pl, $d);
    ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$id ?>">
    <div class="row g-3">
      <div class="col-lg-6">
        <div class="card isp-card"><div class="card-body">
          <h2 class="sec-title">The package</h2>
          <div class="row g-2">
            <?= input_field('code', 'Code', $v('code'), ['class' => 'col-md-4', 'required' => true, 'extra' => 'maxlength="20" style="text-transform:uppercase"']) ?>
            <?= input_field('name', 'Name', $v('name'), ['class' => 'col-md-8', 'required' => true, 'extra' => 'maxlength="120"']) ?>
            <?= input_field('description', 'Description', $v('description'), ['class' => 'col-12', 'extra' => 'maxlength="255"']) ?>
            <?= select_field('category', 'Category', IspPlans::CATEGORIES, $v('category', 'residential'), ['class' => 'col-md-4']) ?>
            <?= select_field('service_type', 'Service', IspPlans::SERVICE_TYPES, $v('service_type', 'postpaid'), ['class' => 'col-md-4']) ?>
            <?= select_field('technology', 'Technology', IspPlans::TECHNOLOGIES, $v('technology', 'fiber'), ['class' => 'col-md-4']) ?>
            <?= input_field('download_mbps', 'Download (Mbps)', $v('download_mbps'), ['type' => 'number', 'class' => 'col-md-4', 'required' => true]) ?>
            <?= input_field('upload_mbps', 'Upload (Mbps)', $v('upload_mbps'), ['type' => 'number', 'class' => 'col-md-4']) ?>
            <?= input_field('data_cap_gb', 'Data cap (GB, empty: unlimited)', $v('data_cap_gb'), ['type' => 'number', 'class' => 'col-md-4']) ?>
            <?= input_field('fair_use_policy', 'Bandwidth limit / fair-use policy', $v('fair_use_policy'), ['class' => 'col-12', 'extra' => 'maxlength="255" placeholder="e.g. unlimited, best effort; speed may be managed above 1 TB a month"']) ?>
            <?= check_field('includes_catv', 'Cable TV + internet bundle (the cable TV share posts to Cable TV revenue)', (string)$v('includes_catv', '0') === '1', 'col-12') ?>
            <?= input_field('network_profile', 'Network profile on the device (PPPoE / RADIUS / queue)', $v('network_profile'), ['class' => 'col-md-6', 'extra' => 'maxlength="60" placeholder="e.g. FIBER-50M"']) ?>
            <?= select_field('status', 'Availability', IspPlans::STATUSES, $v('status', 'active'), ['class' => 'col-md-3']) ?>
            <?= input_field('sort_order', 'List order', $v('sort_order', '0'), ['type' => 'number', 'step' => '1', 'class' => 'col-md-3']) ?>
            <?= input_field('available_from', 'Offered from', $v('available_from'), ['type' => 'date', 'class' => 'col-md-6']) ?>
            <?= input_field('available_to', 'Offered until', $v('available_to'), ['type' => 'date', 'class' => 'col-md-6']) ?>
            <?= multi_check_field('area_ids', 'Eligible service areas', $areas, $planAreas, ['class' => 'col-12', 'all' => 'Every serviceable area', 'noun' => 'areas']) ?>
          </div>
        </div></div>
      </div>
      <div class="col-lg-6">
        <div class="card isp-card"><div class="card-body">
          <h2 class="sec-title">Prices and charges</h2>
          <div class="row g-2">
            <?= input_field('monthly_fee', 'Monthly subscription', $v('monthly_fee'), ['type' => 'number', 'class' => 'col-md-6', 'required' => true]) ?>
            <?= input_field('member_monthly_fee', 'Monthly price for cooperative members (empty: same)', $v('member_monthly_fee'), ['type' => 'number', 'class' => 'col-md-6']) ?>
            <?= select_field('vat_mode', 'Tax', IspPlans::VAT_MODES, $v('vat_mode', 'inclusive'), ['class' => 'col-md-6']) ?>
            <?= select_field('income_account_id', 'Revenue account', $incomeAccts, $v('income_account_id'), ['blank' => '(Internet service revenue)', 'class' => 'col-md-6']) ?>
            <?= input_field('installation_fee', 'Installation charge', $v('installation_fee', '0'), ['type' => 'number', 'class' => 'col-md-4']) ?>
            <?= input_field('router_rental_fee', 'Router rental / month', $v('router_rental_fee', '0'), ['type' => 'number', 'class' => 'col-md-4']) ?>
            <?= input_field('static_ip_fee', 'Static IP / month', $v('static_ip_fee', '0'), ['type' => 'number', 'class' => 'col-md-4']) ?>
            <?= input_field('equipment_deposit', 'Equipment deposit', $v('equipment_deposit', '0'), ['type' => 'number', 'class' => 'col-md-4']) ?>
            <?= input_field('reconnection_fee', 'Reconnection fee', $v('reconnection_fee', '0'), ['type' => 'number', 'class' => 'col-md-4']) ?>
          </div>
          <h2 class="sec-title mt-3">Contract</h2>
          <div class="row g-2">
            <?= input_field('contract_months', 'Contract (months)', $v('contract_months', '12'), ['type' => 'number', 'step' => '1', 'class' => 'col-md-4']) ?>
            <?= input_field('lock_in_months', 'Lock-in (months)', $v('lock_in_months', '0'), ['type' => 'number', 'step' => '1', 'class' => 'col-md-4']) ?>
            <?= input_field('early_termination_fee', 'Early termination fee', $v('early_termination_fee', '0'), ['type' => 'number', 'class' => 'col-md-4']) ?>
          </div>
          <h2 class="sec-title mt-3">Late payment and promotion</h2>
          <div class="row g-2">
            <?= select_field('late_fee_type', 'Late-payment charge', IspPlans::LATE_FEE_TYPES, $v('late_fee_type', 'fixed'), ['class' => 'col-md-6']) ?>
            <?= input_field('late_fee_value', 'Amount or percent', $v('late_fee_value', '0'), ['type' => 'number', 'step' => '0.0001', 'class' => 'col-md-6']) ?>
            <?= input_field('promo_discount_pct', 'Promo discount (%)', $v('promo_discount_pct', '0'), ['type' => 'number', 'step' => '0.0001', 'class' => 'col-md-6']) ?>
            <?= input_field('promo_months', 'For the first billed months', $v('promo_months', '0'), ['type' => 'number', 'step' => '1', 'class' => 'col-md-6']) ?>
          </div>
          <p class="small text-muted mt-3 mb-0">A price change applies from the next invoice. Invoices already issued keep the price they were billed at, and each subscriber's plan history keeps the price of every period.
            Mid-cycle upgrades and downgrades are prorated when ISP Setup &gt; Billing &amp; Disconnection Policies says so.</p>
        </div></div>
      </div>
    </div>
    <?php if ($canSave): ?><div class="mt-3"><?= save_buttons('Save plan', url($pk), !$id) ?></div><?php endif; ?>
    </form>
    <?php if ($id): ?>
      <?php $st = DB::pairs('SELECT service_status, COUNT(*) FROM isp_accounts WHERE plan_id = :p GROUP BY service_status', ['p' => $id]); ?>
      <div class="card isp-card mt-3"><div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
        <div class="small"><strong>Subscribers on this plan:</strong>
          <?= $st ? h(implode(', ', array_map(fn($s, $n) => $n . ' ' . strtolower(IspAccounts::SERVICE_STATUSES[$s] ?? $s), array_keys($st), $st))) : 'none' ?></div>
        <?php if (!$st && Access::can($pk, 'delete')): ?><?= IspUi::actionForm('delete', 'Delete plan', ['id' => $id], 'btn-outline-danger', 'trash', 'Delete plan ' . $pl['code'] . '? Only an unused plan can be deleted.') ?><?php endif; ?>
      </div></div>
    <?php endif; ?>
    <?php
    layout_end();
    exit;
}

// ------------------------------------------------------------------ the list
$status = isset(IspPlans::STATUSES[g('status')]) ? (string)g('status') : '';
$cat = isset(IspPlans::CATEGORIES[g('category')]) ? (string)g('category') : '';
$q = trim((string)g('q'));
$p = [];
$where = ['1 = 1'];
if ($status !== '') {
    $where[] = 'p.status = :st';
    $p['st'] = $status;
}
if ($cat !== '') {
    $where[] = 'p.category = :ca';
    $p['ca'] = $cat;
}
if ($q !== '') {
    $where[] = '(p.code LIKE :q1 OR p.name LIKE :q2)';
    $p += ['q1' => "$q%", 'q2' => "%$q%"];
}
$rows = DB::all("SELECT p.*, (SELECT COUNT(*) FROM isp_accounts a WHERE a.plan_id = p.id AND a.service_status = 'active') AS active_n,
        (SELECT COUNT(*) FROM isp_accounts a WHERE a.plan_id = p.id AND a.service_status NOT IN ('terminated')) AS total_n,
        (SELECT COUNT(*) FROM isp_plan_areas x WHERE x.plan_id = p.id) AS area_n
    FROM isp_plans p WHERE " . implode(' AND ', $where) . ' ORDER BY p.sort_order, p.monthly_fee, p.name', $p);
layout_start('Internet Plans & Packages', ['actions' => page_link($pk, ['a' => 'new'], 'New plan', 'btn-primary', 'plus-lg', 'create')]);
?>
<?= IspUi::filterStart($pk) ?>
  <?= IspUi::fSearch($q, 'Code or name') ?>
  <?= IspUi::fSelect('category', 'Category', IspPlans::CATEGORIES, $cat) ?>
  <?= IspUi::fSelect('status', 'Availability', IspPlans::STATUSES, $status) ?>
<?= IspUi::filterEnd(url($pk)) ?>
<div class="card isp-card"><div class="card-body">
<?php if (!$rows): ?>
  <?= IspUi::empty('No plan yet. Create the internet packages the subscribers can choose from.', 'speedometer2') ?>
<?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
    <thead><tr><th>Plan</th><th>Category</th><th>Speed</th><th class="num">Monthly</th><th class="num">Member price</th><th class="num">Installation</th><th>Contract</th><th>Tax</th><th>Areas</th><th class="num">Active</th><th class="num">Subscribers</th><th>Availability</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><a href="<?= h(url($pk, ['id' => (int)$r['id']])) ?>"><?= h($r['name']) ?></a><div class="small text-muted"><?= h($r['code']) ?><?= (int)$r['includes_catv'] ? ', with cable TV' : '' ?><?= (float)$r['promo_discount_pct'] > 0 ? ', promo ' . h(qty($r['promo_discount_pct'])) . '% for ' . (int)$r['promo_months'] . ' mo.' : '' ?></div></td>
        <td class="small"><?= h(IspPlans::CATEGORIES[$r['category']] ?? $r['category']) ?><div class="text-muted"><?= h(IspPlans::SERVICE_TYPES[$r['service_type']] ?? '') ?>, <?= h(IspPlans::TECHNOLOGIES[$r['technology']] ?? '') ?></div></td>
        <td class="text-nowrap"><?= h(IspPlans::speed($r)) ?></td><td class="num"><?= h(money($r['monthly_fee'])) ?></td><td class="num"><?= $r['member_monthly_fee'] !== null ? h(money($r['member_monthly_fee'])) : '' ?></td>
        <td class="num"><?= h(money($r['installation_fee'], true)) ?></td><td class="small"><?= (int)$r['contract_months'] ?> mo.<?= (int)$r['lock_in_months'] ? ', lock-in ' . (int)$r['lock_in_months'] : '' ?></td>
        <td class="small"><?= h(IspPlans::VAT_MODES[$r['vat_mode']] ?? $r['vat_mode']) ?></td><td class="small"><?= (int)$r['area_n'] ? (int)$r['area_n'] . ' area' . ((int)$r['area_n'] === 1 ? '' : 's') : 'Every area' ?></td>
        <td class="num"><?= (int)$r['active_n'] ?></td><td class="num"><?= (int)$r['total_n'] ?></td>
        <td><?= IspUi::status($r['status'], IspPlans::STATUSES, $r['status'] === 'active' ? 'ok' : 'idle') ?><?= $r['available_to'] ? '<div class="small text-muted">until ' . h(fdate($r['available_to'])) . '</div>' : '' ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
<?php endif; ?>
</div></div>
<?php
layout_end();
