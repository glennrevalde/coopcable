<?php
declare(strict_types=1);

/**
 * Serialized customer equipment (ONUs / ONTs, routers, set-top boxes): kept apart from the bulk stock. Each unit's
 * ownership (the company's, lent or rented; or sold to the customer), where it is and its whole history.
 *
 *   in_stock -> with_technician -> installed -> for_recovery -> recovered | damaged | missing -> in_stock / disposed
 */
final class IspEquipment
{
    public const STATUSES = ['in_stock' => 'In the stockroom', 'with_technician' => 'With a technician', 'installed' => 'Installed at a subscriber', 'for_recovery' => 'To be recovered',
        'recovered' => 'Recovered', 'damaged' => 'Damaged', 'missing' => 'Missing', 'disposed' => 'Disposed'];
    public const OWNERSHIP = ['company' => 'Company-owned (lent / rented)', 'customer' => 'Sold to the customer'];

    public static function get(int $id): ?array
    {
        return DB::row('SELECT e.*, i.code AS item_code, i.name AS item_name, a.account_no FROM isp_equipment e JOIN items i ON i.id = e.item_id LEFT JOIN isp_accounts a ON a.id = e.account_id
                         WHERE e.id = :id', ['id' => $id]);
    }

    public static function register(int $itemId, string $serial, array $f = []): int
    {
        $serial = strtoupper(trim($serial));
        if ($serial === '') {
            fail('Enter the serial number.');
        }
        if ($have = DB::value('SELECT id FROM isp_equipment WHERE item_id = :i AND serial_no = :s', ['i' => $itemId, 's' => $serial])) {
            return (int)$have;
        }
        $id = DB::insert('isp_equipment', ['item_id' => $itemId, 'serial_no' => $serial, 'mac_address' => ($f['mac_address'] ?? '') ?: null, 'ownership' => $f['ownership'] ?? 'company',
            'status' => $f['status'] ?? 'in_stock', 'site_id' => $f['site_id'] ?? null, 'technician_id' => $f['technician_id'] ?? null,
            'unit_cost' => $f['unit_cost'] ?? (float)DB::value('SELECT avg_cost FROM items WHERE id = :i', ['i' => $itemId]), 'notes' => $f['notes'] ?? null]);
        DB::insert('isp_equipment_log', ['equipment_id' => $id, 'from_status' => null, 'to_status' => $f['status'] ?? 'in_stock', 'remarks' => 'Registered', 'user_id' => Auth::id()]);
        return $id;
    }

    public static function move(int $id, string $to, string $remarks, ?int $accountId = null, ?int $woId = null, array $set = []): void
    {
        if (!isset(self::STATUSES[$to])) {
            fail('Unknown equipment status.');
        }
        $e = DB::row('SELECT * FROM isp_equipment WHERE id = :id FOR UPDATE', ['id' => $id]) ?? fail('Equipment not found.');
        if ($to === 'installed' && $e['status'] === 'installed' && (int)$e['account_id'] !== (int)$accountId) {
            fail('Unit ' . $e['serial_no'] . ' is installed at another subscriber.');
        }
        $upd = $set + ['status' => $to, 'updated_at' => date('Y-m-d H:i:s')];
        if ($accountId !== null) {
            $upd['account_id'] = $accountId;
        }
        if ($woId !== null) {
            $upd['work_order_id'] = $woId;
        }
        if ($to === 'installed') {
            $upd['installed_at'] = date('Y-m-d H:i:s');
            $upd['technician_id'] = null;
        }
        if (in_array($to, ['recovered', 'in_stock'], true)) {
            $upd['recovered_at'] = $to === 'recovered' ? date('Y-m-d H:i:s') : $e['recovered_at'];
            if ($to === 'in_stock') {
                $upd['account_id'] = null;
            }
        }
        DB::update('isp_equipment', $upd, 'id = :id', ['id' => $id]);
        $logId = DB::insert('isp_equipment_log', ['equipment_id' => $id, 'from_status' => $e['status'], 'to_status' => $to, 'account_id' => $accountId ?? $e['account_id'], 'work_order_id' => $woId,
            'remarks' => mb_substr($remarks, 0, 255), 'user_id' => Auth::id()]);
        // a company unit that comes back from a subscriber was charged to installation cost when it was installed: it
        // goes back on hand in the stockroom, at its cost, and the cost is reversed
        if ($to === 'recovered' && in_array($e['status'], ['installed', 'for_recovery'], true) && $e['ownership'] === 'company') {
            $site = (int)($upd['site_id'] ?? 0) ?: (int)($e['site_id'] ?? 0) ?: IspWorkOrders::stockSite();
            $acctRow = ($accountId ?? $e['account_id']) ? IspAccounts::get((int)($accountId ?? $e['account_id'])) : null;
            IspPosting::postReturn('EQR-' . $id . '-' . $logId, $site, Accts::get('isp_maint_expense'), [[(int)$e['item_id'], 1, (float)$e['unit_cost'], 'Recovered ' . $e['serial_no']]],
                today(), 'EQR-' . $e['serial_no'], 'Equipment ' . $e['serial_no'] . ' recovered' . ($acctRow ? ' from ' . $acctRow['account_no'] : '') . ($remarks !== '' ? ': ' . $remarks : ''), $acctRow);
            DB::update('isp_equipment', ['site_id' => $site], 'id = :id', ['id' => $id]);
        }
        if ($accountId ?? $e['account_id']) {
            IspAccounts::event((int)($accountId ?? $e['account_id']), 'equipment', 'Equipment ' . $e['serial_no'] . ': ' . (self::STATUSES[$to] ?? $to) . ($remarks !== '' ? ' (' . $remarks . ')' : ''), 'equipment', $id);
        }
    }

    public static function ofAccount(int $accountId): array
    {
        return DB::all('SELECT e.*, i.code AS item_code, i.name AS item_name FROM isp_equipment e JOIN items i ON i.id = e.item_id WHERE e.account_id = :a ORDER BY e.id', ['a' => $accountId]);
    }

    /** Units free in a site (or with a technician) of an item: id => serial */
    public static function available(?int $itemId = null, ?int $technicianId = null): array
    {
        $sql = "SELECT e.id, CONCAT(i.code, ' ', e.serial_no) FROM isp_equipment e JOIN items i ON i.id = e.item_id WHERE e.status IN ('in_stock', 'with_technician')";
        $p = [];
        if ($itemId) {
            $sql .= ' AND e.item_id = :i';
            $p['i'] = $itemId;
        }
        if ($technicianId) {
            $sql .= " AND (e.technician_id = :t OR e.status = 'in_stock')";
            $p['t'] = $technicianId;
        }
        return DB::pairs($sql . ' ORDER BY i.code, e.serial_no', $p);
    }
}
