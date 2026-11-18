<?php
declare(strict_types=1);

/** B01-B12: boundary cases (section 38: month-end billing, leap years, zero balances, payment allocation errors,
 *  network downtime, concurrent cashier transactions, account state conflicts) and the integration checks */

test('B01 Month-end billing and leap years', function () {
    global $ids;
    eq(IspPlans::periodOf('2028-02-20', 1), ['2028-02-01', '2028-02-29'], 'February of a leap year has 29 days');
    eq(IspPlans::periodOf('2027-02-20', 1), ['2027-02-01', '2027-02-28'], 'February of a common year has 28 days');
    eq(IspPlans::periodOf('2026-03-01', 28), ['2026-02-28', '2026-03-27'], 'a cycle on the 28th runs to the 27th');
    eq(IspPlans::periodOf('2028-03-10', 15), ['2028-02-15', '2028-03-14'], 'a mid-month cycle across a leap February');
    eq(IspPlans::addMonths('2026-01-31', 1), '2026-02-28', 'January 31 + 1 month = February 28');
    eq(IspPlans::addMonths('2028-01-31', 1), '2028-02-29', 'in a leap year: February 29');
    eq(IspPlans::addMonths('2026-12-15', 2), '2027-02-15', 'across the year end');
    eq(IspPlans::addMonths('2027-03-31', -1), '2027-02-28', 'one month back from March 31');
    eq(IspPlans::days('2028-02-01', '2028-02-29'), 29, 'days of a leap February');
    check(abs(IspPlans::prorateFactor('2028-02-20', '2028-02-29', '2028-02-01', '2028-02-29') - 10 / 29) < 1e-9, 'proration over 29 days');
    $a = isp_t_acct((int)DB::value("SELECT id FROM isp_accounts WHERE service_status = 'active' AND plan_id = :p ORDER BY id LIMIT 1", ['p' => $ids['plan.F50']]));
    $a['member_price'] = 0;
    $a['cycle_day'] = 1;
    $calc = IspBilling::compute($a, '2028-02-20', '2028-02-29', '2028-02-20', false, true);
    eqm($calc['lines'][0]['amount'] + $calc['lines'][0]['vat_amount'], round(1500 * 10 / 29, 2), 'February 20-29 of a leap year: 10/29 of the fee');
    $calc = IspBilling::compute($a, '2027-02-20', '2027-02-28', '2027-02-20', false, true);
    eqm($calc['lines'][0]['amount'] + $calc['lines'][0]['vat_amount'], round(1500 * 9 / 28, 2), 'February 20-28 of a common year: 9/28 of the fee');
    // an anniversary on the 29th-31st is billed on the 28th (a day every month has)
    $d = ymd(1, 30);
    $acct = isp_t_account(['date' => $d, 'billing_basis' => 'anniversary']);
    eq((int)isp_t_acct($acct)['cycle_day'], 28, 'an anniversary at the end of the month bills on the 28th');
    // the cycle-day billing run on a month end bills the period once
    $m = isp_t_account(['date' => ymd(2, 28), 'cycle_day' => 28]);
    $r1 = IspBilling::generate(ymd(1, 28), 28, null, 'manual', true);
    $r2 = IspBilling::generate(ymd(1, 28), 28, null, 'manual', true);
    eq((int)DB::value("SELECT COUNT(*) FROM isp_invoices WHERE account_id = :a AND kind = 'regular'", ['a' => $m]), 1, 'one invoice for the month-end cycle');
    $inv = DB::row("SELECT * FROM isp_invoices WHERE account_id = :a AND kind = 'regular'", ['a' => $m]);
    eq([$inv['period_start'], $inv['period_end']], [ymd(1, 28), date('Y-m-d', strtotime(IspPlans::addMonths(ymd(1, 28), 1) . ' -1 day'))], 'the 28th to the 27th');
    eqm($inv['total'], 1500, 'a whole period');
});

test('B02 Zero balances', function () {
    $acct = isp_t_account(['date' => today()]);
    isp_t_pay($acct, (float)isp_t_invoices($acct)[0]['total']);
    $b = IspAccounts::balances($acct);
    eq([$b['open_invoices'], $b['credit'], $b['balance'], $b['overdue']], [0.0, 0.0, 0.0, 0.0], 'a settled account has nothing open');
    check(!IspPayments::openInvoices($acct), 'no open invoices');
    [$ok] = IspDisconnect::eligible(isp_t_acct($acct), IspDisconnect::rule('overdue'));
    check(!$ok, 'a zero balance never qualifies for disconnection');
    eqm(IspPayments::applyCredits($acct), 0, 'no credit to apply');
    refuses(fn() => IspPayments::collect($acct, 0, 'cash'), 'amount', 'a zero payment');
    refuses(fn() => IspPayments::collect($acct, -50, 'cash'), 'amount', 'a negative payment');
    refuses(fn() => IspBilling::chargesInvoice($acct, today()), 'no pending charge', 'an invoice of nothing');
    refuses(fn() => IspBilling::addCharge($acct, 'one_time', 'Nothing', 0, today(), 'ZERO-' . $acct), 'amount', 'a zero charge');
    refuses(fn() => IspCredit::request($acct, (int)isp_t_invoices($acct)[0]['id'], 'credit', 10, 'More than open'), 'more than what is open', 'a credit on a paid invoice');
    // a payment on an account with nothing open is wholly a credit
    $p = isp_t_pay($acct, 999.99);
    eqm(IspPayments::get($p)['unapplied_amount'], 999.99, 'kept as the subscriber\'s credit');
    eqm(isp_t_gl_ar_of($acct), -999.99, 'the books agree');
});

