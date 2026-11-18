<?php
declare(strict_types=1);

/** R01-R25: the required tests of the specification (section 38) */

$T = [];   // accounts shared between tests (R10 disconnects one, R15 reconnects it)

test('R01 New customer creates an application', function () {
    global $ids;
    $id = IspApplications::create(['last_name' => 'Reyes', 'first_name' => 'Maria', 'middle_name' => 'Santos', 'mobile' => '0917 555 0101', 'email' => 'maria.reyes@example.com',
        'install_address' => '12 Mabini St., San Roque, San Isidro', 'plan_id' => $ids['plan.F50'], 'area_id' => $ids['area.SR'], 'privacy_consent' => 1], 'online');
    $a = IspApplications::get($id);
    eq($a['status'], 'new', 'status of a new application');
    check(str_starts_with((string)$a['application_no'], 'APP-'), 'the application is numbered from its own series (' . $a['application_no'] . ')');
    eq($a['channel'], 'online', 'channel');
    eq((int)$a['branch_id'], $ids['branch.HO'], 'the branch comes from the service area');
    eq((int)$a['coverage_ok'], 1, 'San Roque is serviceable');
    eq((int)$a['privacy_consent'], 1, 'privacy consent kept');
    $log = DB::all('SELECT * FROM isp_application_log WHERE application_id = :i', ['i' => $id]);
    check(count($log) === 1 && $log[0]['to_status'] === 'new', 'the application log starts with "new"');
    $n = DB::all("SELECT * FROM isp_notifications WHERE event_code = 'application_received' AND dedupe_key LIKE :k", ['k' => 'application_received:APP-' . $id . ':%']);
    $byCh = array_column($n, null, 'channel');
    check(isset($byCh['sms'], $byCh['email']), 'the applicant is notified by SMS and e-mail');
    eq($byCh['sms']['recipient'], '639175550101', 'the mobile number is normalized for the SMS gateway');
    eq($byCh['sms']['status'], 'skipped', 'no SMS provider is set up: the message is logged as not sent, never shown as sent');
    check(count(isp_t_audit('isp_application', $id, 'create')) === 1, 'the creation is in the audit trail');
    // the area outside the coverage is accepted as an application but flagged
    $far = IspApplications::create(['business_name' => 'Malayo Sari-sari Store', 'classification' => 'business', 'mobile' => '09185550102',
        'install_address' => 'Sitio Malayo', 'plan_id' => $ids['plan.F50'], 'area_id' => $ids['area.FAR'], 'privacy_consent' => 1]);
    eq((int)IspApplications::get($far)['coverage_ok'], 0, 'an application outside the serviceable areas is marked without coverage');
    refuses(fn() => IspApplications::create(['last_name' => 'X', 'mobile' => '09170000000', 'install_address' => 'Y', 'plan_id' => $ids['plan.F50']]), 'privacy', 'without the privacy consent');
    refuses(fn() => IspApplications::create(['mobile' => '09170000000', 'install_address' => 'Y', 'plan_id' => $ids['plan.F50'], 'privacy_consent' => 1]), 'name', 'without a name');
    refuses(fn() => IspApplications::create(['last_name' => 'X', 'install_address' => 'Y', 'plan_id' => $ids['plan.F50'], 'privacy_consent' => 1]), 'mobile', 'without a mobile number');
    refuses(fn() => IspApplications::create(['last_name' => 'X', 'mobile' => '09170000000', 'install_address' => 'Y', 'plan_id' => $ids['plan.F25'], 'area_id' => $ids['area.SR'], 'privacy_consent' => 1]),
        'not offered', 'a plan not offered in the area');
});

test('R02 Installation cannot proceed without the required approval', function () {
    global $ids;
    $app = IspApplications::create(['last_name' => 'Bautista', 'first_name' => 'Jose', 'mobile' => '09175550201', 'install_address' => 'Purok 3, San Roque',
        'plan_id' => $ids['plan.F50'], 'area_id' => $ids['area.SR'], 'privacy_consent' => 1]);
    refuses(fn() => IspApplications::move($app, 'site_survey'), 'cannot go from', 'the survey step comes after the verification step');
    IspApplications::move($app, 'for_verification', 'Documents received');
    refuses(fn() => IspApplications::move($app, 'site_survey'), 'Verify the applicant', 'the survey step needs a verified identity');
    IspApplications::verifyIdentity($app, 'Driver\'s license', 'N01-12-345678');
    eq(IspApplications::get($app)['status'], 'site_survey', 'identity verified: at the site survey');
    refuses(fn() => IspApplications::approve($app), 'cannot be approved yet', 'approval before the survey');
    // an installation work order made by hand for the application cannot be scheduled before the approval
    $wo = IspWorkOrders::create(['wo_type' => 'installation', 'application_id' => $app, 'description' => 'Premature installation']);
    refuses(fn() => IspWorkOrders::schedule($wo, $ids['tech.1'], today(), 'AM'), 'before the application is approved', 'scheduling an unapproved installation');
    IspWorkOrders::cancel($wo, 'Made by mistake');
    $port = (int)DB::value("SELECT id FROM isp_net_ports WHERE node_id = :n AND status = 'free' ORDER BY port_no LIMIT 1", ['n' => $ids['nap.1']]);
    IspApplications::survey($app, ['coverage_ok' => 1, 'survey_feasible' => 0, 'survey_notes' => 'No pole route yet']);
    refuses(fn() => IspApplications::approve($app), 'feasible site survey', 'an unfeasible survey');
    IspApplications::survey($app, ['coverage_ok' => 1, 'survey_feasible' => 1]);
    refuses(fn() => IspApplications::approve($app), 'NAP port', 'a fiber installation without a reserved port');
    IspApplications::survey($app, ['coverage_ok' => 1, 'survey_feasible' => 1, 'port_id' => $port, 'est_cable_m' => 60]);
    eq(DB::value('SELECT status FROM isp_net_ports WHERE id = :p', ['p' => $port]), 'reserved', 'the surveyed port is reserved for the application');
    refuses(fn() => IspApplications::approve($app), 'acceptance of the contract', 'approval before the contract is accepted');
    IspApplications::acceptContract($app, 'Jose Bautista');
    $r = IspApplications::approve($app, 'Complete requirements');
    $a = IspApplications::get($app);
    eq($a['status'], 'for_installation', 'approved and waiting for installation');
    eq(DB::value('SELECT status FROM isp_net_ports WHERE id = :p', ['p' => $port]), 'used', 'the port now belongs to the account');
    eq(isp_t_acct($r['account_id'])['service_status'], 'pending', 'the account waits for activation');
    refuses(fn() => IspAccounts::activate($r['account_id'], today()), 'installation work order is completed', 'activation before the installation');
    IspWorkOrders::schedule($r['work_order_id'], $ids['tech.1'], today(), 'PM');
    $base = ['checklist' => range(0, 6), 'cable_used_m' => 60, 'rx_power_dbm' => -18.2, 'customer_accepted_by' => 'Jose Bautista'];
    refuses(fn() => IspWorkOrders::complete($r['work_order_id'], ['checklist' => [0, 1]] + $base), 'Tick the checklist', 'an unfinished checklist');
    refuses(fn() => IspWorkOrders::complete($r['work_order_id'], ['customer_accepted_by' => ''] + $base), 'accepted the installation', 'no subscriber acceptance');
    refuses(fn() => IspWorkOrders::complete($r['work_order_id'], ['rx_power_dbm' => -29.5] + $base), 'out of the acceptable range', 'a bad optical signal');
    eq(DB::value('SELECT status FROM isp_work_orders WHERE id = :w', ['w' => $r['work_order_id']]), 'scheduled', 'nothing was completed by the refused attempts');
    IspWorkOrders::complete($r['work_order_id'], $base);
    eq(IspApplications::get($app)['status'], 'for_activation', 'installed: ready for activation');
    $res = IspAccounts::activate($r['account_id'], today());
    eq(isp_t_acct($r['account_id'])['service_status'], 'active', 'activated after the installation');
    check($res['invoice_id'] > 0, 'the activation made the initial invoice');
});

