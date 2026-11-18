<?php
declare(strict_types=1);

defined('APP_PORTAL') || exit;   // opened only through portal/index.php

$c = portal_customer();
$accounts = portal_accounts();
$news = portal_announcements($accounts, 3);
$online = IspGateway::enabled();
$unread = portal_unread((int)$c['id']);
$first = explode(' ', trim((string)$c['name']))[0] ?? '';
portal_start('Hello' . ($first !== '' ? ', ' . $first : ''), ['actions' => $unread ? '<a class="btn btn-sm btn-outline-light" href="' . h(IspPortal::url('notifications')) . '"><i class="bi bi-bell"></i> ' . $unread . ' new</a>' : '']);
?>
<?php foreach ($news as $n): ?>
<a class="pp-news pp-reveal" href="<?= h(IspPortal::url('announcements')) ?>"><i class="bi bi-megaphone"></i><div><strong><?= h($n['title']) ?></strong><span><?= h(mb_strimwidth((string)$n['body'], 0, 140, '...')) ?></span></div></a>
<?php endforeach; ?>

<?php if (!$accounts): ?>
  <div class="pp-card"><?= portal_empty('There is no service account on your subscriber record yet. Your account appears here once the installation is approved.', 'router') ?></div>
<?php endif; ?>

<div class="pp-accounts">
<?php foreach ($accounts as $i => $a):
    $plan = IspPlans::get((int)$a['plan_id']);
    $due = portal_next_due((int)$a['id']);
    $last = portal_last_payment((int)$a['id']);
    $outages = portal_outages((int)$a['id']);
    $bal = (float)$a['balance'];
    $over = (float)$a['overdue'] > 0.004;
    $pending = (float)DB::value("SELECT COALESCE(SUM(amount), 0) FROM isp_payments WHERE account_id = :a AND status = 'pending'", ['a' => $a['id']]);
    $status = (string)$a['service_status'];
    $q = ['account' => (int)$a['id']];
