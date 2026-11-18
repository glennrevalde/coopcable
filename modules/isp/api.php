<?php
declare(strict_types=1);

// JSON look-ups of the cable / internet pages (the subscriber account picker). Open to logged-in users who may open
// one of the pages that pick an account; the matches follow the user's branch.
$may = false;
foreach (['isp.subscribers', 'isp.account', 'ispbill.collect', 'ispbill.payments', 'ispbill.adjustments', 'ispbill.disputes', 'ispbill.soa', 'ispfield.tickets',
          'ispfield.workorders', 'ispnet.reconnections', 'ispnet.disconnections', 'ispnet.outages', 'isp.requests'] as $k) {
    if (Access::page($k)) {
        $may = true;
        break;
    }
}
if (!$may || !IspSetup::ready()) {
    json_out(['error' => 'not allowed'], 403);
}
switch (g('what')) {
    case 'accounts':
        $rows = array_map(fn($r) => ['id' => (int)$r['id'], 'no' => $r['account_no'], 'name' => $r['account_name'], 'status' => IspAccounts::SERVICE_STATUSES[$r['service_status']] ?? $r['service_status'],
            'tone' => IspUi::tone($r['service_status']), 'plan' => (string)$r['plan'], 'address' => (Access::special('isp_view_sensitive') || Access::special('coop_view_confidential')) ? (string)$r['install_address'] : Party::mask((string)$r['install_address']), 'balance' => money($r['balance'])], IspUi::searchAccounts((string)g('q')));
        json_out(['items' => $rows]);
    default:
        json_out(['error' => 'unknown lookup'], 400);
}
