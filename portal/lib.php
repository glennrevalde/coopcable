<?php
declare(strict_types=1);

defined('APP_PORTAL') || exit;   // opened only through portal/index.php

/**
 * The shared parts of the customer portal pages: the page frame, the chosen account, uploads and the few look-ups
 * every page needs. Every record shown is read through the logged-in subscriber's own accounts (IspPortal::account()).
 */

const PORTAL_MAX_UPLOAD = 5 * 1024 * 1024;
const PORTAL_UPLOAD_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

/** Runs the POST handler of a page; a refusal (fail()) is shown on the page instead of an error page */
function portal_post(callable $fn): void
{
    if (!is_post()) {
        return;
    }
    try {
        $fn();
    } catch (UserError $e) {
        if (DB::inTransaction()) {
            DB::rollBack();
        }
        $GLOBALS['PORTAL_ERROR'] = $e->getMessage();
    }
}

/** The logged-in subscriber (log-in page otherwise; the forced password change first) */
function portal_customer(): array
{
    $c = IspPortal::require();
    if ((int)$c['portal_user']['must_change_password'] === 1 && ($GLOBALS['PORTAL_VIEW'] ?? '') !== 'password') {
        redirect(IspPortal::url('password'));
    }
    return $c;
}

/** The subscriber's accounts (cached for the request) */
function portal_accounts(): array
{
    static $rows = null;
    if ($rows === null) {
        $rows = IspPortal::accounts((int)portal_customer()['id']);
    }
    return $rows;
}

/** The account a page works on: ?account= (checked to be the subscriber's own) or the first one */
function portal_account(?int $id = null): ?array
{
    $id ??= (int)($_REQUEST['account'] ?? 0);
    if ($id > 0) {
        IspPortal::account($id);
        foreach (portal_accounts() as $a) {
            if ((int)$a['id'] === $id) {
                return $a;
            }
        }
        return IspAccounts::get($id) + IspAccounts::balances($id);
    }
    return portal_accounts()[0] ?? null;
}

/** A drop-down to switch between the subscriber's accounts (only when there are several) */
function portal_account_switch(?array $current, string $view, array $keep = []): string
{
    $accounts = portal_accounts();
    if (count($accounts) < 2 || !$current) {
        return '';
    }
    $out = '<form class="pp-switch" method="get" action="' . h(base_url() . '/portal/index.php') . '"><input type="hidden" name="v" value="' . h($view) . '">';
    foreach ($keep as $k => $v) {
        $out .= '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
    }
    $out .= '<label class="visually-hidden" for="ppAcct">Account</label><select id="ppAcct" class="form-select form-select-sm" name="account" data-autosubmit>';
    foreach ($accounts as $a) {
        $out .= '<option value="' . (int)$a['id'] . '"' . ((int)$a['id'] === (int)$current['id'] ? ' selected' : '') . '>' . h($a['account_no'] . ' - ' . $a['plan_name']) . '</option>';
    }
    return $out . '</select><noscript><button class="btn btn-sm btn-outline-light">Show</button></noscript></form>';
}

/** The value of a form field: what was posted (the form is shown again after a refusal), else the record's */
function portal_fv(string $key, ?array $record = null, $default = '')
{
    if (is_post() && array_key_exists($key, $_POST) && is_string($_POST[$key])) {
        return $_POST[$key];
    }
    return is_array($record) && array_key_exists($key, $record) ? ($record[$key] ?? $default) : $default;
}

function portal_error(): string
{
    $e = $GLOBALS['PORTAL_ERROR'] ?? null;
    return $e ? '<div class="pp-alert pp-alert-bad" role="alert"><i class="bi bi-exclamation-octagon"></i><div>' . h($e) . '</div></div>' : '';
}

/** The company logo through the portal (the staff logo link needs a staff log-in) */
function portal_logo_url(): string
{
    $file = Logo::path();
    return $file ? IspPortal::url('logo', ['t' => (string)filemtime($file)]) : '';
}

