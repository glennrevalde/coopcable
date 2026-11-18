<?php
declare(strict_types=1);

defined('APP_PORTAL') || exit;   // opened only through portal/index.php

if (IspPortal::customer()) {
    redirect(IspPortal::url('home'));
}
$on = IspSetup::get('isp_portal_self_register') === '1';
portal_post(function () {
    IspPortal::register((string)p('account_no'), (string)p('mobile4'), (string)($_POST['password'] ?? ''), (string)($_POST['password2'] ?? ''));
    $a = IspAccounts::byNo((string)p('account_no'));
    redirect(IspPortal::url('login', ['u' => $a['customer_no'] ?? '']), 'Your portal account is ready. Log in with your customer number ' . ($a['customer_no'] ?? '') . ' and the password you chose.', 'success');
});
portal_start('Register', ['bare' => true, 'no_title' => true]);
?>
<div class="pp-auth">
  <div class="pp-card pp-auth-card">
    <h1 class="pp-auth-title">Register for the portal</h1>
    <?= portal_error() ?>
    <?php if (!$on): ?>
      <p>Online registration is switched off. Please call or visit our office and we will open your portal account.</p>
      <a class="btn btn-outline-primary w-100" href="<?= h(IspPortal::url('login')) ?>">Back to log in</a>
    <?php else: ?>
    <p class="text-muted small">We check that it is you with your account number (on your bill) and the last four digits of the mobile number we have on file.</p>
    <form method="post" action="<?= h(IspPortal::url('register')) ?>" autocomplete="off">
      <?= csrf_field() ?>
      <div class="mb-3"><label class="form-label" for="an">Account number</label><input class="form-control" id="an" name="account_no" value="<?= h(p('account_no')) ?>" required inputmode="numeric"></div>
      <div class="mb-3"><label class="form-label" for="m4">Last 4 digits of your mobile number</label><input class="form-control" id="m4" name="mobile4" value="<?= h(p('mobile4')) ?>" required inputmode="numeric" maxlength="4" pattern="[0-9]{4}"></div>
      <div class="mb-3"><label class="form-label" for="pw">Choose a password</label><input class="form-control" id="pw" type="password" name="password" required minlength="8" autocomplete="new-password">
        <div class="form-text">At least 8 characters, with letters and numbers.</div></div>
      <div class="mb-3"><label class="form-label" for="pw2">Type the password again</label><input class="form-control" id="pw2" type="password" name="password2" required minlength="8" autocomplete="new-password"></div>
      <p class="small text-muted"><?= h(IspSetup::get('isp_privacy_notice')) ?></p>
      <button class="btn btn-primary w-100">Register</button>
    </form>
    <div class="pp-auth-foot">Already registered? <a href="<?= h(IspPortal::url('login')) ?>">Log in</a></div>
    <?php endif; ?>
  </div>
</div>
<?php
portal_end();