test('R03 Inventory is deducted correctly after material issuance and consumption', function () {
    global $ids;
    $onu = $ids['item.ONU-HG8145'];
    $drop = $ids['item.DROP-1C'];
    $wh = $ids['site.WH'];
    $van = $ids['site.VAN'];
    $s0 = ['whOnu' => Stock::onHand($onu, $wh), 'vanOnu' => Stock::onHand($onu, $van), 'whDrop' => Stock::onHand($drop, $wh), 'vanDrop' => Stock::onHand($drop, $van)];
    $expense = Accts::get('isp_install_expense');
    $exp0 = isp_t_gl($expense);
    $acct = isp_t_account(['pending_install' => 1, 'with_materials' => 1]);
    $wo = (int)DB::value("SELECT id FROM isp_work_orders WHERE account_id = :a AND wo_type = 'installation'", ['a' => $acct]);
    eqm(Stock::onHand($onu, $wh), $s0['whOnu'] - 1, 'stockroom ONU after the issue');
    eqm(Stock::onHand($onu, $van), $s0['vanOnu'] + 1, 'technician van ONU after the issue');
    eqm(Stock::onHand($drop, $wh), $s0['whDrop'] - 120, 'stockroom drop cable after the issue');
    eqm(Stock::onHand($drop, $van), $s0['vanDrop'] + 120, 'van drop cable after the issue');
    eqm(isp_t_gl($expense), $exp0, 'an issue is a transfer between sites: no cost yet');
    $eq = DB::row('SELECT * FROM isp_equipment WHERE work_order_id = :w', ['w' => $wo]);
    eq($eq['status'], 'with_technician', 'the serialized ONU is with the technician');
    refuses(fn() => IspWorkOrders::issue($wo, [[$drop, 999999]]), 'in the stockroom', 'issuing more than is on hand');
    $mats = [];
    foreach (DB::all('SELECT id, item_id FROM isp_wo_materials WHERE work_order_id = :w', ['w' => $wo]) as $m) {
        $mats[(int)$m['id']] = [(int)$m['item_id'] === $drop ? 100 : 1, null];
    }
    IspWorkOrders::complete($wo, ['checklist' => range(0, 6), 'cable_used_m' => 100, 'rx_power_dbm' => -20.1, 'customer_accepted_by' => 'Subscriber', 'materials' => $mats]);
    eqm(Stock::onHand($onu, $van), $s0['vanOnu'], 'the ONU used is consumed from the van');
    eqm(Stock::onHand($drop, $van), $s0['vanDrop'], 'the van keeps no drop cable: 100 m used, 20 m returned');
    eqm(Stock::onHand($drop, $wh), $s0['whDrop'] - 100, 'the 20 m unused are back in the stockroom');
    eqm(Stock::onHand($onu, $wh), $s0['whOnu'] - 1, 'the stockroom ONU count is net of the one installed');
    $use = isp_t_posting('USE-' . $wo);
    check($use !== null && (int)$use['txn_id'] > 0, 'the consumption is posted once (USE-' . $wo . ')');
    eqm($use['amount'], 1 * 1200 + 100 * 8, 'consumption valued at average cost (1 x 1,200 + 100 m x 8)');
    eqm(isp_t_gl($expense) - $exp0, 2000, 'installation cost account debited');
    $m = DB::row('SELECT * FROM isp_wo_materials WHERE work_order_id = :w AND item_id = :i', ['w' => $wo, 'i' => $drop]);
    eq([$m['status'], (float)$m['qty_used'], (float)$m['qty_returned']], ['consumed', 100.0, 20.0], 'material line used / returned');
    $eq = DB::row('SELECT * FROM isp_equipment WHERE id = :e', ['e' => $eq['id']]);
    eq([$eq['status'], (int)$eq['account_id']], ['installed', $acct], 'the ONU is registered as installed at the subscriber');
    IspAccounts::activate($acct, today());
});

test('R04 Service activation triggers the correct billing policy', function () {
    global $ids;
    // calendar cycle on the 1st, billing in advance, first period prorated
    $d = ymd(1, 20);
    $dim = days_in_month($d);
    $days = $dim - 19;
    $sub = round(1500 * $days / $dim, 2);
    $acct = isp_t_account(['date' => $d]);
    $a = isp_t_acct($acct);
    eq([$a['service_status'], $a['network_state'], $a['activation_date']], ['active', 'enabled', $d], 'status, network state, activation date');
    $invs = isp_t_invoices($acct);
    check(count($invs) === 1 && $invs[0]['kind'] === 'initial', 'one initial invoice');
    $inv = $invs[0];
    $lines = DB::all('SELECT * FROM isp_invoice_lines WHERE invoice_id = :i ORDER BY line_no', ['i' => $inv['id']]);
    $byType = [];
    foreach ($lines as $l) {
        $byType[$l['line_type']] = round((float)$l['amount'] + (float)$l['vat_amount'], 2);
    }
    eqm($byType['installation'] ?? 0, 1000, 'installation fee (the quoted fee)');
    eqm($byType['prorate'] ?? 0, $sub, "first period prorated: 1,500 x $days / $dim days");
    eqm($inv['total'], 1000 + $sub, 'initial invoice total');
    [, $v1] = vat_incl(1000);
    [, $v2] = vat_incl($sub);
    eqm($inv['vat_total'], $v1 + $v2, 'output VAT of the VAT-inclusive prices');
    eq($inv['due_date'], date('Y-m-d', strtotime($d . ' +15 days')), 'due 15 days after the invoice date');
    check(str_starts_with((string)$inv['invoice_no'], 'SI-'), 'posted with an official invoice number');
    eq($a['billed_through'], date('Y-m-t', strtotime($d)), 'billed through the end of the first period');
    eq($a['next_bill_date'], date('Y-m-d', strtotime(date('Y-m-t', strtotime($d)) . ' +1 day')), 'the next bill is the first of the next month');
    $c = DB::row('SELECT * FROM isp_contracts WHERE account_id = :a', ['a' => $acct]);
    eq([$c['status'], $c['start_date'], $c['end_date']], ['active', $d, date('Y-m-d', strtotime(IspPlans::addMonths($d, 12) . ' -1 day'))], 'a 12-month contract from the activation date');
    $op = DB::row("SELECT * FROM isp_net_ops WHERE account_id = :a AND op_type = 'activate'", ['a' => $acct]);
    eq([$op['state'], (int)$op['simulated']], ['confirmed', 1], 'the activation is confirmed by the device, marked SIMULATED');
    check((int)DB::value("SELECT COUNT(*) FROM isp_net_logs WHERE op_id = :o AND simulated = 1", ['o' => $op['id']]) > 0, 'the simulator commands are logged as simulated');
    check(count(isp_t_notes($acct, 'service_activated')) > 0, 'the subscriber is notified of the activation');
    check(count(isp_t_notes($acct, 'invoice_generated')) > 0, 'and of the invoice');
    $p = isp_t_posting('INV-' . $inv['id']);
    check($p && $p['status'] === 'posted', 'the invoice is in the books (INV-' . $inv['id'] . ')');
    eqm(isp_t_gl_ar_of($acct), $inv['total'], 'A/R - Subscribers carries the invoice');
    eq(IspApplications::get((int)$a['application_id'])['status'], 'active', 'the application is closed as active');

    // billing in arrears: the initial invoice has no subscription yet
    $b = isp_t_account(['date' => $d, 'bill_timing' => 'arrears']);
    $binv = isp_t_invoices($b)[0];
    check(!DB::value("SELECT id FROM isp_invoice_lines WHERE invoice_id = :i AND line_type IN ('subscription', 'prorate')", ['i' => $binv['id']]), 'arrears billing: no service billed at activation');
    eqm($binv['total'], 1000, 'arrears billing: only the installation');
    check(isp_t_acct($b)['billed_through'] === null, 'arrears billing: nothing billed through yet');
    // no proration of the first period: the full monthly fee
    $c2 = isp_t_account(['date' => $d, 'prorate_first' => 0]);
    eqm(DB::value("SELECT amount + vat_amount FROM isp_invoice_lines WHERE invoice_id = :i AND line_type = 'subscription'", ['i' => isp_t_invoices($c2)[0]['id']]), 1500, 'no proration: the full monthly fee');
    // anniversary billing: the cycle day is the activation day, the first period is whole
    $an = ymd(1, 17);
    $e = isp_t_account(['date' => $an, 'billing_basis' => 'anniversary']);
    eq((int)isp_t_acct($e)['cycle_day'], 17, 'anniversary billing: cycle day = activation day');
    eqm(DB::value("SELECT amount + vat_amount FROM isp_invoice_lines WHERE invoice_id = :i AND line_type = 'subscription'", ['i' => isp_t_invoices($e)[0]['id']]), 1500, 'anniversary billing: whole first period');
    eq(isp_t_acct($e)['billed_through'], date('Y-m-d', strtotime(IspPlans::addMonths($an, 1) . ' -1 day')), 'anniversary billing: billed to the day before the next anniversary');
});

test('R05 Monthly invoices are generated without duplicates', function () {
    global $ids;
    $acct = isp_t_account(['date' => ymd(2, 5), 'cycle_day' => 5]);
    $bill = ymd(1, 5);
    $run1 = IspBilling::generate($bill, 5, null, 'manual', true);
    $r1 = DB::row('SELECT * FROM isp_billing_runs WHERE id = :r', ['r' => $run1]);
    eq([(int)$r1['invoice_count'], $r1['status']], [1, 'posted'], 'first run: one invoice, posted');
    $inv = DB::row("SELECT * FROM isp_invoices WHERE account_id = :a AND kind = 'regular'", ['a' => $acct]);
    eq([$inv['period_start'], $inv['period_end']], [$bill, date('Y-m-d', strtotime(IspPlans::addMonths($bill, 1) . ' -1 day'))], 'the regular invoice period');
    eqm($inv['total'], 1500, 'a whole month');
    $run2 = IspBilling::generate($bill, 5, null, 'manual', true);
    eq((int)DB::value('SELECT invoice_count FROM isp_billing_runs WHERE id = :r', ['r' => $run2]), 0, 'running the same billing again makes nothing');
    $run3 = IspBilling::generate(ymd(1, 25), 5, null, 'manual', true);
    eq((int)DB::value('SELECT invoice_count FROM isp_billing_runs WHERE id = :r', ['r' => $run3]), 0, 'a later run in the same period makes nothing either');
    eq((int)DB::value("SELECT COUNT(*) FROM isp_invoices WHERE account_id = :a AND kind = 'regular' AND status <> 'voided'", ['a' => $acct]), 1, 'one regular invoice for the period');
    // createDraft with the same period gives back the existing invoice
    $a = isp_t_acct($acct);
    $calc = IspBilling::compute($a, $inv['period_start'], $inv['period_end'], $bill);
    eq(IspBilling::createDraft($a, 'regular', $inv['period_start'], $inv['period_end'], $bill, $calc), (int)$inv['id'], 'a second draft for the period returns the existing invoice');
    // the database itself refuses a second invoice with the same period key
    $dup = false;
    try {
        DB::insert('isp_invoices', ['invoice_no' => 'D-DUP', 'account_id' => $acct, 'kind' => 'regular', 'period_key' => $inv['period_key'], 'invoice_date' => $bill, 'due_date' => $bill, 'status' => 'draft']);
    } catch (PDOException $e) {
        $dup = (int)($e->errorInfo[1] ?? 0) === 1062;
    }
    check($dup, 'the unique period key refuses a duplicate invoice');
    // a voided invoice frees its period for a corrected one, with a new official number and no gap
    $before = (int)DB::value("SELECT next_no FROM sequences WHERE seq_key = 'isp_invoice'");
    IspBilling::void((int)$inv['id'], 'Wrong period (test)');
    eq(isp_t_acct($acct)['billed_through'], ymd(1, 4), 'voiding the last period steps the billed-through date back');
    $run4 = IspBilling::generate($bill, 5, null, 'manual', true);
    eq((int)DB::value('SELECT invoice_count FROM isp_billing_runs WHERE id = :r', ['r' => $run4]), 1, 'the period is billed again after the void');
    $new = DB::row("SELECT * FROM isp_invoices WHERE account_id = :a AND kind = 'regular' AND status <> 'voided'", ['a' => $acct]);
    eq($new['invoice_no'], 'SI-' . str_pad((string)$before, 7, '0', STR_PAD_LEFT), 'the corrected invoice takes the next number of the series (no gap)');
    eq(DB::value("SELECT status FROM isp_postings WHERE event_key = :k", ['k' => 'INV-' . $inv['id']]), 'voided', 'the voided invoice is voided in the books');
});