/** The letterhead of a printed document, with the logo served by the portal */
function portal_letterhead(string $title, string $right = ''): string
{
    $html = IspUi::letterhead($title, $right);
    $staff = Logo::path() ? Logo::url() : '';
    return $staff !== '' ? str_replace(h($staff), h(portal_logo_url()), $html) : $html;
}

/** The ids of an area and every area above it (an announcement for a town reaches its barangays) */
function portal_area_chain(?int $areaId): array
{
    $out = [];
    $guard = 0;
    while ($areaId && $guard++ < 12) {
        $out[] = $areaId;
        $areaId = (int)(DB::value('SELECT parent_id FROM isp_areas WHERE id = :a', ['a' => $areaId]) ?: 0);
    }
    return $out;
}

/** Announcements running now for everyone or for the areas of the subscriber's accounts */
function portal_announcements(array $accounts, int $limit = 20): array
{
    $areas = [];
    foreach ($accounts as $a) {
        $areas = array_merge($areas, portal_area_chain($a['area_id'] ? (int)$a['area_id'] : null));
    }
    $areas = array_values(array_unique($areas));
    $p = ['n1' => date('Y-m-d H:i:s'), 'n2' => date('Y-m-d H:i:s')];
    $areaSql = 'a.area_id IS NULL';
    if ($areas) {
        [$in, $ip] = DB::in('ar', $areas);
        $areaSql = '(a.area_id IS NULL OR a.area_id IN ' . $in . ')';
        $p += $ip;
    }
    return DB::all("SELECT a.* FROM isp_announcements a WHERE a.is_active = 1 AND a.starts_at <= :n1 AND (a.ends_at IS NULL OR a.ends_at > :n2) AND $areaSql
                     ORDER BY a.starts_at DESC LIMIT " . (int)$limit, $p);
}

/** The outages affecting an account now */
function portal_outages(int $accountId): array
{
    return DB::all("SELECT o.* FROM isp_outages o JOIN isp_outage_accounts oa ON oa.outage_id = o.id
                     WHERE oa.account_id = :a AND o.status IN ('suspected', 'verified', 'in_repair') ORDER BY o.started_at DESC", ['a' => $accountId]);
}

function portal_unread(int $customerId): int
{
    return (int)DB::value("SELECT COUNT(*) FROM isp_notifications WHERE isp_customer_id = :c AND channel = 'inapp' AND read_at IS NULL AND status IN ('sent', 'queued')", ['c' => $customerId]);
}

// ------------------------------------------------------------------ uploads

/**
 * The folder of the uploaded files of this company (storage/isp, or the company's own folder): never served by the
 * web server (.htaccess + index.html); files are read back only through views/file.php. Like the staff pages, a file
 * is kept in a sub-folder per kind of record and isp_files.stored_name is the path below storage/isp.
 */
function portal_upload_dir(string $entity = ''): string
{
    $base = Tenant::storage('isp');
    Master::protectDir($base);
    if ($entity === '') {
        return $base;
    }
    $dir = Tenant::storage('isp/' . preg_replace('/[^a-z_]/', '', $entity));
    Master::protectDir($dir);
    return $dir;
}

/** The full path of a stored file, or null (never outside storage/isp) */
function portal_file_path(array $f): ?string
{
    $base = realpath(portal_upload_dir());
    if (!$base) {
        return null;
    }
    $path = realpath($base . '/' . str_replace('\\', '/', (string)$f['stored_name']));
    if (!$path) {
        $hits = glob($base . '/*/' . basename((string)$f['stored_name'])) ?: [];
        $path = $hits ? realpath($hits[0]) : false;
    }
    return $path && is_file($path) && str_starts_with($path, $base . DIRECTORY_SEPARATOR) ? $path : null;
}

/**
 * Checks an uploaded file before anything is saved: images (JPG, PNG, WebP) or PDF, 5 MB at most, the type read from
 * the file itself (not from its name). Returns null when nothing was uploaded and none is required.
 */
function portal_upload_check(string $field, bool $required = false): ?array
{
    $f = $_FILES[$field] ?? null;
    if (!$f || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            fail('Attach the file.');
        }
        return null;
    }
    if ((int)$f['error'] !== UPLOAD_ERR_OK) {
        fail((int)$f['error'] === UPLOAD_ERR_INI_SIZE || (int)$f['error'] === UPLOAD_ERR_FORM_SIZE ? 'The file is too big (5 MB at most).' : 'The file could not be uploaded. Please try again.');
    }
    if ((int)$f['size'] > PORTAL_MAX_UPLOAD) {
        fail('The file is too big (5 MB at most).');
    }
    if (!is_uploaded_file((string)$f['tmp_name'])) {
        fail('The file could not be uploaded. Please try again.');
    }
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string)finfo_file($fi, (string)$f['tmp_name']);
        finfo_close($fi);
    } elseif (($img = @getimagesize((string)$f['tmp_name'])) !== false) {
        $mime = (string)$img['mime'];
    }
    if (!isset(PORTAL_UPLOAD_TYPES[$mime])) {
        fail('Attach a photo (JPG, PNG or WebP) or a PDF file.');
    }
    return ['tmp' => (string)$f['tmp_name'], 'name' => mb_substr(basename((string)$f['name']), 0, 160), 'mime' => $mime, 'size' => (int)$f['size'], 'ext' => PORTAL_UPLOAD_TYPES[$mime]];
}