test('B03 Payment allocation errors', function () {
    $a1 = isp_t_account(['date' => today()]);
    $a2 = isp_t_account(['date' => today()]);
    $i1 = isp_t_invoices($a1)[0];
    $i2 = isp_t_invoices($a2)[0];
    $n0 = (int)DB::value('SELECT COUNT(*) FROM isp_payments');
    $r0 = (int)DB::value("SELECT next_no FROM sequences WHERE seq_key = 'isp_receipt'");
    refuses(fn() => isp_t_pay($a1, 100, 'bank_transfer', ['allocations' => [(int)$i2['id'] => 100]]), 'not open on this account', 'allocating to another subscriber\'s invoice');
    refuses(fn() => isp_t_pay($a1, (float)$i1['total'] + 500, 'bank_transfer', ['allocations' => [(int)$i1['id'] => (float)$i1['total'] + 1]]), 'more than its balance', 'allocating more than the invoice balance');
    refuses(fn() => isp_t_pay($a1, 100, 'bank_transfer', ['allocations' => [(int)$i1['id'] => 150]]), 'more than the payment', 'allocating more than the payment');
    refuses(fn() => IspPayments::collect($a1, 100, 'gcash'), 'reference', 'an e-wallet payment without its reference');
    refuses(fn() => IspPayments::collect($a1, 100, 'card_unknown'), 'Choose how', 'an unknown channel');
    eq((int)DB::value('SELECT COUNT(*) FROM isp_payments'), $n0, 'nothing was saved by the refused payments');
    eq((int)DB::value("SELECT next_no FROM sequences WHERE seq_key = 'isp_receipt'"), $r0, 'no receipt number was used (no gap)');
    // a partial allocation leaves the rest as credit
    $p = isp_t_pay($a1, 1000, 'bank_transfer', ['allocations' => [(int)$i1['id'] => 600]]);
    eq([(float)IspPayments::get($p)['applied_amount'], (float)IspPayments::get($p)['unapplied_amount']], [600.0, 400.0], 'the allocation chosen is respected, the rest is credit');
});

test('B04 Concurrent cashier transactions, cash over / short and certification', function () {
    global $ids;
    $acct = isp_t_account(['date' => today()]);
    $inv = isp_t_invoices($acct)[0];
    $total = (float)$inv['total'];
    isp_t_as($ids['user.csr']);
    refuses(fn() => IspPayments::collect($acct, 100, 'cash'), 'Open your cashier shift', 'cash without an open shift');
    isp_t_as($ids['user.cashier']);
    $s1 = IspCashier::open($ids['user.cashier'], Accts::get('isp_cash'), 1000, $ids['branch.HO']);
    refuses(fn() => IspCashier::open($ids['user.cashier'], Accts::get('isp_cash'), 0, $ids['branch.HO']), 'already have an open shift', 'a second shift for the same cashier');
    $p1 = IspPayments::collect($acct, 1000, 'cash');
    isp_t_as($ids['user.cashier2']);
    $s2 = IspCashier::open($ids['user.cashier2'], Accts::get('isp_cash'), 500, $ids['branch.HO']);
    $p2 = IspPayments::collect($acct, $total, 'cash');
    $p3 = IspPayments::collect($acct, 1200, 'check', ['check_no' => '0004512', 'check_bank' => 'BDO']);
    isp_t_as($ids['user.cashier']);
    refuses(fn() => IspPayments::collect($acct, 1200, 'check', ['check_no' => '0004512', 'check_bank' => 'BDO']), 'already recorded', 'the same check entered by the other cashier');
    $pay1 = IspPayments::get($p1);
    $pay2 = IspPayments::get($p2);
    eq([(int)$pay1['shift_id'], (int)$pay2['shift_id']], [$s1, $s2], 'each payment is in its cashier\'s shift');
    eqm($pay1['applied_amount'], 1000, 'the first payment is applied');
    eqm($pay2['applied_amount'], $total - 1000, 'the second takes only what was still open');
    eqm($pay2['unapplied_amount'], 1000, 'the rest is credit: nothing is applied twice');
    eqm(isp_t_invoice((int)$inv['id'])['balance'], 0, 'the invoice is settled once');
    eqm(DB::value('SELECT SUM(amount) FROM isp_payment_allocs WHERE invoice_id = :i AND is_reversed = 0', ['i' => $inv['id']]), $total, 'the allocations equal the invoice');
    // cashier 1 counts 100 short
    $os = Accts::get('isp_cash_over_short');
    $os0 = isp_t_gl($os);
    $c1 = IspCashier::close($s1, ['1000' => 1, '500' => 1, '200' => 2]);
    eq([$c1['expected'], $c1['counted'], $c1['over_short']], [2000.0, 1900.0, -100.0], 'expected 1,000 + 1,000; counted 1,900: 100 short');
    $ovs = isp_t_posting('OVS-' . $s1);
    check($ovs !== null, 'the shortage is posted');
    eqm(isp_t_gl($os) - $os0, 100, 'Dr cashier over / short 100 (Cr the cash drawer)');
    refuses(fn() => IspCashier::certify($s1, 'Own count'), 'not by the cashier', 'the cashier certifies own count');
    isp_t_as($ids['user.cashier2']);
    refuses(fn() => IspCashier::close($s1, []), 'already closed', 'closing a closed shift');
    $tot2 = IspCashier::totals($s2);
    eqm($tot2['cash'][1], $total, 'cash total of shift 2');
    eqm($tot2['check'][1], 1200, 'check total of shift 2');
    $c2 = IspCashier::close($s2, ['1000' => (int)floor(($total + 500) / 1000)], 'counted');
    check(abs($c2['over_short'] - ($c2['counted'] - ($total + 500))) < 0.005, 'the over / short of shift 2 is counted minus expected');
    isp_t_as($ids['user.acct']);
    IspCashier::certify($s1, 'Shortage charged to the cashier');
    eq(DB::value('SELECT status FROM isp_shifts WHERE id = :s', ['s' => $s1]), 'certified', 'certified by the supervisor');
    isp_t_as($GLOBALS['adminId']);
});