test('R06 Prorated billing is calculated correctly', function () {
    global $ids;
    // a first period from the 10th of a month three months ago
    $d = ymd(3, 10);
    $dim = days_in_month($d);
    $acct = isp_t_account(['date' => $d, 'plan' => $ids['plan.F100']]);
    $line = DB::row("SELECT * FROM isp_invoice_lines WHERE invoice_id = :i AND line_type = 'prorate'", ['i' => isp_t_invoices($acct)[0]['id']]);
    $exp = round(2000 * ($dim - 9) / $dim, 2);
    eqm((float)$line['amount'] + (float)$line['vat_amount'], $exp, 'prorated first period ' . ($dim - 9) . "/$dim of 2,000");
    eq([$line['period_start'], $line['period_end']], [$d, date('Y-m-t', strtotime($d))], 'the prorated period');
    // a mid-period upgrade while the period is already billed: the unused old plan is credited, the new plan charged for the same days
    $u = isp_t_account(['date' => ymd(1, 1)]);
    $from = ymd(1, 21);
    $dim = days_in_month($from);
    $left = $dim - 20;
    IspAccounts::changePlan($u, $ids['plan.F100'], $from, 'Customer upgrade (test)');
    $ch = DB::all("SELECT * FROM isp_charges WHERE account_id = :a AND charge_type = 'plan_change' ORDER BY id", ['a' => $u]);
    check(count($ch) === 2, 'two proration charges (credit and charge)');
    eqm($ch[0]['amount'], -round(1500 * $left / $dim, 2), "credit of the unused Fiber 50: $left/$dim of 1,500");
    eqm($ch[1]['amount'], round(2000 * $left / $dim, 2), "charge of Fiber 100 for the same $left days");
    $inv = IspBilling::chargesInvoice($u, today());
    eqm(isp_t_invoice($inv)['total'], round(2000 * $left / $dim, 2) - round(1500 * $left / $dim, 2), 'the proration nets on the next invoice');
});

test('R07 A partial payment updates the remaining receivable', function () {
    $acct = isp_t_account(['date' => today()]);
    $inv = isp_t_invoices($acct)[0];
    $total = (float)$inv['total'];
    $pid = isp_t_pay($acct, 600, 'cash');
    $p = IspPayments::get($pid);
    eq([$p['status'], (float)$p['applied_amount'], (float)$p['unapplied_amount']], ['posted', 600.0, 0.0], 'the payment is applied in full');
    $inv = isp_t_invoice((int)$inv['id']);
    eqm($inv['balance'], $total - 600, 'the invoice balance after a partial payment');
    eq($inv['status'], 'partially_paid', 'partially paid');
    eqm($inv['paid_amount'], 600, 'paid amount');
    eqm(IspAccounts::balances($acct)['balance'], $total - 600, 'the account balance');
    eqm(isp_t_gl_ar_of($acct), $total - 600, 'the books agree');
    $pay = isp_t_posting('PAY-' . $pid);
    eqm(DB::value('SELECT SUM(amount) FROM payment_applications WHERE payment_txn_id = :t', ['t' => $pay['txn_id']]), 600, 'the ledger payment is applied to the ledger invoice');
    eq((int)$p['shift_id'], (int)$GLOBALS['ids']['shift.admin'], 'cash is collected in the cashier\'s open shift');
    isp_t_pay($acct, $total - 600, 'gcash');
    eq(isp_t_invoice((int)$inv['id'])['status'], 'paid', 'paid after the second payment');
    eqm(isp_t_gl_ar_of($acct), 0, 'nothing left in the books');
});

test('R08 An advance payment creates the appropriate credit', function () {
    $acct = isp_t_account(['date' => today()]);
    $inv = isp_t_invoices($acct)[0];
    $total = (float)$inv['total'];
    $pid = isp_t_pay($acct, $total + 3000);
    $p = IspPayments::get($pid);
    eqm($p['applied_amount'], $total, 'the invoice is paid first');
    eqm($p['unapplied_amount'], 3000, 'the rest is the subscriber\'s credit');
    $b = IspAccounts::balances($acct);
    eqm($b['credit'], 3000, 'account credit');
    eqm($b['balance'], -3000, 'a negative balance: money in advance');
    eqm(isp_t_gl_ar_of($acct), -3000, 'the books show the credit in A/R - Subscribers');
    // the next invoice takes the credit when it posts
    IspBilling::addCharge($acct, 'one_time', 'Mesh Wi-Fi extender', 1800, today(), 'TEST-R08-' . $acct);
    $i2 = IspBilling::chargesInvoice($acct, today());
    $i2r = isp_t_invoice($i2);
    eq([$i2r['status'], (float)$i2r['balance']], ['paid', 0.0], 'the new invoice is settled by the advance payment');
    eqm(IspPayments::get($pid)['unapplied_amount'], 1200, 'credit left');
    check((int)DB::value("SELECT COUNT(*) FROM isp_postings WHERE source_type = 'credit_apply' AND source_id = :s", ['s' => $pid . ':' . $i2]) === 1, 'the application is in the books once');
    eqm(isp_t_gl_ar_of($acct), -1200, 'the books follow');
    eqm(IspPayments::applyCredits($acct), 0, 'nothing more to apply');
});

test('R09 An unpaid customer becomes overdue', function () {
    global $ids;
    $acct = isp_t_account(['date' => ymd(1, 1), 'plan' => $ids['plan.F25'], 'area' => $ids['area.PB']]);
    $inv = isp_t_invoices($acct)[0];
    eq($inv['status'], 'posted', 'posted and unpaid');
    check($inv['due_date'] < today(), 'the due date has passed');
    IspBilling::markOverdue();
    $inv = isp_t_invoice((int)$inv['id']);
    eq($inv['status'], 'overdue', 'overdue after the due-date check');
    eq($inv['overdue_since'], date('Y-m-d', strtotime($inv['due_date'] . ' +1 day')), 'overdue since the day after the due date');
    eqm(IspAccounts::balances($acct)['overdue'], $inv['balance'], 'the overdue amount of the account');
    IspBilling::assessLateFees();
    IspBilling::assessLateFees();
    $fees = DB::all("SELECT * FROM isp_charges WHERE account_id = :a AND charge_type = 'late_fee'", ['a' => $acct]);
    check(count($fees) === 1, 'one late charge per overdue invoice, even when assessed twice');
    eqm($fees[0]['amount'], round((float)$inv['balance'] * 0.02, 2), 'the percent late charge of the plan (2% of the overdue amount)');
    IspJobs::run('reminders', 'test');
    IspJobs::run('reminders', 'test');
    eq(count(array_filter(isp_t_notes($acct, 'payment_overdue'), fn($n) => $n['channel'] === 'inapp')), 1, 'one overdue reminder per invoice and channel');
});

