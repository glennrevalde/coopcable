<?php
declare(strict_types=1);

/**
 * The menus and pages of the cable / internet service provider system. app/registry.php adds them to the one page
 * registry, so they get page access, the menu, the audit trail of denied pages and the report engine like every
 * other page of the cooperative and accounting system.
 *
 *   key => [module, name, type, menu group, file, params, show in menu]
 */
final class IspPages
{
    /** code => [name, icon, sort] */
    public const MODULES = [
        'isp'      => ['Subscribers', 'router', 2],
        'ispbill'  => ['Billing & Collection', 'receipt-cutoff', 3],
        'ispnet'   => ['Network & Service Control', 'diagram-3', 4],
        'ispfield' => ['Field Service', 'tools', 5],
        'ispset'   => ['ISP Setup', 'sliders', 6],
    ];

    public static function registry(): array
    {
        $r = fn(string $module, string $key, string $name, string $group) => [$module, $name, 'report', $group, 'reports/run.php', ['report' => $key], 1];
        return [
            // ---------------- Subscribers
            'isp.dashboard'     => ['isp', 'ISP Operations Dashboard', 'center', 'Dashboard', 'isp/dashboard.php', [], 1],
            'isp.subscribers'   => ['isp', 'Subscriber Center', 'center', 'Subscribers', 'isp/subscribers.php', [], 1],
            'isp.account'       => ['isp', 'Subscriber Account', 'transaction', 'Subscribers', 'isp/account.php', [], 0],
            'isp.applications'  => ['isp', 'Applications & Activation', 'transaction', 'Subscribers', 'isp/applications.php', [], 1],
            'isp.contracts'     => ['isp', 'Contracts & Renewals', 'transaction', 'Subscribers', 'isp/contracts.php', [], 1],
            'isp.requests'      => ['isp', 'Service Requests', 'transaction', 'Subscribers', 'isp/requests.php', [], 1],
            'isp.agents'        => ['isp', 'Sales Agents & Commissions', 'transaction', 'Subscribers', 'isp/agents.php', [], 1],
            'rpt.isp_master'        => $r('isp', 'isp_master', 'Subscriber Master List', 'Subscriber Reports'),
            'rpt.isp_active'        => $r('isp', 'isp_active', 'Active Accounts', 'Subscriber Reports'),
            'rpt.isp_new_activations' => $r('isp', 'isp_new_activations', 'Newly Activated Accounts', 'Subscriber Reports'),
            'rpt.isp_cancelled'     => $r('isp', 'isp_cancelled', 'Cancelled Subscriptions', 'Subscriber Reports'),
            'rpt.isp_growth'        => $r('isp', 'isp_growth', 'Subscriber Growth', 'Subscriber Reports'),
            'rpt.isp_plan_dist'     => $r('isp', 'isp_plan_dist', 'Plan Distribution', 'Subscriber Reports'),
            'rpt.isp_by_barangay'   => $r('isp', 'isp_by_barangay', 'Subscribers per Barangay', 'Subscriber Reports'),
            'rpt.isp_contracts_due' => $r('isp', 'isp_contracts_due', 'Contracts Due for Renewal', 'Subscriber Reports'),
            'rpt.isp_agent_perf'    => $r('isp', 'isp_agent_perf', 'Agent Performance & Commissions', 'Subscriber Reports'),
            'rpt.isp_notif_log'     => $r('isp', 'isp_notif_log', 'Customer Notification History', 'Subscriber Reports'),

            // ---------------- Billing & Collection
            'ispbill.collect'     => ['ispbill', 'Collect Payment', 'transaction', 'Collections', 'ispbill/collect.php', [], 1],
            'ispbill.payments'    => ['ispbill', 'Payments & Verification', 'transaction', 'Collections', 'ispbill/payments.php', [], 1],
            'ispbill.shifts'      => ['ispbill', 'Cashier Shifts & Cash Count', 'transaction', 'Collections', 'ispbill/shifts.php', [], 1],
            'ispbill.runs'        => ['ispbill', 'Billing Runs', 'transaction', 'Billing', 'ispbill/runs.php', [], 1],
            'ispbill.invoices'    => ['ispbill', 'Invoices', 'transaction', 'Billing', 'ispbill/invoices.php', [], 1],
            'ispbill.adjustments' => ['ispbill', 'Adjustments & Write-offs', 'transaction', 'Billing', 'ispbill/adjustments.php', [], 1],
            'ispbill.disputes'    => ['ispbill', 'Disputes, Arrangements & Exemptions', 'transaction', 'Billing', 'ispbill/disputes.php', [], 1],
            'ispbill.soa'         => ['ispbill', 'Statement of Account', 'transaction', 'Billing', 'ispbill/soa.php', [], 1],
            'rpt.isp_billing_register' => $r('ispbill', 'isp_billing_register', 'Monthly Billing Register', 'Billing Reports'),
            'rpt.isp_invoice_register' => $r('ispbill', 'isp_invoice_register', 'Invoice Register', 'Billing Reports'),
            'rpt.isp_billing_summary'  => $r('ispbill', 'isp_billing_summary', 'Billing Summary', 'Billing Reports'),
            'rpt.isp_unpaid'           => $r('ispbill', 'isp_unpaid', 'Unpaid Invoices', 'Billing Reports'),
            'rpt.isp_adjustments'      => $r('ispbill', 'isp_adjustments', 'Billing Adjustments', 'Billing Reports'),
            'rpt.isp_late_charges'     => $r('ispbill', 'isp_late_charges', 'Late Charges', 'Billing Reports'),
            'rpt.isp_daily_collection' => $r('ispbill', 'isp_daily_collection', 'Daily Collection', 'Collection Reports'),
            'rpt.isp_cashier_collection' => $r('ispbill', 'isp_cashier_collection', 'Collection by Cashier', 'Collection Reports'),
            'rpt.isp_by_method'        => $r('ispbill', 'isp_by_method', 'Collection by Payment Method', 'Collection Reports'),
            'rpt.isp_efficiency'       => $r('ispbill', 'isp_efficiency', 'Collection Efficiency', 'Collection Reports'),
            'rpt.isp_aging'            => $r('ispbill', 'isp_aging', 'Subscriber Receivables Aging', 'Collection Reports'),
            'rpt.isp_unapplied'        => $r('ispbill', 'isp_unapplied', 'Unapplied Payments & Credits', 'Collection Reports'),
            'rpt.isp_delinquency'      => $r('ispbill', 'isp_delinquency', 'Delinquency Report', 'Collection Reports'),
            'rpt.isp_plan_profit'      => $r('ispbill', 'isp_plan_profit', 'Plan Revenue & Profitability', 'Financial Reports'),
            'rpt.isp_branch_profit'    => $r('ispbill', 'isp_branch_profit', 'Branch Profitability', 'Financial Reports'),
            'rpt.isp_arpu'             => $r('ispbill', 'isp_arpu', 'Revenue per Subscriber', 'Financial Reports'),
            'rpt.isp_reconcile'        => $r('ispbill', 'isp_reconcile', 'Subscriber Ledger vs General Ledger', 'Financial Reports'),

            // ---------------- Network & Service Control
            'ispnet.disconnections' => ['ispnet', 'Disconnection Queue', 'transaction', 'Service Control', 'ispnet/disconnections.php', [], 1],
            'ispnet.reconnections'  => ['ispnet', 'Reconnections', 'transaction', 'Service Control', 'ispnet/reconnections.php', [], 1],
            'ispnet.recovery'       => ['ispnet', 'Disconnected Accounts & Recovery', 'transaction', 'Service Control', 'ispnet/recovery.php', [], 1],
            'ispnet.operations'     => ['ispnet', 'Network Operations', 'center', 'Provisioning', 'ispnet/operations.php', [], 1],
            'ispnet.devices'        => ['ispnet', 'Network Devices & Integrations', 'setup', 'Provisioning', 'ispnet/devices.php', [], 1],
            'ispnet.infra'          => ['ispnet', 'Infrastructure & Ports', 'list', 'Infrastructure', 'ispnet/infra.php', [], 1],
            'ispnet.map'            => ['ispnet', 'Network Map', 'center', 'Infrastructure', 'ispnet/map.php', [], 1],
            'ispnet.outages'        => ['ispnet', 'Network Outages', 'transaction', 'Infrastructure', 'ispnet/outages.php', [], 1],
            'rpt.isp_due_disconnect' => $r('ispnet', 'isp_due_disconnect', 'Accounts Due for Disconnection', 'Disconnection Reports'),
            'rpt.isp_disconnections' => $r('ispnet', 'isp_disconnections', 'Actual Disconnections', 'Disconnection Reports'),
            'rpt.isp_failed_ops'     => $r('ispnet', 'isp_failed_ops', 'Failed Network Operations', 'Disconnection Reports'),
            'rpt.isp_disc_reasons'   => $r('ispnet', 'isp_disc_reasons', 'Disconnection Reasons', 'Disconnection Reports'),
            'rpt.isp_recon_history'  => $r('ispnet', 'isp_recon_history', 'Reconnection History', 'Disconnection Reports'),
            'rpt.isp_disc_outstanding' => $r('ispnet', 'isp_disc_outstanding', 'Outstanding Disconnected Accounts', 'Disconnection Reports'),
            'rpt.isp_outage_history' => $r('ispnet', 'isp_outage_history', 'Outage History & SLA', 'Technical Reports'),
            'rpt.isp_capacity'       => $r('ispnet', 'isp_capacity', 'Network Capacity Utilization', 'Technical Reports'),
            'rpt.isp_net_commands'   => $r('ispnet', 'isp_net_commands', 'Network Command Log', 'Technical Reports'),

            // ---------------- Field Service
            'ispfield.mytasks'     => ['ispfield', 'My Tasks Today', 'center', 'Field', 'ispfield/mytasks.php', [], 1],
            'ispfield.workorders'  => ['ispfield', 'Work Orders & Dispatch', 'transaction', 'Field', 'ispfield/workorders.php', [], 1],
            'ispfield.tickets'     => ['ispfield', 'Support Tickets', 'transaction', 'Support', 'ispfield/tickets.php', [], 1],
            'ispfield.technicians' => ['ispfield', 'Technicians & Teams', 'list', 'Field', 'ispfield/technicians.php', [], 1],
            'ispfield.equipment'   => ['ispfield', 'Equipment Register', 'list', 'Field', 'ispfield/equipment.php', [], 1],
            'rpt.isp_install_progress' => $r('ispfield', 'isp_install_progress', 'Installation Progress', 'Technical Reports'),
            'rpt.isp_tech_productivity' => $r('ispfield', 'isp_tech_productivity', 'Technician Productivity', 'Technical Reports'),
            'rpt.isp_open_wo'          => $r('ispfield', 'isp_open_wo', 'Open Work Orders', 'Technical Reports'),
            'rpt.isp_ticket_times'     => $r('ispfield', 'isp_ticket_times', 'Ticket Resolution Times', 'Technical Reports'),
            'rpt.isp_equipment_failures' => $r('ispfield', 'isp_equipment_failures', 'Equipment Failures & Replacements', 'Technical Reports'),
            'rpt.isp_install_profit'   => $r('ispfield', 'isp_install_profit', 'Installation Profitability', 'Technical Reports'),
            'rpt.isp_materials'        => $r('ispfield', 'isp_materials', 'Materials Issued & Returned', 'Technical Reports'),

            // ---------------- ISP Setup
            'ispset.plans'         => ['ispset', 'Internet Plans & Packages', 'setup', 'Setup', 'ispset/plans.php', [], 1],
            'ispset.areas'         => ['ispset', 'Service Areas', 'setup', 'Setup', 'ispset/areas.php', [], 1],
            'ispset.policies'      => ['ispset', 'Billing & Disconnection Policies', 'setup', 'Setup', 'ispset/policies.php', [], 1],
            'ispset.notifications' => ['ispset', 'Notifications (SMS / E-mail)', 'setup', 'Setup', 'ispset/notifications.php', [], 1],
            'ispset.gateways'      => ['ispset', 'Payment Channels & Gateways', 'setup', 'Setup', 'ispset/gateways.php', [], 1],
            'ispset.templates'     => ['ispset', 'Templates & Announcements', 'setup', 'Setup', 'ispset/templates.php', [], 1],
            'ispset.jobs'          => ['ispset', 'Scheduled Jobs Monitor', 'admin', 'Setup', 'ispset/jobs.php', [], 1],
        ];
    }
}
