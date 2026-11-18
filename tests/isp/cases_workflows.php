<?php
declare(strict_types=1);

/** WA-WI: the end-to-end business workflows of the specification (section 35), step by step */

test('WA Workflow A: new subscriber from application to activation, initial billing and notification', function () {
    global $ids;
    $wh0 = Stock::onHand($ids['item.ONU-HG8145'], $ids['site.WH']);
    // Application
    $app = IspApplications::create(['last_name' => 'Garcia', 'first_name' => 'Lito', 'mobile' => '09185551234', 'email' => 'lito.garcia@example.com',
        'install_address' => 'Lot 4 Blk 2, San Roque, San Isidro', 'landmark' => 'Near the chapel', 'plan_id' => $ids['plan.F100'], 'area_id' => $ids['area.SR'], 'privacy_consent' => 1], 'online');
    // Verification
    IspApplications::move($app, 'for_verification', 'ID uploaded on the portal');
    IspApplications::verifyIdentity($app, 'UMID', 'CRN-0111-2222333-4');
    eq(IspApplications::get($app)['status'], 'site_survey', 'verified, at the survey');
    // Site survey: coverage, the NAP port reserved, the quotation
    $port = (int)DB::value("SELECT id FROM isp_net_ports WHERE node_id = :n AND status = 'free' ORDER BY port_no LIMIT 1", ['n' => $ids['nap.1']]);
    IspApplications::survey($app, ['coverage_ok' => 1, 'survey_feasible' => 1, 'port_id' => $port, 'est_cable_m' => 150, 'quote_materials' => 250, 'survey_notes' => '150 m from NAP-SR-01']);
    $a = IspApplications::get($app);
    eq([(float)$a['quote_installation'], (float)$a['quote_materials'], (float)$a['quote_deposit'], (float)$a['quote_total']], [1500.0, 250.0, 1000.0, 2750.0], 'the quotation');
    // Approval
    IspApplications::acceptContract($app, 'Lito Garcia');
    $r = IspApplications::approve($app, 'Requirements complete');
    $acct = (int)$r['account_id'];
    $cust = IspAccounts::customer((int)$r['customer_id']);
    check($cust['party_id'] > 0, 'the subscriber is on the Party Master');
    eq(IspApplications::get($app)['status'], 'for_installation', 'approved: for installation');
    check(count(isp_t_notes($acct, 'application_approved')) > 0, 'the applicant is told of the approval');
    // Installation scheduling
    IspWorkOrders::schedule((int)$r['work_order_id'], $ids['tech.1'], today(), 'AM', 1);
    eq(DB::value('SELECT status FROM isp_work_orders WHERE id = :w', ['w' => $r['work_order_id']]), 'scheduled', 'scheduled');
    check(count(isp_t_notes($acct, 'installation_schedule')) > 0, 'the subscriber is told of the schedule');
    // Equipment issuance
    IspWorkOrders::issue((int)$r['work_order_id'], [[$ids['item.ONU-HG8145'], 1, 'HWTCWA0001'], [$ids['item.DROP-1C'], 160]]);
    eqm(Stock::onHand($ids['item.ONU-HG8145'], $ids['site.WH']), $wh0 - 1, 'the ONU left the stockroom');
    // Technician installation (on the technician's own login)
    isp_t_as($ids['user.tech']);
    check(IspSetup::myTechnicianId() === $ids['tech.1'], 'the technician\'s own record');
    IspWorkOrders::step((int)$r['work_order_id'], 'accepted');
    IspWorkOrders::step((int)$r['work_order_id'], 'in_progress');
    $mats = [];
    foreach (DB::all('SELECT id, item_id FROM isp_wo_materials WHERE work_order_id = :w', ['w' => $r['work_order_id']]) as $m) {
        $mats[(int)$m['id']] = [(int)$m['item_id'] === $ids['item.DROP-1C'] ? 150 : 1, null];
    }
    IspWorkOrders::complete((int)$r['work_order_id'], ['checklist' => range(0, 6), 'cable_used_m' => 150, 'rx_power_dbm' => -21.3, 'tx_power_dbm' => 2.1, 'speed_test_down' => 98,
        'speed_test_up' => 95, 'customer_accepted_by' => 'Lito Garcia', 'onu_serial' => 'HWTCWA0001', 'materials' => $mats, 'findings' => 'Installed, signal good']);
    isp_t_as($ids['user.supervisor']);
    IspWorkOrders::verify((int)$r['work_order_id'], 'Photos and readings checked');
    isp_t_as($GLOBALS['adminId']);
    eq(DB::value('SELECT status FROM isp_work_orders WHERE id = :w', ['w' => $r['work_order_id']]), 'verified', 'installation verified by the supervisor');
    eq(IspApplications::get($app)['status'], 'for_activation', 'ready for activation');
    $eq = DB::row("SELECT * FROM isp_equipment WHERE serial_no = 'HWTCWA0001'");
    eq([$eq['status'], (int)$eq['account_id']], ['installed', $acct], 'the ONU is installed at the subscriber');
    // Network provisioning + Activation + Initial billing
    $res = IspAccounts::activate($acct, today());
    $op = DB::row('SELECT * FROM isp_net_ops WHERE id = :o', ['o' => $res['net_op_id']]);
    eq($op['state'], 'confirmed', 'provisioned on the device');
    check((bool)DB::value("SELECT id FROM isp_net_logs WHERE op_id = :o AND command LIKE 'SIM provision%'", ['o' => $op['id']]), 'a new subscriber is provisioned (not merely enabled)');
    $a = isp_t_acct($acct);
    eq([$a['service_status'], $a['network_state']], ['active', 'enabled'], 'active and enabled');
    eq($a['lock_in_until'], date('Y-m-d', strtotime(IspPlans::addMonths(today(), 12) . ' -1 day')), 'the 12-month lock-in');
    $inv = isp_t_invoice((int)$res['invoice_id']);
    $dim = days_in_month(today());
    $day = (int)date('j');
    $sub = $day === 1 ? 2000.0 : round(2000 * ($dim - $day + 1) / $dim, 2);
    eqm($inv['total'], 1500 + 250 + 1000 + $sub, 'initial invoice: installation 1,500 + quoted materials 250 + deposit 1,000 + the first period');
    $dep = DB::row("SELECT * FROM isp_invoice_lines WHERE invoice_id = :i AND line_type = 'deposit'", ['i' => $inv['id']]);
    eq([(float)$dep['amount'], (float)$dep['vat_amount'], (int)$dep['income_account_id']], [1000.0, 0.0, Accts::get('isp_deposits')], 'the deposit is a VAT-free liability, not revenue');
    // Customer notification
    check(count(isp_t_notes($acct, 'service_activated')) > 0 && count(isp_t_notes($acct, 'invoice_generated')) > 0, 'activation and invoice notices');
    // the subscriber pays: the deposit is now held
    $depGl0 = isp_t_gl(Accts::get('isp_deposits'));
    isp_t_pay($acct, (float)$inv['total'], 'cash');
    eq(isp_t_acct($acct)['deposit_status'], 'held', 'the paid deposit is held');
    eqm(isp_t_gl(Accts::get('isp_deposits')), $depGl0, 'the deposit liability was booked with the invoice');
    check(count(array_filter(IspAccounts::ofParty((int)$cust['party_id']), fn($x) => (int)$x['id'] === $acct)) === 1, 'the account shows on the party (cooperative and accounting see it)');
    eq(IspApplications::get($app)['status'], 'active', 'the application is closed');
});

