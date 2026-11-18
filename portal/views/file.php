<?php
declare(strict_types=1);

defined('APP_PORTAL') || exit;   // opened only through portal/index.php

// A file the subscriber sent (proof of payment, a photo for a ticket): served only to the subscriber who owns the
// record, from the storage folder the web server never serves directly.
$f = DB::row('SELECT * FROM isp_files WHERE id = :id', ['id' => (int)g('id')]);
$owner = null;
if ($f) {
    $owner = match ((string)$f['entity_type']) {
        'payment' => DB::value('SELECT account_id FROM isp_payments WHERE id = :i', ['i' => $f['entity_id']]),
        'ticket' => DB::value('SELECT account_id FROM isp_tickets WHERE id = :i', ['i' => $f['entity_id']]),
        default => null,
    };
}
if (!$f || !$owner) {
    http_response_code(404);
    fail('That file was not found.');
}
IspPortal::account((int)$owner);
$path = portal_file_path($f);
if ($path === null) {
    http_response_code(404);
    fail('That file is no longer available.');
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
$mime = isset(PORTAL_UPLOAD_TYPES[$f['mime']]) ? (string)$f['mime'] : 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($f['original_name'] ?: $f['stored_name'])) . '"');
header('Cache-Control: private, no-store');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
readfile($path);
exit;