/** Stores a checked upload under a random name and records it (isp_files). Returns the file id. */
function portal_upload_store(array $f, string $entity, int $entityId, int $portalUserId, string $label): int
{
    $name = bin2hex(random_bytes(16)) . '.' . $f['ext'];
    $dir = portal_upload_dir($entity);
    if (!@move_uploaded_file($f['tmp'], $dir . '/' . $name)) {
        fail('The file could not be saved. Please try again, or show it at our office.');
    }
    @chmod($dir . '/' . $name, 0640);
    return DB::insert('isp_files', ['entity_type' => $entity, 'entity_id' => $entityId, 'label' => mb_substr($label, 0, 120), 'stored_name' => $entity . '/' . $name,
        'original_name' => $f['name'], 'mime' => $f['mime'], 'size_bytes' => $f['size'], 'portal_user_id' => $portalUserId]);
}

/** The files of a record the subscriber may open */
function portal_files(string $entity, int $entityId): array
{
    return DB::all('SELECT * FROM isp_files WHERE entity_type = :e AND entity_id = :i ORDER BY id', ['e' => $entity, 'i' => $entityId]);
}

// ------------------------------------------------------------------ the page frame

/** The menu: view => [label, icon, in the bottom bar on phones] */
function portal_menu(): array
{
    return [
        'home' => ['Home', 'house-door', true],
        'invoices' => ['Bills', 'receipt', true],
        'payments' => ['Payments', 'wallet2', false],
        'paid' => ['Pay', 'qr-code-scan', true],
        'tickets' => ['Support', 'headset', true],
        'requests' => ['Requests', 'arrow-left-right', false],
        'profile' => ['Profile', 'person-circle', true],
    ];
}

/**
 * Starts a portal page. $opt: actions (html in the page bar), bare (no menu: log-in pages), print (a document page),
 * back ([url, label]) a link back above the title.
 */
