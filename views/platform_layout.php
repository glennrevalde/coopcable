<?php
declare(strict_types=1);

/**
 * Page frame of the Super Admin (platform) pages: the same look as the company pages, with the platform's
 * own menu. No company database is open on these pages.
 */

const PLATFORM_MENU = [
    'Companies' => [
        'companies' => ['Companies', 'buildings'],
        'new'       => ['New company', 'plus-square'],
        'updates'   => ['Database updates', 'database-check'],
    ],
    'Platform' => [
        'admins'    => ['Super Admins', 'shield-lock'],
        'settings'  => ['Platform settings', 'sliders'],
        'audit'     => ['Platform audit log', 'journal-text'],
        'account'   => ['My account', 'person-circle'],
    ],
];

function platform_start(string $title, array $opt = []): void
{
    $sa = Platform::admin();
    $current = (string)($GLOBALS['PLATFORM_PAGE'] ?? '');
    $app = app_name();
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<?= head_assets() ?>
<title><?= h($title) ?> - Platform - <?= h($app) ?></title>
<script src="<?= h(asset('js/dialog.js')) ?>"></script>
<script src="<?= h(asset('js/datefield.js')) ?>"></script>
<script src="<?= h(asset('js/fmt.js')) ?>"></script>
</head>
<body class="gs-app platform">
<nav class="navbar app-bar sticky-top no-print" aria-label="Platform">
  <div class="app-bar-left">
    <button class="btn btn-sm btn-user btn-menu d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Menu"><i class="bi bi-list"></i></button>
    <a class="navbar-brand" href="<?= h(purl()) ?>"><?= brand_html('gsmplat') ?></a>
    <span class="company d-none d-md-inline-flex"><i class="bi bi-shield-lock"></i>Platform administration</span>
  </div>
  <?php if ($sa): ?>
  <div class="app-bar-right dropdown">
    <button class="btn btn-sm btn-user dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><span class="user-avatar" aria-hidden="true"><i class="bi bi-shield-lock"></i></span><span class="user-name"><?= h($sa['full_name']) ?></span></button>
    <ul class="dropdown-menu dropdown-menu-end">
      <li><span class="dropdown-item-text small text-muted">Super Admin</span></li>
      <li><a class="dropdown-item" href="<?= h(purl('account')) ?>"><i class="bi bi-key"></i> Change password</a></li>
      <li><hr class="dropdown-divider"></li>
      <li><form method="post" action="<?= h(purl('companies', ['a' => 'logout'])) ?>"><?= csrf_field() ?><button class="dropdown-item"><i class="bi bi-box-arrow-right"></i> Log out</button></form></li>
    </ul>
  </div>
  <?php endif; ?>
  <div class="fiber-line" aria-hidden="true"><span></span></div>
</nav>
<div class="d-flex app-shell">
  <aside class="offcanvas-lg offcanvas-start sidebar no-print" id="sidebar" tabindex="-1" aria-label="Platform menu">
    <div class="offcanvas-header d-lg-none"><a class="navbar-brand" href="<?= h(purl()) ?>"><?= brand_html('gsmplatside') ?></a><button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Close"></button></div>
    <div class="offcanvas-body d-block p-0 pt-2">
      <?php foreach (PLATFORM_MENU as $group => $items): ?>
        <div class="menu-group"><?= h($group) ?></div>
        <?php foreach ($items as $k => [$name, $icon]): ?>
          <a class="menu-link platform-link <?= $k === $current ? 'active' : '' ?>" href="<?= h(purl($k)) ?>"><i class="bi bi-<?= h($icon) ?>"></i><?= h($name) ?></a>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <div class="side-foot"><span class="side-signal" aria-hidden="true"></span><span>Platform administration</span></div>
    </div>
  </aside>
  <main class="flex-grow-1 main-area" id="main">
    <?php foreach (take_flash() as [$type, $msg]): ?>
      <div class="alert alert-<?= h($type) ?> alert-dismissible fade show no-print" role="alert"><?= h($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endforeach; ?>
    <div class="page-bar">
      <h1><?= h($title) ?></h1>
      <?php if (($opt['actions'] ?? '') !== ''): ?><div class="page-actions no-print"><?= $opt['actions'] ?></div><?php endif; ?>
    </div>
    <?= form_error() ?>
<?php
}

function platform_end(): void
{
    ?>
  </main>
</div>
<script src="<?= h(vendor_asset('bootstrap.js')) ?>"></script>
<script src="<?= h(asset('js/app.js')) ?>"></script>
<script src="<?= h(asset('js/theme.js')) ?>"></script>
</body>
</html>
<?php
}

/** Company status in words, with a colour cue (plain text, no badge) */
function platform_status(string $status): string
{
    $t = ['active' => 'Active', 'inactive' => 'Inactive', 'provisioning' => 'Being set up', 'failed' => 'Set-up failed'][$status] ?? ucfirst($status);
    return '<span class="co-state co-' . h($status) . '">' . h($t) . '</span>';
}
