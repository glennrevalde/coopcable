<?php
declare(strict_types=1);

/**
 * Network outages (Workflow H): registration, the affected subscribers from the topology (every subscriber below the
 * node) or the area, verification (a suspected outage is not announced), the repair, the restoration, the downtime of
 * each subscriber, the SLA assessment and the service credits (proposed, approved, posted as credit adjustments).
 */
final class IspOutages
{
    public const STATUSES = ['suspected' => 'Suspected', 'verified' => 'Verified', 'in_repair' => 'In repair', 'restored' => 'Restored', 'closed' => 'Closed', 'false_alarm' => 'False alarm'];
    public const SCOPES = ['node' => 'A network node and everything below it', 'area' => 'A service area', 'network' => 'The whole network'];
    public const CREDIT_POLICIES = ['none' => 'No credit', 'prorated' => 'Prorated for the downtime beyond the SLA', 'fixed' => 'A fixed credit per account'];

    public static function get(int $id): ?array
    {
        return DB::row('SELECT o.*, n.code AS node_code, n.name AS node_name, a.name AS area_name FROM isp_outages o LEFT JOIN isp_net_nodes n ON n.id = o.node_id
                          LEFT JOIN isp_areas a ON a.id = o.area_id WHERE o.id = :id', ['id' => $id]);
    }

    /** Areas below an area (barangays of a municipality, zones of a barangay) with the area itself */
    public static function areaTree(int $areaId): array
    {
        $out = [$areaId];
        $queue = [$areaId];
        for ($g = 0; $queue && $g < 2000; $g++) {
            $id = array_shift($queue);
            foreach (array_map('intval', DB::column('SELECT id FROM isp_areas WHERE parent_id = :p', ['p' => $id])) as $c) {
                if (!in_array($c, $out, true)) {
                    $out[] = $c;
                    $queue[] = $c;
                }
            }
        }
        return $out;
    }

    public static function create(array $f): int
    {
        if (trim((string)($f['title'] ?? '')) === '') {
            fail('Enter what is down.');
        }
        $scope = isset(self::SCOPES[$f['scope'] ?? '']) ? $f['scope'] : 'node';
        if ($scope === 'node' && empty($f['node_id'])) {
            fail('Choose the network node that is down.');
        }
        if ($scope === 'area' && empty($f['area_id'])) {
            fail('Choose the service area.');
        }
        return DB::tx(function () use ($f, $scope) {
            $started = ($f['started_at'] ?? '') !== '' ? date('Y-m-d H:i:s', strtotime((string)$f['started_at'])) : date('Y-m-d H:i:s');
            $id = DB::insert('isp_outages', ['outage_no' => Seq::next('isp_outage'), 'title' => mb_substr(trim((string)$f['title']), 0, 160), 'scope' => $scope,
                'node_id' => $scope === 'node' ? (int)$f['node_id'] : null, 'area_id' => $scope === 'area' ? (int)$f['area_id'] : null,
                'status' => !empty($f['verified']) ? 'verified' : 'suspected', 'is_planned' => !empty($f['is_planned']) ? 1 : 0, 'started_at' => $started,
                'verified_at' => !empty($f['verified']) ? date('Y-m-d H:i:s') : null, 'root_cause' => ($f['root_cause'] ?? '') ?: null,
                'sla_hours' => ($f['sla_hours'] ?? '') === '' ? 24 : (float)$f['sla_hours'], 'credit_policy' => isset(self::CREDIT_POLICIES[$f['credit_policy'] ?? '']) ? $f['credit_policy'] : 'prorated',
                'credit_fixed' => r2((float)($f['credit_fixed'] ?? 0)), 'created_by' => Auth::id()]);
            if ($scope === 'node' && in_array(DB::value('SELECT status FROM isp_net_nodes WHERE id = :n', ['n' => $f['node_id']]), ['active'], true)) {
                DB::run("UPDATE isp_net_nodes SET status = 'damaged' WHERE id = :n", ['n' => $f['node_id']]);
                DB::insert('isp_node_events', ['node_id' => (int)$f['node_id'], 'event_type' => 'damaged', 'event_date' => substr($started, 0, 10), 'remarks' => 'Outage ' . $f['title'], 'user_id' => Auth::id()]);
            }
            $n = self::identify($id);
            if (!empty($f['verified'])) {
                self::announce($id);
            }
            Audit::log('create', 'ispnet', 'isp_outage', $id, (string)DB::value('SELECT outage_no FROM isp_outages WHERE id = :i', ['i' => $id]), ['reason' => $n . ' subscriber(s) affected']);
            return $id;
        });
    }

    /** The affected subscribers: from the topology (node), the area tree, or every connected account */
    public static function identify(int $id): int
    {
        $o = self::get($id);
        $ids = match ($o['scope']) {
            'node' => IspInfra::affectedAccounts((int)$o['node_id']),
            'area' => (function () use ($o) {
                [$in, $p] = DB::in('ar', self::areaTree((int)$o['area_id']));
                return array_map('intval', DB::column("SELECT id FROM isp_accounts WHERE area_id IN $in AND service_status IN ('active', 'suspended')", $p));
            })(),
            default => array_map('intval', DB::column("SELECT id FROM isp_accounts WHERE service_status = 'active'")),
        };
        foreach ($ids as $aid) {
            if (!DB::value('SELECT id FROM isp_outage_accounts WHERE outage_id = :o AND account_id = :a', ['o' => $id, 'a' => $aid])) {
                DB::insert('isp_outage_accounts', ['outage_id' => $id, 'account_id' => $aid, 'how_found' => $o['scope'] === 'node' ? 'topology' : ($o['scope'] === 'area' ? 'area' : 'manual')]);
            }
        }
        $n = (int)DB::value('SELECT COUNT(*) FROM isp_outage_accounts WHERE outage_id = :o', ['o' => $id]);
        DB::update('isp_outages', ['affected_count' => $n], 'id = :id', ['id' => $id]);
        return $n;
    }

    /** The subscribers are told (once per account) */
    public static function announce(int $id): int
    {
        $o = self::get($id);
        $where = $o['scope'] === 'node' ? ($o['node_name'] . ' (' . $o['node_code'] . ')') : ($o['area_name'] ?: 'your area');
        $n = 0;
        foreach (DB::all('SELECT * FROM isp_outage_accounts WHERE outage_id = :o AND notified_at IS NULL', ['o' => $id]) as $oa) {
            $n += IspNotify::event((int)$o['is_planned'] === 1 ? 'service_maintenance' : 'network_outage', (int)$oa['account_id'],
                ['area' => $where, 'title' => $o['title'], 'date' => fdatetime($o['started_at'])], 'OUT-' . $id);
            DB::update('isp_outage_accounts', ['notified_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $oa['id']]);
        }
        if (!DB::value('SELECT id FROM isp_announcements WHERE outage_id = :o', ['o' => $id])) {
            DB::insert('isp_announcements', ['title' => ((int)$o['is_planned'] === 1 ? 'Scheduled maintenance: ' : 'Service interruption: ') . $o['title'], 'body' => 'Affected: ' . $where . '. Since ' . fdatetime($o['started_at']) . '. Our team is working on it.',
                'area_id' => $o['area_id'], 'outage_id' => $id, 'starts_at' => $o['started_at'], 'is_active' => 1, 'created_by' => Auth::id()]);
        }
        return $n;
    }

    public static function move(int $id, string $to, array $f = []): void
    {
        DB::tx(function () use ($id, $to, $f) {
            $o = DB::row('SELECT * FROM isp_outages WHERE id = :id FOR UPDATE', ['id' => $id]) ?? fail('Outage not found.');
            $allowed = ['suspected' => ['verified', 'false_alarm'], 'verified' => ['in_repair', 'restored'], 'in_repair' => ['restored'], 'restored' => ['closed', 'in_repair'], 'closed' => [], 'false_alarm' => []];
            if (!in_array($to, $allowed[$o['status']] ?? [], true)) {
                fail('Outage ' . $o['outage_no'] . ' cannot go from ' . self::STATUSES[$o['status']] . ' to ' . (self::STATUSES[$to] ?? $to) . '.');
            }
            $set = ['status' => $to, 'updated_at' => date('Y-m-d H:i:s')];
            if (!empty($f['root_cause'])) {
                $set['root_cause'] = mb_substr((string)$f['root_cause'], 0, 255);
            }
            if (!empty($f['repair_notes'])) {
                $set['repair_notes'] = trim(($o['repair_notes'] ?? '') . "\n" . date('Y-m-d H:i') . ' ' . $f['repair_notes']);
            }
            if ($to === 'verified') {
                $set['verified_at'] = date('Y-m-d H:i:s');
            }
            if ($to === 'restored') {
                $set['restored_at'] = ($f['restored_at'] ?? '') !== '' ? date('Y-m-d H:i:s', strtotime((string)$f['restored_at'])) : date('Y-m-d H:i:s');
                if (!$o['root_cause'] && empty($set['root_cause'])) {
                    fail('Enter the root cause before marking the outage restored.');
                }
            }
            if ($to === 'closed') {
                $set['closed_at'] = date('Y-m-d H:i:s');
            }
            DB::update('isp_outages', $set, 'id = :id', ['id' => $id]);
            if ($to === 'verified') {
                self::announce($id);
            }
            if ($to === 'restored') {
                self::computeCredits($id);
                if ($o['node_id']) {
                    DB::run("UPDATE isp_net_nodes SET status = 'active' WHERE id = :n AND status = 'damaged'", ['n' => $o['node_id']]);
                    DB::insert('isp_node_events', ['node_id' => (int)$o['node_id'], 'event_type' => 'repaired', 'event_date' => today(), 'remarks' => 'Outage ' . $o['outage_no'] . ' restored', 'user_id' => Auth::id()]);
                }
                DB::run('UPDATE isp_announcements SET ends_at = :t WHERE outage_id = :o', ['t' => date('Y-m-d H:i:s', time() + 86400), 'o' => $id]);
                foreach (DB::column('SELECT account_id FROM isp_outage_accounts WHERE outage_id = :o', ['o' => $id]) as $aid) {
                    IspNotify::event('service_restored', (int)$aid, [], 'OUTR-' . $id);
                }
            }
            if ($to === 'false_alarm') {
                DB::run('DELETE FROM isp_outage_accounts WHERE outage_id = :o AND adjustment_id IS NULL', ['o' => $id]);
                if ($o['node_id']) {
                    DB::run("UPDATE isp_net_nodes SET status = 'active' WHERE id = :n AND status = 'damaged'", ['n' => $o['node_id']]);
                }
            }
            Audit::log('status', 'ispnet', 'isp_outage', $id, (string)$o['outage_no'], ['changes' => [['field' => 'status', 'old' => $o['status'], 'new' => $to]]]);
        });
    }

    /** The downtime of each subscriber and the credit the policy gives (proposed: not posted until approved) */
    public static function computeCredits(int $id): void
    {
        $o = self::get($id);
        $end = $o['restored_at'] ?: date('Y-m-d H:i:s');
        $hours = round((strtotime($end) - strtotime($o['started_at'])) / 3600, 2);
        $over = max(0, $hours - (float)$o['sla_hours']);
        foreach (DB::all('SELECT oa.*, a.plan_id, a.member_price FROM isp_outage_accounts oa JOIN isp_accounts a ON a.id = oa.account_id WHERE oa.outage_id = :o', ['o' => $id]) as $oa) {
            $credit = 0.0;
            if ($over > 0 && (int)$o['is_planned'] !== 1) {
                if ($o['credit_policy'] === 'prorated') {
                    $fee = IspPlans::priceFor(IspPlans::get((int)$oa['plan_id']), $oa);
                    $credit = r2($fee * ($hours / 24) / (int)date('t', strtotime($o['started_at'])));
                } elseif ($o['credit_policy'] === 'fixed') {
                    $credit = r2((float)$o['credit_fixed']);
                }
            }
            DB::update('isp_outage_accounts', ['downtime_hours' => $hours, 'credit_amount' => $credit], 'id = :id', ['id' => $oa['id']]);
        }
        DB::update('isp_outages', ['credits_status' => $over > 0 && $o['credit_policy'] !== 'none' ? 'proposed' : 'none'], 'id = :id', ['id' => $id]);
    }

    /** Posts the proposed credits: on the latest open invoice of each account, else on its next invoice */
    public static function postCredits(int $id): int
    {
        if (!Access::special('isp_write_off')) {
            fail('Posting outage credits needs the permission "Cable & Internet: can approve billing adjustments".');
        }
        $o = self::get($id);
        if ($o['credits_status'] !== 'proposed') {
            fail('There are no proposed credits on this outage.');
        }
        $n = 0;
        foreach (DB::all('SELECT * FROM isp_outage_accounts WHERE outage_id = :o AND credit_amount > 0 AND adjustment_id IS NULL', ['o' => $id]) as $oa) {
            DB::tx(function () use ($oa, $o, &$n) {
                $inv = DB::row("SELECT id, balance FROM isp_invoices WHERE account_id = :a AND status IN ('posted', 'partially_paid', 'overdue') AND balance >= :c ORDER BY invoice_date DESC LIMIT 1",
                    ['a' => $oa['account_id'], 'c' => $oa['credit_amount']]);
                $why = 'Service credit for outage ' . $o['outage_no'] . ' (' . $oa['downtime_hours'] . ' h down)';
                if ($inv) {
                    $adj = IspCredit::request((int)$oa['account_id'], (int)$inv['id'], 'outage_credit', (float)$oa['credit_amount'], $why, 'outage', (int)$o['id'], true);
                    DB::update('isp_outage_accounts', ['adjustment_id' => $adj], 'id = :id', ['id' => $oa['id']]);
                } else {
                    $plan = IspPlans::get((int)DB::value('SELECT plan_id FROM isp_accounts WHERE id = :a', ['a' => $oa['account_id']]));
                    // nothing open to credit: the credit goes on the next invoice (idempotent on its charge key)
                    IspBilling::addCharge((int)$oa['account_id'], 'outage_credit', $why, -(float)$oa['credit_amount'], today(), 'OUTC-' . $o['id'] . '-' . $oa['account_id'], $plan['vat_mode'],
                        Accts::get('isp_service_credits'), 'outage', (int)$o['id']);
                }
                $n++;
            });
        }
        DB::update('isp_outages', ['credits_status' => 'posted'], 'id = :id', ['id' => $id]);
        Audit::log('post_credits', 'ispnet', 'isp_outage', $id, (string)$o['outage_no'], ['reason' => $n . ' service credit(s)']);
        return $n;
    }
}