function portal_start(string $title, array $opt = []): void
{
    $GLOBALS['PORTAL_BARE'] = !empty($opt['bare']);
    $company = DB::isBlocked() ? '' : Settings::get('company_name');
    $logo = DB::isBlocked() ? '' : portal_logo_url();
    $c = $GLOBALS['PORTAL_BARE'] ? null : IspPortal::customer();
    $view = (string)($GLOBALS['PORTAL_VIEW'] ?? '');
    $unread = $c ? portal_unread((int)$c['id']) : 0;
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0b1630">
<meta name="robots" content="noindex">
<title><?= h($title) ?><?= $company !== '' ? ' - ' . h($company) : '' ?></title>
<link rel="stylesheet" href="<?= h(vendor_asset('bootstrap.css')) ?>">
<link rel="stylesheet" href="<?= h(vendor_asset('icons.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('css/portal.css')) ?>">
</head>
<body class="<?= h(trim('portal ' . ($GLOBALS['PORTAL_BARE'] ? 'pp-bare ' : 'pp-in ') . (string)($opt['body_class'] ?? ''))) ?>">
<header class="pp-top no-print">
  <div class="pp-top-in">
    <a class="pp-brand" href="<?= h(IspPortal::url($c ? 'home' : 'login')) ?>">
      <?php if ($logo !== ''): ?><img class="pp-brand-logo" src="<?= h($logo) ?>" alt=""><?php else: ?><span class="pp-brand-mark" aria-hidden="true"><i class="bi bi-router"></i></span><?php endif; ?>
      <span class="pp-brand-text"><strong><?= h($company !== '' ? $company : app_name()) ?></strong><span>Subscriber portal</span></span>
    </a>
    <?php if ($c): ?>
    <nav class="pp-nav" aria-label="Portal">
      <?php foreach (portal_menu() as $k => [$label, $icon]): ?>
        <a class="pp-nav-link<?= $view === $k || ($view === 'invoice' && $k === 'invoices') || ($view === 'ticket' && $k === 'tickets') || ($view === 'receipt' && $k === 'payments') ? ' is-active' : '' ?>" href="<?= h(IspPortal::url($k)) ?>"><i class="bi bi-<?= h($icon) ?>"></i><span><?= h($label) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <div class="pp-top-tools">
      <a class="pp-bell<?= $unread ? ' has-new' : '' ?>" href="<?= h(IspPortal::url('notifications')) ?>" title="Notifications" aria-label="Notifications<?= $unread ? ', ' . $unread . ' unread' : '' ?>">
        <i class="bi bi-bell<?= $unread ? '-fill' : '' ?>"></i><?php if ($unread): ?><span class="pp-bell-n"><?= $unread > 99 ? '99+' : $unread ?></span><?php endif; ?></a>
      <div class="dropdown">
        <button class="pp-user dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-person-circle"></i><span class="pp-user-name"><?= h($c['name']) ?></span></button>
        <ul class="dropdown-menu dropdown-menu-end pp-menu">
          <li><span class="dropdown-item-text small text-muted">Customer no. <?= h($c['customer_no']) ?></span></li>
          <li><a class="dropdown-item" href="<?= h(IspPortal::url('soa')) ?>"><i class="bi bi-file-earmark-text"></i> Statement of account</a></li>
          <li><a class="dropdown-item" href="<?= h(IspPortal::url('payments')) ?>"><i class="bi bi-wallet2"></i> Payment history</a></li>
          <li><a class="dropdown-item" href="<?= h(IspPortal::url('requests')) ?>"><i class="bi bi-arrow-left-right"></i> Service requests</a></li>
          <li><a class="dropdown-item" href="<?= h(IspPortal::url('announcements')) ?>"><i class="bi bi-megaphone"></i> Announcements</a></li>
          <li><a class="dropdown-item" href="<?= h(IspPortal::url('notifications')) ?>"><i class="bi bi-bell"></i> Notifications</a></li>
          <li><a class="dropdown-item" href="<?= h(IspPortal::url('password')) ?>"><i class="bi bi-key"></i> Change password</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><form method="post" action="<?= h(IspPortal::url('logout')) ?>"><?= csrf_field() ?><button class="dropdown-item"><i class="bi bi-box-arrow-right"></i> Log out</button></form></li>
        </ul>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <div class="pp-signal" aria-hidden="true"><span></span></div>
</header>
<main class="pp-main<?= !empty($opt['print']) ? ' pp-main-doc' : '' ?>" id="main">
  <?php foreach (take_flash() as [$type, $msg]): $tone = ['success' => 'ok', 'danger' => 'bad', 'warning' => 'warn'][$type] ?? 'info'; ?>
    <div class="pp-alert pp-alert-<?= h($tone) ?> no-print" role="status"><i class="bi bi-<?= $tone === 'ok' ? 'check-circle' : ($tone === 'bad' ? 'exclamation-octagon' : ($tone === 'warn' ? 'exclamation-triangle' : 'info-circle')) ?>"></i><div><?= h($msg) ?></div></div>
  <?php endforeach; ?>
  <?php if (empty($opt['no_title'])): ?>
  <div class="pp-bar">
    <div class="pp-bar-l">
      <?php if (!empty($opt['back'])): ?><a class="pp-back no-print" href="<?= h($opt['back'][0]) ?>"><i class="bi bi-arrow-left"></i> <?= h($opt['back'][1]) ?></a><?php endif; ?>
      <h1><?= h($title) ?></h1>
    </div>
    <?php if (!empty($opt['actions'])): ?><div class="pp-bar-actions no-print"><?= $opt['actions'] ?></div><?php endif; ?>
  </div>
  <?= portal_error() ?>
  <?php endif; ?>
<?php
}

