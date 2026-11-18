<?php
declare(strict_types=1);

/**
 * Payment gateway webhook: api/gateway.php?provider=paymongo|xendit|generic (&co=<company code> when several
 * companies run on this system). Only a notice that passes the provider's signature / token check posts a payment,
 * and each provider event is posted once (app/IspGateway.php). No session, no cookies, JSON in and out.
 */
define('APP_ENDPOINT', true);
require dirname(__DIR__) . '/app/bootstrap.php';

const GATEWAY_MAX_BODY = 262144;   // 256 KB: a payment notice is a few KB

function gateway_reply(int $code, string $message): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['ok' => $code >= 200 && $code < 300, 'message' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** The request headers (getallheaders() is missing on some PHP builds, e.g. PHP-FPM before 7.3) */
function gateway_headers(): array
{
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        if (is_array($h) && $h) {
            return $h;
        }
    }
    $out = [];
    foreach ($_SERVER as $k => $v) {
        if (str_starts_with((string)$k, 'HTTP_')) {
            $out[str_replace('_', '-', substr((string)$k, 5))] = (string)$v;
        } elseif (in_array($k, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
            $out[str_replace('_', '-', (string)$k)] = (string)$v;
        }
    }
    return $out;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    gateway_reply(405, 'Use POST');
}
if (DB::isBlocked()) {
    gateway_reply(404, 'Unknown company: add &co=<company code> to the webhook URL');
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > GATEWAY_MAX_BODY) {
    gateway_reply(413, 'Body too large');
}
$raw = (string)file_get_contents('php://input', false, null, 0, GATEWAY_MAX_BODY + 1);
if (strlen($raw) > GATEWAY_MAX_BODY) {
    gateway_reply(413, 'Body too large');
}
if ($raw === '') {
    gateway_reply(400, 'Empty body');
}
$provider = strtolower(preg_replace('/[^a-z]/i', '', (string)($_GET['provider'] ?? '')));
try {
    if (!IspSetup::ready()) {
        gateway_reply(503, 'Not installed');
    }
    [$code, $message] = IspGateway::handle($provider, $raw, gateway_headers(), client_ip());
    gateway_reply((int)$code, (string)$message);
} catch (Throwable $e) {
    if (DB::inTransaction()) {
        DB::rollBack();
    }
    error_log('Payment gateway webhook: ' . $e);
    // a 5xx makes the provider send the notice again later; the event id keeps it from posting twice
    gateway_reply(500, 'Temporary error, please retry');
}
