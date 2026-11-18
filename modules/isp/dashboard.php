<?php
declare(strict_types=1);

/**
 * ISP Operations Dashboard: the subscriber base, billing and collection, service control and field work of the
 * cable / internet operation, every figure counted from the records and linked to the list behind it.
 */
$pk = 'isp.dashboard';
$cur = (string)($GLOBALS['CONFIG']['app']['currency'] ?? '₱');

// the branch: a user tied to a branch sees that branch; the others may pick one
$userBranch = IspSetup::userBranch();
$branches = IspSetup::branches();
$branchId = $userBranch ?? (gint('branch_id') && isset($branches[gint('branch_id')]) ? gint('branch_id') : null);

$k = IspDashboard::kpis($branchId);
$trend = IspDashboard::trend(6, $branchId);
$aging = IspDashboard::aging($branchId);
$plans = IspDashboard::plans($branchId);
$activity = IspDashboard::activity(12, $branchId);
$attention = IspDashboard::attention($k);

/** The link of a tile when the user may open the page behind it */
$link = fn(string $page, array $params = []) => Access::page($page) ? url($page, $params + ($branchId && $userBranch === null && in_array($page, ['isp.subscribers'], true) ? ['branch_id' => $branchId] : [])) : null;
$peso = fn($v) => $cur . money($v);

$actions = '';
if ($userBranch === null && count($branches) > 1) {
    $actions .= '<form method="get" action="' . h(base_url() . '/index.php') . '" class="d-flex align-items-center gap-2"><input type="hidden" name="p" value="' . h($pk) . '">'
        . '<label class="visually-hidden" for="dashBranch">Branch</label><select class="form-select form-select-sm" id="dashBranch" name="branch_id" data-autosubmit>' . options($branches, $branchId, 'All branches') . '</select>'
        . '<noscript><button class="btn btn-sm btn-outline-secondary">Show</button></noscript></form>';
}
$actions .= page_link('ispbill.collect', [], 'Collect payment', 'btn-primary', 'cash-coin', 'create');
$actions .= page_link('isp.applications', ['a' => 'new'], 'New application', 'btn-outline-primary', 'person-plus', 'create');

layout_start('ISP Operations Dashboard', ['actions' => $actions, 'body_class' => 'dash-live']);

if ($k['simulated']):
?>
<div class="sim-note"><i class="bi bi-cpu"></i><div><strong>Simulated network.</strong> A network device of this company uses the integration simulator: its connects and disconnects are recorded and labelled as simulated, and no real router or OLT is changed.
<?php if (Access::page('ispnet.devices')): ?> <a href="<?= h(url('ispnet.devices')) ?>">Network devices</a><?php endif; ?></div></div>
<?php endif; ?>

<h2 class="sec-title">Subscribers<?php if ($branchId): ?><span class="sec-aside"><?= h($branches[$branchId] ?? '') ?></span><?php endif; ?></h2>
<div class="kpi-grid">
  <?= IspUi::kpi('Active subscribers', number_format($k['active']), 'ARPU ' . $peso($k['arpu']) . ' this month', $link('isp.subscribers', ['status' => 'active']), 'wifi', 'ok') ?>
  <?= IspUi::kpi('New this month', number_format($k['new_month']), $k['terminated_month'] . ' terminated this month', $link('rpt.isp_new_activations'), 'person-plus', 'info') ?>
  <?= IspUi::kpi('Waiting for activation', number_format($k['pending']), $k['applications_open'] . ' application(s) in progress', $link('isp.applications'), 'hourglass-split', 'violet') ?>
  <?= IspUi::kpi('Disconnected', number_format($k['disconnected']), $k['suspended'] . ' suspended on request', $link('isp.subscribers', ['status' => 'disconnected']), 'plug', $k['disconnected'] ? 'bad' : 'idle') ?>
</div>