test('R10 A customer is disconnected only after satisfying the configured conditions', function () {
    global $ids, $T;
    $acct = isp_t_account(['date' => today()]);
    $T['r10'] = $acct;
    check(IspDisconnect::evaluateAccount($acct) === null, 'not due yet: not queued');
    isp_t_age($acct, 3);
    [$ok, $why] = IspDisconnect::eligible(isp_t_acct($acct), IspDisconnect::rule('overdue'));
    check(!$ok && str_contains($why, 'below the rule'), 'overdue for 3 days, inside the 5-day grace period: not eligible (' . $why . ')');
    check(IspDisconnect::evaluateAccount($acct) === null, 'inside the grace period: not queued');
    // an amount below the rule's minimum does not qualify
    $small = isp_t_account(['date' => today()]);
    $sinv = isp_t_invoices($small)[0];
    isp_t_pay($small, (float)$sinv['total'] - 50);
    isp_t_age($small, 10);
    [$ok, $why] = IspDisconnect::eligible(isp_t_acct($small), IspDisconnect::rule('overdue'));
    check(!$ok && str_contains($why, 'below the rule'), '50.00 overdue is below the 100.00 minimum (' . $why . ')');
    // past the grace period: a candidate that waits for approval
    isp_t_age($acct, 10);
    $qid = IspDisconnect::evaluateAccount($acct);
    check($qid !== null, 'past the grace period: queued');
    $q = isp_t_queue($acct);
    eq($q['status'], 'candidate', 'the rule asks for approval: a candidate');
    eqm($q['eligible_amount'], IspAccounts::balances($acct)['overdue'], 'the qualifying arrears are recorded');
    refuses(fn() => IspDisconnect::execute($qid), 'only an approved item', 'a candidate is not executed');
    IspDisconnect::approve([$qid]);
    $q = isp_t_queue($acct);
    eq($q['status'], 'notified', 'approved: the final notice goes out');
    check($q['execute_after'] > date('Y-m-d H:i:s', time() + 23 * 3600), 'the disconnection waits the 24 hours of the final notice');
    check(count(isp_t_notes($acct, 'final_notice')) > 0, 'the final notice is queued for the subscriber');
    eq(IspDisconnect::executeDue(), 0, 'nothing is executed before the notice period ends');
    eq(isp_t_acct($acct)['service_status'], 'active', 'still active during the notice');
    DB::run('UPDATE isp_disc_queue SET execute_after = :t WHERE id = :q', ['t' => date('Y-m-d H:i:s', time() - 60), 'q' => $qid]);
    eq(IspDisconnect::executeDue(), 1, 'the notice period over: executed');
    $a = isp_t_acct($acct);
    eq([$a['service_status'], $a['network_state']], ['disconnected', 'disabled'], 'disconnected, confirmed by the device');
    check($a['disconnected_at'] !== null, 'the time of the disconnection is kept');
    eq(isp_t_queue($acct)['status'], 'disconnected', 'the queue item is done');
    check(isp_t_queue($acct)['live_key'] === null, 'the live key is released');
    check(count(isp_t_notes($acct, 'service_disconnected')) > 0, 'the subscriber is told');
    $op = DB::row("SELECT * FROM isp_net_ops WHERE account_id = :a AND op_type = 'disconnect'", ['a' => $acct]);
    eq([$op['state'], $op['source_type'], (int)$op['source_id']], ['confirmed', 'disc_queue', $qid], 'one confirmed disconnect operation from the queue item');
    // a voluntarily suspended account is never a nonpayment candidate
    $s = isp_t_account(['date' => today()]);
    isp_t_age($s, 20);
    IspAccounts::suspend($s, null, 'Out of the country (test)');
    check(IspDisconnect::evaluateAccount($s) === null, 'a suspended account is not queued');
});

test('R11 A verified last-minute payment prevents an incorrect disconnection', function () {
    // paid after the final notice, before the execution time
    $a1 = isp_t_account(['date' => today()]);
    isp_t_age($a1, 10);
    $q1 = IspDisconnect::evaluateAccount($a1);
    IspDisconnect::approve([$q1]);
    eq(isp_t_queue($a1)['status'], 'notified', 'final notice sent');
    // a payment reference that is not verified yet changes nothing
    $pend = isp_t_pay($a1, IspAccounts::balances($a1)['balance'], 'gcash', ['status' => 'pending', 'source' => 'portal']);
    eq(isp_t_queue($a1)['status'], 'notified', 'an unverified GCash reference does not stop the disconnection');
    IspPayments::verify($pend, true, 'Matched with the GCash statement');
    $q = isp_t_queue($a1);
    eq($q['status'], 'skipped', 'the verified payment cancels the waiting disconnection');
    check(str_contains((string)$q['skip_reason'], 'Paid'), 'with the reason');
    DB::run('UPDATE isp_disc_queue SET execute_after = :t WHERE id = :q', ['t' => date('Y-m-d H:i:s', time() - 60), 'q' => $q1]);
    IspDisconnect::executeDue();
    eq(isp_t_acct($a1)['service_status'], 'active', 'still connected');
    // paid while the command is on its way: the recheck under the lock stops it
    $a2 = isp_t_account(['date' => today()]);
    isp_t_age($a2, 10);
    $q2 = IspDisconnect::evaluateAccount($a2);
    IspDisconnect::approve([$q2]);
    DB::run("UPDATE isp_disc_queue SET status = 'executing' WHERE id = :q", ['q' => $q2]);
    $op = IspNetwork::request($a2, 'disconnect', 'Nonpayment (test)', 'disc_queue', $q2, false);
    isp_t_pay($a2, IspAccounts::balances($a2)['balance']);
    eq(IspNetwork::execute($op), 'skipped', 'the operation is not sent');
    $o = DB::row('SELECT * FROM isp_net_ops WHERE id = :o', ['o' => $op]);
    eq($o['state'], 'cancelled', 'the operation is cancelled before sending');
    eq(isp_t_queue($a2)['status'], 'skipped', 'the queue item is skipped');
    eq((int)DB::value("SELECT COUNT(*) FROM isp_net_logs WHERE account_id = :a AND command LIKE 'SIM disable%'", ['a' => $a2]), 0, 'no disable command reached the device');
    eq([isp_t_acct($a2)['service_status'], isp_t_acct($a2)['network_state']], ['active', 'enabled'], 'the subscriber stays connected');
});

test('R12 A protected customer is not disconnected', function () {
    global $ids;
    $rule = IspDisconnect::rule('overdue');
    // exemption
    $ex = isp_t_account(['date' => today()]);
    isp_t_age($ex, 15);
    $eid = IspCredit::grantExemption($ex, date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('+10 days')), 'Senior citizen, hospital confinement (test)');
    [$ok, $why] = IspDisconnect::eligible(isp_t_acct($ex), $rule);
    check(!$ok && str_contains($why, 'protected'), 'an exemption protects the account (' . $why . ')');
    check(IspDisconnect::evaluateAccount($ex) === null, 'the exempted account is not queued');
    IspCredit::revokeExemption($eid, 'Discharged (test)');
    check(IspDisconnect::evaluateAccount($ex) !== null, 'once the exemption is revoked the account qualifies again');
    // payment arrangement: approving it cancels the waiting item and protects the account
    $ar = isp_t_account(['date' => today()]);
    isp_t_age($ar, 15);
    $qid = IspDisconnect::evaluateAccount($ar);
    $bal = IspAccounts::balances($ar)['balance'];
    $arr = IspCredit::createArrangement($ar, 'installment', [[date('Y-m-d', strtotime('+7 days')), round($bal / 2, 2)], [date('Y-m-d', strtotime('+21 days')), $bal - round($bal / 2, 2)]],
        true, true, 'Two installments (test)');
    check(IspDisconnect::evaluateAccount($ar) === null || isp_t_queue($ar)['id'] == $qid, 'a pending arrangement does not create a second queue item');
    IspCredit::decideArrangement($arr, true, 'Approved by the manager');
    eq(DB::value('SELECT status FROM isp_disc_queue WHERE id = :q', ['q' => $qid]), 'cancelled', 'the approved arrangement cancels the waiting disconnection');
    [$ok, $why] = IspDisconnect::eligible(isp_t_acct($ar), $rule);
    check(!$ok && str_contains($why, 'arrangement'), 'the arrangement protects the account (' . $why . ')');
    IspBilling::assessLateFees();
    check(!DB::value("SELECT id FROM isp_charges WHERE account_id = :a AND charge_type = 'late_fee'", ['a' => $ar]), 'the arrangement waives the late charges');
    // a protected dispute takes the disputed invoice out of the arrears
    $dp = isp_t_account(['date' => today()]);
    isp_t_age($dp, 15);
    $inv = isp_t_invoices($dp)[0];
    IspCredit::openDispute($dp, (int)$inv['id'], (float)$inv['balance'], 'Billed before the installation date (test)', true);
    eq(isp_t_invoice((int)$inv['id'])['status'], 'disputed', 'the invoice is disputed');
    $arrs = IspDisconnect::arrears($dp, $rule);
    eqm($arrs['amount'], 0, 'the disputed invoice is not counted as arrears');
    eqm($arrs['disputed'], $inv['balance'], 'it is reported as disputed');
    check(IspDisconnect::evaluateAccount($dp) === null, 'the account with the protected dispute is not queued');
    // an unprotected dispute protects nothing
    $up = isp_t_account(['date' => today()]);
    isp_t_age($up, 15);
    IspCredit::openDispute($up, (int)isp_t_invoices($up)[0]['id'], 100, 'Asks about a line (test)', false);
    check(IspDisconnect::evaluateAccount($up) !== null, 'an unprotected dispute does not stop the disconnection');
    // disconnected for fraud: an authorized reconnection only, never an automatic one after payment
    $fr = isp_t_account(['date' => today()]);
    IspDisconnect::queueManual($fr, 'fraud', 'Illegal tapping of the line (test)', true);
    eq(isp_t_acct($fr)['service_status'], 'disconnected', 'disconnected for fraud');
    isp_t_pay($fr, max(1, IspAccounts::balances($fr)['balance']));
    eq(isp_t_acct($fr)['service_status'], 'disconnected', 'a payment does not reconnect a fraud disconnection');
    check(str_contains((string)IspReconnect::blocker(isp_t_acct($fr)), 'authorized reconnection'), 'it needs an authorized reconnection');
});