test('B05 Account state conflicts', function () {
    global $ids;
    $pending = isp_t_account(['pending' => 1]);
    refuses(fn() => IspAccounts::terminate($pending, today(), 'x', false), 'Only an active', 'terminating a pending account');
    refuses(fn() => IspAccounts::suspend($pending, null, 'x'), 'Only an active', 'suspending a pending account');
    refuses(fn() => IspDisconnect::queueManual($pending, 'administrative', 'x'), 'not connected', 'disconnecting a pending account');
    $act = isp_t_account(['date' => today()]);
    refuses(fn() => IspAccounts::activate($act, today()), 'not waiting for activation', 'activating an active account');
    refuses(fn() => IspAccounts::resume($act, 'x'), 'not suspended', 'resuming an active account');
    refuses(fn() => IspReconnect::manual($act, 'manual', 'x'), 'not disconnected', 'reconnecting a connected account');
    IspDisconnect::queueManual($act, 'technical', 'Line maintenance (test)', false);
    refuses(fn() => IspDisconnect::queueManual($act, 'administrative', 'x'), 'already in the disconnection queue', 'a second disconnection while one waits');
    IspDisconnect::cancel((int)isp_t_queue($act)['id'], 'Maintenance postponed');
    IspAccounts::suspend($act, date('Y-m-d', strtotime('+30 days')), 'Vacation (test)');
    eq([isp_t_acct($act)['service_status'], isp_t_acct($act)['network_state']], ['suspended', 'disabled'], 'a voluntary suspension disables the service');
    refuses(fn() => IspAccounts::suspend($act, null, 'x'), 'Only an active', 'suspending twice');
    IspAccounts::resume($act, 'Back home (test)');
    eq([isp_t_acct($act)['service_status'], isp_t_acct($act)['network_state']], ['active', 'enabled'], 'resumed and enabled');
    IspAccounts::terminate($act, today(), 'Closed (test)', false);
    refuses(fn() => IspAccounts::changePlan($act, $ids['plan.F100'], today(), 'x'), 'terminated account', 'a plan change on a terminated account');
    refuses(fn() => IspAccounts::setStatus($act, 'active', 'x'), 'cannot go from', 'reopening a terminated account');
    // a cancelled (never activated) account takes no payment
    IspAccounts::setStatus($pending, 'cancelled', 'Applicant withdrew (test)');
    refuses(fn() => isp_t_pay($pending, 100), 'cancelled before activation', 'a payment on a cancelled account');
    // an application that went live cannot move again
    $app = (int)DB::value('SELECT application_id FROM isp_accounts WHERE id = :a', ['a' => $act]);
    refuses(fn() => IspApplications::move($app, 'cancelled', 'x'), 'cannot go from', 'moving a closed application');
    // a closed ticket stays closed; a ticket is closed only with its resolution
    $t = IspTickets::create(['account_id' => $act, 'ticket_type' => 'billing', 'subject' => 'Question on the bill']);
    refuses(fn() => IspTickets::move($t, 'closed'), 'cannot go from', 'closing a new ticket straight away');
});

test('B06 Network downtime: with the device unreachable, operations become manual work orders and nothing is reported done before', function () {
    global $ids;
    $acct = isp_t_account(['date' => today()]);
    DB::run('UPDATE isp_devices SET is_active = 0 WHERE id = :d', ['d' => $ids['device.sim']]);
    $q = IspDisconnect::queueManual($acct, 'technical', 'Pole transfer by the power company (test)', true);
    $op = DB::row('SELECT * FROM isp_net_ops WHERE id = :o', ['o' => isp_t_queue($acct)['net_op_id']]);
    eq($op['state'], 'manual', 'without a reachable device the operation waits for a technician');
    $wo = DB::row('SELECT * FROM isp_work_orders WHERE id = :w', ['w' => $op['work_order_id']]);
    eq($wo['wo_type'], 'disconnection', 'a disconnection work order');
    eq([isp_t_acct($acct)['service_status'], isp_t_acct($acct)['network_state']], ['active', 'enabled'], 'nothing is reported disconnected yet');
    eq(isp_t_queue($acct)['status'], 'executing', 'the queue item waits for the confirmation');
    IspWorkOrders::schedule((int)$wo['id'], $ids['tech.2'], today(), 'AM');
    IspWorkOrders::complete((int)$wo['id'], ['checklist' => [0, 1], 'net_done' => '1', 'remarks' => 'Cut at the NAP']);
    eq(DB::value('SELECT state FROM isp_net_ops WHERE id = :o', ['o' => $op['id']]), 'confirmed', 'confirmed by the technician\'s work order');
    eq([isp_t_acct($acct)['service_status'], isp_t_acct($acct)['network_state']], ['disconnected', 'disabled'], 'disconnected only now');
    // a manual operation the technician could not do is failed, not done
    $o2 = IspNetwork::request($acct, 'reset_session', 'Reset (test)', 'manual', 1);
    $w2 = (int)DB::value('SELECT work_order_id FROM isp_net_ops WHERE id = :o', ['o' => $o2]);
    IspWorkOrders::cancel($w2, 'Could not reach the site (flood)');
    eq(DB::value('SELECT state FROM isp_net_ops WHERE id = :o', ['o' => $o2]), 'failed', 'a cancelled manual job fails the operation');
    isp_t_reset_device();
    // the network reconciliation finds the subscriber state the device reports
    $diff = IspNetwork::reconcile();
    check(is_array($diff), 'the reconciliation runs once the device is back');
});

