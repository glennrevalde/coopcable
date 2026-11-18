<?php
declare(strict_types=1);

/**
 * The physical network: OLTs and their PON ports, splitters, NAP / distribution terminals and their drop ports,
 * poles, cabinets, splice closures, cable segments and their cores. A subscriber is linked to the drop port of a NAP;
 * from there the chain goes up port by port (the port that feeds each node) to the OLT, so a customer can be traced
 * to the upstream infrastructure and an outage of any node lists the subscribers below it.
 */
final class IspInfra
{
    public const NODE_TYPES = ['olt' => 'OLT', 'olt_card' => 'OLT card / PON board', 'splitter' => 'Optical splitter', 'nap' => 'NAP / distribution terminal', 'fdp' => 'Fiber distribution point',
        'cabinet' => 'Network cabinet', 'splice_closure' => 'Splice closure', 'pole' => 'Utility pole', 'patch_panel' => 'Patch panel', 'switch' => 'Network switch', 'router' => 'Router / BNG',
        'coax_amp' => 'Coaxial amplifier', 'tap' => 'Coaxial tap'];
    public const PORT_TYPE = ['olt' => 'pon', 'olt_card' => 'pon', 'splitter' => 'out', 'nap' => 'drop', 'fdp' => 'out', 'patch_panel' => 'out', 'switch' => 'out', 'tap' => 'coax', 'coax_amp' => 'coax'];
    public const NODE_STATUSES = ['planned' => 'Planned', 'active' => 'In service', 'damaged' => 'Damaged', 'maintenance' => 'Under maintenance', 'retired' => 'Retired'];
    public const PORT_STATUSES = ['free' => 'Free', 'reserved' => 'Reserved', 'used' => 'In use', 'faulty' => 'Faulty'];
    public const SEGMENT_STATUSES = ['planned' => 'Planned', 'active' => 'In service', 'damaged' => 'Damaged', 'cut' => 'Cut', 'relocated' => 'Relocated', 'replaced' => 'Replaced', 'retired' => 'Retired'];
    public const CABLE_TYPES = ['fiber' => 'Fiber optic', 'drop' => 'Drop cable', 'coax' => 'Coaxial', 'utp' => 'UTP'];

    public static function node(int $id): ?array
    {
        return DB::row('SELECT * FROM isp_net_nodes WHERE id = :id', ['id' => $id]);
    }

    public static function portLabel(?int $portId): string
    {
        if (!$portId) {
            return '';
        }
        $r = DB::row('SELECT p.port_no, n.code, n.name FROM isp_net_ports p JOIN isp_net_nodes n ON n.id = p.node_id WHERE p.id = :p', ['p' => $portId]);
        return $r ? $r['code'] . ' port ' . $r['port_no'] : '';
    }

