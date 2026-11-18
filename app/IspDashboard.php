<?php
declare(strict_types=1);

/**
 * The figures of the ISP operations dashboard, every one counted from the records (nothing estimated), each with
 * the page that lists the records behind it.
 */
final class IspDashboard
{
    /** The KPI figures of the subscriber base, billing, collection, service control and field work */
    public static function kpis(?int $branchId = null): array
    {
        $p = [];
        $b = $branchId ? ' AND a.branch_id = ' . (int)$branchId : '';
        $mStart = date('Y-m-01');
        $mEnd = date('Y-m-t');
        $k = [];
        $k['active'] = (int)DB::value("SELECT COUNT(*) FROM isp_accounts a WHERE a.service_status = 'active'$b");
        $k['disconnected'] = (int)DB::value("SELECT COUNT(*) FROM isp_accounts a WHERE a.service_status = 'disconnected'$b");
        $k['suspended'] = (int)DB::value("SELECT COUNT(*) FROM isp_accounts a WHERE a.service_status = 'suspended'$b");
        $k['pending'] = (int)DB::value("SELECT COUNT(*) FROM isp_accounts a WHERE a.service_status = 'pending'$b");
        $k['new_month'] = (int)DB::value("SELECT COUNT(*) FROM isp_accounts a WHERE a.activation_date BETWEEN :f AND :t$b", ['f' => $mStart, 't' => $mEnd]);
        $k['terminated_month'] = (int)DB::value("SELECT COUNT(*) FROM isp_accounts a WHERE a.terminated_at BETWEEN :f AND :t$b", ['f' => $mStart . ' 00:00:00', 't' => $mEnd . ' 23:59:59']);
        $k['applications_open'] = (int)DB::value("SELECT COUNT(*) FROM isp_applications x WHERE x.status IN ('new','for_verification','site_survey','approved','for_installation','for_activation')"
            . ($branchId ? ' AND x.branch_id = ' . (int)$branchId : ''));
        $k['billed_month'] = (float)DB::value("SELECT COALESCE(SUM(i.total), 0) FROM isp_invoices i JOIN isp_accounts a ON a.id = i.account_id
            WHERE i.invoice_date BETWEEN :f AND :t AND i.status NOT IN ('draft','approved','voided')$b", ['f' => $mStart, 't' => $mEnd]);
        $k['collected_month'] = (float)DB::value("SELECT COALESCE(SUM(y.amount), 0) FROM isp_payments y JOIN isp_accounts a ON a.id = y.account_id
            WHERE y.pay_date BETWEEN :f AND :t AND y.status = 'posted'$b", ['f' => $mStart, 't' => $mEnd]);
        $k['collected_today'] = (float)DB::value("SELECT COALESCE(SUM(y.amount), 0) FROM isp_payments y JOIN isp_accounts a ON a.id = y.account_id
            WHERE y.pay_date = :d AND y.status = 'posted'$b", ['d' => today()]);
        // efficiency: what was paid of the invoices issued this month
        $paidOfMonth = (float)DB::value("SELECT COALESCE(SUM(i.paid_amount + i.credit_amount), 0) FROM isp_invoices i JOIN isp_accounts a ON a.id = i.account_id
            WHERE i.invoice_date BETWEEN :f AND :t AND i.status NOT IN ('draft','approved','voided')$b", ['f' => $mStart, 't' => $mEnd]);
        $k['efficiency'] = $k['billed_month'] > 0 ? round($paidOfMonth / $k['billed_month'] * 100, 1) : 0.0;
        $k['receivable'] = (float)DB::value("SELECT COALESCE(SUM(i.balance), 0) FROM isp_invoices i JOIN isp_accounts a ON a.id = i.account_id
            WHERE i.status IN ('posted','partially_paid','overdue','disputed')$b");
        $k['overdue'] = (float)DB::value("SELECT COALESCE(SUM(i.balance), 0) FROM isp_invoices i JOIN isp_accounts a ON a.id = i.account_id
            WHERE i.status IN ('posted','partially_paid','overdue','disputed') AND i.due_date < :d$b", ['d' => today()]);
        $k['overdue_accounts'] = (int)DB::value("SELECT COUNT(DISTINCT i.account_id) FROM isp_invoices i JOIN isp_accounts a ON a.id = i.account_id
            WHERE i.status IN ('posted','partially_paid','overdue','disputed') AND i.due_date < :d AND i.balance > 0$b", ['d' => today()]);
        $k['unapplied'] = (float)DB::value("SELECT COALESCE(SUM(y.unapplied_amount), 0) FROM isp_payments y JOIN isp_accounts a ON a.id = y.account_id WHERE y.status = 'posted'$b");
        $k['pending_payments'] = (int)DB::value("SELECT COUNT(*) FROM isp_payments y JOIN isp_accounts a ON a.id = y.account_id WHERE y.status = 'pending'$b");
        $k['drafts'] = (int)DB::value("SELECT COUNT(*) FROM isp_billing_runs r WHERE r.status IN ('draft','approved')");
        $k['disc_candidates'] = (int)DB::value("SELECT COUNT(*) FROM isp_disc_queue q JOIN isp_accounts a ON a.id = q.account_id WHERE q.status IN ('candidate','approved','notified')$b");
        $k['disc_today'] = (int)DB::value("SELECT COUNT(*) FROM isp_disc_queue q JOIN isp_accounts a ON a.id = q.account_id WHERE q.status = 'disconnected' AND DATE(q.executed_at) = :d$b", ['d' => today()]);
        $k['recon_pending'] = (int)DB::value("SELECT COUNT(*) FROM isp_reconnections r JOIN isp_accounts a ON a.id = r.account_id WHERE r.status IN ('requested','pending_approval','approved','executing')$b");
        $k['net_failed'] = (int)DB::value("SELECT COUNT(*) FROM isp_net_ops o JOIN isp_accounts a ON a.id = o.account_id WHERE o.state IN ('failed','manual')$b");
        $k['net_queued'] = (int)DB::value("SELECT COUNT(*) FROM isp_net_ops o JOIN isp_accounts a ON a.id = o.account_id WHERE o.state IN ('requested','queued','sent')$b");
        $k['tickets_open'] = (int)DB::value("SELECT COUNT(*) FROM isp_tickets t LEFT JOIN isp_accounts a ON a.id = t.account_id WHERE t.status NOT IN ('resolved','closed','cancelled')"
            . ($branchId ? ' AND (a.branch_id = ' . (int)$branchId . ' OR a.id IS NULL)' : ''));
        $k['tickets_breached'] = (int)DB::value("SELECT COUNT(*) FROM isp_tickets t LEFT JOIN isp_accounts a ON a.id = t.account_id WHERE t.status NOT IN ('resolved','closed','cancelled') AND t.sla_due_at < :n"
            . ($branchId ? ' AND (a.branch_id = ' . (int)$branchId . ' OR a.id IS NULL)' : ''), ['n' => date('Y-m-d H:i:s')]);
        $k['wo_open'] = (int)DB::value("SELECT COUNT(*) FROM isp_work_orders w WHERE w.status IN ('open','scheduled','accepted','in_progress')");
        $k['wo_today'] = (int)DB::value("SELECT COUNT(*) FROM isp_work_orders w WHERE w.status IN ('scheduled','accepted','in_progress') AND w.scheduled_date = :d", ['d' => today()]);
        $k['outages'] = (int)DB::value("SELECT COUNT(*) FROM isp_outages o WHERE o.status IN ('suspected','verified','in_repair')");
        $k['outage_accounts'] = (int)DB::value("SELECT COALESCE(SUM(o.affected_count), 0) FROM isp_outages o WHERE o.status IN ('suspected','verified','in_repair')");
        $k['arpu'] = $k['active'] > 0 ? r2($k['billed_month'] / $k['active']) : 0.0;
        $k['simulated'] = (int)DB::value("SELECT COUNT(*) FROM isp_devices WHERE is_active = 1 AND adapter = 'simulator'") > 0;
        $k['equipment_recover'] = (int)DB::value("SELECT COUNT(*) FROM isp_equipment WHERE status = 'for_recovery'");
        return $k;
    }

    /** Billed and collected per month, the last $months months (oldest first) */
    public static function trend(int $months = 6, ?int $branchId = null): array
    {
        $b = $branchId ? ' AND a.branch_id = ' . (int)$branchId : '';
        $from = date('Y-m-01', strtotime('-' . ($months - 1) . ' months', strtotime(date('Y-m-01'))));
        $billed = DB::pairs("SELECT DATE_FORMAT(i.invoice_date, '%Y-%m') AS ym, SUM(i.total) FROM isp_invoices i JOIN isp_accounts a ON a.id = i.account_id
            WHERE i.invoice_date >= :f AND i.status NOT IN ('draft','approved','voided')$b GROUP BY ym", ['f' => $from]);
        $coll = DB::pairs("SELECT DATE_FORMAT(y.pay_date, '%Y-%m') AS ym, SUM(y.amount) FROM isp_payments y JOIN isp_accounts a ON a.id = y.account_id
            WHERE y.pay_date >= :f AND y.status = 'posted'$b GROUP BY ym", ['f' => $from]);
        $subs = [];
        $out = [];
        for ($i = 0; $i < $months; $i++) {
            $ym = date('Y-m', strtotime("+$i months", strtotime($from)));
            $end = date('Y-m-t', strtotime($ym . '-01'));
            $subs = (int)DB::value("SELECT COUNT(*) FROM isp_accounts a WHERE a.activation_date IS NOT NULL AND a.activation_date <= :e AND (a.terminated_at IS NULL OR a.terminated_at > :e2)$b",
                ['e' => $end, 'e2' => $end . ' 23:59:59']);
            $out[] = ['ym' => $ym, 'label' => date('M', strtotime($ym . '-01')), 'billed' => r2((float)($billed[$ym] ?? 0)), 'collected' => r2((float)($coll[$ym] ?? 0)), 'subscribers' => $subs];
        }
        return $out;
    }

    /** Receivables by age of the due date */
    public static function aging(?int $branchId = null): array
    {
        $b = $branchId ? ' AND a.branch_id = ' . (int)$branchId : '';
        $row = DB::row("SELECT
                COALESCE(SUM(CASE WHEN i.due_date >= :d THEN i.balance END), 0) AS cur,
                COALESCE(SUM(CASE WHEN i.due_date < :d1 AND DATEDIFF(:d2, i.due_date) <= 30 THEN i.balance END), 0) AS d30,
                COALESCE(SUM(CASE WHEN DATEDIFF(:d3, i.due_date) BETWEEN 31 AND 60 THEN i.balance END), 0) AS d60,
                COALESCE(SUM(CASE WHEN DATEDIFF(:d4, i.due_date) BETWEEN 61 AND 90 THEN i.balance END), 0) AS d90,
                COALESCE(SUM(CASE WHEN DATEDIFF(:d5, i.due_date) > 90 THEN i.balance END), 0) AS d91
            FROM isp_invoices i JOIN isp_accounts a ON a.id = i.account_id WHERE i.status IN ('posted','partially_paid','overdue','disputed') AND i.balance > 0$b",
            ['d' => today(), 'd1' => today(), 'd2' => today(), 'd3' => today(), 'd4' => today(), 'd5' => today()]);
        return ['Current' => (float)$row['cur'], '1-30 days' => (float)$row['d30'], '31-60 days' => (float)$row['d60'], '61-90 days' => (float)$row['d90'], 'Over 90 days' => (float)$row['d91']];
    }

    /** Active subscribers per plan */
    public static function plans(?int $branchId = null): array
    {
        $b = $branchId ? ' AND a.branch_id = ' . (int)$branchId : '';
        return DB::all("SELECT p.id, p.name, p.download_mbps, COUNT(a.id) AS n, COALESCE(SUM(p.monthly_fee), 0) AS mrr FROM isp_plans p
            JOIN isp_accounts a ON a.plan_id = p.id AND a.service_status = 'active'$b GROUP BY p.id, p.name, p.download_mbps ORDER BY n DESC, p.name LIMIT 8");
    }

    /** The latest events of the accounts */
    public static function activity(int $limit = 12, ?int $branchId = null): array
    {
        $b = $branchId ? ' AND a.branch_id = ' . (int)$branchId : '';
        return DB::all("SELECT e.*, a.account_no, a.account_name FROM isp_account_events e JOIN isp_accounts a ON a.id = e.account_id WHERE 1 = 1$b ORDER BY e.id DESC LIMIT " . (int)$limit);
    }

    /** What needs a person today: [label, count, page, params, tone] */
    public static function attention(array $k): array
    {
        $out = [];
        $add = function (string $label, int $n, string $page, array $params, string $tone) use (&$out) {
            if ($n > 0 && Access::page($page)) {
                $out[] = [$label, $n, $page, $params, $tone];
            }
        };
        $add('Payments waiting for verification', $k['pending_payments'], 'ispbill.payments', ['tab' => 'pending'], 'warn');
        $add('Billing runs waiting for approval or posting', $k['drafts'], 'ispbill.runs', [], 'info');
        $add('Disconnection candidates and approved disconnections', $k['disc_candidates'], 'ispnet.disconnections', [], 'warn');
        $add('Network operations failed or waiting for a technician', $k['net_failed'], 'ispnet.operations', ['state' => 'problem'], 'bad');
        $add('Reconnections in progress', $k['recon_pending'], 'ispnet.reconnections', [], 'info');
        $add('Support tickets past their SLA', $k['tickets_breached'], 'ispfield.tickets', ['sla' => 'breached'], 'bad');
        $add('Active network outages', $k['outages'], 'ispnet.outages', [], 'bad');
        $add('Work orders scheduled today', $k['wo_today'], 'ispfield.workorders', ['date' => today()], 'info');
        $add('Applications in progress', $k['applications_open'], 'isp.applications', [], 'info');
        $add('Equipment to recover from disconnected subscribers', $k['equipment_recover'], 'ispnet.recovery', ['tab' => 'equipment'], 'warn');
        return $out;
    }
}