test('B07 Credentials and secrets are stored encrypted, never in plain text', function () {
    global $ids;
    $a = isp_t_acct((int)DB::value("SELECT id FROM isp_accounts ORDER BY id LIMIT 1"));
    $enc = (string)$a['pppoe_password_enc'];
    check(preg_match('/^[a-z][0-9]:/', $enc) === 1, 'the PPPoE password is stored encrypted (' . substr($enc, 0, 3) . '...)');
    $plain = IspAccounts::pppoePassword($a);
    check($plain !== null && strlen($plain) >= 8 && !str_contains($enc, $plain), 'it decrypts, and the stored value does not contain it');
    $dev = IspNetwork::device($ids['device.sim']);
    check(!str_contains((string)$dev['secret_enc'], 'sim-secret-123') && IspCrypto::decrypt($dev['secret_enc']) === 'sim-secret-123', 'the device secret is encrypted and readable with the key');
    foreach (['isp_gateway_secret_enc' => 'sk_test_generic_123', 'isp_gateway_webhook_secret_enc' => 'whsec_test_456'] as $k => $secret) {
        $v = Settings::get($k);
        check($v !== '' && !str_contains($v, $secret) && IspCrypto::decrypt($v) === $secret, "$k is encrypted");
    }
    foreach (['sim-secret-123', 'sk_test_generic_123', 'whsec_test_456', $plain] as $secret) {
        $n = (int)DB::value('SELECT COUNT(*) FROM audit_trail WHERE changes LIKE :s OR snapshot LIKE :s2 OR reason LIKE :s3', ['s' => '%' . $secret . '%', 's2' => '%' . $secret . '%', 's3' => '%' . $secret . '%']);
        eq($n, 0, 'no secret is written to the audit trail');
        eq((int)DB::value('SELECT COUNT(*) FROM settings WHERE setting_value LIKE :s', ['s' => '%' . $secret . '%']), 0, 'no secret in plain text in the settings');
    }
    $tampered = substr($enc, 0, -4) . (substr($enc, -4) === 'AAAA' ? 'BBBB' : 'AAAA');
    check(IspCrypto::decrypt($tampered) === null, 'a tampered value is refused (authenticated encryption)');
    check(!str_contains(IspCrypto::masked($dev['secret_enc']), 'sim-secret'), 'screens show the secret masked');
});

test('B08 Branch data isolation for a branch-limited user', function () {
    global $ids;
    $pb = isp_t_account(['date' => today(), 'area' => $ids['area.PB'], 'last' => 'Poblacionista']);
    $ho = isp_t_account(['date' => today(), 'area' => $ids['area.SR'], 'last' => 'Sanroqueno']);
    eq([(int)isp_t_acct($pb)['branch_id'], (int)isp_t_acct($ho)['branch_id']], [$ids['branch.BR2'], $ids['branch.HO']], 'each account belongs to its area\'s branch');
    isp_t_as($ids['user.br2coll']);
    eq(IspSetup::userBranch(), $ids['branch.BR2'], 'the collection officer works in the Poblacion branch');
    $found = IspUi::searchAccounts('Subscriber', 500) ?: [];
    $found = array_merge($found, IspUi::searchAccounts('Poblacionista'), IspUi::searchAccounts('Sanroqueno'));
    $branches = array_unique(array_map(fn($r) => (int)DB::value('SELECT branch_id FROM isp_accounts WHERE id = :i', ['i' => $r['id']]), $found));
    eq(array_values($branches), [$ids['branch.BR2']], 'the account search shows only the user\'s branch');
    check(in_array($pb, array_map(fn($r) => (int)$r['id'], $found), true), 'the branch\'s own subscriber is found');
    check(!in_array($ho, array_map(fn($r) => (int)$r['id'], $found), true), 'the other branch\'s subscriber is not');
    $p = [];
    eq(IspSetup::branchSql('a.branch_id', $p), ' AND (a.branch_id = :ub OR a.branch_id IS NULL)', 'every list is limited by the branch');
    isp_t_as($GLOBALS['adminId']);
    check(IspSetup::userBranch() === null, 'the administrator sees every branch');
    // the ledger: each branch posts under its own class
    $inv = isp_t_invoices($pb)[0];
    $class = (int)DB::value('SELECT class_id FROM transactions WHERE id = :t', ['t' => isp_t_posting('INV-' . $inv['id'])['txn_id']]);
    eq($class, (int)DB::value('SELECT class_id FROM coop_branches WHERE id = :b', ['b' => $ids['branch.BR2']]), 'the invoice posts under the branch class');
    eq((int)DB::value('SELECT business_unit_id FROM transactions WHERE id = :t', ['t' => isp_t_posting('INV-' . $inv['id'])['txn_id']]), (int)IspSetup::unitId(), 'and the Cable & Internet line of business');
});

test('B09 Portal ownership: a subscriber sees only their own accounts', function () {
    $a1 = isp_t_account(['date' => today(), 'mobile' => '09171234561']);
    $a2 = isp_t_account(['date' => today(), 'mobile' => '09171234562']);
    $c1 = (int)isp_t_acct($a1)['isp_customer_id'];
    $c2 = (int)isp_t_acct($a2)['isp_customer_id'];
    $pw = IspPortal::grant($c1);
    check(strlen($pw) >= 8, 'a temporary portal password');
    check(!DB::value('SELECT id FROM isp_portal_users WHERE password_hash = :p', ['p' => $pw]), 'stored as a hash only');
    IspPortal::register(isp_t_acct($a2)['account_no'], '4562', 'Portal2026x', 'Portal2026x');
    refuses(fn() => IspPortal::register(isp_t_acct($a2)['account_no'], '4562', 'Portal2026x', 'Portal2026x'), 'already has a portal account', 'registering twice');
    refuses(fn() => IspPortal::register(isp_t_acct($a1)['account_no'], '0000', 'Portal2026x', 'Portal2026x'), 'could not match', 'registering with the wrong mobile digits');
    isp_t_portal_as($c1);
    eq((int)IspPortal::account($a1)['id'], $a1, 'the subscriber opens own account');
    eq(array_map(fn($r) => (int)$r['id'], IspPortal::accounts($c1)), [$a1], 'the account list holds only own accounts');
    $denied0 = (int)DB::value("SELECT COUNT(*) FROM audit_trail WHERE action = 'access_denied' AND record_type = 'isp_account' AND record_id = :a", ['a' => $a2]);
    refuses(fn() => IspPortal::account($a2), 'not yours', 'opening another subscriber\'s account');
    eq((int)DB::value("SELECT COUNT(*) FROM audit_trail WHERE action = 'access_denied' AND record_type = 'isp_account' AND record_id = :a", ['a' => $a2]), $denied0 + 1, 'the attempt is in the audit trail');
    isp_t_portal_as($c2);
    refuses(fn() => IspPortal::account($a1), 'not yours', 'the other way round too');
    isp_t_portal_as(null);
    // five wrong passwords lock the portal account
    $user = (string)DB::value('SELECT username FROM isp_portal_users WHERE isp_customer_id = :c', ['c' => $c1]);
    for ($i = 0; $i < 5; $i++) {
        refuses(fn() => IspPortal::login($user, 'wrong-password'), 'not correct', 'a wrong password');
    }
    eq(DB::value('SELECT status FROM isp_portal_users WHERE isp_customer_id = :c', ['c' => $c1]), 'locked', 'locked after five failures');
    refuses(fn() => IspPortal::login($user, $pw), 'locked', 'even the right password is refused once locked');
});