test('WB Workflow B: normal monthly billing, approval, posting, notification, collection and accounting', function () {
    global $ids;
    $acct = isp_t_account(['date' => ymd(2, 12), 'cycle_day' => 12]);
    isp_t_pay($acct, (float)isp_t_invoices($acct)[0]['total']);
    $bill = ymd(1, 12);
    // Billing schedule: the preview
    $prev = array_values(array_filter(IspBilling::preview($bill, 12), fn($r) => (int)$r['account']['id'] === $acct));
    check(count($prev) === 1 && $prev[0]['duplicate'] === null, 'the account is due on its cycle day');
    eqm($prev[0]['calc']['total'], 1500, 'the preview total');
    $ar0 = isp_t_gl_ar_of($acct);
    $rev = Accts::get('isp_revenue');
    $rev0 = isp_t_gl($rev);
    // Invoice generation (a draft run that needs approval)
    isp_t_as($ids['user.billing']);
    $run = IspBilling::generate($bill, 12, null, 'manual', false);
    $inv = DB::row("SELECT * FROM isp_invoices WHERE account_id = :a AND kind = 'regular'", ['a' => $acct]);
    eq([$inv['status'], $inv['invoice_no']], ['draft', 'D-' . $inv['id']], 'a numbered draft (no official number yet)');
    check(isp_t_posting('INV-' . $inv['id']) === null, 'nothing in the books yet');
    eqm(isp_t_gl_ar_of($acct), $ar0, 'the receivable is unchanged by a draft');
    refuses(fn() => IspBilling::approveRun($run), 'permission', 'the billing officer does not approve the run');
    // Invoice approval / posting
    isp_t_as($ids['user.acct']);
    IspBilling::approveRun($run);
    eq(isp_t_invoice((int)$inv['id'])['status'], 'approved', 'approved');
    $next = (int)DB::value("SELECT next_no FROM sequences WHERE seq_key = 'isp_invoice'");
    $res = IspBilling::postRun($run);
    eq($res['posted'], 1, 'posted');
    $inv = isp_t_invoice((int)$inv['id']);
    eq([$inv['status'], $inv['invoice_no']], ['posted', 'SI-' . str_pad((string)$next, 7, '0', STR_PAD_LEFT)], 'posted with the next official number');
    eq(DB::value('SELECT status FROM isp_billing_runs WHERE id = :r', ['r' => $run]), 'posted', 'the run is posted');
    // Customer notification
    check(count(array_filter(isp_t_notes($acct, 'invoice_generated'), fn($n) => str_contains((string)$n['body'], (string)$inv['invoice_no']))) > 0, 'the invoice notice names the invoice');
    // Accounting posting of the invoice
    eqm(isp_t_gl_ar_of($acct) - $ar0, 1500, 'Dr A/R - Subscribers 1,500');
    [$net] = vat_incl(1500);
    eqm($rev0 - isp_t_gl($rev), $net, 'Cr internet service revenue (net of VAT)');
    // Payment collection at the counter, in a cashier shift
    isp_t_as($ids['user.cashier']);
    $shift = IspCashier::open($ids['user.cashier'], Accts::get('isp_cash'), 500, $ids['branch.HO']);
    $cash0 = isp_t_gl(Accts::get('isp_cash'));
    $pid = IspPayments::collect($acct, 1500, 'cash', ['allocations' => [(int)$inv['id'] => 1500]]);
    // Invoice allocation + ledger update
    $p = IspPayments::get($pid);
    eq([(int)$p['shift_id'], $p['status']], [$shift, 'posted'], 'collected in the shift');
    eqm(DB::value('SELECT amount FROM isp_payment_allocs WHERE payment_id = :p AND invoice_id = :i', ['p' => $pid, 'i' => $inv['id']]), 1500, 'allocated to the invoice');
    eq(isp_t_invoice((int)$inv['id'])['status'], 'paid', 'the invoice is paid');
    eqm(IspAccounts::balances($acct)['balance'], 0, 'the subscriber ledger is settled');
    eqm(isp_t_gl(Accts::get('isp_cash')) - $cash0, 1500, 'Dr cash on hand 1,500');
    eqm(isp_t_gl_ar_of($acct), $ar0, 'Cr A/R 1,500');
    $c = IspCashier::close($shift, ['1000' => 2]);
    eq([$c['expected'], $c['counted'], $c['over_short']], [2000.0, 2000.0, 0.0], 'the cash count balances (500 opening + 1,500)');
    check(isp_t_posting('OVS-' . $shift) === null, 'nothing over or short');
    isp_t_as($ids['user.acct']);
    IspCashier::certify($shift, 'Counted with the cashier');
    $txn = IspCashier::remit($shift, $ids['bank'], 2000, 'DS-0001');
    check($txn > 0, 'the shift cash is remitted to the bank');
    isp_t_as($GLOBALS['adminId']);
});

