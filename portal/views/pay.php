<?php
declare(strict_types=1);

defined('APP_PORTAL') || exit;   // opened only through portal/index.php

// Online payment: the subscriber is sent to the payment provider's own checkout. Nothing is marked paid here or on
// the return page: the payment is posted only when the provider's signed webhook arrives (api/gateway.php).
$a = portal_account();
if (!$a) {
    redirect(IspPortal::url('home'));
}
if (!IspGateway::enabled()) {
    redirect(IspPortal::url('paid', ['account' => (int)$a['id']]), 'Online payment is not available. Report your GCash, Maya or bank payment here instead.', 'warning');
}
portal_post(function () {
    $acct = portal_account((int)p('account'));
    $amount = r2(to_num(p('amount')));
    if ($amount < 1) {
        fail('Enter the amount to pay.');
    }
    if ($amount > 200000) {
        fail('For amounts this large, please pay at our office or by bank transfer.');
    }
    $return = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . IspPortal::url('payreturn', ['account' => (int)$acct['id']]);
    $url = IspGateway::checkout(IspAccounts::get((int)$acct['id']), $amount, $return);
    if (!preg_match('~^https?://~i', $url)) {
        fail('The payment page could not be opened. Please try again later.');
    }
    IspAccounts::event((int)$acct['id'], 'payment', 'Online payment started on the portal: ' . money($amount));
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Location: ' . $url);
    exit;
});
$fee = IspSetup::float('isp_gateway_fee_pct');
$fixed = IspSetup::float('isp_gateway_fee_fixed');
$amount = is_post() ? (string)p('amount') : (g('amount') !== '' ? number_format(to_num(g('amount')), 2, '.', '') : number_format(max(0, (float)$a['balance']), 2, '.', ''));
portal_start('Pay online', ['back' => [IspPortal::url('home'), 'Home'], 'actions' => portal_account_switch($a, 'pay')]);
?>
<form class="pp-card pp-narrow pp-reveal" method="post" action="<?= h(IspPortal::url('pay', ['account' => (int)$a['id']])) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="account" value="<?= (int)$a['id'] ?>">
  <div class="pp-kv"><span>Account</span><strong><?= h($a['account_no']) ?></strong></div>
  <div class="pp-kv"><span>Amount due</span><strong><?= h(money(max(0, (float)$a['balance']))) ?></strong></div>
  <label class="form-label mt-3" for="amt">Amount to pay</label>
  <input class="form-control form-control-lg" id="amt" name="amount" value="<?= h($amount) ?>" inputmode="decimal" required>
  <p class="small text-muted mt-2">You will continue on the secure page of <?= h(IspGateway::PROVIDERS[IspSetup::get('isp_gateway_provider')] ?? 'the payment provider') ?> (GCash, Maya, cards and other channels it offers).
    <?= $fee > 0 || $fixed > 0 ? 'The provider may add a convenience fee.' : '' ?> Your payment shows on your account as soon as the provider confirms it to us.</p>
  <button class="btn btn-primary w-100"><i class="bi bi-lock"></i> Continue to payment</button>
</form>
<?php
portal_end();