function portal_end(): void
{
    $c = empty($GLOBALS['PORTAL_BARE']) ? IspPortal::customer() : null;
    $view = (string)($GLOBALS['PORTAL_VIEW'] ?? '');
    ?>
</main>
<?php if ($c): ?>
<nav class="pp-tabbar no-print" aria-label="Portal shortcuts">
  <?php foreach (portal_menu() as $k => [$label, $icon, $inBar]): if (!$inBar) continue; ?>
  <a class="pp-tab<?= $view === $k || ($view === 'invoice' && $k === 'invoices') || ($view === 'ticket' && $k === 'tickets') || ($view === 'pay' && $k === 'paid') ? ' is-active' : '' ?>" href="<?= h(IspPortal::url($k)) ?>"><i class="bi bi-<?= h($icon) ?>"></i><span><?= h($label) ?></span></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
<footer class="pp-foot no-print">
  <span><?= h(DB::isBlocked() ? app_name() : Settings::get('company_name')) ?></span>
  <?php if (!DB::isBlocked() && IspSetup::ready()): ?><span class="pp-foot-privacy"><?= h(IspSetup::get('isp_privacy_notice')) ?></span><?php endif; ?>
</footer>
<script src="<?= h(vendor_asset('bootstrap.js')) ?>"></script>
<script src="<?= h(asset('js/portal.js')) ?>"></script>
</body>
</html>
<?php
}

/** A status as plain text with its signal light */
function portal_status(?string $v, array $labels = []): string
{
    return IspUi::status($v, $labels);
}

/** An empty list */
function portal_empty(string $text, string $icon = 'inbox'): string
{
    return '<div class="pp-empty"><i class="bi bi-' . h($icon) . '"></i><p>' . h($text) . '</p></div>';
}

/** The earliest due date of the account's open bills */
function portal_next_due(int $accountId): ?string
{
    $d = DB::value("SELECT MIN(due_date) FROM isp_invoices WHERE account_id = :a AND status IN ('posted', 'partially_paid', 'overdue', 'disputed') AND balance > 0", ['a' => $accountId]);
    return $d ? (string)$d : null;
}

function portal_last_payment(int $accountId): ?array
{
    return DB::row("SELECT * FROM isp_payments WHERE account_id = :a AND status = 'posted' ORDER BY pay_date DESC, id DESC LIMIT 1", ['a' => $accountId]);
}

/** The bills a subscriber sees: posted ones (drafts are the office's work in progress) */
const PORTAL_BILL_STATUSES = ['posted', 'partially_paid', 'paid', 'overdue', 'disputed', 'adjusted', 'voided'];

/** How a bill's state reads to a subscriber */
const PORTAL_BILL_LABELS = ['posted' => 'Unpaid', 'partially_paid' => 'Partly paid', 'paid' => 'Paid', 'overdue' => 'Overdue', 'disputed' => 'Under review',
    'adjusted' => 'Settled by adjustment', 'voided' => 'Cancelled'];

