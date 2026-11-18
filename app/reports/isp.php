<?php
declare(strict_types=1);

/**
 * Cable / internet service provider reports: subscribers, billing, collections, receivables, profitability,
 * disconnections and reconnections, the network and field service. Every figure is read from the ISP records
 * (and, for the reconciliation and the branch expenses, from the general ledger). Balances "as of" a past date are
 * worked out from the documents: an invoice is open as of a date by its total less the payments applied to it and
 * the credits posted on it up to that date. Users limited to a branch see only their branch.
 */

// ====================================================================== shared helpers

function isprpt_ready(): void
{
    if (!IspSetup::ready()) {
        fail('The cable / internet tables are not installed yet.');
    }
}

/** The branches a user may report on */
function isprpt_branches(): array
{
    if (!IspSetup::ready()) {
        return [];
    }
    $all = IspSetup::branches();
    $ub = IspSetup::userBranch();
    return $ub ? array_intersect_key($all, [$ub => 1]) : $all;
}

function isprpt_plans(): array
{
    return IspSetup::ready() ? DB::pairs("SELECT id, CONCAT(code, ' - ', name) FROM isp_plans ORDER BY sort_order, code") : [];
}

/** Service areas with their place in the hierarchy ("Bulacan / Malolos / Sto. Rosario") */
function isprpt_areas(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    if (!IspSetup::ready()) {
        return $cache = [];
    }
    $rows = [];
    foreach (DB::all('SELECT id, parent_id, name FROM isp_areas') as $r) {
        $rows[(int)$r['id']] = $r;
    }
    $out = [];
    foreach ($rows as $id => $r) {
        $path = [$r['name']];
        $seen = [$id => true];
        $p = (int)$r['parent_id'];
        while ($p && isset($rows[$p]) && !isset($seen[$p])) {
            $seen[$p] = true;
            array_unshift($path, $rows[$p]['name']);
            $p = (int)$rows[$p]['parent_id'];
        }
        $out[$id] = implode(' / ', $path);
    }
    asort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $cache = $out;
}

function isprpt_statuses(): array
{
    return IspAccounts::SERVICE_STATUSES;
}

function isprpt_technicians(): array
{
    return IspSetup::ready() ? DB::pairs('SELECT id, name FROM isp_technicians ORDER BY name') : [];
}