test('WC Workflow C: unpaid subscriber from overdue invoice to disconnection', function () {
    global $ids;
    $acct = isp_t_account(['date' => today()]);
    $inv = isp_t_invoices($acct)[0];
    // Reminder before the due date
    DB::run('UPDATE isp_invoices SET due_date = :d WHERE id = :i', ['d' => date('Y-m-d', strtotime('+' . IspSetup::int('isp_reminder_days') . ' days')), 'i' => $inv['id']]);
    IspJobs::run('reminders', 'test');
    check(count(isp_t_notes($acct, 'due_reminder')) > 0, 'the due-date reminder');
    // Invoice overdue
    isp_t_age($acct, 3);
    IspJobs::run('due_check', 'test');
    eq(isp_t_invoice((int)$inv['id'])['status'], 'overdue', 'overdue');
    IspJobs::run('reminders', 'test');
    check(count(isp_t_notes($acct, 'payment_overdue')) > 0, 'the overdue reminder');
    // Grace period: not yet
    IspJobs::run('disc_eval', 'test');
    check(isp_t_queue($acct) === null, 'inside the grace period nothing is queued');
    // Eligibility check after the grace period
    isp_t_age($acct, 8);
    IspJobs::run('disc_eval', 'test');
    $q = isp_t_queue($acct);
    eq($q['status'], 'candidate', 'eligible: a candidate in the disconnection queue');
    IspJobs::run('late_fees', 'test');
    check((bool)DB::value("SELECT id FROM isp_charges WHERE account_id = :a AND charge_type = 'late_fee'", ['a' => $acct]), 'the late charge is assessed');
    // Final notice
    IspDisconnect::approve([(int)$q['id']]);
    eq(isp_t_queue($acct)['status'], 'notified', 'approved, final notice sent');
    check(count(isp_t_notes($acct, 'final_notice')) > 0, 'the final notice reached the queue of messages');
    // Disconnection queue + network disconnection (the scheduler, once the notice period is over)
    IspJobs::run('disc_execute', 'test');
    eq(isp_t_acct($acct)['service_status'], 'active', 'not before the notice period ends');
    DB::run('UPDATE isp_disc_queue SET execute_after = :t WHERE id = :q', ['t' => date('Y-m-d H:i:s', time() - 60), 'q' => $q['id']]);
    $r = IspJobs::run('disc_execute', 'test');
    eq($r['status'], 'ok', 'the execution job ran');
    // Verification: the device is read back
    $a = isp_t_acct($acct);
    $st = IspNetwork::adapter(IspNetwork::device((int)$a['device_id']))->status($a);
    eq($st['state'], 'disabled', 'the device reads the subscriber disabled');
    // Service status update
    eq([$a['service_status'], $a['network_state']], ['disconnected', 'disabled'], 'disconnected');
    check(count(isp_t_notes($acct, 'service_disconnected')) > 0, 'the subscriber is told');
    eq((int)DB::value("SELECT created_by FROM isp_disc_queue WHERE id = :q", ['q' => $q['id']]), (int)DB::value("SELECT id FROM users WHERE username = 'isp.scheduler'"), 'queued by the scheduler account');
});

