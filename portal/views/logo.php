<?php
declare(strict_types=1);

defined('APP_PORTAL') || exit;   // opened only through portal/index.php

// the company logo for the portal pages and printed documents (public: it is printed on every bill)
$file = Logo::path();
if (!$file) {
    http_response_code(404);
    exit;
}
$mime = function_exists('mime_content_type') ? (string)mime_content_type($file) : 'image/png';
if (!str_starts_with($mime, 'image/')) {
    http_response_code(404);
    exit;
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Cache-Control: public, max-age=86400');
readfile($file);
exit;