test('R13 Duplicate scheduler execution does not duplicate network commands', function () {
    global $ids;
    $acct = isp_t_account(['date' => today()]);
    isp_t_age($acct, 10);
    $q = IspDisconnect::evaluateAccount($acct);
    check(IspDisconnect::evaluateAccount($acct) === null, 'a second evaluation does not queue the account again');
    IspDisconnect::evaluate();
    IspDisconnect::evaluate();
    eq((int)DB::value("SELECT COUNT(*) FROM isp_disc_queue WHERE account_id = :a AND status NOT IN ('skipped', 'cancelled')", ['a' => $acct]), 1, 'one live queue item after repeated evaluations');
    IspDisconnect::approve([$q]);
    IspDisconnect::approve([$q]);
    eq(IspDisconnect::execute($q, true), 'confirmed', 'the first execution disconnects');
    refuses(fn() => IspDisconnect::execute($q, true), 'only an approved item', 'a second execution of the same item');
    eq((int)DB::value("SELECT COUNT(*) FROM isp_net_ops WHERE account_id = :a AND op_type = 'disconnect'", ['a' => $acct]), 1, 'one disconnect operation');
    eq((int)DB::value("SELECT COUNT(*) FROM isp_net_logs WHERE account_id = :a AND command LIKE 'SIM disable%'", ['a' => $acct]), 1, 'one command sent to the device');
    // the same request from the same source is the same operation
    $o1 = IspNetwork::request($acct, 'status', 'Status check (test)', 'manual', 777, false);
    $o2 = IspNetwork::request($acct, 'status', 'Status check (test)', 'manual', 777, false);
    eq($o1, $o2, 'a repeated request returns the same operation (idempotency key)');
    IspNetwork::processQueue();
    IspNetwork::processQueue();
    eq(DB::value('SELECT state FROM isp_net_ops WHERE id = :o', ['o' => $o1]), 'confirmed', 'sent once by the queue');
    eq((int)DB::value('SELECT attempts FROM isp_net_ops WHERE id = :o', ['o' => $o1]), 1, 'one attempt only');
    // a job held by another process (its named lock taken by another database connection) is skipped
    $cfg = $GLOBALS['CONFIG']['db'];
    $other = new PDO('mysql:host=' . $cfg['host'] . ';port=' . $cfg['port'] . ';dbname=' . $cfg['name'] . ';charset=utf8mb4', $cfg['user'], $cfg['pass']);
    $key = substr('gsc' . substr(md5(DB::databaseName()), 0, 8) . ':job_disc_execute', 0, 64);
    eq((int)$other->query('SELECT GET_LOCK(' . $other->quote($key) . ', 0)')->fetchColumn(), 1, 'the other process holds the job lock');
    $runs = (int)DB::value("SELECT COUNT(*) FROM isp_job_runs WHERE job_code = 'disc_execute'");
    $r = IspJobs::run('disc_execute', 'test');
    eq($r['status'], 'skipped', 'the job is skipped while another process runs it');
    eq((int)DB::value("SELECT COUNT(*) FROM isp_job_runs WHERE job_code = 'disc_execute'"), $runs, 'no run is recorded');
    $other->query('SELECT RELEASE_LOCK(' . $other->quote($key) . ')');
    // the claim on the job row protects too (a crashed process left its claim)
    DB::run("UPDATE isp_jobs SET locked_until = :u WHERE job_code = 'disc_execute'", ['u' => date('Y-m-d H:i:s', time() + 600)]);
    eq(IspJobs::run('disc_execute', 'test')['status'], 'skipped', 'a job claimed by another process is skipped');
    DB::run("UPDATE isp_jobs SET locked_until = NULL WHERE job_code = 'disc_execute'");
    eq(IspJobs::run('disc_execute', 'test')['status'], 'ok', 'once released it runs');
    eq((int)DB::value("SELECT COUNT(*) FROM isp_net_logs WHERE account_id = :a AND command LIKE 'SIM disable%'", ['a' => $acct]), 1, 'still one command after the job runs');
});

test('R14 A network disconnection failure is correctly reported', function () {
    $acct = isp_t_account(['date' => today()]);
    $no = isp_t_acct($acct)['account_no'];
    isp_t_age($acct, 10);
    $q = IspDisconnect::evaluateAccount($acct);
    IspDisconnect::approve([$q]);
    isp_t_device_options('fail_accounts = ' . $no);
    eq(IspDisconnect::execute($q, true), 'retry', 'the device did not answer: the command will be retried');
    $op = DB::row("SELECT * FROM isp_net_ops WHERE account_id = :a AND op_type = 'disconnect'", ['a' => $acct]);
    eq([$op['state'], (int)$op['attempts']], ['queued', 1], 'queued for a retry after the first attempt');
    check(str_contains((string)$op['error'], 'simulated failure'), 'the error is kept');
    $a = isp_t_acct($acct);
    eq([$a['service_status'], $a['network_state']], ['active', 'enabled'], 'nothing is reported disconnected');
    for ($i = 0; $i < 6; $i++) {
        DB::run("UPDATE isp_net_ops SET next_try_at = :t WHERE id = :o AND state = 'queued'", ['t' => date('Y-m-d H:i:s', time() - 1), 'o' => $op['id']]);
        IspNetwork::processQueue();
    }
    $op = DB::row('SELECT * FROM isp_net_ops WHERE id = :o', ['o' => $op['id']]);
    eq([$op['state'], (int)$op['attempts']], ['failed', 5], 'failed after the five attempts');
    eq(isp_t_queue($acct)['status'], 'failed', 'the queue item is failed');
    $wo = DB::row('SELECT * FROM isp_work_orders WHERE net_op_id = :o', ['o' => $op['id']]);
    check($wo && $wo['wo_type'] === 'disconnection' && $wo['priority'] === 'high', 'a high-priority work order goes to the network team');
    eq(isp_t_acct($acct)['service_status'], 'active', 'the account is still shown connected (the truth)');
    check((bool)DB::value("SELECT id FROM isp_account_events WHERE account_id = :a AND summary LIKE '%FAILED%'", ['a' => $acct]), 'the failure is on the account history');
    // fixed and retried: done
    isp_t_reset_device();
    IspNetwork::retry((int)$op['id']);
    eq(DB::value('SELECT state FROM isp_net_ops WHERE id = :o', ['o' => $op['id']]), 'confirmed', 'the retry is confirmed');
    eq(isp_t_acct($acct)['service_status'], 'disconnected', 'disconnected once the device confirmed');
});

test('R15 Payment qualification triggers reconnection processing', function () {
    global $T, $ids;
    $acct = $T['r10'];
    eq(isp_t_acct($acct)['service_status'], 'disconnected', 'the account of R10 is disconnected');
    $bal = IspAccounts::balances($acct)['balance'];
    isp_t_pay($acct, round($bal - 200, 2));
    eq(isp_t_acct($acct)['service_status'], 'disconnected', 'a partial payment below the arrears does not reconnect');
    check(!DB::value("SELECT id FROM isp_reconnections WHERE account_id = :a AND status NOT IN ('cancelled')", ['a' => $acct]), 'no reconnection yet');
    $pid = isp_t_pay($acct, 200);
    $r = DB::row('SELECT * FROM isp_reconnections WHERE account_id = :a ORDER BY id DESC LIMIT 1', ['a' => $acct]);
    eq([$r['recon_type'], $r['status'], (int)$r['payment_id']], ['automatic', 'restored', $pid], 'an automatic reconnection after the qualifying payment, confirmed');
    $a = isp_t_acct($acct);
    eq([$a['service_status'], $a['network_state']], ['active', 'enabled'], 'active and enabled again');
    eq(DB::value('SELECT state FROM isp_net_ops WHERE id = :o', ['o' => $r['net_op_id']]), 'confirmed', 'the reconnect operation is confirmed');
    $fee = DB::row('SELECT * FROM isp_charges WHERE id = :c', ['c' => $r['fee_charge_id']]);
    check($fee && $fee['charge_type'] === 'reconnection_fee' && $fee['status'] === 'pending', 'the reconnection fee waits for the next invoice');
    eqm($fee['amount'], 300, 'the plan reconnection fee');
    check(count(isp_t_notes($acct, 'service_restored')) > 0, 'the subscriber is told');
    check($a['billed_through'] >= date('Y-m-d', strtotime('-1 day')), 'billing resumes from the reconnection (no charge for the days disconnected)');
    check($r['live_key'] === null, 'the reconnection is closed');
});

test('R16 A reconnection failure does not falsely mark the network service as restored', function () {
    $acct = isp_t_account(['date' => today()]);
    isp_t_age($acct, 10);
    IspDisconnect::evaluateAccount($acct);
    isp_t_disconnect_now($acct);
    eq(isp_t_acct($acct)['service_status'], 'disconnected', 'disconnected');
    isp_t_device_options('fail_ops = enable');
    isp_t_pay($acct, IspAccounts::balances($acct)['balance']);
    $r = DB::row('SELECT * FROM isp_reconnections WHERE account_id = :a ORDER BY id DESC LIMIT 1', ['a' => $acct]);
    eq($r['status'], 'executing', 'the reconnection is on its way, not restored');
    $a = isp_t_acct($acct);
    eq([$a['service_status'], $a['network_state']], ['disconnected', 'disabled'], 'still disconnected and disabled: never shown restored');
    for ($i = 0; $i < 6; $i++) {
        DB::run("UPDATE isp_net_ops SET next_try_at = :t WHERE id = :o AND state = 'queued'", ['t' => date('Y-m-d H:i:s', time() - 1), 'o' => $r['net_op_id']]);
        IspNetwork::processQueue();
    }
    eq(DB::value('SELECT status FROM isp_reconnections WHERE id = :r', ['r' => $r['id']]), 'failed', 'the reconnection is failed');
    $a = isp_t_acct($acct);
    eq([$a['service_status'], $a['network_state']], ['disconnected', 'failed'], 'the account shows the failure');
    check((bool)DB::value("SELECT id FROM isp_work_orders WHERE net_op_id = :o AND wo_type = 'reconnection'", ['o' => $r['net_op_id']]), 'a reconnection work order for the network team');
    check((bool)DB::value("SELECT id FROM isp_account_events WHERE account_id = :a AND summary LIKE '%NOT restored%'", ['a' => $acct]), 'the history says it is not restored');
    isp_t_reset_device();
    IspNetwork::retry((int)$r['net_op_id']);
    eq(DB::value('SELECT status FROM isp_reconnections WHERE id = :r', ['r' => $r['id']]), 'restored', 'restored once the device confirmed');
    eq(isp_t_acct($acct)['service_status'], 'active', 'active again');
});

