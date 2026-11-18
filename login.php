<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/views/layout.php';

// already logged in: into a company, or (several companies) on the platform pages as a Super Admin
if (!DB::isBlocked() && Auth::user()) {
    redirect(url('home.dashboard'));
}
if (Tenant::isMulti() && !Tenant::id() && Platform::admin()) {
    redirect(purl());
}

$error = '';
if (is_post()) {
    verify_csrf();
    // which company (or the platform) the username belongs to is decided on the server: app/Login.php
    [$ok, $error, $to] = Login::attempt((string)p('username'), (string)($_POST['password'] ?? ''));
    if ($ok) {
        redirect($to);
    }
}
$app = app_name();
$multi = Tenant::isMulti();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<?= head_assets() ?>
<title>Log in - <?= h($app) ?></title>
</head>
<body class="login-bg">
<?= net_background() ?>
<div class="login-wrap">
  <div class="card login-card">
    <div class="card-body p-4 p-md-5">
      <div class="d-flex align-items-center gap-3 mb-4">
        <span class="brand-tile"><?= brand_mark(48, 'gsmlogin') ?></span>
        <div class="min-w-0"><h1 class="login-title"><?= h($app) ?></h1><div class="login-sub"><?= $multi ? 'Log in to your company' : h(Settings::get('company_name')) ?></div></div>
      </div>
      <?php foreach (take_flash() as [$type, $msg]): ?>
        <div class="alert alert-<?= h($type) ?> py-2"><?= h($msg) ?></div>
      <?php endforeach; ?>
      <?php if ($error): ?><div class="alert alert-danger py-2"><?= h($error) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label" for="lgUser">Username</label>
          <div class="input-icon"><i class="bi bi-person"></i><input class="form-control" id="lgUser" name="username" value="<?= h(p('username')) ?>" required autofocus autocomplete="username" autocapitalize="none"></div></div>
        <div class="mb-4"><label class="form-label" for="lgPass">Password</label>
          <div class="input-icon"><i class="bi bi-lock"></i><input class="form-control" id="lgPass" type="password" name="password" required autocomplete="current-password"></div></div>
        <button class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right"></i> Log in</button>
      </form>
      <?php if ($multi): ?>
      <div class="login-join mt-3">New employee? <a href="<?= h(base_url() . '/register.php') ?>">Join your company with its Company Code</a></div>
      <?php endif; ?>
      <div class="login-foot"><i class="bi bi-shield-check"></i><span>Every log-in, failed attempt and action is recorded in the audit trail.</span></div>
    </div>
  </div>
</div>
<div class="login-footer">Subscriber? <a href="<?= h(base_url() . '/portal/index.php') ?>">Open the customer portal</a> to see your bills, pay and ask for support.</div>
</body>
</html>