function isprpt_collectors(): array
{
    return IspSetup::ready() ? DB::pairs("SELECT u.id, COALESCE(NULLIF(u.full_name, ''), u.username) FROM users u
                                           WHERE EXISTS (SELECT 1 FROM isp_payments y WHERE y.collector_user_id = u.id) ORDER BY 2") : [];
}

function isprpt_agents(): array
{
    return IspSetup::ready() ? DB::pairs("SELECT id, CONCAT(code, ' - ', name) FROM isp_agents ORDER BY name") : [];
}

/** The standard filters of a report about accounts: [name => [label, options]] */
function isprpt_filters(array $which = ['branch', 'area', 'plan', 'status']): array
{
    $all = [
        'branch' => ['Branch', fn() => isprpt_branches()],
        'area' => ['Area', fn() => isprpt_areas()],
        'plan' => ['Plan', fn() => isprpt_plans()],
        'status' => ['Service status', fn() => isprpt_statuses()],
    ];
    return array_intersect_key($all, array_flip($which));
}

/**
 * " AND ..." limiting an account query (alias $a) to the branch of the user and to the branch, area (with the areas
 * under it), plan and service status chosen
 */
function isprpt_where(array $f, array &$p, string $a = 'a', string $tag = 'iw'): string
{
    $sql = '';
    $ub = IspSetup::userBranch();
    if ($ub !== null) {
        $sql .= " AND ($a.branch_id = :{$tag}ub OR $a.branch_id IS NULL)";
        $p[$tag . 'ub'] = $ub;
    }
    if (($f['branch'] ?? '') !== '') {
        $sql .= " AND $a.branch_id = :{$tag}br";
        $p[$tag . 'br'] = (int)$f['branch'];
    }
    if (($f['area'] ?? '') !== '' && (int)$f['area'] > 0) {
        [$in, $ip] = DB::in($tag . 'ar', IspOutages::areaTree((int)$f['area']));
        $sql .= " AND $a.area_id IN $in";
        $p += $ip;
    }
    if (($f['plan'] ?? '') !== '') {
        $sql .= " AND $a.plan_id = :{$tag}pl";
        $p[$tag . 'pl'] = (int)$f['plan'];
    }
    if (($f['status'] ?? '') !== '' && isset(IspAccounts::SERVICE_STATUSES[$f['status']])) {
        $sql .= " AND $a.service_status = :{$tag}st";
        $p[$tag . 'st'] = $f['status'];
    }
    if (trim((string)($f['q'] ?? '')) !== '') {
        $q = '%' . trim((string)$f['q']) . '%';
        $sql .= " AND ($a.account_no LIKE :{$tag}q1 OR $a.account_name LIKE :{$tag}q2 OR $a.install_address LIKE :{$tag}q3)";
        $p[$tag . 'q1'] = $q;
        $p[$tag . 'q2'] = $q;
        $p[$tag . 'q3'] = $q;
    }
    return $sql;
}

/** A contact detail or address as the user may see it */
function isprpt_mask(?string $v): string
{
    $v = (string)$v;
    if ($v === '' || Access::special('isp_view_sensitive') || Access::special('coop_view_confidential')) {
        return $v;
    }
    return Party::mask($v);
}

/** The monthly fee of the plan of an account (member price when it applies), in SQL ($a account, $p plan aliases) */
function isprpt_fee_sql(string $a = 'a', string $p = 'p'): string
{
    return "(CASE WHEN $a.member_price = 1 AND $p.member_monthly_fee > 0 THEN $p.member_monthly_fee ELSE $p.monthly_fee END)";
}

/** The recurring extras of an account (router rental, static IP), in SQL */
function isprpt_extras_sql(string $a = 'a', string $p = 'p'): string
{
    return "((CASE WHEN $a.router_rental = 1 THEN COALESCE($p.router_rental_fee, 0) ELSE 0 END) + (CASE WHEN $a.static_ip IS NOT NULL AND $a.static_ip <> '' THEN COALESCE($p.static_ip_fee, 0) ELSE 0 END))";
}

/** The active recurring add-ons of every account on a date: account id => monthly amount */
function isprpt_addons(string $date): array
{
    return array_map('floatval', DB::pairs('SELECT account_id, SUM(amount) FROM isp_account_addons WHERE is_active = 1 AND (start_date IS NULL OR start_date <= :d1)
                                             AND (end_date IS NULL OR end_date >= :d2) GROUP BY account_id', ['d1' => $date, 'd2' => $date]));
}

/** Statuses of invoices that are in the books */
defined('ISPRPT_BOOKED') || define('ISPRPT_BOOKED', "('posted', 'partially_paid', 'paid', 'overdue', 'disputed', 'adjusted')");

/**
 * The invoices of the accounts chosen ($where on alias a, i the invoice) with their balance as of a date:
 * total less the payments applied and the credits posted up to the date. Only those still open are returned.
 */
function isprpt_open_invoices(string $asof, string $where = '', array $p = []): Generator
{
    // only the invoices that can be open at the date: open now, or settled by a payment or a credit dated after it
    $open = "('posted', 'partially_paid', 'overdue', 'disputed')";
    $past = $asof < today();
    if (!$past) {
        $cand = " AND i.status IN $open AND i.balance > 0";
    } else {
        $cand = " AND (i.status IN $open OR EXISTS (SELECT 1 FROM isp_payment_allocs al JOIN isp_payments y ON y.id = al.payment_id
                        WHERE al.invoice_id = i.id AND al.is_reversed = 0 AND y.status = 'posted' AND y.pay_date > :asof2)";
        $p['asof2'] = $asof;
        $late = array_map('intval', DB::column("SELECT DISTINCT x.invoice_id FROM isp_adjustments x LEFT JOIN transactions t ON t.id = x.txn_id
                                                 WHERE x.status = 'posted' AND x.adj_type <> 'debit' AND x.invoice_id IS NOT NULL AND COALESCE(t.txn_date, DATE(x.approved_at)) > :d", ['d' => $asof]));
        if ($late) {
            [$in, $ip] = DB::in('lateadj', $late);
            $cand .= " OR i.id IN $in";
            $p += $ip;
        }
        $cand .= ')';
    }
    $base = "FROM isp_invoices i JOIN isp_accounts a ON a.id = i.account_id JOIN isp_customers c ON c.id = a.isp_customer_id LEFT JOIN isp_plans pl ON pl.id = a.plan_id
             WHERE i.status IN " . ISPRPT_BOOKED . " AND i.invoice_date <= :asof" . $cand . $where;
    $p['asof'] = $asof;
    $paid = $cred = [];
    if ($past) {
        $ids = array_map('intval', DB::column("SELECT i.id $base", $p));
        foreach (array_chunk($ids, 2000) as $chunk) {
            [$in, $ip] = DB::in('pinv', $chunk);
            $paid += DB::pairs("SELECT al.invoice_id, SUM(al.amount) FROM isp_payment_allocs al JOIN isp_payments y ON y.id = al.payment_id
                                 WHERE al.invoice_id IN $in AND al.is_reversed = 0 AND y.status = 'posted' AND y.pay_date <= :d1 GROUP BY al.invoice_id", $ip + ['d1' => $asof]);
        }
        unset($ids);
        $cred = isprpt_credits_by_invoice($asof);
    }
    foreach (isprpt_stream("SELECT i.id, i.invoice_no, i.account_id, i.kind, i.invoice_date, i.due_date, i.period_start, i.period_end, i.total, i.balance, i.status,
                                   a.account_no, a.account_name, a.service_status, a.branch_id, a.area_id, a.plan_id, a.isp_customer_id, pl.name AS plan_name, c.mobile
                              $base ORDER BY a.account_no, i.due_date, i.id", $p) as $r) {
        if ($past) {
            $r['paid_at'] = r2((float)($paid[$r['id']] ?? 0) + (float)($cred[$r['id']] ?? 0));
            $r['bal_at'] = r2((float)$r['total'] - $r['paid_at']);
        } else {
            $r['paid_at'] = r2((float)$r['total'] - (float)$r['balance']);
            $r['bal_at'] = (float)$r['balance'];
        }
        if ($r['bal_at'] > 0.004) {
            yield $r;
        }
    }
}

/** Credits (credit memos of the adjustments) posted on each invoice up to a date: invoice id => amount */
function isprpt_credits_by_invoice(string $asof): array
{
    return DB::pairs("SELECT adj.invoice_id, SUM(adj.amount) FROM isp_adjustments adj LEFT JOIN transactions t ON t.id = adj.txn_id
                       WHERE adj.status = 'posted' AND adj.adj_type <> 'debit' AND adj.invoice_id IS NOT NULL AND COALESCE(t.txn_date, DATE(adj.approved_at)) <= :d GROUP BY adj.invoice_id", ['d' => $asof]);
}

/** The unapplied credit (advance payments) of every account as of a date: account id => amount */
function isprpt_unapplied_at(string $asof): array
{
    if ($asof >= today()) {
        return array_map('floatval', DB::pairs("SELECT account_id, SUM(unapplied_amount) FROM isp_payments WHERE status = 'posted' AND pay_date <= :d GROUP BY account_id HAVING SUM(unapplied_amount) > 0",
            ['d' => $asof]));
    }
    $pays = DB::pairs("SELECT account_id, SUM(amount) FROM isp_payments WHERE status = 'posted' AND pay_date <= :d GROUP BY account_id", ['d' => $asof]);
    $alloc = DB::pairs("SELECT y.account_id, SUM(al.amount) FROM isp_payment_allocs al JOIN isp_payments y ON y.id = al.payment_id JOIN isp_invoices i ON i.id = al.invoice_id
                         WHERE al.is_reversed = 0 AND y.status = 'posted' AND y.pay_date <= :d1 AND i.invoice_date <= :d2 GROUP BY y.account_id", ['d1' => $asof, 'd2' => $asof]);
    $out = [];
    foreach ($pays as $acct => $amt) {
        $left = r2((float)$amt - (float)($alloc[$acct] ?? 0));
        if ($left > 0.004) {
            $out[$acct] = $left;
        }
    }
    return $out;
}

/** The balance of the A/R - Subscribers account in the general ledger as of a date */
function isprpt_gl_ar(string $asof): float
{
    return r2((float)DB::value('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines WHERE account_id = :a AND txn_date <= :d', ['a' => IspSetup::account('isp_ar'), 'd' => $asof]));
}

/** Days from one date to another */
function isprpt_days(?string $from, string $to): int
{
    if (!$from) {
        return 0;
    }
    return (int)floor((strtotime($to) - strtotime(substr($from, 0, 10))) / 86400);
}

function isprpt_bucket(int $daysPastDue): string
{
    return match (true) {
        $daysPastDue <= 0 => 'current',
        $daysPastDue <= 30 => 'd30',
        $daysPastDue <= 60 => 'd60',
        $daysPastDue <= 90 => 'd90',
        default => 'd91',
    };
}

defined('ISPRPT_BUCKETS') || define('ISPRPT_BUCKETS', ['current' => 'Current', 'd30' => '1 - 30', 'd60' => '31 - 60', 'd90' => '61 - 90', 'd91' => 'Over 90']);

function isprpt_user_names(): array
{
    static $u = null;
    return $u ??= DB::pairs("SELECT id, COALESCE(NULLIF(full_name, ''), username) FROM users");
}

/** Links of the ISP records (null when the user may not open the page) */
function isprpt_link(string $what, $id): ?string
{
    if (!$id) {
        return null;
    }
    $page = ['account' => 'isp.account', 'invoice' => 'ispbill.invoices', 'payment' => 'ispbill.payments', 'wo' => 'ispfield.workorders', 'ticket' => 'ispfield.tickets',
        'op' => 'ispnet.operations', 'outage' => 'ispnet.outages', 'application' => 'isp.applications', 'adjustment' => 'ispbill.adjustments', 'contract' => 'isp.contracts',
        'agent' => 'isp.agents', 'equipment' => 'ispfield.equipment', 'shift' => 'ispbill.shifts', 'disc' => 'ispnet.disconnections', 'recon' => 'ispnet.reconnections',
        'node' => 'ispnet.infra', 'run' => 'ispbill.runs'][$what] ?? null;
    return $page ? Report::link($page, ['id' => (int)$id]) : null;
}

defined('ISPRPT_MAX_ROWS') || define('ISPRPT_MAX_ROWS', 15000);

/** The rows of a query one at a time (a long result is not held as PHP arrays all at once) */
function isprpt_stream(string $sql, array $p = []): Generator
{
    $st = DB::run($sql, $p);
    while (($r = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
        yield $r;
    }
    $st->closeCursor();
}

/** Cuts a long list to ISPRPT_MAX_ROWS lines (queries ask for one more to know); returns the note to show, or '' */
function isprpt_capped(array &$rows, bool $totalsOfAll = true): string
{
    if (count($rows) <= ISPRPT_MAX_ROWS) {
        return '';
    }
    $rows = array_slice($rows, 0, ISPRPT_MAX_ROWS);
    return ' <span class="text-danger">Only the first ' . number_format(ISPRPT_MAX_ROWS) . ' lines are listed' . ($totalsOfAll ? ' (the totals are of all)' : ' and totalled')
        . ': choose a shorter period or narrow the filters.</span>';
}

/** The months of a report's period up to the current month: 'Y-m' => 'M Y' */
function isprpt_months(array $f): array
{
    $to = min($f['to'], date('Y-m-t'));
    $m = Report::periods($f['from'], $to < $f['from'] ? $f['from'] : $to, 'month');
    return $m ?: [substr($f['from'], 0, 7) => date('M Y', strtotime($f['from']))];
}

function isprpt_pct(float $part, float $whole): string
{
    return abs($whole) > 0.004 ? (string)round($part / $whole * 100, 1) : '';
}

// ====================================================================== subscriber reports

Report::define('isp_master', [
    'filters' => ['search'],
    'filter_labels' => ['search' => 'Account, name or address'],
    'extra_filters' => isprpt_filters() + ['class' => ['Classification', fn() => IspAccounts::CLASSIFICATIONS]],
    'run' => function (array $f): array {
        isprpt_ready();
        $p = [];
        $w = isprpt_where($f, $p);
        if (($f['class'] ?? '') !== '') {
            $w .= ' AND c.classification = :cls';
            $p['cls'] = $f['class'];
        }
        $rows = DB::all("SELECT a.*, c.customer_no, c.classification, c.mobile, c.email, c.party_id, pl.code AS plan_code, pl.name AS plan_name, " . isprpt_fee_sql('a', 'pl') . " AS fee,
                                ar.name AS area_name, b.name AS branch_name, ag.name AS agent_name
                           FROM isp_accounts a JOIN isp_customers c ON c.id = a.isp_customer_id LEFT JOIN isp_plans pl ON pl.id = a.plan_id
                           LEFT JOIN isp_areas ar ON ar.id = a.area_id LEFT JOIN coop_branches b ON b.id = a.branch_id LEFT JOIN isp_agents ag ON ag.id = a.agent_id
                          WHERE 1 = 1$w ORDER BY a.account_no LIMIT " . (ISPRPT_MAX_ROWS + 1), $p);
        $capNote = isprpt_capped($rows, false);
        $bal = DB::pairs("SELECT account_id, SUM(balance) FROM isp_invoices WHERE status IN ('posted', 'partially_paid', 'overdue', 'disputed') GROUP BY account_id");
        $credit = isprpt_unapplied_at(today());
        $members = DB::pairs("SELECT party_id, member_no FROM members WHERE party_id IS NOT NULL");
        $areas = isprpt_areas();
        $out = [];
        $tot = ['acct' => 'TOTAL (0)', 'fee' => 0.0, 'balance' => 0.0, 'credit' => 0.0];
        foreach ($rows as $r) {
            $b = (float)($bal[$r['id']] ?? 0);
            $cr = (float)($credit[$r['id']] ?? 0);
            $out[] = ['acct' => $r['account_no'], 'customer' => $r['customer_no'], 'name' => $r['account_name'], 'class' => IspAccounts::CLASSIFICATIONS[$r['classification']] ?? $r['classification'],
                'member' => $r['party_id'] ? (string)($members[$r['party_id']] ?? '') : '', 'plan' => trim($r['plan_code'] . ' ' . $r['plan_name']), 'fee' => (float)$r['fee'],
                'area' => $areas[$r['area_id']] ?? '', 'address' => isprpt_mask($r['install_address']), 'mobile' => isprpt_mask($r['mobile']), 'email' => isprpt_mask($r['email']),
                'status' => IspAccounts::SERVICE_STATUSES[$r['service_status']] ?? $r['service_status'], 'network' => IspAccounts::NETWORK_STATES[$r['network_state']] ?? $r['network_state'],
                'activated' => $r['activation_date'], 'contract_end' => $r['contract_end'], 'branch' => $r['branch_name'], 'agent' => $r['agent_name'], 'pppoe' => $r['pppoe_username'],
                'balance' => $b, 'credit' => $cr ?: '',
                '_links' => ['acct' => isprpt_link('account', $r['id'])]];
            $tot['fee'] += (float)$r['fee'];
            $tot['balance'] += $b;
            $tot['credit'] += $cr;
        }
        $tot['acct'] = 'TOTAL (' . count($out) . ')';
        return ['columns' => [['key' => 'acct', 'label' => 'Account no.'], ['key' => 'customer', 'label' => 'Customer no.', 'optional' => true], ['key' => 'name', 'label' => 'Subscriber'],
            ['key' => 'class', 'label' => 'Classification', 'optional' => true], ['key' => 'member', 'label' => 'Member no.'], ['key' => 'plan', 'label' => 'Plan'], ['key' => 'fee', 'label' => 'Monthly fee', 'type' => 'money'],
            ['key' => 'area', 'label' => 'Area'], ['key' => 'address', 'label' => 'Installation address', 'optional' => true], ['key' => 'mobile', 'label' => 'Mobile'], ['key' => 'email', 'label' => 'E-mail', 'optional' => true],
            ['key' => 'status', 'label' => 'Service'], ['key' => 'network', 'label' => 'Network'], ['key' => 'activated', 'label' => 'Activated', 'type' => 'date'],
            ['key' => 'contract_end', 'label' => 'Contract ends', 'type' => 'date', 'optional' => true], ['key' => 'branch', 'label' => 'Branch', 'optional' => true], ['key' => 'agent', 'label' => 'Agent', 'optional' => true],
            ['key' => 'pppoe', 'label' => 'PPPoE user', 'optional' => true], ['key' => 'balance', 'label' => 'Balance', 'type' => 'money'], ['key' => 'credit', 'label' => 'Advance credit', 'type' => 'money']],
            'rows' => $out, 'totals' => array_map(fn($v) => is_float($v) ? r2($v) : $v, $tot),
            'notes' => 'Every subscription account with its subscriber, plan, location, service and network state and its open balance today. Contact details are masked for users without the right to see them.' . $capNote];
    },
]);

Report::define('isp_active', [
    'extra_filters' => isprpt_filters(['branch', 'area', 'plan']),
    'run' => function (array $f): array {
        isprpt_ready();
        $p = [];
        $w = isprpt_where($f, $p);
        $rows = DB::all("SELECT a.id, a.account_no, a.account_name, a.activation_date, a.network_state, a.area_id, pl.name AS plan_name, pl.download_mbps, pl.upload_mbps,
                                " . isprpt_fee_sql('a', 'pl') . " AS fee, " . isprpt_extras_sql('a', 'pl') . " AS extras, b.name AS branch_name
                           FROM isp_accounts a LEFT JOIN isp_plans pl ON pl.id = a.plan_id LEFT JOIN coop_branches b ON b.id = a.branch_id
                          WHERE a.service_status = 'active'$w ORDER BY pl.sort_order, pl.name, a.account_no LIMIT " . (ISPRPT_MAX_ROWS + 1), $p);
        $capNote = isprpt_capped($rows, false);
        $addons = isprpt_addons(today());
        $last = [];
        foreach (DB::all("SELECT account_id, MAX(pay_date) AS d FROM isp_payments WHERE status = 'posted' GROUP BY account_id") as $x) {
            $last[$x['account_id']] = $x['d'];
        }
        $bal = DB::pairs("SELECT account_id, SUM(balance) FROM isp_invoices WHERE status IN ('posted', 'partially_paid', 'overdue', 'disputed') GROUP BY account_id");
        $areas = isprpt_areas();
        $out = [];
        $tot = ['acct' => '', 'fee' => 0.0, 'extras' => 0.0, 'mrr' => 0.0, 'balance' => 0.0];
        foreach ($rows as $r) {
            $extras = r2((float)$r['extras'] + (float)($addons[$r['id']] ?? 0));
            $mrr = r2((float)$r['fee'] + $extras);
            $months = $r['activation_date'] ? max(0, (int)floor(isprpt_days($r['activation_date'], today()) / 30.4375)) : 0;
            $out[] = ['acct' => $r['account_no'], 'name' => $r['account_name'], 'plan' => $r['plan_name'], 'speed' => (float)$r['download_mbps'] . '/' . (float)$r['upload_mbps'] . ' Mbps',
                'fee' => (float)$r['fee'], 'extras' => $extras ?: '', 'mrr' => $mrr, 'activated' => $r['activation_date'], 'months' => $months, 'area' => $areas[$r['area_id']] ?? '',
                'branch' => $r['branch_name'], 'network' => IspAccounts::NETWORK_STATES[$r['network_state']] ?? $r['network_state'], 'last_paid' => $last[$r['id']] ?? '',
                'balance' => (float)($bal[$r['id']] ?? 0), '_links' => ['acct' => isprpt_link('account', $r['id'])]];
            $tot['fee'] += (float)$r['fee'];
            $tot['extras'] += $extras;
            $tot['mrr'] += $mrr;
            $tot['balance'] += (float)($bal[$r['id']] ?? 0);
        }
        $tot['acct'] = 'TOTAL (' . count($out) . ' active)';
        $nonEnabled = count(array_filter($rows, fn($r) => $r['network_state'] !== 'enabled'));
        return ['columns' => [['key' => 'acct', 'label' => 'Account no.'], ['key' => 'name', 'label' => 'Subscriber'], ['key' => 'plan', 'label' => 'Plan'], ['key' => 'speed', 'label' => 'Speed'],
            ['key' => 'fee', 'label' => 'Plan fee', 'type' => 'money'], ['key' => 'extras', 'label' => 'Add-ons', 'type' => 'money'], ['key' => 'mrr', 'label' => 'Monthly recurring', 'type' => 'money'],
            ['key' => 'activated', 'label' => 'Activated', 'type' => 'date'], ['key' => 'months', 'label' => 'Months', 'type' => 'int'], ['key' => 'area', 'label' => 'Area'],
            ['key' => 'branch', 'label' => 'Branch', 'optional' => true], ['key' => 'network', 'label' => 'Network'], ['key' => 'last_paid', 'label' => 'Last payment', 'type' => 'date'],
            ['key' => 'balance', 'label' => 'Balance', 'type' => 'money']],
            'rows' => $out, 'totals' => array_map(fn($v) => is_float($v) ? r2($v) : $v, $tot),
            'notes' => 'Accounts with active service today. Monthly recurring = the plan fee (member price where it applies) plus router rental, static IP and active add-ons.'
                . ($nonEnabled ? ' <span class="text-danger">' . $nonEnabled . ' active account(s) are not enabled on the network: check Network Operations.</span>' : '') . $capNote];
    },
]);

Report::define('isp_new_activations', [
    'filters' => ['range'],
    'extra_filters' => isprpt_filters(['branch', 'area', 'plan']) + ['agent' => ['Agent', fn() => isprpt_agents()]],
    'run' => function (array $f): array {
        isprpt_ready();
        $p = ['f' => $f['from'], 't' => $f['to']];
        $w = isprpt_where($f, $p);
        if (($f['agent'] ?? '') !== '') {
            $w .= ' AND a.agent_id = :ag';
            $p['ag'] = (int)$f['agent'];
        }
        $rows = DB::all("SELECT a.id, a.account_no, a.account_name, a.activation_date, a.service_status, a.area_id, a.application_id, pl.name AS plan_name, " . isprpt_fee_sql('a', 'pl') . " AS fee,
                                b.name AS branch_name, ag.name AS agent_name, ap.application_no, ap.channel, ap.created_at AS applied_at,
                                (SELECT w.id FROM isp_work_orders w WHERE w.account_id = a.id AND w.wo_type = 'installation' AND w.status <> 'cancelled' ORDER BY w.id DESC LIMIT 1) AS wo_id
                           FROM isp_accounts a LEFT JOIN isp_plans pl ON pl.id = a.plan_id LEFT JOIN coop_branches b ON b.id = a.branch_id LEFT JOIN isp_agents ag ON ag.id = a.agent_id
                           LEFT JOIN isp_applications ap ON ap.id = a.application_id
                          WHERE a.activation_date BETWEEN :f AND :t$w ORDER BY a.activation_date, a.account_no LIMIT " . (ISPRPT_MAX_ROWS + 1), $p);
        $capNote = isprpt_capped($rows, false);
        $wo = [];
        $ids = array_filter(array_column($rows, 'wo_id'));
        if ($ids) {
            [$in, $ip] = DB::in('w', $ids);
            foreach (DB::all("SELECT w.id, w.wo_no, w.completed_at, t.name AS tech FROM isp_work_orders w LEFT JOIN isp_technicians t ON t.id = w.technician_id WHERE w.id IN $in", $ip) as $x) {
                $wo[$x['id']] = $x;
            }
        }
        $initial = DB::pairs("SELECT account_id, SUM(total) FROM isp_invoices WHERE kind = 'initial' AND status IN " . ISPRPT_BOOKED . " GROUP BY account_id");
        $areas = isprpt_areas();
        $out = [];
        $tot = ['date' => '', 'fee' => 0.0, 'initial' => 0.0];
        foreach ($rows as $r) {
            $x = $wo[$r['wo_id']] ?? null;
            $out[] = ['date' => $r['activation_date'], 'acct' => $r['account_no'], 'name' => $r['account_name'], 'plan' => $r['plan_name'], 'fee' => (float)$r['fee'],
                'area' => $areas[$r['area_id']] ?? '', 'branch' => $r['branch_name'], 'agent' => $r['agent_name'], 'application' => $r['application_no'],
                'channel' => $r['channel'] ? (IspApplications::CHANNELS[$r['channel']] ?? $r['channel']) : '', 'days' => $r['applied_at'] ? max(0, isprpt_days($r['applied_at'], $r['activation_date'])) : '',
                'wo' => $x['wo_no'] ?? '', 'tech' => $x['tech'] ?? '', 'initial' => (float)($initial[$r['id']] ?? 0),
                'status' => IspAccounts::SERVICE_STATUSES[$r['service_status']] ?? $r['service_status'],
                '_links' => ['acct' => isprpt_link('account', $r['id']), 'wo' => isprpt_link('wo', $r['wo_id']), 'application' => isprpt_link('application', $r['application_id'])]];
            $tot['fee'] += (float)$r['fee'];
            $tot['initial'] += (float)($initial[$r['id']] ?? 0);
        }
        $tot['date'] = 'TOTAL (' . count($out) . ')';
        return ['columns' => [['key' => 'date', 'label' => 'Activated', 'type' => 'date'], ['key' => 'acct', 'label' => 'Account no.'], ['key' => 'name', 'label' => 'Subscriber'], ['key' => 'plan', 'label' => 'Plan'],
            ['key' => 'fee', 'label' => 'Monthly fee', 'type' => 'money'], ['key' => 'area', 'label' => 'Area'], ['key' => 'branch', 'label' => 'Branch', 'optional' => true], ['key' => 'agent', 'label' => 'Agent'],
            ['key' => 'application', 'label' => 'Application'], ['key' => 'channel', 'label' => 'Channel', 'optional' => true], ['key' => 'days', 'label' => 'Days from application', 'type' => 'int'],
            ['key' => 'wo', 'label' => 'Installation WO'], ['key' => 'tech', 'label' => 'Technician'], ['key' => 'initial', 'label' => 'Initial invoice', 'type' => 'money'], ['key' => 'status', 'label' => 'Service now']],
            'rows' => $out, 'totals' => array_map(fn($v) => is_float($v) ? r2($v) : $v, $tot),
            'notes' => 'Accounts activated in the period, with the application and installation behind each, and the initial invoice (installation, deposit and the first period).' . $capNote];
    },
]);

Report::define('isp_cancelled', [
    'filters' => ['range'],
    'extra_filters' => isprpt_filters(['branch', 'area', 'plan']),
    'run' => function (array $f): array {
        isprpt_ready();
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59', 'f2' => $f['from'] . ' 00:00:00', 't2' => $f['to'] . ' 23:59:59'];
        $w = isprpt_where($f, $p);
        $rows = DB::all("SELECT a.id, a.account_no, a.account_name, a.service_status, a.activation_date, a.terminated_at, a.termination_reason, a.updated_at, a.deposit_status, a.security_deposit,
                                a.lock_in_until, a.area_id, pl.name AS plan_name, b.name AS branch_name
                           FROM isp_accounts a LEFT JOIN isp_plans pl ON pl.id = a.plan_id LEFT JOIN coop_branches b ON b.id = a.branch_id
                          WHERE ((a.service_status = 'terminated' AND a.terminated_at BETWEEN :f AND :t)
                              OR (a.service_status = 'cancelled' AND COALESCE(a.terminated_at, a.updated_at) BETWEEN :f2 AND :t2))$w
                          ORDER BY COALESCE(a.terminated_at, a.updated_at), a.account_no LIMIT " . (ISPRPT_MAX_ROWS + 1), $p);
        $capNote = isprpt_capped($rows, false);
        $bal = DB::pairs("SELECT account_id, SUM(balance) FROM isp_invoices WHERE status IN ('posted', 'partially_paid', 'overdue', 'disputed') GROUP BY account_id");
        $etf = DB::pairs("SELECT account_id, SUM(amount) FROM isp_charges WHERE charge_type = 'termination_fee' AND status <> 'cancelled' GROUP BY account_id");
        $equip = DB::pairs("SELECT account_id, COUNT(*) FROM isp_equipment WHERE ownership = 'company' AND status IN ('installed', 'for_recovery') AND account_id IS NOT NULL GROUP BY account_id");
        $out = [];
        $tot = ['date' => '', 'etf' => 0.0, 'balance' => 0.0, 'equip' => 0];
        foreach ($rows as $r) {
            $when = $r['terminated_at'] ?: $r['updated_at'];
            $months = $r['activation_date'] ? max(0, (int)floor(isprpt_days($r['activation_date'], substr((string)$when, 0, 10)) / 30.4375)) : 0;
            $out[] = ['date' => substr((string)$when, 0, 10), 'acct' => $r['account_no'], 'name' => $r['account_name'], 'plan' => $r['plan_name'],
                'kind' => $r['service_status'] === 'cancelled' ? 'Cancelled before activation' : 'Terminated', 'activated' => $r['activation_date'], 'months' => $r['activation_date'] ? $months : '',
                'lockin' => $r['lock_in_until'] && $r['lock_in_until'] >= substr((string)$when, 0, 10) ? 'Within lock-in' : '', 'reason' => $r['termination_reason'],
                'etf' => (float)($etf[$r['id']] ?? 0) ?: '', 'deposit' => $r['deposit_status'] && $r['deposit_status'] !== 'none' ? ucfirst((string)$r['deposit_status']) . ((float)$r['security_deposit'] > 0 ? ' ' . money($r['security_deposit']) : '') : '',
                'balance' => (float)($bal[$r['id']] ?? 0), 'equip' => (int)($equip[$r['id']] ?? 0) ?: '', 'branch' => $r['branch_name'],
                '_links' => ['acct' => isprpt_link('account', $r['id'])]];
            $tot['etf'] += (float)($etf[$r['id']] ?? 0);
            $tot['balance'] += (float)($bal[$r['id']] ?? 0);
            $tot['equip'] += (int)($equip[$r['id']] ?? 0);
        }
        $tot['date'] = 'TOTAL (' . count($out) . ')';
        $tot['etf'] = r2($tot['etf']);
        $tot['balance'] = r2($tot['balance']);
        return ['columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'acct', 'label' => 'Account no.'], ['key' => 'name', 'label' => 'Subscriber'], ['key' => 'plan', 'label' => 'Plan'],
            ['key' => 'kind', 'label' => 'Kind'], ['key' => 'activated', 'label' => 'Activated', 'type' => 'date'], ['key' => 'months', 'label' => 'Months in service', 'type' => 'int'],
            ['key' => 'lockin', 'label' => 'Lock-in'], ['key' => 'reason', 'label' => 'Reason'], ['key' => 'etf', 'label' => 'Early termination fee', 'type' => 'money'],
            ['key' => 'deposit', 'label' => 'Deposit', 'optional' => true], ['key' => 'balance', 'label' => 'Balance left', 'type' => 'money'], ['key' => 'equip', 'label' => 'Equipment to recover', 'type' => 'int'],
            ['key' => 'branch', 'label' => 'Branch', 'optional' => true]],
            'rows' => $out, 'totals' => $tot,
            'notes' => 'Subscriptions terminated in the period, and applications cancelled before their activation. Company-owned equipment still at the address is listed for recovery.' . $capNote];
    },
]);

Report::define('isp_growth', [
    'filters' => ['range'],
    'default_dates' => 'this_year',
    'sortable' => false,
    'extra_filters' => isprpt_filters(['branch', 'area', 'plan']),
    'run' => function (array $f): array {
        isprpt_ready();
        $months = isprpt_months($f);
        $start = array_key_first($months) . '-01';
        $p = ['s' => $start, 's2' => $start . ' 00:00:00'];
        $w = isprpt_where($f, $p);
        $opening = (int)DB::value("SELECT COUNT(*) FROM isp_accounts a WHERE a.activation_date < :s AND (a.terminated_at IS NULL OR a.terminated_at >= :s2)$w", $p);
        $p2 = ['f' => $start, 't' => $f['to']];
        $w2 = isprpt_where($f, $p2);
        $new = DB::pairs("SELECT DATE_FORMAT(a.activation_date, '%Y-%m'), COUNT(*) FROM isp_accounts a WHERE a.activation_date BETWEEN :f AND :t$w2 GROUP BY 1", $p2);
        $p3 = ['f' => $start . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $w3 = isprpt_where($f, $p3);
        $churn = DB::pairs("SELECT DATE_FORMAT(a.terminated_at, '%Y-%m'), COUNT(*) FROM isp_accounts a WHERE a.activation_date IS NOT NULL AND a.terminated_at BETWEEN :f AND :t$w3 GROUP BY 1", $p3);
        $p4 = ['f' => $start . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $w4 = isprpt_where($f, $p4);
        $disc = DB::pairs("SELECT DATE_FORMAT(q.executed_at, '%Y-%m'), COUNT(*) FROM isp_disc_queue q JOIN isp_accounts a ON a.id = q.account_id
                            WHERE q.status = 'disconnected' AND q.executed_at BETWEEN :f AND :t$w4 GROUP BY 1", $p4);
        $p5 = ['f' => $start . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $w5 = isprpt_where($f, $p5);
        $recon = DB::pairs("SELECT DATE_FORMAT(r.restored_at, '%Y-%m'), COUNT(*) FROM isp_reconnections r JOIN isp_accounts a ON a.id = r.account_id
                             WHERE r.status = 'restored' AND r.restored_at BETWEEN :f AND :t$w5 GROUP BY 1", $p5);
        $rows = [];
        $open = $opening;
        $tot = ['month' => 'TOTAL', 'opening' => $opening, 'new' => 0, 'churn' => 0, 'net' => 0, 'closing' => 0, 'disc' => 0, 'recon' => 0];
        foreach ($months as $ym => $label) {
            $n = (int)($new[$ym] ?? 0);
            $c = (int)($churn[$ym] ?? 0);
            $close = $open + $n - $c;
            $rows[] = ['month' => $label, 'opening' => $open, 'new' => $n, 'churn' => $c, 'net' => $n - $c, 'closing' => $close, 'growth' => isprpt_pct($n - $c, $open),
                'churn_rate' => isprpt_pct($c, $open), 'disc' => (int)($disc[$ym] ?? 0), 'recon' => (int)($recon[$ym] ?? 0)];
            $tot['new'] += $n;
            $tot['churn'] += $c;
            $tot['disc'] += (int)($disc[$ym] ?? 0);
            $tot['recon'] += (int)($recon[$ym] ?? 0);
            $open = $close;
        }
        $tot['net'] = $tot['new'] - $tot['churn'];
        $tot['closing'] = $open;
        $tot['growth'] = isprpt_pct($tot['net'], $opening);
        $tot['churn_rate'] = isprpt_pct($tot['churn'], $opening);
        return ['columns' => [['key' => 'month', 'label' => 'Month'], ['key' => 'opening', 'label' => 'Subscribers at start', 'type' => 'int'], ['key' => 'new', 'label' => 'New activations', 'type' => 'int'],
            ['key' => 'churn', 'label' => 'Terminated', 'type' => 'int'], ['key' => 'net', 'label' => 'Net change', 'type' => 'int'], ['key' => 'closing', 'label' => 'Subscribers at end', 'type' => 'int'],
            ['key' => 'growth', 'label' => 'Growth', 'type' => 'pct'], ['key' => 'churn_rate', 'label' => 'Churn', 'type' => 'pct'], ['key' => 'disc', 'label' => 'Disconnected (non-payment etc.)', 'type' => 'int'],
            ['key' => 'recon', 'label' => 'Reconnected', 'type' => 'int']],
            'rows' => $rows, 'totals' => $tot,
            'notes' => 'Subscribers are the accounts activated and not yet terminated; a disconnected or suspended account is still a subscriber. Growth and churn are against the subscribers at the start of the month (of the period on the total line).'];
    },
]);

Report::define('isp_plan_dist', [
    'extra_filters' => isprpt_filters(['branch', 'area']),
    'run' => function (array $f): array {
        isprpt_ready();
        $p = [];
        $w = isprpt_where($f, $p);
        $rows = DB::all("SELECT pl.id, pl.code, pl.name, pl.download_mbps, pl.monthly_fee, pl.status AS plan_status,
                                SUM(a.service_status = 'active') AS active, SUM(a.service_status = 'suspended') AS suspended, SUM(a.service_status = 'disconnected') AS disconnected,
                                SUM(a.service_status = 'pending') AS pending, COUNT(a.id) AS total,
                                SUM(CASE WHEN a.service_status = 'active' THEN " . isprpt_fee_sql('a', 'pl') . " + " . isprpt_extras_sql('a', 'pl') . " ELSE 0 END) AS mrr
                           FROM isp_plans pl JOIN isp_accounts a ON a.plan_id = pl.id AND a.service_status IN ('active', 'suspended', 'disconnected', 'pending')$w
                          GROUP BY pl.id, pl.code, pl.name, pl.download_mbps, pl.monthly_fee, pl.status ORDER BY active DESC, pl.sort_order, pl.code", $p);
        $addons = isprpt_addons(today());
        if ($addons) {
            $p2 = [];
            $w2 = isprpt_where($f, $p2);
            foreach (DB::all("SELECT a.id, a.plan_id FROM isp_accounts a WHERE a.service_status = 'active'$w2", $p2) as $a) {
                foreach ($rows as &$r) {
                    if ((int)$r['id'] === (int)$a['plan_id']) {
                        $r['mrr'] = (float)$r['mrr'] + (float)($addons[$a['id']] ?? 0);
                    }
                }
                unset($r);
            }
        }
        $totActive = array_sum(array_column($rows, 'active'));
        $totMrr = array_sum(array_map('floatval', array_column($rows, 'mrr')));
        $out = [];
        $chart = [];
        foreach ($rows as $r) {
            $out[] = ['plan' => $r['code'] . ' - ' . $r['name'], 'speed' => (float)$r['download_mbps'] . ' Mbps', 'fee' => (float)$r['monthly_fee'],
                'offered' => IspPlans::STATUSES[$r['plan_status']] ?? $r['plan_status'], 'active' => (int)$r['active'], 'share' => isprpt_pct((float)$r['active'], (float)$totActive),
                'suspended' => (int)$r['suspended'], 'disconnected' => (int)$r['disconnected'], 'pending' => (int)$r['pending'], 'total' => (int)$r['total'],
                'mrr' => r2((float)$r['mrr']), 'mrr_share' => isprpt_pct((float)$r['mrr'], $totMrr), 'arpu' => (int)$r['active'] ? r2((float)$r['mrr'] / (int)$r['active']) : '',
                '_links' => ['plan' => Report::link('rpt.isp_active', ['plan' => $r['id']])]];
            $chart[$r['name']] = r2((float)$r['mrr']);
        }
        $tot = ['plan' => 'TOTAL', 'active' => $totActive, 'share' => $totActive ? '100' : '', 'suspended' => array_sum(array_column($rows, 'suspended')),
            'disconnected' => array_sum(array_column($rows, 'disconnected')), 'pending' => array_sum(array_column($rows, 'pending')), 'total' => array_sum(array_column($rows, 'total')),
            'mrr' => r2($totMrr), 'mrr_share' => $totMrr > 0 ? '100' : '', 'arpu' => $totActive ? r2($totMrr / $totActive) : ''];
        return ['columns' => [['key' => 'plan', 'label' => 'Plan'], ['key' => 'speed', 'label' => 'Speed'], ['key' => 'fee', 'label' => 'List price', 'type' => 'money'], ['key' => 'offered', 'label' => 'Offered', 'optional' => true],
            ['key' => 'active', 'label' => 'Active', 'type' => 'int'], ['key' => 'share', 'label' => '% of active', 'type' => 'pct'], ['key' => 'suspended', 'label' => 'Suspended', 'type' => 'int'],
            ['key' => 'disconnected', 'label' => 'Disconnected', 'type' => 'int'], ['key' => 'pending', 'label' => 'Pending activation', 'type' => 'int'], ['key' => 'total', 'label' => 'All accounts', 'type' => 'int'],
            ['key' => 'mrr', 'label' => 'Monthly recurring (active)', 'type' => 'money'], ['key' => 'mrr_share', 'label' => '% of recurring', 'type' => 'pct'], ['key' => 'arpu', 'label' => 'Average per active', 'type' => 'money']],
            'rows' => $out, 'totals' => $tot, 'chart' => $chart ? ['pie' => array_slice($chart, 0, 8, true), 'pie_title' => 'Monthly recurring revenue by plan'] : [],
            'notes' => 'Accounts on each plan today (terminated and cancelled accounts are left out). Monthly recurring is of the active accounts: plan fee at the member price where it applies, plus rentals and add-ons.'];
    },
]);

Report::define('isp_by_barangay', [
    'extra_filters' => isprpt_filters(['branch', 'area', 'plan']),
    'run' => function (array $f): array {
        isprpt_ready();
        $p = [];
        $w = isprpt_where($f, $p);
        $rows = DB::all("SELECT a.area_id, SUM(a.service_status = 'active') AS active, SUM(a.service_status = 'suspended') AS suspended, SUM(a.service_status = 'disconnected') AS disconnected,
                                SUM(a.service_status = 'pending') AS pending, SUM(a.service_status IN ('terminated', 'cancelled')) AS ended, COUNT(*) AS total,
                                SUM(CASE WHEN a.service_status = 'active' THEN " . isprpt_fee_sql('a', 'pl') . " ELSE 0 END) AS mrr
                           FROM isp_accounts a LEFT JOIN isp_plans pl ON pl.id = a.plan_id WHERE 1 = 1$w GROUP BY a.area_id", $p);
        $p2 = ['d' => today()];
        $w2 = isprpt_where($f, $p2);
        $bal = [];
        foreach (DB::all("SELECT a.area_id, SUM(i.balance) AS bal, SUM(CASE WHEN i.due_date < :d THEN i.balance ELSE 0 END) AS overdue
                            FROM isp_invoices i JOIN isp_accounts a ON a.id = i.account_id WHERE i.status IN ('posted', 'partially_paid', 'overdue', 'disputed')$w2 GROUP BY a.area_id", $p2) as $b) {
            $bal[(int)$b['area_id']] = $b;
        }
        $areas = isprpt_areas();
        $types = DB::pairs('SELECT id, area_type FROM isp_areas');
        $out = [];
        $tot = ['area' => 'TOTAL', 'active' => 0, 'suspended' => 0, 'disconnected' => 0, 'pending' => 0, 'ended' => 0, 'total' => 0, 'mrr' => 0.0, 'balance' => 0.0, 'overdue' => 0.0];
        foreach ($rows as $r) {
            $aid = (int)$r['area_id'];
            $parts = $aid ? explode(' / ', $areas[$aid] ?? '?') : ['(no area)'];
            $out[] = ['area' => array_pop($parts), 'parent' => implode(' / ', $parts), 'type' => $aid ? ucfirst((string)($types[$aid] ?? '')) : '',
                'active' => (int)$r['active'], 'suspended' => (int)$r['suspended'], 'disconnected' => (int)$r['disconnected'], 'pending' => (int)$r['pending'], 'ended' => (int)$r['ended'],
                'total' => (int)$r['total'], 'mrr' => r2((float)$r['mrr']), 'balance' => r2((float)($bal[$aid]['bal'] ?? 0)), 'overdue' => r2((float)($bal[$aid]['overdue'] ?? 0)),
                '_sort' => $aid ? ($areas[$aid] ?? '') : 'zzz', '_links' => ['area' => $aid ? Report::link('rpt.isp_master', ['area' => $aid]) : null]];
            foreach (['active', 'suspended', 'disconnected', 'pending', 'ended', 'total'] as $k) {
                $tot[$k] += (int)$r[$k];
            }
            $tot['mrr'] += (float)$r['mrr'];
            $tot['balance'] += (float)($bal[$aid]['bal'] ?? 0);
            $tot['overdue'] += (float)($bal[$aid]['overdue'] ?? 0);
        }
        usort($out, fn($x, $y) => strnatcasecmp($x['_sort'], $y['_sort']));
        foreach (['mrr', 'balance', 'overdue'] as $k) {
            $tot[$k] = r2($tot[$k]);
        }
        return ['columns' => [['key' => 'area', 'label' => 'Barangay / area'], ['key' => 'parent', 'label' => 'Municipality / province'], ['key' => 'type', 'label' => 'Level', 'optional' => true],
            ['key' => 'active', 'label' => 'Active', 'type' => 'int'], ['key' => 'suspended', 'label' => 'Suspended', 'type' => 'int'], ['key' => 'disconnected', 'label' => 'Disconnected', 'type' => 'int'],
            ['key' => 'pending', 'label' => 'Pending', 'type' => 'int'], ['key' => 'ended', 'label' => 'Terminated / cancelled', 'type' => 'int'], ['key' => 'total', 'label' => 'All accounts', 'type' => 'int'],
            ['key' => 'mrr', 'label' => 'Monthly recurring (plan fees)', 'type' => 'money'], ['key' => 'balance', 'label' => 'Open balance', 'type' => 'money'], ['key' => 'overdue', 'label' => 'Overdue', 'type' => 'money']],
            'rows' => $out, 'totals' => $tot,
            'notes' => 'Accounts counted in the area of their installation address. Choose a municipality in Area to see only its barangays.'];
    },
]);

Report::define('isp_contracts_due', [
    'filters' => ['asof'],
    'extra_filters' => isprpt_filters(['branch', 'area', 'plan']) + ['within' => ['Ending within', fn() => ['30' => '30 days', '60' => '60 days', '90' => '90 days', '180' => '180 days', '365' => 'One year', 'ended' => 'Already ended (month to month)'], null]],
    'run' => function (array $f): array {
        isprpt_ready();
        $within = $f['within'] !== '' ? $f['within'] : '30';
        $p = [];
        $w = isprpt_where($f, $p);
        if ($within === 'ended') {
            $cond = "k.end_date < :d AND (k.status = 'expired' OR k.status = 'active')";
            $p['d'] = $f['asof'];
        } else {
            $cond = "k.status IN ('active', 'amended', 'renewed') AND k.end_date BETWEEN :d1 AND :d2";
            $p['d1'] = $f['asof'];
            $p['d2'] = IspPlans::addDays($f['asof'], (int)$within);
        }
        $rows = DB::all("SELECT k.*, a.account_no, a.account_name, a.service_status, a.lock_in_until, c.mobile, pl.name AS plan_name
                           FROM isp_contracts k JOIN isp_accounts a ON a.id = k.account_id JOIN isp_customers c ON c.id = a.isp_customer_id LEFT JOIN isp_plans pl ON pl.id = a.plan_id
                          WHERE $cond AND a.service_status IN ('active', 'suspended', 'disconnected')
                            AND k.id = (SELECT MAX(k2.id) FROM isp_contracts k2 WHERE k2.account_id = k.account_id AND k2.status <> 'draft')$w
                          ORDER BY k.end_date, a.account_no LIMIT " . (ISPRPT_MAX_ROWS + 1), $p);
        $capNote = isprpt_capped($rows, false);
        $notified = DB::pairs("SELECT account_id, MAX(created_at) FROM isp_notifications WHERE event_code = 'contract_expiration' GROUP BY account_id");
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['end' => $r['end_date'], 'days' => isprpt_days($f['asof'], (string)$r['end_date']), 'acct' => $r['account_no'], 'name' => $r['account_name'], 'plan' => $r['plan_name'],
                'contract' => $r['contract_no'], 'start' => $r['start_date'], 'lockin' => $r['lock_in_until'], 'etf' => (float)$r['early_termination_fee'] ?: '',
                'status' => IspContracts::STATUSES[$r['status']] ?? $r['status'], 'service' => IspAccounts::SERVICE_STATUSES[$r['service_status']] ?? $r['service_status'],
                'mobile' => isprpt_mask($r['mobile']), 'notified' => $notified[$r['account_id']] ?? '',
                '_links' => ['acct' => isprpt_link('account', $r['account_id']), 'contract' => isprpt_link('contract', $r['id'])]];
        }
        return ['columns' => [['key' => 'end', 'label' => 'Contract ends', 'type' => 'date'], ['key' => 'days', 'label' => 'Days left', 'type' => 'int'], ['key' => 'acct', 'label' => 'Account no.'],
            ['key' => 'name', 'label' => 'Subscriber'], ['key' => 'plan', 'label' => 'Plan'], ['key' => 'contract', 'label' => 'Contract'], ['key' => 'start', 'label' => 'Started', 'type' => 'date'],
            ['key' => 'lockin', 'label' => 'Lock-in until', 'type' => 'date', 'optional' => true], ['key' => 'etf', 'label' => 'Early termination fee', 'type' => 'money', 'optional' => true],
            ['key' => 'status', 'label' => 'Contract status'], ['key' => 'service', 'label' => 'Service'], ['key' => 'mobile', 'label' => 'Mobile'], ['key' => 'notified', 'label' => 'Renewal notice sent', 'type' => 'datetime']],
            'rows' => $out, 'totals' => ['end' => 'TOTAL (' . count($out) . ')'],
            'notes' => 'The latest contract of each account in service. An ended contract continues month to month until it is renewed or the account is terminated.' . $capNote];
    },
]);

Report::define('isp_agent_perf', [
    'filters' => ['range'],
    'extra_filters' => ['agent' => ['Agent', fn() => isprpt_agents()], 'branch' => ['Branch', fn() => isprpt_branches()]],
    'run' => function (array $f): array {
        isprpt_ready();
        $agents = DB::all('SELECT * FROM isp_agents' . (($f['agent'] ?? '') !== '' ? ' WHERE id = ' . (int)$f['agent'] : '') . ' ORDER BY name');
        $bw = ($f['branch'] ?? '') !== '' ? ' AND x.branch_id = ' . (int)$f['branch'] : '';
        $ub = IspSetup::userBranch();
        if ($ub !== null) {
            $bw .= ' AND (x.branch_id = ' . $ub . ' OR x.branch_id IS NULL)';
        }
        $rng = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $apps = DB::pairs("SELECT x.agent_id, COUNT(*) FROM isp_applications x WHERE x.agent_id IS NOT NULL AND x.created_at BETWEEN :f AND :t$bw GROUP BY x.agent_id", $rng);
        $lost = DB::pairs("SELECT x.agent_id, COUNT(*) FROM isp_applications x WHERE x.agent_id IS NOT NULL AND x.created_at BETWEEN :f AND :t AND x.status IN ('rejected', 'cancelled')$bw GROUP BY x.agent_id", $rng);
        $won = DB::pairs("SELECT x.agent_id, COUNT(*) FROM isp_applications x WHERE x.agent_id IS NOT NULL AND x.created_at BETWEEN :f AND :t AND x.status = 'active'$bw GROUP BY x.agent_id", $rng);
        $acts = DB::pairs("SELECT x.agent_id, COUNT(*) FROM isp_accounts x WHERE x.agent_id IS NOT NULL AND x.activation_date BETWEEN :f AND :t$bw GROUP BY x.agent_id", ['f' => $f['from'], 't' => $f['to']]);
        $mrr = DB::pairs("SELECT x.agent_id, SUM(" . isprpt_fee_sql('x', 'pl') . ") FROM isp_accounts x JOIN isp_plans pl ON pl.id = x.plan_id WHERE x.agent_id IS NOT NULL AND x.activation_date BETWEEN :f AND :t$bw GROUP BY x.agent_id",
            ['f' => $f['from'], 't' => $f['to']]);
        $live = DB::pairs("SELECT x.agent_id, COUNT(*) FROM isp_accounts x WHERE x.agent_id IS NOT NULL AND x.service_status IN ('active', 'suspended', 'disconnected')$bw GROUP BY x.agent_id");
        $gone = DB::pairs("SELECT x.agent_id, COUNT(*) FROM isp_accounts x WHERE x.agent_id IS NOT NULL AND x.activation_date BETWEEN :f AND :t AND x.service_status = 'terminated'$bw GROUP BY x.agent_id",
            ['f' => $f['from'], 't' => $f['to']]);
        $com = [];
        foreach (DB::all("SELECT m.agent_id, m.status, SUM(m.amount) AS amt FROM isp_commissions m JOIN isp_accounts x ON x.id = m.account_id WHERE m.created_at BETWEEN :f AND :t$bw GROUP BY m.agent_id, m.status", $rng) as $c) {
            $com[(int)$c['agent_id']][$c['status']] = (float)$c['amt'];
        }
        $out = [];
        $tot = ['agent' => 'TOTAL', 'apps' => 0, 'lost' => 0, 'won' => 0, 'acts' => 0, 'mrr' => 0.0, 'live' => 0, 'gone' => 0, 'pending' => 0.0, 'approved' => 0.0, 'paid' => 0.0, 'reversed' => 0.0, 'earned' => 0.0];
        foreach ($agents as $ag) {
            $id = (int)$ag['id'];
            $c = $com[$id] ?? [];
            $earned = r2(($c['pending'] ?? 0) + ($c['approved'] ?? 0) + ($c['paid'] ?? 0));
            $r = ['agent' => $ag['code'] . ' - ' . $ag['name'], 'apps' => (int)($apps[$id] ?? 0), 'lost' => (int)($lost[$id] ?? 0), 'acts' => (int)($acts[$id] ?? 0),
                'won' => (int)($won[$id] ?? 0), 'conv' => isprpt_pct((float)($won[$id] ?? 0), (float)($apps[$id] ?? 0)), 'mrr' => r2((float)($mrr[$id] ?? 0)), 'live' => (int)($live[$id] ?? 0), 'gone' => (int)($gone[$id] ?? 0),
                'pending' => r2($c['pending'] ?? 0), 'approved' => r2($c['approved'] ?? 0), 'paid' => r2($c['paid'] ?? 0), 'reversed' => r2($c['reversed'] ?? 0), 'earned' => $earned,
                'active' => (int)$ag['is_active'] ? 'Yes' : 'No', '_links' => ['agent' => isprpt_link('agent', $id)]];
            if (!$r['apps'] && !$r['acts'] && !$r['live'] && !$earned && !$r['reversed'] && !(int)$ag['is_active']) {
                continue;
            }
            $out[] = $r;
            foreach (['apps', 'lost', 'won', 'acts', 'live', 'gone'] as $k) {
                $tot[$k] += $r[$k];
            }
            foreach (['mrr', 'pending', 'approved', 'paid', 'reversed', 'earned'] as $k) {
                $tot[$k] = r2($tot[$k] + $r[$k]);
            }
        }
        $tot['conv'] = isprpt_pct((float)$tot['won'], (float)$tot['apps']);
        return ['columns' => [['key' => 'agent', 'label' => 'Agent'], ['key' => 'apps', 'label' => 'Applications', 'type' => 'int'], ['key' => 'lost', 'label' => 'Rejected / cancelled', 'type' => 'int'],
            ['key' => 'won', 'label' => 'Of them active', 'type' => 'int'], ['key' => 'conv', 'label' => 'Conversion', 'type' => 'pct'],
            ['key' => 'acts', 'label' => 'Accounts activated', 'type' => 'int'], ['key' => 'mrr', 'label' => 'Recurring signed up', 'type' => 'money'],
            ['key' => 'live', 'label' => 'Accounts in service now', 'type' => 'int'], ['key' => 'gone', 'label' => 'Terminated', 'type' => 'int'],
            ['key' => 'pending', 'label' => 'Commission pending', 'type' => 'money'], ['key' => 'approved', 'label' => 'Approved', 'type' => 'money'], ['key' => 'paid', 'label' => 'Paid', 'type' => 'money'],
            ['key' => 'reversed', 'label' => 'Reversed (clawback)', 'type' => 'money'], ['key' => 'earned', 'label' => 'Earned', 'type' => 'money'], ['key' => 'active', 'label' => 'Agent active', 'optional' => true]],
            'rows' => $out, 'totals' => $tot,
            'notes' => 'Applications and commissions dated in the period (conversion = the applications of the period that are active now); activations and the recurring revenue signed up are of the accounts activated in the period. Earned = pending + approved + paid (reversed commissions are clawbacks of cancelled accounts).'];
    },
]);

Report::define('isp_notif_log', [
    'filters' => ['range', 'search'],
    'filter_labels' => ['search' => 'Account or recipient'],
    'extra_filters' => ['channel' => ['Channel', fn() => IspNotify::CHANNELS], 'event' => ['Event', fn() => IspNotify::EVENTS],
        'nstatus' => ['Status', fn() => ['queued' => 'Queued', 'sent' => 'Sent', 'failed' => 'Failed', 'skipped' => 'Skipped']]],
    'run' => function (array $f): array {
        isprpt_ready();
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $w = '';
        foreach (['channel' => 'n.channel', 'event' => 'n.event_code', 'nstatus' => 'n.status'] as $k => $col) {
            if (($f[$k] ?? '') !== '') {
                $w .= " AND $col = :$k";
                $p[$k] = $f[$k];
            }
        }
        if (trim((string)$f['q']) !== '') {
            $w .= ' AND (a.account_no LIKE :q1 OR a.account_name LIKE :q2 OR n.recipient LIKE :q3)';
            $p['q1'] = $p['q2'] = $p['q3'] = '%' . trim((string)$f['q']) . '%';
        }
        $ub = IspSetup::userBranch();
        if ($ub !== null) {
            $w .= ' AND (a.branch_id = :ub OR a.branch_id IS NULL)';
            $p['ub'] = $ub;
        }
        $rows = DB::all("SELECT n.*, a.account_no, a.account_name FROM isp_notifications n LEFT JOIN isp_accounts a ON a.id = n.account_id
                          WHERE n.created_at BETWEEN :f AND :t$w ORDER BY n.id DESC LIMIT 5001", $p);
        $more = count($rows) > 5000;
        $rows = array_slice($rows, 0, 5000);
        $out = [];
        $count = [];
        foreach ($rows as $r) {
            $count[$r['status']] = ($count[$r['status']] ?? 0) + 1;
            $out[] = ['when' => $r['created_at'], 'event' => IspNotify::EVENTS[$r['event_code']] ?? $r['event_code'], 'channel' => IspNotify::CHANNELS[$r['channel']] ?? $r['channel'],
                'acct' => (string)$r['account_no'], 'name' => (string)$r['account_name'], 'to' => isprpt_mask($r['recipient']),
                'message' => mb_substr(trim(($r['subject'] ? $r['subject'] . ': ' : '') . preg_replace('/\s+/', ' ', (string)$r['body'])), 0, 160),
                'status' => ucfirst((string)$r['status']), 'attempts' => (int)$r['attempts'], 'sent' => $r['sent_at'], 'read' => $r['read_at'], 'error' => $r['last_error'],
                '_links' => ['acct' => isprpt_link('account', $r['account_id'])]];
        }
        $summary = implode(', ', array_map(fn($k, $v) => $v . ' ' . $k, array_keys($count), $count));
        return ['columns' => [['key' => 'when', 'label' => 'Created', 'type' => 'datetime'], ['key' => 'event', 'label' => 'Event'], ['key' => 'channel', 'label' => 'Channel'],
            ['key' => 'acct', 'label' => 'Account no.'], ['key' => 'name', 'label' => 'Subscriber'], ['key' => 'to', 'label' => 'Recipient'], ['key' => 'message', 'label' => 'Message'],
            ['key' => 'status', 'label' => 'Status'], ['key' => 'attempts', 'label' => 'Attempts', 'type' => 'int', 'optional' => true], ['key' => 'sent', 'label' => 'Sent', 'type' => 'datetime'],
            ['key' => 'read', 'label' => 'Read (portal)', 'type' => 'datetime', 'optional' => true], ['key' => 'error', 'label' => 'Error']],
            'rows' => $out, 'totals' => ['when' => 'TOTAL (' . count($out) . ')'],
            'notes' => h($summary !== '' ? 'By status: ' . $summary . '.' : '') . ($more ? ' <span class="text-danger">Only the latest 5,000 are listed: narrow the dates or the filters.</span>' : '')
                . ' Skipped messages are those of subscribers who turned the channel off, or with no mobile number or e-mail address.'];
    },
]);

// ====================================================================== billing reports

/** Line types grouped into the columns of the billing register */
function isprpt_line_group(string $type, float $amount): string
{
    if ($amount < 0 || in_array($type, ['discount', 'outage_credit'], true)) {
        return 'credits';
    }
    return match ($type) {
        'subscription', 'prorate', 'plan_change' => 'service',
        'installation' => 'install',
        'router_rental', 'static_ip', 'addon', 'equipment' => 'rental',
        'late_fee', 'reconnection_fee', 'termination_fee' => 'fees',
        'deposit' => 'deposit',
        default => 'other',
    };
}

Report::define('isp_billing_register', [
    'filters' => ['range'],
    'extra_filters' => isprpt_filters(['branch', 'area', 'plan']) + ['kind' => ['Invoice kind', fn() => ['initial' => 'Initial (activation)', 'regular' => 'Regular (billing run)', 'charges' => 'Charges only', 'final' => 'Final']]],
    'run' => function (array $f): array {
        isprpt_ready();
        $p = ['f' => $f['from'], 't' => $f['to']];
        $w = isprpt_where($f, $p);
        if (($f['kind'] ?? '') !== '') {
            $w .= ' AND i.kind = :kind';
            $p['kind'] = $f['kind'];
        }
        $invs = DB::all("SELECT i.id, i.invoice_no, i.invoice_date, i.due_date, i.account_id, i.period_start, i.period_end, i.kind, i.run_id, i.status, i.total,
                                a.account_no, a.account_name, pl.name AS plan_name, r.run_no
                           FROM isp_invoices i JOIN isp_accounts a ON a.id = i.account_id LEFT JOIN isp_plans pl ON pl.id = a.plan_id LEFT JOIN isp_billing_runs r ON r.id = i.run_id
                          WHERE i.invoice_date BETWEEN :f AND :t AND i.status IN " . ISPRPT_BOOKED . "$w ORDER BY i.invoice_date, i.invoice_no LIMIT " . (ISPRPT_MAX_ROWS + 1), $p);
        $capNote = isprpt_capped($invs);
        $lines = [];
        foreach (array_chunk(array_column($invs, 'id'), 2000) as $chunk) {
            [$in, $ip] = DB::in('bl', $chunk);
            foreach (DB::all("SELECT l.invoice_id, l.line_type, SUM(l.amount) AS amt, SUM(l.vat_amount) AS vat FROM isp_invoice_lines l WHERE l.invoice_id IN $in GROUP BY l.invoice_id, l.line_type, SIGN(l.amount)", $ip) as $l) {
                $g = isprpt_line_group((string)$l['line_type'], (float)$l['amt']);
                $lines[(int)$l['invoice_id']][$g] = ($lines[(int)$l['invoice_id']][$g] ?? 0) + (float)$l['amt'];
                $lines[(int)$l['invoice_id']]['vat'] = ($lines[(int)$l['invoice_id']]['vat'] ?? 0) + (float)$l['vat'];
            }
        }
        $keys = ['service', 'install', 'rental', 'fees', 'deposit', 'other', 'credits', 'vat', 'total'];
        $tot = array_fill_keys($keys, 0.0);
        // the totals are of every invoice of the period, also when the list is cut short
        $p2 = ['f' => $f['from'], 't' => $f['to']];
        $w2 = isprpt_where($f, $p2);
        if (($f['kind'] ?? '') !== '') {
            $w2 .= ' AND i.kind = :kind';
            $p2['kind'] = $f['kind'];
        }
        foreach (DB::all("SELECT l.line_type, SIGN(l.amount) AS sg, SUM(l.amount) AS amt, SUM(l.vat_amount) AS vat FROM isp_invoice_lines l JOIN isp_invoices i ON i.id = l.invoice_id JOIN isp_accounts a ON a.id = i.account_id
                           WHERE i.invoice_date BETWEEN :f AND :t AND i.status IN " . ISPRPT_BOOKED . "$w2 GROUP BY l.line_type, SIGN(l.amount)", $p2) as $l) {
            $tot[isprpt_line_group((string)$l['line_type'], (float)$l['amt'])] += (float)$l['amt'];
            $tot['vat'] += (float)$l['vat'];
        }
        $p3 = ['f' => $f['from'], 't' => $f['to']];
        $w3 = isprpt_where($f, $p3);
        if (($f['kind'] ?? '') !== '') {
            $w3 .= ' AND i.kind = :kind';
            $p3['kind'] = $f['kind'];
        }
        $agg = DB::row("SELECT COUNT(*) AS n, COALESCE(SUM(i.total), 0) AS total FROM isp_invoices i JOIN isp_accounts a ON a.id = i.account_id WHERE i.invoice_date BETWEEN :f AND :t AND i.status IN " . ISPRPT_BOOKED . $w3, $p3);
        $tot['total'] = (float)$agg['total'];
        $out = [];
        foreach ($invs as $i) {
            $l = $lines[(int)$i['id']] ?? [];
            $r = ['no' => $i['invoice_no'], 'date' => $i['invoice_date'], 'due' => $i['due_date'], 'acct' => $i['account_no'], 'name' => $i['account_name'], 'plan' => $i['plan_name'],
                'period' => $i['period_start'] ? fdate($i['period_start']) . ' - ' . fdate($i['period_end']) : '', 'kind' => ucfirst((string)$i['kind']), 'run' => $i['run_no'],
                'status' => IspBilling::STATUSES[$i['status']] ?? $i['status'],
                '_links' => ['no' => isprpt_link('invoice', $i['id']), 'acct' => isprpt_link('account', $i['account_id']), 'run' => isprpt_link('run', $i['run_id'])]];
            foreach (['service', 'install', 'rental', 'fees', 'deposit', 'other', 'credits', 'vat'] as $k) {
                $r[$k] = r2((float)($l[$k] ?? 0)) ?: '';
            }
            $r['total'] = (float)$i['total'];
            $out[] = $r;
        }
        $tot = array_map('r2', $tot) + ['no' => 'TOTAL (' . number_format((int)$agg['n']) . ' invoices)'];
        $chk = r2($tot['service'] + $tot['install'] + $tot['rental'] + $tot['fees'] + $tot['deposit'] + $tot['other'] + $tot['credits'] + $tot['vat']);
        return ['columns' => [['key' => 'no', 'label' => 'Invoice no.'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'due', 'label' => 'Due', 'type' => 'date', 'optional' => true],
            ['key' => 'acct', 'label' => 'Account no.'], ['key' => 'name', 'label' => 'Subscriber'], ['key' => 'plan', 'label' => 'Plan', 'optional' => true], ['key' => 'period', 'label' => 'Service period'],
            ['key' => 'kind', 'label' => 'Kind', 'optional' => true], ['key' => 'run', 'label' => 'Billing run', 'optional' => true],
            ['key' => 'service', 'label' => 'Service fees', 'type' => 'money'], ['key' => 'install', 'label' => 'Installation', 'type' => 'money'], ['key' => 'rental', 'label' => 'Rentals & add-ons', 'type' => 'money'],
            ['key' => 'fees', 'label' => 'Late / reconnection / ETF', 'type' => 'money'], ['key' => 'deposit', 'label' => 'Deposits', 'type' => 'money'], ['key' => 'other', 'label' => 'Other', 'type' => 'money'],
            ['key' => 'credits', 'label' => 'Discounts & credits', 'type' => 'money'], ['key' => 'vat', 'label' => 'Output VAT', 'type' => 'money'], ['key' => 'total', 'label' => 'Invoice total', 'type' => 'money'],
            ['key' => 'status', 'label' => 'Status']],
            'rows' => $out, 'totals' => $tot,
            'notes' => 'Invoices in the books dated in the period (drafts, approved-but-unposted and voided invoices are left out). Amounts are net of VAT; VAT is shown on its own.'
                . (abs($chk - $tot['total']) > 0.01 ? ' <span class="text-danger">The columns add up to ' . money($chk) . ', not the invoice total: check the invoice lines.</span>' : '') . $capNote];
    },
]);

Report::define('isp_invoice_register', [
    'filters' => ['range', 'search'],
    'filter_labels' => ['search' => 'Invoice, account or name'],
    'extra_filters' => isprpt_filters(['branch', 'area', 'plan']) + ['istatus' => ['Invoice status', fn() => IspBilling::STATUSES]],
    'run' => function (array $f): array {
        isprpt_ready();
        $q = trim((string)$f['q']);
        $f['q'] = '';
        $p = ['f' => $f['from'], 't' => $f['to']];
        $w = isprpt_where($f, $p);
        if (($f['istatus'] ?? '') !== '') {
            $w .= ' AND i.status = :ist';
            $p['ist'] = $f['istatus'];
        }
        if ($q !== '') {
            $w .= ' AND (i.invoice_no LIKE :q1 OR a.account_no LIKE :q2 OR a.account_name LIKE :q3)';
            $p['q1'] = $p['q2'] = $p['q3'] = '%' . $q . '%';
        }
        $users = isprpt_user_names();
        $out = [];
        $more = 0;
        $tot = ['no' => '', 'total' => 0.0, 'vat' => 0.0, 'paid' => 0.0, 'credit' => 0.0, 'balance' => 0.0];
        foreach (isprpt_stream("SELECT i.*, a.account_no, a.account_name FROM isp_invoices i JOIN isp_accounts a ON a.id = i.account_id
                                 WHERE i.invoice_date BETWEEN :f AND :t$w ORDER BY i.invoice_date, i.id", $p) as $i) {
            $booked = !in_array($i['status'], ['draft', 'approved', 'voided'], true);
            if ($booked) {
                foreach (['total' => 'total', 'vat' => 'vat_total', 'paid' => 'paid_amount', 'credit' => 'credit_amount', 'balance' => 'balance'] as $k => $col) {
                    $tot[$k] += (float)$i[$col];
                }
            }
            if (count($out) >= ISPRPT_MAX_ROWS) {
                $more++;
                continue;
            }
            $out[] = ['no' => $i['invoice_no'], 'date' => $i['invoice_date'], 'due' => $i['due_date'], 'acct' => $i['account_no'], 'name' => $i['account_name'], 'kind' => ucfirst((string)$i['kind']),
                'period' => $i['period_start'] ? fdate($i['period_start']) . ' - ' . fdate($i['period_end']) : '', 'subtotal' => (float)$i['subtotal'], 'vat' => (float)$i['vat_total'],
                'total' => (float)$i['total'], 'paid' => (float)$i['paid_amount'] ?: '', 'credit' => (float)$i['credit_amount'] ?: '', 'balance' => $booked ? (float)$i['balance'] : '',
                'status' => IspBilling::STATUSES[$i['status']] ?? $i['status'], 'posted' => $i['posted_at'], 'by' => $users[$i['posted_by']] ?? '',
                'void' => $i['status'] === 'voided' ? trim(fdate($i['voided_at']) . ' ' . ($users[$i['voided_by']] ?? '') . ': ' . $i['void_reason']) : '',
                '_links' => ['no' => isprpt_link('invoice', $i['id']), 'acct' => isprpt_link('account', $i['account_id'])]];
        }
        $tot = array_map(fn($v) => is_float($v) ? r2($v) : $v, $tot);
        $tot['no'] = 'TOTAL IN THE BOOKS';
        return ['columns' => [['key' => 'no', 'label' => 'Invoice no.'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'due', 'label' => 'Due', 'type' => 'date'],
            ['key' => 'acct', 'label' => 'Account no.'], ['key' => 'name', 'label' => 'Subscriber'], ['key' => 'kind', 'label' => 'Kind'], ['key' => 'period', 'label' => 'Service period', 'optional' => true],
            ['key' => 'subtotal', 'label' => 'Net of VAT', 'type' => 'money', 'optional' => true], ['key' => 'vat', 'label' => 'VAT', 'type' => 'money'], ['key' => 'total', 'label' => 'Total', 'type' => 'money'],
            ['key' => 'paid', 'label' => 'Paid', 'type' => 'money'], ['key' => 'credit', 'label' => 'Credited', 'type' => 'money'], ['key' => 'balance', 'label' => 'Balance', 'type' => 'money'],
            ['key' => 'status', 'label' => 'Status'], ['key' => 'posted', 'label' => 'Posted', 'type' => 'datetime', 'optional' => true], ['key' => 'by', 'label' => 'Posted by', 'optional' => true],
            ['key' => 'void', 'label' => 'Voided']],
            'rows' => $out, 'totals' => $tot,
            'notes' => 'Every invoice dated in the period, drafts and voided ones included (the total line counts only the invoices in the books). Invoice numbers are issued when an invoice posts, so the posted series has no gaps.'
                . ($more ? ' <span class="text-danger">' . number_format($more) . ' more invoices are not listed (the totals are of all): choose a shorter period or narrow the filters.</span>' : '')];
    },
]);

Report::define('isp_billing_summary', [
    'filters' => ['range'],
    'sortable' => false,
    'extra_filters' => isprpt_filters(['branch', 'area', 'plan']) + ['by' => ['Group by', fn() => ['type' => 'Kind of charge', 'account' => 'Income account', 'plan' => 'Plan', 'branch' => 'Branch', 'month' => 'Month'], null]],
    'run' => function (array $f): array {
        isprpt_ready();
        $by = $f['by'] !== '' ? $f['by'] : 'type';
        $p = ['f' => $f['from'], 't' => $f['to']];
        $w = isprpt_where($f, $p);
        $group = match ($by) {
            'account' => 'l.income_account_id',
            'plan' => 'COALESCE(l.plan_id, a.plan_id)',
            'branch' => 'a.branch_id',
            'month' => "DATE_FORMAT(i.invoice_date, '%Y-%m')",
            default => 'l.line_type',
        };
        $rows = DB::all("SELECT $group AS g, COUNT(DISTINCT i.id) AS invoices, COUNT(DISTINCT i.account_id) AS accounts, COUNT(*) AS line_count,
                                SUM(CASE WHEN l.amount >= 0 THEN l.amount ELSE 0 END) AS charges, SUM(CASE WHEN l.amount < 0 THEN l.amount ELSE 0 END) AS credits, SUM(l.vat_amount) AS vat
                           FROM isp_invoice_lines l JOIN isp_invoices i ON i.id = l.invoice_id JOIN isp_accounts a ON a.id = i.account_id
                          WHERE i.invoice_date BETWEEN :f AND :t AND i.status IN " . ISPRPT_BOOKED . "$w GROUP BY $group ORDER BY $group", $p);
        $names = match ($by) {
            'account' => DB::pairs("SELECT id, CONCAT(code, ' ', name) FROM accounts"),
            'plan' => isprpt_plans(),
            'branch' => IspSetup::branches(),
            'month' => [],
            default => IspBilling::LINE_TYPES,
        };
        $p2 = ['f' => $f['from'], 't' => $f['to']];
        $w2 = isprpt_where($f, $p2);
        $invTotal = (float)DB::value("SELECT COALESCE(SUM(i.total), 0) FROM isp_invoices i JOIN isp_accounts a ON a.id = i.account_id WHERE i.invoice_date BETWEEN :f AND :t AND i.status IN " . ISPRPT_BOOKED . $w2, $p2);
        $invCount = (int)DB::value("SELECT COUNT(*) FROM isp_invoices i JOIN isp_accounts a ON a.id = i.account_id WHERE i.invoice_date BETWEEN :f AND :t AND i.status IN " . ISPRPT_BOOKED . $w2, $p2);
        $out = [];
        $tot = ['g' => 'TOTAL', 'charges' => 0.0, 'credits' => 0.0, 'net' => 0.0, 'vat' => 0.0, 'gross' => 0.0];
        $chart = [];
        foreach ($rows as $r) {
            $label = $by === 'month' ? date('M Y', strtotime($r['g'] . '-01')) : ($r['g'] === null ? '(none)' : (string)($names[$r['g']] ?? $r['g']));
            $net = r2((float)$r['charges'] + (float)$r['credits']);
            $gross = r2($net + (float)$r['vat']);
            $out[] = ['g' => $label, 'invoices' => (int)$r['invoices'], 'accounts' => (int)$r['accounts'], 'charges' => r2((float)$r['charges']), 'credits' => r2((float)$r['credits']) ?: '',
                'net' => $net, 'vat' => r2((float)$r['vat']), 'gross' => $gross, 'share' => isprpt_pct($gross, $invTotal)];
            $chart[$label] = $gross;
            foreach (['charges', 'credits', 'vat'] as $k) {
                $tot[$k] += (float)$r[$k];
            }
            $tot['net'] += $net;
            $tot['gross'] += $gross;
        }
        $tot = array_map(fn($v) => is_float($v) ? r2($v) : $v, $tot);
        $tot['invoices'] = $invCount;
        $tot['share'] = $invTotal > 0 ? '100' : '';
        $groupLabel = ['type' => 'Kind of charge', 'account' => 'Income account', 'plan' => 'Plan', 'branch' => 'Branch', 'month' => 'Month'][$by];
        return ['columns' => [['key' => 'g', 'label' => $groupLabel], ['key' => 'invoices', 'label' => 'Invoices', 'type' => 'int'], ['key' => 'accounts', 'label' => 'Accounts', 'type' => 'int'],
            ['key' => 'charges', 'label' => 'Charges (net of VAT)', 'type' => 'money'], ['key' => 'credits', 'label' => 'Discounts & credits', 'type' => 'money'], ['key' => 'net', 'label' => 'Net billed', 'type' => 'money'],
            ['key' => 'vat', 'label' => 'Output VAT', 'type' => 'money'], ['key' => 'gross', 'label' => 'Billed with VAT', 'type' => 'money'], ['key' => 'share', 'label' => 'Share', 'type' => 'pct']],
            'rows' => $out, 'totals' => $tot, 'chart' => $chart && count($chart) <= 12 ? ['bars' => $chart, 'bars_title' => 'Billed with VAT'] : [],
            'notes' => 'What was billed on the invoices in the books dated in the period, grouped by ' . h(strtolower($groupLabel)) . '. Invoices in the period total ' . money($invTotal)
                . (abs($invTotal - $tot['gross']) > 0.01 ? ' <span class="text-danger">(differs from the lines: ' . money($tot['gross']) . ')</span>' : '') . '.'];
    },
]);

Report::define('isp_unpaid', [
    'filters' => ['asof'],
    'extra_filters' => isprpt_filters() + ['over' => ['Show', fn() => ['all' => 'Every unpaid invoice', 'due' => 'Past due only'], null]],
    'run' => function (array $f): array {
        isprpt_ready();
        $p = [];
        $w = isprpt_where($f, $p);
        $pastDue = ($f['over'] ?? '') === 'due';
        $out = [];
        $n = 0;
        $tot = ['no' => '', 'total' => 0.0, 'paid' => 0.0, 'balance' => 0.0];
        $accts = [];
        foreach (isprpt_open_invoices($f['asof'], $w, $p) as $i) {
            $days = isprpt_days((string)$i['due_date'], $f['asof']);
            if ($pastDue && $days <= 0) {
                continue;
            }
            $accts[$i['account_id']] = true;
            $n++;
            $tot['total'] += (float)$i['total'];
            $tot['paid'] += (float)$i['paid_at'];
            $tot['balance'] += (float)$i['bal_at'];
            if (count($out) >= ISPRPT_MAX_ROWS) {
                continue;
            }
            $out[] = ['no' => $i['invoice_no'], 'date' => $i['invoice_date'], 'due' => $i['due_date'], 'days' => $days > 0 ? $days : '', 'acct' => $i['account_no'], 'name' => $i['account_name'],
                'plan' => $i['plan_name'], 'mobile' => isprpt_mask($i['mobile']), 'service' => IspAccounts::SERVICE_STATUSES[$i['service_status']] ?? $i['service_status'],
                'kind' => ucfirst((string)$i['kind']), 'total' => (float)$i['total'], 'paid' => (float)$i['paid_at'] ?: '', 'balance' => (float)$i['bal_at'],
                'status' => $days > 0 ? 'Past due' : 'Not yet due',
                '_links' => ['no' => isprpt_link('invoice', $i['id']), 'acct' => isprpt_link('account', $i['account_id'])]];
        }
        $tot = array_map(fn($v) => is_float($v) ? r2($v) : $v, $tot);
        $tot['no'] = 'TOTAL (' . number_format($n) . ' invoices, ' . number_format(count($accts)) . ' accounts)';
        return ['columns' => [['key' => 'no', 'label' => 'Invoice no.'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'due', 'label' => 'Due', 'type' => 'date'],
            ['key' => 'days', 'label' => 'Days past due', 'type' => 'int'], ['key' => 'acct', 'label' => 'Account no.'], ['key' => 'name', 'label' => 'Subscriber'], ['key' => 'plan', 'label' => 'Plan', 'optional' => true],
            ['key' => 'mobile', 'label' => 'Mobile', 'optional' => true], ['key' => 'service', 'label' => 'Service'], ['key' => 'kind', 'label' => 'Kind', 'optional' => true],
            ['key' => 'total', 'label' => 'Invoice total', 'type' => 'money'], ['key' => 'paid', 'label' => 'Paid / credited', 'type' => 'money'], ['key' => 'balance', 'label' => 'Unpaid', 'type' => 'money'],
            ['key' => 'status', 'label' => 'As of the date']],
            'rows' => $out, 'totals' => $tot,
            'notes' => 'Invoices with an unpaid balance as of ' . fdate($f['asof']) . ': the invoice total less the payments applied and the credits posted up to that date. Advance payments not yet applied are in Unapplied Payments & Credits.'
                . ($n > count($out) ? ' <span class="text-danger">' . number_format($n - count($out)) . ' more invoices are not listed (the totals are of all): narrow the filters.</span>' : '')];
    },
]);

Report::define('isp_adjustments', [
    'filters' => ['range'],
    'extra_filters' => isprpt_filters(['branch', 'area']) + ['atype' => ['Kind', fn() => IspCredit::ADJ_TYPES], 'astatus' => ['Status', fn() => IspCredit::ADJ_STATUSES]],
    'run' => function (array $f): array {
        isprpt_ready();
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $w = isprpt_where($f, $p);
        foreach (['atype' => 'x.adj_type', 'astatus' => 'x.status'] as $k => $col) {
            if (($f[$k] ?? '') !== '') {
                $w .= " AND $col = :$k";
                $p[$k] = $f[$k];
            }
        }
        $rows = DB::all("SELECT x.*, a.account_no, a.account_name, i.invoice_no FROM isp_adjustments x JOIN isp_accounts a ON a.id = x.account_id LEFT JOIN isp_invoices i ON i.id = x.invoice_id
                          WHERE x.created_at BETWEEN :f AND :t$w ORDER BY x.created_at, x.id LIMIT " . (ISPRPT_MAX_ROWS + 1), $p);
        $capNote = isprpt_capped($rows, false);
        $users = isprpt_user_names();
        $out = [];
        $byType = [];
        $posted = 0.0;
        foreach ($rows as $x) {
            $sign = $x['adj_type'] === 'debit' ? 1 : -1;
            $out[] = ['no' => $x['adj_no'], 'date' => $x['created_at'], 'acct' => $x['account_no'], 'name' => $x['account_name'], 'invoice' => $x['invoice_no'],
                'type' => IspCredit::ADJ_TYPES[$x['adj_type']] ?? $x['adj_type'], 'amount' => $sign * (float)$x['amount'], 'reason' => $x['reason'],
                'status' => IspCredit::ADJ_STATUSES[$x['status']] ?? $x['status'], 'source' => ucfirst(str_replace('_', ' ', (string)$x['source_type'])),
                'requested' => $users[$x['requested_by']] ?? '', 'approved' => $users[$x['approved_by']] ?? '', 'approved_at' => $x['approved_at'], 'rejected' => $x['rejected_reason'],
                '_links' => ['no' => isprpt_link('adjustment', $x['id']), 'acct' => isprpt_link('account', $x['account_id']), 'invoice' => isprpt_link('invoice', $x['invoice_id'])]];
            if ($x['status'] === 'posted') {
                $posted += $sign * (float)$x['amount'];
                $byType[$x['adj_type']] = ($byType[$x['adj_type']] ?? 0) + $sign * (float)$x['amount'];
            }
        }
        $sum = implode('; ', array_map(fn($k, $v) => (IspCredit::ADJ_TYPES[$k] ?? $k) . ' ' . money($v), array_keys($byType), $byType));
        return ['columns' => [['key' => 'no', 'label' => 'Adjustment no.'], ['key' => 'date', 'label' => 'Requested', 'type' => 'datetime'], ['key' => 'acct', 'label' => 'Account no.'],
            ['key' => 'name', 'label' => 'Subscriber'], ['key' => 'invoice', 'label' => 'Invoice'], ['key' => 'type', 'label' => 'Kind'], ['key' => 'amount', 'label' => 'Amount (credits negative)', 'type' => 'money'],
            ['key' => 'reason', 'label' => 'Reason'], ['key' => 'status', 'label' => 'Status'], ['key' => 'source', 'label' => 'From', 'optional' => true], ['key' => 'requested', 'label' => 'Requested by'],
            ['key' => 'approved', 'label' => 'Decided by'], ['key' => 'approved_at', 'label' => 'Decided', 'type' => 'datetime', 'optional' => true], ['key' => 'rejected', 'label' => 'Why rejected', 'optional' => true]],
            'rows' => $out, 'totals' => ['no' => 'POSTED (' . count(array_filter($rows, fn($x) => $x['status'] === 'posted')) . ')', 'amount' => r2($posted)],
            'notes' => 'Adjustments requested in the period, with who asked for and who decided each. Posted by kind: ' . h($sum !== '' ? $sum : 'none') . '.' . $capNote];
    },
]);

Report::define('isp_late_charges', [
    'filters' => ['range'],
    'extra_filters' => isprpt_filters(['branch', 'area', 'plan']) + ['cstatus' => ['Status', fn() => ['pending' => 'Waiting for the next invoice', 'billed' => 'Billed', 'cancelled' => 'Cancelled / waived']]],
    'run' => function (array $f): array {
        isprpt_ready();
        $p = ['f' => $f['from'], 't' => $f['to']];
        $w = isprpt_where($f, $p);
        if (($f['cstatus'] ?? '') !== '') {
            $w .= ' AND ch.status = :cs';
            $p['cs'] = $f['cstatus'];
        }
        $rows = DB::all("SELECT ch.*, a.account_no, a.account_name, i.invoice_no AS billed_on, src.invoice_no AS late_on, src.id AS late_on_id, src.due_date AS late_due
                           FROM isp_charges ch JOIN isp_accounts a ON a.id = ch.account_id LEFT JOIN isp_invoices i ON i.id = ch.invoice_id
                           LEFT JOIN isp_invoices src ON src.late_fee_charge_id = ch.id
                          WHERE ch.charge_type = 'late_fee' AND ch.charge_date BETWEEN :f AND :t$w ORDER BY ch.charge_date, a.account_no LIMIT " . (ISPRPT_MAX_ROWS + 1), $p);
        $capNote = isprpt_capped($rows, false);
        $p2 = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $w2 = isprpt_where($f, $p2);
        $waived = (float)DB::value("SELECT COALESCE(SUM(x.amount), 0) FROM isp_adjustments x JOIN isp_accounts a ON a.id = x.account_id
                                      WHERE x.adj_type = 'penalty_waiver' AND x.status = 'posted' AND x.approved_at BETWEEN :f AND :t$w2", $p2);
        $out = [];
        $tot = ['date' => '', 'amount' => 0.0, 'billed' => 0.0, 'pending' => 0.0];
        $labels = ['pending' => 'Waiting for the next invoice', 'billed' => 'Billed', 'cancelled' => 'Cancelled / waived'];
        foreach ($rows as $c) {
            $out[] = ['date' => $c['charge_date'], 'acct' => $c['account_no'], 'name' => $c['account_name'], 'late_on' => $c['late_on'], 'late_due' => $c['late_due'],
                'amount' => (float)$c['amount'], 'status' => $labels[$c['status']] ?? $c['status'], 'billed_on' => $c['billed_on'], 'cancel' => $c['cancel_reason'],
                '_links' => ['acct' => isprpt_link('account', $c['account_id']), 'late_on' => isprpt_link('invoice', $c['late_on_id']), 'billed_on' => isprpt_link('invoice', $c['invoice_id'])]];
            if ($c['status'] !== 'cancelled') {
                $tot['amount'] += (float)$c['amount'];
                $tot[$c['status'] === 'billed' ? 'billed' : 'pending'] += (float)$c['amount'];
            }
        }
        return ['columns' => [['key' => 'date', 'label' => 'Assessed', 'type' => 'date'], ['key' => 'acct', 'label' => 'Account no.'], ['key' => 'name', 'label' => 'Subscriber'],
            ['key' => 'late_on', 'label' => 'For invoice'], ['key' => 'late_due', 'label' => 'That was due', 'type' => 'date'], ['key' => 'amount', 'label' => 'Late charge', 'type' => 'money'],
            ['key' => 'status', 'label' => 'Status'], ['key' => 'billed_on', 'label' => 'Billed on'], ['key' => 'cancel', 'label' => 'Why cancelled', 'optional' => true]],
            'rows' => $out, 'totals' => ['date' => 'TOTAL (' . count($out) . ')', 'amount' => r2($tot['amount'])],
            'notes' => 'Late payment charges assessed in the period (cancelled ones are not in the total): ' . money($tot['billed']) . ' already billed, ' . money($tot['pending'])
                . ' waiting for the next invoice. Penalty waivers posted in the period: ' . money($waived) . '.' . $capNote];
    },
]);

// ====================================================================== collection reports

/** The payment channels in the columns of the cashier report */
function isprpt_channel_group(string $ch): string
{
    return match ($ch) {
        'cash' => 'cash',
        'check' => 'check',
        'bank_deposit', 'bank_transfer' => 'bank',
        'gcash', 'maya' => 'ewallet',
        'gateway' => 'gateway',
        'auto_debit' => 'autodebit',
        default => 'other',
    };
}

/** " AND ..." of the collection filters (channel, collector) on alias y */
function isprpt_pay_where(array $f, array &$p): string
{
    $w = '';
    if (($f['channel'] ?? '') !== '' && isset(IspPayments::CHANNELS[$f['channel']])) {
        $w .= ' AND y.channel = :ych';
        $p['ych'] = $f['channel'];
    }
    if (($f['collector'] ?? '') !== '') {
        $w .= ' AND y.collector_user_id = :ycol';
        $p['ycol'] = (int)$f['collector'];
    }
    return $w;
}

function isprpt_pay_filters(): array
{
    return ['branch' => ['Branch', fn() => isprpt_branches()], 'channel' => ['Payment method', fn() => IspPayments::CHANNELS], 'collector' => ['Collected by', fn() => isprpt_collectors()]];
}

Report::define('isp_daily_collection', [
    'filters' => ['range'],
    'default_dates' => 'today',
    'sortable' => false,
    'extra_filters' => isprpt_pay_filters() + ['area' => ['Area', fn() => isprpt_areas()]],
    'run' => function (array $f): array {
        isprpt_ready();
        $p = ['f' => $f['from'], 't' => $f['to']];
        $w = isprpt_where($f, $p) . isprpt_pay_where($f, $p);
        $rows = isprpt_stream("SELECT y.*, a.account_no, a.account_name FROM isp_payments y JOIN isp_accounts a ON a.id = y.account_id
                                WHERE y.status = 'posted' AND y.pay_date BETWEEN :f AND :t$w ORDER BY y.pay_date, y.received_at, y.id", $p);
        $users = isprpt_user_names();
        $listed = 0;
        $more = 0;
        $out = [];
        $day = null;
        $sub = ['amount' => 0.0, 'applied' => 0.0, 'unapplied' => 0.0, 'fee' => 0.0, 'n' => 0];
        $grand = $sub;
        $flush = function () use (&$out, &$sub, &$day) {
            if ($day !== null) {
                $out[] = ['receipt' => 'Total ' . fdate($day) . ' (' . $sub['n'] . ')', 'amount' => r2($sub['amount']), 'applied' => r2($sub['applied']), 'unapplied' => r2($sub['unapplied']) ?: '',
                    'fee' => r2($sub['fee']) ?: '', '_class' => 'subtotal-row'];
            }
            $sub = ['amount' => 0.0, 'applied' => 0.0, 'unapplied' => 0.0, 'fee' => 0.0, 'n' => 0];
        };
        foreach ($rows as $y) {
            if ($listed >= ISPRPT_MAX_ROWS) {
                foreach (['amount' => 'amount', 'applied' => 'applied_amount', 'unapplied' => 'unapplied_amount', 'fee' => 'gateway_fee'] as $k => $col) {
                    $grand[$k] += (float)$y[$col];
                }
                $grand['n']++;
                $more++;
                continue;
            }
            $listed++;
            if ($y['pay_date'] !== $day) {
                $flush();
                $day = $y['pay_date'];
                $out[] = ['receipt' => date('l, F j, Y', strtotime($day)), '_class' => 'group-row'];
            }
            $out[] = ['receipt' => $y['receipt_no'], 'time' => substr((string)$y['received_at'], 11, 5), 'acct' => $y['account_no'], 'name' => $y['account_name'],
                'channel' => IspPayments::CHANNELS[$y['channel']] ?? $y['channel'], 'ref' => trim((string)$y['reference_no'] . ($y['check_no'] ? ' check ' . $y['check_no'] . ' ' . $y['check_bank'] : '')),
                'source' => ucfirst((string)$y['source']), 'collector' => $users[$y['collector_user_id']] ?? '', 'amount' => (float)$y['amount'], 'applied' => (float)$y['applied_amount'],
                'unapplied' => (float)$y['unapplied_amount'] ?: '', 'fee' => (float)$y['gateway_fee'] ?: '', '_class' => 'indent-1',
                '_links' => ['receipt' => isprpt_link('payment', $y['id']), 'acct' => isprpt_link('account', $y['account_id'])]];
            foreach (['amount' => 'amount', 'applied' => 'applied_amount', 'unapplied' => 'unapplied_amount', 'fee' => 'gateway_fee'] as $k => $col) {
                $sub[$k] += (float)$y[$col];
                $grand[$k] += (float)$y[$col];
            }
            $sub['n']++;
            $grand['n']++;
        }
        $flush();
        $out[] = ['receipt' => 'TOTAL (' . $grand['n'] . ' receipts)', 'amount' => r2($grand['amount']), 'applied' => r2($grand['applied']), 'unapplied' => r2($grand['unapplied']), 'fee' => r2($grand['fee']), '_class' => 'total-row'];
        $p2 = ['f' => $f['from'], 't' => $f['to']];
        $w2 = isprpt_where($f, $p2) . isprpt_pay_where($f, $p2);
        $pend = DB::row("SELECT COUNT(*) AS n, COALESCE(SUM(y.amount), 0) AS amt FROM isp_payments y JOIN isp_accounts a ON a.id = y.account_id WHERE y.status = 'pending' AND y.pay_date BETWEEN :f AND :t$w2", $p2);
        $p3 = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $w3 = isprpt_where($f, $p3) . isprpt_pay_where($f, $p3);
        $rev = DB::row("SELECT COUNT(*) AS n, COALESCE(SUM(y.amount), 0) AS amt FROM isp_payments y JOIN isp_accounts a ON a.id = y.account_id WHERE y.status = 'reversed' AND y.reversed_at BETWEEN :f AND :t$w3", $p3);
        return ['grouped' => true, 'columns' => [['key' => 'receipt', 'label' => 'Receipt no.'], ['key' => 'time', 'label' => 'Time'], ['key' => 'acct', 'label' => 'Account no.'], ['key' => 'name', 'label' => 'Subscriber'],
            ['key' => 'channel', 'label' => 'Method'], ['key' => 'ref', 'label' => 'Reference'], ['key' => 'source', 'label' => 'Received at', 'optional' => true], ['key' => 'collector', 'label' => 'Collected by'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'], ['key' => 'applied', 'label' => 'Applied to invoices', 'type' => 'money'], ['key' => 'unapplied', 'label' => 'Advance (unapplied)', 'type' => 'money'],
            ['key' => 'fee', 'label' => 'Gateway fee', 'type' => 'money', 'optional' => true]],
            'rows' => $out,
            'notes' => 'Posted collections by the date of payment. Not included: ' . (int)$pend['n'] . ' payment reference(s) waiting for verification (' . money($pend['amt']) . ')'
                . ((int)$rev['n'] ? ', and ' . (int)$rev['n'] . ' collection(s) reversed in the period (' . money($rev['amt']) . '), which are no longer collections' : '') . '.'
                . ($more ? ' <span class="text-danger">' . number_format($more) . ' more receipts are not listed (the total is of all): choose a shorter period.</span>' : '')];
    },
]);

Report::define('isp_cashier_collection', [
    'filters' => ['range'],
    'default_dates' => 'today',
    'extra_filters' => isprpt_pay_filters(),
    'run' => function (array $f): array {
        isprpt_ready();
        $p = ['f' => $f['from'], 't' => $f['to']];
        $w = isprpt_where($f, $p) . isprpt_pay_where($f, $p);
        $rows = DB::all("SELECT y.collector_user_id, y.channel, COUNT(*) AS n, SUM(y.amount) AS amt FROM isp_payments y JOIN isp_accounts a ON a.id = y.account_id
                          WHERE y.status = 'posted' AND y.pay_date BETWEEN :f AND :t$w GROUP BY y.collector_user_id, y.channel", $p);
        $users = isprpt_user_names();
        $by = [];
        foreach ($rows as $r) {
            $u = (int)$r['collector_user_id'];
            $by[$u] ??= ['n' => 0, 'cash' => 0.0, 'check' => 0.0, 'bank' => 0.0, 'ewallet' => 0.0, 'gateway' => 0.0, 'autodebit' => 0.0, 'other' => 0.0, 'total' => 0.0];
            $g = isprpt_channel_group((string)$r['channel']);
            $by[$u][$g] += (float)$r['amt'];
            $by[$u]['total'] += (float)$r['amt'];
            $by[$u]['n'] += (int)$r['n'];
        }
        $sp = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $sw = '';
        if (($f['branch'] ?? '') !== '') {
            $sw .= ' AND s.branch_id = :sb';
            $sp['sb'] = (int)$f['branch'];
        }
        if (($f['collector'] ?? '') !== '') {
            $sw .= ' AND s.user_id = :su';
            $sp['su'] = (int)$f['collector'];
        }
        if (($ub = IspSetup::userBranch()) !== null) {
            $sw .= ' AND (s.branch_id = :sub OR s.branch_id IS NULL)';
            $sp['sub'] = $ub;
        }
        $shifts = [];
        foreach (DB::all("SELECT s.user_id, COUNT(*) AS n, SUM(COALESCE(s.over_short, 0)) AS os, SUM(COALESCE(s.remitted_amount, 0)) AS remit, SUM(s.status = 'open') AS still_open
                            FROM isp_shifts s WHERE s.opened_at BETWEEN :f AND :t$sw GROUP BY s.user_id", $sp) as $s) {
            $shifts[(int)$s['user_id']] = $s;
        }
        $out = [];
        $keys = ['cash', 'check', 'bank', 'ewallet', 'gateway', 'autodebit', 'other', 'total'];
        $tot = array_fill_keys($keys, 0.0) + ['user' => 'TOTAL', 'n' => 0, 'shifts' => 0, 'os' => 0.0, 'remit' => 0.0];
        foreach ($by + array_fill_keys(array_diff(array_keys($shifts), array_keys($by)), null) as $u => $v) {
            $v ??= array_fill_keys($keys, 0.0) + ['n' => 0];
            $s = $shifts[$u] ?? null;
            $r = ['user' => $users[$u] ?? ('User #' . $u), 'n' => $v['n'], 'shifts' => $s ? (int)$s['n'] . ((int)$s['still_open'] ? ' (' . (int)$s['still_open'] . ' open)' : '') : '',
                'os' => $s ? r2((float)$s['os']) : '', 'remit' => $s ? r2((float)$s['remit']) : '',
                '_links' => ['user' => Report::link('rpt.isp_daily_collection', ['dates' => 'custom', 'from' => $f['from'], 'to' => $f['to'], 'collector' => $u])]];
            foreach ($keys as $k) {
                $r[$k] = r2($v[$k]) ?: '';
                $tot[$k] += $v[$k];
            }
            $tot['n'] += $v['n'];
            $tot['shifts'] += $s ? (int)$s['n'] : 0;
            $tot['os'] += $s ? (float)$s['os'] : 0;
            $tot['remit'] += $s ? (float)$s['remit'] : 0;
            $out[] = $r;
        }
        $tot = array_map(fn($v) => is_float($v) ? r2($v) : $v, $tot);
        return ['columns' => [['key' => 'user', 'label' => 'Collected by'], ['key' => 'n', 'label' => 'Receipts', 'type' => 'int'], ['key' => 'cash', 'label' => 'Cash', 'type' => 'money'],
            ['key' => 'check', 'label' => 'Checks', 'type' => 'money'], ['key' => 'bank', 'label' => 'Bank deposit / transfer', 'type' => 'money'], ['key' => 'ewallet', 'label' => 'GCash / Maya', 'type' => 'money'],
            ['key' => 'gateway', 'label' => 'Online gateway', 'type' => 'money'], ['key' => 'autodebit', 'label' => 'Savings auto-debit', 'type' => 'money'], ['key' => 'other', 'label' => 'Other', 'type' => 'money'],
            ['key' => 'total', 'label' => 'Total collected', 'type' => 'money'], ['key' => 'shifts', 'label' => 'Shifts'], ['key' => 'os', 'label' => 'Over / (short)', 'type' => 'money'],
            ['key' => 'remit', 'label' => 'Remitted', 'type' => 'money', 'optional' => true]],
            'rows' => $out, 'totals' => $tot,
            'notes' => 'Posted collections by the user who received them, and the cashier shifts opened in the period with their cash count over or short. Online and auto-debit payments are under the user (or the scheduler) that posted them.'];
    },
]);

Report::define('isp_by_method', [
    'filters' => ['range'],
    'extra_filters' => isprpt_pay_filters() + ['area' => ['Area', fn() => isprpt_areas()]],
    'run' => function (array $f): array {
        isprpt_ready();
        $p = ['f' => $f['from'], 't' => $f['to']];
        $w = isprpt_where($f, $p) . isprpt_pay_where($f, $p);
        $rows = DB::all("SELECT y.channel, y.source, COUNT(*) AS n, COUNT(DISTINCT y.account_id) AS accts, SUM(y.amount) AS amt, SUM(y.gateway_fee) AS fee
                           FROM isp_payments y JOIN isp_accounts a ON a.id = y.account_id WHERE y.status = 'posted' AND y.pay_date BETWEEN :f AND :t$w GROUP BY y.channel, y.source ORDER BY amt DESC", $p);
        $total = array_sum(array_map(fn($r) => (float)$r['amt'], $rows));
        $out = [];
        $chart = [];
        $tot = ['ch' => 'TOTAL', 'n' => 0, 'amt' => 0.0, 'fee' => 0.0, 'net' => 0.0];
        foreach ($rows as $r) {
            $label = IspPayments::CHANNELS[$r['channel']] ?? $r['channel'];
            $out[] = ['ch' => $label, 'source' => ucfirst((string)$r['source']), 'n' => (int)$r['n'], 'accts' => (int)$r['accts'], 'amt' => r2((float)$r['amt']),
                'avg' => (int)$r['n'] ? r2((float)$r['amt'] / (int)$r['n']) : '', 'fee' => r2((float)$r['fee']) ?: '', 'net' => r2((float)$r['amt'] - (float)$r['fee']),
                'share' => isprpt_pct((float)$r['amt'], $total),
                '_links' => ['ch' => Report::link('rpt.isp_daily_collection', ['dates' => 'custom', 'from' => $f['from'], 'to' => $f['to'], 'channel' => $r['channel']])]];
            $chart[$label] = ($chart[$label] ?? 0) + r2((float)$r['amt']);
            $tot['n'] += (int)$r['n'];
            $tot['amt'] += (float)$r['amt'];
            $tot['fee'] += (float)$r['fee'];
            $tot['net'] += (float)$r['amt'] - (float)$r['fee'];
        }
        $tot = array_map(fn($v) => is_float($v) ? r2($v) : $v, $tot) + ['share' => $total > 0 ? '100' : ''];
        return ['columns' => [['key' => 'ch', 'label' => 'Payment method'], ['key' => 'source', 'label' => 'Received at'], ['key' => 'n', 'label' => 'Receipts', 'type' => 'int'],
            ['key' => 'accts', 'label' => 'Accounts', 'type' => 'int'], ['key' => 'amt', 'label' => 'Amount', 'type' => 'money'], ['key' => 'avg', 'label' => 'Average', 'type' => 'money'],
            ['key' => 'fee', 'label' => 'Gateway fees', 'type' => 'money'], ['key' => 'net', 'label' => 'Net of fees', 'type' => 'money'], ['key' => 'share', 'label' => 'Share', 'type' => 'pct']],
            'rows' => $out, 'totals' => $tot, 'chart' => $chart ? ['pie' => $chart, 'pie_title' => 'Collections by payment method'] : [],
            'notes' => 'Posted collections in the period by payment method and where they were received (counter, field, portal, gateway, scheduler).'];
    },
]);

Report::define('isp_efficiency', [
    'filters' => ['range'],
    'default_dates' => 'this_year',
    'sortable' => false,
    'extra_filters' => isprpt_filters(['branch', 'area', 'plan']) + ['by' => ['Rows', fn() => ['month' => 'Month', 'branch' => 'Branch', 'plan' => 'Plan', 'area' => 'Area'], null]],
    'run' => function (array $f): array {
        isprpt_ready();
        $by = $f['by'] !== '' ? $f['by'] : 'month';
        $gInv = ['month' => "DATE_FORMAT(i.invoice_date, '%Y-%m')", 'branch' => 'a.branch_id', 'plan' => 'a.plan_id', 'area' => 'a.area_id'][$by];
        $gPay = ['month' => "DATE_FORMAT(y.pay_date, '%Y-%m')", 'branch' => 'a.branch_id', 'plan' => 'a.plan_id', 'area' => 'a.area_id'][$by];
        $p = ['f' => $f['from'], 't' => $f['to']];
        $w = isprpt_where($f, $p);
        $billed = [];
        foreach (DB::all("SELECT $gInv AS g, COUNT(*) AS n, SUM(i.total) AS total, SUM(i.paid_amount) AS paid, SUM(i.credit_amount) AS cred, SUM(i.balance) AS bal,
                                 SUM(CASE WHEN i.balance > 0.004 AND i.due_date < :d THEN i.balance ELSE 0 END) AS overdue
                            FROM isp_invoices i JOIN isp_accounts a ON a.id = i.account_id WHERE i.invoice_date BETWEEN :f AND :t AND i.status IN " . ISPRPT_BOOKED . "$w GROUP BY $gInv", $p + ['d' => today()]) as $r) {
            $billed[(string)$r['g']] = $r;
        }
        $p2 = ['f' => $f['from'], 't' => $f['to']];
        $w2 = isprpt_where($f, $p2);
        $coll = DB::pairs("SELECT $gPay AS g, SUM(y.amount) FROM isp_payments y JOIN isp_accounts a ON a.id = y.account_id WHERE y.status = 'posted' AND y.pay_date BETWEEN :f AND :t$w2 GROUP BY $gPay", $p2);
        // collected on the invoices of the period by their due date (on-time collection)
        $p3 = ['f' => $f['from'], 't' => $f['to']];
        $w3 = isprpt_where($f, $p3);
        $onTime = DB::pairs("SELECT $gInv AS g, SUM(al.amount) FROM isp_payment_allocs al JOIN isp_payments y ON y.id = al.payment_id JOIN isp_invoices i ON i.id = al.invoice_id JOIN isp_accounts a ON a.id = i.account_id
                              WHERE al.is_reversed = 0 AND y.status = 'posted' AND i.invoice_date BETWEEN :f AND :t AND i.status IN " . ISPRPT_BOOKED . " AND y.pay_date <= i.due_date$w3 GROUP BY $gInv", $p3);
        if ($by === 'month') {
            $names = isprpt_months($f);
            $keys = array_keys($names);
        } else {
            $keys = array_values(array_unique(array_merge(array_keys($billed), array_map('strval', array_keys($coll)))));
            $names = match ($by) {
                'branch' => IspSetup::branches(),
                'plan' => isprpt_plans(),
                default => isprpt_areas(),
            };
            usort($keys, fn($x, $y) => strnatcasecmp((string)($names[$x] ?? 'zz'), (string)($names[$y] ?? 'zz')));
        }
        $out = [];
        $tot = ['g' => 'TOTAL', 'n' => 0, 'billed' => 0.0, 'collected' => 0.0, 'settled' => 0.0, 'ontime' => 0.0, 'open' => 0.0, 'overdue' => 0.0];
        foreach ($keys as $k) {
            $b = $billed[$k] ?? null;
            $tb = (float)($b['total'] ?? 0);
            $settled = (float)($b['paid'] ?? 0) + (float)($b['cred'] ?? 0);
            $c = (float)($coll[$k] ?? 0);
            $ot = (float)($onTime[$k] ?? 0);
            $out[] = ['g' => (string)($names[$k] ?? ($k === '' ? '(none)' : $k)), 'n' => (int)($b['n'] ?? 0), 'billed' => r2($tb), 'collected' => r2($c), 'eff' => isprpt_pct($c, $tb),
                'settled' => r2($settled), 'settled_pct' => isprpt_pct($settled, $tb), 'ontime' => r2($ot), 'ontime_pct' => isprpt_pct($ot, $tb), 'open' => r2((float)($b['bal'] ?? 0)),
                'overdue' => r2((float)($b['overdue'] ?? 0))];
            $tot['n'] += (int)($b['n'] ?? 0);
            $tot['billed'] += $tb;
            $tot['collected'] += $c;
            $tot['settled'] += $settled;
            $tot['ontime'] += $ot;
            $tot['open'] += (float)($b['bal'] ?? 0);
            $tot['overdue'] += (float)($b['overdue'] ?? 0);
        }
        $tot = array_map(fn($v) => is_float($v) ? r2($v) : $v, $tot);
        $tot['eff'] = isprpt_pct($tot['collected'], $tot['billed']);
        $tot['settled_pct'] = isprpt_pct($tot['settled'], $tot['billed']);
        $tot['ontime_pct'] = isprpt_pct($tot['ontime'], $tot['billed']);
        $rowLabel = ['month' => 'Month', 'branch' => 'Branch', 'plan' => 'Plan', 'area' => 'Area'][$by];
        return ['columns' => [['key' => 'g', 'label' => $rowLabel], ['key' => 'n', 'label' => 'Invoices', 'type' => 'int'], ['key' => 'billed', 'label' => 'Billed', 'type' => 'money'],
            ['key' => 'collected', 'label' => 'Collected in the period', 'type' => 'money'], ['key' => 'eff', 'label' => 'Collection efficiency', 'type' => 'pct'],
            ['key' => 'settled', 'label' => 'Of the billing: paid / credited to date', 'type' => 'money'], ['key' => 'settled_pct', 'label' => '% of billing settled', 'type' => 'pct'],
            ['key' => 'ontime', 'label' => 'Paid by the due date', 'type' => 'money'], ['key' => 'ontime_pct', 'label' => 'On-time %', 'type' => 'pct'],
            ['key' => 'open', 'label' => 'Still open', 'type' => 'money'], ['key' => 'overdue', 'label' => 'Of it overdue', 'type' => 'money']],
            'rows' => $out, 'totals' => $tot,
            'notes' => 'Collection efficiency = what was collected in the period (payments by their date, advance payments included) against what was billed in it (invoices by their date). '
                . 'The settled columns follow the invoices billed in the period: how much of them is paid or credited today, and how much was paid by each due date.'];
    },
]);

Report::define('isp_aging', [
    'filters' => ['asof'],
    'extra_filters' => isprpt_filters() + ['detail' => ['Show', fn() => ['accounts' => 'One line per account', 'invoices' => 'Every invoice'], null]],
    'sortable' => false,
    'run' => function (array $f): array {
        isprpt_ready();
        $p = [];
        $w = isprpt_where($f, $p);
        $invs = isprpt_open_invoices($f['asof'], $w, $p);
        $credit = isprpt_unapplied_at($f['asof']);
        $detail = ($f['detail'] ?? '') === 'invoices';
        $detailN = 0;
        $byAcct = [];
        foreach ($invs as $i) {
            $a = (int)$i['account_id'];
            $byAcct[$a] ??= ['head' => ['account_no' => $i['account_no'], 'account_name' => $i['account_name'], 'service_status' => $i['service_status']],
                'b' => array_fill_keys(array_keys(ISPRPT_BUCKETS), 0.0), 'inv' => [], 'oldest' => null];
            $days = isprpt_days((string)$i['due_date'], $f['asof']);
            $byAcct[$a]['b'][isprpt_bucket($days)] += (float)$i['bal_at'];
            if ($detail && $detailN < ISPRPT_MAX_ROWS) {
                $byAcct[$a]['inv'][] = ['id' => $i['id'], 'invoice_no' => $i['invoice_no'], 'due_date' => $i['due_date'], 'bal_at' => $i['bal_at'], 'days' => $days];
                $detailN++;
            }
            if ($days > 0 && ($byAcct[$a]['oldest'] === null || $days > $byAcct[$a]['oldest'])) {
                $byAcct[$a]['oldest'] = $days;
            }
        }
        // the accounts with only an advance credit are listed too, so the net agrees with the ledger
        if ($credit) {
            $p2 = [];
            $w2 = isprpt_where($f, $p2);
            [$in, $ip] = DB::in('cr', array_keys($credit));
            foreach (DB::all("SELECT a.id AS account_id, a.account_no, a.account_name, a.service_status FROM isp_accounts a WHERE a.id IN $in$w2", $ip + $p2) as $a) {
                $byAcct[(int)$a['account_id']] ??= ['head' => $a, 'b' => array_fill_keys(array_keys(ISPRPT_BUCKETS), 0.0), 'inv' => [], 'oldest' => null];
            }
        }
        uasort($byAcct, fn($x, $y) => strcmp((string)$x['head']['account_no'], (string)$y['head']['account_no']));
        $out = [];
        $tot = array_fill_keys(array_keys(ISPRPT_BUCKETS), 0.0) + ['total' => 0.0, 'credit' => 0.0, 'net' => 0.0];
        foreach ($byAcct as $aid => $x) {
            $h = $x['head'];
            $total = array_sum($x['b']);
            $cr = (float)($credit[$aid] ?? 0);
            $r = ['acct' => $h['account_no'], 'name' => $h['account_name'], 'service' => IspAccounts::SERVICE_STATUSES[$h['service_status']] ?? $h['service_status'],
                'days' => $x['oldest'] ?: '', 'total' => r2($total), 'credit' => $cr ? -r2($cr) : '', 'net' => r2($total - $cr),
                '_class' => $detail ? 'group-row' : '', '_links' => ['acct' => isprpt_link('account', $aid)]];
            foreach (ISPRPT_BUCKETS as $k => $_) {
                $r[$k] = r2($x['b'][$k]) ?: '';
                $tot[$k] += $x['b'][$k];
            }
            $out[] = $r;
            if ($detail) {
                foreach ($x['inv'] as $i) {
                    $dr = ['acct' => $i['invoice_no'], 'name' => 'Due ' . fdate($i['due_date']), 'days' => $i['days'] > 0 ? $i['days'] : '', 'total' => r2((float)$i['bal_at']), '_class' => 'indent-1',
                        '_links' => ['acct' => isprpt_link('invoice', $i['id'])]];
                    $dr[isprpt_bucket($i['days'])] = r2((float)$i['bal_at']);
                    $out[] = $dr;
                }
            }
            $tot['total'] += $total;
            $tot['credit'] += $cr;
            $tot['net'] += $total - $cr;
        }
        $chart = [];
        foreach (ISPRPT_BUCKETS as $k => $label) {
            $chart[$label] = r2($tot[$k]);
        }
        $tot = array_map('r2', $tot);
        $tot['credit'] = -$tot['credit'];
        $tot['acct'] = 'TOTAL (' . count($byAcct) . ' accounts)';
        $out[] = $tot + ['_class' => 'total-row'];
        $over = $tot['total'] - $tot['current'];
        $out[] = ['acct' => 'Past due, % of the receivables', 'total' => $tot['total'] > 0 ? number_format($over / $tot['total'] * 100, 1) . ' %' : '', '_class' => 'total-row'];
        $cols = [['key' => 'acct', 'label' => 'Account no.'], ['key' => 'name', 'label' => 'Subscriber'], ['key' => 'service', 'label' => 'Service'], ['key' => 'days', 'label' => 'Oldest days past due', 'type' => 'int']];
        foreach (ISPRPT_BUCKETS as $k => $label) {
            $cols[] = ['key' => $k, 'label' => $label, 'type' => 'money'];
        }
        $cols[] = ['key' => 'total', 'label' => 'Open invoices', 'type' => 'money'];
        $cols[] = ['key' => 'credit', 'label' => 'Advance credit', 'type' => 'money'];
        $cols[] = ['key' => 'net', 'label' => 'Net receivable', 'type' => 'money'];
        $notes = 'Open invoice balances as of ' . fdate($f['asof']) . ', aged by the days past their due date; advance payments not yet applied are deducted to give the net receivable.'
            . ($detail && $detailN >= ISPRPT_MAX_ROWS ? ' <span class="text-danger">Only the first ' . number_format(ISPRPT_MAX_ROWS) . ' invoices are shown under their accounts; the account lines and totals are complete.</span>' : '');
        if (!isprpt_narrowed($f)) {
            $gl = isprpt_gl_ar($f['asof']);
            $notes .= ' A/R - Subscribers in the general ledger: <strong>' . money($gl) . '</strong>'
                . (abs($gl - $tot['net']) < 0.01 ? ', agrees with the net receivable.' : ', <span class="text-danger">differs from the net receivable by ' . money($gl - $tot['net']) . ': see Subscriber Ledger vs General Ledger.</span>');
        }
        return ['grouped' => true, 'columns' => $cols, 'rows' => $out, 'chart' => ['bars' => $chart, 'bars_title' => 'Receivables by days past due'], 'notes' => $notes];
    },
]);

/** True when a report is narrowed to part of the subscribers (the ledger total cannot be compared then) */
function isprpt_narrowed(array $f): bool
{
    foreach (['branch', 'area', 'plan', 'status', 'q'] as $k) {
        if (trim((string)($f[$k] ?? '')) !== '') {
            return true;
        }
    }
    return IspSetup::userBranch() !== null;
}

Report::define('isp_unapplied', [
    'filters' => ['search'],
    'filter_labels' => ['search' => 'Account or name'],
    'extra_filters' => isprpt_filters(['branch', 'area']) + ['show' => ['Show', fn() => ['both' => 'Credits and references waiting', 'credits' => 'Advance credits (unapplied)', 'pending' => 'References waiting for verification'], null]],
    'run' => function (array $f): array {
        isprpt_ready();
        $show = $f['show'] !== '' ? $f['show'] : 'both';
        $p = [];
        $w = isprpt_where($f, $p);
        $cond = match ($show) {
            'credits' => "y.status = 'posted' AND y.unapplied_amount > 0.004",
            'pending' => "y.status = 'pending'",
            default => "((y.status = 'posted' AND y.unapplied_amount > 0.004) OR y.status = 'pending')",
        };
        $rows = DB::all("SELECT y.*, a.account_no, a.account_name, a.service_status FROM isp_payments y JOIN isp_accounts a ON a.id = y.account_id WHERE $cond$w ORDER BY y.status, a.account_no, y.pay_date", $p);
        $open = DB::pairs("SELECT account_id, SUM(balance) FROM isp_invoices WHERE status IN ('posted', 'partially_paid', 'overdue', 'disputed') GROUP BY account_id");
        $out = [];
        $tot = ['receipt' => '', 'amount' => 0.0, 'unapplied' => 0.0, 'pending' => 0.0];
        foreach ($rows as $y) {
            $pending = $y['status'] === 'pending';
            $out[] = ['receipt' => $pending ? '(pending)' : $y['receipt_no'], 'date' => $y['pay_date'], 'acct' => $y['account_no'], 'name' => $y['account_name'],
                'channel' => IspPayments::CHANNELS[$y['channel']] ?? $y['channel'], 'ref' => $y['reference_no'], 'status' => IspPayments::STATUSES[$y['status']] ?? $y['status'],
                'amount' => (float)$y['amount'], 'unapplied' => $pending ? '' : (float)$y['unapplied_amount'], 'pending' => $pending ? (float)$y['amount'] : '',
                'open' => (float)($open[$y['account_id']] ?? 0) ?: '', 'age' => isprpt_days((string)$y['pay_date'], today()),
                'service' => IspAccounts::SERVICE_STATUSES[$y['service_status']] ?? $y['service_status'],
                '_links' => ['receipt' => isprpt_link('payment', $y['id']), 'acct' => isprpt_link('account', $y['account_id'])]];
            $tot['amount'] += (float)$y['amount'];
            $tot[$pending ? 'pending' : 'unapplied'] += $pending ? (float)$y['amount'] : (float)$y['unapplied_amount'];
        }
        $tot = array_map(fn($v) => is_float($v) ? r2($v) : $v, $tot);
        $tot['receipt'] = 'TOTAL (' . count($out) . ')';
        $canApply = count(array_filter($rows, fn($y) => $y['status'] === 'posted' && (float)($open[$y['account_id']] ?? 0) > 0.004));
        return ['columns' => [['key' => 'receipt', 'label' => 'Receipt no.'], ['key' => 'date', 'label' => 'Paid', 'type' => 'date'], ['key' => 'acct', 'label' => 'Account no.'], ['key' => 'name', 'label' => 'Subscriber'],
            ['key' => 'channel', 'label' => 'Method'], ['key' => 'ref', 'label' => 'Reference'], ['key' => 'status', 'label' => 'Status'], ['key' => 'amount', 'label' => 'Payment', 'type' => 'money'],
            ['key' => 'unapplied', 'label' => 'Unapplied credit', 'type' => 'money'], ['key' => 'pending', 'label' => 'Waiting for verification', 'type' => 'money'],
            ['key' => 'open', 'label' => 'Open invoices of the account', 'type' => 'money'], ['key' => 'age', 'label' => 'Days since paid', 'type' => 'int'], ['key' => 'service', 'label' => 'Service', 'optional' => true]],
            'rows' => $out, 'totals' => $tot,
            'notes' => 'Advance payments and overpayments not yet applied to an invoice (they are applied to the next invoices when they post), and payment references submitted but not yet verified (not in the books).'
                . ($canApply ? ' <span class="text-danger">' . $canApply . ' credit(s) belong to accounts with open invoices: apply them from the account.</span>' : '')];
    },
]);

Report::define('isp_delinquency', [
    'filters' => ['asof'],
    'extra_filters' => isprpt_filters() + ['days' => ['Past due at least', fn() => ['1' => '1 day', '30' => '30 days', '60' => '60 days', '90' => '90 days', '180' => '180 days'], null]],
    'run' => function (array $f): array {
        isprpt_ready();
        $min = max(1, (int)($f['days'] !== '' ? $f['days'] : 1));
        $p = [];
        $w = isprpt_where($f, $p);
        $acc = [];
        foreach (isprpt_open_invoices($f['asof'], $w, $p) as $i) {
            $a = (int)$i['account_id'];
            $days = isprpt_days((string)$i['due_date'], $f['asof']);
            $acc[$a] ??= ['i' => $i, 'overdue' => 0.0, 'n' => 0, 'oldest' => null, 'total' => 0.0];
            $acc[$a]['total'] += (float)$i['bal_at'];
            if ($days > 0) {
                $acc[$a]['overdue'] += (float)$i['bal_at'];
                $acc[$a]['n']++;
                $acc[$a]['oldest'] = $acc[$a]['oldest'] === null || $i['due_date'] < $acc[$a]['oldest'] ? $i['due_date'] : $acc[$a]['oldest'];
            }
        }
        $acc = array_filter($acc, fn($x) => $x['oldest'] !== null && isprpt_days($x['oldest'], $f['asof']) >= $min);
        $ids = array_keys($acc);
        $last = $disc = $prot = $credit = [];
        if ($ids) {
            [$in, $ip] = DB::in('dq', $ids);
            foreach (DB::all("SELECT y.account_id, y.pay_date, y.amount FROM isp_payments y WHERE y.status = 'posted' AND y.account_id IN $in AND y.pay_date <= :d ORDER BY y.pay_date, y.id", $ip + ['d' => $f['asof']]) as $y) {
                $last[(int)$y['account_id']] = $y;
            }
            [$in2, $ip2] = DB::in('dq2', $ids);
            foreach (DB::all("SELECT account_id, status, queue_no FROM isp_disc_queue WHERE account_id IN $in2 AND status IN ('candidate', 'approved', 'notified', 'executing', 'failed') ORDER BY id", $ip2) as $q) {
                $disc[(int)$q['account_id']] = (IspDisconnect::STATUSES[$q['status']] ?? $q['status']) . ' ' . $q['queue_no'];
            }
            [$in3, $ip3] = DB::in('dq3', $ids);
            foreach (DB::all("SELECT account_id, 'Dispute open' AS what FROM isp_disputes WHERE account_id IN $in3 AND status IN ('open', 'investigating') AND protect = 1", $ip3) as $x) {
                $prot[(int)$x['account_id']][] = $x['what'];
            }
            [$in4, $ip4] = DB::in('dq4', $ids);
            foreach (DB::all("SELECT account_id, 'Payment arrangement' AS what FROM isp_arrangements WHERE account_id IN $in4 AND status = 'active' AND protect = 1", $ip4) as $x) {
                $prot[(int)$x['account_id']][] = $x['what'];
            }
            [$in5, $ip5] = DB::in('dq5', $ids);
            foreach (DB::all("SELECT account_id, 'Exempt' AS what FROM isp_exemptions WHERE account_id IN $in5 AND status = 'active' AND valid_from <= :d1 AND valid_until >= :d2", $ip5 + ['d1' => $f['asof'], 'd2' => $f['asof']]) as $x) {
                $prot[(int)$x['account_id']][] = $x['what'];
            }
            $credit = isprpt_unapplied_at($f['asof']);
        }
        $mob = $ids ? DB::pairs('SELECT a.id, c.mobile FROM isp_accounts a JOIN isp_customers c ON c.id = a.isp_customer_id WHERE a.id IN ' . DB::in('mb', $ids)[0], DB::in('mb', $ids)[1]) : [];
        uasort($acc, fn($x, $y) => strcmp((string)$x['oldest'], (string)$y['oldest']) ?: $y['overdue'] <=> $x['overdue']);
        $out = [];
        $tot = ['acct' => '', 'overdue' => 0.0, 'total' => 0.0, 'credit' => 0.0];
        $bands = ['1 - 30' => 0, '31 - 60' => 0, '61 - 90' => 0, 'Over 90' => 0];
        foreach ($acc as $aid => $x) {
            $i = $x['i'];
            $days = isprpt_days($x['oldest'], $f['asof']);
            $bands[ISPRPT_BUCKETS[isprpt_bucket($days)]]++;
            $l = $last[$aid] ?? null;
            $cr = (float)($credit[$aid] ?? 0);
            $out[] = ['acct' => $i['account_no'], 'name' => $i['account_name'], 'service' => IspAccounts::SERVICE_STATUSES[$i['service_status']] ?? $i['service_status'], 'plan' => $i['plan_name'],
                'mobile' => isprpt_mask($mob[$aid] ?? ''), 'oldest' => $x['oldest'], 'days' => $days, 'n' => $x['n'], 'overdue' => r2($x['overdue']), 'total' => r2($x['total']),
                'credit' => $cr ? -r2($cr) : '', 'last_date' => $l['pay_date'] ?? '', 'last_amt' => $l ? (float)$l['amount'] : '', 'disc' => $disc[$aid] ?? '',
                'protect' => implode(', ', $prot[$aid] ?? []), '_links' => ['acct' => isprpt_link('account', $aid)]];
            $tot['overdue'] += $x['overdue'];
            $tot['total'] += $x['total'];
            $tot['credit'] += $cr;
        }
        $tot = array_map(fn($v) => is_float($v) ? r2($v) : $v, $tot);
        $tot['credit'] = $tot['credit'] ? -$tot['credit'] : '';
        $tot['acct'] = 'TOTAL (' . count($out) . ' accounts)';
        return ['columns' => [['key' => 'acct', 'label' => 'Account no.'], ['key' => 'name', 'label' => 'Subscriber'], ['key' => 'service', 'label' => 'Service'], ['key' => 'plan', 'label' => 'Plan', 'optional' => true],
            ['key' => 'mobile', 'label' => 'Mobile'], ['key' => 'oldest', 'label' => 'Oldest due', 'type' => 'date'], ['key' => 'days', 'label' => 'Days past due', 'type' => 'int'],
            ['key' => 'n', 'label' => 'Overdue invoices', 'type' => 'int'], ['key' => 'overdue', 'label' => 'Overdue', 'type' => 'money'], ['key' => 'total', 'label' => 'Total open', 'type' => 'money'],
            ['key' => 'credit', 'label' => 'Advance credit', 'type' => 'money', 'optional' => true], ['key' => 'last_date', 'label' => 'Last payment', 'type' => 'date'], ['key' => 'last_amt', 'label' => 'Amount', 'type' => 'money'],
            ['key' => 'disc', 'label' => 'Disconnection'], ['key' => 'protect', 'label' => 'Protected by']],
            'rows' => $out, 'totals' => $tot,
            'notes' => 'Accounts with invoices unpaid past their due date as of ' . fdate($f['asof']) . ', oldest first. By days past due: '
                . h(implode(', ', array_map(fn($k, $v) => $k . ' days: ' . $v, array_keys($bands), $bands))) . '. Disputes, payment arrangements and exemptions that protect an account from disconnection are shown.'];
    },
]);

// ====================================================================== financial reports

/**
 * Revenue and the direct costs traceable to subscriber accounts in a period, grouped by $groupSql (an expression on
 * the account alias a): revenue = invoice lines net of VAT (deposits left out, they are a liability), less the service
 * credits posted; direct costs = work order materials used and the labor and travel recorded on the work orders
 * completed, gateway fees of the collections, agent commissions approved or paid, and write-offs.
 * Returns [group key => [revenue, vat, credits, writeoff, materials, labor, fees, commissions, invoices]].
 */
function isprpt_profit(array $f, string $groupSql): array
{
    $out = [];
    $add = function (array $rows, string $field) use (&$out) {
        foreach ($rows as $r) {
            $k = (string)($r['g'] ?? '');
            $out[$k] ??= ['revenue' => 0.0, 'vat' => 0.0, 'credits' => 0.0, 'writeoff' => 0.0, 'materials' => 0.0, 'labor' => 0.0, 'fees' => 0.0, 'commissions' => 0.0, 'invoices' => 0];
            if ($field === 'revenue') {
                $out[$k]['revenue'] += (float)$r['amt'];
                $out[$k]['vat'] += (float)$r['vat'];
                $out[$k]['invoices'] += (int)$r['n'];
            } else {
                $out[$k][$field] += (float)$r['amt'];
            }
        }
    };
    $q = function (string $sql, array $p) use ($f) {
        $w = isprpt_where($f, $p);
        return DB::all(str_replace('{W}', $w, $sql), $p);
    };
    $groupLine = str_contains($groupSql, 'a.plan_id') ? str_replace('a.plan_id', 'COALESCE(l.plan_id, a.plan_id)', $groupSql) : $groupSql;
    $add($q("SELECT $groupLine AS g, SUM(l.amount) AS amt, SUM(l.vat_amount) AS vat, COUNT(DISTINCT i.id) AS n FROM isp_invoice_lines l JOIN isp_invoices i ON i.id = l.invoice_id JOIN isp_accounts a ON a.id = i.account_id
              WHERE i.invoice_date BETWEEN :f AND :t AND i.status IN " . ISPRPT_BOOKED . " AND l.line_type <> 'deposit'{W} GROUP BY $groupLine", ['f' => $f['from'], 't' => $f['to']]), 'revenue');
    $add($q("SELECT $groupSql AS g, SUM(x.amount) AS amt FROM isp_adjustments x JOIN isp_accounts a ON a.id = x.account_id LEFT JOIN transactions t ON t.id = x.txn_id
              WHERE x.status = 'posted' AND x.adj_type IN ('credit', 'outage_credit', 'penalty_waiver', 'dispute_credit') AND COALESCE(t.txn_date, DATE(x.approved_at)) BETWEEN :f AND :t{W} GROUP BY $groupSql",
        ['f' => $f['from'], 't' => $f['to']]), 'credits');
    $add($q("SELECT $groupSql AS g, SUM(x.amount) AS amt FROM isp_adjustments x JOIN isp_accounts a ON a.id = x.account_id LEFT JOIN transactions t ON t.id = x.txn_id
              WHERE x.status = 'posted' AND x.adj_type = 'write_off' AND COALESCE(t.txn_date, DATE(x.approved_at)) BETWEEN :f AND :t{W} GROUP BY $groupSql", ['f' => $f['from'], 't' => $f['to']]), 'writeoff');
    $add($q("SELECT $groupSql AS g, SUM(m.qty_used * m.unit_cost) AS amt FROM isp_wo_materials m JOIN isp_work_orders w ON w.id = m.work_order_id JOIN isp_accounts a ON a.id = w.account_id
              WHERE w.status IN ('completed', 'verified') AND w.completed_at BETWEEN :f AND :t{W} GROUP BY $groupSql", ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59']), 'materials');
    $add($q("SELECT $groupSql AS g, SUM(COALESCE(w.labor_cost, 0) + COALESCE(w.travel_expense, 0)) AS amt FROM isp_work_orders w JOIN isp_accounts a ON a.id = w.account_id
              WHERE w.status IN ('completed', 'verified') AND w.completed_at BETWEEN :f AND :t{W} GROUP BY $groupSql", ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59']), 'labor');
    $add($q("SELECT $groupSql AS g, SUM(y.gateway_fee) AS amt FROM isp_payments y JOIN isp_accounts a ON a.id = y.account_id
              WHERE y.status = 'posted' AND y.pay_date BETWEEN :f AND :t{W} GROUP BY $groupSql", ['f' => $f['from'], 't' => $f['to']]), 'fees');
    $add($q("SELECT $groupSql AS g, SUM(m.amount) AS amt FROM isp_commissions m JOIN isp_accounts a ON a.id = m.account_id
              WHERE m.status IN ('approved', 'paid') AND m.created_at BETWEEN :f AND :t{W} GROUP BY $groupSql", ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59']), 'commissions');
    return $out;
}

/** The columns of the two profitability reports */
function isprpt_profit_cols(string $first, array $extra = []): array
{
    return array_merge([['key' => 'g', 'label' => $first]], $extra, [
        ['key' => 'invoices', 'label' => 'Invoices', 'type' => 'int', 'optional' => true],
        ['key' => 'revenue', 'label' => 'Billed (net of VAT)', 'type' => 'money'], ['key' => 'credits', 'label' => 'Service credits', 'type' => 'money'],
        ['key' => 'net', 'label' => 'Net revenue', 'type' => 'money'], ['key' => 'materials', 'label' => 'Materials used', 'type' => 'money', 'sensitive' => 'cost'],
        ['key' => 'labor', 'label' => 'Field labor & travel', 'type' => 'money'], ['key' => 'fees', 'label' => 'Gateway fees', 'type' => 'money'],
        ['key' => 'commissions', 'label' => 'Agent commissions', 'type' => 'money'], ['key' => 'writeoff', 'label' => 'Write-offs', 'type' => 'money'],
        ['key' => 'direct', 'label' => 'Direct costs', 'type' => 'money', 'sensitive' => 'cost'], ['key' => 'margin', 'label' => 'Contribution margin', 'type' => 'money', 'sensitive' => 'cost'],
        ['key' => 'margin_pct', 'label' => 'Margin', 'type' => 'pct', 'sensitive' => 'cost']]);
}

function isprpt_profit_row(array $v): array
{
    $net = r2($v['revenue'] - $v['credits']);
    $direct = r2($v['materials'] + $v['labor'] + $v['fees'] + $v['commissions'] + $v['writeoff']);
    return ['invoices' => $v['invoices'], 'revenue' => r2($v['revenue']), 'credits' => $v['credits'] ? -r2($v['credits']) : '', 'net' => $net, 'materials' => r2($v['materials']) ?: '',
        'labor' => r2($v['labor']) ?: '', 'fees' => r2($v['fees']) ?: '', 'commissions' => r2($v['commissions']) ?: '', 'writeoff' => r2($v['writeoff']) ?: '',
        'direct' => $direct, 'margin' => r2($net - $direct), 'margin_pct' => isprpt_pct($net - $direct, $net)];
}

defined('ISPRPT_PROFIT_NOTE') || define('ISPRPT_PROFIT_NOTE', 'Billed = the invoice lines in the books dated in the period, net of VAT (security deposits are not revenue and are left out); service credits = credit adjustments, '
    . 'outage credits, penalty waivers and dispute credits posted in the period. Direct costs = materials used on the work orders completed in the period (at their issue cost), the labor '
    . 'and travel recorded on them, the gateway fees of the period\'s collections, the agent commissions approved or paid, and the write-offs posted. ');

Report::define('isp_plan_profit', [
    'filters' => ['range'],
    'default_dates' => 'this_year',
    'extra_filters' => isprpt_filters(['branch', 'area']),
    'run' => function (array $f): array {
        isprpt_ready();
        $data = isprpt_profit($f, 'a.plan_id');
        $p = [];
        $w = isprpt_where($f, $p);
        $subs = DB::pairs("SELECT a.plan_id, COUNT(*) FROM isp_accounts a WHERE a.service_status = 'active'$w GROUP BY a.plan_id", $p);
        $plans = DB::all('SELECT id, code, name, monthly_fee FROM isp_plans ORDER BY sort_order, code');
        $out = [];
        $zero = ['revenue' => 0.0, 'vat' => 0.0, 'credits' => 0.0, 'writeoff' => 0.0, 'materials' => 0.0, 'labor' => 0.0, 'fees' => 0.0, 'commissions' => 0.0, 'invoices' => 0];
        $sum = $zero;
        $months = max(1, count(Report::periods($f['from'], $f['to'], 'month')));
        foreach ($plans as $pl) {
            $v = $data[(string)$pl['id']] ?? null;
            if (!$v && empty($subs[$pl['id']])) {
                continue;
            }
            $v ??= $zero;
            $n = (int)($subs[$pl['id']] ?? 0);
            $row = ['g' => $pl['code'] . ' - ' . $pl['name'], 'subs' => $n, 'fee' => (float)$pl['monthly_fee']] + isprpt_profit_row($v);
            $row['arpu'] = $n ? r2((float)$row['net'] / $n / $months) : '';
            $row['_links'] = ['g' => Report::link('rpt.isp_active', ['plan' => $pl['id']])];
            $out[] = $row;
            foreach ($zero as $k => $_) {
                $sum[$k] += $v[$k];
            }
        }
        if (isset($data[''])) {
            $out[] = ['g' => '(no plan)', 'subs' => ''] + isprpt_profit_row($data['']);
            foreach ($zero as $k => $_) {
                $sum[$k] += $data[''][$k];
            }
        }
        $totSubs = array_sum(array_map('intval', $subs));
        $tot = ['g' => 'TOTAL', 'subs' => $totSubs] + isprpt_profit_row($sum);
        $tot['arpu'] = $totSubs ? r2((float)$tot['net'] / $totSubs / $months) : '';
        return ['columns' => isprpt_profit_cols('Plan', [['key' => 'subs', 'label' => 'Active now', 'type' => 'int'], ['key' => 'fee', 'label' => 'List price', 'type' => 'money', 'optional' => true]]),
            'rows' => $out, 'totals' => $tot,
            'notes' => ISPRPT_PROFIT_NOTE . 'Revenue goes to the plan billed on the line (other charges to the account\'s plan). Network, bandwidth and office overheads are not allocated: see Branch Profitability. '
                . 'Net revenue per active subscriber per month: ' . ($totSubs ? money((float)$tot['net'] / $totSubs / $months) : '-') . '.'];
    },
]);

Report::define('isp_branch_profit', [
    'filters' => ['range'],
    'default_dates' => 'this_year',
    'extra_filters' => ['branch' => ['Branch', fn() => isprpt_branches()], 'plan' => ['Plan', fn() => isprpt_plans()]],
    'run' => function (array $f): array {
        isprpt_ready();
        $data = isprpt_profit($f, 'a.branch_id');
        $branches = DB::all('SELECT id, code, name, class_id FROM coop_branches ORDER BY is_head_office DESC, name');
        // the other expenses of each branch in the ledger (its class), apart from the ISP documents already counted above
        $classOf = [];
        foreach ($branches as $b) {
            if ($b['class_id']) {
                $classOf[(int)$b['class_id']] = (int)$b['id'];
            }
        }
        $other = [];
        if ($classOf && ($f['plan'] ?? '') === '') {
            [$in, $ip] = DB::in('cl', array_keys($classOf));
            foreach (DB::all("SELECT jl.class_id, SUM(jl.debit - jl.credit) AS amt FROM journal_lines jl JOIN accounts ac ON ac.id = jl.account_id
                               WHERE ac.category = 'Expense' AND jl.class_id IN $in AND jl.txn_date BETWEEN :f AND :t
                                 AND NOT EXISTS (SELECT 1 FROM isp_postings ps WHERE ps.txn_id = jl.txn_id)
                                 AND NOT EXISTS (SELECT 1 FROM isp_work_orders w WHERE w.expense_txn_id = jl.txn_id)
                               GROUP BY jl.class_id", $ip + ['f' => $f['from'], 't' => $f['to']]) as $r) {
                $other[$classOf[(int)$r['class_id']]] = (float)$r['amt'];
            }
        }
        $p = [];
        $w = isprpt_where($f, $p);
        $subs = DB::pairs("SELECT a.branch_id, COUNT(*) FROM isp_accounts a WHERE a.service_status = 'active'$w GROUP BY a.branch_id", $p);
        $out = [];
        $zero = ['revenue' => 0.0, 'vat' => 0.0, 'credits' => 0.0, 'writeoff' => 0.0, 'materials' => 0.0, 'labor' => 0.0, 'fees' => 0.0, 'commissions' => 0.0, 'invoices' => 0];
        $sum = $zero;
        $otherSum = 0.0;
        $ub = IspSetup::userBranch();
        foreach (array_merge($branches, [['id' => '', 'code' => '', 'name' => '(no branch)', 'class_id' => null]]) as $b) {
            $key = (string)$b['id'];
            if (($f['branch'] ?? '') !== '' && $key !== (string)$f['branch']) {
                continue;
            }
            if ($ub !== null && $key !== (string)$ub && $key !== '') {
                continue;
            }
            $v = $data[$key] ?? null;
            $o = (float)($other[(int)$b['id']] ?? 0);
            if (!$v && !$o && empty($subs[$b['id']])) {
                continue;
            }
            $v ??= $zero;
            $row = ['g' => trim($b['code'] . ' ' . $b['name']), 'subs' => (int)($subs[$b['id']] ?? 0)] + isprpt_profit_row($v);
            $row['other'] = $b['class_id'] ? r2($o) : '';
            $row['profit'] = r2((float)$row['margin'] - $o);
            $out[] = $row;
            foreach ($zero as $k => $_) {
                $sum[$k] += $v[$k];
            }
            $otherSum += $o;
        }
        $tot = ['g' => 'TOTAL', 'subs' => array_sum(array_column($out, 'subs'))] + isprpt_profit_row($sum);
        $tot['other'] = r2($otherSum);
        $tot['profit'] = r2((float)$tot['margin'] - $otherSum);
        $cols = isprpt_profit_cols('Branch', [['key' => 'subs', 'label' => 'Active now', 'type' => 'int']]);
        $cols[] = ['key' => 'other', 'label' => 'Other branch expenses (ledger)', 'type' => 'money'];
        $cols[] = ['key' => 'profit', 'label' => 'Branch contribution', 'type' => 'money', 'sensitive' => 'cost'];
        $noClass = count(array_filter($branches, fn($b) => !$b['class_id']));
        return ['columns' => $cols, 'rows' => $out, 'totals' => $tot,
            'notes' => ISPRPT_PROFIT_NOTE . 'Other branch expenses = every other expense posted in the general ledger in the period under the class of the branch (salaries, rent, power, '
                . 'bandwidth...), apart from the documents of the cable / internet system counted above.'
                . ($noClass ? ' ' . $noClass . ' branch(es) have no class, so their ledger expenses cannot be told apart (Cooperative > Branches).' : '')
                . (($f['plan'] ?? '') !== '' ? ' With a plan chosen, the ledger expenses are left out.' : '')];
    },
]);

Report::define('isp_arpu', [
    'filters' => ['range'],
    'default_dates' => 'this_year',
    'sortable' => false,
    'extra_filters' => isprpt_filters(['branch', 'area', 'plan']),
    'run' => function (array $f): array {
        isprpt_ready();
        $months = isprpt_months($f);
        $p = ['f' => $f['from'], 't' => $f['to']];
        $w = isprpt_where($f, $p);
        $rev = [];
        foreach (DB::all("SELECT DATE_FORMAT(i.invoice_date, '%Y-%m') AS ym, SUM(CASE WHEN l.line_type IN ('subscription', 'prorate', 'plan_change') THEN l.amount ELSE 0 END) AS service,
                                 SUM(CASE WHEN l.line_type NOT IN ('subscription', 'prorate', 'plan_change') THEN l.amount ELSE 0 END) AS other
                            FROM isp_invoice_lines l JOIN isp_invoices i ON i.id = l.invoice_id JOIN isp_accounts a ON a.id = i.account_id
                           WHERE i.invoice_date BETWEEN :f AND :t AND i.status IN " . ISPRPT_BOOKED . " AND l.line_type <> 'deposit'$w GROUP BY ym", $p) as $r) {
            $rev[$r['ym']] = $r;
        }
        $out = [];
        $tot = ['m' => 'TOTAL', 'service' => 0.0, 'other' => 0.0, 'total' => 0.0];
        $avgSum = 0.0;
        foreach ($months as $ym => $label) {
            $start = $ym . '-01';
            $end = date('Y-m-t', strtotime($start));
            $pp = ['s' => $start, 's2' => $start . ' 00:00:00'];
            $ww = isprpt_where($f, $pp);
            $open = (int)DB::value("SELECT COUNT(*) FROM isp_accounts a WHERE a.activation_date < :s AND (a.terminated_at IS NULL OR a.terminated_at >= :s2)$ww", $pp);
            $pe = ['e' => $end, 'e2' => $end . ' 23:59:59'];
            $we = isprpt_where($f, $pe);
            $close = (int)DB::value("SELECT COUNT(*) FROM isp_accounts a WHERE a.activation_date <= :e AND (a.terminated_at IS NULL OR a.terminated_at > :e2)$we", $pe);
            $avg = ($open + $close) / 2;
            $s = (float)($rev[$ym]['service'] ?? 0);
            $o = (float)($rev[$ym]['other'] ?? 0);
            $out[] = ['m' => $label, 'open' => $open, 'close' => $close, 'avg' => round($avg, 1), 'service' => r2($s), 'other' => r2($o), 'total' => r2($s + $o),
                'arpu_service' => $avg > 0 ? r2($s / $avg) : '', 'arpu' => $avg > 0 ? r2(($s + $o) / $avg) : ''];
            $tot['service'] += $s;
            $tot['other'] += $o;
            $tot['total'] += $s + $o;
            $avgSum += $avg;
        }
        $n = max(1, count($months));
        $avgSubs = $avgSum / $n;
        $tot = array_map(fn($v) => is_float($v) ? r2($v) : $v, $tot);
        $tot['avg'] = '';
        $tot['arpu_service'] = $avgSubs > 0 ? r2($tot['service'] / $avgSubs / $n) : '';
        $tot['arpu'] = $avgSubs > 0 ? r2($tot['total'] / $avgSubs / $n) : '';
        return ['columns' => [['key' => 'm', 'label' => 'Month'], ['key' => 'open', 'label' => 'Subscribers at start', 'type' => 'int'], ['key' => 'close', 'label' => 'At end', 'type' => 'int'],
            ['key' => 'avg', 'label' => 'Average', 'type' => 'qty'], ['key' => 'service', 'label' => 'Service revenue', 'type' => 'money'], ['key' => 'other', 'label' => 'Other charges', 'type' => 'money'],
            ['key' => 'total', 'label' => 'Total billed', 'type' => 'money'], ['key' => 'arpu_service', 'label' => 'Service revenue per subscriber', 'type' => 'money'],
            ['key' => 'arpu', 'label' => 'Revenue per subscriber (ARPU)', 'type' => 'money']],
            'rows' => $out, 'totals' => $tot,
            'notes' => 'Revenue billed in each month (net of VAT, deposits left out) per average subscriber of the month (the subscribers at its start and end; disconnected and suspended accounts '
                . 'count until terminated). On the total line, ARPU = what was billed in the period over its subscriber-months (' . number_format($avgSum, 1) . ').'];
    },
]);

Report::define('isp_reconcile', [
    'filters' => ['asof'],
    'sortable' => false,
    'run' => function (array $f): array {
        isprpt_ready();
        if (IspSetup::userBranch() !== null) {
            return ['columns' => [['key' => 'item', 'label' => 'Item / document']], 'rows' => [],
                'notes' => 'The reconciliation covers the receivables of the whole company and is for users who see every branch.'];
        }
        $d = $f['asof'];
        $arId = IspSetup::account('isp_ar');
        $invTotal = (float)DB::value("SELECT COALESCE(SUM(total), 0) FROM isp_invoices WHERE status IN " . ISPRPT_BOOKED . " AND invoice_date <= :d", ['d' => $d]);
        $payTotal = (float)DB::value("SELECT COALESCE(SUM(amount), 0) FROM isp_payments WHERE status = 'posted' AND pay_date <= :d", ['d' => $d]);
        $credTotal = (float)array_sum(array_map('floatval', isprpt_credits_by_invoice($d)));
        $open = 0.0;
        foreach (isprpt_open_invoices($d) as $i) {
            $open += (float)$i['bal_at'];
        }
        $unapplied = array_sum(isprpt_unapplied_at($d));
        $net = r2($open - $unapplied);
        $gl = isprpt_gl_ar($d);
        $rows = [];
        $rows[] = ['item' => 'Subscriber ledger', '_class' => 'group-row'];
        $rows[] = ['item' => 'Open invoice balances', 'isp' => r2($open), '_class' => 'indent-1', '_links' => ['item' => Report::link('rpt.isp_unpaid', ['asof' => $d, 'over' => 'all'])]];
        $rows[] = ['item' => 'Less advance credits not yet applied', 'isp' => -r2($unapplied), '_class' => 'indent-1', '_links' => ['item' => Report::link('rpt.isp_unapplied', [])]];
        $rows[] = ['item' => 'Subscriber ledger, net', 'isp' => $net, '_class' => 'subtotal-row'];
        $rows[] = ['item' => 'Invoices ' . money($invTotal) . ' less collections ' . money($payTotal) . ' less credits ' . money($credTotal), 'isp' => r2($invTotal - $payTotal - $credTotal), '_class' => 'indent-1'];
        $rows[] = ['item' => 'General ledger: ' . (string)DB::value("SELECT CONCAT(code, ' ', name) FROM accounts WHERE id = :a", ['a' => $arId]), 'gl' => $gl, '_class' => 'subtotal-row',
            '_links' => ['item' => Report::link('rpt.general_ledger', ['account_id' => $arId, 'asof' => $d])]];
        $rows[] = ['item' => 'Difference (subscriber ledger less general ledger)', 'diff' => r2($net - $gl), '_class' => 'total-row'];

        // the documents behind any difference: each ISP posting against what its ledger document put on the account
        $issues = [];
        foreach (DB::all("SELECT ps.id, ps.event_key, ps.doc_no, ps.isp_account_id, ps.txn_id, ps.txn_type, ps.amount, t.txn_date, t.txn_type AS ledger_type,
                                 CASE WHEN ps.txn_type = 'invoice' THEN ps.amount ELSE -ps.amount END AS expect, COALESCE(SUM(jl.debit - jl.credit), 0) AS actual
                            FROM isp_postings ps LEFT JOIN transactions t ON t.id = ps.txn_id LEFT JOIN journal_lines jl ON jl.txn_id = ps.txn_id AND jl.account_id = :a
                           WHERE ps.status = 'posted' AND ps.txn_type IN ('invoice', 'payment', 'credit_memo') AND ps.source_type IN ('invoice', 'payment', 'adjustment')
                             AND (t.id IS NULL OR t.txn_date <= :d)
                           GROUP BY ps.id, ps.event_key, ps.doc_no, ps.isp_account_id, ps.txn_id, ps.txn_type, ps.amount, t.txn_date, t.txn_type
                          HAVING t.txn_date IS NULL OR ABS(expect - actual) > 0.009 LIMIT 2001", ['a' => $arId, 'd' => $d]) as $ps) {
            if ($ps['txn_date'] === null) {
                $issues[] = ['item' => $ps['event_key'] . ' ' . $ps['doc_no'], 'date' => '', 'isp' => r2((float)$ps['expect']), 'gl' => 0.0, 'note' => 'The ledger document of the posting is missing', 'acct' => $ps['isp_account_id']];
                continue;
            }
            $actual = (float)$ps['actual'];
            $issues[] = ['item' => $ps['event_key'] . ' ' . $ps['doc_no'], 'date' => $ps['txn_date'], 'isp' => r2((float)$ps['expect']), 'gl' => r2($actual),
                'note' => abs($actual) < 0.005 ? 'Not on the A/R - Subscribers account in the ledger (voided or posted elsewhere)' : 'The ledger amount differs from the ISP document', 'acct' => $ps['isp_account_id'],
                'txn' => (int)$ps['txn_id'], 'txn_type' => $ps['ledger_type']];
        }
        // entries on the account that did not come from the cable / internet system
        foreach (DB::all("SELECT jl.txn_id, SUM(jl.debit - jl.credit) AS amt, t.txn_type, t.txn_no, t.txn_date, t.memo FROM journal_lines jl JOIN transactions t ON t.id = jl.txn_id
                           WHERE jl.account_id = :a AND jl.txn_date <= :d AND NOT EXISTS (SELECT 1 FROM isp_postings ps WHERE ps.txn_id = jl.txn_id)
                           GROUP BY jl.txn_id, t.txn_type, t.txn_no, t.txn_date, t.memo HAVING ABS(SUM(jl.debit - jl.credit)) > 0.004 LIMIT 2001", ['a' => $arId, 'd' => $d]) as $t) {
            $issues[] = ['item' => Txn::label((string)$t['txn_type']) . ' ' . ($t['txn_no'] ?: $t['txn_id']), 'date' => $t['txn_date'], 'isp' => 0.0, 'gl' => r2((float)$t['amt']),
                'note' => 'Entered directly on A/R - Subscribers in the ledger, not from the cable / internet system: ' . $t['memo'], 'txn' => (int)$t['txn_id'], 'txn_type' => $t['txn_type']];
        }
        // ISP documents in the books with no posting
        foreach (DB::all("SELECT i.id, i.invoice_no, i.invoice_date, i.total, i.account_id FROM isp_invoices i WHERE i.status IN " . ISPRPT_BOOKED . " AND i.invoice_date <= :d
                           AND NOT EXISTS (SELECT 1 FROM isp_postings ps WHERE ps.event_key = CONCAT('INV-', i.id) AND ps.status = 'posted') LIMIT 2001", ['d' => $d]) as $i) {
            $issues[] = ['item' => 'Invoice ' . $i['invoice_no'], 'date' => $i['invoice_date'], 'isp' => (float)$i['total'], 'gl' => 0.0, 'note' => 'In the subscriber ledger but not posted to the books', 'acct' => $i['account_id']];
        }
        foreach (DB::all("SELECT y.id, y.receipt_no, y.pay_date, y.amount, y.account_id FROM isp_payments y WHERE y.status = 'posted' AND y.pay_date <= :d
                           AND NOT EXISTS (SELECT 1 FROM isp_postings ps WHERE ps.event_key = CONCAT('PAY-', y.id) AND ps.status = 'posted') LIMIT 2001", ['d' => $d]) as $y) {
            $issues[] = ['item' => 'Collection ' . $y['receipt_no'], 'date' => $y['pay_date'], 'isp' => -(float)$y['amount'], 'gl' => 0.0, 'note' => 'In the subscriber ledger but not posted to the books', 'acct' => $y['account_id']];
        }
        // invoice by invoice (today): open in the subscriber ledger against open on its ledger invoice
        if ($d >= today()) {
            foreach (DB::all("SELECT i.id, i.invoice_no, i.account_id, i.balance, t.total - COALESCE((SELECT SUM(pa.amount + pa.discount + pa.withholding) FROM payment_applications pa
                                     JOIN transactions pt ON pt.id = pa.payment_txn_id WHERE pa.target_txn_id = t.id AND pt.is_voided = 0), 0) AS ledger_open
                                FROM isp_invoices i JOIN isp_postings ps ON ps.event_key = CONCAT('INV-', i.id) AND ps.status = 'posted' JOIN transactions t ON t.id = ps.txn_id
                               WHERE i.status IN ('posted', 'partially_paid', 'overdue', 'disputed')") as $x) {
                if (abs((float)$x['ledger_open'] - (float)$x['balance']) > 0.009) {
                    $issues[] = ['item' => 'Invoice ' . $x['invoice_no'], 'date' => '', 'isp' => (float)$x['balance'], 'gl' => r2((float)$x['ledger_open']), 'acct' => $x['account_id'],
                        'note' => 'Open balance differs between the subscriber ledger and the ledger invoice (payments or credits applied differently)'];
                }
            }
        }
        if ($issues) {
            $rows[] = ['item' => 'Documents to look at (' . number_format(count($issues)) . (count($issues) > 2000 ? ', the first 2,000 listed' : '') . ')', '_class' => 'group-row'];
            $issues = array_slice($issues, 0, 2000);
            $acctNos = [];
            foreach (array_chunk(array_values(array_unique(array_filter(array_map(fn($x) => (int)($x['acct'] ?? 0), $issues)))), 1000) as $chunk) {
                [$in, $ip] = DB::in('ra', $chunk);
                $acctNos += DB::pairs("SELECT id, account_no FROM isp_accounts WHERE id IN $in", $ip);
            }
            foreach ($issues as $x) {
                $links = ['item' => !empty($x['txn']) && !empty($x['txn_type']) ? Report::txnLink(['txn_type' => $x['txn_type'], 'txn_id' => $x['txn']]) : null, 'acctno' => isprpt_link('account', $x['acct'] ?? null)];
                $rows[] = ['item' => $x['item'], 'date' => $x['date'], 'acctno' => (string)($acctNos[(int)($x['acct'] ?? 0)] ?? ''),
                    'isp' => $x['isp'], 'gl' => $x['gl'], 'diff' => r2($x['isp'] - $x['gl']), 'note' => $x['note'], '_class' => 'indent-1', '_links' => $links];
            }
        } else {
            $rows[] = ['item' => abs($net - $gl) < 0.01 ? 'No differences: every ISP posting is in the ledger with its amount.' : 'No single document explains the difference: look for postings dated after ' . fdate($d) . ' or edited ledger documents.', '_class' => 'indent-1'];
        }
        return ['grouped' => true, 'columns' => [['key' => 'item', 'label' => 'Item / document'], ['key' => 'date', 'label' => 'Ledger date', 'type' => 'date'], ['key' => 'acctno', 'label' => 'Account no.'],
            ['key' => 'isp', 'label' => 'Subscriber ledger', 'type' => 'money'], ['key' => 'gl', 'label' => 'General ledger', 'type' => 'money'], ['key' => 'diff', 'label' => 'Difference', 'type' => 'money'],
            ['key' => 'note', 'label' => 'What it is']],
            'rows' => $rows,
            'notes' => abs($net - $gl) < 0.01 ? 'The subscriber ledger agrees with the general ledger as of ' . fdate($d) . '.'
                : '<span class="text-danger">The subscriber ledger and the general ledger differ by ' . money($net - $gl) . ' as of ' . fdate($d) . '.</span> The documents behind the difference are listed.'];
    },
]);

// ====================================================================== disconnection reports

Report::define('isp_due_disconnect', [
    'filters' => ['asof'],
    'extra_filters' => isprpt_filters(['branch', 'area', 'plan']) + ['show' => ['Show', fn() => ['eligible' => 'Eligible now', 'all' => 'Every overdue active account'], null]],
    'run' => function (array $f): array {
        isprpt_ready();
        $rule = IspDisconnect::rule('overdue');
        if (!$rule) {
            return ['columns' => [['key' => 'acct', 'label' => 'Account no.']], 'rows' => [],
                'notes' => '<span class="text-danger">The disconnection rule for overdue payment is switched off (ISP Setup &gt; Billing &amp; Disconnection Policies): no account is due for disconnection.</span>'];
        }
        $cutoff = IspPlans::addDays($f['asof'], -max(0, (int)$rule['grace_days']));
        $p = ['c' => $cutoff];
        $w = isprpt_where($f, $p);
        $accts = DB::all("SELECT a.*, c.mobile, pl.name AS plan_name FROM isp_accounts a JOIN isp_customers c ON c.id = a.isp_customer_id LEFT JOIN isp_plans pl ON pl.id = a.plan_id
                           WHERE a.service_status = 'active' AND EXISTS (SELECT 1 FROM isp_invoices i WHERE i.account_id = a.id AND i.status IN ('posted', 'partially_paid', 'overdue', 'disputed')
                                 AND i.balance > 0 AND i.due_date < :c)$w ORDER BY a.account_no", $p);
        $queue = [];
        if ($accts) {
            [$in, $ip] = DB::in('q', array_column($accts, 'id'));
            foreach (DB::all("SELECT * FROM isp_disc_queue WHERE account_id IN $in AND status IN ('candidate', 'approved', 'notified', 'executing', 'failed') ORDER BY id", $ip) as $q) {
                $queue[(int)$q['account_id']] = $q;
            }
        }
        $all = ($f['show'] ?? '') === 'all';
        $out = [];
        $tot = ['acct' => '', 'amount' => 0.0, 'disputed' => 0.0];
        $count = ['eligible' => 0, 'protected' => 0, 'below' => 0];
        foreach ($accts as $a) {
            [$ok, $why, $ar] = IspDisconnect::eligible($a, $rule, $f['asof']);
            $kind = $ok ? 'eligible' : (!empty($ar['protected']) ? 'protected' : 'below');
            $count[$kind]++;
            if (!$ok && !$all) {
                continue;
            }
            $q = $queue[(int)$a['id']] ?? null;
            $out[] = ['acct' => $a['account_no'], 'name' => $a['account_name'], 'plan' => $a['plan_name'], 'mobile' => isprpt_mask($a['mobile']),
                'amount' => r2((float)($ar['amount'] ?? 0)), 'n' => (int)($ar['invoices'] ?? 0), 'oldest' => $ar['oldest_due'] ?? '',
                'days' => !empty($ar['oldest_due']) ? isprpt_days($ar['oldest_due'], $f['asof']) : '', 'disputed' => (float)($ar['disputed'] ?? 0) ?: '',
                'result' => $ok ? 'Eligible' : ucfirst($why), 'queue' => $q ? (IspDisconnect::STATUSES[$q['status']] ?? $q['status']) . ' ' . $q['queue_no'] : 'Not queued yet',
                'notice' => $q['final_notice_at'] ?? '', 'after' => $q['execute_after'] ?? '',
                '_links' => ['acct' => isprpt_link('account', $a['id']), 'queue' => $q ? isprpt_link('disc', $q['id']) : null]];
            $tot['amount'] += (float)($ar['amount'] ?? 0);
            $tot['disputed'] += (float)($ar['disputed'] ?? 0);
        }
        $tot = array_map(fn($v) => is_float($v) ? r2($v) : $v, $tot);
        $tot['acct'] = 'TOTAL (' . count($out) . ')';
        return ['columns' => [['key' => 'acct', 'label' => 'Account no.'], ['key' => 'name', 'label' => 'Subscriber'], ['key' => 'plan', 'label' => 'Plan', 'optional' => true], ['key' => 'mobile', 'label' => 'Mobile'],
            ['key' => 'amount', 'label' => 'Qualifying arrears', 'type' => 'money'], ['key' => 'n', 'label' => 'Invoices', 'type' => 'int'], ['key' => 'oldest', 'label' => 'Oldest due', 'type' => 'date'],
            ['key' => 'days', 'label' => 'Days past due', 'type' => 'int'], ['key' => 'disputed', 'label' => 'Disputed (protected)', 'type' => 'money'], ['key' => 'result', 'label' => 'Rule check'],
            ['key' => 'queue', 'label' => 'Disconnection queue'], ['key' => 'notice', 'label' => 'Final notice', 'type' => 'datetime'], ['key' => 'after', 'label' => 'May run after', 'type' => 'datetime']],
            'rows' => $out, 'totals' => $tot,
            'notes' => h('Rule "' . $rule['name'] . '": ' . (int)$rule['grace_days'] . ' days of grace after the due date, at least ' . money($rule['min_overdue_amount']) . ' over ' . max(1, (int)$rule['min_overdue_invoices'])
                . ' invoice(s); disputes' . ((int)$rule['protect_disputes'] ? '' : ' do not') . ' protect, payment arrangements and exemptions protect. Active accounts with arrears past the grace: '
                . $count['eligible'] . ' eligible, ' . $count['protected'] . ' protected, ' . $count['below'] . ' below the rule. Balances are today\'s; advance credits are deducted.')];
    },
]);

Report::define('isp_disconnections', [
    'filters' => ['range'],
    'extra_filters' => isprpt_filters(['branch', 'area', 'plan']) + ['reason' => ['Reason', fn() => IspDisconnect::REASON_TYPES]],
    'run' => function (array $f): array {
        isprpt_ready();
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $w = isprpt_where($f, $p);
        if (($f['reason'] ?? '') !== '') {
            $w .= ' AND q.reason_type = :rt';
            $p['rt'] = $f['reason'];
        }
        $rows = DB::all("SELECT q.*, a.account_no, a.account_name, a.service_status, o.op_no, o.simulated, o.state AS op_state, ru.name AS rule_name
                           FROM isp_disc_queue q JOIN isp_accounts a ON a.id = q.account_id LEFT JOIN isp_net_ops o ON o.id = q.net_op_id LEFT JOIN isp_disc_rules ru ON ru.id = q.rule_id
                          WHERE q.status = 'disconnected' AND q.executed_at BETWEEN :f AND :t$w ORDER BY q.executed_at, q.id LIMIT " . (ISPRPT_MAX_ROWS + 1), $p);
        $capNote = isprpt_capped($rows, false);
        $recon = [];
        if ($rows) {
            [$in, $ip] = DB::in('r', array_column($rows, 'account_id'));
            foreach (DB::all("SELECT account_id, restored_at FROM isp_reconnections WHERE status = 'restored' AND account_id IN $in ORDER BY restored_at", $ip) as $r) {
                $recon[(int)$r['account_id']][] = $r['restored_at'];
            }
        }
        $users = isprpt_user_names();
        $out = [];
        $sim = 0;
        $tot = 0.0;
        foreach ($rows as $q) {
            $back = '';
            foreach ($recon[(int)$q['account_id']] ?? [] as $r) {
                if ($r >= $q['executed_at']) {
                    $back = $r;
                    break;
                }
            }
            $sim += (int)$q['simulated'];
            $out[] = ['when' => $q['executed_at'], 'no' => $q['queue_no'], 'acct' => $q['account_no'], 'name' => $q['account_name'], 'reason' => IspDisconnect::REASON_TYPES[$q['reason_type']] ?? $q['reason_type'],
                'detail' => $q['reason'], 'amount' => (float)$q['eligible_amount'] ?: '', 'oldest' => $q['oldest_due'],
                'by' => str_starts_with((string)$q['initiated_by'], 'user:') ? ($users[(int)substr((string)$q['initiated_by'], 5)] ?? '') : ucfirst((string)$q['initiated_by']),
                'approved' => $users[$q['approved_by']] ?? '',
                'op' => $q['op_no'] . ((int)$q['simulated'] ? ' (simulated)' : ''), 'back' => $back, 'now' => IspAccounts::SERVICE_STATUSES[$q['service_status']] ?? $q['service_status'],
                '_links' => ['no' => isprpt_link('disc', $q['id']), 'acct' => isprpt_link('account', $q['account_id']), 'op' => isprpt_link('op', $q['net_op_id'])]];
            $tot += (float)$q['eligible_amount'];
        }
        $back = count(array_filter($out, fn($r) => $r['back'] !== ''));
        return ['columns' => [['key' => 'when', 'label' => 'Disconnected', 'type' => 'datetime'], ['key' => 'no', 'label' => 'Queue no.'], ['key' => 'acct', 'label' => 'Account no.'], ['key' => 'name', 'label' => 'Subscriber'],
            ['key' => 'reason', 'label' => 'Reason'], ['key' => 'detail', 'label' => 'Details', 'optional' => true], ['key' => 'amount', 'label' => 'Arrears at disconnection', 'type' => 'money'],
            ['key' => 'oldest', 'label' => 'Oldest due', 'type' => 'date', 'optional' => true], ['key' => 'by', 'label' => 'Queued by'], ['key' => 'approved', 'label' => 'Approved by'],
            ['key' => 'op', 'label' => 'Network operation'],
            ['key' => 'back', 'label' => 'Reconnected', 'type' => 'datetime'], ['key' => 'now', 'label' => 'Service now']],
            'rows' => $out, 'totals' => ['when' => 'TOTAL (' . count($out) . ')', 'amount' => r2($tot)],
            'notes' => 'Disconnections confirmed by the network in the period. ' . $back . ' of them were reconnected since.'
                . ($sim ? ' <strong>' . $sim . ' were run on the integration simulator: no real device was changed.</strong>' : '') . $capNote];
    },
]);

Report::define('isp_failed_ops', [
    'filters' => ['range'],
    'default_dates' => 'this_month',
    'extra_filters' => ['branch' => ['Branch', fn() => isprpt_branches()], 'op' => ['Operation', fn() => IspNetwork::OP_TYPES],
        'state' => ['Show', fn() => ['problem' => 'Failed or waiting for a technician', 'failed' => 'Failed', 'manual' => 'Manual work order', 'retried' => 'Needed more than one attempt'], null],
        'device' => ['Device', fn() => IspSetup::ready() ? DB::pairs('SELECT id, name FROM isp_devices ORDER BY name') : []]],
    'run' => function (array $f): array {
        isprpt_ready();
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $w = isprpt_where($f, $p);
        $state = $f['state'] !== '' ? $f['state'] : 'problem';
        $w .= match ($state) {
            'failed' => " AND o.state = 'failed'",
            'manual' => " AND o.state = 'manual'",
            'retried' => ' AND o.attempts > 1',
            default => " AND o.state IN ('failed', 'manual')",
        };
        if (($f['op'] ?? '') !== '') {
            $w .= ' AND o.op_type = :op';
            $p['op'] = $f['op'];
        }
        if (($f['device'] ?? '') !== '') {
            $w .= ' AND o.device_id = :dv';
            $p['dv'] = (int)$f['device'];
        }
        $rows = DB::all("SELECT o.*, a.account_no, a.account_name, d.name AS device_name, d.adapter, w.wo_no
                           FROM isp_net_ops o JOIN isp_accounts a ON a.id = o.account_id LEFT JOIN isp_devices d ON d.id = o.device_id LEFT JOIN isp_work_orders w ON w.id = o.work_order_id
                          WHERE o.created_at BETWEEN :f AND :t$w ORDER BY o.id DESC LIMIT 3000", $p);
        $out = [];
        $byType = [];
        foreach ($rows as $o) {
            $byType[$o['op_type']] = ($byType[$o['op_type']] ?? 0) + 1;
            $out[] = ['no' => $o['op_no'], 'when' => $o['created_at'], 'acct' => $o['account_no'], 'name' => $o['account_name'], 'op' => IspNetwork::OP_TYPES[$o['op_type']] ?? $o['op_type'],
                'reason' => $o['reason'], 'device' => trim((string)$o['device_name'] . ($o['adapter'] ? ' (' . (IspNetwork::ADAPTER_LABELS[$o['adapter']] ?? $o['adapter']) . ')' : '')),
                'state' => IspNetwork::STATES[$o['state']] ?? $o['state'], 'attempts' => (int)$o['attempts'] . ' / ' . (int)$o['max_attempts'], 'next' => in_array($o['state'], ['queued', 'failed'], true) ? $o['next_try_at'] : '',
                'error' => mb_substr((string)$o['error'], 0, 200), 'wo' => $o['wo_no'], 'sim' => (int)$o['simulated'] ? 'Simulated' : '',
                '_links' => ['no' => isprpt_link('op', $o['id']), 'acct' => isprpt_link('account', $o['account_id']), 'wo' => isprpt_link('wo', $o['work_order_id'])]];
        }
        return ['columns' => [['key' => 'no', 'label' => 'Operation no.'], ['key' => 'when', 'label' => 'Requested', 'type' => 'datetime'], ['key' => 'acct', 'label' => 'Account no.'], ['key' => 'name', 'label' => 'Subscriber'],
            ['key' => 'op', 'label' => 'Operation'], ['key' => 'reason', 'label' => 'Why', 'optional' => true], ['key' => 'device', 'label' => 'Device'], ['key' => 'state', 'label' => 'State'],
            ['key' => 'attempts', 'label' => 'Attempts'], ['key' => 'next', 'label' => 'Next try', 'type' => 'datetime', 'optional' => true], ['key' => 'error', 'label' => 'Last error'],
            ['key' => 'wo', 'label' => 'Work order'], ['key' => 'sim', 'label' => 'Simulator']],
            'rows' => $out, 'totals' => ['no' => 'TOTAL (' . count($out) . ')'],
            'notes' => 'Network operations requested in the period that failed, were turned into a manual work order, or needed retries. By operation: '
                . h(implode(', ', array_map(fn($k, $v) => (IspNetwork::OP_TYPES[$k] ?? $k) . ' ' . $v, array_keys($byType), $byType)) ?: 'none') . '. A failed disconnection never leaves the account marked disconnected.'];
    },
]);

Report::define('isp_disc_reasons', [
    'filters' => ['range'],
    'default_dates' => 'this_year',
    'extra_filters' => isprpt_filters(['branch', 'area', 'plan']),
    'run' => function (array $f): array {
        isprpt_ready();
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $w = isprpt_where($f, $p);
        $rows = DB::all("SELECT q.reason_type, COUNT(*) AS queued, SUM(q.status = 'disconnected') AS done, SUM(q.status = 'skipped') AS skipped, SUM(q.status = 'cancelled') AS cancelled,
                                SUM(q.status = 'failed') AS failed, SUM(q.status IN ('candidate', 'approved', 'notified', 'executing')) AS waiting,
                                SUM(CASE WHEN q.status = 'disconnected' THEN q.eligible_amount ELSE 0 END) AS amt
                           FROM isp_disc_queue q JOIN isp_accounts a ON a.id = q.account_id WHERE q.created_at BETWEEN :f AND :t$w GROUP BY q.reason_type", $p);
        // reconnections after the disconnections of the period, and how long the accounts stayed off
        $p2 = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $w2 = isprpt_where($f, $p2);
        $back = [];
        foreach (DB::all("SELECT q.reason_type, q.executed_at, (SELECT MIN(r.restored_at) FROM isp_reconnections r WHERE r.account_id = q.account_id AND r.status = 'restored' AND r.restored_at >= q.executed_at) AS restored
                            FROM isp_disc_queue q JOIN isp_accounts a ON a.id = q.account_id WHERE q.status = 'disconnected' AND q.created_at BETWEEN :f AND :t$w2", $p2) as $x) {
            if ($x['restored']) {
                $back[$x['reason_type']]['n'] = ($back[$x['reason_type']]['n'] ?? 0) + 1;
                $back[$x['reason_type']]['h'] = ($back[$x['reason_type']]['h'] ?? 0) + (strtotime($x['restored']) - strtotime($x['executed_at'])) / 3600;
            }
        }
        $total = array_sum(array_column($rows, 'done'));
        $out = [];
        $tot = ['reason' => 'TOTAL', 'queued' => 0, 'done' => 0, 'skipped' => 0, 'cancelled' => 0, 'failed' => 0, 'waiting' => 0, 'amt' => 0.0, 'back' => 0];
        foreach ($rows as $r) {
            $b = $back[$r['reason_type']] ?? ['n' => 0, 'h' => 0];
            $out[] = ['reason' => IspDisconnect::REASON_TYPES[$r['reason_type']] ?? $r['reason_type'], 'queued' => (int)$r['queued'], 'done' => (int)$r['done'], 'share' => isprpt_pct((float)$r['done'], (float)$total),
                'skipped' => (int)$r['skipped'], 'cancelled' => (int)$r['cancelled'], 'failed' => (int)$r['failed'], 'waiting' => (int)$r['waiting'], 'amt' => r2((float)$r['amt']),
                'back' => (int)$b['n'], 'back_pct' => isprpt_pct((float)$b['n'], (float)$r['done']), 'hours' => $b['n'] ? round($b['h'] / $b['n'], 1) : '',
                '_links' => ['reason' => Report::link('rpt.isp_disconnections', ['dates' => 'custom', 'from' => $f['from'], 'to' => $f['to'], 'reason' => $r['reason_type']])]];
            foreach (['queued', 'done', 'skipped', 'cancelled', 'failed', 'waiting'] as $k) {
                $tot[$k] += (int)$r[$k];
            }
            $tot['amt'] += (float)$r['amt'];
            $tot['back'] += (int)$b['n'];
        }
        $tot['amt'] = r2($tot['amt']);
        $tot['share'] = $total ? '100' : '';
        $tot['back_pct'] = isprpt_pct((float)$tot['back'], (float)$tot['done']);
        return ['columns' => [['key' => 'reason', 'label' => 'Reason'], ['key' => 'queued', 'label' => 'Queued', 'type' => 'int'], ['key' => 'done', 'label' => 'Disconnected', 'type' => 'int'],
            ['key' => 'share', 'label' => 'Share', 'type' => 'pct'], ['key' => 'skipped', 'label' => 'Skipped (paid / protected)', 'type' => 'int'], ['key' => 'cancelled', 'label' => 'Cancelled', 'type' => 'int'],
            ['key' => 'failed', 'label' => 'Failed', 'type' => 'int'], ['key' => 'waiting', 'label' => 'Still waiting', 'type' => 'int'], ['key' => 'amt', 'label' => 'Arrears disconnected', 'type' => 'money'],
            ['key' => 'back', 'label' => 'Reconnected since', 'type' => 'int'], ['key' => 'back_pct', 'label' => 'Recovery', 'type' => 'pct'], ['key' => 'hours', 'label' => 'Average hours off', 'type' => 'qty']],
            'rows' => $out, 'totals' => $tot,
            'notes' => 'Disconnection queue items created in the period, by reason. Skipped items are accounts that paid, came under protection or stopped qualifying before the disconnection ran.'];
    },
]);

Report::define('isp_recon_history', [
    'filters' => ['range'],
    'extra_filters' => isprpt_filters(['branch', 'area', 'plan']) + ['rtype' => ['Kind', fn() => IspReconnect::TYPES], 'rstatus' => ['Status', fn() => IspReconnect::STATUSES]],
    'run' => function (array $f): array {
        isprpt_ready();
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $w = isprpt_where($f, $p);
        foreach (['rtype' => 'r.recon_type', 'rstatus' => 'r.status'] as $k => $col) {
            if (($f[$k] ?? '') !== '') {
                $w .= " AND $col = :$k";
                $p[$k] = $f[$k];
            }
        }
        $rows = DB::all("SELECT r.*, a.account_no, a.account_name, a.disconnected_at, y.receipt_no, o.op_no, o.simulated,
                                (SELECT MAX(q.executed_at) FROM isp_disc_queue q WHERE q.account_id = r.account_id AND q.status = 'disconnected' AND q.executed_at <= r.created_at) AS off_at
                           FROM isp_reconnections r JOIN isp_accounts a ON a.id = r.account_id LEFT JOIN isp_payments y ON y.id = r.payment_id LEFT JOIN isp_net_ops o ON o.id = r.net_op_id
                          WHERE r.created_at BETWEEN :f AND :t$w ORDER BY r.created_at, r.id LIMIT " . (ISPRPT_MAX_ROWS + 1), $p);
        $capNote = isprpt_capped($rows, false);
        $users = isprpt_user_names();
        $out = [];
        $hours = [];
        $fees = 0.0;
        foreach ($rows as $r) {
            $h = $r['off_at'] && $r['restored_at'] ? round((strtotime($r['restored_at']) - strtotime($r['off_at'])) / 3600, 1) : '';
            if ($h !== '') {
                $hours[] = $h;
            }
            $mins = $r['restored_at'] ? round((strtotime($r['restored_at']) - strtotime($r['created_at'])) / 60) : '';
            $out[] = ['when' => $r['created_at'], 'no' => $r['recon_no'], 'acct' => $r['account_no'], 'name' => $r['account_name'], 'type' => IspReconnect::TYPES[$r['recon_type']] ?? $r['recon_type'],
                'status' => IspReconnect::STATUSES[$r['status']] ?? $r['status'], 'receipt' => $r['receipt_no'], 'fee' => (float)$r['fee_amount'] ?: '', 'reason' => $r['reason'],
                'approved' => $users[$r['approved_by']] ?? '', 'restored' => $r['restored_at'], 'minutes' => $mins, 'off' => $h, 'expires' => $r['expires_at'],
                'op' => $r['op_no'] ? $r['op_no'] . ((int)$r['simulated'] ? ' (simulated)' : '') : '',
                '_links' => ['no' => isprpt_link('recon', $r['id']), 'acct' => isprpt_link('account', $r['account_id']), 'receipt' => isprpt_link('payment', $r['payment_id']), 'op' => isprpt_link('op', $r['net_op_id'])]];
            $fees += (float)$r['fee_amount'];
        }
        $restored = count(array_filter($rows, fn($r) => $r['status'] === 'restored'));
        return ['columns' => [['key' => 'when', 'label' => 'Requested', 'type' => 'datetime'], ['key' => 'no', 'label' => 'Reconnection no.'], ['key' => 'acct', 'label' => 'Account no.'],
            ['key' => 'name', 'label' => 'Subscriber'], ['key' => 'type', 'label' => 'Kind'], ['key' => 'status', 'label' => 'Status'], ['key' => 'receipt', 'label' => 'Payment'],
            ['key' => 'fee', 'label' => 'Reconnection fee', 'type' => 'money'], ['key' => 'reason', 'label' => 'Reason', 'optional' => true], ['key' => 'approved', 'label' => 'Approved by'],
            ['key' => 'restored', 'label' => 'Restored', 'type' => 'datetime'], ['key' => 'minutes', 'label' => 'Minutes to restore', 'type' => 'int'], ['key' => 'off', 'label' => 'Hours disconnected', 'type' => 'qty'],
            ['key' => 'expires', 'label' => 'Temporary until', 'type' => 'datetime', 'optional' => true], ['key' => 'op', 'label' => 'Network operation']],
            'rows' => $out, 'totals' => ['when' => 'TOTAL (' . count($out) . ', ' . $restored . ' restored)', 'fee' => r2($fees)],
            'notes' => 'Reconnections requested in the period: automatic ones after a qualifying payment, and the manual, temporary, arrangement and emergency ones with who approved them.'
                . ($hours ? ' Average time disconnected before the restoration: ' . number_format(array_sum($hours) / count($hours), 1) . ' hours.' : '') . $capNote];
    },
]);

Report::define('isp_disc_outstanding', [
    'extra_filters' => isprpt_filters(['branch', 'area', 'plan']) + ['age' => ['Disconnected for', fn() => ['0' => 'Any time', '30' => '30 days or more', '60' => '60 days or more', '90' => '90 days or more'], null]],
    'run' => function (array $f): array {
        isprpt_ready();
        $p = [];
        $w = isprpt_where($f, $p);
        $rows = DB::all("SELECT a.*, c.mobile, pl.name AS plan_name FROM isp_accounts a JOIN isp_customers c ON c.id = a.isp_customer_id LEFT JOIN isp_plans pl ON pl.id = a.plan_id
                          WHERE a.service_status = 'disconnected'$w ORDER BY a.disconnected_at, a.account_no LIMIT " . (ISPRPT_MAX_ROWS + 1), $p);
        $capNote = isprpt_capped($rows, false);
        $bal = DB::pairs("SELECT account_id, SUM(balance) FROM isp_invoices WHERE status IN ('posted', 'partially_paid', 'overdue', 'disputed') GROUP BY account_id");
        $credit = isprpt_unapplied_at(today());
        $equip = DB::pairs("SELECT account_id, COUNT(*) FROM isp_equipment WHERE ownership = 'company' AND status IN ('installed', 'for_recovery') AND account_id IS NOT NULL GROUP BY account_id");
        $rec = DB::pairs("SELECT account_id, MAX(wo_no) FROM isp_work_orders WHERE wo_type = 'recovery' AND status NOT IN ('cancelled') GROUP BY account_id");
        $last = [];
        foreach (DB::all("SELECT account_id, MAX(pay_date) AS d FROM isp_payments WHERE status = 'posted' GROUP BY account_id") as $x) {
            $last[$x['account_id']] = $x['d'];
        }
        $minAge = (int)($f['age'] ?? 0);
        $out = [];
        $tot = ['acct' => '', 'balance' => 0.0, 'equip' => 0];
        $bands = ['0 - 30' => 0, '31 - 60' => 0, '61 - 90' => 0, 'Over 90' => 0];
        foreach ($rows as $a) {
            $days = $a['disconnected_at'] ? isprpt_days((string)$a['disconnected_at'], today()) : 0;
            if ($days < $minAge) {
                continue;
            }
            $bands[$days <= 30 ? '0 - 30' : ($days <= 60 ? '31 - 60' : ($days <= 90 ? '61 - 90' : 'Over 90'))]++;
            $b = r2((float)($bal[$a['id']] ?? 0) - (float)($credit[$a['id']] ?? 0));
            $out[] = ['acct' => $a['account_no'], 'name' => $a['account_name'], 'plan' => $a['plan_name'], 'mobile' => isprpt_mask($a['mobile']), 'address' => isprpt_mask($a['install_address']),
                'since' => $a['disconnected_at'], 'days' => $days, 'reason' => $a['disconnect_reason'], 'balance' => $b, 'last' => $last[$a['id']] ?? '',
                'network' => IspAccounts::NETWORK_STATES[$a['network_state']] ?? $a['network_state'], 'equip' => (int)($equip[$a['id']] ?? 0) ?: '', 'recovery' => $rec[$a['id']] ?? '',
                '_links' => ['acct' => isprpt_link('account', $a['id'])]];
            $tot['balance'] += $b;
            $tot['equip'] += (int)($equip[$a['id']] ?? 0);
        }
        $tot['balance'] = r2($tot['balance']);
        $tot['acct'] = 'TOTAL (' . count($out) . ' accounts)';
        return ['columns' => [['key' => 'acct', 'label' => 'Account no.'], ['key' => 'name', 'label' => 'Subscriber'], ['key' => 'plan', 'label' => 'Plan', 'optional' => true], ['key' => 'mobile', 'label' => 'Mobile'],
            ['key' => 'address', 'label' => 'Address', 'optional' => true], ['key' => 'since', 'label' => 'Disconnected', 'type' => 'datetime'], ['key' => 'days', 'label' => 'Days', 'type' => 'int'],
            ['key' => 'reason', 'label' => 'Reason'], ['key' => 'balance', 'label' => 'Balance (net of credits)', 'type' => 'money'], ['key' => 'last', 'label' => 'Last payment', 'type' => 'date'],
            ['key' => 'network', 'label' => 'Network'], ['key' => 'equip', 'label' => 'Company equipment on site', 'type' => 'int'], ['key' => 'recovery', 'label' => 'Recovery work order']],
            'rows' => $out, 'totals' => $tot,
            'notes' => 'Accounts disconnected today and still owing, longest first. By days disconnected: ' . h(implode(', ', array_map(fn($k, $v) => $k . ': ' . $v, array_keys($bands), $bands)))
                . '. Accounts disconnected for long are candidates for termination and equipment recovery (Disconnected Accounts & Recovery).' . $capNote];
    },
]);

// ====================================================================== technical reports

Report::define('isp_outage_history', [
    'filters' => ['range'],
    'default_dates' => 'this_year',
    'extra_filters' => ['area' => ['Area', fn() => isprpt_areas()], 'ostatus' => ['Status', fn() => IspOutages::STATUSES], 'planned' => ['Kind', fn() => ['0' => 'Unplanned', '1' => 'Planned maintenance']]],
    'run' => function (array $f): array {
        isprpt_ready();
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $w = '';
        if (($f['area'] ?? '') !== '') {
            [$in, $ip] = DB::in('oa', IspOutages::areaTree((int)$f['area']));
            $w .= " AND o.area_id IN $in";
            $p += $ip;
        }
        if (($f['ostatus'] ?? '') !== '') {
            $w .= ' AND o.status = :os';
            $p['os'] = $f['ostatus'];
        }
        if (($f['planned'] ?? '') !== '') {
            $w .= ' AND o.is_planned = :pl';
            $p['pl'] = (int)$f['planned'];
        }
        if (($ub = IspSetup::userBranch()) !== null) {
            $w .= ' AND (ar.branch_id = :oub OR ar.branch_id IS NULL)';
            $p['oub'] = $ub;
        }
        $rows = DB::all("SELECT o.*, n.code AS node_code, n.name AS node_name, ar.name AS area_name,
                                (SELECT COUNT(*) FROM isp_outage_accounts x WHERE x.outage_id = o.id) AS affected, (SELECT COALESCE(SUM(x.credit_amount), 0) FROM isp_outage_accounts x WHERE x.outage_id = o.id) AS credits,
                                (SELECT COUNT(*) FROM isp_tickets t WHERE t.outage_id = o.id) AS tickets
                           FROM isp_outages o LEFT JOIN isp_net_nodes n ON n.id = o.node_id LEFT JOIN isp_areas ar ON ar.id = o.area_id
                          WHERE o.started_at BETWEEN :f AND :t$w ORDER BY o.started_at", $p);
        $out = [];
        $tot = ['no' => '', 'affected' => 0, 'credits' => 0.0, 'tickets' => 0];
        $durations = [];
        $breached = 0;
        foreach ($rows as $o) {
            $end = $o['restored_at'] ?: (in_array($o['status'], ['closed', 'false_alarm'], true) ? $o['closed_at'] : null);
            $hours = $end ? round((strtotime($end) - strtotime($o['started_at'])) / 3600, 1) : round((time() - strtotime($o['started_at'])) / 3600, 1);
            $over = (int)$o['sla_hours'] > 0 && $hours > (int)$o['sla_hours'] && $o['status'] !== 'false_alarm';
            $breached += $over ? 1 : 0;
            if ($end && $o['status'] !== 'false_alarm') {
                $durations[] = $hours;
            }
            $out[] = ['no' => $o['outage_no'], 'title' => $o['title'], 'scope' => IspOutages::SCOPES[$o['scope']] ?? $o['scope'],
                'where' => trim(($o['node_code'] ? $o['node_code'] . ' ' . $o['node_name'] : '') . ($o['area_name'] ? ' ' . $o['area_name'] : '')), 'kind' => (int)$o['is_planned'] ? 'Planned' : 'Unplanned',
                'started' => $o['started_at'], 'restored' => $o['restored_at'], 'hours' => $hours . ($end ? '' : ' (ongoing)'), 'sla' => (int)$o['sla_hours'] ?: '', 'breach' => $over ? 'Over SLA' : '',
                'affected' => (int)$o['affected'], 'tickets' => (int)$o['tickets'], 'credits' => r2((float)$o['credits']) ?: '', 'credit_status' => ucfirst((string)$o['credits_status']),
                'cause' => $o['root_cause'], 'status' => IspOutages::STATUSES[$o['status']] ?? $o['status'], '_links' => ['no' => isprpt_link('outage', $o['id'])]];
            $tot['affected'] += (int)$o['affected'];
            $tot['credits'] += (float)$o['credits'];
            $tot['tickets'] += (int)$o['tickets'];
        }
        $tot['credits'] = r2($tot['credits']);
        $tot['no'] = 'TOTAL (' . count($out) . ')';
        return ['columns' => [['key' => 'no', 'label' => 'Outage no.'], ['key' => 'title', 'label' => 'Title'], ['key' => 'scope', 'label' => 'Scope', 'optional' => true], ['key' => 'where', 'label' => 'Node / area'],
            ['key' => 'kind', 'label' => 'Kind'], ['key' => 'started', 'label' => 'Started', 'type' => 'datetime'], ['key' => 'restored', 'label' => 'Restored', 'type' => 'datetime'], ['key' => 'hours', 'label' => 'Hours'],
            ['key' => 'sla', 'label' => 'SLA hours', 'type' => 'int'], ['key' => 'breach', 'label' => 'SLA'], ['key' => 'affected', 'label' => 'Accounts affected', 'type' => 'int'],
            ['key' => 'tickets', 'label' => 'Tickets', 'type' => 'int'], ['key' => 'credits', 'label' => 'Service credits', 'type' => 'money'], ['key' => 'credit_status', 'label' => 'Credits', 'optional' => true],
            ['key' => 'cause', 'label' => 'Root cause'], ['key' => 'status', 'label' => 'Status']],
            'rows' => $out, 'totals' => $tot,
            'notes' => 'Network outages that started in the period. ' . ($durations ? 'Average time to restore: ' . number_format(array_sum($durations) / count($durations), 1) . ' hours. ' : '')
                . $breached . ' outage(s) went beyond their SLA. Service credits are those computed for the affected accounts under each outage\'s credit policy.'];
    },
]);

Report::define('isp_capacity', [
    'extra_filters' => ['ntype' => ['Node type', fn() => IspInfra::NODE_TYPES], 'area' => ['Area', fn() => isprpt_areas()], 'branch' => ['Branch', fn() => isprpt_branches()],
        'band' => ['Utilization', fn() => ['80' => '80% or more (near full)', '100' => 'Full', 'low' => 'Below 30%'], 'Any']],
    'run' => function (array $f): array {
        isprpt_ready();
        $p = [];
        $w = '';
        if (($f['ntype'] ?? '') !== '') {
            $w .= ' AND n.node_type = :nt';
            $p['nt'] = $f['ntype'];
        }
        if (($f['area'] ?? '') !== '') {
            [$in, $ip] = DB::in('na', IspOutages::areaTree((int)$f['area']));
            $w .= " AND n.area_id IN $in";
            $p += $ip;
        }
        if (($f['branch'] ?? '') !== '') {
            $w .= ' AND n.branch_id = :nb';
            $p['nb'] = (int)$f['branch'];
        }
        if (($ub = IspSetup::userBranch()) !== null) {
            $w .= ' AND (n.branch_id = :nub OR n.branch_id IS NULL)';
            $p['nub'] = $ub;
        }
        $rows = DB::all("SELECT n.id, n.code, n.name, n.node_type, n.status, n.split_ratio, n.area_id, n.port_count, COUNT(pt.id) AS total, SUM(pt.status = 'used') AS used, SUM(pt.status = 'reserved') AS reserved,
                                SUM(pt.status = 'free') AS free, SUM(pt.status = 'faulty') AS faulty, SUM(pt.status = 'used' AND pt.account_id IS NOT NULL) AS subscribers
                           FROM isp_net_nodes n LEFT JOIN isp_net_ports pt ON pt.node_id = n.id WHERE n.port_count > 0 AND n.status <> 'retired'$w
                          GROUP BY n.id, n.code, n.name, n.node_type, n.status, n.split_ratio, n.area_id, n.port_count ORDER BY n.node_type, n.code", $p);
        $areas = isprpt_areas();
        $out = [];
        $byType = [];
        $tot = ['code' => '', 'total' => 0, 'used' => 0, 'reserved' => 0, 'free' => 0, 'faulty' => 0, 'subscribers' => 0];
        foreach ($rows as $r) {
            $total = (int)$r['total'];
            $util = $total ? round(((int)$r['used'] + (int)$r['reserved']) / $total * 100, 1) : 0.0;
            $band = $f['band'] ?? '';
            if (($band === '80' && $util < 80) || ($band === '100' && $util < 100) || ($band === 'low' && $util >= 30)) {
                continue;
            }
            $t = $r['node_type'];
            $byType[$t] ??= ['total' => 0, 'busy' => 0];
            $byType[$t]['total'] += $total;
            $byType[$t]['busy'] += (int)$r['used'] + (int)$r['reserved'];
            $out[] = ['code' => $r['code'], 'name' => $r['name'], 'type' => IspInfra::NODE_TYPES[$t] ?? $t, 'split' => $r['split_ratio'], 'area' => $areas[$r['area_id']] ?? '',
                'status' => IspInfra::NODE_STATUSES[$r['status']] ?? $r['status'], 'total' => $total, 'used' => (int)$r['used'], 'reserved' => (int)$r['reserved'], 'free' => (int)$r['free'],
                'faulty' => (int)$r['faulty'] ?: '', 'subscribers' => (int)$r['subscribers'], 'util' => $util, 'flag' => $util >= 100 ? 'Full' : ($util >= 80 ? 'Near full' : ''),
                '_links' => ['code' => isprpt_link('node', $r['id'])]];
            foreach (['total', 'used', 'reserved', 'free', 'faulty', 'subscribers'] as $k) {
                $tot[$k] += (int)$r[$k];
            }
        }
        $tot['code'] = 'TOTAL (' . count($out) . ' nodes)';
        $tot['util'] = $tot['total'] ? round(($tot['used'] + $tot['reserved']) / $tot['total'] * 100, 1) : '';
        return ['columns' => [['key' => 'code', 'label' => 'Node'], ['key' => 'name', 'label' => 'Name'], ['key' => 'type', 'label' => 'Type'], ['key' => 'split', 'label' => 'Split', 'optional' => true],
            ['key' => 'area', 'label' => 'Area'], ['key' => 'status', 'label' => 'Status'], ['key' => 'total', 'label' => 'Ports', 'type' => 'int'], ['key' => 'used', 'label' => 'In use', 'type' => 'int'],
            ['key' => 'reserved', 'label' => 'Reserved', 'type' => 'int'], ['key' => 'free', 'label' => 'Free', 'type' => 'int'], ['key' => 'faulty', 'label' => 'Faulty', 'type' => 'int'],
            ['key' => 'subscribers', 'label' => 'Subscriber drops', 'type' => 'int'], ['key' => 'util', 'label' => 'Utilization', 'type' => 'pct'], ['key' => 'flag', 'label' => 'Capacity']],
            'rows' => $out, 'totals' => $tot,
            'notes' => 'Ports of every network node in service: in use (a subscriber drop or a downstream node), reserved for an approved application, free or faulty. Utilization = (in use + reserved) / ports. By type: '
                . h(implode(', ', array_map(fn($k, $v) => (IspInfra::NODE_TYPES[$k] ?? $k) . ' ' . ($v['total'] ? round($v['busy'] / $v['total'] * 100, 1) : 0) . '%', array_keys($byType), $byType)) ?: 'none') . '.'];
    },
]);

Report::define('isp_net_commands', [
    'filters' => ['range', 'search'],
    'default_dates' => 'today',
    'filter_labels' => ['search' => 'Account or command'],
    'extra_filters' => ['device' => ['Device', fn() => IspSetup::ready() ? DB::pairs('SELECT id, name FROM isp_devices ORDER BY name') : []],
        'ok' => ['Result', fn() => ['0' => 'Failed commands only', '1' => 'Successful only']], 'sim' => ['Source', fn() => ['0' => 'Real devices', '1' => 'Simulator']]],
    'run' => function (array $f): array {
        isprpt_ready();
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $w = '';
        if (($f['device'] ?? '') !== '') {
            $w .= ' AND l.device_id = :dv';
            $p['dv'] = (int)$f['device'];
        }
        if (($f['ok'] ?? '') !== '') {
            $w .= ' AND l.ok = :ok';
            $p['ok'] = (int)$f['ok'];
        }
        if (($f['sim'] ?? '') !== '') {
            $w .= ' AND l.simulated = :sm';
            $p['sm'] = (int)$f['sim'];
        }
        if (trim((string)$f['q']) !== '') {
            $w .= ' AND (a.account_no LIKE :q1 OR l.command LIKE :q2)';
            $p['q1'] = $p['q2'] = '%' . trim((string)$f['q']) . '%';
        }
        $ub = IspSetup::userBranch();
        if ($ub !== null) {
            $w .= ' AND (a.branch_id = :ub OR a.branch_id IS NULL)';
            $p['ub'] = $ub;
        }
        $rows = DB::all("SELECT l.*, a.account_no, d.name AS device_name, o.op_no, o.op_type FROM isp_net_logs l LEFT JOIN isp_accounts a ON a.id = l.account_id LEFT JOIN isp_devices d ON d.id = l.device_id
                          LEFT JOIN isp_net_ops o ON o.id = l.op_id WHERE l.created_at BETWEEN :f AND :t$w ORDER BY l.id DESC LIMIT 5001", $p);
        $more = count($rows) > 5000;
        $rows = array_slice($rows, 0, 5000);
        $users = isprpt_user_names();
        $out = [];
        $failed = 0;
        foreach ($rows as $l) {
            $failed += (int)$l['ok'] ? 0 : 1;
            $out[] = ['when' => $l['created_at'], 'op' => $l['op_no'], 'optype' => $l['op_type'] ? (IspNetwork::OP_TYPES[$l['op_type']] ?? $l['op_type']) : '', 'acct' => $l['account_no'],
                'device' => $l['device_name'], 'dir' => ucfirst((string)$l['direction']), 'command' => mb_substr((string)$l['command'], 0, 200), 'result' => mb_substr((string)$l['result'], 0, 200),
                'ok' => (int)$l['ok'] ? 'OK' : 'Failed', 'sim' => (int)$l['simulated'] ? 'Simulated' : '', 'user' => $users[$l['user_id']] ?? '',
                '_links' => ['op' => isprpt_link('op', $l['op_id']), 'acct' => isprpt_link('account', $l['account_id'])]];
        }
        return ['columns' => [['key' => 'when', 'label' => 'Time', 'type' => 'datetime'], ['key' => 'op', 'label' => 'Operation'], ['key' => 'optype', 'label' => 'Kind'], ['key' => 'acct', 'label' => 'Account no.'],
            ['key' => 'device', 'label' => 'Device'], ['key' => 'dir', 'label' => 'Direction', 'optional' => true], ['key' => 'command', 'label' => 'Command'], ['key' => 'result', 'label' => 'Device answer'],
            ['key' => 'ok', 'label' => 'Result'], ['key' => 'sim', 'label' => 'Simulator'], ['key' => 'user', 'label' => 'User']],
            'rows' => $out, 'totals' => ['when' => 'TOTAL (' . count($out) . ', ' . $failed . ' failed)'],
            'notes' => 'Every command sent to a network device and its answer, newest first. Passwords and secrets are never written to this log.'
                . ($more ? ' <span class="text-danger">Only the latest 5,000 are listed: narrow the dates or the filters.</span>' : '')];
    },
]);

Report::define('isp_install_progress', [
    'filters' => ['range'],
    'default_dates' => 'this_year',
    'extra_filters' => ['branch' => ['Branch', fn() => isprpt_branches()], 'area' => ['Area', fn() => isprpt_areas()], 'astatus' => ['Application status', fn() => IspApplications::STATUSES],
        'open' => ['Show', fn() => ['open' => 'Still in progress', 'all' => 'Every application'], null]],
    'run' => function (array $f): array {
        isprpt_ready();
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $w = '';
        $ub = IspSetup::userBranch();
        if ($ub !== null) {
            $w .= ' AND (x.branch_id = :ub OR x.branch_id IS NULL)';
            $p['ub'] = $ub;
        }
        if (($f['branch'] ?? '') !== '') {
            $w .= ' AND x.branch_id = :br';
            $p['br'] = (int)$f['branch'];
        }
        if (($f['area'] ?? '') !== '') {
            [$in, $ip] = DB::in('xa', IspOutages::areaTree((int)$f['area']));
            $w .= " AND x.area_id IN $in";
            $p += $ip;
        }
        if (($f['astatus'] ?? '') !== '') {
            $w .= ' AND x.status = :st';
            $p['st'] = $f['astatus'];
        } elseif (($f['open'] ?? '') !== 'all') {
            $w .= " AND x.status NOT IN ('active', 'rejected', 'cancelled')";
        }
        $rows = DB::all("SELECT x.*, pl.name AS plan_name, ag.name AS agent_name, w.wo_no, w.status AS wo_status, w.scheduled_date, w.completed_at, t.name AS tech, a.account_no, a.activation_date
                           FROM isp_applications x LEFT JOIN isp_plans pl ON pl.id = x.plan_id LEFT JOIN isp_agents ag ON ag.id = x.agent_id LEFT JOIN isp_work_orders w ON w.id = x.work_order_id
                           LEFT JOIN isp_technicians t ON t.id = w.technician_id LEFT JOIN isp_accounts a ON a.id = x.account_id
                          WHERE x.created_at BETWEEN :f AND :t$w ORDER BY x.created_at, x.id LIMIT " . (ISPRPT_MAX_ROWS + 1), $p);
        $capNote = isprpt_capped($rows, false);
        $areas = isprpt_areas();
        $out = [];
        $byStatus = [];
        foreach ($rows as $x) {
            $end = $x['activation_date'] ?: today();
            $byStatus[$x['status']] = ($byStatus[$x['status']] ?? 0) + 1;
            $out[] = ['no' => $x['application_no'], 'date' => substr((string)$x['created_at'], 0, 10), 'name' => IspApplications::name($x), 'plan' => $x['plan_name'], 'area' => $areas[$x['area_id']] ?? '',
                'channel' => IspApplications::CHANNELS[$x['channel']] ?? $x['channel'], 'agent' => $x['agent_name'], 'status' => IspApplications::STATUSES[$x['status']] ?? $x['status'],
                'days' => isprpt_days((string)$x['created_at'], $end), 'survey' => $x['survey_date'], 'wo' => $x['wo_no'], 'wo_status' => $x['wo_status'] ? (IspWorkOrders::STATUSES[$x['wo_status']] ?? $x['wo_status']) : '',
                'scheduled' => $x['scheduled_date'], 'tech' => $x['tech'], 'installed' => $x['completed_at'], 'acct' => $x['account_no'], 'activated' => $x['activation_date'],
                '_links' => ['no' => isprpt_link('application', $x['id']), 'wo' => isprpt_link('wo', $x['work_order_id']), 'acct' => isprpt_link('account', $x['account_id'])]];
        }
        $done = array_filter($rows, fn($x) => $x['activation_date']);
        $avg = $done ? array_sum(array_map(fn($x) => isprpt_days((string)$x['created_at'], (string)$x['activation_date']), $done)) / count($done) : null;
        return ['columns' => [['key' => 'no', 'label' => 'Application'], ['key' => 'date', 'label' => 'Received', 'type' => 'date'], ['key' => 'name', 'label' => 'Applicant'], ['key' => 'plan', 'label' => 'Plan'],
            ['key' => 'area', 'label' => 'Area'], ['key' => 'channel', 'label' => 'Channel', 'optional' => true], ['key' => 'agent', 'label' => 'Agent', 'optional' => true], ['key' => 'status', 'label' => 'Status'],
            ['key' => 'days', 'label' => 'Days in progress', 'type' => 'int'], ['key' => 'survey', 'label' => 'Survey', 'type' => 'date', 'optional' => true], ['key' => 'wo', 'label' => 'Installation WO'],
            ['key' => 'wo_status', 'label' => 'WO status'], ['key' => 'scheduled', 'label' => 'Scheduled', 'type' => 'date'], ['key' => 'tech', 'label' => 'Technician'],
            ['key' => 'installed', 'label' => 'Installed', 'type' => 'datetime'], ['key' => 'acct', 'label' => 'Account'], ['key' => 'activated', 'label' => 'Activated', 'type' => 'date']],
            'rows' => $out, 'totals' => ['no' => 'TOTAL (' . count($out) . ')'],
            'notes' => 'Applications received in the period through survey, installation and activation. By status: ' . h(implode(', ', array_map(fn($k, $v) => (IspApplications::STATUSES[$k] ?? $k) . ' ' . $v, array_keys($byStatus), $byStatus)) ?: 'none')
                . '.' . ($avg !== null ? ' Average days from application to activation: ' . number_format($avg, 1) . '.' : '') . $capNote];
    },
]);

Report::define('isp_tech_productivity', [
    'filters' => ['range'],
    'extra_filters' => ['technician' => ['Technician', fn() => isprpt_technicians()], 'team' => ['Team', fn() => IspSetup::ready() ? DB::pairs("SELECT DISTINCT team, team FROM isp_technicians WHERE team IS NOT NULL AND team <> '' ORDER BY team") : []]],
    'run' => function (array $f): array {
        isprpt_ready();
        $techs = DB::all('SELECT * FROM isp_technicians WHERE 1 = 1' . (($f['technician'] ?? '') !== '' ? ' AND id = ' . (int)$f['technician'] : '')
            . (($f['team'] ?? '') !== '' ? ' AND team = ' . DB::pdo()->quote((string)$f['team']) : '') . ' ORDER BY name');
        $rng = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $done = [];
        foreach (DB::all("SELECT technician_id, wo_type, COUNT(*) AS n, SUM(CASE WHEN scheduled_date IS NOT NULL AND DATE(completed_at) <= scheduled_date THEN 1 ELSE 0 END) AS ontime,
                                 SUM(CASE WHEN started_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, started_at, completed_at) ELSE 0 END) AS mins, SUM(started_at IS NOT NULL) AS timed,
                                 SUM(COALESCE(labor_hours, 0)) AS hrs, SUM(COALESCE(cable_used_m, 0)) AS cable
                            FROM isp_work_orders WHERE status IN ('completed', 'verified') AND completed_at BETWEEN :f AND :t AND technician_id IS NOT NULL GROUP BY technician_id, wo_type", $rng) as $r) {
            $done[(int)$r['technician_id']][$r['wo_type']] = $r;
        }
        $tickets = DB::pairs("SELECT technician_id, COUNT(*) FROM isp_tickets WHERE technician_id IS NOT NULL AND resolved_at BETWEEN :f AND :t GROUP BY technician_id", $rng);
        $redo = DB::pairs("SELECT w.technician_id, COUNT(*) FROM isp_work_orders w WHERE w.status IN ('completed', 'verified') AND w.wo_type = 'installation' AND w.completed_at BETWEEN :f AND :t
                           AND EXISTS (SELECT 1 FROM isp_tickets t WHERE t.account_id = w.account_id AND t.created_at BETWEEN w.completed_at AND DATE_ADD(w.completed_at, INTERVAL 30 DAY)
                                       AND t.ticket_type IN ('no_internet', 'slow', 'intermittent', 'low_signal', 'damaged_cable')) GROUP BY w.technician_id", $rng);
        $open = DB::pairs("SELECT technician_id, COUNT(*) FROM isp_work_orders WHERE status IN ('scheduled', 'accepted', 'in_progress') AND technician_id IS NOT NULL GROUP BY technician_id");
        $late = DB::pairs("SELECT technician_id, COUNT(*) FROM isp_work_orders WHERE status IN ('scheduled', 'accepted', 'in_progress') AND scheduled_date < :d AND technician_id IS NOT NULL GROUP BY technician_id", ['d' => today()]);
        $out = [];
        $tot = ['tech' => 'TOTAL', 'install' => 0, 'repair' => 0, 'other' => 0, 'total' => 0, 'ontime' => 0, 'tickets' => 0, 'redo' => 0, 'hrs' => 0.0, 'cable' => 0.0, 'open' => 0, 'late' => 0];
        $mins = 0;
        $timed = 0;
        foreach ($techs as $t) {
            $id = (int)$t['id'];
            $d = $done[$id] ?? [];
            $sum = fn(string $k, ?array $types = null) => array_sum(array_map(fn($x) => (float)$x[$k], $types === null ? $d : array_intersect_key($d, array_flip($types))));
            $n = (int)$sum('n');
            $r = ['tech' => $t['name'], 'team' => $t['team'], 'install' => (int)$sum('n', ['installation']), 'repair' => (int)$sum('n', ['repair']), 'total' => $n,
                'ontime' => (int)$sum('ontime'), 'ontime_pct' => isprpt_pct($sum('ontime'), (float)$n), 'avg' => $sum('timed') ? round($sum('mins') / $sum('timed') / 60, 1) : '',
                'hrs' => round($sum('hrs'), 1) ?: '', 'cable' => round($sum('cable'), 1) ?: '', 'tickets' => (int)($tickets[$id] ?? 0), 'redo' => (int)($redo[$id] ?? 0),
                'open' => (int)($open[$id] ?? 0), 'late' => (int)($late[$id] ?? 0), 'active' => (int)$t['is_active'] ? '' : 'Inactive',
                '_links' => ['tech' => Report::link('ispfield.workorders', ['technician' => $id])]];
            $r['other'] = $n - $r['install'] - $r['repair'];
            if (!$n && !$r['tickets'] && !$r['open'] && !(int)$t['is_active']) {
                continue;
            }
            $out[] = $r;
            foreach (['install', 'repair', 'other', 'total', 'ontime', 'tickets', 'redo', 'open', 'late'] as $k) {
                $tot[$k] += $r[$k];
            }
            $tot['hrs'] += $sum('hrs');
            $tot['cable'] += $sum('cable');
            $mins += $sum('mins');
            $timed += $sum('timed');
        }
        $tot['ontime_pct'] = isprpt_pct((float)$tot['ontime'], (float)$tot['total']);
        $tot['avg'] = $timed ? round($mins / $timed / 60, 1) : '';
        $tot['hrs'] = round($tot['hrs'], 1);
        $tot['cable'] = round($tot['cable'], 1);
        return ['columns' => [['key' => 'tech', 'label' => 'Technician'], ['key' => 'team', 'label' => 'Team'], ['key' => 'install', 'label' => 'Installations', 'type' => 'int'],
            ['key' => 'repair', 'label' => 'Repairs', 'type' => 'int'], ['key' => 'other', 'label' => 'Other jobs', 'type' => 'int'], ['key' => 'total', 'label' => 'Completed', 'type' => 'int'],
            ['key' => 'ontime', 'label' => 'On the scheduled day', 'type' => 'int'], ['key' => 'ontime_pct', 'label' => 'On time', 'type' => 'pct'], ['key' => 'avg', 'label' => 'Average hours on site', 'type' => 'qty'],
            ['key' => 'hrs', 'label' => 'Labor hours', 'type' => 'qty', 'optional' => true], ['key' => 'cable', 'label' => 'Cable used (m)', 'type' => 'qty'], ['key' => 'tickets', 'label' => 'Tickets resolved', 'type' => 'int'],
            ['key' => 'redo', 'label' => 'Installs with a fault ticket within 30 days', 'type' => 'int'], ['key' => 'open', 'label' => 'Open jobs now', 'type' => 'int'], ['key' => 'late', 'label' => 'Past their date', 'type' => 'int'],
            ['key' => 'active', 'label' => 'Status', 'optional' => true]],
            'rows' => $out, 'totals' => $tot,
            'notes' => 'Work orders completed in the period by the technician assigned. Hours on site run from the start to the completion recorded in the field; the repeat-fault column counts installations followed by a connection fault ticket within 30 days.'];
    },
]);

Report::define('isp_open_wo', [
    'extra_filters' => ['wtype' => ['Kind', fn() => IspWorkOrders::TYPES], 'technician' => ['Technician', fn() => isprpt_technicians()], 'branch' => ['Branch', fn() => isprpt_branches()],
        'area' => ['Area', fn() => isprpt_areas()], 'priority' => ['Priority', fn() => IspWorkOrders::PRIORITIES], 'late' => ['Show', fn() => ['late' => 'Past their scheduled date', 'unassigned' => 'Not assigned yet']]],
    'run' => function (array $f): array {
        isprpt_ready();
        $p = [];
        $w = '';
        foreach (['wtype' => 'w.wo_type', 'priority' => 'w.priority'] as $k => $col) {
            if (($f[$k] ?? '') !== '') {
                $w .= " AND $col = :$k";
                $p[$k] = $f[$k];
            }
        }
        if (($f['technician'] ?? '') !== '') {
            $w .= ' AND w.technician_id = :tc';
            $p['tc'] = (int)$f['technician'];
        }
        if (($f['area'] ?? '') !== '') {
            [$in, $ip] = DB::in('wa', IspOutages::areaTree((int)$f['area']));
            $w .= " AND w.area_id IN $in";
            $p += $ip;
        }
        $ub = IspSetup::userBranch();
        if ($ub !== null) {
            $w .= ' AND (a.branch_id = :ub OR a.branch_id IS NULL)';
            $p['ub'] = $ub;
        }
        if (($f['branch'] ?? '') !== '') {
            $w .= ' AND a.branch_id = :br';
            $p['br'] = (int)$f['branch'];
        }
        if (($f['late'] ?? '') === 'late') {
            $w .= ' AND w.scheduled_date < :td';
            $p['td'] = today();
        } elseif (($f['late'] ?? '') === 'unassigned') {
            $w .= ' AND w.technician_id IS NULL';
        }
        $rows = DB::all("SELECT w.*, a.account_no, a.account_name, t.name AS tech, tk.ticket_no
                           FROM isp_work_orders w LEFT JOIN isp_accounts a ON a.id = w.account_id LEFT JOIN isp_technicians t ON t.id = w.technician_id LEFT JOIN isp_tickets tk ON tk.id = w.ticket_id
                          WHERE w.status IN ('open', 'scheduled', 'accepted', 'in_progress')$w
                          ORDER BY FIELD(w.priority, 'urgent', 'high', 'normal', 'low'), COALESCE(w.scheduled_date, '9999-12-31'), w.id LIMIT " . (ISPRPT_MAX_ROWS + 1), $p);
        $capNote = isprpt_capped($rows, false);
        $areas = isprpt_areas();
        $out = [];
        $lateN = 0;
        foreach ($rows as $w) {
            $late = $w['scheduled_date'] && $w['scheduled_date'] < today();
            $lateN += $late ? 1 : 0;
            $out[] = ['no' => $w['wo_no'], 'type' => IspWorkOrders::TYPES[$w['wo_type']] ?? $w['wo_type'], 'priority' => IspWorkOrders::PRIORITIES[$w['priority']] ?? $w['priority'],
                'status' => IspWorkOrders::STATUSES[$w['status']] ?? $w['status'], 'created' => substr((string)$w['created_at'], 0, 10), 'age' => isprpt_days((string)$w['created_at'], today()),
                'scheduled' => $w['scheduled_date'], 'slot' => $w['scheduled_slot'] ? (IspWorkOrders::SLOTS[$w['scheduled_slot']] ?? $w['scheduled_slot']) : '', 'late' => $late ? 'Past due' : '',
                'acct' => (string)$w['account_no'], 'name' => (string)$w['account_name'], 'address' => isprpt_mask($w['address']), 'area' => $areas[$w['area_id']] ?? '', 'tech' => $w['tech'] ?: 'Not assigned',
                'ticket' => $w['ticket_no'], 'desc' => $w['description'],
                '_links' => ['no' => isprpt_link('wo', $w['id']), 'acct' => isprpt_link('account', $w['account_id']), 'ticket' => isprpt_link('ticket', $w['ticket_id'])]];
        }
        return ['columns' => [['key' => 'no', 'label' => 'Work order'], ['key' => 'type', 'label' => 'Kind'], ['key' => 'priority', 'label' => 'Priority'], ['key' => 'status', 'label' => 'Status'],
            ['key' => 'created', 'label' => 'Created', 'type' => 'date'], ['key' => 'age', 'label' => 'Age (days)', 'type' => 'int'], ['key' => 'scheduled', 'label' => 'Scheduled', 'type' => 'date'],
            ['key' => 'slot', 'label' => 'Slot'], ['key' => 'late', 'label' => 'Late'], ['key' => 'acct', 'label' => 'Account no.'], ['key' => 'name', 'label' => 'Subscriber'],
            ['key' => 'address', 'label' => 'Address', 'optional' => true], ['key' => 'area', 'label' => 'Area'], ['key' => 'tech', 'label' => 'Technician'], ['key' => 'ticket', 'label' => 'Ticket'],
            ['key' => 'desc', 'label' => 'Work to do']],
            'rows' => $out, 'totals' => ['no' => 'TOTAL (' . count($out) . ', ' . $lateN . ' past their date)'],
            'notes' => 'Work orders not yet completed or cancelled, most urgent first.' . $capNote];
    },
]);

Report::define('isp_ticket_times', [
    'filters' => ['range'],
    'extra_filters' => ['ttype' => ['Concern', fn() => IspTickets::TYPES], 'priority' => ['Priority', fn() => IspTickets::PRIORITIES], 'tstatus' => ['Status', fn() => IspTickets::STATUSES],
        'source' => ['Reported through', fn() => IspTickets::SOURCES], 'branch' => ['Branch', fn() => isprpt_branches()]],
    'run' => function (array $f): array {
        isprpt_ready();
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $w = '';
        foreach (['ttype' => 't.ticket_type', 'priority' => 't.priority', 'tstatus' => 't.status', 'source' => 't.source'] as $k => $col) {
            if (($f[$k] ?? '') !== '') {
                $w .= " AND $col = :$k";
                $p[$k] = $f[$k];
            }
        }
        $ub = IspSetup::userBranch();
        if ($ub !== null) {
            $w .= ' AND (a.branch_id = :ub OR a.branch_id IS NULL)';
            $p['ub'] = $ub;
        }
        if (($f['branch'] ?? '') !== '') {
            $w .= ' AND a.branch_id = :br';
            $p['br'] = (int)$f['branch'];
        }
        $rows = DB::all("SELECT t.*, a.account_no, a.account_name, tc.name AS tech FROM isp_tickets t LEFT JOIN isp_accounts a ON a.id = t.account_id LEFT JOIN isp_technicians tc ON tc.id = t.technician_id
                          WHERE t.created_at BETWEEN :f AND :t$w ORDER BY t.created_at, t.id LIMIT " . (ISPRPT_MAX_ROWS + 1), $p);
        $capNote = isprpt_capped($rows, false);
        $out = [];
        $hours = [];
        $within = 0;
        $resolved = 0;
        $breachedOpen = 0;
        $byType = [];
        $ratings = [];
        foreach ($rows as $t) {
            $end = $t['resolved_at'];
            $h = $end ? round((strtotime($end) - strtotime($t['created_at'])) / 3600, 1) : null;
            $ok = null;
            if ($end) {
                $resolved++;
                $hours[] = $h;
                $ok = !$t['sla_due_at'] || $end <= $t['sla_due_at'];
                $within += $ok ? 1 : 0;
                $byType[$t['ticket_type']]['h'][] = $h;
            } elseif ($t['sla_due_at'] && $t['sla_due_at'] < date('Y-m-d H:i:s') && !in_array($t['status'], ['resolved', 'verified', 'closed'], true)) {
                $breachedOpen++;
            }
            $byType[$t['ticket_type']]['n'] = ($byType[$t['ticket_type']]['n'] ?? 0) + 1;
            if ($t['feedback_rating']) {
                $ratings[] = (int)$t['feedback_rating'];
            }
            $out[] = ['no' => $t['ticket_no'], 'created' => $t['created_at'], 'type' => IspTickets::TYPES[$t['ticket_type']] ?? $t['ticket_type'], 'priority' => IspTickets::PRIORITIES[$t['priority']] ?? $t['priority'],
                'source' => IspTickets::SOURCES[$t['source']] ?? $t['source'], 'acct' => (string)$t['account_no'], 'name' => (string)$t['account_name'], 'subject' => $t['subject'],
                'status' => IspTickets::STATUSES[$t['status']] ?? $t['status'], 'sla' => $t['sla_due_at'], 'resolved' => $end, 'hours' => $h ?? '',
                'within' => $ok === null ? (!$end && $t['sla_due_at'] && $t['sla_due_at'] < date('Y-m-d H:i:s') ? 'Open, over SLA' : '') : ($ok ? 'Within SLA' : 'Over SLA'),
                'esc' => (int)$t['escalation_level'] ?: '', 'tech' => $t['tech'], 'cause' => $t['root_cause'], 'rating' => $t['feedback_rating'] ?: '', 'ntc' => (int)$t['ntc_complaint'] ? 'NTC' : '',
                '_links' => ['no' => isprpt_link('ticket', $t['id']), 'acct' => isprpt_link('account', $t['account_id'])]];
        }
        $summary = [];
        foreach ($byType as $k => $v) {
            $summary[] = (IspTickets::TYPES[$k] ?? $k) . ' ' . $v['n'] . (!empty($v['h']) ? ' (avg ' . number_format(array_sum($v['h']) / count($v['h']), 1) . ' h)' : '');
        }
        return ['columns' => [['key' => 'no', 'label' => 'Ticket'], ['key' => 'created', 'label' => 'Reported', 'type' => 'datetime'], ['key' => 'type', 'label' => 'Concern'], ['key' => 'priority', 'label' => 'Priority'],
            ['key' => 'source', 'label' => 'Through', 'optional' => true], ['key' => 'acct', 'label' => 'Account no.'], ['key' => 'name', 'label' => 'Subscriber'], ['key' => 'subject', 'label' => 'Subject', 'optional' => true],
            ['key' => 'status', 'label' => 'Status'], ['key' => 'sla', 'label' => 'SLA due', 'type' => 'datetime'], ['key' => 'resolved', 'label' => 'Resolved', 'type' => 'datetime'],
            ['key' => 'hours', 'label' => 'Hours to resolve', 'type' => 'qty'], ['key' => 'within', 'label' => 'SLA'], ['key' => 'esc', 'label' => 'Escalations', 'type' => 'int'],
            ['key' => 'tech', 'label' => 'Technician'], ['key' => 'cause', 'label' => 'Root cause', 'optional' => true], ['key' => 'rating', 'label' => 'Rating', 'type' => 'int'], ['key' => 'ntc', 'label' => 'NTC complaint', 'optional' => true]],
            'rows' => $out, 'totals' => ['no' => 'TOTAL (' . count($out) . ')', 'hours' => $hours ? round(array_sum($hours) / count($hours), 1) : ''],
            'notes' => 'Tickets reported in the period; the total line gives the average hours to resolve. ' . $resolved . ' resolved, ' . ($resolved ? round($within / $resolved * 100, 1) : 0) . '% within their SLA; '
                . $breachedOpen . ' still open past their SLA.' . ($ratings ? ' Average rating ' . number_format(array_sum($ratings) / count($ratings), 1) . ' of 5.' : '')
                . ' By concern: ' . h(implode(', ', $summary) ?: 'none') . '.' . $capNote];
    },
]);

Report::define('isp_equipment_failures', [
    'filters' => ['range'],
    'default_dates' => 'this_year',
    'sortable' => false,
    'extra_filters' => ['ownership' => ['Ownership', fn() => IspEquipment::OWNERSHIP]],
    'run' => function (array $f): array {
        isprpt_ready();
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $w = '';
        if (($f['ownership'] ?? '') !== '') {
            $w .= ' AND e.ownership = :ow';
            $p['ow'] = $f['ownership'];
        }
        $events = DB::all("SELECT l.*, e.serial_no, e.item_id, e.ownership, e.installed_at, e.unit_cost, i.code AS item_code, i.name AS item_name, a.account_no, w.wo_no, w.wo_type
                             FROM isp_equipment_log l JOIN isp_equipment e ON e.id = l.equipment_id JOIN items i ON i.id = e.item_id LEFT JOIN isp_accounts a ON a.id = l.account_id
                             LEFT JOIN isp_work_orders w ON w.id = l.work_order_id
                            WHERE l.to_status IN ('damaged', 'missing', 'disposed') AND l.created_at BETWEEN :f AND :t$w ORDER BY i.code, l.created_at", $p);
        $p2 = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $repl = (int)DB::value("SELECT COUNT(*) FROM isp_work_orders WHERE wo_type = 'replacement' AND status IN ('completed', 'verified') AND completed_at BETWEEN :f AND :t", $p2);
        $deployed = DB::pairs("SELECT e.item_id, COUNT(*) FROM isp_equipment e WHERE e.status IN ('installed', 'for_recovery', 'damaged', 'missing', 'disposed', 'recovered') OR e.installed_at IS NOT NULL GROUP BY e.item_id");
        $users = isprpt_user_names();
        $out = [];
        $item = null;
        $n = 0;
        $cost = 0.0;
        $grand = 0;
        $grandCost = 0.0;
        $flush = function () use (&$out, &$item, &$n, &$cost, $deployed) {
            if ($item !== null) {
                $dep = (int)($deployed[$item] ?? 0);
                $out[] = ['date' => 'Total: ' . $n . ' failure(s) of ' . $dep . ' deployed' . ($dep ? ' (' . round($n / $dep * 100, 1) . '%)' : ''), 'cost' => r2($cost), '_class' => 'subtotal-row'];
            }
            $n = 0;
            $cost = 0.0;
        };
        foreach ($events as $e) {
            if ((int)$e['item_id'] !== $item) {
                $flush();
                $item = (int)$e['item_id'];
                $out[] = ['date' => $e['item_code'] . ' ' . $e['item_name'], '_class' => 'group-row'];
            }
            $months = $e['installed_at'] ? max(0, (int)floor(isprpt_days((string)$e['installed_at'], substr((string)$e['created_at'], 0, 10)) / 30.4375)) : '';
            $out[] = ['date' => fdate($e['created_at']), 'serial' => $e['serial_no'], 'owner' => IspEquipment::OWNERSHIP[$e['ownership']] ?? $e['ownership'],
                'what' => (IspEquipment::STATUSES[$e['from_status']] ?? $e['from_status']) . ' to ' . (IspEquipment::STATUSES[$e['to_status']] ?? $e['to_status']), 'acct' => $e['account_no'],
                'months' => $months, 'wo' => $e['wo_no'] ? $e['wo_no'] . ' (' . (IspWorkOrders::TYPES[$e['wo_type']] ?? $e['wo_type']) . ')' : '', 'remarks' => $e['remarks'], 'cost' => (float)$e['unit_cost'] ?: '',
                'user' => $users[$e['user_id']] ?? '', '_class' => 'indent-1',
                '_links' => ['serial' => isprpt_link('equipment', $e['equipment_id']), 'acct' => isprpt_link('account', $e['account_id']), 'wo' => isprpt_link('wo', $e['work_order_id'])]];
            $n++;
            $grand++;
            $cost += (float)$e['unit_cost'];
            $grandCost += (float)$e['unit_cost'];
        }
        $flush();
        $out[] = ['date' => 'TOTAL (' . $grand . ' failures)', 'cost' => r2($grandCost), '_class' => 'total-row'];
        return ['grouped' => true, 'columns' => [['key' => 'date', 'label' => 'Date'], ['key' => 'serial', 'label' => 'Serial no.'], ['key' => 'owner', 'label' => 'Ownership', 'optional' => true],
            ['key' => 'what', 'label' => 'Change'], ['key' => 'acct', 'label' => 'Subscriber account'], ['key' => 'months', 'label' => 'Months in service', 'type' => 'int'], ['key' => 'wo', 'label' => 'Work order'],
            ['key' => 'remarks', 'label' => 'Remarks'], ['key' => 'cost', 'label' => 'Unit cost', 'type' => 'money', 'sensitive' => 'cost'], ['key' => 'user', 'label' => 'Recorded by', 'optional' => true]],
            'rows' => $out,
            'notes' => 'Equipment marked damaged, missing or disposed in the period, by model, with the failure rate against the units of the model ever deployed. Replacement work orders completed in the period: ' . $repl . '.'];
    },
]);

Report::define('isp_install_profit', [
    'filters' => ['range'],
    'extra_filters' => ['branch' => ['Branch', fn() => isprpt_branches()], 'plan' => ['Plan', fn() => isprpt_plans()], 'technician' => ['Technician', fn() => isprpt_technicians()]],
    'run' => function (array $f): array {
        isprpt_ready();
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $w = isprpt_where($f, $p);
        if (($f['technician'] ?? '') !== '') {
            $w .= ' AND w.technician_id = :tc';
            $p['tc'] = (int)$f['technician'];
        }
        $rows = DB::all("SELECT w.*, a.account_no, a.account_name, pl.name AS plan_name, t.name AS tech FROM isp_work_orders w JOIN isp_accounts a ON a.id = w.account_id
                           LEFT JOIN isp_plans pl ON pl.id = a.plan_id LEFT JOIN isp_technicians t ON t.id = w.technician_id
                          WHERE w.wo_type = 'installation' AND w.status IN ('completed', 'verified') AND w.completed_at BETWEEN :f AND :t$w ORDER BY w.completed_at LIMIT " . (ISPRPT_MAX_ROWS + 1), $p);
        $capNote = isprpt_capped($rows, false);
        $mat = $fee = [];
        if ($rows) {
            [$in, $ip] = DB::in('wm', array_column($rows, 'id'));
            $mat = DB::pairs("SELECT work_order_id, SUM(qty_used * unit_cost) FROM isp_wo_materials WHERE work_order_id IN $in GROUP BY work_order_id", $ip);
            [$in2, $ip2] = DB::in('wf', array_column($rows, 'account_id'));
            // the installation fee billed (net of VAT)
            foreach (DB::all("SELECT i.account_id, SUM(l.amount) AS amt FROM isp_invoice_lines l JOIN isp_invoices i ON i.id = l.invoice_id
                               WHERE l.line_type = 'installation' AND i.status IN " . ISPRPT_BOOKED . " AND i.account_id IN $in2 GROUP BY i.account_id", $ip2) as $x) {
                $fee[(int)$x['account_id']] = (float)$x['amt'];
            }
        }
        $out = [];
        $tot = ['wo' => '', 'fee' => 0.0, 'materials' => 0.0, 'labor' => 0.0, 'cost' => 0.0, 'margin' => 0.0, 'cable' => 0.0];
        foreach ($rows as $w) {
            $m = (float)($mat[$w['id']] ?? 0);
            $l = (float)$w['labor_cost'] + (float)$w['travel_expense'];
            $fe = (float)($fee[$w['account_id']] ?? 0);
            $cost = $m + $l;
            $out[] = ['wo' => $w['wo_no'], 'date' => substr((string)$w['completed_at'], 0, 10), 'acct' => $w['account_no'], 'name' => $w['account_name'], 'plan' => $w['plan_name'], 'tech' => $w['tech'],
                'cable' => (float)$w['cable_used_m'] ?: '', 'fee' => r2($fe), 'materials' => r2($m) ?: '', 'labor' => r2($l) ?: '', 'cost' => r2($cost), 'margin' => r2($fe - $cost), 'pct' => isprpt_pct($fe - $cost, $fe),
                '_links' => ['wo' => isprpt_link('wo', $w['id']), 'acct' => isprpt_link('account', $w['account_id'])]];
            $tot['fee'] += $fe;
            $tot['materials'] += $m;
            $tot['labor'] += $l;
            $tot['cost'] += $cost;
            $tot['margin'] += $fe - $cost;
            $tot['cable'] += (float)$w['cable_used_m'];
        }
        $tot = array_map(fn($v) => is_float($v) ? r2($v) : $v, $tot);
        $tot['pct'] = isprpt_pct($tot['margin'], $tot['fee']);
        $tot['wo'] = 'TOTAL (' . count($out) . ' installations)';
        return ['columns' => [['key' => 'wo', 'label' => 'Work order'], ['key' => 'date', 'label' => 'Completed', 'type' => 'date'], ['key' => 'acct', 'label' => 'Account no.'], ['key' => 'name', 'label' => 'Subscriber'],
            ['key' => 'plan', 'label' => 'Plan'], ['key' => 'tech', 'label' => 'Technician'], ['key' => 'cable', 'label' => 'Cable (m)', 'type' => 'qty'], ['key' => 'fee', 'label' => 'Installation fee billed', 'type' => 'money'],
            ['key' => 'materials', 'label' => 'Materials', 'type' => 'money', 'sensitive' => 'cost'], ['key' => 'labor', 'label' => 'Labor & travel', 'type' => 'money'],
            ['key' => 'cost', 'label' => 'Total cost', 'type' => 'money', 'sensitive' => 'cost'], ['key' => 'margin', 'label' => 'Margin', 'type' => 'money', 'sensitive' => 'cost'], ['key' => 'pct', 'label' => 'Margin %', 'type' => 'pct', 'sensitive' => 'cost']],
            'rows' => $out, 'totals' => $tot,
            'notes' => 'Installations completed in the period: the installation fee billed to the subscriber (net of VAT; a waived fee counts as nothing) against the materials used at their issue cost and the labor and travel recorded on the work order.' . $capNote];
    },
]);

Report::define('isp_materials', [
    'filters' => ['range', 'search'],
    'filter_labels' => ['search' => 'Item, serial or work order'],
    'extra_filters' => ['technician' => ['Technician', fn() => isprpt_technicians()], 'wtype' => ['Work order kind', fn() => IspWorkOrders::TYPES],
        'by' => ['Show', fn() => ['detail' => 'Every line', 'item' => 'Totals by item', 'technician' => 'Totals by technician'], null]],
    'run' => function (array $f): array {
        isprpt_ready();
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $w = '';
        if (($f['technician'] ?? '') !== '') {
            $w .= ' AND w.technician_id = :tc';
            $p['tc'] = (int)$f['technician'];
        }
        if (($f['wtype'] ?? '') !== '') {
            $w .= ' AND w.wo_type = :wt';
            $p['wt'] = $f['wtype'];
        }
        if (trim((string)$f['q']) !== '') {
            $w .= ' AND (i.code LIKE :q1 OR i.name LIKE :q2 OR m.serial_no LIKE :q3 OR w.wo_no LIKE :q4)';
            $p['q1'] = $p['q2'] = $p['q3'] = $p['q4'] = '%' . trim((string)$f['q']) . '%';
        }
        $rows = DB::all("SELECT m.*, i.code AS item_code, i.name AS item_name, i.unit, w.wo_no, w.wo_type, w.status AS wo_status, w.technician_id, t.name AS tech, a.account_no
                           FROM isp_wo_materials m JOIN isp_work_orders w ON w.id = m.work_order_id JOIN items i ON i.id = m.item_id LEFT JOIN isp_technicians t ON t.id = w.technician_id
                           LEFT JOIN isp_accounts a ON a.id = w.account_id
                          WHERE m.created_at BETWEEN :f AND :t$w ORDER BY m.created_at, m.id LIMIT " . (ISPRPT_MAX_ROWS + 1), $p);
        $capNote = isprpt_capped($rows, false);
        $by = $f['by'] !== '' ? $f['by'] : 'detail';
        $out = [];
        $tot = ['issued' => 0.0, 'used' => 0.0, 'returned' => 0.0, 'open' => 0.0, 'cost' => 0.0];
        $groups = [];
        foreach ($rows as $m) {
            $closed = in_array($m['wo_status'], ['completed', 'verified', 'cancelled'], true);
            $open = r2((float)$m['qty_issued'] - (float)$m['qty_used'] - (float)$m['qty_returned']);
            $cost = r2((float)$m['qty_used'] * (float)$m['unit_cost']);
            if ($by === 'detail') {
                $out[] = ['date' => substr((string)$m['created_at'], 0, 10), 'wo' => $m['wo_no'], 'type' => IspWorkOrders::TYPES[$m['wo_type']] ?? $m['wo_type'], 'tech' => $m['tech'], 'acct' => $m['account_no'],
                    'item' => $m['item_code'] . ' ' . $m['item_name'], 'serial' => $m['serial_no'], 'unit' => $m['unit'], 'issued' => (float)$m['qty_issued'], 'used' => (float)$m['qty_used'],
                    'returned' => (float)$m['qty_returned'] ?: '', 'open' => $open > 0 ? $open : '', 'flag' => $open > 0 && $closed ? 'Not accounted for' : ($open > 0 ? 'With the technician' : ''),
                    'ucost' => (float)$m['unit_cost'], 'cost' => $cost, '_links' => ['wo' => isprpt_link('wo', $m['work_order_id'])]];
            } else {
                $k = $by === 'item' ? (string)$m['item_id'] : (string)$m['technician_id'];
                $groups[$k] ??= ['label' => $by === 'item' ? $m['item_code'] . ' ' . $m['item_name'] : ($m['tech'] ?: '(no technician)'), 'unit' => $by === 'item' ? $m['unit'] : '', 'issued' => 0.0, 'used' => 0.0, 'returned' => 0.0, 'open' => 0.0, 'cost' => 0.0, 'lost' => 0.0, 'n' => 0];
                $groups[$k]['issued'] += (float)$m['qty_issued'];
                $groups[$k]['used'] += (float)$m['qty_used'];
                $groups[$k]['returned'] += (float)$m['qty_returned'];
                $groups[$k]['open'] += max(0, $open);
                $groups[$k]['lost'] += $closed ? max(0, $open) : 0;
                $groups[$k]['cost'] += $cost;
                $groups[$k]['n']++;
            }
            $tot['issued'] += (float)$m['qty_issued'];
            $tot['used'] += (float)$m['qty_used'];
            $tot['returned'] += (float)$m['qty_returned'];
            $tot['open'] += max(0, $open);
            $tot['cost'] += $cost;
        }
        if ($by !== 'detail') {
            uasort($groups, fn($x, $y) => strnatcasecmp($x['label'], $y['label']));
            foreach ($groups as $g) {
                $out[] = ['item' => $g['label'], 'unit' => $g['unit'], 'n' => $g['n'], 'issued' => round($g['issued'], 4), 'used' => round($g['used'], 4), 'returned' => round($g['returned'], 4) ?: '',
                    'open' => round($g['open'], 4) ?: '', 'flag' => $g['lost'] > 0 ? round($g['lost'], 4) . ' not accounted for' : '', 'cost' => r2($g['cost'])];
            }
            $cols = [['key' => 'item', 'label' => $by === 'item' ? 'Item' : 'Technician'], ['key' => 'unit', 'label' => 'Unit'], ['key' => 'n', 'label' => 'Lines', 'type' => 'int'],
                ['key' => 'issued', 'label' => 'Issued', 'type' => 'qty'], ['key' => 'used', 'label' => 'Used', 'type' => 'qty'], ['key' => 'returned', 'label' => 'Returned', 'type' => 'qty'],
                ['key' => 'open', 'label' => 'Not yet returned / used', 'type' => 'qty'], ['key' => 'flag', 'label' => 'Of closed work orders'], ['key' => 'cost', 'label' => 'Cost of what was used', 'type' => 'money', 'sensitive' => 'cost']];
            if ($by === 'technician') {
                unset($cols[1]);
                $cols = array_values($cols);
            }
        } else {
            $cols = [['key' => 'date', 'label' => 'Issued', 'type' => 'date'], ['key' => 'wo', 'label' => 'Work order'], ['key' => 'type', 'label' => 'Kind', 'optional' => true], ['key' => 'tech', 'label' => 'Technician'],
                ['key' => 'acct', 'label' => 'Account no.'], ['key' => 'item', 'label' => 'Item'], ['key' => 'serial', 'label' => 'Serial no.'], ['key' => 'unit', 'label' => 'Unit', 'optional' => true],
                ['key' => 'issued', 'label' => 'Issued', 'type' => 'qty'], ['key' => 'used', 'label' => 'Used', 'type' => 'qty'], ['key' => 'returned', 'label' => 'Returned', 'type' => 'qty'],
                ['key' => 'open', 'label' => 'Not yet returned / used', 'type' => 'qty'], ['key' => 'flag', 'label' => 'Check'], ['key' => 'ucost', 'label' => 'Unit cost', 'type' => 'money', 'sensitive' => 'cost'],
                ['key' => 'cost', 'label' => 'Cost of what was used', 'type' => 'money', 'sensitive' => 'cost']];
        }
        $first = $cols[0]['key'];
        return ['columns' => $cols, 'rows' => $out,
            'totals' => [$first => 'TOTAL', 'issued' => round($tot['issued'], 4), 'used' => round($tot['used'], 4), 'returned' => round($tot['returned'], 4), 'open' => round($tot['open'], 4), 'cost' => r2($tot['cost'])],
            'notes' => 'Materials and equipment issued to work orders in the period, what was used at the subscriber and what came back to the stockroom. Quantities still out on a completed or cancelled work order are flagged.' . $capNote];
    },
]);