test('R17 A payment reversal recalculates the ledger and the service eligibility', function () {
    global $ids;
    $acct = isp_t_account(['date' => today()]);
    isp_t_age($acct, 10);
    $inv = isp_t_invoices($acct)[0];
    $total = (float)$inv['total'];
    $ar0 = isp_t_gl_ar_of($acct);
    $pid = isp_t_pay($acct, $total, 'bank_transfer', ['reference_no' => 'BPI-778899']);
    eq(isp_t_invoice((int)$inv['id'])['status'], 'paid', 'paid');
    check(IspDisconnect::evaluateAccount($acct) === null, 'paid: not eligible');
    isp_t_as($ids['user.billing']);
    refuses(fn() => IspPayments::reverse($pid, 'Bounced transfer'), 'permission', 'a billing officer cannot reverse a collection');
    isp_t_as($ids['user.acct']);
    refuses(fn() => IspPayments::reverse($pid, ''), 'reason', 'a reversal needs a reason');
    IspPayments::reverse($pid, 'The bank transfer was recalled by the bank');
    isp_t_as($GLOBALS['adminId']);
    $p = IspPayments::get($pid);
    eq($p['status'], 'reversed', 'the payment is reversed');
    $inv = isp_t_invoice((int)$inv['id']);
    eqm($inv['balance'], $total, 'the invoice is open again');
    eq($inv['status'], 'overdue', 'and overdue again');
    eqm(isp_t_gl_ar_of($acct), $ar0, 'the receivable is back in the books');
    $post = isp_t_posting('PAY-' . $pid);
    eq($post['status'], 'voided', 'the ledger payment is voided');
    eq((int)DB::value('SELECT is_voided FROM transactions WHERE id = :t', ['t' => $post['txn_id']]), 1, 'the ledger document is void');
    check(isp_t_posting('REV-PAY-' . $pid) !== null, 'the reversal is linked to the original');
    check(count(isp_t_audit('isp_payment', $pid, 'reverse')) === 1, 'the reversal is in the audit trail');
    $q = isp_t_queue($acct);
    check($q && $q['status'] === 'candidate', 'the account qualifies for disconnection again and is queued for approval');
    // the corrected payment can be entered again with the same reference
    $pid2 = isp_t_pay($acct, $total, 'bank_transfer', ['reference_no' => 'BPI-778899']);
    eq(IspPayments::get($pid2)['status'], 'posted', 'the same reference can be posted again after the reversal');
    eq(isp_t_queue($acct)['status'], 'skipped', 'paid again: the queue item is dropped');
});

test('R18 Plan upgrades and downgrades preserve invoice history', function () {
    global $ids;
    $acct = isp_t_account(['date' => ymd(1, 1)]);
    $inv = isp_t_invoices($acct)[0];
    $lines0 = DB::all('SELECT * FROM isp_invoice_lines WHERE invoice_id = :i ORDER BY line_no', ['i' => $inv['id']]);
    IspAccounts::changePlan($acct, $ids['plan.F100'], ymd(1, 16), 'Upgrade (test)');
    IspAccounts::changePlan($acct, $ids['plan.F50'], today(), 'Downgrade back (test)');
    $hist = DB::all('SELECT * FROM isp_account_plans WHERE account_id = :a ORDER BY id', ['a' => $acct]);
    eq(array_column($hist, 'change_type'), ['new', 'upgrade', 'downgrade'], 'the plan history');
    eq($hist[0]['effective_to'], ymd(1, 15), 'the first plan ends the day before the upgrade');
    eqm($hist[1]['monthly_fee'], 2000, 'the upgrade price is kept on the history');
    // a price change of the plan does not touch issued invoices
    IspPlans::save(['code' => 'FIB50', 'name' => 'Fiber 50', 'monthly_fee' => 1600, 'download_mbps' => 50], $ids['plan.F50']);
    $lines1 = DB::all('SELECT * FROM isp_invoice_lines WHERE invoice_id = :i ORDER BY line_no', ['i' => $inv['id']]);
    eq($lines1, $lines0, 'the issued invoice lines are unchanged by the plan changes and the price change');
    eq(isp_t_invoice((int)$inv['id'])['total'], $inv['total'], 'the issued invoice total is unchanged');
    $sub = DB::row("SELECT * FROM isp_invoice_lines WHERE invoice_id = :i AND line_type = 'subscription'", ['i' => $inv['id']]);
    eq([(int)$sub['plan_id'], (float)$sub['plan_price']], [$ids['plan.F50'], 1500.0], 'the line keeps the plan and the price it was billed at');
    IspPlans::save(['code' => 'FIB50', 'name' => 'Fiber 50', 'monthly_fee' => 1500, 'download_mbps' => 50], $ids['plan.F50']);
    check((int)DB::value("SELECT COUNT(*) FROM isp_net_ops WHERE account_id = :a AND op_type = 'change_plan'", ['a' => $acct]) === 2, 'each change is sent to the network');
    refuses(fn() => IspAccounts::changePlan($acct, $ids['plan.F50'], today(), 'Same'), 'already on that plan', 'a change to the same plan');
});

test('R19 Customer cancellation preserves historical transactions', function () {
    global $ids;
    $acct = isp_t_account(['date' => ymd(2, 1)]);
    $pid = isp_t_pay($acct, 500);
    $invCount = count(isp_t_invoices($acct));
    $txns = DB::column("SELECT txn_id FROM isp_postings WHERE isp_account_id = :a AND txn_id IS NOT NULL", ['a' => $acct]);
    $events = (int)DB::value('SELECT COUNT(*) FROM isp_account_events WHERE account_id = :a', ['a' => $acct]);
    $port = (int)isp_t_acct($acct)['port_id'];
    $out = IspAccounts::terminate($acct, today(), 'Moving to another province (test)', true);
    $a = isp_t_acct($acct);
    eq($a['service_status'], 'terminated', 'terminated');
    check(count(isp_t_invoices($acct)) >= $invCount, 'every invoice is still there');
    eq(IspPayments::get($pid)['status'], 'posted', 'the payment is still posted');
    eq((int)DB::value('SELECT COUNT(*) FROM transactions WHERE is_voided = 1 AND id IN (' . implode(',', array_map('intval', $txns)) . ')'), 0, 'no ledger document of the account was voided');
    check((int)DB::value('SELECT COUNT(*) FROM isp_account_events WHERE account_id = :a', ['a' => $acct]) > $events, 'the history is kept and extended');
    eq(DB::value('SELECT status FROM isp_net_ports WHERE id = :p', ['p' => $port]), 'free', 'the port is released for another subscriber');
    eq(DB::value('SELECT status FROM isp_contracts WHERE account_id = :a ORDER BY id DESC LIMIT 1', ['a' => $acct]), 'terminated', 'the contract is terminated');
    check(!empty($out['net_op_id']) && DB::value('SELECT state FROM isp_net_ops WHERE id = :o', ['o' => $out['net_op_id']]) === 'confirmed', 'the subscriber is removed from the network');
    refuses(fn() => IspAccounts::terminate($acct, today(), 'Again', false), 'can be terminated', 'a second termination');
    $bal = IspAccounts::balances($acct)['balance'];
    if ($bal > 0) {
        isp_t_pay($acct, $bal);
        eqm(IspAccounts::balances($acct)['balance'], 0, 'the final balance can still be collected and settled');
    }
});

test('R20 Posted accounting transactions always balance', function () {
    $bad = DB::all('SELECT txn_id, ROUND(SUM(debit), 2) d, ROUND(SUM(credit), 2) c FROM journal_lines GROUP BY txn_id HAVING ROUND(SUM(debit), 2) <> ROUND(SUM(credit), 2)');
    check(!$bad, 'every ledger transaction balances: ' . json_encode($bad));
    $n = (int)DB::value("SELECT COUNT(*) FROM isp_postings WHERE status = 'posted' AND txn_id IS NOT NULL");
    check($n > 20, "the ISP module has posted $n documents so far");
    $orphans = DB::all("SELECT p.event_key FROM isp_postings p LEFT JOIN transactions t ON t.id = p.txn_id WHERE p.status = 'posted' AND p.txn_id IS NOT NULL AND (t.id IS NULL OR t.is_voided = 1)");
    check(!$orphans, 'every posted ISP event has its live ledger document: ' . json_encode(array_slice($orphans, 0, 5)));
    $voids = DB::all("SELECT p.event_key FROM isp_postings p JOIN transactions t ON t.id = p.txn_id WHERE p.status = 'voided' AND t.is_voided = 0");
    check(!$voids, 'every voided ISP event has its ledger document voided: ' . json_encode(array_slice($voids, 0, 5)));
    $dupKeys = DB::all('SELECT event_key, COUNT(*) n FROM isp_postings GROUP BY event_key HAVING COUNT(*) > 1');
    check(!$dupKeys, 'no ISP event was posted twice');
    // a posted ISP document cannot be changed outside the module
    $t = (int)DB::value("SELECT txn_id FROM isp_postings WHERE source_type = 'invoice' AND status = 'posted' ORDER BY id LIMIT 1");
    refuses(fn() => Txn::void($t, 'Trying from the general ledger'), 'cable / internet', 'voiding an ISP invoice from the accounting side');
    isp_t_assert_books('R20');
});