test('B10 Cooperative integration: member price and the savings auto-debit', function () {
    global $ids;
    $mid = DB::tx(fn() => coop_fx_member($ids, 'Mendoza', 'Ramon', ['mobile' => '09175553311']));
    $party = (int)DB::value('SELECT party_id FROM members WHERE id = :m', ['m' => $mid]);
    check($party > 0, 'the member is on the Party Master');
    $acct = isp_t_account(['date' => today(), 'party_id' => $party, 'mobile' => '09175553311']);
    $a = isp_t_acct($acct);
    eq((int)$a['member_price'], 1, 'a member gets the member price');
    eq((int)$a['party_id'], $party, 'the subscriber is the same party as the member (no duplicate record)');
    $sub = DB::row("SELECT * FROM isp_invoice_lines WHERE invoice_id = :i AND line_type IN ('subscription', 'prorate')", ['i' => isp_t_invoices($acct)[0]['id']]);
    eqm($sub['plan_price'], 1350, 'billed at the member rate of 1,350');
    $sav = Savings::open($mid, $ids['sav.RS'], null, today(), null, 'sav.accounts');
    if (DB::value('SELECT status FROM sav_accounts WHERE id = :s', ['s' => $sav]) === 'pending') {
        Savings::approve($sav, 'sav.accounts');
    }
    Savings::deposit($sav, 'CSD', '10000.00', today(), $ids['cash.HO']);
    IspAccounts::saveDetails($acct, [], ['autodebit_sav_account_id' => $sav]);
    $bal0 = (float)DB::value('SELECT balance FROM sav_accounts WHERE id = :s', ['s' => $sav]);
    $due = (float)isp_t_invoices($acct)[0]['balance'];
    IspPayments::runAutoDebits(31);
    $p = DB::row("SELECT * FROM isp_payments WHERE account_id = :a AND channel = 'auto_debit'", ['a' => $acct]);
    check($p !== null && $p['status'] === 'posted', 'the bill is paid from the member\'s savings');
    eqm($p['amount'], $due, 'the amount due');
    eqm((float)DB::value('SELECT balance FROM sav_accounts WHERE id = :s', ['s' => $sav]), $bal0 - $due, 'the savings balance went down by the bill');
    check(isp_t_posting('SAV-' . $p['id']) !== null && isp_t_posting('PAY-' . $p['id']) !== null, 'the savings withdrawal and the payment are both in the books');
    eqm(isp_t_gl(Accts::get('isp_autodebit_clearing')), 0, 'the auto-debit clearing account nets to zero');
    eqm(IspAccounts::balances($acct)['balance'], 0, 'the subscriber owes nothing');
    eq(IspPayments::runAutoDebits(31), 0, 'a second run debits nothing more');
    check(count(array_filter(IspAccounts::ofParty($party), fn($r) => (int)$r['id'] === $acct)) === 1, 'the member\'s party shows the internet account');
});

test('B11 VAT treatment: inclusive, exclusive, exempt, zero-rated and a non-VAT company', function () {
    global $ids;
    $a = isp_t_acct((int)DB::value("SELECT id FROM isp_accounts WHERE service_status = 'active' AND plan_id = :p ORDER BY id LIMIT 1", ['p' => $ids['plan.F50']]));
    $a['member_price'] = 0;
    $a['cycle_day'] = 1;
    $ps = date('Y-m-01');
    $pe = date('Y-m-t');
    $c = IspBilling::compute($a, $ps, $pe, $ps, false, true);
    [$net, $vat] = vat_incl(1500);
    eq([$c['subtotal'], $c['vat'], $c['total']], [$net, $vat, 1500.0], 'VAT-inclusive 1,500: 1,339.29 + 160.71');
    foreach (['exclusive' => [1500.0, 180.0, 1680.0], 'exempt' => [1500.0, 0.0, 1500.0], 'zero' => [1500.0, 0.0, 1500.0]] as $mode => $exp) {
        DB::run('UPDATE isp_plans SET vat_mode = :m WHERE id = :p', ['m' => $mode, 'p' => $ids['plan.F50']]);
        IspPlans::flush();
        $c = IspBilling::compute($a, $ps, $pe, $ps, false, true);
        eq([$c['subtotal'], $c['vat'], $c['total']], $exp, "VAT mode $mode");
    }
    DB::run("UPDATE isp_plans SET vat_mode = 'inclusive' WHERE id = :p", ['p' => $ids['plan.F50']]);
    IspPlans::flush();
    Settings::set('isp_vat_registered', '0');
    Settings::flush();
    $c = IspBilling::compute($a, $ps, $pe, $ps, false, true);
    eq([$c['subtotal'], $c['vat'], $c['total']], [1500.0, 0.0, 1500.0], 'a non-VAT company charges no VAT');
    Settings::set('isp_vat_registered', '1');
    Settings::flush();
});