test('WD Workflow D: subscriber reconnection after a verified payment', function () {
    $acct = isp_t_account(['date' => today()]);
    isp_t_age($acct, 10);
    IspDisconnect::evaluateAccount($acct);
    isp_t_disconnect_now($acct);
    eq(isp_t_acct($acct)['service_status'], 'disconnected', 'disconnected');
    $bal = IspAccounts::balances($acct)['balance'];
    // a reference that does not check out is rejected and changes nothing
    $bad = isp_t_pay($acct, $bal, 'gcash', ['status' => 'pending', 'source' => 'portal', 'reference_no' => 'GC-FAKE-01']);
    IspPayments::verify($bad, false, 'No such GCash reference on the statement');
    eq(IspPayments::get($bad)['status'], 'rejected', 'rejected');
    eq(isp_t_acct($acct)['service_status'], 'disconnected', 'still disconnected');
    // Payment verification
    $p = isp_t_pay($acct, $bal, 'gcash', ['status' => 'pending', 'source' => 'portal', 'reference_no' => 'GC-0091-7781']);
    check(count(isp_t_notes($acct, 'payment_pending')) > 0, 'the subscriber is told the reference was received');
    eq(isp_t_acct($acct)['service_status'], 'disconnected', 'an unverified reference does not reconnect');
    IspPayments::verify($p, true, 'Matched with the GCash statement');
    // Receivable settlement
    eqm(IspAccounts::balances($acct)['overdue'], 0, 'the arrears are settled');
    // Eligibility, reconnection queue, network restoration, verification
    $r = DB::row('SELECT * FROM isp_reconnections WHERE account_id = :a ORDER BY id DESC LIMIT 1', ['a' => $acct]);
    eq([$r['recon_type'], $r['status']], ['automatic', 'restored'], 'reconnected automatically and confirmed');
    $a = isp_t_acct($acct);
    eq(IspNetwork::adapter(IspNetwork::device((int)$a['device_id']))->status($a)['state'], 'enabled', 'the device reads the subscriber enabled');
    eq([$a['service_status'], $a['network_state']], ['active', 'enabled'], 'active again');
    // Notification
    check(count(isp_t_notes($acct, 'payment_received')) > 0 && count(isp_t_notes($acct, 'service_restored')) > 0, 'payment and restoration notices');
});

