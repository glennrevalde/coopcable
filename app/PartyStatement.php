<?php
declare(strict_types=1);

/**
 * The Consolidated Party Statement (Party 360): everything one party has with the cooperative, from the
 * subsidiary ledgers of each of its roles, kept apart.
 *
 *   Party owes the cooperative:  accounts receivable (each customer record), loans (each loan: principal,
 *                                interest and penalties), cable / internet service (each subscription account: its
 *                                invoices less its collections and credits, from the ledger documents of the ISP
 *                                module; those documents are left out of the accounts receivable section so nothing
 *                                counts twice)
 *   Cooperative owes the party:  accounts payable (each supplier record), savings deposits and time deposits
 *                                (each account), interest on share capital and patronage refund still payable
 *   Member equity interest:      share capital
 *
 * Every section has its own opening balance, activity and closing balance, and every line leads to its document.
 * Nothing is offset: the net position is shown for information only. Filters: business unit and branch (class) of
 * the posting document. Amounts are exact decimals.
 */
final class PartyStatement
{
    public const SIDES = [
        'owes' => 'Party owes the cooperative',
        'owed' => 'Cooperative owes the party',
        'equity' => 'Member equity interest',
    ];

    /**
     * @param array $f filters: business_unit_id, class_id (both optional)
     */
    public static function build(int $partyId, string $from, string $to, array $f = []): array
    {
        $p = Party::get($partyId) ?? fail('Party not found.');
        $f = ['business_unit_id' => !empty($f['business_unit_id']) ? (int)$f['business_unit_id'] : null, 'class_id' => !empty($f['class_id']) ? (int)$f['class_id'] : null];
        // a merged party's history is under the party it went into
        $ids = array_map('intval', DB::column("SELECT id FROM parties WHERE id = :p OR (merged_into_id = :p2 AND status = 'merged')", ['p' => $partyId, 'p2' => $partyId]));
        $sections = [];
        $sections[] = self::glSection('ar', 'Accounts receivable', 'owes', 'customer', $ids, $from, $to, $f);
        $sections[] = self::loanSection($ids, $from, $to, $f);
        $sections[] = self::ispSection($ids, $from, $to, $f);
        $sections[] = self::glSection('ap', 'Accounts payable', 'owed', 'vendor', $ids, $from, $to, $f);
        $sections[] = self::savingsSection('savings', 'Savings deposits', 'owed', ['ordinary', 'special'], $ids, $from, $to, $f);
        $sections[] = self::savingsSection('td', 'Time deposits', 'owed', ['time_deposit'], $ids, $from, $to, $f);
        $sections[] = self::distPayableSection($ids, $from, $to, $f);
        $sections[] = self::savingsSection('share_capital', 'Share capital', 'equity', ['share_capital'], $ids, $from, $to, $f);
        $sections = array_values(array_filter($sections, fn($s) => $s['groups']));
        $tot = ['owes' => '0', 'owed' => '0', 'equity' => '0'];
        foreach ($sections as $s) {
            $tot[$s['side']] = Dec::add($tot[$s['side']], $s['closing'], 2);
        }
        return [
            'party' => $p, 'roles' => Party::roles($partyId), 'from' => $from, 'to' => $to, 'filters' => $f,
            'sections' => $sections,
            'distributions' => self::distributions($ids, $from, $to),
            'totals' => $tot + ['net' => Dec::sub($tot['owes'], $tot['owed'], 2)],
        ];
    }

    // ------------------------------------------------------------------ sections

    private static function section(string $key, string $title, string $side, array $groups): array
    {
        $s = ['key' => $key, 'title' => $title, 'side' => $side, 'groups' => $groups, 'opening' => '0', 'increase' => '0', 'decrease' => '0', 'closing' => '0'];
        foreach ($groups as $g) {
            foreach (['opening', 'increase', 'decrease', 'closing'] as $k) {
                $s[$k] = Dec::add($s[$k], $g[$k], 2);
            }
        }
        return $s;
    }