/**
 * The statement of an account from the general ledger (the subscriber receivable lines of the documents posted for
 * the account): [opening balance, rows [date, doc, description, charges, payments, balance]]
 */
function portal_statement(int $accountId, string $from, string $to): array
{
    $ar = Accts::arIds();
    if (!$ar) {
        return [0.0, []];
    }
    [$ain, $ap] = DB::in('ar', $ar);
    $base = "FROM journal_lines j JOIN transactions t ON t.id = j.txn_id
             WHERE j.account_id IN $ain AND t.is_voided = 0
               AND j.txn_id IN (SELECT DISTINCT p.txn_id FROM isp_postings p WHERE p.isp_account_id = :acct AND p.status = 'posted' AND p.txn_id IS NOT NULL)";
    $opening = (float)DB::value("SELECT COALESCE(SUM(j.debit - j.credit), 0) $base AND j.txn_date < :f", $ap + ['acct' => $accountId, 'f' => $from]);
    $rows = [];
    $bal = $opening;
    $labels = ['invoice' => 'Bill', 'payment' => 'Payment', 'reversal' => 'Payment reversed', 'gateway_fee' => 'Online payment fee', 'credit_apply' => 'Advance payment applied',
        'auto_debit' => 'Savings auto-debit', 'adjustment' => 'Adjustment', 'outage_credit' => 'Outage service credit', 'deposit' => 'Security deposit'];
    foreach (DB::all("SELECT t.id, j.txn_date AS d, t.memo, SUM(j.debit - j.credit) AS amt $base AND j.txn_date BETWEEN :f AND :t
                       GROUP BY t.id, j.txn_date, t.memo HAVING ABS(SUM(j.debit - j.credit)) >= 0.005 ORDER BY j.txn_date, t.id", $ap + ['acct' => $accountId, 'f' => $from, 't' => $to]) as $r) {
        $post = DB::row('SELECT source_type, doc_no FROM isp_postings WHERE txn_id = :t AND isp_account_id = :a ORDER BY id LIMIT 1', ['t' => $r['id'], 'a' => $accountId]);
        $amt = r2((float)$r['amt']);
        $bal = r2($bal + $amt);
        $type = (string)($post['source_type'] ?? '');
        $rows[] = ['date' => (string)$r['d'], 'doc' => (string)($post['doc_no'] ?? ''), 'what' => $labels[$type] ?? ($amt > 0 ? 'Charge' : 'Credit'),
            'memo' => (string)$r['memo'], 'charge' => $amt > 0 ? $amt : 0.0, 'credit' => $amt < 0 ? -$amt : 0.0, 'balance' => $bal];
    }
    return [r2($opening), $rows];
}

/** The channels a subscriber reports a payment by */
function portal_pay_channels(): array
{
    $out = [];
    foreach (['gcash', 'maya', 'bank_transfer', 'bank_deposit'] as $k) {
        $out[$k] = IspPayments::CHANNELS[$k];
    }
    return $out;
}

/** The concerns a subscriber reports as a support ticket (plan changes and relocation go through requests) */
function portal_ticket_types(): array
{
    return array_diff_key(IspTickets::TYPES, array_flip(['outage', 'relocation', 'plan_change']));
}

/** request type => label (isp_service_requests.request_type) */
const PORTAL_REQUEST_TYPES = ['upgrade' => 'Plan upgrade', 'downgrade' => 'Plan downgrade', 'relocation' => 'Relocation (transfer of service)', 'suspension' => 'Temporary suspension',
    'reconnection' => 'Reconnection', 'cancellation' => 'Cancellation of service', 'profile_update' => 'Change of billing details', 'other' => 'Other request'];
const PORTAL_REQUEST_STATUSES = ['open' => 'Received', 'in_progress' => 'In progress', 'done' => 'Done', 'declined' => 'Declined', 'cancelled' => 'Cancelled'];