test('WE Workflow E: connectivity complaint from ticket to closure', function () {
    global $ids;
    $acct = isp_t_account(['date' => today()]);
    $wh0 = Stock::onHand($ids['item.DROP-1C'], $ids['site.WH']);
    // Customer ticket
    $t = IspTickets::create(['account_id' => $acct, 'ticket_type' => 'slow', 'subject' => 'Slow every evening', 'description' => 'Below 5 Mbps from 7 to 11 PM', 'source' => 'phone']);
    $tk = IspTickets::get($t);
    eq([$tk['status'], $tk['priority']], ['new', 'normal'], 'a new normal-priority ticket');
    check(abs(strtotime($tk['sla_due_at']) - (time() + 24 * 3600)) < 120, 'the SLA deadline is 24 hours for normal priority');
    // Troubleshooting (remote) and classification
    IspTickets::move($t, 'classified', ['note' => 'Remote check: high latency, line errors', 'priority' => 'high']);
    eq(IspTickets::get($t)['priority'], 'high', 'raised to high priority');
    $op = IspNetwork::request($acct, 'reset_session', 'Ticket troubleshooting', 'ticket', $t);
    eq(DB::value('SELECT state FROM isp_net_ops WHERE id = :o', ['o' => $op]), 'confirmed', 'the remote session reset is confirmed');
    // Technician assignment
    $wo = IspTickets::dispatch($t, $ids['tech.2'], today(), 'PM');
    eq(DB::value('SELECT wo_type FROM isp_work_orders WHERE id = :w', ['w' => $wo]), 'repair', 'a repair work order');
    eq([IspTickets::get($t)['status'], (int)IspTickets::get($t)['technician_id']], ['assigned', $ids['tech.2']], 'the ticket is assigned to the technician');
    refuses(fn() => IspTickets::dispatch($t, $ids['tech.2'], today()), 'already has work order', 'a second dispatch of the same ticket');
    // Materials issuance (the maintenance technician works from the stockroom)
    IspWorkOrders::issue($wo, [[$ids['item.DROP-1C'], 30]]);
    // Repair (on the technician's login)
    isp_t_as($ids['user.tech2']);
    IspWorkOrders::step($wo, 'in_progress');
    eq(IspTickets::get($t)['status'], 'in_progress', 'the ticket follows the work order');
    $mid = (int)DB::value('SELECT id FROM isp_wo_materials WHERE work_order_id = :w', ['w' => $wo]);
    IspWorkOrders::complete($wo, ['checklist' => [0, 1, 2, 3], 'materials' => [$mid => [25, null]], 'findings' => 'Damaged drop segment replaced', 'rx_power_dbm' => -20.4]);
    isp_t_as($GLOBALS['adminId']);
    eqm(Stock::onHand($ids['item.DROP-1C'], $ids['site.WH']), $wh0 - 25, '25 m consumed from the stockroom, 5 m back');
    check(isp_t_posting('USE-' . $wo) !== null, 'the materials are charged to maintenance');
    // Verification
    isp_t_as($ids['user.supervisor']);
    IspWorkOrders::verify($wo, 'Customer called back: speed OK');
    // Customer confirmation and closure
    refuses(fn() => IspTickets::move($t, 'resolved', ['resolution' => 'Fixed']), 'root cause', 'a technical ticket needs its root cause');
    IspTickets::move($t, 'resolved', ['resolution' => 'Drop cable replaced, 48 Mbps measured', 'root_cause' => 'Damaged drop cable (rodent)']);
    check(count(isp_t_notes($acct, 'ticket_resolved')) > 0, 'the subscriber is told it is resolved');
    IspTickets::move($t, 'verified', ['note' => 'Subscriber confirmed by phone']);
    IspTickets::move($t, 'closed', ['rating' => 5, 'feedback' => 'Fast service']);
    isp_t_as($GLOBALS['adminId']);
    $tk = IspTickets::get($t);
    eq([$tk['status'], (int)$tk['feedback_rating']], ['closed', 5], 'closed with the subscriber\'s rating');
    check($tk['resolved_at'] && $tk['closed_at'], 'the times are kept for the resolution-time report');
    check((int)DB::value('SELECT COUNT(*) FROM isp_ticket_notes WHERE ticket_id = :t', ['t' => $t]) >= 6, 'every step is noted');
});

test('WF Workflow F: equipment replacement with the old unit returned', function () {
    global $ids;
    $acct = isp_t_account(['date' => today(), 'with_materials' => 1]);
    $old = DB::row("SELECT * FROM isp_equipment WHERE account_id = :a AND status = 'installed'", ['a' => $acct]);
    check($old !== null, 'the subscriber has an installed ONU');
    // Problem report + equipment assessment
    $t = IspTickets::create(['account_id' => $acct, 'ticket_type' => 'broken_device', 'subject' => 'ONU keeps rebooting', 'source' => 'walkin']);
    IspTickets::move($t, 'classified', ['note' => 'Assessed: power board failure, replacement needed']);
    // Replacement approval: the supervisor dispatches the replacement work order
    isp_t_as($ids['user.supervisor']);
    $wo = IspTickets::dispatch($t, $ids['tech.1'], today(), 'AM');
    eq(DB::value('SELECT wo_type FROM isp_work_orders WHERE id = :w', ['w' => $wo]), 'replacement', 'a replacement work order');
    // Warehouse issuance
    IspWorkOrders::issue($wo, [[$ids['item.ONU-HG8145'], 1, 'HWTCNEW0001']]);
    // Device installation, network configuration, old equipment return
    isp_t_as($ids['user.tech']);
    $mid = (int)DB::value('SELECT id FROM isp_wo_materials WHERE work_order_id = :w', ['w' => $wo]);
    $onHand0 = Stock::onHand($ids['item.ONU-HG8145'], $ids['site.WH']);
    IspWorkOrders::complete($wo, ['checklist' => [0, 1, 2, 3], 'materials' => [$mid => [1, null]], 'recovered' => [(int)$old['id'] => 'recovered'], 'findings' => 'ONU replaced']);
    // the returned unit is back on hand in the stockroom, and the installation cost it was charged to is reversed
    eqm(Stock::onHand($ids['item.ONU-HG8145'], $ids['site.WH']) - $onHand0, 1, 'the recovered ONU is counted in the stockroom again');
    $ret = IspPosting::byEvent('EQR-' . $old['id'] . '-' . (int)DB::value("SELECT MAX(id) FROM isp_equipment_log WHERE equipment_id = :e AND to_status = 'recovered'", ['e' => $old['id']]));
    check($ret !== null, 'the return is posted once (inventory adjustment)');
    eqm((float)DB::value('SELECT COALESCE(SUM(credit), 0) FROM journal_lines WHERE txn_id = :t AND account_id = :a', ['t' => $ret['txn_id'], 'a' => Accts::get('isp_maint_expense')]),
        r2((float)$old['unit_cost']), 'Cr maintenance cost at the unit cost');
    isp_t_as($GLOBALS['adminId']);
    $op = IspNetwork::request($acct, 'provision', 'New ONU HWTCNEW0001 configured', 'replacement', $wo);
    eq(DB::value('SELECT state FROM isp_net_ops WHERE id = :o', ['o' => $op]), 'confirmed', 'the new unit is configured on the network');
    // Asset register update
    $new = DB::row("SELECT * FROM isp_equipment WHERE serial_no = 'HWTCNEW0001'");
    eq([$new['status'], (int)$new['account_id']], ['installed', $acct], 'the new unit is installed at the subscriber');
    $oldNow = IspEquipment::get((int)$old['id']);
    eq([$oldNow['status'], (int)$oldNow['site_id']], ['recovered', $ids['site.WH']], 'the old unit is back in the stockroom');
    check((int)DB::value('SELECT COUNT(*) FROM isp_equipment_log WHERE equipment_id = :e', ['e' => $old['id']]) >= 3, 'the old unit\'s history: registered, issued, installed, recovered');
    IspTickets::move($t, 'resolved', ['resolution' => 'ONU replaced with HWTCNEW0001', 'root_cause' => 'Defective power board']);
    IspTickets::move($t, 'closed');
    eq(IspTickets::get($t)['status'], 'closed', 'ticket closed');
});

