<?php
declare(strict_types=1);

defined('APP_PORTAL') || exit;   // opened only through portal/index.php

// The page the payment provider sends the subscriber back to. It never marks anything paid: it only shows what the
// provider's confirmed notices (webhooks) have posted so far.
$a = portal_account();
if (!$a) {
    redirect(IspPortal::url('home'));
}
$recent = DB::all("SELECT * FROM isp_payments WHERE account_id = :a AND channel = 'gateway' AND created_at >= :t ORDER BY id DESC", ['a' => $a['id'], 't' => date('Y-m-d H:i:s', time() - 3 * 3600)]);
portal_start('Payment', ['back' => [IspPortal::url('home'), 'Home']]);
?>
<div class="pp-card pp-narrow pp-reveal">
  <?php if ($recent): ?>
    <p><i class="bi bi-check-circle text-success"></i> Your online payment<?= count($recent) > 1 ? 's were' : ' was' ?> confirmed by the provider:</p>
    <?php foreach ($recent as $r): ?><div class="pp-kv"><span><?= h($r['receipt_no']) ?>, <?= h(fdate($r['pay_date'])) ?></span><strong><?= h(money($r['amount'])) ?></strong></div><?php endforeach; ?>
  <?php else: ?>
    <p>Thank you. If you completed the payment, it shows on your account once the payment provider confirms it to us, usually within a few minutes.
      You do not need to report it again. If it is not there after an hour, keep the provider's reference number and contact our office.</p>
  <?php endif; ?>
  <a class="btn btn-primary btn-sm" href="<?= h(IspPortal::url('payments', ['account' => (int)$a['id']])) ?>">See my payments</a>
  <a class="btn btn-outline-secondary btn-sm" href="<?= h(IspPortal::url('payreturn', ['account' => (int)$a['id']])) ?>"><i class="bi bi-arrow-clockwise"></i> Check again</a>
</div>
<?php
portal_end();
