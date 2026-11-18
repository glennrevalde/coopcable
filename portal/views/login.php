<?php
declare(strict_types=1);

defined('APP_PORTAL') || exit;   // opened only through portal/index.php

if (IspPortal::customer()) {
    redirect(IspPortal::url('home'));
}
portal_post(function () {
    IspPortal::login((string)p('username'), (string)($_POST['password'] ?? ''));
    redirect(IspPortal::url('home'));
});
$selfReg = IspSetup::get('isp_portal_self_register') === '1';
$company = Tenant::company();
portal_start('Log in', ['bare' => true, 'no_title' => true]);
?>
<div class="pp-auth">
  <div class="pp-card pp-auth-card">
    <h1 class="pp-auth-title">Log in to your account</h1>
    <p class="text-muted small mb-4">See your bills, pay, and report a problem with your connection.</p>
    <?= portal_error() ?>
    <form method="post" action="<?= h(IspPortal::url('login')) ?>" autocomplete="on">
      <?= csrf_field() ?>
      <div class="mb-3"><label class="form-label" for="u">Customer no. or account no.</label>
        <input class="form-control" id="u" name="username" value="<?= h((string)(p('username') ?: g('u'))) ?>" required autofocus autocomplete="username" inputmode="text"></div>
      <div class="mb-2"><label class="form-label" for="pw">Password</label>
        <div class="pp-pw"><input class="form-control" id="pw" type="password" name="password" required autocomplete="current-password">
          <button class="pp-pw-eye" type="button" data-show-pw="pw" aria-label="Show password"><i class="bi bi-eye"></i></button></div></div>
      <div class="text-end mb-3"><a class="small" href="<?= h(IspPortal::url('forgot')) ?>">Forgot password?</a></div>
      <button class="btn btn-primary w-100">Log in</button>
    </form>
    <div class="pp-auth-foot">
      <?php if ($selfReg): ?>First time here? <a href="<?= h(IspPortal::url('register')) ?>">Register with your account number</a><?php else: ?>No password yet? Ask our office to open your portal account.<?php endif; ?>
      <?php if ($company && Master::enabled()): ?><div class="mt-2 small text-muted"><?= h($company['name']) ?>. <a href="<?= h(IspPortal::url('company', ['change' => 1])) ?>">Not your provider?</a></div><?php endif; ?>
    </div>
  </div>
</div>
<?php
portal_end();