    /** A group: one role record or account with its own history */
    private static function group(string $title, string $ref, string $opening, array $rows, array $extra = []): array
    {
        $bal = Dec::of($opening);
        $inc = '0';
        $dec = '0';
        foreach ($rows as &$r) {
            $inc = Dec::add($inc, $r['increase'], 2);
            $dec = Dec::add($dec, $r['decrease'], 2);
            $bal = Dec::add($bal, Dec::sub($r['increase'], $r['decrease'], 2), 2);
            $r['balance'] = $bal;
        }
        unset($r);
        return ['title' => $title, 'ref' => $ref, 'opening' => Dec::fix($opening), 'increase' => $inc, 'decrease' => $dec, 'closing' => Dec::fix($bal), 'rows' => $rows] + $extra;
    }

    /** A business unit or branch is chosen: accounts with nothing in it are left out */
    private static function filtered(array $f): bool
    {
        return !empty($f['business_unit_id']) || !empty($f['class_id']);
    }

    /** Filters on the posting document: business unit and class (branch) */
    private static function txnFilter(array $f, string $t = 't'): array
    {
        $sql = '';
        $p = [];
        if ($f['business_unit_id']) {
            $sql .= " AND $t.business_unit_id = :fbu";
            $p['fbu'] = $f['business_unit_id'];
        }
        if ($f['class_id']) {
            $sql .= " AND $t.class_id = :fcl";
            $p['fcl'] = $f['class_id'];
        }
        return [$sql, $p];
    }