test('R21 Unauthorized users cannot access restricted operations', function () {
    global $ids;
    isp_t_as($ids['user.tech']);
    foreach (['ispbill.collect', 'ispbill.runs', 'ispbill.invoices', 'ispbill.adjustments', 'ispbill.payments', 'isp.subscribers', 'ispnet.devices', 'ispset.gateways'] as $k) {
        check(!Access::page($k), "a technician cannot open $k");
    }
    check(Access::page('ispfield.mytasks'), 'a technician opens own tasks');
    check(!Access::special('isp_reverse_payment') && !Access::special('isp_disconnect'), 'no special rights');
    $acct = isp_t_account(['date' => today()]);
    isp_t_as($ids['user.tech']);
    $pid = (int)DB::value("SELECT id FROM isp_payments WHERE status = 'posted' ORDER BY id DESC LIMIT 1");
    refuses(fn() => IspPayments::reverse($pid, 'Not allowed'), 'permission', 'a technician cannot reverse a payment');
    isp_t_as($ids['user.csr']);
    refuses(fn() => IspDisconnect::queueManual($acct, 'administrative', 'Not allowed'), 'permission', 'a CSR cannot disconnect');
    check(!Access::can('ispbill.adjustments', 'approve'), 'a CSR cannot approve adjustments');
    isp_t_as($ids['user.billing']);
    $run = IspBilling::generate(ymd(1, 27), 27, null, 'manual', false);
    if ((int)DB::value('SELECT invoice_count FROM isp_billing_runs WHERE id = :r', ['r' => $run]) === 0) {
        DB::run("UPDATE isp_billing_runs SET status = 'draft' WHERE id = :r", ['r' => $run]);
    }
    refuses(fn() => IspBilling::approveRun($run), 'permission', 'a billing officer cannot approve a billing run');
    $inv = isp_t_invoices($acct)[0];
    $adj = IspCredit::request($acct, (int)$inv['id'], 'credit', 500, 'Goodwill (test)');
    refuses(fn() => IspCredit::decide($adj, true, 'ok'), 'permission', 'a billing officer cannot approve an adjustment');
    // an approver with a limit
    isp_t_as($GLOBALS['adminId']);
    $rid = DB::insert('roles', ['name' => 'Test Approver 1000', 'description' => 'Approves up to 1,000', 'is_admin' => 0, 'is_system' => 0, 'all_sites' => 1]);
    DB::insert('role_special_permissions', ['role_id' => $rid, 'perm_key' => 'isp_write_off', 'perm_value' => '1']);
    DB::insert('role_special_permissions', ['role_id' => $rid, 'perm_key' => 'isp_max_adjustment', 'perm_value' => '400']);
    $uid = isp_t_user('approver400', 'Limited Approver', ['Test Approver 1000']);
    isp_t_as($uid);
    refuses(fn() => IspCredit::decide($adj, true, 'ok'), 'approval limit', 'an adjustment above the approver\'s limit');
    $wo = IspCredit::request($acct, (int)$inv['id'], 'write_off', 100, 'Uncollectible part (test)');
    refuses(fn() => IspCredit::decide($wo, true, 'ok'), 'someone other than', 'a write-off approved by the user who asked for it');
    isp_t_as($ids['user.acct']);
    IspCredit::decide($adj, true, 'Approved by accounting');
    IspCredit::decide($wo, true, 'Approved by accounting');
    eq(DB::value('SELECT status FROM isp_adjustments WHERE id = :a', ['a' => $adj]), 'posted', 'the accountant (no limit) approves and posts it');
    eqm(isp_t_invoice((int)$inv['id'])['balance'], (float)$inv['total'] - 600, 'the credits reduce the invoice');
    isp_t_as($GLOBALS['adminId']);
});

test('R22 Gateway duplicate webhooks cannot double-post a payment', function () {
    $acct = isp_t_account(['date' => today()]);
    $a = isp_t_acct($acct);
    $total = (float)isp_t_invoices($acct)[0]['total'];
    Settings::set('isp_gateway_provider', 'generic');
    Settings::set('isp_gateway_secret_enc', (string)IspCrypto::encrypt('sk_test_generic_123'));
    Settings::set('isp_gateway_webhook_secret_enc', (string)IspCrypto::encrypt('whsec_test_456'));
    Settings::flush();
    $body = json_encode(['event_id' => 'evt_r22_1', 'type' => 'payment', 'status' => 'paid', 'account_no' => $a['account_no'], 'amount' => $total, 'fee' => 25.50, 'reference' => 'GW-R22-1']);
    $sig = hash_hmac('sha256', $body, 'whsec_test_456');
    [$code, $msg] = IspGateway::handle('generic', $body, ['X-Signature' => $sig], '203.0.113.5');
    eq([$code, $msg], [200, 'Posted'], 'the signed webhook posts the payment');
    [$code, $msg] = IspGateway::handle('generic', $body, ['X-Signature' => $sig], '203.0.113.5');
    eq([$code, $msg], [200, 'Duplicate event ignored'], 'the same event again is ignored');
    $pays = DB::all("SELECT * FROM isp_payments WHERE account_id = :a AND channel = 'gateway'", ['a' => $acct]);
    eq(count($pays), 1, 'one payment');
    eq([$pays[0]['status'], $pays[0]['source']], ['posted', 'gateway'], 'posted from the gateway');
    eqm($pays[0]['gateway_fee'], 25.50, 'the gateway fee is kept');
    $sched = (int)DB::value("SELECT id FROM users WHERE username = 'isp.scheduler'");
    eq((int)$pays[0]['created_by'], $sched, 'the webhook payment is recorded by the scheduler account (no person posted it)');
    eq(DB::value("SELECT username FROM audit_trail WHERE record_type = 'isp_payment' AND record_id = :p AND action = 'create'", ['p' => $pays[0]['id']]), 'isp.scheduler', 'and audited under it');
    eq(Auth::id(), $GLOBALS['adminId'], 'the logged-in user is back after the webhook');
    check(Auth::$systemRun === false, 'the scheduler account is accepted only during the job');
    isp_t_as($sched);
    check(Auth::id() === null, 'the scheduler account cannot be used outside a job (it is locked)');
    isp_t_as($GLOBALS['adminId']);
    check(isp_t_posting('FEE-' . $pays[0]['id']) !== null, 'the fee is posted once: Dr gateway fees, Cr the clearing account');
    eq(isp_t_invoices($acct)[0]['status'], 'paid', 'the invoice is paid');
    // another event id for the same payment reference is refused by the reference check
    $body2 = json_encode(['event_id' => 'evt_r22_2', 'status' => 'paid', 'account_no' => $a['account_no'], 'amount' => $total, 'reference' => 'GW-R22-1']);
    [$code, $msg] = IspGateway::handle('generic', $body2, ['X-Signature' => hash_hmac('sha256', $body2, 'whsec_test_456')], '203.0.113.5');
    check($code === 200 && str_contains($msg, 'already recorded'), 'a replay with a new event id is recorded, not posted (' . $msg . ')');
    eq((int)DB::value("SELECT COUNT(*) FROM isp_payments WHERE account_id = :a AND channel = 'gateway'", ['a' => $acct]), 1, 'still one payment');
    // a wrong signature posts nothing
    $body3 = json_encode(['event_id' => 'evt_r22_3', 'status' => 'paid', 'account_no' => $a['account_no'], 'amount' => 999, 'reference' => 'GW-R22-3']);
    [$code] = IspGateway::handle('generic', $body3, ['X-Signature' => hash_hmac('sha256', $body3, 'wrong-secret')], '198.51.100.9');
    eq($code, 401, 'a forged webhook is refused');
    eq(DB::value("SELECT status FROM isp_gateway_events WHERE event_id LIKE 'unverified:%:evt_r22_3'"), 'rejected', 'and logged as rejected');
    check(!DB::value("SELECT id FROM isp_payments WHERE reference_no = 'GW-R22-3'"), 'no payment from it');
    // a forged notice sent first with the real event id cannot block the provider's real notice
    $body4 = json_encode(['event_id' => 'evt_r22_4', 'status' => 'paid', 'account_no' => $a['account_no'], 'amount' => 100, 'reference' => 'GW-R22-4']);
    IspGateway::handle('generic', $body4, ['X-Signature' => 'forged'], '198.51.100.9');
    [$code, $msg] = IspGateway::handle('generic', $body4, ['X-Signature' => hash_hmac('sha256', $body4, 'whsec_test_456')], '203.0.113.5');
    eq([$code, $msg], [200, 'Posted'], 'the real notice after a forged one still posts');
    eqm(IspPayments::get((int)DB::value("SELECT payment_id FROM isp_gateway_events WHERE event_id = 'evt_r22_4'"))['unapplied_amount'], 100, 'an advance payment kept as credit');
    [$code] = IspGateway::handle('paymongo', $body, [], '198.51.100.9');
    eq($code, 409, 'a provider that is not the configured one is refused');
    Settings::set('isp_gateway_provider', 'none');
    Settings::flush();
});