<h2 class="sec-title">Billing and collection</h2>
<div class="kpi-grid">
  <?= IspUi::kpi('Billed this month', $peso($k['billed_month']), $k['drafts'] ? $k['drafts'] . ' billing run(s) to approve or post' : 'invoices posted ' . date('F Y'), $link('ispbill.invoices'), 'receipt', 'info') ?>
  <?= IspUi::kpi('Collected this month', $peso($k['collected_month']), 'today ' . $peso($k['collected_today']), $link('rpt.isp_daily_collection'), 'cash-coin', 'ok') ?>
  <?= IspUi::kpi('Collection efficiency', number_format($k['efficiency'], 1) . '%', 'of this month\'s invoices paid', $link('rpt.isp_efficiency'), 'speedometer2', $k['efficiency'] >= 85 ? 'ok' : ($k['efficiency'] >= 60 ? 'warn' : 'bad')) ?>
  <?= IspUi::kpi('Overdue receivables', $peso($k['overdue']), number_format($k['overdue_accounts']) . ' account(s) past due', $link('isp.subscribers', ['money' => 'overdue']), 'exclamation-triangle', $k['overdue'] > 0 ? 'warn' : 'idle') ?>
</div>

<h2 class="sec-title">Service control and field work</h2>
<div class="kpi-grid">
  <?= IspUi::kpi('Disconnection queue', number_format($k['disc_candidates']), $k['disc_today'] . ' disconnected today', $link('ispnet.disconnections', ['tab' => 'candidate']), 'scissors', $k['disc_candidates'] ? 'warn' : 'idle') ?>
  <?= IspUi::kpi('Network operations', number_format($k['net_failed']), $k['net_failed'] ? 'failed or waiting for a technician' : $k['net_queued'] . ' queued, none failed', $link('ispnet.operations', ['state' => $k['net_failed'] ? 'problem' : 'open']), 'hdd-network', $k['net_failed'] ? 'bad' : 'ok') ?>
  <?= IspUi::kpi('Open tickets', number_format($k['tickets_open']), $k['tickets_breached'] . ' past the SLA', $link('ispfield.tickets', $k['tickets_breached'] ? ['sla' => 'breached'] : []), 'headset', $k['tickets_breached'] ? 'bad' : 'info') ?>
  <?= IspUi::kpi('Active outages', number_format($k['outages']), $k['outages'] ? number_format($k['outage_accounts']) . ' account(s) affected' : 'the network is up', $link('ispnet.outages'), 'broadcast-pin', $k['outages'] ? 'bad' : 'ok') ?>