    /** Accounts receivable or payable from the general ledger, one group per customer (with its jobs) or supplier record */
    private static function glSection(string $key, string $title, string $side, string $kind, array $partyIds, string $from, string $to, array $f): array
    {
        [$pin, $pp] = DB::in('pp', $partyIds);
        $accounts = $kind === 'customer' ? Accts::arIds() : Accts::apIds();
        if (!$accounts) {
            return self::section($key, $title, $side, []);
        }
        [$ain, $ap] = DB::in('ac', $accounts);
        $records = $kind === 'customer'
            ? DB::all("SELECT id, name, is_active FROM customers WHERE party_id IN $pin AND parent_id IS NULL ORDER BY id", $pp)
            : DB::all("SELECT id, name, is_active FROM vendors WHERE party_id IN $pin ORDER BY id", $pp);
        $sign = $kind === 'customer' ? 'j.debit - j.credit' : 'j.credit - j.debit';
        $filter = '';
        $fp = [];
        if ($kind === 'customer' && Party::ispReady() && IspPosting::ready()) {
            // the cable / internet invoices and collections are shown under Cable / internet service, per account
            $filter .= ' AND NOT EXISTS (SELECT 1 FROM isp_postings wp WHERE wp.txn_id = j.txn_id)';
        }
        if ($f['business_unit_id']) {
            $filter .= ' AND j.business_unit_id = :fbu';
            $fp['fbu'] = $f['business_unit_id'];
        }
        if ($f['class_id']) {
            $filter .= ' AND j.class_id = :fcl';
            $fp['fcl'] = $f['class_id'];
        }
        $groups = [];
        foreach ($records as $r) {
            $ids = [(int)$r['id']];
            if ($kind === 'customer') {
                $ids = array_merge($ids, array_map('intval', DB::column('SELECT id FROM customers WHERE parent_id = :c', ['c' => $r['id']])));
            }
            [$rin, $rp] = DB::in('rr', $ids);
            $col = $kind === 'customer' ? 'j.customer_id' : 'j.vendor_id';
            $opening = (string)DB::value("SELECT COALESCE(SUM($sign),0) FROM journal_lines j WHERE $col IN $rin AND j.account_id IN $ain AND j.txn_date < :f $filter", $rp + $ap + $fp + ['f' => $from]);
            $lines = DB::all("SELECT j.txn_date, j.debit, j.credit, j.memo, t.id AS txn_id, t.txn_type, t.txn_no, t.memo AS txn_memo, t.cp_name, bu.code AS bu_code
                                FROM journal_lines j JOIN transactions t ON t.id = j.txn_id LEFT JOIN business_units bu ON bu.id = j.business_unit_id
                               WHERE $col IN $rin AND j.account_id IN $ain AND j.txn_date BETWEEN :f AND :t $filter
                               ORDER BY j.txn_date, j.txn_id, j.id", $rp + $ap + $fp + ['f' => $from, 't' => $to]);
            $rows = [];
            foreach ($lines as $l) {
                $inc = $kind === 'customer' ? $l['debit'] : $l['credit'];
                $dec = $kind === 'customer' ? $l['credit'] : $l['debit'];
                $rows[] = ['date' => $l['txn_date'], 'txn_id' => (int)$l['txn_id'], 'txn_type' => $l['txn_type'], 'doc' => Txn::label($l['txn_type']) . ' ' . ($l['txn_no'] ?: '#' . $l['txn_id']),
                    'memo' => (string)($l['memo'] ?: $l['txn_memo']), 'unit' => (string)$l['bu_code'], 'increase' => Dec::fix($inc), 'decrease' => Dec::fix($dec)];
            }
            if (!$rows && Dec::isZero($opening) && ((int)$r['is_active'] !== 1 || self::filtered($f))) {
                continue;
            }
            $groups[] = self::group(($kind === 'customer' ? 'Customer ' : 'Supplier ') . $r['name'], ($kind === 'customer' ? 'C-' : 'V-') . $r['id'], $opening, $rows);
        }
        return self::section($key, $title, $side, $groups);
    }

    /** Savings, time deposit or share capital accounts of the party's membership, from the savings ledger */
    private static function savingsSection(string $key, string $title, string $side, array $types, array $partyIds, string $from, string $to, array $f): array
    {
        if (!Coop::ready()) {
            return self::section($key, $title, $side, []);
        }
        [$pin, $pp] = DB::in('pp', $partyIds);
        [$tin, $tp] = DB::in('ty', $types);
        $accounts = DB::all("SELECT a.id, a.account_no, a.status, p.name AS product_name FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id JOIN members m ON m.id = a.member_id
                              WHERE m.party_id IN $pin AND p.product_type IN $tin ORDER BY p.sort_order, a.account_no", $pp + $tp);
        [$fs, $fpar] = self::txnFilter($f);
        $join = $fs !== '' ? ' JOIN transactions t ON t.id = x.txn_id' : '';
        $groups = [];
        foreach ($accounts as $a) {
            $opening = (string)DB::value("SELECT COALESCE(SUM(x.credit - x.debit),0) FROM sav_txns x$join WHERE x.account_id = :a AND x.is_voided = 0 AND x.txn_date < :f$fs", ['a' => $a['id'], 'f' => $from] + $fpar);
            $lines = DB::all("SELECT x.*, tt.txn_type, tt.txn_no, bu.code AS bu_code FROM sav_txns x$join LEFT JOIN transactions tt ON tt.id = x.txn_id LEFT JOIN business_units bu ON bu.id = tt.business_unit_id
                               WHERE x.account_id = :a AND x.is_voided = 0 AND x.txn_date BETWEEN :f AND :t$fs ORDER BY x.txn_date, x.id", ['a' => $a['id'], 'f' => $from, 't' => $to] + $fpar);
            $rows = [];
            foreach ($lines as $l) {
                $rows[] = ['date' => $l['txn_date'], 'txn_id' => $l['txn_id'] ? (int)$l['txn_id'] : null, 'txn_type' => $l['txn_type'],
                    'doc' => $l['txn_type'] ? Txn::label($l['txn_type']) . ' ' . ($l['txn_no'] ?: '#' . $l['txn_id']) : ($l['or_no'] ? 'OR ' . $l['or_no'] : $l['txn_code']),
                    'memo' => (string)($l['description'] ?: $l['txn_code']), 'unit' => (string)$l['bu_code'], 'increase' => Dec::fix($l['credit']), 'decrease' => Dec::fix($l['debit'])];
            }
            if (!$rows && Dec::isZero($opening) && ($a['status'] === 'closed' || self::filtered($f))) {
                continue;
            }
            $groups[] = self::group($a['product_name'] . ' ' . $a['account_no'], (string)$a['account_no'], $opening, $rows, ['status' => $a['status']]);
        }
        return self::section($key, $title, $side, $groups);
    }

    /** Loans of the party's membership: principal, interest and penalties owed, from the loan ledger */
    private static function loanSection(array $partyIds, string $from, string $to, array $f): array
    {
        if (!Coop::ready()) {
            return self::section('loans', 'Loans receivable', 'owes', []);
        }
        [$pin, $pp] = DB::in('pp', $partyIds);
        $loans = DB::all("SELECT l.id, l.loan_no, l.status, l.principal, l.release_date, p.name AS product_name FROM loan_accounts l JOIN loan_products p ON p.id = l.product_id JOIN members m ON m.id = l.member_id
                           WHERE m.party_id IN $pin AND l.status <> 'cancelled' ORDER BY l.release_date, l.id", $pp);
        [$fs, $fpar] = self::txnFilter($f);
        $join = $fs !== '' ? ' JOIN transactions t ON t.id = x.txn_id' : '';
        $owed = '(x.principal_dr + x.interest_dr + x.penalty_dr) - (x.principal_cr + x.interest_cr + x.penalty_cr)';
        $groups = [];
        foreach ($loans as $l) {
            $opening = (string)DB::value("SELECT COALESCE(SUM($owed),0) FROM loan_txns x$join WHERE x.loan_id = :l AND x.is_voided = 0 AND x.txn_date < :f$fs", ['l' => $l['id'], 'f' => $from] + $fpar);
            $lines = DB::all("SELECT x.*, tt.txn_type, tt.txn_no, bu.code AS bu_code FROM loan_txns x$join LEFT JOIN transactions tt ON tt.id = x.txn_id LEFT JOIN business_units bu ON bu.id = tt.business_unit_id
                               WHERE x.loan_id = :l AND x.is_voided = 0 AND x.txn_date BETWEEN :f AND :t$fs ORDER BY x.txn_date, x.id", ['l' => $l['id'], 'f' => $from, 't' => $to] + $fpar);
            $rows = [];
            foreach ($lines as $x) {
                $inc = Dec::add(Dec::add($x['principal_dr'], $x['interest_dr'], 2), $x['penalty_dr'], 2);
                $dec = Dec::add(Dec::add($x['principal_cr'], $x['interest_cr'], 2), $x['penalty_cr'], 2);
                $parts = [];
                foreach (['principal' => 'principal', 'interest' => 'interest', 'penalty' => 'penalty'] as $k => $w) {
                    $net = Dec::add($x[$k . '_dr'], $x[$k . '_cr'], 2);
                    if (!Dec::isZero($net)) {
                        $parts[] = $w . ' ' . Dec::money(Dec::isZero($x[$k . '_dr']) ? $x[$k . '_cr'] : $x[$k . '_dr']);
                    }
                }
                $rows[] = ['date' => $x['txn_date'], 'txn_id' => $x['txn_id'] ? (int)$x['txn_id'] : null, 'txn_type' => $x['txn_type'],
                    'doc' => $x['txn_type'] ? Txn::label($x['txn_type']) . ' ' . ($x['txn_no'] ?: '#' . $x['txn_id']) : ucfirst((string)$x['kind']),
                    'memo' => ucfirst((string)$x['kind']) . ($parts ? ': ' . implode(', ', $parts) : '')
                        . ($x['memo'] && strcasecmp(trim((string)$x['memo']), (string)$x['kind']) !== 0 ? '. ' . $x['memo'] : ''), 'unit' => (string)$x['bu_code'],
                    'increase' => $inc, 'decrease' => $dec];
            }
            $principal = (string)DB::value("SELECT COALESCE(SUM(x.principal_dr - x.principal_cr),0) FROM loan_txns x$join WHERE x.loan_id = :l AND x.is_voided = 0 AND x.txn_date <= :t$fs", ['l' => $l['id'], 't' => $to] + $fpar);
            if (!$rows && Dec::isZero($opening) && (in_array($l['status'], ['paid', 'renewed', 'written_off'], true) || self::filtered($f))) {
                continue;
            }
            $g = self::group($l['product_name'] . ' ' . $l['loan_no'], (string)$l['loan_no'], $opening, $rows, ['status' => $l['status'], 'principal' => Dec::fix($principal)]);
            $g['charges'] = Dec::sub($g['closing'], $principal, 2);
            $groups[] = $g;
        }
        return self::section('loans', 'Loans receivable', 'owes', $groups);
    }

    /**
     * Cable / internet service: each subscription account of the party, from the ledger documents its invoices,
     * collections, credits and charges posted (isp_postings), read on the receivable accounts: an invoice raises the
     * balance, a payment or credit memo lowers it. A voided document drops out with its ledger lines.
     */
    private static function ispSection(array $partyIds, string $from, string $to, array $f): array
    {
        if (!Party::ispReady() || !IspPosting::ready()) {
            return self::section('isp', 'Cable / internet service', 'owes', []);
        }
        [$pin, $pp] = DB::in('pp', $partyIds);
        $accounts = DB::all("SELECT a.id, a.account_no, a.account_name, a.service_status FROM isp_accounts a JOIN isp_customers c ON c.id = a.isp_customer_id
                              WHERE c.party_id IN $pin ORDER BY a.account_no", $pp);
        $ar = Accts::arIds();
        if (!$accounts || !$ar) {
            return self::section('isp', 'Cable / internet service', 'owes', []);
        }
        [$ain, $ap] = DB::in('ac', $ar);
        $unitCode = '';
        if (($unit = IspSetup::unitId()) && BusinessUnits::ready()) {
            $unitCode = (string)DB::value('SELECT code FROM business_units WHERE id = :u', ['u' => $unit]);
        }
        $groups = [];
        foreach ($accounts as $a) {
            $filter = '';
            $fp = [];
            if ($f['business_unit_id']) {
                $filter .= ' AND j.business_unit_id = :fbu';
                $fp['fbu'] = $f['business_unit_id'];
            }
            if ($f['class_id']) {
                $filter .= ' AND j.class_id = :fcl';
                $fp['fcl'] = $f['class_id'];
            }
            $rowsSql = "SELECT t.id AS txn_id, t.txn_type, t.txn_no, j.txn_date AS d, t.memo, SUM(j.debit - j.credit) AS amt
                          FROM journal_lines j JOIN transactions t ON t.id = j.txn_id
                         WHERE j.account_id IN $ain AND t.is_voided = 0
                           AND j.txn_id IN (SELECT DISTINCT p.txn_id FROM isp_postings p WHERE p.isp_account_id = :acct AND p.status = 'posted')" . $filter;
            $opening = (string)(DB::value("SELECT COALESCE(SUM(amt), 0) FROM ($rowsSql AND j.txn_date < :f GROUP BY t.id, t.txn_type, t.txn_no, j.txn_date, t.memo) z",
                $ap + $fp + ['acct' => $a['id'], 'f' => $from]) ?? '0');
            $rows = [];
            foreach (DB::all($rowsSql . ' AND j.txn_date BETWEEN :f AND :t GROUP BY t.id, t.txn_type, t.txn_no, j.txn_date, t.memo ORDER BY j.txn_date, t.id',
                $ap + $fp + ['acct' => $a['id'], 'f' => $from, 't' => $to]) as $r) {
                $amt = Dec::of((string)$r['amt']);
                $rows[] = ['date' => (string)$r['d'], 'txn_id' => (int)$r['txn_id'], 'txn_type' => (string)$r['txn_type'], 'doc' => Txn::label((string)$r['txn_type']) . ' ' . $r['txn_no'],
                    'memo' => (string)$r['memo'], 'unit' => $unitCode,
                    'increase' => Dec::isNeg($amt) ? '0.00' : Dec::fix($amt), 'decrease' => Dec::isNeg($amt) ? Dec::fix(Dec::neg($amt)) : '0.00'];
            }
            $active = in_array($a['service_status'], ['active', 'suspended', 'disconnected', 'pending'], true);
            if (!$rows && Dec::isZero($opening) && (!$active || self::filtered($f))) {
                continue;
            }
            $groups[] = self::group('Subscription ' . $a['account_no'] . ', ' . $a['account_name'], (string)$a['account_no'], $opening, $rows,
                ['status' => (string)$a['service_status'], 'isp_account_id' => (int)$a['id']]);
        }
        return self::section('isp', 'Cable / internet service', 'owes', $groups);
    }

    /** Interest on share capital and patronage refund allocated as payable and not yet paid out */
    private static function distPayableSection(array $partyIds, string $from, string $to, array $f): array
    {
        if (!Coop::ready()) {
            return self::section('dist_payable', 'Interest on share capital and patronage refund payable', 'owed', []);
        }
        [$pin, $pp] = DB::in('pp', $partyIds);
        $lines = DB::all("SELECT bl.*, b.kind, b.batch_no, b.date_to, b.txn_id AS batch_txn_id, DATE(b.posted_at) AS posted_on, pt.txn_type AS paid_type, pt.txn_no AS paid_no, bt.business_unit_id, bt.class_id
                            FROM dist_batch_lines bl JOIN dist_batches b ON b.id = bl.batch_id JOIN members m ON m.id = bl.member_id
                            LEFT JOIN transactions bt ON bt.id = b.txn_id LEFT JOIN transactions pt ON pt.id = bl.paid_txn_id
                           WHERE m.party_id IN $pin AND b.status = 'posted' AND b.credit_mode = 'payable' AND bl.is_excluded = 0
                           ORDER BY b.posted_at, bl.id", $pp);
        $opening = '0';
        $rows = [];
        foreach ($lines as $l) {
            if ($f['business_unit_id'] && (int)$l['business_unit_id'] !== $f['business_unit_id']) {
                continue;
            }
            if ($f['class_id'] && (int)$l['class_id'] !== $f['class_id']) {
                continue;
            }
            $label = $l['kind'] === 'isc' ? 'Interest on share capital' : 'Patronage refund';
            $posted = (string)($l['posted_on'] ?: $l['date_to']);
            $paid = $l['paid_on'] ? (string)$l['paid_on'] : null;
            if ($posted < $from) {
                if (!$paid || $paid >= $from) {
                    $opening = Dec::add($opening, $l['net_amount'], 2);
                }
            } elseif ($posted <= $to) {
                $rows[] = ['date' => $posted, 'txn_id' => $l['batch_txn_id'] ? (int)$l['batch_txn_id'] : null, 'txn_type' => null, 'doc' => 'Batch ' . $l['batch_no'],
                    'memo' => $label . ' allocated', 'unit' => '', 'increase' => Dec::fix($l['net_amount']), 'decrease' => '0.00'];
            }
            if ($paid && $paid >= $from && $paid <= $to) {
                $rows[] = ['date' => $paid, 'txn_id' => $l['paid_txn_id'] ? (int)$l['paid_txn_id'] : null, 'txn_type' => $l['paid_type'],
                    'doc' => $l['paid_type'] ? Txn::label($l['paid_type']) . ' ' . $l['paid_no'] : 'Paid', 'memo' => $label . ' paid out (batch ' . $l['batch_no'] . ')', 'unit' => '',
                    'increase' => '0.00', 'decrease' => Dec::fix($l['net_amount'])];
            }
        }
        usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']));
        $groups = $rows || !Dec::isZero($opening) ? [self::group('Distributions payable', 'dist', $opening, $rows)] : [];
        return self::section('dist_payable', 'Interest on share capital and patronage refund payable', 'owed', $groups);
    }

    /** Every dividend (interest on share capital) and patronage refund allocated in the period, and how it was settled */
    private static function distributions(array $partyIds, string $from, string $to): array
    {
        if (!Coop::ready()) {
            return [];
        }
        [$pin, $pp] = DB::in('pp', $partyIds);
        $rows = DB::all("SELECT bl.basis_amount, bl.net_amount, bl.paid_on, b.kind, b.batch_no, b.date_from, b.date_to, b.credit_mode, b.txn_id, DATE(b.posted_at) AS posted_on
                           FROM dist_batch_lines bl JOIN dist_batches b ON b.id = bl.batch_id JOIN members m ON m.id = bl.member_id
                          WHERE m.party_id IN $pin AND b.status = 'posted' AND bl.is_excluded = 0 AND DATE(b.posted_at) BETWEEN :f AND :t
                          ORDER BY b.posted_at", $pp + ['f' => $from, 't' => $to]);
        $modes = ['savings' => 'Credited to savings', 'share_capital' => 'Credited to share capital', 'payable' => 'Payable'];
        return array_map(fn($r) => $r + ['kind_label' => $r['kind'] === 'isc' ? 'Interest on share capital' : 'Patronage refund',
            'settled' => ($modes[$r['credit_mode']] ?? $r['credit_mode']) . ($r['credit_mode'] === 'payable' ? ($r['paid_on'] ? ', paid ' . fdate($r['paid_on']) : ', not yet paid') : '')], $rows);
    }

    // ------------------------------------------------------------------ export

    /** The statement as spreadsheet rows (one sheet): headings, each section and group with its lines */
    public static function sheetRows(array $st): array
    {
        $p = $st['party'];
        $rows = [['Consolidated Party Statement'], [$p['legal_name'] . ' (' . $p['party_no'] . ')'], [fdate($st['from']) . ' through ' . fdate($st['to'])], []];
        $bold = [0, 1];
        $rows[] = ['Summary', '', '', '', '', 'Closing balance'];
        $bold[] = count($rows) - 1;
        foreach (self::SIDES as $k => $label) {
            $rows[] = [$label, '', '', '', '', (float)$st['totals'][$k]];
        }
        $rows[] = ['Net position, for information only (nothing is offset)', '', '', '', '', (float)$st['totals']['net']];
        $rows[] = [];
        foreach ($st['sections'] as $s) {
            $rows[] = [self::SIDES[$s['side']] . ': ' . $s['title']];
            $bold[] = count($rows) - 1;
            foreach ($s['groups'] as $g) {
                $rows[] = [$g['title'], 'Document', 'Description', 'Increase', 'Decrease', 'Balance'];
                $bold[] = count($rows) - 1;
                $rows[] = ['Opening balance', '', '', '', '', (float)$g['opening']];
                foreach ($g['rows'] as $r) {
                    $rows[] = [fdate($r['date']), $r['doc'], $r['memo'], (float)$r['increase'], (float)$r['decrease'], (float)$r['balance']];
                }
                $rows[] = ['Closing balance', '', '', (float)$g['increase'], (float)$g['decrease'], (float)$g['closing']];
                $bold[] = count($rows) - 1;
            }
            $rows[] = [];
        }
        return [$rows, $bold];
    }
}
