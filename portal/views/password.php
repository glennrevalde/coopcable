<?php
declare(strict_types=1);

defined('APP_PORTAL') || exit;   // opened only through portal/index.php

$c = portal_customer();
$forced = (int)$c['portal_user']['must_change_password'] === 1;
portal_post(function () use ($c) {
    IspPortal::changePassword($c, (string)($_POST['old'] ?? ''), (string)($_POST['new'] ?? ''), (string)($_POST['new2'] ?? ''));
    redirect(IspPortal::url('home'), 'Your password is changed.', 'success');
});
portal_start($forced ? 'Choose your own password' : 'Change password', ['back' => $forced ? null : [IspPortal::url('profile'), 'Profile']]);
?>
<div class="pp-card pp-narrow">
  <?php if ($forced): ?><p>You logged in with a temporary password from our office. Choose your own password to continue.</p><?php endif; ?>
  <form method="post" action="<?= h(IspPortal::url('password')) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <div class="mb-3"><label class="form-label" for="old"><?= $forced ? 'Temporary password' : 'Current password' ?></label><input class="form-control" id="old" type="password" name="old" required autocomplete="current-password"></div>
    <div class="mb-3"><label class="form-label" for="new">New password</label><input class="form-control" id="new" type="password" name="new" required minlength="8" autocomplete="new-password">
      <div class="form-text">At least 8 characters, with letters and numbers.</div></div>
    <div class="mb-3"><label class="form-label" for="new2">Type the new password again</label><input class="form-control" id="new2" type="password" name="new2" required minlength="8" autocomplete="new-password"></div>
    <button class="btn btn-primary">Save the new password</button>
  </form>
</div>
<?php
portal_end();