test('WG Workflow G: subscription cancellation with final invoice, equipment recovery and deposit settlement', function () {
    global $ids;
    $acct = isp_t_account(['date' => ymd(2, 1), 'plan' => $ids['plan.F100'], 'with_materials' => 1]);
    isp_t_pay($acct, (float)isp_t_invoices($acct)[0]['total']);
    eq(isp_t_acct($acct)['deposit_status'], 'held', 'the deposit is held');
    // Customer request
    $req = DB::insert('isp_service_requests', ['request_no' => Seq::next('isp_request'), 'account_id' => $acct, 'request_type' => 'cancellation', 'details' => 'Moving abroad', 'status' => 'open', 'created_by' => Auth::id()]);
    // Contract review: inside the lock-in, the early termination fee applies
    $a = isp_t_acct($acct);
    check($a['lock_in_until'] >= today(), 'inside the lock-in period');
    // Outstanding balance, final invoice, disconnection
    $out = IspAccounts::terminate($acct, today(), 'Moving abroad (request ' . DB::value('SELECT request_no FROM isp_service_requests WHERE id = :r', ['r' => $req]) . ')', true);
    $final = isp_t_invoice((int)$out['final_invoice_id']);
    eq($final['kind'], 'final', 'a final invoice');
    eqm(DB::value("SELECT SUM(amount + vat_amount) FROM isp_invoice_lines WHERE invoice_id = :i AND line_type = 'termination_fee'", ['i' => $final['id']]), 3000, 'the early termination fee');
    $billed = DB::value("SELECT MIN(period_start) FROM isp_invoice_lines WHERE invoice_id = :i AND line_type IN ('subscription', 'prorate')", ['i' => $final['id']]);
    eq($billed, ymd(1, 1), 'the service is billed from the end of the last billed period');
    eq(DB::value("SELECT MAX(period_end) FROM isp_invoice_lines WHERE invoice_id = :i AND line_type IN ('subscription', 'prorate')", ['i' => $final['id']]), today(), 'up to the termination date');
    eq(DB::value('SELECT state FROM isp_net_ops WHERE id = :o', ['o' => $out['net_op_id']]), 'confirmed', 'the subscriber is removed from the network');
    eq(isp_t_acct($acct)['network_state'], 'not_provisioned', 'nothing left on the device');
    // Deposit settlement: applied to the final balance
    eq($out['deposit'][0], 1000.0, 'the 1,000 deposit is applied to the final balance');
    eq(isp_t_acct($acct)['deposit_status'], 'applied', 'deposit applied');
    $dadj = DB::row("SELECT * FROM isp_adjustments WHERE account_id = :a AND adj_type = 'deposit'", ['a' => $acct]);
    eq($dadj['status'], 'posted', 'the deposit application is posted');
    $ar = IspAccounts::arCustomer((int)isp_t_acct($acct)['isp_customer_id']);
    eqm(isp_t_gl(Accts::get('isp_deposits'), $ar), 0, 'the deposit liability of the subscriber is cleared');
    // Equipment recovery
    $wo = (int)$out['recovery_wo_id'];
    $eq = DB::row("SELECT * FROM isp_equipment WHERE account_id = :a AND status = 'for_recovery'", ['a' => $acct]);
    check($wo > 0 && $eq !== null, 'a recovery work order for the lent ONU');
    IspWorkOrders::schedule($wo, $ids['tech.1'], today(), 'PM');
    IspWorkOrders::complete($wo, ['checklist' => [0, 1, 2], 'recovered' => [(int)$eq['id'] => 'recovered'], 'findings' => 'Unit collected in good condition']);
    eq(IspEquipment::get((int)$eq['id'])['status'], 'recovered', 'the ONU is recovered');
    // Approval and account closure: the balance is settled
    $bal = IspAccounts::balances($acct)['balance'];
    check($bal > 0, 'the termination fee and the last days are still owed after the deposit');
    isp_t_pay($acct, $bal);
    eqm(IspAccounts::balances($acct)['balance'], 0, 'nothing owed');
    DB::update('isp_service_requests', ['status' => 'done', 'handled_by' => Auth::id(), 'handled_at' => date('Y-m-d H:i:s'), 'response' => 'Account closed'], 'id = :id', ['id' => $req]);
    eq(isp_t_acct($acct)['service_status'], 'terminated', 'the account is closed');
    check(count(isp_t_audit('isp_account', $acct, 'terminate')) === 1, 'the termination is in the audit trail');
});