test('B12 Payment references are recognized however they are typed', function () {
    $acct = isp_t_account(['date' => today()]);
    isp_t_pay($acct, 100, 'bank_transfer', ['reference_no' => 'bpi 2026 0001 77']);
    refuses(fn() => isp_t_pay($acct, 100, 'bank_transfer', ['reference_no' => 'BPI202600 0177']), 'already recorded', 'the same reference with other spacing and case');
    $other = isp_t_pay($acct, 100, 'gcash', ['reference_no' => 'BPI2026000177']);
    check($other > 0, 'the same text on another channel is another payment');
    $p = isp_t_pay($acct, 50, 'maya', ['reference_no' => 'MY-5501', 'status' => 'pending', 'source' => 'portal']);
    refuses(fn() => isp_t_pay($acct, 50, 'maya', ['reference_no' => 'my-5501', 'status' => 'pending', 'source' => 'portal']), 'already recorded', 'a pending reference cannot be submitted twice');
    IspPayments::verify($p, false, 'Reference not found');
    $again = isp_t_pay($acct, 50, 'maya', ['reference_no' => 'MY-5501', 'status' => 'pending', 'source' => 'portal']);
    check($again > 0, 'a rejected reference can be submitted again (corrected)');
});

test('B13 Temporary reconnection expires unpaid and the service is disconnected again', function () {
    $acct = isp_t_account(['date' => today()]);
    isp_t_age($acct, 10);
    IspDisconnect::evaluateAccount($acct);
    isp_t_disconnect_now($acct);
    $rid = IspReconnect::manual($acct, 'temporary', 'Promised to pay on Friday (test)', date('Y-m-d H:i:s', time() + 3600));
    $r = DB::row('SELECT * FROM isp_reconnections WHERE id = :r', ['r' => $rid]);
    eq([$r['recon_type'], $r['status']], ['temporary', 'restored'], 'restored for a limited time');
    eq(isp_t_acct($acct)['service_status'], 'active', 'active while the temporary reconnection lasts');
    eqm($r['fee_amount'], 300, 'the reconnection fee applies');
    eq(IspReconnect::expireTemporary(), 0, 'nothing expires before its time');
    DB::run('UPDATE isp_reconnections SET expires_at = :t WHERE id = :r', ['t' => date('Y-m-d H:i:s', time() - 60), 'r' => $rid]);
    eq(IspReconnect::expireTemporary(), 1, 'expired');
    eq(DB::value('SELECT status FROM isp_reconnections WHERE id = :r', ['r' => $rid]), 'expired', 'the temporary reconnection is closed');
    eq(isp_t_acct($acct)['service_status'], 'disconnected', 'unpaid at the expiry: disconnected again');
    // paid before the expiry: it stays connected
    $b = isp_t_account(['date' => today()]);
    isp_t_age($b, 10);
    IspDisconnect::evaluateAccount($b);
    isp_t_disconnect_now($b);
    $rb = IspReconnect::manual($b, 'temporary', 'Promise (test)', date('Y-m-d H:i:s', time() + 3600));
    isp_t_pay($b, IspAccounts::balances($b)['balance']);
    DB::run('UPDATE isp_reconnections SET expires_at = :t WHERE id = :r', ['t' => date('Y-m-d H:i:s', time() - 60), 'r' => $rb]);
    IspReconnect::expireTemporary();
    eq(isp_t_acct($b)['service_status'], 'active', 'paid in time: still connected after the expiry');
});

test('B14 Partial-payment reconnection and the prepaid reconnection fee policies', function () {
    // half of the arrears is enough under a 50% policy
    Settings::set('isp_partial_recon_pct', '50');
    Settings::flush();
    try {
        $acct = isp_t_account(['date' => today()]);
        isp_t_age($acct, 10);
        IspDisconnect::evaluateAccount($acct);
        isp_t_disconnect_now($acct);
        $arrears = (float)isp_t_queue($acct)['eligible_amount'];
        isp_t_pay($acct, round($arrears * 0.4, 2));
        eq(isp_t_acct($acct)['service_status'], 'disconnected', '40% paid: not enough');
        isp_t_pay($acct, round($arrears * 0.15, 2));
        $r = DB::row('SELECT * FROM isp_reconnections WHERE account_id = :a ORDER BY id DESC LIMIT 1', ['a' => $acct]);
        eq([$r['recon_type'], $r['status']], ['partial', 'restored'], '55% paid: reconnected under the partial-payment policy');
    } finally {
        Settings::set('isp_partial_recon_pct', '100');
        Settings::flush();
    }
    // the reconnection fee must be paid before the service is restored
    Settings::set('isp_recon_fee_policy', 'prepay');
    Settings::flush();
    try {
        $b = isp_t_account(['date' => today()]);
        isp_t_age($b, 10);
        IspDisconnect::evaluateAccount($b);
        isp_t_disconnect_now($b);
        isp_t_pay($b, IspAccounts::balances($b)['balance']);
        $r = DB::row('SELECT * FROM isp_reconnections WHERE account_id = :a ORDER BY id DESC LIMIT 1', ['a' => $b]);
        eq($r['status'], 'approved', 'the reconnection waits');
        eq(isp_t_acct($b)['service_status'], 'disconnected', 'not restored before the fee is paid');
        $feeInv = (int)DB::value('SELECT invoice_id FROM isp_charges WHERE id = :c', ['c' => $r['fee_charge_id']]);
        eqm(isp_t_invoice($feeInv)['balance'], 300, 'the fee is billed at once');
        isp_t_pay($b, 300);
        eq(DB::value('SELECT status FROM isp_reconnections WHERE id = :r', ['r' => $r['id']]), 'restored', 'restored as soon as the fee is paid');
        IspJobs::run('reconnect', 'test');
        eq((int)DB::value("SELECT COUNT(*) FROM isp_reconnections WHERE account_id = :a", ['a' => $b]), 1, 'the reconnection job adds nothing');
        eq(isp_t_acct($b)['service_status'], 'active', 'active');
    } finally {
        Settings::set('isp_recon_fee_policy', 'next_bill');
        Settings::flush();
    }
});