</div>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="card isp-card">
      <div class="card-body">
        <h2 class="sec-title">Billed, collected and subscribers<span class="sec-aside">last 6 months</span></h2>
        <?php
        // ------------------------------------------------------------- the trend chart (SVG)
        $W = 720; $H = 270; $pl = 62; $pr = 50; $pt = 16; $pb = 34;
        $max = max(1.0, ...array_map(fn($t) => max($t['billed'], $t['collected']), $trend));
        $mag = 10 ** floor(log10($max));
        $step = $max / $mag <= 2 ? $mag / 2 : ($max / $mag <= 5 ? $mag : $mag * 2);
        $top = ceil($max / $step) * $step;
        $maxS = max(1, ...array_map(fn($t) => $t['subscribers'], $trend));
        $topS = max(4, (int)ceil($maxS * 1.15));
        $n = count($trend);
        $gw = ($W - $pl - $pr) / max(1, $n);
        $bw = min(26.0, $gw * .3);
        $y = fn(float $v) => $pt + ($H - $pt - $pb) * (1 - $v / $top);
        $ys = fn(int $v) => $pt + ($H - $pt - $pb) * (1 - $v / $topS);
        $short = fn(float $v) => $v >= 1e6 ? round($v / 1e6, 1) . 'M' : ($v >= 1e3 ? round($v / 1e3, 1) . 'k' : (string)round($v));
        $pts = [];
        ?>
        <svg class="chart-svg" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Billed and collected per month, and active subscribers">
          <defs>
            <linearGradient id="gBilled" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#22d3ee"/><stop offset="1" stop-color="#0891b2"/></linearGradient>
            <linearGradient id="gColl" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#34d399"/><stop offset="1" stop-color="#059669"/></linearGradient>
            <linearGradient id="gArea" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#7c3aed" stop-opacity=".18"/><stop offset="1" stop-color="#7c3aed" stop-opacity="0"/></linearGradient>
          </defs>
          <g class="grid axis">
            <?php for ($v = 0; $v <= $top + 1e-9; $v += $step): ?>
              <line x1="<?= $pl ?>" x2="<?= $W - $pr ?>" y1="<?= round($y($v), 1) ?>" y2="<?= round($y($v), 1) ?>"/>
              <text x="<?= $pl - 8 ?>" y="<?= round($y($v), 1) + 4 ?>" text-anchor="end"><?= h($short($v)) ?></text>
            <?php endfor; ?>
            <text x="<?= $W - $pr + 8 ?>" y="<?= round($ys($topS), 1) + 4 ?>"><?= $topS ?></text>
            <text x="<?= $W - $pr + 8 ?>" y="<?= round($ys(0), 1) + 4 ?>">0</text>
          </g>
          <?php foreach ($trend as $i => $t):
              $cx = $pl + $gw * $i + $gw / 2;
              $pts[] = [round($cx, 1), round($ys($t['subscribers']), 1), $t['subscribers']]; ?>
            <g>
              <title><?= h(date('F Y', strtotime($t['ym'] . '-01'))) ?>: billed <?= h($peso($t['billed'])) ?>, collected <?= h($peso($t['collected'])) ?>, <?= (int)$t['subscribers'] ?> subscribers</title>
              <rect class="bar-b" x="<?= round($cx - $bw - 2, 1) ?>" y="<?= round($y($t['billed']), 1) ?>" width="<?= round($bw, 1) ?>" height="<?= round($H - $pb - $y($t['billed']), 1) ?>" rx="5" style="animation-delay:<?= $i * 70 ?>ms"/>
              <rect class="bar-c" x="<?= round($cx + 2, 1) ?>" y="<?= round($y($t['collected']), 1) ?>" width="<?= round($bw, 1) ?>" height="<?= round($H - $pb - $y($t['collected']), 1) ?>" rx="5" style="animation-delay:<?= $i * 70 + 35 ?>ms"/>
              <text class="lbl" x="<?= round($cx, 1) ?>" y="<?= $H - 12 ?>" text-anchor="middle"><?= h($t['label']) ?></text>
            </g>
          <?php endforeach; ?>
          <?php if ($pts):
              $d = 'M' . implode(' L', array_map(fn($p) => $p[0] . ' ' . $p[1], $pts));
              $area = $d . ' L' . end($pts)[0] . ' ' . ($H - $pb) . ' L' . $pts[0][0] . ' ' . ($H - $pb) . ' Z'; ?>
            <path class="area-s" d="<?= $area ?>"/>
            <path class="line-s" d="<?= $d ?>"/>
            <?php foreach ($pts as $i => $p): ?><circle class="dot-s" cx="<?= $p[0] ?>" cy="<?= $p[1] ?>" r="4" style="animation-delay:<?= 900 + $i * 120 ?>ms"><title><?= (int)$p[2] ?> subscribers</title></circle><?php endforeach; ?>
          <?php endif; ?>
        </svg>
        <div class="chart-legend mt-2">
          <span><i style="background:linear-gradient(180deg,#22d3ee,#0891b2)"></i>Billed</span>
          <span><i style="background:linear-gradient(180deg,#34d399,#059669)"></i>Collected</span>
          <span><i style="background:#7c3aed;height:3px;border-radius:2px"></i>Subscribers (right scale)</span>
        </div>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-md-6">
        <div class="card isp-card h-100">
          <div class="card-body">
            <h2 class="sec-title">Receivables by age<?php if (Access::page('rpt.isp_aging')): ?><a class="sec-aside" href="<?= h(url('rpt.isp_aging')) ?>">Aging report</a><?php endif; ?></h2>
            <?php
            $agingTotal = array_sum($aging);
            $agingColors = ['#10b981', '#22d3ee', '#f59e0b', '#f97316', '#ef4444'];
            ?>
            <div class="d-flex align-items-baseline gap-2 mb-2"><span class="fs-4 fw-bold text-body"><?= h($peso($agingTotal)) ?></span><span class="text-muted small">open balance</span></div>
            <div class="stack-bar" role="img" aria-label="Receivables by age">
              <?php $i = 0; foreach ($aging as $label => $amt): $w = $agingTotal > 0 ? $amt / $agingTotal * 100 : 0; ?>
                <?php if ($w > 0): ?><span style="width:<?= round($w, 2) ?>%;background:<?= $agingColors[$i] ?>;animation-delay:<?= $i * 90 ?>ms" title="<?= h($label . ': ' . $peso($amt)) ?>"></span><?php endif; ?>
              <?php $i++; endforeach; ?>
            </div>
            <div class="legend-rows">
              <?php $i = 0; foreach ($aging as $label => $amt): ?>
                <div class="legend-row"><i style="background:<?= $agingColors[$i++] ?>"></i><span><?= h($label) ?></span><span class="num"><?= h(money($amt)) ?></span></div>
              <?php endforeach; ?>
            </div>
            <?php if ($k['unapplied'] > 0): ?><div class="small text-muted mt-2">Advance payments and credits not yet applied: <?= h($peso($k['unapplied'])) ?></div><?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card isp-card h-100">
          <div class="card-body">
            <h2 class="sec-title">Active subscribers per plan<?php if (Access::page('rpt.isp_plan_dist')): ?><a class="sec-aside" href="<?= h(url('rpt.isp_plan_dist')) ?>">Plan report</a><?php endif; ?></h2>
            <?php if (!$plans): ?>
              <?= IspUi::empty('No active subscriber yet.', 'router') ?>
            <?php else: $maxN = max(array_map(fn($p) => (int)$p['n'], $plans)); ?>
              <div class="d-grid gap-3">
              <?php foreach ($plans as $i => $p): ?>
                <div>
                  <div class="d-flex justify-content-between gap-2 small mb-1"><span class="fw-semibold text-truncate"><?= h($p['name']) ?></span><span class="text-nowrap"><strong><?= (int)$p['n'] ?></strong> <span class="text-muted"><?= h(qty($p['download_mbps'])) ?> Mbps</span></span></div>
                  <div class="meter"><span style="width:<?= round((int)$p['n'] / max(1, $maxN) * 100, 1) ?>%;animation-delay:<?= $i * 80 ?>ms"></span></div>
                </div>
              <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-4">
    <div class="card isp-card">
      <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-lightning-charge text-warning"></i>Needs attention</div>
      <?php if (!$attention): ?>
        <?= IspUi::empty('Nothing needs attention right now.', 'check2-circle') ?>
      <?php else: ?>
        <ul class="attn-list">
          <?php foreach ($attention as [$label, $n, $page, $params, $tone]): ?>
            <li><a href="<?= h(url($page, $params)) ?>"><?= IspUi::status($tone, [$tone => $label], $tone) ?><span class="attn-n"><?= number_format($n) ?></span><i class="bi bi-chevron-right"></i></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <div class="card isp-card">
      <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-activity text-primary"></i>Recent account activity</div>
      <?php if (!$activity): ?>
        <?= IspUi::empty('No account activity yet.', 'clock-history') ?>
      <?php else: ?>
        <ul class="feed">
          <?php foreach ($activity as $e): ?>
            <li><div class="feed-main"><div><?= IspUi::acct($e['account_id'], $e['account_no']) ?> <span class="text-muted"><?= h($e['account_name']) ?></span></div>
              <div class="small"><?= h($e['summary']) ?><?= $e['amount'] !== null && (float)$e['amount'] != 0.0 ? ' <strong>' . h($peso($e['amount'])) . '</strong>' : '' ?></div></div>
              <span class="feed-when" title="<?= h(fdatetime($e['event_date'])) ?>"><?= h(IspUi::ago($e['event_date'])) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php
layout_end();
