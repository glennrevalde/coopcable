<?php
declare(strict_types=1);

/**
 * Work orders of the field: site surveys, installations, repairs, disconnections and reconnections done by hand,
 * relocations, equipment replacements and recoveries, network maintenance.
 *
 *   open -> scheduled (technician, date, slot) -> accepted -> in_progress -> completed -> verified (supervisor)
 *   cancelled (issued materials come back to the stockroom)
 *
 * Materials: issued from the network stockroom to the technician's van site (a stock transfer, no ledger effect);
 * at completion what was used is consumed from the van at average cost (Dr installation / maintenance cost,
 * Cr inventory) and what was not used goes back. Serialized units (ONU, router) move in the equipment register.
 */
final class IspWorkOrders
{
    public const TYPES = ['survey' => 'Site survey', 'installation' => 'Installation', 'repair' => 'Repair / troubleshooting', 'disconnection' => 'Disconnection (manual)',
        'reconnection' => 'Reconnection (manual)', 'relocation' => 'Relocation', 'replacement' => 'Equipment replacement', 'recovery' => 'Equipment recovery', 'maintenance' => 'Network maintenance'];
    public const STATUSES = ['open' => 'Open', 'scheduled' => 'Scheduled', 'accepted' => 'Accepted', 'in_progress' => 'In progress', 'completed' => 'Completed', 'verified' => 'Verified', 'cancelled' => 'Cancelled'];
    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];
    public const SLOTS = ['AM' => 'Morning (8-12)', 'PM' => 'Afternoon (1-5)', 'EVE' => 'Evening'];
    /** the checklist of each type: the items a completion needs ticked */
    public const CHECKLISTS = [
        'installation' => ['Drop cable laid and secured', 'NAP port connected and labelled', 'ONU / router installed and powered', 'Optical signal measured within range', 'Speed test done', 'Subscriber shown how to use the service', 'Site cleaned'],
        'repair' => ['Problem confirmed on site', 'Signal / cabling checked', 'Fault fixed', 'Service tested with the subscriber'],
        'replacement' => ['Old unit removed', 'New unit installed and configured', 'Service tested', 'Old unit returned to the stockroom'],
        'relocation' => ['Old drop removed', 'New drop installed', 'Signal measured', 'Service tested at the new address'],
        'recovery' => ['Units collected', 'Condition recorded', 'Subscriber acknowledgment signed'],
        'disconnection' => ['Service cut at the NAP / device', 'Confirmed no service'],
        'reconnection' => ['Service restored at the NAP / device', 'Confirmed working with the subscriber'],
        'survey' => ['Coverage confirmed', 'Nearest NAP and free port found', 'Cable route and length estimated'],
        'maintenance' => ['Work done', 'Network tested'],
    ];

    public static function get(int $id): ?array
    {
        return DB::row('SELECT w.*, a.account_no, a.account_name, t.name AS technician_name, t.user_id AS technician_user_id, t.site_id AS technician_site_id
                          FROM isp_work_orders w LEFT JOIN isp_accounts a ON a.id = w.account_id LEFT JOIN isp_technicians t ON t.id = w.technician_id WHERE w.id = :id', ['id' => $id]);
    }

    /** $f: wo_type, description, account_id, application_id, ticket_id, outage_id, net_op_id, priority, address, area_id, technician_id, scheduled_date, scheduled_slot */
    public static function create(array $f): int
    {
        if (!isset(self::TYPES[$f['wo_type'] ?? ''])) {
            fail('Choose the kind of work order.');
        }
        if (trim((string)($f['description'] ?? '')) === '') {
            fail('Describe the work to do.');
        }
        $row = array_intersect_key($f, array_flip(['wo_type', 'description', 'account_id', 'application_id', 'ticket_id', 'outage_id', 'net_op_id', 'priority', 'address', 'area_id',
            'technician_id', 'scheduled_date', 'scheduled_slot', 'site_id']));
        if (!empty($row['account_id']) && empty($row['address'])) {
            $a = IspAccounts::get((int)$row['account_id']);
            $row['address'] = $a['install_address'] ?? null;
            $row['area_id'] = $row['area_id'] ?? ($a['area_id'] ?? null);
        }
        $row['description'] = mb_substr((string)$row['description'], 0, 255);
        $row['priority'] = isset(self::PRIORITIES[$row['priority'] ?? '']) ? $row['priority'] : 'normal';
        $row['status'] = !empty($row['technician_id']) && !empty($row['scheduled_date']) ? 'scheduled' : 'open';
        $row['wo_no'] = Seq::next('isp_wo');
        $row['checklist'] = implode("\n", array_map(fn($c) => '0|' . $c, self::CHECKLISTS[$row['wo_type']] ?? []));
        $row['created_by'] = Auth::id();
        $id = DB::insert('isp_work_orders', $row);
        if (!empty($row['account_id'])) {
            IspAccounts::event((int)$row['account_id'], 'work_order', self::TYPES[$row['wo_type']] . ' work order ' . $row['wo_no'] . ': ' . $row['description'], 'work_order', $id);
        }
        Audit::log('create', 'ispfield', 'isp_work_order', $id, $row['wo_no'], ['reason' => $row['description']]);
        return $id;
    }

    /** Assigns the technician, the date and the time slot; the subscriber is told */
    public static function schedule(int $id, int $technicianId, string $date, string $slot, ?int $routeSeq = null): void
    {
        DB::tx(function () use ($id, $technicianId, $date, $slot, $routeSeq) {
            $w = self::get($id) ?? fail('Work order not found.');
            if (in_array($w['status'], ['completed', 'verified', 'cancelled'], true)) {
                fail('Work order ' . $w['wo_no'] . ' is ' . $w['status'] . '.');
            }
            if (!valid_date($date)) {
                fail('Enter the date.');
            }
            $t = DB::row('SELECT * FROM isp_technicians WHERE id = :t AND is_active = 1', ['t' => $technicianId]) ?? fail('Choose an active technician.');
            if ($w['wo_type'] === 'installation' && $w['application_id']) {
                $app = DB::row('SELECT status FROM isp_applications WHERE id = :a', ['a' => $w['application_id']]);
                if ($app && !in_array($app['status'], ['approved', 'for_installation'], true)) {
                    fail('The installation cannot be scheduled before the application is approved (it is ' . str_replace('_', ' ', $app['status']) . ').');
                }
            }
            DB::update('isp_work_orders', ['technician_id' => $technicianId, 'scheduled_date' => $date, 'scheduled_slot' => $slot, 'route_seq' => $routeSeq,
                'status' => in_array($w['status'], ['open', 'scheduled'], true) ? 'scheduled' : $w['status'], 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            if ($w['account_id']) {
                $event = $w['wo_type'] === 'installation' ? 'installation_schedule' : 'technician_dispatched';
                IspNotify::event($event, (int)$w['account_id'], ['wo_no' => $w['wo_no'], 'date' => fdate($date), 'slot' => self::SLOTS[$slot] ?? $slot, 'technician' => $t['name']], 'WO-' . $id . '-' . $date);
            }
            Audit::log('schedule', 'ispfield', 'isp_work_order', $id, (string)$w['wo_no'], ['reason' => $t['name'] . ' on ' . $date . ' ' . $slot]);
        });
    }

    /** The technician's own steps: accept, start */
    public static function step(int $id, string $to): void
    {
        $w = self::get($id) ?? fail('Work order not found.');
        $allowed = ['accepted' => ['scheduled', 'open'], 'in_progress' => ['accepted', 'scheduled', 'open']];
        if (!in_array($w['status'], $allowed[$to] ?? [], true)) {
            fail('Work order ' . $w['wo_no'] . ' is ' . str_replace('_', ' ', $w['status']) . '.');
        }
        self::assertOwn($w);
        DB::update('isp_work_orders', ['status' => $to, $to === 'accepted' ? 'accepted_at' : 'started_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        if ($to === 'in_progress' && $w['ticket_id']) {
            DB::run("UPDATE isp_tickets SET status = 'in_progress' WHERE id = :t AND status IN ('assigned', 'classified', 'new')", ['t' => $w['ticket_id']]);
        }
    }

    /** A technician only works on own jobs; supervisors (edit right on Work Orders & Dispatch) on any */
    private static function assertOwn(array $w): void
    {
        $mine = IspSetup::myTechnicianId();
        if ($mine && (int)$w['technician_id'] !== $mine && !Access::can('ispfield.workorders', 'approve') && !Access::isAdmin()) {
            fail('Work order ' . $w['wo_no'] . ' is assigned to another technician.');
        }
    }

    public static function stockSite(): int
    {
        $s = (int)Settings::get('isp_stock_site_id', '0');
        if (!$s) {
            $s = (int)(DB::value("SELECT id FROM sites WHERE is_active = 1 AND site_type IN ('warehouse', 'stockroom') ORDER BY site_type = 'stockroom' DESC, id LIMIT 1") ?: 0);
        }
        if (!$s) {
            fail('Choose the network stockroom (ISP Setup > Billing & Disconnection Policies > Stockroom).');
        }
        return $s;
    }

    /**
     * Issues materials to the job: $lines [[item_id, qty, serial(optional)]]. From the stockroom to the technician's
     * van site; a technician without a van site works straight from the stockroom.
     */
    public static function issue(int $id, array $lines, ?string $date = null): ?int
    {
        return DB::tx(function () use ($id, $lines, $date) {
            $w = self::get($id) ?? fail('Work order not found.');
            if (in_array($w['status'], ['completed', 'verified', 'cancelled'], true)) {
                fail('Materials are not issued to a ' . $w['status'] . ' work order.');
            }
            if (!$w['technician_id']) {
                fail('Assign the technician first: the materials are issued to the technician.');
            }
            $from = self::stockSite();
            $to = (int)($w['technician_site_id'] ?: 0);
            $date ??= today();
            $moves = [];
            foreach ($lines as $l) {
                [$itemId, $qty] = [(int)$l[0], (float)$l[1]];
                $serial = trim((string)($l[2] ?? ''));
                if ($itemId <= 0 || $qty <= 0) {
                    continue;
                }
                $item = DB::row('SELECT * FROM items WHERE id = :i', ['i' => $itemId]) ?? fail('Item not found.');
                if (!Stock::isTracked($item['item_type'])) {
                    fail($item['code'] . ' is not a stocked item.');
                }
                $onHand = Stock::onHand($itemId, $from);
                if ($onHand + 0.0001 < $qty) {
                    fail('Only ' . qty($onHand) . ' of ' . $item['code'] . ' is in the stockroom.');
                }
                $mid = DB::insert('isp_wo_materials', ['work_order_id' => $id, 'item_id' => $itemId, 'qty_issued' => $qty, 'serial_no' => $serial !== '' ? strtoupper($serial) : null,
                    'unit_cost' => (float)$item['avg_cost'], 'status' => 'issued']);
                $moves[] = [$itemId, $qty, $item['code'] . ' for ' . $w['wo_no'], $mid];
                if ($serial !== '') {
                    $eq = IspEquipment::register($itemId, $serial, ['site_id' => $from, 'unit_cost' => (float)$item['avg_cost']]);
                    IspEquipment::move($eq, 'with_technician', 'Issued for ' . $w['wo_no'], null, $id, ['technician_id' => (int)$w['technician_id'], 'site_id' => $to ?: $from]);
                }
            }
            if (!$moves) {
                fail('Enter the items and quantities to issue.');
            }
            $txn = null;
            if ($to && $to !== $from) {
                $txn = IspPosting::postIssue('ISS-' . $id . '-' . (int)DB::value('SELECT COUNT(*) FROM isp_wo_materials WHERE work_order_id = :w', ['w' => $id]), $from, $to,
                    array_map(fn($m) => [$m[0], $m[1], $m[2]], $moves), $date, 'ISS-' . $w['wo_no'], 'Materials issued to ' . $w['technician_name'] . ' for ' . $w['wo_no'], $w['account_id'] ? (int)$w['account_id'] : null);
                foreach ($moves as $m) {
                    DB::run('UPDATE isp_wo_materials SET issue_txn_id = :t WHERE id = :id', ['t' => $txn, 'id' => $m[3]]);
                }
            }
            Audit::log('issue', 'ispfield', 'isp_work_order', $id, (string)$w['wo_no'], ['reason' => count($moves) . ' material line(s) issued']);
            return $txn;
        });
    }

    /**
     * Completes the job. $d: checklist (ticked items), materials [material id => [used, returned]], cable_used_m,
     * onu_serial, router_serial, port_id, rx_power_dbm, tx_power_dbm, speed_test_down, speed_test_up, labor_hours,
     * labor_cost, travel_expense, findings, remarks, customer_accepted_by, expense_account_id (pays the labor / travel),
     * recovered [equipment id => recovered | damaged | missing], net_done (manual network work done: 1 / 0)
     */
    public static function complete(int $id, array $d): void
    {
        DB::tx(function () use ($id, $d) {
            $w = self::get($id) ?? fail('Work order not found.');
            if (!in_array($w['status'], ['scheduled', 'accepted', 'in_progress', 'open'], true)) {
                fail('Work order ' . $w['wo_no'] . ' is ' . $w['status'] . '.');
            }
            self::assertOwn($w);
            // the checklist: every item ticked
            $items = self::CHECKLISTS[$w['wo_type']] ?? [];
            $ticked = array_map('intval', (array)($d['checklist'] ?? []));
            $missing = array_values(array_diff(array_keys($items), $ticked));
            if ($missing && empty($d['partial_ok'])) {
                fail('Tick the checklist: ' . implode('; ', array_map(fn($i) => $items[$i], $missing)) . '.');
            }
            $set = ['checklist' => implode("\n", array_map(fn($i, $c) => (in_array($i, $ticked, true) ? '1' : '0') . '|' . $c, array_keys($items), $items))];
            foreach (['cable_used_m', 'rx_power_dbm', 'tx_power_dbm', 'speed_test_down', 'speed_test_up', 'labor_hours'] as $k) {
                $set[$k] = ($d[$k] ?? '') === '' ? null : (float)$d[$k];
            }
            foreach (['labor_cost', 'travel_expense'] as $k) {
                $set[$k] = r2((float)($d[$k] ?? 0));
            }
            foreach (['onu_serial', 'router_serial'] as $k) {
                $set[$k] = trim((string)($d[$k] ?? '')) !== '' ? strtoupper(trim((string)$d[$k])) : null;
            }
            foreach (['findings', 'remarks', 'customer_accepted_by'] as $k) {
                $set[$k] = trim((string)($d[$k] ?? '')) !== '' ? trim((string)$d[$k]) : null;
            }
            if ($w['wo_type'] === 'installation') {
                if (!$set['customer_accepted_by']) {
                    fail('Enter the name of the subscriber (or representative) who accepted the installation.');
                }
                if ($set['cable_used_m'] === null) {
                    fail('Enter the drop cable length used.');
                }
                if ($set['rx_power_dbm'] !== null && ($set['rx_power_dbm'] > -8 || $set['rx_power_dbm'] < -27)) {
                    fail('The optical signal ' . $set['rx_power_dbm'] . ' dBm is out of the acceptable range (-8 to -27 dBm). Fix the line before completing.');
                }
            }
            if ($set['customer_accepted_by']) {
                $set['customer_accepted_at'] = date('Y-m-d H:i:s');
            }
            if (!empty($d['port_id']) && $w['account_id']) {
                IspInfra::assignPort((int)$d['port_id'], (int)$w['account_id'], $w['application_id'] ? (int)$w['application_id'] : null);
                $set['port_id'] = (int)$d['port_id'];
            }
            // materials: what was used is consumed from where it was issued to, the rest goes back to the stockroom
            $mats = DB::all("SELECT m.*, i.code, i.item_type FROM isp_wo_materials m JOIN items i ON i.id = m.item_id WHERE m.work_order_id = :w AND m.status = 'issued'", ['w' => $id]);
            $consume = [];
            $return = [];
            foreach ($mats as $m) {
                $u = $d['materials'][(int)$m['id']] ?? [null, null];
                $used = $u[0] === null || $u[0] === '' ? (float)$m['qty_issued'] : (float)$u[0];
                $used = max(0.0, min((float)$m['qty_issued'], $used));
                $ret = r2((float)$m['qty_issued'] - $used);
                DB::update('isp_wo_materials', ['qty_used' => $used, 'qty_returned' => $ret, 'status' => $used > 0 ? 'consumed' : 'returned'], 'id = :id', ['id' => $m['id']]);
                if ($used > 0) {
                    $consume[] = [(int)$m['item_id'], $used, $m['code'] . ' used on ' . $w['wo_no']];
                }
                if ($ret > 0) {
                    $return[] = [(int)$m['item_id'], $ret, $m['code'] . ' returned from ' . $w['wo_no']];
                }
                // a serialized unit used here is installed at the subscriber
                if ($m['serial_no'] && $w['account_id']) {
                    $eq = DB::value('SELECT id FROM isp_equipment WHERE item_id = :i AND serial_no = :s', ['i' => $m['item_id'], 's' => $m['serial_no']]);
                    if ($eq) {
                        $used > 0 ? IspEquipment::move((int)$eq, 'installed', 'Installed by ' . $w['wo_no'], (int)$w['account_id'], $id, ['site_id' => null])
                            : IspEquipment::move((int)$eq, 'in_stock', 'Returned unused from ' . $w['wo_no'], null, $id, ['site_id' => self::stockSite(), 'technician_id' => null]);
                    }
                }
            }
            $van = (int)($w['technician_site_id'] ?: 0);
            $site = $van ?: self::stockSite();
            $acct = $w['account_id'] ? IspAccounts::get((int)$w['account_id']) : null;
            if ($consume) {
                $expense = Accts::get(in_array($w['wo_type'], ['installation', 'relocation'], true) ? 'isp_install_expense' : 'isp_maint_expense');
                $txn = IspPosting::postConsumption('USE-' . $id, $site, $expense, $consume, today(), 'USE-' . $w['wo_no'], 'Materials used on ' . $w['wo_no'] . ' (' . self::TYPES[$w['wo_type']] . ')', $acct);
                DB::run("UPDATE isp_wo_materials SET issue_txn_id = COALESCE(issue_txn_id, :t) WHERE work_order_id = :w AND status = 'consumed'", ['t' => $txn, 'w' => $id]);
            }
            if ($return && $van && $van !== self::stockSite()) {
                $rt = IspPosting::postIssue('RET-' . $id, $van, self::stockSite(), $return, today(), 'RET-' . $w['wo_no'], 'Materials returned from ' . $w['wo_no'], $acct['id'] ?? null);
                DB::run("UPDATE isp_wo_materials SET return_txn_id = :t WHERE work_order_id = :w AND qty_returned > 0", ['t' => $rt, 'w' => $id]);
            }
            // labor and travel paid out (optional): Dr field labor, Cr the account it was paid from
            $cost = r2($set['labor_cost'] + $set['travel_expense']);
            if ($cost > 0 && !empty($d['expense_account_id'])) {
                $own = IspPosting::owning();
                $set['expense_txn_id'] = Txn::save('journal', ['txn_date' => today(), 'txn_no' => 'LAB-' . $w['wo_no'], 'business_unit_id' => IspSetup::unitId(),
                    'class_id' => $acct ? IspSetup::classOfBranch($acct['branch_id'] ? (int)$acct['branch_id'] : null) : null, 'memo' => 'Labor and travel of ' . $w['wo_no']], [
                    ['line_kind' => 'expense', 'account_id' => Accts::get('isp_labor_expense'), 'amount' => $cost, 'description' => 'Labor and travel ' . $w['wo_no']],
                    ['line_kind' => 'expense', 'account_id' => (int)$d['expense_account_id'], 'amount' => -$cost, 'description' => 'Labor and travel ' . $w['wo_no']],
                ], null, ['skip_policy' => true]);
            }
            // equipment recovery
            foreach ((array)($d['recovered'] ?? []) as $eqId => $how) {
                if (!in_array($how, ['recovered', 'damaged', 'missing'], true)) {
                    continue;
                }
                $e = IspEquipment::get((int)$eqId);
                if (!$e || (int)$e['account_id'] !== (int)$w['account_id']) {
                    continue;
                }
                IspEquipment::move((int)$eqId, $how, 'Recovery ' . $w['wo_no'], null, $id, $how === 'recovered' ? ['site_id' => self::stockSite()] : []);
                if ($how === 'missing' && $acct) {
                    IspBilling::addCharge((int)$acct['id'], 'equipment', 'Unreturned equipment ' . $e['item_code'] . ' ' . $e['serial_no'], max((float)$e['unit_cost'], (float)$e['deposit']),
                        today(), 'EQM-' . $eqId, 'inclusive', Accts::get('isp_other_revenue'), 'equipment', (int)$eqId);
                }
            }
            DB::update('isp_work_orders', $set + ['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            if ($w['account_id']) {
                IspAccounts::event((int)$w['account_id'], 'work_order', self::TYPES[$w['wo_type']] . ' ' . $w['wo_no'] . ' completed' . ($set['findings'] ? ': ' . $set['findings'] : ''), 'work_order', $id);
            }
            if ($w['wo_type'] === 'installation' && $w['application_id']) {
                IspApplications::move((int)$w['application_id'], 'installed', 'Installed by ' . $w['wo_no']);
                IspApplications::move((int)$w['application_id'], 'for_activation', 'Ready for activation');
            }
            if ($w['net_op_id']) {
                IspNetwork::confirmManual((int)$w['net_op_id'], ($d['net_done'] ?? '1') === '1', 'Work order ' . $w['wo_no'] . ' completed' . ($set['remarks'] ? ': ' . $set['remarks'] : ''));
            }
            if ($w['ticket_id']) {
                IspTickets::note((int)$w['ticket_id'], 'Work order ' . $w['wo_no'] . ' completed: ' . ($set['findings'] ?? 'done'), null, false);
            }
            Audit::log('complete', 'ispfield', 'isp_work_order', $id, (string)$w['wo_no'], ['reason' => $set['findings'] ?? null]);
        });
    }

    public static function verify(int $id, string $note): void
    {
        $w = self::get($id) ?? fail('Work order not found.');
        if ($w['status'] !== 'completed') {
            fail('Only a completed work order is verified.');
        }
        if (IspSetup::myTechnicianId() && (int)$w['technician_id'] === IspSetup::myTechnicianId() && !Access::isAdmin()) {
            fail('A supervisor other than the technician verifies the work.');
        }
        DB::update('isp_work_orders', ['status' => 'verified', 'verified_by' => Auth::id(), 'verified_at' => date('Y-m-d H:i:s'),
            'remarks' => trim(($w['remarks'] ?? '') . ($note !== '' ? "\nVerified: " . $note : ''))], 'id = :id', ['id' => $id]);
        Audit::log('verify', 'ispfield', 'isp_work_order', $id, (string)$w['wo_no'], ['reason' => $note]);
    }

    public static function cancel(int $id, string $reason): void
    {
        DB::tx(function () use ($id, $reason) {
            $w = self::get($id) ?? fail('Work order not found.');
            if (in_array($w['status'], ['completed', 'verified', 'cancelled'], true)) {
                fail('Work order ' . $w['wo_no'] . ' is ' . $w['status'] . '.');
            }
            if (trim($reason) === '') {
                fail('Enter the reason.');
            }
            // issued materials come back
            $mats = DB::all("SELECT m.*, i.code FROM isp_wo_materials m JOIN items i ON i.id = m.item_id WHERE m.work_order_id = :w AND m.status = 'issued'", ['w' => $id]);
            $van = (int)($w['technician_site_id'] ?: 0);
            if ($mats && $van && $van !== self::stockSite()) {
                IspPosting::postIssue('RET-' . $id, $van, self::stockSite(), array_map(fn($m) => [(int)$m['item_id'], (float)$m['qty_issued'], $m['code'] . ' returned (cancelled ' . $w['wo_no'] . ')'], $mats),
                    today(), 'RET-' . $w['wo_no'], 'Materials returned: ' . $w['wo_no'] . ' cancelled', $w['account_id'] ? (int)$w['account_id'] : null);
            }
            DB::run("UPDATE isp_wo_materials SET status = 'returned', qty_returned = qty_issued WHERE work_order_id = :w AND status = 'issued'", ['w' => $id]);
            foreach (DB::all("SELECT e.id FROM isp_equipment e WHERE e.work_order_id = :w AND e.status = 'with_technician'", ['w' => $id]) as $e) {
                IspEquipment::move((int)$e['id'], 'in_stock', 'Work order cancelled', null, $id, ['site_id' => self::stockSite(), 'technician_id' => null]);
            }
            DB::update('isp_work_orders', ['status' => 'cancelled', 'cancel_reason' => mb_substr($reason, 0, 255), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            if ($w['net_op_id']) {
                IspNetwork::confirmManual((int)$w['net_op_id'], false, 'Work order cancelled: ' . $reason);
            }
            Audit::log('cancel', 'ispfield', 'isp_work_order', $id, (string)$w['wo_no'], ['reason' => $reason]);
        });
    }

    /** The checklist as [[done, text], ...] */
    public static function checklist(array $w): array
    {
        $out = [];
        foreach (preg_split('/\R/', (string)$w['checklist']) as $line) {
            if (str_contains($line, '|')) {
                [$done, $text] = explode('|', $line, 2);
                $out[] = [$done === '1', $text];
            }
        }
        return $out;
    }
}