test('B15 Payment arrangements: a missed installment ends the protection; a kept one completes', function () {
    $acct = isp_t_account(['date' => today()]);
    isp_t_age($acct, 15);
    $bal = IspAccounts::balances($acct)['balance'];
    $arr = IspCredit::createArrangement($acct, 'installment', [[date('Y-m-d', strtotime('-1 day')), round($bal / 2, 2)], [date('Y-m-d', strtotime('+14 days')), $bal - round($bal / 2, 2)]],
        true, false, 'Two installments (test)');
    isp_t_as($GLOBALS['ids']['user.billing']);
    refuses(fn() => IspCredit::decideArrangement($arr, true, 'ok'), 'permission', 'a billing officer cannot approve an arrangement');
    isp_t_as($GLOBALS['adminId']);
    IspCredit::decideArrangement($arr, true, 'Approved');
    check(IspDisconnect::evaluateAccount($acct) === null, 'protected while the arrangement is kept');
    eq(IspCredit::followUp(), 1, 'the missed installment is found');
    eq(DB::value('SELECT status FROM isp_arrangements WHERE id = :a', ['a' => $arr]), 'defaulted', 'the arrangement is defaulted');
    check(IspDisconnect::evaluateAccount($acct) !== null, 'without the arrangement the arrears qualify again');
    // kept: every installment paid completes it
    $b = isp_t_account(['date' => today()]);
    isp_t_age($b, 15);
    $bb = IspAccounts::balances($b)['balance'];
    $ar2 = IspCredit::createArrangement($b, 'extension', [[date('Y-m-d', strtotime('+7 days')), $bb]], true, true, 'One week extension (test)');
    IspCredit::decideArrangement($ar2, true, 'Approved');
    refuses(fn() => IspCredit::createArrangement($b, 'extension', [[date('Y-m-d', strtotime('+9 days')), 10]], true, true, 'x'), 'already has a payment arrangement', 'a second arrangement');
    isp_t_pay($b, $bb);
    eq(DB::value('SELECT status FROM isp_arrangements WHERE id = :a', ['a' => $ar2]), 'completed', 'completed by the payment');
});

test('B16 Sales agent commissions: activation, first payment, payment by bill, clawback', function () {
    global $ids;
    $ag = DB::insert('isp_agents', ['code' => 'AG-01', 'name' => 'Nora Villareal', 'mobile' => '09179998877', 'activation_type' => 'fixed', 'activation_value' => 500,
        'first_payment_type' => 'percent', 'first_payment_value' => 10, 'clawback_days' => 90, 'is_active' => 1]);
    $acct = isp_t_account(['date' => today(), 'agent_id' => $ag]);
    $act = DB::row("SELECT * FROM isp_commissions WHERE account_id = :a AND basis = 'activation'", ['a' => $acct]);
    eqm($act['amount'], 500, 'the activation commission');
    $total = (float)isp_t_invoices($acct)[0]['total'];
    isp_t_pay($acct, $total);
    $fp = DB::row("SELECT * FROM isp_commissions WHERE account_id = :a AND basis = 'first_payment'", ['a' => $acct]);
    eqm($fp['amount'], round($total * 0.10, 2), 'the first-payment commission (10%)');
    isp_t_pay($acct, 100);
    eq((int)DB::value('SELECT COUNT(*) FROM isp_commissions WHERE account_id = :a', ['a' => $acct]), 2, 'only the first payment earns a commission');
    eq(IspAgents::approve([(int)$act['id'], (int)$fp['id']], true), 2, 'approved');
    $exp0 = isp_t_gl(Accts::get('isp_commission_expense'));
    $txn = IspAgents::pay($ag, today());
    eq(DB::value('SELECT txn_type FROM transactions WHERE id = :t', ['t' => $txn]), 'bill', 'paid with a bill of the agent\'s supplier record');
    eqm(isp_t_gl(Accts::get('isp_commission_expense')) - $exp0, 500 + round($total * 0.10, 2), 'commission expense');
    eq(DB::value('SELECT status FROM isp_commissions WHERE id = :c', ['c' => $act['id']]), 'paid', 'paid');
    refuses(fn() => IspAgents::pay($ag, today()), 'no approved commission', 'paying twice');
    // cancelled within the clawback period: the paid commissions are clawed back
    IspAccounts::terminate($acct, today(), 'Changed his mind (test)', false);
    $claw = DB::all("SELECT * FROM isp_commissions WHERE account_id = :a AND amount < 0", ['a' => $acct]);
    eq(count($claw), 2, 'two clawbacks');
    eqm(array_sum(array_column($claw, 'amount')), -(500 + round($total * 0.10, 2)), 'the whole commission is clawed back');
});

test('B17 Support SLA escalation and recurring complaints', function () {
    $acct = isp_t_account(['date' => today()]);
    $t1 = IspTickets::create(['account_id' => $acct, 'ticket_type' => 'no_internet', 'subject' => 'No internet']);
    $tk = IspTickets::get($t1);
    eq($tk['priority'], 'high', 'no internet is high priority');
    check(abs(strtotime($tk['sla_due_at']) - (time() + 8 * 3600)) < 120, 'an 8-hour SLA');
    DB::run('UPDATE isp_tickets SET sla_due_at = :t WHERE id = :i', ['t' => date('Y-m-d H:i:s', time() - 60), 'i' => $t1]);
    check(IspTickets::escalate() >= 1, 'past the SLA: escalated');
    $tk = IspTickets::get($t1);
    eq([(int)$tk['escalation_level'], $tk['priority']], [1, 'critical'], 'one level up, critical priority');
    check($tk['sla_due_at'] > date('Y-m-d H:i:s'), 'a new deadline');
    IspTickets::create(['account_id' => $acct, 'ticket_type' => 'slow', 'subject' => 'Slow']);
    $t3 = IspTickets::create(['account_id' => $acct, 'ticket_type' => 'intermittent', 'subject' => 'Drops again']);
    eq([(int)IspTickets::get($t3)['escalation_level'], IspTickets::get($t3)['priority']], [1, 'high'], 'the third complaint in 30 days is escalated at once');
});

