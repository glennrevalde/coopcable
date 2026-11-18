<?php
declare(strict_types=1);

/**
 * Support tickets (Workflow E): New -> Classified -> Assigned -> In Progress -> On Hold -> Resolved -> Verified -> Closed.
 * SLA deadlines by priority, the technician and the repair work order, the root cause and the repair, the materials
 * (on the work order), the customer's confirmation and rating, escalation of overdue and recurring complaints.
 * A ticket is not resolved without its resolution, nor closed without being resolved.
 */
final class IspTickets
{
    public const TYPES = ['no_internet' => 'No internet connection', 'slow' => 'Slow connection', 'intermittent' => 'Intermittent connectivity', 'damaged_cable' => 'Damaged cable',
        'broken_device' => 'Broken modem or router', 'low_signal' => 'Low optical signal', 'outage' => 'Network outage', 'billing' => 'Billing complaint',
        'wrong_disconnection' => 'Incorrect disconnection', 'reconnection_failure' => 'Reconnection failure', 'relocation' => 'Relocation request', 'plan_change' => 'Plan upgrade or downgrade',
        'equipment_replacement' => 'Equipment replacement', 'other' => 'Other concern'];
    public const TECHNICAL = ['no_internet', 'slow', 'intermittent', 'damaged_cable', 'broken_device', 'low_signal', 'outage', 'reconnection_failure', 'equipment_replacement', 'relocation'];
    public const STATUSES = ['new' => 'New', 'classified' => 'Classified', 'assigned' => 'Assigned', 'in_progress' => 'In progress', 'on_hold' => 'On hold', 'resolved' => 'Resolved',
        'verified' => 'Verified', 'closed' => 'Closed'];
    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'critical' => 'Critical'];
    public const SOURCES = ['phone' => 'Phone', 'walkin' => 'Walk-in', 'portal' => 'Customer portal', 'sms' => 'SMS', 'email' => 'E-mail', 'field' => 'Field', 'system' => 'System'];
    private const MOVES = [
        'new' => ['classified', 'assigned', 'in_progress', 'resolved'],
        'classified' => ['assigned', 'in_progress', 'resolved', 'on_hold'],
        'assigned' => ['in_progress', 'on_hold', 'resolved'],
        'in_progress' => ['on_hold', 'resolved'],
        'on_hold' => ['in_progress', 'assigned', 'resolved'],
        'resolved' => ['verified', 'in_progress', 'closed'],
        'verified' => ['closed', 'in_progress'],
        'closed' => [],
    ];

    public static function get(int $id): ?array
    {
        return DB::row('SELECT t.*, a.account_no, a.account_name, a.install_address, tc.name AS technician_name, u.full_name AS assigned_name FROM isp_tickets t
                          LEFT JOIN isp_accounts a ON a.id = t.account_id LEFT JOIN isp_technicians tc ON tc.id = t.technician_id LEFT JOIN users u ON u.id = t.assigned_user_id WHERE t.id = :id', ['id' => $id]);
    }

    /** The statuses a ticket may move to from $from */
    public static function moves(string $from): array
    {
        return self::MOVES[$from] ?? [];
    }

    public static function slaHours(string $priority): int
    {
        return max(1, IspSetup::int('isp_sla_' . (isset(self::PRIORITIES[$priority]) ? $priority : 'normal') . '_hours'));
    }

    public static function create(array $f): int
    {
        if (!isset(self::TYPES[$f['ticket_type'] ?? ''])) {
            fail('Choose the kind of concern.');
        }
        if (trim((string)($f['subject'] ?? '')) === '') {
            fail('Enter the subject.');
        }
        $prio = isset(self::PRIORITIES[$f['priority'] ?? '']) ? $f['priority'] : (in_array($f['ticket_type'], ['no_internet', 'outage', 'wrong_disconnection', 'reconnection_failure'], true) ? 'high' : 'normal');
        $outage = null;
        if (!empty($f['account_id'])) {
            $outage = DB::value("SELECT o.id FROM isp_outages o JOIN isp_outage_accounts oa ON oa.outage_id = o.id WHERE oa.account_id = :a AND o.status IN ('suspected', 'verified', 'in_repair') ORDER BY o.id DESC LIMIT 1",
                ['a' => $f['account_id']]);
        }
        $id = DB::insert('isp_tickets', ['ticket_no' => Seq::next('isp_ticket'), 'account_id' => !empty($f['account_id']) ? (int)$f['account_id'] : null, 'ticket_type' => $f['ticket_type'],
            'subject' => mb_substr(trim((string)$f['subject']), 0, 160), 'description' => ($f['description'] ?? '') ?: null, 'priority' => $prio, 'status' => 'new',
            'source' => isset(self::SOURCES[$f['source'] ?? '']) ? $f['source'] : 'phone', 'ntc_complaint' => !empty($f['ntc_complaint']) ? 1 : 0,
            'sla_due_at' => date('Y-m-d H:i:s', time() + self::slaHours($prio) * 3600), 'outage_id' => $outage ?: null, 'portal_user_id' => $f['portal_user_id'] ?? null, 'created_by' => Auth::id()]);
        $no = (string)DB::value('SELECT ticket_no FROM isp_tickets WHERE id = :i', ['i' => $id]);
        if (!empty($f['account_id'])) {
            IspAccounts::event((int)$f['account_id'], 'complaint', 'Ticket ' . $no . ': ' . self::TYPES[$f['ticket_type']] . ' - ' . $f['subject'], 'ticket', $id);
            // recurring: three or more tickets in 30 days escalate at once
            $recent = (int)DB::value('SELECT COUNT(*) FROM isp_tickets WHERE account_id = :a AND created_at >= :d', ['a' => $f['account_id'], 'd' => date('Y-m-d H:i:s', time() - 30 * 86400)]);
            if ($recent >= 3) {
                DB::update('isp_tickets', ['escalation_level' => 1, 'priority' => $prio === 'critical' ? 'critical' : 'high'], 'id = :id', ['id' => $id]);
                self::note($id, 'Escalated: recurring complaint (' . $recent . ' tickets in 30 days).', null, false);
            }
        }
        if ($outage) {
            self::note($id, 'Linked to the network outage ' . DB::value('SELECT outage_no FROM isp_outages WHERE id = :o', ['o' => $outage]) . ' affecting this account.', null, true);
        }
        Audit::log('create', 'ispfield', 'isp_ticket', $id, $no);
        return $id;
    }

    public static function note(int $id, string $text, ?string $toStatus, bool $public, ?int $portalUser = null): void
    {
        $t = DB::row('SELECT status FROM isp_tickets WHERE id = :id', ['id' => $id]);
        DB::insert('isp_ticket_notes', ['ticket_id' => $id, 'note' => $text, 'from_status' => $toStatus ? $t['status'] : null, 'to_status' => $toStatus, 'is_public' => $public ? 1 : 0,
            'user_id' => $portalUser ? null : Auth::id(), 'portal_user_id' => $portalUser]);
    }

    /** Moves the ticket. $f: note, root_cause, resolution, technician_id, assigned_user_id, ticket_type, priority, rating, feedback */
    public static function move(int $id, string $to, array $f = []): void
    {
        DB::tx(function () use ($id, $to, $f) {
            $t = DB::row('SELECT * FROM isp_tickets WHERE id = :id FOR UPDATE', ['id' => $id]) ?? fail('Ticket not found.');
            if (!in_array($to, self::MOVES[$t['status']] ?? [], true)) {
                fail('Ticket ' . $t['ticket_no'] . ' cannot go from ' . self::STATUSES[$t['status']] . ' to ' . (self::STATUSES[$to] ?? $to) . '.');
            }
            $set = ['status' => $to, 'updated_at' => date('Y-m-d H:i:s')];
            if (!empty($f['ticket_type']) && isset(self::TYPES[$f['ticket_type']])) {
                $set['ticket_type'] = $f['ticket_type'];
            }
            if (!empty($f['priority']) && isset(self::PRIORITIES[$f['priority']]) && $f['priority'] !== $t['priority']) {
                $set['priority'] = $f['priority'];
                $set['sla_due_at'] = date('Y-m-d H:i:s', strtotime((string)$t['created_at']) + self::slaHours($f['priority']) * 3600);
            }
            if ($to === 'classified') {
                $set['classified_at'] = date('Y-m-d H:i:s');
            }
            if ($to === 'assigned') {
                if (empty($f['technician_id']) && empty($f['assigned_user_id'])) {
                    fail('Choose the technician or the staff member the ticket is assigned to.');
                }
                $set['technician_id'] = !empty($f['technician_id']) ? (int)$f['technician_id'] : null;
                $set['assigned_user_id'] = !empty($f['assigned_user_id']) ? (int)$f['assigned_user_id'] : null;
                $set['assigned_at'] = date('Y-m-d H:i:s');
            }
            if ($to === 'resolved') {
                $resolution = trim((string)($f['resolution'] ?? ''));
                $cause = trim((string)($f['root_cause'] ?? ''));
                if ($resolution === '') {
                    fail('Enter the resolution (what was done).');
                }
                if (in_array($t['ticket_type'], self::TECHNICAL, true) && $cause === '') {
                    fail('Enter the root cause of a technical problem.');
                }
                $set += ['resolution' => $resolution, 'root_cause' => $cause ?: null, 'resolved_at' => date('Y-m-d H:i:s')];
                if ($t['account_id']) {
                    IspNotify::event('ticket_resolved', (int)$t['account_id'], ['ticket_no' => $t['ticket_no'], 'resolution' => mb_substr($resolution, 0, 120)], 'TKR-' . $id . '-' . time());
                }
            }
            if ($to === 'verified') {
                $set['verified_at'] = date('Y-m-d H:i:s');
            }
            if ($to === 'closed') {
                if (!$t['resolution'] && empty($set['resolution'])) {
                    fail('A ticket is closed only with its resolution.');
                }
                $set['closed_at'] = date('Y-m-d H:i:s');
                if (!empty($f['rating'])) {
                    $set['feedback_rating'] = max(1, min(5, (int)$f['rating']));
                }
                if (!empty($f['feedback'])) {
                    $set['feedback_text'] = mb_substr((string)$f['feedback'], 0, 255);
                }
            }
            DB::update('isp_tickets', $set, 'id = :id', ['id' => $id]);
            self::note($id, trim((string)($f['note'] ?? '')) ?: (self::STATUSES[$to] . ($to === 'resolved' ? ': ' . ($set['resolution'] ?? '') : '')), $to, in_array($to, ['resolved', 'closed'], true));
            if ($t['account_id']) {
                IspAccounts::event((int)$t['account_id'], 'complaint', 'Ticket ' . $t['ticket_no'] . ' ' . strtolower(self::STATUSES[$to]), 'ticket', $id);
            }
        });
    }

    /** A repair work order for a technical ticket (the technician of the ticket, if any) */
    public static function dispatch(int $id, ?int $technicianId, ?string $date, string $slot = 'AM'): int
    {
        $t = self::get($id) ?? fail('Ticket not found.');
        if ($t['work_order_id'] && DB::value("SELECT id FROM isp_work_orders WHERE id = :w AND status NOT IN ('cancelled')", ['w' => $t['work_order_id']])) {
            fail('The ticket already has work order ' . DB::value('SELECT wo_no FROM isp_work_orders WHERE id = :w', ['w' => $t['work_order_id']]) . '.');
        }
        $type = match ($t['ticket_type']) {
            'relocation' => 'relocation',
            'equipment_replacement', 'broken_device' => 'replacement',
            'reconnection_failure' => 'reconnection',
            default => 'repair',
        };
        $wo = IspWorkOrders::create(['wo_type' => $type, 'account_id' => $t['account_id'], 'ticket_id' => $id, 'priority' => $t['priority'] === 'critical' ? 'urgent' : ($t['priority'] === 'high' ? 'high' : 'normal'),
            'description' => 'Ticket ' . $t['ticket_no'] . ': ' . $t['subject'], 'outage_id' => $t['outage_id']]);
        DB::update('isp_tickets', ['work_order_id' => $wo], 'id = :id', ['id' => $id]);
        if ($technicianId && $date) {
            IspWorkOrders::schedule($wo, $technicianId, $date, $slot);
            if (in_array($t['status'], ['new', 'classified', 'on_hold'], true)) {
                self::move($id, 'assigned', ['technician_id' => $technicianId, 'note' => 'Technician dispatched (' . DB::value('SELECT wo_no FROM isp_work_orders WHERE id = :w', ['w' => $wo]) . ')']);
            }
        }
        return $wo;
    }

    /** The alert job: tickets past their SLA deadline are escalated one level (once per deadline) */
    public static function escalate(): int
    {
        $n = 0;
        foreach (DB::all("SELECT * FROM isp_tickets WHERE status IN ('new', 'classified', 'assigned', 'in_progress', 'on_hold') AND sla_due_at < :n", ['n' => date('Y-m-d H:i:s')]) as $t) {
            $level = (int)$t['escalation_level'] + 1;
            $next = date('Y-m-d H:i:s', time() + IspSetup::int('isp_escalate_after_hours') * 3600);
            DB::update('isp_tickets', ['escalation_level' => $level, 'sla_due_at' => $next, 'priority' => $t['priority'] === 'low' ? 'normal' : ($t['priority'] === 'normal' ? 'high' : 'critical')], 'id = :id', ['id' => $t['id']]);
            self::note((int)$t['id'], 'Escalated to level ' . $level . ': past the SLA deadline (' . fdatetime($t['sla_due_at']) . ').', null, false);
            $n++;
        }
        return $n;
    }
}