test('WH Workflow H: network outage from detection to billing credits', function () {
    global $ids;
    $a1 = isp_t_account(['date' => ymd(0, 1), 'nap' => $ids['nap.3']]);
    $a2 = isp_t_account(['date' => ymd(0, 1), 'nap' => $ids['nap.3']]);
    $other = isp_t_account(['date' => ymd(0, 1)]);
    // Outage detection / report
    $t = IspTickets::create(['account_id' => $a1, 'ticket_type' => 'no_internet', 'subject' => 'No connection since last night']);
    // Network impact assessment + incident registration
    $start = date('Y-m-d H:i:s', time() - 30 * 3600);
    $o = IspOutages::create(['title' => 'Fiber cut on the national road', 'scope' => 'node', 'node_id' => $ids['nap.3'], 'started_at' => $start, 'verified' => 1, 'sla_hours' => 24, 'credit_policy' => 'prorated']);
    $out = IspOutages::get($o);
    eq((int)$out['affected_count'], 2, 'the two subscribers of the NAP are affected (found from the topology)');
    eq(array_map('intval', DB::column('SELECT account_id FROM isp_outage_accounts WHERE outage_id = :o ORDER BY account_id', ['o' => $o])), [$a1, $a2], 'the right subscribers');
    eq(DB::value('SELECT status FROM isp_net_nodes WHERE id = :n', ['n' => $ids['nap.3']]), 'damaged', 'the node is marked damaged');
    // Customer notification
    check(count(isp_t_notes($a1, 'network_outage')) > 0 && count(isp_t_notes($a2, 'network_outage')) > 0, 'the affected subscribers are told');
    check(count(isp_t_notes($other, 'network_outage')) === 0, 'the others are not');
    check((bool)DB::value('SELECT id FROM isp_announcements WHERE outage_id = :o', ['o' => $o]), 'an announcement for the portal');
    $t2 = IspTickets::create(['account_id' => $a2, 'ticket_type' => 'no_internet', 'subject' => 'Down']);
    eq((int)IspTickets::get($t2)['outage_id'], $o, 'a new ticket of an affected subscriber is linked to the outage');
    // Technical repair + network verification
    IspOutages::move($o, 'in_repair', ['repair_notes' => 'Splicing team on site']);
    refuses(fn() => IspOutages::move($o, 'restored'), 'root cause', 'restoring needs the root cause');
    IspOutages::move($o, 'restored', ['root_cause' => 'Cable cut by road works', 'restored_at' => date('Y-m-d H:i:s')]);
    eq(DB::value('SELECT status FROM isp_net_nodes WHERE id = :n', ['n' => $ids['nap.3']]), 'active', 'the node is in service again');
    check(count(isp_t_notes($a1, 'service_restored')) > 0, 'the subscribers are told it is restored');
    // Eligible billing credits: 30 h down, beyond the 24-hour SLA
    $oa = DB::row('SELECT * FROM isp_outage_accounts WHERE outage_id = :o AND account_id = :a', ['o' => $o, 'a' => $a1]);
    eqm($oa['downtime_hours'], 30, 'downtime');
    $credit = round(1500 * (30 / 24) / days_in_month($start), 2);
    eqm($oa['credit_amount'], $credit, 'the prorated service credit (1,500 x 30/24 days / days of the month)');
    eq(IspOutages::get($o)['credits_status'], 'proposed', 'the credits are proposed, not posted');
    $bal0 = (float)isp_t_invoices($a1)[0]['balance'];
    isp_t_as($ids['user.billing']);
    refuses(fn() => IspOutages::postCredits($o), 'permission', 'posting the credits needs the adjustment right');
    isp_t_as($GLOBALS['adminId']);
    eq(IspOutages::postCredits($o), 2, 'two credits posted');
    eqm(isp_t_invoices($a1)[0]['balance'], $bal0 - $credit, 'the credit reduces the subscriber\'s open invoice');
    eq(DB::value("SELECT adj_type FROM isp_adjustments WHERE id = :i", ['i' => DB::value('SELECT adjustment_id FROM isp_outage_accounts WHERE id = :x', ['x' => $oa['id']])]), 'outage_credit', 'as an outage credit');
    // Incident closure
    IspOutages::move($o, 'closed');
    eq(IspOutages::get($o)['status'], 'closed', 'closed');
    refuses(fn() => IspOutages::postCredits($o), 'no proposed credits', 'credits are posted once');
    IspTickets::move($t, 'resolved', ['resolution' => 'Restored after the outage repair', 'root_cause' => 'Network outage ' . $out['outage_no']]);
});