test('B18 Security deposit refund after termination', function () {
    global $ids;
    $acct = isp_t_account(['date' => today(), 'plan' => $ids['plan.F100']]);
    isp_t_pay($acct, (float)isp_t_invoices($acct)[0]['total']);
    eq(isp_t_acct($acct)['deposit_status'], 'held', 'deposit held');
    $out = IspAccounts::terminate($acct, today(), 'Relocation outside the coverage (test)', false);
    $a = isp_t_acct($acct);
    check($a['deposit_status'] === 'held' && (float)$a['security_deposit'] > 0, 'nothing owed: the deposit is refundable (' . $a['deposit_status'] . ' ' . $a['security_deposit'] . ')');
    $cash0 = isp_t_gl(Accts::get('isp_cash'));
    $dep = (float)$a['security_deposit'];
    // the month was billed in advance and paid: the days after the end are owed back with the deposit
    $unused = r2(-(float)DB::value("SELECT COALESCE(SUM(amount), 0) FROM isp_charges WHERE account_id = :a AND status = 'pending' AND amount < 0", ['a' => $acct]));
    check($unused > 0, 'the unused days of the advance month are an unused service credit (' . money($unused) . ')');
    eq(IspBilling::refundable($acct), ['deposit' => r2($dep), 'credit' => $unused, 'total' => r2($dep + $unused)], 'the final settlement: deposit and unused service');
    $credits0 = isp_t_gl(Accts::get('isp_service_credits'));
    $txn = IspBilling::refundDeposit($acct, Accts::get('isp_cash'), today(), 'Refunded at the counter');
    eq(isp_t_acct($acct)['deposit_status'], 'refunded', 'refunded');
    eqm($cash0 - isp_t_gl(Accts::get('isp_cash')), r2($dep + $unused), 'Cr cash: the deposit and the unused service');
    eqm(isp_t_gl(Accts::get('isp_service_credits')) - $credits0, $unused, 'Dr service credits: the unused service');
    eq((int)DB::value("SELECT COUNT(*) FROM isp_charges WHERE account_id = :a AND status = 'pending' AND amount < 0", ['a' => $acct]), 0, 'the unused service credit is settled');
    eqm(isp_t_gl(Accts::get('isp_deposits'), IspAccounts::arCustomer((int)$a['isp_customer_id'])), 0, 'the deposit liability of the subscriber is cleared');
    refuses(fn() => IspBilling::refundDeposit($acct, Accts::get('isp_cash'), today(), 'again'), 'no paid deposit', 'a second refund');
});

test('B19 Contract expiration notices and renewal', function () {
    $acct = isp_t_account(['date' => today()]);
    $c = DB::row('SELECT * FROM isp_contracts WHERE account_id = :a', ['a' => $acct]);
    $end = date('Y-m-d', strtotime('+10 days'));
    DB::run('UPDATE isp_contracts SET end_date = :e WHERE id = :c', ['e' => $end, 'c' => $c['id']]);
    IspContracts::notices();
    IspContracts::notices();
    eq(count(array_filter(isp_t_notes($acct, 'contract_expiration'), fn($n) => $n['channel'] === 'inapp')), 1, 'one expiration notice');
    $new = IspContracts::renew((int)$c['id'], 12, 'Test Subscriber');
    $n = DB::row('SELECT * FROM isp_contracts WHERE id = :c', ['c' => $new]);
    eq([$n['status'], $n['start_date'], (int)$n['amends_id']], ['active', date('Y-m-d', strtotime($end . ' +1 day')), (int)$c['id']], 'the renewal starts the day after the end');
    eq($n['end_date'], date('Y-m-d', strtotime(IspPlans::addMonths(date('Y-m-d', strtotime($end . ' +1 day')), 12) . ' -1 day')), 'for 12 months');
    eq(DB::value('SELECT status FROM isp_contracts WHERE id = :c', ['c' => $c['id']]), 'renewed', 'the old contract is renewed');
    eq(isp_t_acct($acct)['contract_end'], $n['end_date'], 'the account follows');
    DB::run('UPDATE isp_contracts SET end_date = :e WHERE id = :c', ['e' => date('Y-m-d', strtotime('-1 day')), 'c' => $new]);
    IspContracts::notices();
    eq(DB::value('SELECT status FROM isp_contracts WHERE id = :c', ['c' => $new]), 'expired', 'an ended contract continues month to month');
});

test('B20 Every scheduled job runs cleanly (the backup job excepted: it writes to the program folder)', function () {
    foreach (array_keys(IspSetup::JOBS) as $code) {
        if ($code === 'backup') {
            continue;
        }
        $r = IspJobs::run($code, 'test');
        check($r['status'] === 'ok', "job $code: " . $r['status'] . ' ' . ($r['message'] ?? ''));
    }
    DB::run("UPDATE isp_jobs SET is_enabled = 0 WHERE job_code = 'backup'");
    $due = IspJobs::runDue('test');
    DB::run("UPDATE isp_jobs SET is_enabled = 1 WHERE job_code = 'backup'");
    check(!isset($due['backup']), 'a disabled job is not run');
    foreach ($due as $code => $r) {
        check(in_array($r['status'], ['ok', 'skipped'], true), "runDue $code: " . $r['status'] . ' ' . ($r['message'] ?? ''));
    }
    check((int)DB::value("SELECT COUNT(*) FROM isp_jobs WHERE lock_token IS NOT NULL OR locked_until IS NOT NULL") === 0, 'no job is left claimed');
    eq(Auth::id(), $GLOBALS['adminId'], 'the jobs leave the logged-in user as it was');
});
