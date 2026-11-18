<?php
declare(strict_types=1);

/**
 * The member requests of ADDITIONAL_MODULE_FOR_COOP_SYSTEMS_v3.xlsx: one name for the member in every line of
 * business, with the member's Customer Defaults and Vendor Defaults kept on the membership form.
 * Runs after cases_v30.php.
 */
require_once APP_ROOT . '/modules/txn/_grids.php';

test('Member defaults: every member has its own customer and supplier record, which follow the member and carry the Customer and Vendor Defaults', function () {
    $mid = newMember('Salazar', 'Teodora', ['mobile' => '09171234567']);
    $m = Coop::member($mid);
    $c = MemberDefaults::customerOf($m);
    $v = MemberDefaults::vendorOf($m);
    check($c !== null && (int)$c['is_active'] === 1, 'the member is a customer at once, on its own record');
    check($v !== null && (int)$v['is_active'] === 1, 'the member is a supplier at once too (no second form for the person)');
    eq($c['name'], 'Salazar, Teodora', 'the customer record carries the member\'s name');
    eq($v['name'], 'Salazar, Teodora', 'the supplier record carries the member\'s name');
    eq((int)MemberDefaults::memberOf('c', (int)$c['id']), $mid, 'the customer record is known as the member\'s');
    eq((int)MemberDefaults::memberOf('v', (int)$v['id']), $mid, 'the supplier record is known as the member\'s');

    // the Customer Defaults tab
    $terms = (int)DB::value('SELECT id FROM terms ORDER BY id LIMIT 1');
    $tax = (int)DB::value('SELECT id FROM tax_codes ORDER BY id LIMIT 1');
    $pm = (int)DB::value('SELECT id FROM payment_methods ORDER BY id LIMIT 1');
    $ctype = (int)DB::value('SELECT id FROM customer_types ORDER BY id LIMIT 1') ?: DB::insert('customer_types', ['name' => 'Member-buyer']);
    $rep = (int)DB::value('SELECT id FROM sales_reps ORDER BY id LIMIT 1') ?: DB::insert('sales_reps', ['name' => 'Ana Cruz', 'is_active' => 1]);
    $bg = (int)DB::value('SELECT id FROM billing_groups ORDER BY id LIMIT 1') ?: DB::insert('billing_groups', ['name' => 'Members of Purok 1']);
    $wSales = Withholding::ready() ? array_slice(array_keys(Withholding::forSide('sales')), 0, 2) : [];
    DB::tx(fn() => MemberDefaults::saveCustomer($mid, ['terms_id' => $terms, 'credit_limit' => 15000, 'tax_code_id' => $tax, 'payment_method_id' => $pm, 'customer_type_id' => $ctype,
        'sales_rep_id' => $rep, 'billing_group_id' => $bg, 'wht_ids' => $wSales ? implode(',', $wSales) : null], 'Board approved', 'coop.member'));
    $c = DB::row('SELECT * FROM customers WHERE id = :id', ['id' => $c['id']]);
    eq([(int)$c['terms_id'], (int)$c['tax_code_id'], (int)$c['payment_method_id'], (int)$c['customer_type_id'], (int)$c['sales_rep_id'], (int)$c['billing_group_id']],
        [$terms, $tax, $pm, (int)$ctype, (int)$rep, (int)$bg], 'terms, tax code, payment method, customer type, sales rep and billing group are on the member\'s customer record');
    eqm($c['credit_limit'], '15000', 'the credit limit');
    eq((string)$c['wht_ids'], $wSales ? implode(',', $wSales) : '', 'the taxes the member withholds as a customer');

    // a user who may not approve on Customer Center gives a reason for a credit limit change
    asUser('teller1');
    if (!Access::can('cust.center', 'approve') && !Access::isAdmin()) {
        refuses(fn() => DB::tx(fn() => MemberDefaults::saveCustomer($mid, ['credit_limit' => 20000], '', 'coop.member')), 'reason', 'a credit limit change without a reason');
        DB::tx(fn() => MemberDefaults::saveCustomer($mid, ['credit_limit' => 20000], 'Raised by the credit committee', 'coop.member'));
        eqm(DB::value('SELECT credit_limit FROM customers WHERE id = :id', ['id' => $c['id']]), '20000', 'with a reason the limit changes');
    }
    asUser('gm');

    // the Vendor Defaults tab
    $exp = (int)DB::value("SELECT id FROM accounts WHERE account_type = 'Expense' AND is_active = 1 ORDER BY code LIMIT 1");
    $wBuy = Withholding::ready() ? array_slice(array_keys(Withholding::forSide('purchases')), 0, 1) : [];
    DB::tx(fn() => MemberDefaults::saveVendor($mid, ['terms_id' => $terms, 'credit_limit' => 5000, 'account_no' => 'SUP-0042', 'default_expense_account_id' => $exp, 'tax_code_id' => $tax,
        'wht_ids' => $wBuy ? implode(',', $wBuy) : null], 'coop.member'));
    $v = DB::row('SELECT * FROM vendors WHERE id = :id', ['id' => $v['id']]);
    eq([(int)$v['terms_id'], (string)$v['account_no'], (int)$v['default_expense_account_id'], (int)$v['tax_code_id']], [$terms, 'SUP-0042', $exp, $tax], 'terms, account no., expense account and tax code on the supplier record');
    eqm($v['credit_limit'], '5000', 'the credit limit with the vendor');
    eq((string)$v['wht_ids'], $wBuy ? implode(',', $wBuy) : '', 'the withholding taxes of the member as a supplier');

    // one name: renaming the member renames its records
    DB::tx(fn() => Members::save(array_merge(array_intersect_key($m, array_flip(['member_kind', 'first_name', 'middle_name', 'suffix', 'branch_id', 'member_type_id', 'birth_date', 'gender', 'civil_status',
        'res_street', 'res_barangay', 'res_city', 'res_province', 'subscribed_shares', 'par_value'])), ['last_name' => 'Salazar-Ramos', 'tin' => '123-456-789-000', 'email' => 'teo@example.test',
        'mobile' => '09171234567']), [], $mid, 'coop.member'));
    eq((string)DB::value('SELECT name FROM customers WHERE id = :id', ['id' => $c['id']]), 'Salazar-Ramos, Teodora', 'the customer record follows the member\'s new name');
    eq((string)DB::value('SELECT name FROM vendors WHERE id = :id', ['id' => $v['id']]), 'Salazar-Ramos, Teodora', 'the supplier record follows it too');
    eq((string)DB::value('SELECT tin FROM vendors WHERE id = :id', ['id' => $v['id']]), '123-456-789-000', 'and the TIN');
    eq((string)DB::value('SELECT email FROM customers WHERE id = :id', ['id' => $c['id']]), 'teo@example.test', 'and the e-mail');
    eqm(DB::value('SELECT credit_limit FROM vendors WHERE id = :id', ['id' => $v['id']]), '5000', 'the defaults stay');

    // every member is a supplier: the vendor list makes the missing ones (members saved before the upgrade)
    $old = newMember('Pascual', 'Lito');
    DB::run('DELETE FROM vendors WHERE party_id = (SELECT party_id FROM members WHERE id = :m)', ['m' => $old]);
    Lookup::reset();
    $list = Lookup::vendors();
    $ov = MemberDefaults::vendorOf(Coop::member($old));
    check($ov !== null && isset($list[(int)$ov['id']]) && str_contains($list[(int)$ov['id']], 'Member'), 'a member without a supplier record gets one when a vendor list opens, described as a member');
});