test('WI Workflow I: incorrect payment and adjustment corrected with the ledger and eligibility recalculated', function () {
    global $ids;
    $right = isp_t_account(['date' => today()]);
    isp_t_age($right, 10);
    IspDisconnect::evaluateAccount($right);
    $wrong = isp_t_account(['date' => today()]);
    isp_t_age($wrong, 10);
    IspDisconnect::evaluateAccount($wrong);
    isp_t_disconnect_now($wrong);
    eq(isp_t_acct($wrong)['service_status'], 'disconnected', 'the wrong account is disconnected for nonpayment');
    $amt = (float)isp_t_invoices($right)[0]['balance'];
    $wrongBal = IspAccounts::balances($wrong)['balance'];
    // Error: the payment of the right subscriber is posted to the wrong account, and reconnects it
    $pid = isp_t_pay($wrong, $amt, 'bank_transfer', ['reference_no' => 'MBTC-55667788']);
    eq(isp_t_acct($wrong)['service_status'], 'active', 'the misposted payment reconnected the wrong account');
    // Error report + investigation
    $d = IspCredit::openDispute($right, (int)isp_t_invoices($right)[0]['id'], $amt, 'Paid by bank transfer MBTC-55667788, not credited', true);
    IspCredit::noteDispute($d, 'Found posted to another account');
    // Approval + reversal
    isp_t_as($ids['user.acct']);
    IspPayments::reverse($pid, 'Posted to the wrong account; belongs to ' . isp_t_acct($right)['account_no']);
    isp_t_as($GLOBALS['adminId']);
    // Ledger recalculation + accounting correction
    eqm(IspAccounts::balances($wrong)['balance'], $wrongBal, 'the wrong account owes what it owed before');
    eqm(isp_t_gl_ar_of($wrong), $wrongBal, 'and so do the books');
    // Service eligibility recalculation
    $q = isp_t_queue($wrong);
    eq($q['status'], 'candidate', 'the wrong account is eligible for disconnection again');
    // the payment is entered on the right account with the same reference
    IspCredit::resolveDispute($d, 'resolved_no_credit', 0, 'Payment moved to the right account');
    $pid2 = isp_t_pay($right, $amt, 'bank_transfer', ['reference_no' => 'MBTC-55667788']);
    eqm(IspAccounts::balances($right)['balance'], 0, 'the right account is paid');
    eq(isp_t_queue($right)['status'], 'skipped', 'its waiting disconnection is dropped');
    // the reconnection fee of the wrongful reconnection is cancelled before it is billed
    $fee = DB::row("SELECT * FROM isp_charges WHERE account_id = :a AND charge_type = 'reconnection_fee' AND status = 'pending'", ['a' => $wrong]);
    check($fee !== null, 'the wrongful reconnection left a reconnection fee');
    IspBilling::cancelCharge((int)$fee['id'], 'Reconnection caused by a misposted payment');
    // an incorrect charge corrected by a credit adjustment
    IspBilling::addCharge($wrong, 'one_time', 'Router replacement (charged by mistake)', 850, today(), 'TEST-WI-' . $wrong);
    $inv = IspBilling::chargesInvoice($wrong, today());
    $adj = IspCredit::request($wrong, $inv, 'credit', 850, 'The router was replaced under warranty');
    isp_t_as($ids['user.acct']);
    IspCredit::decide($adj, true, 'Warranty confirmed');
    isp_t_as($GLOBALS['adminId']);
    eq(isp_t_invoice($inv)['status'], 'adjusted', 'the wrong invoice is fully credited');
    eqm(IspAccounts::balances($wrong)['balance'], $wrongBal, 'the balance is back to what is really owed');
    // Audit trail
    check(count(isp_t_audit('isp_payment', $pid, 'reverse')) === 1, 'reversal audited');
    check(count(isp_t_audit('isp_adjustment', $adj, 'approve')) === 1, 'adjustment approval audited');
    check(count(isp_t_audit('isp_payment', $pid2, 'create')) === 1, 'the corrected payment audited');
    isp_t_assert_books('WI');
});
