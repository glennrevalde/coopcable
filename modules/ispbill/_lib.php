<?php
declare(strict_types=1);

/**
 * Shared pieces of the Billing & Collection pages: proofs of payment (isp_files), the subscriber summary shown above
 * the collection and statement forms, and small look-ups. Loaded with require_once by the ispbill pages.
 *
 * Files are kept under Tenant::storage('isp'); isp_files.stored_name is the path below that folder
 * ("payment/3f9c...e1.jpg"), never a name the user chose.
 */

const ISPBILL_FILE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
const ISPBILL_FILE_MAX = 5242880; // 5 MB

/** Saves an uploaded file (field $field) for a record; returns the isp_files id, or null when nothing was uploaded */
function ispbill_save_upload(string $field, string $entity, int $entityId, string $label): ?int
{
    $f = $_FILES[$field] ?? null;
    if (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
        fail('The file could not be uploaded (error ' . (int)$f['error'] . '). Files up to 5 MB are accepted.');
    }
    if ((int)$f['size'] > ISPBILL_FILE_MAX) {
        fail('The file is larger than 5 MB.');
    }
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$f['tmp_name']);
    $ext = ISPBILL_FILE_TYPES[$mime] ?? null;
    if (!$ext) {
        fail('Only JPG, PNG, WEBP images and PDF files are accepted as proof of payment.');
    }
    $dir = Tenant::storage('isp/' . preg_replace('/[^a-z_]/', '', $entity));
    Master::protectDir(Tenant::storage('isp'));
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file((string)$f['tmp_name'], $dir . '/' . $name)) {
        fail('The file could not be stored on the server.');
    }
    $id = DB::insert('isp_files', ['entity_type' => $entity, 'entity_id' => $entityId, 'label' => mb_substr($label, 0, 120), 'stored_name' => $entity . '/' . $name,
        'original_name' => mb_substr(basename((string)$f['name']), 0, 190), 'mime' => $mime, 'size_bytes' => (int)$f['size'], 'uploaded_by' => Auth::id()]);
    Audit::log('upload', 'ispbill', 'isp_file', $id, $entity . ' ' . $entityId, ['reason' => $label]);
    return $id;
}

function ispbill_files(string $entity, int $entityId): array
{
    return DB::all('SELECT f.*, u.full_name AS uploader FROM isp_files f LEFT JOIN users u ON u.id = f.uploaded_by WHERE f.entity_type = :e AND f.entity_id = :i ORDER BY f.id',
        ['e' => $entity, 'i' => $entityId]);
}

/** Streams a stored file of a record to the browser (the caller checked the page access) */
function ispbill_send_file(int $fileId, string $entity, int $entityId): never
{
    $f = DB::row('SELECT * FROM isp_files WHERE id = :id AND entity_type = :e AND entity_id = :i', ['id' => $fileId, 'e' => $entity, 'i' => $entityId]);
    if (!$f) {
        http_response_code(404);
        exit('File not found.');
    }
    $base = realpath(Tenant::storage('isp'));
    $path = $base ? realpath($base . '/' . $f['stored_name']) : false;
    if ((!$path || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) && $base) {
        // a file stored by another part of the system under a sub-folder of its own
        $hits = glob($base . '/*/' . basename((string)$f['stored_name'])) ?: [];
        $path = $hits ? realpath($hits[0]) : false;
    }
    if (!$path || !is_file($path) || !str_starts_with($path, (string)$base)) {
        http_response_code(404);
        exit('The file is no longer on the server.');
    }
    Audit::log('download', 'ispbill', 'isp_file', $fileId, (string)$f['original_name']);
    $mime = isset(ISPBILL_FILE_TYPES[$f['mime']]) ? $f['mime'] : 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($mime === 'application/octet-stream' ? 'attachment' : 'inline') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$f['original_name']) . '"');
    readfile($path);
    exit;
}

/** The subscriber summary above the collection and statement pages */
function ispbill_account_panel(array $a, bool $actions = true): string
{
    $b = IspAccounts::balances((int)$a['id']);
    $plan = $a['plan_id'] ? IspPlans::get((int)$a['plan_id']) : null;
    $c = IspAccounts::customer((int)$a['isp_customer_id']);
    $links = '';
    if ($actions) {
        $links .= Access::page('isp.account') ? btn_link(url('isp.account', ['id' => $a['id']]), 'Account', 'btn-outline-secondary', 'person-vcard') . ' ' : '';
        $links .= Access::page('ispbill.soa') ? btn_link(url('ispbill.soa', ['account_id' => $a['id']]), 'Statement', 'btn-outline-secondary', 'file-text') : '';
    }
    return '<div class="card isp-card mb-3"><div class="card-body"><div class="isp-head">'
        . '<div><h2 class="isp-head-title">' . IspUi::acct($a['id'], $a['account_no'], null) . ' ' . h($a['account_name']) . '</h2>'
        . '<div class="isp-head-sub">' . IspUi::status($a['service_status'], IspAccounts::SERVICE_STATUSES) . ' <span class="ms-2">' . h($plan['name'] ?? '') . '</span>'
        . ($c && IspAccounts::isMember((int)$c['id']) ? ' <span class="ms-2 text-muted">Cooperative member</span>' : '') . '</div>'
        . '<div class="small text-muted mt-1">' . IspUi::sensitive($a['install_address']) . ($c && $c['mobile'] ? ', ' . IspUi::sensitive($c['mobile']) : '') . '</div></div>'
        . '<div class="isp-head-actions">' . $links . '</div></div>'
        . '<div class="kpi-grid mt-3">'
        . IspUi::kpi('Balance due', money($b['balance']), $b['credit'] > 0 ? 'after ' . money($b['credit']) . ' advance credit' : 'open invoices less credits', null, 'wallet2', $b['balance'] > 0 ? 'warn' : 'ok')
        . IspUi::kpi('Overdue', money($b['overdue']), 'past the due date', null, 'alarm', $b['overdue'] > 0 ? 'bad' : 'ok')
        . IspUi::kpi('Advance credit', money($b['credit']), 'unapplied payments', null, 'piggy-bank', 'info')
        . '</div></div></div>';
}

/** Users who collected subscriber payments: id => name */
function ispbill_cashiers(): array
{
    return DB::pairs('SELECT DISTINCT u.id, u.full_name FROM isp_payments p JOIN users u ON u.id = p.collector_user_id ORDER BY u.full_name');
}

/** The ledger transaction link of an ISP document (plain text when the user may not open it) */
function ispbill_txn(?int $txnId, string $text = ''): string
{
    if (!$txnId) {
        return '';
    }
    $t = Txn::load($txnId);
    if (!$t) {
        return '';
    }
    return Txn::link($t, $text !== '' ? $text : Txn::ref($t));
}

/** The BIR lines printed at the foot of an invoice or receipt (only the ones set in ISP Setup) */
function ispbill_bir_foot(): string
{
    $atp = IspSetup::get('isp_bir_atp');
    $series = IspSetup::get('isp_bir_series');
    $out = [];
    if ($atp !== '') {
        $out[] = 'Authority to Print / Permit no. ' . h($atp);
    }
    if ($series !== '') {
        $out[] = 'Approved series ' . h($series);
    }
    return $out ? '<div class="doc-bir small">' . implode('<br>', $out) . '</div>' : '';
}