test('One name: the member is listed once on the name pickers; the entry is recorded on the record of the role the member plays', function () {
    $mid = newMember('Ilagan', 'Corazon');
    $m = Coop::member($mid);
    $c = MemberDefaults::customerOf($m);
    $v = MemberDefaults::vendorOf($m);
    Names::reset();
    $opts = Names::options();
    check(isset($opts['m' . $mid]), 'the member is on the list (m code)');
    check(str_contains($opts['m' . $mid], 'Member: Ilagan, Corazon'), 'described by its member type: ' . $opts['m' . $mid]);
    check(!isset($opts['c' . $c['id']]) && !isset($opts['v' . $v['id']]) && !isset($opts['o' . $m['other_name_id']]), 'its customer, supplier and member-name records are not listed apart');
    $same = array_filter($opts, fn($l) => str_contains($l, 'Ilagan, Corazon'));
    eq(count($same), 1, 'one entry for the person');
    eq(Names::code(['customer_id' => $c['id']]), 'm' . $mid, 'a line on the member\'s customer record shows the member');
    eq(Names::code(['vendor_id' => $v['id']]), 'm' . $mid, 'a line on its supplier record too');
    eq(Names::code(['other_name_id' => $m['other_name_id']]), 'm' . $mid, 'and a line on its member name');
    check(str_contains(combo_name_options($opts)['m' . $mid], 'Ilagan, Corazon ('), 'the searchable box reads "name (member type)"');

    $ar = Accts::get('ar');
    $ap = Accts::get('ap');
    $exp = (int)DB::value("SELECT id FROM accounts WHERE account_type = 'Expense' AND is_active = 1 ORDER BY code LIMIT 1");
    eq(Names::parse('m' . $mid, $ar)['customer_id'], (int)$c['id'], 'on Accounts Receivable the member is its customer record');
    eq(Names::parse('m' . $mid, $ap)['vendor_id'], (int)$v['id'], 'on Accounts Payable its supplier record');
    eq(Names::parse('m' . $mid, null, 'payee')['vendor_id'], (int)$v['id'], 'as the payee of a check or cash voucher its supplier record');
    eq(Names::parse('m' . $mid, $exp)['other_name_id'], (int)$m['other_name_id'], 'elsewhere its member name');

    // a journal entry with the member on a receivable and on an income line
    $income = (int)DB::value("SELECT id FROM accounts WHERE account_type = 'Income' AND is_active = 1 ORDER BY code LIMIT 1");
    $lines = parse_journal_lines([
        ['account_id' => (string)$ar, 'debit' => '1250.00', 'credit' => '', 'description' => 'Rice sold on account', 'name' => 'm' . $mid],
        ['account_id' => (string)$income, 'debit' => '', 'credit' => '1250.00', 'description' => 'Rice sold on account', 'name' => 'm' . $mid],
    ]);
    eq($lines[0]['customer_id'], (int)$c['id'], 'the receivable line is recorded on the member\'s customer record (A/R rule satisfied)');
    eq($lines[1]['other_name_id'] ?? null, (int)$m['other_name_id'], 'the income line on the member\'s name');
    $jid = DB::tx(fn() => Txn::save('journal', ['txn_date' => today(), 'memo' => 'Rice on account', 'status' => 'open'], $lines));
    check($jid > 0, 'the entry is saved');
    eq((int)DB::value('SELECT customer_id FROM journal_lines WHERE txn_id = :t AND account_id = :a', ['t' => $jid, 'a' => $ar]), (int)$c['id'], 'its ledger line carries the customer record');
    $bill = parse_journal_lines([
        ['account_id' => (string)$exp, 'debit' => '300.00', 'credit' => '', 'description' => 'Vegetables bought from the member', 'name' => ''],
        ['account_id' => (string)$ap, 'debit' => '', 'credit' => '300.00', 'description' => 'Owed to the member', 'name' => 'm' . $mid],
    ]);
    eq($bill[1]['vendor_id'], (int)$v['id'], 'a payable to the member is on its supplier record (A/P rule satisfied)');
    $jid2 = DB::tx(fn() => Txn::save('journal', ['txn_date' => today(), 'memo' => 'Owed to member', 'status' => 'open'], $bill));

    // the subsidiary ledger of the member shows every record of the member as one name
    $txt = '';
    foreach ([$ar, $ap, $income] as $acc) {
        $sl = pt_report('subsidiary_ledger', ['sl_name' => 'm' . $mid, 'sl_period' => 'range', 'from' => '2026-01-01', 'to' => today(), 'account_id' => $acc]);
        $txt .= json_encode($sl['rows']);
        $groups = array_values(array_filter($sl['rows'], fn($r) => ($r['_class'] ?? '') === 'group-row' && str_contains((string)($r['date'] ?? ''), 'Ilagan')));
        eq(count($groups), 1, 'one name on the subsidiary ledger of account ' . $acc);
    }
    check(substr_count($txt, 'Rice sold on account') === 2 && str_contains($txt, 'Owed to the member'), 'the member\'s ledger has the receivable (customer record), the sale (member name) and the payable (supplier record)');
    $sched = pt_report('schedule_of_accounts', ['sl_period' => 'range', 'from' => '2026-01-01', 'to' => today(), 'account_id' => $ar]);
    $mine = array_values(array_filter($sched['rows'], fn($r) => ($r['sl_code'] ?? '') === 'm' . $mid));
    eq(count($mine), 1, 'the schedule of Accounts Receivable lists the member once');
    eqm($mine[0]['amount'], '1250', 'with what the member owes');

    // Find: the member is one name on the name filter, and finds the entries of all its records
    $fo = Find::options('name');
    check(isset($fo['m' . $mid]) && !isset($fo['c' . $c['id']]) && !isset($fo['v' . $v['id']]), 'Find lists the member once, not its customer and supplier records apart');
    $found = array_map(fn($r) => (int)$r['txn_id'], Find::run(Find::parse(['name' => ['v' => ['m' . $mid]]]))['rows']);
    check(in_array($jid, $found, true) && in_array($jid2, $found, true), 'Find by the member finds the entry on its customer record and the one on its supplier record');
    eq(Find::parse(['name' => ['v' => ['c' . $c['id']]]])['name']['v'] ?? [], ['m' . $mid], 'a saved search on the member\'s customer record now reads as the member');

    // a new customer or supplier with the member's name is pointed to the member's defaults tabs
    $cands = Party::candidatesFor(Party::fromCustomer(['name' => 'Ilagan, Corazon']));
    if ($cands) {
        check(str_contains(Party::candidatesMessage($cands, 'customer'), 'Customer Defaults'), 'the duplicate message names the member\'s Customer Defaults tab');
        check(str_contains(Party::candidatesMessage($cands, 'supplier'), 'Vendor Defaults'), 'and the Vendor Defaults tab for a supplier');
    }
    assertLedgerBalanced('after the member journal entries');
});
