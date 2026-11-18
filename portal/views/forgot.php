<?php
declare(strict_types=1);

defined('APP_PORTAL') || exit;   // opened only through portal/index.php

if (IspPortal::customer()) {
    redirect(IspPortal::url('home'));
}
$on = IspSetup::get('isp_portal_self_register') === '1';
portal_post(function () use ($on) {
    if (!$on) {
        fail('Please call or visit our office to reset your portal password.');
    }
    $user = IspPortal::resetPassword((string)p('account_no'), (string)p('mobile4'), (string)($_POST['password'] ?? ''), (string)($_POST['password2'] ?? ''));
    redirect(IspPortal::url('login'), 'Your new password is set. Log in with ' . $user . '.', 'success');
});
portal_start('Forgot password', ['bare' => true, 'no_title' => true]);
?>
<div class="pp-auth">
  <div class="pp-card pp-auth-card">
    <h1 class="pp-auth-title">Set a new password</h1>
    <?= portal_error() ?>
    <?php if (!$on): ?>
      <p>Please call or visit our office to reset your portal password. Bring a valid ID.</p>
      <a class="btn btn-outline-primary w-100" href="<?= h(IspPortal::url('login')) ?>">Back to log in</a>
    <?php else: ?>
    <p class="text-muted small">Prove it is you with your account number and the last four digits of the mobile number we have on file. After 5 wrong tries the portal account is locked and only our office can open it.</p>
    <form method="post" action="<?= h(IspPortal::url('forgot')) ?>" autocomplete="off">
      <?= csrf_field() ?>
      <div class="mb-3"><label class="form-label" for="an">Account number</label><input class="form-control" id="an" name="account_no" value="<?= h(p('account_no')) ?>" required inputmode="numeric"></div>
      <div class="mb-3"><label class="form-label" for="m4">Last 4 digits of your mobile number</label><input class="form-control" id="m4" name="mobile4" required inputmode="numeric" maxlength="4" pattern="[0-9]{4}"></div>
      <div class="mb-3"><label class="form-label" for="pw">New password</label><input class="form-control" id="pw" type="password" name="password" required minlength="8" autocomplete="new-password">
        <div class="form-text">At least 8 characters, with letters and numbers.</div></div>
      <div class="mb-3"><label class="form-label" for="pw2">Type it again</label><input class="form-control" id="pw2" type="password" name="password2" required minlength="8" autocomplete="new-password"></div>
      <button class="btn btn-primary w-100">Set the new password</button>
    </form>
    <div class="pp-auth-foot"><a href="<?= h(IspPortal::url('login')) ?>">Back to log in</a></div>
    <?php endif; ?>
  </div>
</div>
<?php
portal_end();