    /** Saves a node; a node with ports gets them numbered 1..N (more are added, ports in use are never removed) */
    public static function saveNode(array $f, ?int $id): int
    {
        if (trim((string)$f['code']) === '' || trim((string)$f['name']) === '' || !isset(self::NODE_TYPES[$f['node_type']])) {
            fail('Enter the code, the name and the type of the node.');
        }
        if (DB::value('SELECT id FROM isp_net_nodes WHERE code = :c AND id <> :id', ['c' => $f['code'], 'id' => (int)$id])) {
            fail('Another node already has the code ' . $f['code'] . '.');
        }
        if (!empty($f['upstream_port_id'])) {
            $up = DB::row('SELECT * FROM isp_net_ports WHERE id = :p', ['p' => $f['upstream_port_id']]) ?? fail('The feeding port was not found.');
            $other = DB::value('SELECT id FROM isp_net_nodes WHERE upstream_port_id = :p AND id <> :id', ['p' => $f['upstream_port_id'], 'id' => (int)$id]);
            if ($other || ($up['status'] === 'used' && $up['account_id'])) {
                fail('The feeding port is already used.');
            }
        }
        return DB::tx(function () use ($f, $id) {
            $isNew = !$id;
            foreach (['latitude', 'longitude'] as $k) {
                $f[$k] = ($f[$k] ?? '') === '' ? null : (float)$f[$k];
            }
            foreach (['parent_id', 'upstream_port_id', 'area_id', 'branch_id', 'device_id'] as $k) {
                $f[$k] = !empty($f[$k]) ? (int)$f[$k] : null;
            }
            $f['port_count'] = max(0, (int)($f['port_count'] ?? 0));
            if ($id) {
                $before = self::node($id);
                DB::update('isp_net_nodes', $f + ['updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
                if ($before['status'] !== $f['status']) {
                    DB::insert('isp_node_events', ['node_id' => $id, 'event_type' => $f['status'] === 'damaged' ? 'damaged' : ($f['status'] === 'active' ? 'repaired' : $f['status']),
                        'event_date' => today(), 'remarks' => 'Status ' . $before['status'] . ' -> ' . $f['status'], 'user_id' => Auth::id()]);
                }
            } else {
                $id = DB::insert('isp_net_nodes', $f);
                DB::insert('isp_node_events', ['node_id' => $id, 'event_type' => 'installed', 'event_date' => $f['installed_on'] ?? today(), 'remarks' => 'Added to the inventory of the network', 'user_id' => Auth::id()]);
            }
            if (!empty($f['upstream_port_id'])) {
                DB::run("UPDATE isp_net_ports SET status = 'used', label = :l WHERE id = :p", ['l' => mb_substr('feeds ' . $f['code'], 0, 60), 'p' => $f['upstream_port_id']]);
            }
            $type = self::PORT_TYPE[$f['node_type']] ?? null;
            if ($type && $f['port_count'] > 0) {
                $have = (int)DB::value('SELECT COALESCE(MAX(port_no), 0) FROM isp_net_ports WHERE node_id = :n', ['n' => $id]);
                for ($p = $have + 1; $p <= $f['port_count']; $p++) {
                    DB::insert('isp_net_ports', ['node_id' => $id, 'port_no' => $p, 'port_type' => $type, 'status' => 'free']);
                }
            }
            Audit::log($isNew ? 'create' : 'edit', 'ispnet', 'isp_net_node', $id, (string)$f['code']);
            return $id;
        });
    }

    /** Gives a drop port to a subscriber (exclusive: a port in use by someone else is refused) */
    public static function assignPort(int $portId, int $accountId, ?int $applicationId = null): void
    {
        DB::tx(function () use ($portId, $accountId, $applicationId) {
            $p = DB::row('SELECT p.*, n.node_type, n.code, n.status AS node_status FROM isp_net_ports p JOIN isp_net_nodes n ON n.id = p.node_id WHERE p.id = :p FOR UPDATE', ['p' => $portId]) ?? fail('Port not found.');
            if ($p['status'] === 'faulty' || $p['node_status'] === 'retired') {
                fail('Port ' . $p['code'] . '/' . $p['port_no'] . ' cannot be used (faulty or retired).');
            }
            if ($p['status'] === 'used' && (int)$p['account_id'] !== $accountId) {
                fail('Port ' . $p['code'] . '/' . $p['port_no'] . ' is already used by another subscriber.');
            }
            if ($p['status'] === 'reserved' && $p['application_id'] && $applicationId !== (int)$p['application_id'] && (int)DB::value('SELECT application_id FROM isp_accounts WHERE id = :a', ['a' => $accountId]) !== (int)$p['application_id']) {
                fail('Port ' . $p['code'] . '/' . $p['port_no'] . ' is reserved for another application.');
            }
            // the subscriber's previous port is freed
            $old = DB::value('SELECT port_id FROM isp_accounts WHERE id = :a', ['a' => $accountId]);
            if ($old && (int)$old !== $portId) {
                self::releasePort((int)$old);
            }
            DB::update('isp_net_ports', ['status' => 'used', 'account_id' => $accountId, 'application_id' => null, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $portId]);
            DB::run('UPDATE isp_accounts SET port_id = :p WHERE id = :a', ['p' => $portId, 'a' => $accountId]);
        });
    }

    public static function reservePort(int $portId, int $applicationId): void
    {
        DB::tx(function () use ($portId, $applicationId) {
            $p = DB::row('SELECT * FROM isp_net_ports WHERE id = :p FOR UPDATE', ['p' => $portId]) ?? fail('Port not found.');
            if (!in_array($p['status'], ['free'], true) && (int)$p['application_id'] !== $applicationId) {
                fail('That port is not free.');
            }
            DB::run('UPDATE isp_net_ports SET status = \'free\', application_id = NULL WHERE application_id = :a AND id <> :p AND status = \'reserved\'', ['a' => $applicationId, 'p' => $portId]);
            DB::update('isp_net_ports', ['status' => 'reserved', 'application_id' => $applicationId, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $portId]);
        });
    }

    public static function releasePort(int $portId): void
    {
        DB::update('isp_net_ports', ['status' => 'free', 'account_id' => null, 'application_id' => null, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $portId]);
        DB::run('UPDATE isp_accounts SET port_id = NULL WHERE port_id = :p', ['p' => $portId]);
    }

    /** Free drop ports (NAP), nearest area first: id => "NAP-001 port 3 (Area)" */
    public static function freePorts(?int $areaId = null, ?int $keep = null): array
    {
        $rows = DB::all("SELECT p.id, p.port_no, p.status, n.code, n.name, n.area_id, a.name AS area FROM isp_net_ports p JOIN isp_net_nodes n ON n.id = p.node_id LEFT JOIN isp_areas a ON a.id = n.area_id
                          WHERE n.node_type = 'nap' AND n.status = 'active' AND (p.status = 'free' OR p.id = :k) ORDER BY (n.area_id = :ar) DESC, n.code, p.port_no", ['k' => (int)$keep, 'ar' => (int)$areaId]);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['id']] = $r['code'] . ' port ' . $r['port_no'] . ($r['area'] ? ' (' . $r['area'] . ')' : '');
        }
        return $out;
    }

    /** The chain from the subscriber's drop port up to the OLT: [[node, port], ...] subscriber side first */
    public static function trace(int $accountId): array
    {
        $chain = [];
        $portId = (int)(DB::value('SELECT port_id FROM isp_accounts WHERE id = :a', ['a' => $accountId]) ?: 0);
        for ($i = 0; $portId && $i < 12; $i++) {
            $p = DB::row('SELECT * FROM isp_net_ports WHERE id = :p', ['p' => $portId]);
            if (!$p) {
                break;
            }
            $n = self::node((int)$p['node_id']);
            $chain[] = ['node' => $n, 'port' => $p];
            $portId = (int)($n['upstream_port_id'] ?? 0);
            if (!$portId && $n['parent_id']) {
                // a card inside an OLT, a splitter inside a cabinet
                $parent = self::node((int)$n['parent_id']);
                if ($parent) {
                    $chain[] = ['node' => $parent, 'port' => null];
                    $portId = (int)($parent['upstream_port_id'] ?? 0);
                }
            }
        }
        return $chain;
    }

    /** Every node below a node (fed by its ports, or inside it), the node itself included */
    public static function downstreamNodes(int $nodeId): array
    {
        $out = [$nodeId];
        $queue = [$nodeId];
        for ($guard = 0; $queue && $guard < 5000; $guard++) {
            $id = array_shift($queue);
            $children = array_map('intval', DB::column('SELECT n.id FROM isp_net_nodes n WHERE n.parent_id = :i OR n.upstream_port_id IN (SELECT id FROM isp_net_ports WHERE node_id = :i2)', ['i' => $id, 'i2' => $id]));
            foreach ($children as $c) {
                if (!in_array($c, $out, true)) {
                    $out[] = $c;
                    $queue[] = $c;
                }
            }
        }
        return $out;
    }

    /** The subscribers served by a node (through every node below it) */
    public static function affectedAccounts(int $nodeId): array
    {
        $nodes = self::downstreamNodes($nodeId);
        [$in, $p] = DB::in('n', $nodes);
        return array_map('intval', DB::column("SELECT DISTINCT p.account_id FROM isp_net_ports p JOIN isp_accounts a ON a.id = p.account_id
                                                WHERE p.node_id IN $in AND p.account_id IS NOT NULL AND a.service_status IN ('active', 'suspended', 'disconnected')", $p));
    }

    /** Ports by node: total, used, reserved, free, faulty, utilization % */
    public static function capacity(?string $type = null): array
    {
        $sql = "SELECT n.id, n.code, n.name, n.node_type, n.status, n.split_ratio, a.name AS area, COUNT(p.id) AS total,
                       SUM(p.status = 'used') AS used, SUM(p.status = 'reserved') AS reserved, SUM(p.status = 'free') AS free, SUM(p.status = 'faulty') AS faulty
                  FROM isp_net_nodes n LEFT JOIN isp_net_ports p ON p.node_id = n.id LEFT JOIN isp_areas a ON a.id = n.area_id
                 WHERE n.port_count > 0" . ($type ? ' AND n.node_type = :t' : '') . ' GROUP BY n.id, n.code, n.name, n.node_type, n.status, n.split_ratio, a.name ORDER BY n.node_type, n.code';
        $rows = DB::all($sql, $type ? ['t' => $type] : []);
        foreach ($rows as &$r) {
            $r['utilization'] = (int)$r['total'] ? round(((int)$r['used'] + (int)$r['reserved']) / (int)$r['total'] * 100, 1) : 0;
        }
        return $rows;
    }

    public static function saveSegment(array $f, ?int $id): int
    {
        if (trim((string)$f['code']) === '') {
            fail('Enter the code of the cable segment.');
        }
        if (DB::value('SELECT id FROM isp_cable_segments WHERE code = :c AND id <> :id', ['c' => $f['code'], 'id' => (int)$id])) {
            fail('Another segment already has the code ' . $f['code'] . '.');
        }
        foreach (['from_node_id', 'to_node_id', 'replaced_by_id'] as $k) {
            $f[$k] = !empty($f[$k]) ? (int)$f[$k] : null;
        }
        $f['length_m'] = ($f['length_m'] ?? '') === '' ? self::pathLength((string)($f['path'] ?? '')) : (float)$f['length_m'];
        return DB::tx(function () use ($f, $id) {
            if ($id) {
                $before = DB::row('SELECT * FROM isp_cable_segments WHERE id = :id', ['id' => $id]);
                DB::update('isp_cable_segments', $f + ['updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
                if ($before['status'] !== $f['status']) {
                    DB::insert('isp_node_events', ['segment_id' => $id, 'event_type' => in_array($f['status'], ['damaged', 'cut'], true) ? 'damaged' : $f['status'], 'event_date' => today(),
                        'remarks' => 'Status ' . $before['status'] . ' -> ' . $f['status'], 'user_id' => Auth::id()]);
                }
            } else {
                $id = DB::insert('isp_cable_segments', $f);
            }
            $have = (int)DB::value('SELECT COALESCE(MAX(core_no), 0) FROM isp_cable_cores WHERE segment_id = :s', ['s' => $id]);
            $colors = ['Blue', 'Orange', 'Green', 'Brown', 'Slate', 'White', 'Red', 'Black', 'Yellow', 'Violet', 'Rose', 'Aqua'];
            for ($c = $have + 1; $c <= (int)$f['core_count']; $c++) {
                DB::insert('isp_cable_cores', ['segment_id' => $id, 'core_no' => $c, 'color' => $colors[($c - 1) % 12], 'status' => 'free']);
            }
            return $id;
        });
    }

    /** The length of a "lat,lng;lat,lng" path in metres (haversine) */
    public static function pathLength(string $path): ?float
    {
        $pts = [];
        foreach (explode(';', $path) as $p) {
            $xy = array_map('trim', explode(',', $p));
            if (count($xy) === 2 && is_numeric($xy[0]) && is_numeric($xy[1])) {
                $pts[] = [(float)$xy[0], (float)$xy[1]];
            }
        }
        if (count($pts) < 2) {
            return null;
        }
        $m = 0.0;
        for ($i = 1; $i < count($pts); $i++) {
            [$la1, $lo1] = $pts[$i - 1];
            [$la2, $lo2] = $pts[$i];
            $dLa = deg2rad($la2 - $la1);
            $dLo = deg2rad($lo2 - $lo1);
            $a = sin($dLa / 2) ** 2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($dLo / 2) ** 2;
            $m += 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
        }
        return round($m, 2);
    }

    public static function nodeOptions(?array $types = null): array
    {
        $sql = 'SELECT id, CONCAT(code, \' - \', name) FROM isp_net_nodes';
        $p = [];
        if ($types) {
            [$in, $p] = DB::in('t', $types);
            $sql .= " WHERE node_type IN $in";
        }
        return DB::pairs($sql . ' ORDER BY code', $p);
    }

    /** Ports that can feed a new node: free ports of OLTs, splitters and FDPs */
    public static function feedPorts(?int $keep = null): array
    {
        return DB::pairs("SELECT p.id, CONCAT(n.code, ' ', UPPER(p.port_type), ' ', p.port_no) FROM isp_net_ports p JOIN isp_net_nodes n ON n.id = p.node_id
                           WHERE n.node_type IN ('olt', 'olt_card', 'splitter', 'fdp', 'patch_panel') AND (p.status = 'free' OR p.id = :k) ORDER BY n.code, p.port_no", ['k' => (int)$keep]);
    }
}