?>
  <section class="pp-acct pp-reveal" style="--i:<?= (int)$i ?>">
    <header class="pp-acct-head">
      <div class="pp-acct-id">
        <span class="pp-signal-ring st-<?= h(IspUi::tone($status)) ?>" aria-hidden="true"><i class="bi bi-wifi"></i></span>
        <div><h2><?= h($plan['name'] ?? $a['plan_name']) ?></h2>
          <div class="pp-acct-sub">Account <?= h($a['account_no']) ?><?= $a['install_address'] ? ', ' . h($a['install_address']) : '' ?></div></div>
      </div>
      <div class="pp-acct-status"><?= portal_status($status, IspAccounts::SERVICE_STATUSES) ?></div>
    </header>

    <?php foreach ($outages as $o): ?>
      <div class="pp-alert pp-alert-warn"><i class="bi bi-cone-striped"></i><div><strong><?= (int)$o['is_planned'] === 1 ? 'Scheduled maintenance' : 'Service interruption' ?> in your area.</strong>
        <?= h($o['title']) ?>, since <?= h(fdatetime($o['started_at'])) ?>. Our team is working on it; no need to report it again.</div></div>
    <?php endforeach; ?>
    <?php if ($status === 'disconnected'): $block = IspReconnect::blocker(IspAccounts::get((int)$a['id'])); ?>
      <div class="pp-alert pp-alert-bad"><i class="bi bi-plug"></i><div><strong>Your service is disconnected<?= $a['disconnect_reason'] ? ': ' . h($a['disconnect_reason']) : '' ?>.</strong>
        <?php if ($block === null): ?>It is restored automatically once your payment is confirmed.<?php else: ?>To restore it: <?= h($block) ?>. Payments are applied as soon as they are confirmed.<?php endif; ?>
        <a href="<?= h(IspPortal::url('requests', $q + ['new' => 'reconnection'])) ?>">Request reconnection</a></div></div>
    <?php elseif ($status === 'suspended'): ?>
      <div class="pp-alert pp-alert-info"><i class="bi bi-pause-circle"></i><div>Your service is suspended<?= $a['suspended_until'] ? ' until ' . h(fdate($a['suspended_until'])) : '' ?>.</div></div>
    <?php elseif ($status === 'pending'): ?>
      <div class="pp-alert pp-alert-info"><i class="bi bi-tools"></i><div>Your installation is being prepared. We will message you the schedule of the technician.</div></div>
    <?php endif; ?>

    <div class="pp-figs">
      <div class="pp-fig pp-fig-main<?= $over ? ' is-bad' : '' ?>">
        <span class="pp-fig-label"><?= $bal < -0.004 ? 'Advance credit' : ($over ? 'Amount due (overdue)' : 'Amount due') ?></span>
        <span class="pp-fig-value" data-countup><?= h(money(abs($bal))) ?></span>
        <span class="pp-fig-sub"><?= $bal > 0.004 && $due ? 'Due ' . h(fdate($due)) . ($over ? ', overdue ' . h(money($a['overdue'])) : '') : ($bal < -0.004 ? 'Applied to your next bill' : 'Nothing to pay') ?></span>
      </div>
      <div class="pp-fig">
        <span class="pp-fig-label">Speed</span>
        <span class="pp-fig-value"><?= h($plan ? IspPlans::speed($plan) : '') ?></span>
        <span class="pp-fig-sub"><?= h(money($plan ? IspPlans::priceFor($plan, $a) : 0)) ?> a month<?= (int)$a['member_price'] === 1 && $plan && $plan['member_monthly_fee'] !== null ? ' (member price)' : '' ?><?= ($plan['data_cap_gb'] ?? null) ? ', ' . h(qty($plan['data_cap_gb'])) . ' GB' : '' ?></span>
      </div>
      <div class="pp-fig">
        <span class="pp-fig-label">Last payment</span>
        <span class="pp-fig-value"><?= $last ? h(money($last['amount'])) : 'None yet' ?></span>
        <span class="pp-fig-sub"><?= $last ? h(fdate($last['pay_date'])) . ', ' . h(IspPayments::CHANNELS[$last['channel']] ?? $last['channel']) : '' ?><?= $pending > 0 ? ($last ? '. ' : '') . h(money($pending)) . ' waiting for verification' : '' ?></span>
      </div>
    </div>

    <div class="pp-quick">
      <?php if ($online && $bal > 0.004): ?><a class="btn btn-primary" href="<?= h(IspPortal::url('pay', $q)) ?>"><i class="bi bi-credit-card"></i> Pay online</a><?php endif; ?>
      <a class="btn <?= $online && $bal > 0.004 ? 'btn-outline-primary' : 'btn-primary' ?>" href="<?= h(IspPortal::url('paid', $q)) ?>"><i class="bi bi-qr-code-scan"></i> I paid by GCash / Maya / bank</a>
      <a class="btn btn-outline-secondary" href="<?= h(IspPortal::url('invoices', $q)) ?>"><i class="bi bi-receipt"></i> Bills</a>
      <a class="btn btn-outline-secondary" href="<?= h(IspPortal::url('soa', $q)) ?>"><i class="bi bi-file-earmark-text"></i> Statement</a>
      <a class="btn btn-outline-secondary" href="<?= h(IspPortal::url('tickets', $q + ['new' => 1])) ?>"><i class="bi bi-life-preserver"></i> Report a problem</a>
      <a class="btn btn-outline-secondary" href="<?= h(IspPortal::url('requests', $q)) ?>"><i class="bi bi-arrow-left-right"></i> Upgrade, move or suspend</a>
    </div>
  </section>
<?php endforeach; ?>
</div>

<?php $instr = IspSetup::get('isp_payment_instructions'); if ($instr !== '' && $accounts): ?>
<div class="pp-card pp-reveal pp-howto"><h2 class="pp-sec">How to pay</h2><p class="mb-0"><?= nl2br(h($instr)) ?></p></div>
<?php endif; ?>
<?php
portal_end();