test('R23 Billing disputes follow the approval and protection policies', function () {
    global $ids;
    $acct = isp_t_account(['date' => today()]);
    isp_t_age($acct, 12);
    $inv = isp_t_invoices($acct)[0];
    $d = IspCredit::openDispute($acct, (int)$inv['id'], 1000, 'Installation fee was waived by the agent (test)', true);
    eq(isp_t_invoice((int)$inv['id'])['status'], 'disputed', 'the invoice is marked disputed');
    check(IspDisconnect::evaluateAccount($acct) === null, 'the protected dispute holds the disconnection');
    IspCredit::noteDispute($d, 'Agent confirmed the waiver');
    eq(DB::value('SELECT status FROM isp_disputes WHERE id = :d', ['d' => $d]), 'investigating', 'under investigation');
    isp_t_as($ids['user.billing']);
    IspCredit::resolveDispute($d, 'resolved_credit', 1000, 'Installation fee waived as promised');
    isp_t_as($GLOBALS['adminId']);
    $dr = DB::row('SELECT * FROM isp_disputes WHERE id = :d', ['d' => $d]);
    eq($dr['status'], 'resolved_credit', 'resolved with a credit');
    $adj = DB::row('SELECT * FROM isp_adjustments WHERE id = :a', ['a' => $dr['adjustment_id']]);
    eq([$adj['adj_type'], $adj['status']], ['dispute_credit', 'pending'], 'the credit waits for approval like any adjustment');
    eqm(isp_t_invoice((int)$inv['id'])['balance'], $inv['balance'], 'nothing is credited before the approval');
    isp_t_as($ids['user.billing']);
    refuses(fn() => IspCredit::decide((int)$adj['id'], true, 'ok'), 'permission', 'the billing officer cannot approve the dispute credit');
    isp_t_as($ids['user.acct']);
    IspCredit::decide((int)$adj['id'], true, 'Approved');
    isp_t_as($GLOBALS['adminId']);
    $i2 = isp_t_invoice((int)$inv['id']);
    eqm($i2['balance'], (float)$inv['balance'] - 1000, 'the approved credit reduces the invoice');
    eqm($i2['credit_amount'], 1000, 'credited amount');
    eq($i2['status'], 'overdue', 'the dispute is over: the rest is overdue again');
    $p = isp_t_posting('ADJ-' . $adj['id']);
    check($p && $p['txn_type'] === 'credit_memo', 'the credit is a credit memo in the books');
    check(IspDisconnect::evaluateAccount($acct) !== null, 'the protection ended with the dispute: the remaining arrears qualify');
    refuses(fn() => IspCredit::resolveDispute($d, 'resolved_no_credit', 0, 'again'), 'already resolved', 'a resolved dispute is not resolved again');
});

test('R24 Failed scheduled jobs can be retried safely', function () {
    $runs0 = (int)DB::value("SELECT COUNT(*) FROM isp_job_runs WHERE job_code = 'arrangements'");
    // the job meets a database error (a table it reads is missing)
    DB::pdo()->exec('RENAME TABLE isp_arrangement_lines TO isp_arrangement_lines_off');
    $log = ini_set('error_log', sys_get_temp_dir() . '/isp_tests_expected_errors.log');   // the job logs the error it met
    try {
        $r = IspJobs::run('arrangements', 'test');
    } finally {
        DB::pdo()->exec('RENAME TABLE isp_arrangement_lines_off TO isp_arrangement_lines');
        ini_set('error_log', $log === false ? '' : $log);
    }
    eq($r['status'], 'failed', 'the job run is recorded as failed');
    $j = DB::row("SELECT * FROM isp_jobs WHERE job_code = 'arrangements'");
    eq([$j['last_status'], (int)$j['fail_count']], ['failed', 1], 'the job remembers the failure');
    check($j['locked_until'] === null && $j['lock_token'] === null, 'the failed run released its claim');
    check(!IspJobs::isDue($j), 'it is not retried at once');
    check(IspJobs::isDue($j, time() + 301), 'it is retried at the next turn (after 5 minutes)');
    $r2 = IspJobs::run('arrangements', 'test');
    eq($r2['status'], 'ok', 'the retry succeeds');
    $j = DB::row("SELECT * FROM isp_jobs WHERE job_code = 'arrangements'");
    eq([$j['last_status'], (int)$j['fail_count']], ['ok', 0], 'the failure count is reset');
    $runs = DB::all("SELECT status FROM isp_job_runs WHERE job_code = 'arrangements' ORDER BY id");
    eq(array_slice(array_column($runs, 'status'), $runs0), ['failed', 'ok'], 'both runs are logged');
    // rerunning jobs changes nothing twice
    $before = [(int)DB::value('SELECT COUNT(*) FROM isp_invoices'), (int)DB::value('SELECT COUNT(*) FROM isp_charges'), (int)DB::value('SELECT COUNT(*) FROM isp_notifications'),
        (int)DB::value('SELECT COUNT(*) FROM isp_disc_queue')];
    foreach (['due_check', 'late_fees', 'reminders', 'disc_eval'] as $code) {
        IspJobs::run($code, 'test');
    }
    $mid = [(int)DB::value('SELECT COUNT(*) FROM isp_invoices'), (int)DB::value('SELECT COUNT(*) FROM isp_charges'), (int)DB::value('SELECT COUNT(*) FROM isp_notifications'),
        (int)DB::value('SELECT COUNT(*) FROM isp_disc_queue')];
    foreach (['due_check', 'late_fees', 'reminders', 'disc_eval'] as $code) {
        IspJobs::run($code, 'test');
    }
    $after = [(int)DB::value('SELECT COUNT(*) FROM isp_invoices'), (int)DB::value('SELECT COUNT(*) FROM isp_charges'), (int)DB::value('SELECT COUNT(*) FROM isp_notifications'),
        (int)DB::value('SELECT COUNT(*) FROM isp_disc_queue')];
    eq($after, $mid, 'a second run of the same jobs adds no invoice, charge, notification or queue item');
    check($mid[0] === $before[0], 'the due-date jobs make no invoices');
});

test('R25 Reports reconcile with the underlying transactions', function () {
    global $ids;
    // the subscriber ledger computed from the source records equals the A/R account
    $invoiced = (float)DB::value("SELECT COALESCE(SUM(total), 0) FROM isp_invoices WHERE status NOT IN ('draft', 'approved', 'voided')");
    $paid = (float)DB::value("SELECT COALESCE(SUM(amount), 0) FROM isp_payments WHERE status = 'posted'");
    $credits = (float)DB::value("SELECT COALESCE(SUM(amount), 0) FROM isp_adjustments WHERE status = 'posted' AND adj_type <> 'debit'");
    eqm($invoiced - $paid - $credits, isp_t_gl(Accts::get('isp_ar')), 'invoices posted - payments posted - credits posted = A/R - Subscribers in the general ledger');
    $open = (float)DB::value("SELECT COALESCE(SUM(balance), 0) FROM isp_invoices WHERE status IN ('posted', 'partially_paid', 'overdue', 'disputed')");
    $k = IspDashboard::kpis();
    eqm($k['receivable'], $open, 'dashboard receivables = the open invoices');
    eqm(array_sum(IspDashboard::aging()), $open, 'the aging buckets add up to the receivables');
    eqm($k['collected_today'], DB::value("SELECT COALESCE(SUM(amount), 0) FROM isp_payments WHERE status = 'posted' AND pay_date = :d", ['d' => today()]), 'collections today');
    eqm($k['unapplied'], DB::value("SELECT COALESCE(SUM(unapplied_amount), 0) FROM isp_payments WHERE status = 'posted'"), 'unapplied credits');
    eq($k['active'], (int)DB::value("SELECT COUNT(*) FROM isp_accounts WHERE service_status = 'active'"), 'active subscribers');
    $shift = $ids['shift.admin'];
    $tot = IspCashier::totals($shift);
    eqm(array_sum(array_map(fn($x) => $x[1], $tot)), DB::value("SELECT COALESCE(SUM(amount), 0) FROM isp_payments WHERE shift_id = :s AND status = 'posted'", ['s' => $shift]), 'the cashier shift totals = its posted payments');
    // each account: its balance = its open invoices less its credit, and the books of its customer
    foreach (DB::column("SELECT id FROM isp_accounts WHERE service_status <> 'pending' ORDER BY id LIMIT 40") as $id) {
        $b = IspAccounts::balances((int)$id);
        eqm($b['balance'], isp_t_gl_ar_of((int)$id), 'account ' . $id . ': the subscriber balance = its A/R in the books');
    }
    $r = IspPosting::reconcile();
    eqm($r['difference'], 0, 'the reconciliation report shows no difference');
    // the report engine's ISP reports, when they are installed (app/reports/isp.php)
    $ran = 0;
    try {
        foreach (['isp_aging', 'isp_reconcile', 'isp_unpaid', 'isp_daily_collection'] as $key) {
            $def = Report::get($key);
            if (!$def) {
                continue;
            }
            $_GET = ['asof' => today(), 'dates' => 'today'];
            $out = ($def['run'])(Report::filters($def));
            check(isset($out['rows']), "report $key runs");
            $ran++;
        }
    } catch (Throwable $e) {
        check(false, 'an ISP report failed: ' . $e->getMessage());
    } finally {
        $_GET = [];
    }
    check(true, $ran . ' report(s) of the report engine ran');
});
