<?php
declare(strict_types=1);

/**
 * The customer self-service portal: index.php?v=<view>. A session of its own (app/bootstrap.php: APP_PORTAL), its own
 * log-in (isp_portal_users), and every record read through the subscriber's own accounts. Works on plain Apache /
 * XAMPP without .htaccess rules: everything goes through this one file.
 */
define('APP_PORTAL', true);
require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/lib.php';

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

/** view => [file, needs a log-in] */
$views = [
    'login' => ['login.php', false], 'register' => ['register.php', false], 'forgot' => ['forgot.php', false], 'company' => ['company.php', false],
    'logo' => ['logo.php', false], 'logout' => ['logout.php', false],
    'home' => ['home.php', true], 'password' => ['password.php', true],
    'invoices' => ['invoices.php', true], 'invoice' => ['invoice.php', true], 'soa' => ['soa.php', true],
    'payments' => ['payments.php', true], 'receipt' => ['receipt.php', true], 'paid' => ['paid.php', true], 'pay' => ['pay.php', true], 'payreturn' => ['payreturn.php', true],
    'tickets' => ['tickets.php', true], 'ticket' => ['ticket.php', true], 'requests' => ['requests.php', true],
    'profile' => ['profile.php', true], 'notifications' => ['notifications.php', true], 'announcements' => ['announcements.php', true], 'file' => ['file.php', true],
];
$v = (string)($_GET['v'] ?? 'home');
if (!isset($views[$v])) {
    $v = 'home';
}

verify_csrf();

// several companies: the subscriber's company comes first (its code, or ?co= in the link the company gives out)
if (!IspPortal::companyChosen() && $v !== 'company') {
    $v = 'company';
}
$GLOBALS['PORTAL_VIEW'] = $v;

ob_start();
try {
    if ($v !== 'company' && (!IspSetup::ready() || IspSetup::get('isp_portal_enabled') !== '1')) {
        http_response_code(503);
        portal_start('Portal not available', ['bare' => true]);
        echo '<div class="pp-card pp-narrow"><p>The subscriber portal is not available at the moment. For your bills and payments, please call or visit our office.</p></div>';
        portal_end();
        exit;
    }
    [$file, $auth] = $views[$v];
    if ($auth) {
        portal_customer();
    }
    require __DIR__ . '/views/' . $file;
    ob_end_flush();
} catch (UserError $e) {
    if (DB::inTransaction()) {
        DB::rollBack();
    }
    // whatever the page had started to print is dropped: the refusal is shown on a page of its own
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (http_response_code() < 400) {
        http_response_code(400);
    }
    portal_start('Not available', ['bare' => !IspPortal::customer()]);
    echo '<div class="pp-card pp-narrow"><p>' . h($e->getMessage()) . '</p><a class="btn btn-sm btn-primary" href="' . h(IspPortal::url('home')) . '">Back to the home page</a></div>';
    portal_end();
} catch (Throwable $e) {
    // an unexpected error: the half-printed page is dropped and the error page of app/bootstrap.php is shown
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    throw $e;
}
