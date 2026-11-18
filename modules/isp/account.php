<?php
declare(strict_types=1);

/**
 * Subscriber Account: everything about one subscription in one place (the subscriber, the plan, billing, payments,
 * service changes, the network, support, equipment, the history, documents and the portal log-in).
 */
$pk = 'isp.account';
$id = (int)g('id', (string)p('id'));
$a = $id ? IspAccounts::get($id) : null;
if (!$a) {
    layout_start('Subscriber Account');
    echo IspUi::empty('The account was not found. Open it from the Subscriber Center.', 'search');
    echo '<p class="mt-3">' . btn_link(url('isp.subscribers'), 'Subscriber Center', 'btn-outline-primary', 'arrow-left') . '</p>';
    layout_end();
    return;
}
// a branch user only works on the accounts of the branch
$myBranch = IspSetup::userBranch();
if ($myBranch !== null && $a['branch_id'] !== null && (int)$a['branch_id'] !== $myBranch) {
    Access::denyPage($pk);
}
$cust = IspAccounts::customer((int)$a['isp_customer_id']);
$tabs = ['overview' => 'Overview', 'billing' => 'Billing', 'payments' => 'Payments', 'service' => 'Service', 'network' => 'Network', 'support' => 'Support',
    'equipment' => 'Equipment', 'timeline' => 'History', 'documents' => 'Documents', 'portal' => 'Portal'];
$tab = isset($tabs[(string)g('tab')]) ? (string)g('tab') : 'overview';
if (is_post() && isset($tabs[(string)p('tab')])) {
    $tab = (string)p('tab');
}
$here = fn(string $t = '', array $x = []) => url($pk, ['id' => $id, 'tab' => $t ?: $tab] + $x);
$canEdit = Access::can($pk, 'edit');
$mayReveal = Access::special('isp_network_admin') || Access::special('isp_view_sensitive');

// ---------------------------------------------------------------- documents (stored outside the web folder)
$fileDir = function (): string {
    $dir = Tenant::storage('isp') . '/account';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    Master::protectDir(Tenant::storage('isp'));
    Master::protectDir($dir);
    return $dir;
};
$fileAllowed = function (array $f) use ($id, $a): bool {
    return ($f['entity_type'] === 'account' && (int)$f['entity_id'] === $id)
        || ($f['entity_type'] === 'application' && $a['application_id'] && (int)$f['entity_id'] === (int)$a['application_id']);
};
if (g('a') === 'file') {
    $f = DB::row('SELECT * FROM isp_files WHERE id = :f', ['f' => (int)g('fid')]);
    if (!$f || !$fileAllowed($f)) {
        http_response_code(404);
        exit('File not found.');
    }
    $dir = $f['entity_type'] === 'account' ? $fileDir() : Tenant::storage('isp') . '/' . $f['entity_type'];
    $path = $dir . '/' . basename((string)$f['stored_name']);
    if (!is_file($path)) {
        http_response_code(404);
        exit('The file is no longer on the server.');
    }
    $inline = in_array($f['mime'], ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true) && g('dl') !== '1';
    header('Content-Type: ' . $f['mime']);
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($f['original_name'] ?: $f['stored_name'])) . '"');
    readfile($path);
    exit;
}

$revealed = null;      // the PPPoE password, shown once after the "show" request
$portalPassword = null;  // a new portal password, shown once
handle_post(function () use ($pk, $id, $a, $cust, $here, $fileDir, $fileAllowed, $mayReveal, &$revealed, &$portalPassword) {
    $action = (string)p('action');
    $reason = trim((string)p('reason'));
    switch ($action) {
        case 'save_details':
            Access::require($pk, 'edit');
            $c = ['classification' => (string)p('classification'), 'mobile' => trim((string)p('mobile')) ?: null, 'email' => trim((string)p('email')) ?: null,
                'billing_address' => trim((string)p('billing_address')) ?: null, 'notify_sms' => (int)(p('notify_sms') === '1'), 'notify_email' => (int)(p('notify_email') === '1'),
                'id_type' => trim((string)p('id_type')) ?: null, 'id_number' => trim((string)p('id_number')) ?: null, 'notes' => trim((string)p('cust_notes')) ?: null];
            if (!isset(IspAccounts::CLASSIFICATIONS[$c['classification']])) {
                fail('Choose the classification.');
            }
            if ($c['email'] !== null && !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
                fail('The e-mail address is not valid.');
            }
            if (trim((string)p('account_name')) === '' || trim((string)p('install_address')) === '') {
                fail('Enter the account name and the installation address.');
            }
            foreach (['latitude' => [-90, 90], 'longitude' => [-180, 180]] as $k => [$lo, $hi]) {
                $v = trim((string)p($k));
                if ($v !== '' && (!is_numeric($v) || (float)$v < $lo || (float)$v > $hi)) {
                    fail('The ' . $k . ' is not valid.');
                }
            }
            $acct = ['account_name' => trim((string)p('account_name')), 'install_address' => trim((string)p('install_address')), 'landmark' => trim((string)p('landmark')) ?: null,
                'latitude' => trim((string)p('latitude')), 'longitude' => trim((string)p('longitude')), 'area_id' => pint('area_id'), 'branch_id' => pint('branch_id'),
                'due_days' => max(0, min(60, (int)p('due_days'))), 'custom_due_day' => pint('custom_due_day') ? max(1, min(28, (int)p('custom_due_day'))) : null,
                'billing_basis' => isset(IspAccounts::BILLING_BASES[(string)p('billing_basis')]) ? (string)p('billing_basis') : $a['billing_basis'],
                'bill_timing' => isset(IspAccounts::BILL_TIMINGS[(string)p('bill_timing')]) ? (string)p('bill_timing') : $a['bill_timing'],
                'router_rental' => (int)(p('router_rental') === '1'), 'static_ip' => trim((string)p('static_ip')) ?: null, 'ip_pool' => trim((string)p('ip_pool')) ?: null,
                'mac_address' => strtoupper(trim((string)p('mac_address'))) ?: null, 'device_id' => pint('device_id'), 'notes' => trim((string)p('acct_notes')) ?: null, 'agent_id' => pint('agent_id')];
            // the cycle day follows the activation day on anniversary billing; it is changed here only on calendar billing
            if ($acct['billing_basis'] === 'calendar') {
                $acct['cycle_day'] = max(1, min(28, (int)p('cycle_day')));
            }
            if ($acct['static_ip'] !== null && !filter_var($acct['static_ip'], FILTER_VALIDATE_IP)) {
                fail('The static IP address is not valid.');
            }
            IspAccounts::saveDetails($id, $c, $acct, $pk);
            redirect($here('overview'), 'The details were saved.', 'success');

        case 'add_contact':
            Access::require($pk, 'edit');
            $name = trim((string)p('contact_name'));
            if ($name === '') {
                fail('Enter the name of the contact.');
            }
            $email = trim((string)p('contact_email'));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                fail('The contact\'s e-mail address is not valid.');
            }
            $cid = DB::insert('isp_contacts', ['isp_customer_id' => $cust['id'], 'name' => mb_substr($name, 0, 120), 'relationship' => mb_substr(trim((string)p('relationship')), 0, 60) ?: null,
                'mobile' => mb_substr(trim((string)p('contact_mobile')), 0, 40) ?: null, 'email' => $email ?: null, 'is_authorized' => (int)(p('is_authorized') === '1')]);
            IspAccounts::event($id, 'profile', 'Contact added: ' . $name . (p('is_authorized') === '1' ? ' (may transact for the subscriber)' : ''));
            Audit::log('create', 'isp', 'isp_contact', $cid, $name);
            redirect($here('overview'), 'Contact added.', 'success');

        case 'remove_contact':
            Access::require($pk, 'edit');
            $ct = DB::row('SELECT * FROM isp_contacts WHERE id = :c AND isp_customer_id = :s', ['c' => (int)p('contact_id'), 's' => $cust['id']]) ?? fail('Contact not found.');
            DB::run('DELETE FROM isp_contacts WHERE id = :c', ['c' => $ct['id']]);
            IspAccounts::event($id, 'profile', 'Contact removed: ' . $ct['name']);
            Audit::log('delete', 'isp', 'isp_contact', (int)$ct['id'], (string)$ct['name']);
            redirect($here('overview'), 'Contact removed.', 'success');

        case 'add_charge':
            Access::require($pk, 'create');
            $type = (string)p('charge_type');
            $types = ['one_time' => 1, 'installation' => 1, 'equipment' => 1, 'static_ip' => 1, 'addon' => 1, 'reconnection_fee' => 1, 'discount' => 1];
            if (!isset($types[$type])) {
                fail('Choose the kind of charge.');
            }
            $desc = trim((string)p('description'));
            if ($desc === '') {
                fail('Describe the charge.');
            }
            $amt = r2(pnum('amount'));
            if ($amt <= 0) {
                fail('Enter the amount.');
            }
            if ($type === 'discount') {
                $amt = -$amt;
            }
            $date = pdate('charge_date', today()) ?? fail('Enter the date of the charge.');
            $plan = IspPlans::get((int)$a['plan_id']);
            IspBilling::addCharge($id, $type, $desc, $amt, $date, 'MAN-' . $id . '-' . bin2hex(random_bytes(6)), (string)$plan['vat_mode'], null, 'manual', null);
            if (p('bill_now') === '1') {
                $inv = IspBilling::chargesInvoice($id, $date);
                redirect($here('billing'), 'Charge added and billed on invoice ' . DB::value('SELECT invoice_no FROM isp_invoices WHERE id = :i', ['i' => $inv]) . '.', 'success');
            }
            redirect($here('billing'), 'Charge added: it goes on the next invoice.', 'success');

        case 'cancel_charge':
            Access::require($pk, 'delete');
            $ch = DB::row('SELECT * FROM isp_charges WHERE id = :c AND account_id = :a', ['c' => (int)p('charge_id'), 'a' => $id]) ?? fail('Charge not found.');
            if ($reason === '') {
                fail('Enter the reason for cancelling the charge.');
            }
            IspBilling::cancelCharge((int)$ch['id'], $reason);
            Audit::log('void', 'isp', 'isp_charge', (int)$ch['id'], (string)$ch['description'], ['reason' => $reason, 'amount' => $ch['amount']]);
            redirect($here('billing'), 'Charge cancelled.', 'success');

        case 'bill_charges':
            Access::require($pk, 'create');
            $inv = IspBilling::chargesInvoice($id, today());
            redirect(url('ispbill.invoices', ['id' => $inv]), 'The pending charges were billed.', 'success');

        case 'add_addon':
            Access::require($pk, 'edit');
            $desc = trim((string)p('addon_desc'));
            $amt = r2(pnum('addon_amount'));
            $from = pdate('addon_start') ?? fail('Enter the start date of the add-on.');
            $until = pdate('addon_end');
            if ($desc === '' || $amt <= 0) {
                fail('Enter the add-on and its monthly amount.');
            }
            if ($until !== null && $until < $from) {
                fail('The add-on ends before it starts.');
            }
            $ad = DB::insert('isp_account_addons', ['account_id' => $id, 'description' => mb_substr($desc, 0, 160), 'amount' => $amt, 'taxable' => (int)(p('addon_taxable') === '1'),
                'start_date' => $from, 'end_date' => $until, 'created_by' => Auth::id()]);
            IspAccounts::event($id, 'addon', 'Recurring add-on ' . $desc . ' ' . money($amt) . '/month from ' . fdate($from), 'addon', $ad, $amt);
            Audit::log('create', 'isp', 'isp_account_addon', $ad, $desc, ['amount' => $amt]);
            redirect($here('billing'), 'Add-on added: it is billed with the monthly subscription.', 'success');

        case 'end_addon':
            Access::require($pk, 'edit');
            $ad = DB::row('SELECT * FROM isp_account_addons WHERE id = :d AND account_id = :a', ['d' => (int)p('addon_id'), 'a' => $id]) ?? fail('Add-on not found.');
            $end = pdate('end_date', today());
            DB::update('isp_account_addons', ['end_date' => $end, 'is_active' => $end <= today() ? 0 : 1], 'id = :id', ['id' => $ad['id']]);
            IspAccounts::event($id, 'addon', 'Add-on ' . $ad['description'] . ' ends ' . fdate($end), 'addon', (int)$ad['id']);
            Audit::log('edit', 'isp', 'isp_account_addon', (int)$ad['id'], (string)$ad['description'], ['reason' => 'Ended ' . $end]);
            redirect($here('billing'), 'Add-on ended.', 'success');

        case 'change_plan':
            Access::require($pk, 'edit');
            IspAccounts::changePlan($id, (int)p('plan_id'), pdate('effective', today()) ?? today(), $reason, $pk);
            redirect($here('service'), 'Plan changed.', 'success');

        case 'suspend':
            Access::require($pk, 'edit');
            $until = pdate('until');
            if ($until !== null && $until <= today()) {
                fail('The suspension has to end after today.');
            }
            if ($reason === '') {
                fail('Enter the reason for the suspension.');
            }
            IspAccounts::suspend($id, $until, $reason, $pk);
            redirect($here('service'), 'The account is suspended; the network was asked to disable it.', 'success');

        case 'resume':
            Access::require($pk, 'edit');
            IspAccounts::resume($id, $reason ?: 'Resumed at the subscriber\'s request', $pk);
            redirect($here('service'), 'The service was resumed; the network was asked to enable it.', 'success');

        case 'terminate':
            Access::require($pk, 'delete');
            $date = pdate('end_date', today()) ?? today();
            $out = IspAccounts::terminate($id, $date, $reason, p('charge_etf') === '1', $pk);
            $msg = 'The subscription was terminated.';
            if (!empty($out['final_invoice_id'])) {
                $msg .= ' Final invoice ' . DB::value('SELECT invoice_no FROM isp_invoices WHERE id = :i', ['i' => $out['final_invoice_id']]) . ' was issued.';
            }
            if (!empty($out['recovery_wo_id'])) {
                $msg .= ' An equipment recovery work order was created.';
            }
            redirect($here('service'), $msg, 'success');

        case 'final_refund':
            // the deposit still held and the unused service credit, paid back in cash (Workflow G: deposit settlement)
            Access::require($pk, 'approve');
            $cashAcct = (int)p('cash_account_id');
            if (!DB::value("SELECT id FROM accounts WHERE id = :a AND account_type = 'Bank' AND is_active = 1", ['a' => $cashAcct])) {
                fail('Choose the cash or bank account the refund is paid from.');
            }
            $txn = IspBilling::refundDeposit($id, $cashAcct, pdate('refund_date', today()) ?? today(), $reason);
            redirect($here('service'), 'The refund was recorded (journal ' . DB::value('SELECT txn_no FROM transactions WHERE id = :t', ['t' => $txn]) . ').', 'success');

        case 'disconnect':
            Access::require($pk, 'edit');
            $qid = IspDisconnect::queueManual($id, (string)p('reason_type'), $reason, p('execute_now') === '1');
            $q = DB::row('SELECT queue_no, status FROM isp_disc_queue WHERE id = :q', ['q' => $qid]);
            redirect($here('service'), 'Disconnection ' . $q['queue_no'] . ': ' . (IspDisconnect::STATUSES[$q['status']] ?? $q['status']) . '.', 'success');

        case 'reconnect':
            Access::require($pk, 'edit');
            $exp = trim((string)p('expires_at'));
            $rid = IspReconnect::manual($id, (string)p('recon_type'), $reason, $exp !== '' ? str_replace('T', ' ', $exp) . (strlen($exp) === 16 ? ':00' : '') : null);
            if (!$rid) {
                redirect($here('service'), 'A reconnection is already in progress for this account.', 'warning');
            }
            $r = DB::row('SELECT recon_no, status FROM isp_reconnections WHERE id = :r', ['r' => $rid]);
            redirect($here('service'), 'Reconnection ' . $r['recon_no'] . ': ' . (IspReconnect::STATUSES[$r['status']] ?? $r['status']) . '.', 'success');

        case 'exempt':
            Access::require($pk, 'edit');
            IspCredit::grantExemption($id, (string)pdate('valid_from', today()), (string)pdate('valid_until'), $reason);
            redirect($here('service'), 'Disconnection exemption recorded.', 'success');

        case 'revoke_exemption':
            Access::require($pk, 'edit');
            if (!Access::special('isp_exempt')) {
                fail('Exemptions need the permission "Cable & Internet: can approve disconnection exemptions".');
            }
            $ex = DB::row("SELECT * FROM isp_exemptions WHERE id = :e AND account_id = :a AND status = 'active'", ['e' => (int)p('exemption_id'), 'a' => $id]) ?? fail('Exemption not found.');
            if ($reason === '') {
                fail('Enter the reason for revoking the exemption.');
            }
            IspCredit::revokeExemption((int)$ex['id'], $reason);
            Audit::log('void', 'ispbill', 'isp_exemption', (int)$ex['id'], (string)$a['account_no'], ['reason' => $reason]);
            redirect($here('service'), 'Exemption revoked.', 'success');

        case 'autodebit':
            Access::require($pk, 'edit');
            IspAccounts::saveDetails($id, [], ['autodebit_sav_account_id' => pint('sav_account_id')], $pk);
            redirect($here('service'), pint('sav_account_id') ? 'The bills will be paid from the chosen savings account on their due date.' : 'Auto-debit switched off.', 'success');

        case 'reveal':
            Access::require($pk, 'view');
            if (!$mayReveal) {
                fail('Showing the PPPoE password needs the network administrator or the sensitive-data permission.');
            }
            $revealed = (string)IspAccounts::pppoePassword($a);
            Audit::log('view_sensitive', 'isp', 'isp_account', $id, (string)$a['account_no'], ['reason' => 'PPPoE password shown']);
            return;

        case 'net_op':
            Access::require($pk, 'edit');
            $op = (string)p('op');
            if (!in_array($op, ['status', 'reset_session', 'provision', 'change_plan'], true)) {
                fail('Choose the network operation.');
            }
            if (in_array($op, ['provision', 'change_plan'], true) && !Access::special('isp_network_admin')) {
                fail('Provisioning needs the network administrator permission.');
            }
            if (in_array($a['service_status'], ['terminated', 'cancelled'], true)) {
                fail('The account is ' . $a['service_status'] . '.');
            }
            $dev = $a['device_id'] ? IspNetwork::device((int)$a['device_id']) : IspNetwork::defaultDevice();
            $map = ['status' => 'status', 'reset_session' => 'reset_session', 'provision' => 'provision', 'change_plan' => 'change_plan'];
            if (!IspNetwork::adapter($dev)->supports($map[$op])) {
                fail('The device of this account does not support "' . IspNetwork::OP_TYPES[$op] . '".');
            }
            $oid = IspNetwork::request($id, $op, $reason ?: IspNetwork::OP_TYPES[$op] . ' (asked from the account page)', 'manual', time());
            $o = DB::row('SELECT op_no, state, simulated, error FROM isp_net_ops WHERE id = :o', ['o' => $oid]);
            redirect($here('network'), 'Network operation ' . $o['op_no'] . ': ' . (IspNetwork::STATES[$o['state']] ?? $o['state'])
                . ($o['simulated'] ? ' (simulated: no real device was changed)' : '') . ($o['error'] ? '. ' . $o['error'] : '.'), $o['state'] === 'failed' ? 'warning' : 'success');

        case 'upload':
            Access::require($pk, 'edit');
            $up = $_FILES['file'] ?? null;
            if (!$up || !is_array($up) || ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                fail('Choose the file to upload.');
            }
            if ((int)$up['error'] !== UPLOAD_ERR_OK) {
                fail((int)$up['error'] === UPLOAD_ERR_INI_SIZE || (int)$up['error'] === UPLOAD_ERR_FORM_SIZE ? 'The file is too large (5 MB at most).' : 'The upload failed; try again.');
            }
            if ((int)$up['size'] > 5 * 1024 * 1024) {
                fail('The file is too large (5 MB at most).');
            }
            $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$up['tmp_name']);
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime] ?? null;
            if (!$ext) {
                fail('Only JPG, PNG, WEBP images and PDF files can be uploaded.');
            }
            $stored = bin2hex(random_bytes(16)) . '.' . $ext;
            if (!is_uploaded_file((string)$up['tmp_name']) || !move_uploaded_file((string)$up['tmp_name'], $fileDir() . '/' . $stored)) {
                fail('The file could not be stored on the server.');
            }
            $label = trim((string)p('label')) ?: 'Document';
            $fid = DB::insert('isp_files', ['entity_type' => 'account', 'entity_id' => $id, 'label' => mb_substr($label, 0, 120), 'stored_name' => $stored,
                'original_name' => mb_substr(basename((string)$up['name']), 0, 160), 'mime' => $mime, 'size_bytes' => (int)$up['size'], 'uploaded_by' => Auth::id()]);
            IspAccounts::event($id, 'document', 'Document uploaded: ' . $label, 'file', $fid);
            Audit::log('create', 'isp', 'isp_file', $fid, $label);
            redirect($here('documents'), 'Document uploaded.', 'success');

        case 'delete_file':
            Access::require($pk, 'delete');
            $f = DB::row("SELECT * FROM isp_files WHERE id = :f AND entity_type = 'account' AND entity_id = :a", ['f' => (int)p('file_id'), 'a' => $id]) ?? fail('Document not found.');
            if ($reason === '') {
                fail('Enter the reason for removing the document.');
            }
            DB::run('DELETE FROM isp_files WHERE id = :f', ['f' => $f['id']]);
            @unlink($fileDir() . '/' . basename((string)$f['stored_name']));
            IspAccounts::event($id, 'document', 'Document removed: ' . ($f['label'] ?: $f['original_name']) . ' (' . $reason . ')', 'file', (int)$f['id']);
            Audit::log('delete', 'isp', 'isp_file', (int)$f['id'], (string)($f['label'] ?: $f['original_name']), ['reason' => $reason]);
            redirect($here('documents'), 'Document removed.', 'success');

        case 'portal_grant':
            Access::require($pk, 'edit');
            $portalPassword = IspPortal::grant((int)$cust['id']);
            IspAccounts::event($id, 'portal', 'Customer portal password set by staff');
            return;

        case 'portal_lock':
            Access::require($pk, 'edit');
            $u = DB::row('SELECT * FROM isp_portal_users WHERE isp_customer_id = :c', ['c' => $cust['id']]) ?? fail('The subscriber has no portal log-in.');
            $to = $u['status'] === 'active' ? 'inactive' : 'active';
            DB::update('isp_portal_users', ['status' => $to, 'failed_attempts' => 0], 'id = :id', ['id' => $u['id']]);
            Audit::log('status', 'isp', 'isp_portal_user', (int)$u['id'], (string)$u['username'], ['changes' => [['field' => 'status', 'old' => $u['status'], 'new' => $to]]]);
            redirect($here('portal'), $to === 'active' ? 'Portal log-in enabled.' : 'Portal log-in disabled.', 'success');

        case 'activate':
            if (!Access::can($pk, 'approve') && !Access::can('isp.applications', 'approve')) {
                Access::denyPage($pk, 'approve');
            }
            $r = IspAccounts::activate($id, pdate('activation_date', today()) ?? today(), $pk);
            redirect($here('overview'), 'The service is active' . (!empty($r['invoice_id']) ? '; the first invoice was issued' : '') . '.', 'success');
    }
    fail('Unknown action.');
});

// fresh after any change made above
$a = IspAccounts::get($id);
$cust = IspAccounts::customer((int)$a['isp_customer_id']);
$plan = IspPlans::get((int)$a['plan_id']);
$bal = IspAccounts::balances($id);
$fee = IspPlans::priceFor($plan, $a);
$member = IspAccounts::isMember((int)$cust['id']);
$area = $a['area_id'] ? DB::row('SELECT a.name, p.name AS parent FROM isp_areas a LEFT JOIN isp_areas p ON p.id = a.parent_id WHERE a.id = :i', ['i' => $a['area_id']]) : null;
$branches = IspSetup::branches();

$head = '';
if (Access::can('ispbill.collect', 'create') && !in_array($a['service_status'], ['cancelled'], true)) {
    $head .= btn_link(url('ispbill.collect', ['account_id' => $id]), 'Collect payment', 'btn-primary', 'cash-coin') . ' ';
}
if (Access::page('ispbill.soa')) {
    $head .= btn_link(url('ispbill.soa', ['account_id' => $id]), 'Statement', 'btn-outline-secondary', 'file-text') . ' ';
}
if (Access::can('ispfield.tickets', 'create')) {
    $head .= btn_link(url('ispfield.tickets', ['a' => 'new', 'account_id' => $id]), 'New ticket', 'btn-outline-secondary', 'headset') . ' ';
}
if ($canEdit) {
    $head .= btn_link(url($pk, ['id' => $id, 'a' => 'edit']), 'Edit details', 'btn-outline-secondary', 'pencil');
}
$editing = g('a') === 'edit' || (is_post() && p('action') === 'save_details');
if ($editing && !$canEdit) {
    Access::denyPage($pk, 'edit');
}
layout_start('Account ' . $a['account_no'], ['actions' => $head]);

// ---------------------------------------------------------------- the record header
$protect = IspCredit::protection($id);
$discLive = DB::row("SELECT * FROM isp_disc_queue WHERE account_id = :a AND live_key IS NOT NULL ORDER BY id DESC LIMIT 1", ['a' => $id]);
$reconLive = in_array($a['service_status'], ['terminated', 'cancelled'], true) ? null
    : DB::row("SELECT * FROM isp_reconnections WHERE account_id = :a AND live_key IS NOT NULL ORDER BY id DESC LIMIT 1", ['a' => $id]);
?>
<div class="card isp-card">
  <div class="card-body">
    <div class="isp-head">
      <div>
        <h2 class="isp-head-title"><?= h($a['account_name']) ?></h2>
        <div class="isp-head-sub">
          Subscriber <?= h($cust['customer_no']) ?><?= $member ? ', cooperative member' : '' ?>, <?= h(IspAccounts::CLASSIFICATIONS[$cust['classification']] ?? $cust['classification']) ?>.
          <?= h($plan['name']) ?> (<?= h(IspPlans::speed($plan)) ?>) at <?= money($fee) ?> a month<?= (int)$a['member_price'] === 1 ? ' (member price)' : '' ?>.
        </div>
        <div class="mt-2 d-flex flex-wrap gap-3">
          <span>Service: <?= IspUi::status($a['service_status'], IspAccounts::SERVICE_STATUSES) ?></span>
          <span>Network: <?= IspUi::status($a['network_state'], IspAccounts::NETWORK_STATES) ?></span>
          <?php if ($protect): ?><span class="text-muted small"><i class="bi bi-shield-check"></i> <?= h($protect) ?></span><?php endif; ?>
        </div>
      </div>
      <div class="isp-head-actions text-md-end">
        <div class="small text-muted">Balance</div>
        <div class="fs-4 fw-semibold<?= $bal['balance'] > 0 ? '' : ' text-success' ?>"><?= money($bal['balance']) ?></div>
        <?php if ($bal['overdue'] > 0): ?><div class="small text-danger">Overdue <?= money($bal['overdue']) ?></div><?php endif; ?>
        <?php if ($bal['credit'] > 0): ?><div class="small text-success">Advance / credit <?= money($bal['credit']) ?></div><?php endif; ?>
      </div>
    </div>
    <?php if ($discLive): ?>
      <div class="alert alert-warning mt-3 mb-0 py-2"><i class="bi bi-plug"></i> In the disconnection queue: <?= h($discLive['queue_no']) ?>, <?= h(IspDisconnect::STATUSES[$discLive['status']] ?? $discLive['status']) ?>
        (<?= h($discLive['reason']) ?>)<?= Access::page('ispnet.disconnections') ? ' <a href="' . h(url('ispnet.disconnections', ['id' => $discLive['id']])) . '">Open</a>' : '' ?></div>
    <?php endif; ?>
    <?php if ($reconLive): ?>
      <div class="alert alert-info mt-3 mb-0 py-2"><i class="bi bi-arrow-repeat"></i> <?php if ($reconLive['status'] === 'restored' && $reconLive['recon_type'] === 'temporary'): ?>Temporary reconnection <?= h($reconLive['recon_no']) ?> until <?= h(fdatetime($reconLive['expires_at'])) ?>: the service is cut again then unless the arrears are paid.<?php else: ?>Reconnection <?= h($reconLive['recon_no']) ?>: <?= h(IspReconnect::STATUSES[$reconLive['status']] ?? $reconLive['status']) ?><?php endif; ?>
        <?= Access::page('ispnet.reconnections') ? ' <a href="' . h(url('ispnet.reconnections', ['id' => $reconLive['id']])) . '">Open</a>' : '' ?></div>
    <?php endif; ?>
    <?php if ($a['service_status'] === 'pending'): ?>
      <?php $wo = DB::row("SELECT * FROM isp_work_orders WHERE account_id = :a AND wo_type = 'installation' AND status <> 'cancelled' ORDER BY id DESC LIMIT 1", ['a' => $id]); ?>
      <div class="alert alert-info mt-3 mb-0 py-2 d-flex flex-wrap align-items-center gap-2">
        <span><i class="bi bi-hourglass-split"></i> Waiting for activation.
          <?php if ($wo): ?>Installation <?= safe_link('ispfield.workorders', ['id' => $wo['id']], $wo['wo_no']) ?> is <?= h(IspWorkOrders::STATUSES[$wo['status']] ?? $wo['status']) ?>.<?php else: ?>No installation work order yet.<?php endif; ?></span>
        <?php if ($wo && in_array($wo['status'], ['completed', 'verified'], true) && (Access::can($pk, 'approve') || Access::can('isp.applications', 'approve'))): ?>
          <form method="post" class="d-flex gap-2 align-items-center ms-auto" data-confirm="Activate the service? The first invoice is issued and the network is told to enable the subscriber.">
            <?= csrf_field() ?><input type="hidden" name="action" value="activate">
            <input class="form-control form-control-sm" type="date" name="activation_date" value="<?= h(today()) ?>" style="max-width:160px">
            <button class="btn btn-sm btn-success"><i class="bi bi-power"></i> Activate</button></form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($editing): ?>
<?php
    $areaOpts = DB::pairs("SELECT a.id, CONCAT(a.name, CASE WHEN p.name IS NULL THEN '' ELSE CONCAT(', ', p.name) END) FROM isp_areas a LEFT JOIN isp_areas p ON p.id = a.parent_id WHERE a.is_active = 1 OR a.id = :k ORDER BY p.name, a.name", ['k' => (int)$a['area_id']]);
    $devOpts = DB::pairs('SELECT id, name FROM isp_devices WHERE is_active = 1 OR id = :k ORDER BY name', ['k' => (int)$a['device_id']]);
    $agentOpts = DB::pairs('SELECT id, CONCAT(code, \' \', name) FROM isp_agents WHERE is_active = 1 OR id = :k ORDER BY name', ['k' => (int)$a['agent_id']]);
    $rec = $a + ['cust_notes' => $cust['notes'], 'acct_notes' => $a['notes'], 'id_type' => $cust['id_type'], 'id_number' => $cust['id_number']];
?>
<form method="post" class="card isp-card mt-3"><div class="card-body">
  <?= csrf_field() ?><input type="hidden" name="action" value="save_details">
  <h2 class="sec-title">Subscriber</h2>
  <div class="row g-2">
    <?= select_field('classification', 'Classification', IspAccounts::CLASSIFICATIONS, fv('classification', $rec), ['class' => 'col-md-3']) ?>
    <?= input_field('mobile', 'Mobile', fv('mobile', $rec), ['class' => 'col-md-3']) ?>
    <?= input_field('email', 'E-mail', fv('email', $rec), ['class' => 'col-md-3', 'type' => 'email']) ?>
    <?= select_field('id_type', 'ID presented', array_combine(IspApplications::ID_TYPES, IspApplications::ID_TYPES), fv('id_type', $rec), ['class' => 'col-md-3', 'blank' => '']) ?>
    <?= input_field('id_number', 'ID number', fv('id_number', $rec), ['class' => 'col-md-3']) ?>
    <?= input_field('billing_address', 'Billing address', fv('billing_address', $rec), ['class' => 'col-md-9']) ?>
    <?= check_field('notify_sms', 'Send SMS notices', fv('notify_sms', $rec), 'col-md-3') ?>
    <?= check_field('notify_email', 'Send e-mail notices', fv('notify_email', $rec), 'col-md-3') ?>
    <div class="col-md-6"><label class="form-label">Notes on the subscriber</label><textarea class="form-control form-control-sm" name="cust_notes" rows="2"><?= h(fv('cust_notes', $rec)) ?></textarea></div>
  </div>
  <h2 class="sec-title mt-4">Account</h2>
  <div class="row g-2">
    <?= input_field('account_name', 'Account name', fv('account_name', $rec), ['class' => 'col-md-6', 'required' => true]) ?>
    <?= select_field('area_id', 'Service area', $areaOpts, fv('area_id', $rec), ['class' => 'col-md-3', 'blank' => '']) ?>
    <?php if ($branches): ?><?= select_field('branch_id', 'Branch', $branches, fv('branch_id', $rec), ['class' => 'col-md-3', 'blank' => '']) ?><?php endif; ?>
    <?= input_field('install_address', 'Installation address', fv('install_address', $rec), ['class' => 'col-md-6', 'required' => true]) ?>
    <?= input_field('landmark', 'Landmark', fv('landmark', $rec), ['class' => 'col-md-6']) ?>
    <?= input_field('latitude', 'Latitude', fv('latitude', $rec), ['class' => 'col-md-3']) ?>
    <?= input_field('longitude', 'Longitude', fv('longitude', $rec), ['class' => 'col-md-3']) ?>
    <?= select_field('billing_basis', 'Billing cycle', IspAccounts::BILLING_BASES, fv('billing_basis', $rec), ['class' => 'col-md-3']) ?>
    <?= input_field('cycle_day', 'Cycle day (1-28)', fv('cycle_day', $rec), ['class' => 'col-md-3', 'type' => 'number', 'step' => '1', 'extra' => 'min="1" max="28"']) ?>
    <?= select_field('bill_timing', 'Billed', IspAccounts::BILL_TIMINGS, fv('bill_timing', $rec), ['class' => 'col-md-3']) ?>
    <?= input_field('due_days', 'Days to pay', fv('due_days', $rec), ['class' => 'col-md-3', 'type' => 'number', 'step' => '1', 'extra' => 'min="0" max="60"']) ?>
    <?= input_field('custom_due_day', 'Fixed due day (optional)', fv('custom_due_day', $rec), ['class' => 'col-md-3', 'type' => 'number', 'step' => '1', 'extra' => 'min="1" max="28"']) ?>
    <?= check_field('router_rental', 'Router rented', fv('router_rental', $rec), 'col-md-3') ?>
    <?= input_field('static_ip', 'Static IP', fv('static_ip', $rec), ['class' => 'col-md-3']) ?>
    <?= input_field('ip_pool', 'IP pool', fv('ip_pool', $rec), ['class' => 'col-md-3']) ?>
    <?= input_field('mac_address', 'MAC address', fv('mac_address', $rec), ['class' => 'col-md-3']) ?>
    <?= select_field('device_id', 'Network device', $devOpts, fv('device_id', $rec), ['class' => 'col-md-3', 'blank' => 'Default device']) ?>
    <?= select_field('agent_id', 'Sales agent', $agentOpts, fv('agent_id', $rec), ['class' => 'col-md-3', 'blank' => 'None']) ?>
    <div class="col-12"><label class="form-label">Notes on the account</label><textarea class="form-control form-control-sm" name="acct_notes" rows="2"><?= h(fv('acct_notes', $rec)) ?></textarea></div>
  </div>
  <p class="small text-muted mt-2 mb-3">The cycle day is used on fixed-cycle billing; on anniversary billing it follows the activation day. Invoices already issued keep their dates and amounts.</p>
  <?= save_buttons('Save', url($pk, ['id' => $id]), false) ?>
</div></form>
<?php
    layout_end();
    return;
endif;

echo '<div class="mt-3">' . IspUi::tabs($tabs, $tab, $pk, ['id' => $id]) . '</div>';

// ================================================================ OVERVIEW
if ($tab === 'overview'):
    $contacts = DB::all('SELECT * FROM isp_contacts WHERE isp_customer_id = :c ORDER BY name', ['c' => $cust['id']]);
    $plans = DB::all('SELECT h.*, p.name AS plan_name FROM isp_account_plans h JOIN isp_plans p ON p.id = h.plan_id WHERE h.account_id = :a ORDER BY h.effective_from DESC, h.id DESC', ['a' => $id]);
    $contract = DB::row("SELECT * FROM isp_contracts WHERE account_id = :a ORDER BY FIELD(status, 'active', 'draft', 'expired', 'renewed', 'amended', 'terminated'), id DESC LIMIT 1", ['a' => $id]);
    $others = DB::all('SELECT a.id, a.account_no, a.service_status, a.install_address, p.name AS plan_name FROM isp_accounts a JOIN isp_plans p ON p.id = a.plan_id WHERE a.isp_customer_id = :c AND a.id <> :a ORDER BY a.account_no',
        ['c' => $cust['id'], 'a' => $id]);
    $agent = $a['agent_id'] ? DB::row('SELECT code, name FROM isp_agents WHERE id = :i', ['i' => $a['agent_id']]) : null;
    $app = $a['application_id'] ? DB::row('SELECT id, application_no FROM isp_applications WHERE id = :i', ['i' => $a['application_id']]) : null;
    $branchName = $a['branch_id'] ? ($branches[(int)$a['branch_id']] ?? '') : '';
    $coords = $a['latitude'] !== null && $a['longitude'] !== null
        ? h(rtrim(rtrim((string)$a['latitude'], '0'), '.') . ', ' . rtrim(rtrim((string)$a['longitude'], '0'), '.')) . ' <a class="small" target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat=' . h((string)(float)$a['latitude']) . '&mlon=' . h((string)(float)$a['longitude']) . '#map=18/' . h((string)(float)$a['latitude']) . '/' . h((string)(float)$a['longitude']) . '">map</a>'
        : '';
?>
<div class="row g-3 mt-0">
  <div class="col-lg-6">
    <div class="card isp-card h-100"><div class="card-body">
      <h2 class="sec-title">Subscriber</h2>
      <?= IspUi::dl([
          'Customer no.' => h($cust['customer_no']),
          'Name on documents' => h($cust['name']) . ($cust['party_id'] && Access::page('party.center') ? ' <a class="small" href="' . h(url('party.center', ['id' => $cust['party_id']])) . '">Party record</a>' : ''),
          'Cooperative member' => $member ? 'Yes' . (($m = Party::member((int)$cust['party_id'])) ? ', member no. ' . h($m['member_no']) : '') : 'No',
          'Classification' => h(IspAccounts::CLASSIFICATIONS[$cust['classification']] ?? $cust['classification']),
          'Mobile' => IspUi::sensitive($cust['mobile']),
          'Phone' => IspUi::sensitive($cust['phone']),
          'E-mail' => IspUi::sensitive($cust['email']),
          'Billing address' => IspUi::sensitive($cust['billing_address']),
          'TIN' => IspUi::sensitive($cust['tin']),
          'ID' => $cust['id_type'] ? h($cust['id_type']) . ' ' . IspUi::sensitive($cust['id_number']) . ($cust['id_verified_at'] ? ', verified ' . h(fdate($cust['id_verified_at'])) : ', not verified') : 'Not recorded',
          'Data privacy consent' => $cust['privacy_consent_at'] ? h(fdatetime($cust['privacy_consent_at'])) : 'Not recorded',
          'Notices' => h(implode(', ', array_filter([(int)$cust['notify_sms'] ? 'SMS' : '', (int)$cust['notify_email'] ? 'E-mail' : ''])) ?: 'None'),
          'Notes' => $cust['notes'] ? nl2br(h($cust['notes'])) : null,
      ]) ?>
      <h2 class="sec-title mt-3">Authorized contacts</h2>
      <?php if (!$contacts): ?><p class="small text-muted">No other contact on file.</p><?php else: ?>
      <div class="table-responsive"><table class="table table-sm align-middle isp-table">
        <thead><tr><th>Name</th><th>Relationship</th><th>Mobile</th><th>May transact</th><th></th></tr></thead><tbody>
        <?php foreach ($contacts as $c): ?>
          <tr><td><?= h($c['name']) ?><?php if ($c['email']): ?><div class="small text-muted"><?= IspUi::sensitive($c['email']) ?></div><?php endif; ?></td><td><?= h((string)$c['relationship']) ?></td>
            <td><?= IspUi::sensitive($c['mobile']) ?></td><td><?= (int)$c['is_authorized'] ? 'Yes' : 'No' ?></td>
            <td class="text-end"><?= $canEdit ? IspUi::actionForm('remove_contact', 'Remove', ['contact_id' => $c['id']], 'btn-outline-danger', 'x-lg', 'Remove ' . $c['name'] . ' from the contacts?') : '' ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
      <?php endif; ?>
      <?php if ($canEdit): ?>
      <details class="mt-2"><summary class="small">Add a contact</summary>
        <form method="post" class="row g-2 mt-1"><?= csrf_field() ?><input type="hidden" name="action" value="add_contact">
          <?= input_field('contact_name', 'Name', '', ['class' => 'col-md-6', 'required' => true]) ?>
          <?= input_field('relationship', 'Relationship', '', ['class' => 'col-md-6']) ?>
          <?= input_field('contact_mobile', 'Mobile', '', ['class' => 'col-md-6']) ?>
          <?= input_field('contact_email', 'E-mail', '', ['class' => 'col-md-6', 'type' => 'email']) ?>
          <?= check_field('is_authorized', 'May transact for the subscriber', '1', 'col-md-8') ?>
          <div class="col-md-4 d-flex align-items-end"><button class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Add</button></div>
        </form></details>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card isp-card h-100"><div class="card-body">
      <h2 class="sec-title">Subscription</h2>
      <?= IspUi::dl([
          'Account no.' => h($a['account_no']),
          'Installation address' => IspUi::sensitive($a['install_address']) . ($a['landmark'] ? '<div class="small text-muted">' . h($a['landmark']) . '</div>' : ''),
          'Service area' => $area ? h($area['name'] . ($area['parent'] ? ', ' . $area['parent'] : '')) : null,
          'Branch' => $branchName !== '' ? h($branchName) : null,
          'Coordinates' => $coords ?: null,
          'Opened' => h(fdate($a['created_at'])),
          'Activated' => $a['activation_date'] ? h(fdate($a['activation_date'])) : 'Not yet',
          'Contract' => $a['contract_start'] ? h(fdate($a['contract_start'])) . ($a['contract_end'] ? ' to ' . h(fdate($a['contract_end'])) : ', month to month') : null,
          'Lock-in until' => $a['lock_in_until'] ? h(fdate($a['lock_in_until'])) : null,
          'Billing' => h((IspAccounts::BILLING_BASES[$a['billing_basis']] ?? $a['billing_basis']) . ', day ' . $a['cycle_day'] . ', ' . (IspAccounts::BILL_TIMINGS[$a['bill_timing']] ?? $a['bill_timing'])
              . ', due ' . ($a['custom_due_day'] ? 'on day ' . $a['custom_due_day'] : $a['due_days'] . ' days after the invoice')),
          'Billed through' => $a['billed_through'] ? h(fdate($a['billed_through'])) : null,
          'Next invoice' => $a['next_bill_date'] ? h(fdate($a['next_bill_date'])) : null,
          'Security deposit' => (float)$a['security_deposit'] > 0 ? money($a['security_deposit']) . ' (' . h($a['deposit_status']) . ')' : null,
          'Extras' => h(implode(', ', array_filter([(int)$a['router_rental'] ? 'router rental' : '', $a['static_ip'] ? 'static IP ' . $a['static_ip'] : '']))) ?: null,
          'Sales agent' => $agent ? h($agent['code'] . ' ' . $agent['name']) : null,
          'Application' => $app ? safe_link('isp.applications', ['id' => $app['id']], $app['application_no']) : null,
          'Notes' => $a['notes'] ? nl2br(h($a['notes'])) : null,
      ]) ?>
      <?php if ($contract): ?>
      <h2 class="sec-title mt-3">Contract</h2>
      <p class="mb-1"><?= safe_link('isp.contracts', ['id' => $contract['id']], $contract['contract_no']) ?>, <?= IspUi::status($contract['status'], IspContracts::STATUSES) ?>,
        <?= h(fdate($contract['start_date'])) ?><?= $contract['end_date'] ? ' to ' . h(fdate($contract['end_date'])) : '' ?>.
        <?= $contract['accepted_at'] ? 'Accepted by ' . h((string)$contract['accepted_name']) . ' on ' . h(fdatetime($contract['accepted_at'])) . '.' : 'Acceptance not recorded.' ?></p>
      <?php endif; ?>
      <h2 class="sec-title mt-3">Plan history</h2>
      <div class="table-responsive"><table class="table table-sm align-middle isp-table">
        <thead><tr><th>From</th><th>To</th><th>Plan</th><th class="num">Monthly</th><th>Change</th></tr></thead><tbody>
        <?php foreach ($plans as $p): ?>
          <tr><td><?= h(fdate($p['effective_from'])) ?></td><td><?= h(fdate($p['effective_to'])) ?></td><td><?= h($p['plan_name']) ?></td><td class="num"><?= money($p['monthly_fee']) ?></td>
            <td><?= h(ucfirst((string)$p['change_type'])) ?><?php if ($p['proration']): ?><div class="small text-muted"><?= h($p['proration']) ?></div><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
      <?php if ($others): ?>
      <h2 class="sec-title mt-3">Other subscriptions of this subscriber</h2>
      <ul class="list-unstyled mb-0">
        <?php foreach ($others as $o): ?>
          <li><a href="<?= h(url($pk, ['id' => $o['id']])) ?>"><?= h($o['account_no']) ?></a> <?= h($o['plan_name']) ?>, <?= IspUi::status($o['service_status'], IspAccounts::SERVICE_STATUSES) ?>
            <span class="small text-muted"><?= IspUi::sensitive($o['install_address']) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div></div>
  </div>
</div>
<?php
// ================================================================ BILLING
elseif ($tab === 'billing'):
    $invoices = DB::all('SELECT * FROM isp_invoices WHERE account_id = :a ORDER BY invoice_date DESC, id DESC LIMIT 60', ['a' => $id]);
    $charges = DB::all("SELECT c.*, i.invoice_no FROM isp_charges c LEFT JOIN isp_invoices i ON i.id = c.invoice_id WHERE c.account_id = :a ORDER BY c.status = 'pending' DESC, c.charge_date DESC, c.id DESC LIMIT 60", ['a' => $id]);
    $addons = DB::all('SELECT * FROM isp_account_addons WHERE account_id = :a ORDER BY is_active DESC, start_date DESC', ['a' => $id]);
    $pending = array_values(array_filter($charges, fn($c) => $c['status'] === 'pending'));
    $canBill = Access::can($pk, 'create');
?>
<div class="row g-3 mt-0">
  <div class="col-12">
    <div class="card isp-card"><div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><h2 class="sec-title mb-0">Invoices</h2>
        <span class="small text-muted">Open <?= money($bal['open_invoices']) ?>, overdue <?= money($bal['overdue']) ?>, credit <?= money($bal['credit']) ?></span></div>
      <?php if (!$invoices): ?><?= IspUi::empty('No invoice yet.', 'receipt') ?><?php else: ?>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table mt-2">
        <thead><tr><th>Invoice</th><th>Date</th><th>Period</th><th>Due</th><th>Status</th><th class="num">Total</th><th class="num">Paid / credited</th><th class="num">Balance</th></tr></thead><tbody>
        <?php foreach ($invoices as $i): ?>
          <tr><td><?= safe_link('ispbill.invoices', ['id' => $i['id']], $i['invoice_no']) ?></td><td><?= h(fdate($i['invoice_date'])) ?></td>
            <td class="small"><?= $i['period_start'] ? h(fdate($i['period_start']) . ' - ' . fdate($i['period_end'])) : h(ucfirst((string)$i['kind'])) ?></td><td><?= h(fdate($i['due_date'])) ?></td>
            <td><?= IspUi::status($i['status'], IspBilling::STATUSES) ?></td><td class="num"><?= money($i['total']) ?></td><td class="num"><?= money((float)$i['paid_amount'] + (float)$i['credit_amount'], true) ?></td>
            <td class="num<?= (float)$i['balance'] > 0 && in_array($i['status'], IspBilling::OPEN, true) && $i['due_date'] < today() ? ' text-danger' : '' ?>"><?= money($i['balance'], true) ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-lg-7">
    <div class="card isp-card h-100"><div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><h2 class="sec-title mb-0">Charges</h2>
        <?php if ($canBill && $pending && !in_array($a['service_status'], ['pending', 'cancelled'], true)): ?>
          <?= IspUi::actionForm('bill_charges', 'Bill the pending charges now', [], 'btn-outline-primary', 'receipt', 'Issue an invoice now for the pending charges only?') ?>
        <?php endif; ?></div>
      <?php if (!$charges): ?><p class="small text-muted mt-2">No one-time charge. Charges wait here until the next invoice.</p><?php else: ?>
      <div class="table-responsive"><table class="table table-sm align-middle isp-table mt-2">
        <thead><tr><th>Date</th><th>Charge</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($charges as $c): ?>
          <tr><td><?= h(fdate($c['charge_date'])) ?></td><td><?= h($c['description']) ?><div class="small text-muted"><?= h(IspBilling::LINE_TYPES[$c['charge_type']] ?? $c['charge_type']) ?></div></td>
            <td class="num"><?= money($c['amount']) ?></td>
            <td><?= $c['status'] === 'billed' && $c['invoice_no'] ? 'Billed on ' . safe_link('ispbill.invoices', ['id' => $c['invoice_id']], $c['invoice_no']) : IspUi::status($c['status']) ?>
              <?php if ($c['cancel_reason']): ?><div class="small text-muted"><?= h($c['cancel_reason']) ?></div><?php endif; ?></td>
            <td class="text-end"><?php if ($c['status'] === 'pending' && Access::can($pk, 'delete')): ?>
              <form method="post" class="d-flex gap-1" data-confirm="Cancel this charge?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_charge"><input type="hidden" name="charge_id" value="<?= (int)$c['id'] ?>">
                <input class="form-control form-control-sm" name="reason" placeholder="Reason" required style="min-width:110px"><button class="btn btn-sm btn-outline-danger" title="Cancel the charge"><i class="bi bi-x-lg"></i></button></form>
            <?php endif; ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
      <?php endif; ?>
      <?php if ($canBill && !in_array($a['service_status'], ['cancelled'], true)): ?>
      <details class="mt-2" <?= is_post() && p('action') === 'add_charge' ? 'open' : '' ?>><summary class="small">Add a one-time charge or discount</summary>
        <form method="post" class="row g-2 mt-1"><?= csrf_field() ?><input type="hidden" name="action" value="add_charge"><input type="hidden" name="tab" value="billing">
          <?= select_field('charge_type', 'Kind', ['one_time' => 'One-time charge', 'installation' => 'Installation / activation', 'equipment' => 'Equipment', 'static_ip' => 'Static IP',
              'addon' => 'Add-on service', 'reconnection_fee' => 'Reconnection fee', 'discount' => 'Discount (a credit)'], fv('charge_type'), ['class' => 'col-md-4']) ?>
          <?= input_field('description', 'Description', fv('description'), ['class' => 'col-md-8', 'required' => true]) ?>
          <?= input_field('amount', 'Amount', fv('amount'), ['class' => 'col-md-4', 'type' => 'number', 'required' => true, 'extra' => 'min="0.01"']) ?>
          <?= input_field('charge_date', 'Date', fv('charge_date', null, today()), ['class' => 'col-md-4', 'type' => 'date']) ?>
          <?= check_field('bill_now', 'Bill it now (own invoice)', fv('bill_now'), 'col-md-4') ?>
          <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Add charge</button>
            <span class="small text-muted ms-2">VAT follows the plan (<?= h(IspPlans::VAT_MODES[$plan['vat_mode']] ?? $plan['vat_mode']) ?>).</span></div>
        </form></details>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-lg-5">
    <div class="card isp-card h-100"><div class="card-body">
      <h2 class="sec-title">Recurring add-ons</h2>
      <?php if (!$addons): ?><p class="small text-muted">No recurring add-on. Add-ons are billed every month with the subscription.</p><?php else: ?>
      <div class="table-responsive"><table class="table table-sm align-middle isp-table">
        <thead><tr><th>Add-on</th><th class="num">Monthly</th><th>Period</th><th></th></tr></thead><tbody>
        <?php foreach ($addons as $ad): $live = (int)$ad['is_active'] === 1 && (!$ad['end_date'] || $ad['end_date'] >= today()); ?>
          <tr class="<?= $live ? '' : 'text-muted' ?>"><td><?= h($ad['description']) ?><?= (int)$ad['taxable'] ? '' : '<div class="small">VAT-exempt</div>' ?></td><td class="num"><?= money($ad['amount']) ?></td>
            <td class="small"><?= h(fdate($ad['start_date'])) ?> to <?= $ad['end_date'] ? h(fdate($ad['end_date'])) : 'open' ?></td>
            <td class="text-end"><?php if ($live && $canEdit): ?>
              <form method="post" class="d-flex gap-1" data-confirm="End this add-on on the date given?"><?= csrf_field() ?><input type="hidden" name="action" value="end_addon"><input type="hidden" name="addon_id" value="<?= (int)$ad['id'] ?>">
                <input class="form-control form-control-sm" type="date" name="end_date" value="<?= h(today()) ?>"><button class="btn btn-sm btn-outline-secondary">End</button></form>
            <?php endif; ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
      <?php endif; ?>
      <?php if ($canEdit && !in_array($a['service_status'], ['terminated', 'cancelled'], true)): ?>
      <details class="mt-2" <?= is_post() && p('action') === 'add_addon' ? 'open' : '' ?>><summary class="small">Add a recurring add-on</summary>
        <form method="post" class="row g-2 mt-1"><?= csrf_field() ?><input type="hidden" name="action" value="add_addon"><input type="hidden" name="tab" value="billing">
          <?= input_field('addon_desc', 'Add-on', fv('addon_desc'), ['class' => 'col-12', 'required' => true, 'extra' => 'placeholder="e.g. Mesh Wi-Fi unit rental"']) ?>
          <?= input_field('addon_amount', 'Monthly amount', fv('addon_amount'), ['class' => 'col-md-6', 'type' => 'number', 'required' => true]) ?>
          <?= check_field('addon_taxable', 'Subject to VAT', fv('addon_taxable', null, '1'), 'col-md-6') ?>
          <?= input_field('addon_start', 'From', fv('addon_start', null, today()), ['class' => 'col-md-6', 'type' => 'date']) ?>
          <?= input_field('addon_end', 'Until (optional)', fv('addon_end'), ['class' => 'col-md-6', 'type' => 'date']) ?>
          <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Add</button></div>
        </form></details>
      <?php endif; ?>
    </div></div>
  </div>
</div>
<?php
// ================================================================ PAYMENTS
elseif ($tab === 'payments'):
    $pays = DB::all('SELECT y.*, u.full_name AS collector FROM isp_payments y LEFT JOIN users u ON u.id = y.collector_user_id WHERE y.account_id = :a ORDER BY y.pay_date DESC, y.id DESC LIMIT 100', ['a' => $id]);
?>
<div class="card isp-card mt-3"><div class="card-body">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><h2 class="sec-title mb-0">Payments</h2>
    <?= Access::can('ispbill.collect', 'create') ? btn_link(url('ispbill.collect', ['account_id' => $id]), 'Collect payment', 'btn-primary', 'cash-coin') : '' ?></div>
  <?php if (!$pays): ?><?= IspUi::empty('No payment recorded.', 'cash-stack') ?><?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table mt-2">
    <thead><tr><th>Receipt</th><th>Date</th><th>Channel</th><th>Reference</th><th>Status</th><th class="num">Amount</th><th class="num">Applied</th><th class="num">Advance</th><th class="d-none d-lg-table-cell">Collected by</th></tr></thead><tbody>
    <?php foreach ($pays as $y): ?>
      <tr><td><?= safe_link('ispbill.payments', ['id' => $y['id']], $y['receipt_no']) ?></td><td><?= h(fdate($y['pay_date'])) ?></td>
        <td><?= h(IspPayments::CHANNELS[$y['channel']] ?? $y['channel']) ?><?= $y['source'] !== 'counter' ? '<div class="small text-muted">' . h(ucfirst((string)$y['source'])) . '</div>' : '' ?></td>
        <td class="small"><?= h((string)($y['reference_no'] ?: $y['check_no'])) ?></td>
        <td><?= IspUi::status($y['status'], IspPayments::STATUSES) ?><?php if ($y['rejected_reason'] || $y['reversal_reason']): ?><div class="small text-muted"><?= h((string)($y['rejected_reason'] ?: $y['reversal_reason'])) ?></div><?php endif; ?></td>
        <td class="num"><?= money($y['amount']) ?></td><td class="num"><?= money($y['applied_amount'], true) ?></td><td class="num"><?= money($y['unapplied_amount'], true) ?></td>
        <td class="d-none d-lg-table-cell small"><?= h((string)$y['collector']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div></div>
<?php
// ================================================================ SERVICE
elseif ($tab === 'service'):
    $st = $a['service_status'];
    $planOpts = IspPlans::options(true, null, $a['area_id'] ? (int)$a['area_id'] : null);
    unset($planOpts[(int)$a['plan_id']]);
    $exemptions = DB::all('SELECT e.*, u.full_name AS approver FROM isp_exemptions e LEFT JOIN users u ON u.id = e.approved_by WHERE e.account_id = :a ORDER BY e.id DESC', ['a' => $id]);
    $discs = DB::all('SELECT * FROM isp_disc_queue WHERE account_id = :a ORDER BY id DESC LIMIT 10', ['a' => $id]);
    $recons = DB::all('SELECT * FROM isp_reconnections WHERE account_id = :a ORDER BY id DESC LIMIT 10', ['a' => $id]);
    $savOpts = $member ? IspAccounts::savingsOptions((int)$cust['id']) : [];
    $etfApplies = $a['lock_in_until'] && today() <= $a['lock_in_until'] && (float)$plan['early_termination_fee'] > 0;
    $live = in_array($st, ['active', 'suspended', 'disconnected', 'pending'], true);
?>
<div class="row g-3 mt-0">
  <?php if ($canEdit && $live && $planOpts): ?>
  <div class="col-lg-6"><div class="card isp-card h-100"><div class="card-body">
    <h2 class="sec-title">Change plan</h2>
    <form method="post" class="row g-2" data-confirm="Change the plan? A change inside a billed period is prorated on the next invoice."><?= csrf_field() ?>
      <input type="hidden" name="action" value="change_plan"><input type="hidden" name="tab" value="service">
      <?= select_field('plan_id', 'New plan', $planOpts, fv('plan_id'), ['class' => 'col-12', 'required' => true, 'blank' => '']) ?>
      <?= input_field('effective', 'Effective', fv('effective', null, today()), ['class' => 'col-md-5', 'type' => 'date']) ?>
      <?= input_field('reason', 'Reason', p('action') === 'change_plan' ? fv('reason') : '', ['class' => 'col-md-7', 'required' => true]) ?>
      <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-arrow-left-right"></i> Change plan</button>
        <span class="small text-muted ms-2">Now on <?= h($plan['name']) ?> at <?= money($fee) ?>.</span></div>
    </form>
  </div></div></div>
  <?php endif; ?>

  <?php if ($canEdit && in_array($st, ['active', 'suspended'], true)): ?>
  <div class="col-lg-6"><div class="card isp-card h-100"><div class="card-body">
    <h2 class="sec-title">Temporary suspension</h2>
    <?php if ($st === 'active'): ?>
    <form method="post" class="row g-2" data-confirm="Suspend the service? The network is asked to disable the subscriber."><?= csrf_field() ?>
      <input type="hidden" name="action" value="suspend"><input type="hidden" name="tab" value="service">
      <?= input_field('until', 'Until (optional)', fv('until'), ['class' => 'col-md-5', 'type' => 'date']) ?>
      <?= input_field('reason', 'Reason', p('action') === 'suspend' ? fv('reason') : '', ['class' => 'col-md-7', 'required' => true, 'extra' => 'placeholder="Asked by the subscriber"']) ?>
      <div class="col-12"><button class="btn btn-sm btn-outline-warning"><i class="bi bi-pause-circle"></i> Suspend</button></div>
    </form>
    <?php else: ?>
    <p class="small">Suspended<?= $a['suspended_until'] ? ' until ' . h(fdate($a['suspended_until'])) : '' ?>.</p>
    <form method="post" class="row g-2" data-confirm="Resume the service? The network is asked to enable the subscriber."><?= csrf_field() ?>
      <input type="hidden" name="action" value="resume"><input type="hidden" name="tab" value="service">
      <?= input_field('reason', 'Note', '', ['class' => 'col-md-8']) ?>
      <div class="col-md-4 d-flex align-items-end"><button class="btn btn-sm btn-success"><i class="bi bi-play-circle"></i> Resume</button></div>
    </form>
    <?php endif; ?>
  </div></div></div>
  <?php endif; ?>

  <?php if ($canEdit && in_array($st, ['active', 'suspended'], true) && Access::special('isp_disconnect')): ?>
  <div class="col-lg-6"><div class="card isp-card h-100"><div class="card-body">
    <h2 class="sec-title">Disconnect</h2>
    <p class="small text-muted">For overdue accounts the disconnection engine queues them itself after the grace period. Use this for the other reasons; it is recorded and can be reversed only by a reconnection.</p>
    <form method="post" class="row g-2" data-confirm="Queue this account for disconnection?"><?= csrf_field() ?>
      <input type="hidden" name="action" value="disconnect"><input type="hidden" name="tab" value="service">
      <?= select_field('reason_type', 'Reason', array_diff_key(IspDisconnect::REASON_TYPES, ['overdue' => 1]), fv('reason_type'), ['class' => 'col-md-6']) ?>
      <?= input_field('reason', 'Details', p('action') === 'disconnect' ? fv('reason') : '', ['class' => 'col-md-6', 'required' => true]) ?>
      <?= check_field('execute_now', 'Send to the network now', fv('execute_now'), 'col-md-7') ?>
      <div class="col-md-5 d-flex align-items-end"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-plug"></i> Queue disconnection</button></div>
    </form>
  </div></div></div>
  <?php endif; ?>

  <?php if ($canEdit && $st === 'disconnected' && Access::special('isp_reconnect')): ?>
  <div class="col-lg-6"><div class="card isp-card h-100"><div class="card-body">
    <h2 class="sec-title">Reconnect</h2>
    <p class="small text-muted">A payment that settles the arrears reconnects the account by itself. A manual reconnection is recorded with its reason and approver.</p>
    <form method="post" class="row g-2" data-confirm="Reconnect this account?"><?= csrf_field() ?>
      <input type="hidden" name="action" value="reconnect"><input type="hidden" name="tab" value="service">
      <?= select_field('recon_type', 'Kind', array_diff_key(IspReconnect::TYPES, ['automatic' => 1]), fv('recon_type'), ['class' => 'col-md-6']) ?>
      <?= input_field('expires_at', 'Expires (temporary)', fv('expires_at'), ['class' => 'col-md-6', 'type' => 'datetime-local']) ?>
      <?= input_field('reason', 'Reason', p('action') === 'reconnect' ? fv('reason') : '', ['class' => 'col-12', 'required' => true]) ?>
      <div class="col-12"><button class="btn btn-sm btn-success"><i class="bi bi-arrow-repeat"></i> Reconnect</button>
        <span class="small text-muted ms-2">A temporary reconnection without a time lasts <?= (int)IspSetup::int('isp_temp_recon_hours') ?> hours.</span></div>
    </form>
  </div></div></div>
  <?php endif; ?>

  <?php if ($member && $canEdit && $live): ?>
  <div class="col-lg-6"><div class="card isp-card h-100"><div class="card-body">
    <h2 class="sec-title">Auto-debit from savings</h2>
    <p class="small text-muted">For cooperative members: each invoice is paid from the chosen savings account on its due date, when the balance allows.</p>
    <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="autodebit"><input type="hidden" name="tab" value="service">
      <?= select_field('sav_account_id', 'Savings account', $savOpts, $a['autodebit_sav_account_id'], ['class' => 'col-md-8', 'blank' => 'No auto-debit']) ?>
      <div class="col-md-4 d-flex align-items-end"><button class="btn btn-sm btn-primary">Save</button></div>
    </form>
  </div></div></div>
  <?php endif; ?>

  <div class="col-lg-6"><div class="card isp-card h-100"><div class="card-body">
    <h2 class="sec-title">Disconnection exemptions</h2>
    <?php if (!$exemptions): ?><p class="small text-muted">None.</p><?php else: ?>
    <div class="table-responsive"><table class="table table-sm align-middle isp-table">
      <thead><tr><th>Period</th><th>Reason</th><th>Status</th><th></th></tr></thead><tbody>
      <?php foreach ($exemptions as $e): ?>
        <tr><td class="small"><?= h(fdate($e['valid_from']) . ' - ' . fdate($e['valid_until'])) ?></td><td><?= h($e['reason']) ?><div class="small text-muted"><?= h((string)$e['approver']) ?></div></td>
          <td><?= IspUi::status($e['status'] === 'active' && $e['valid_until'] < today() ? 'expired' : $e['status'], [], $e['status'] === 'revoked' ? 'idle' : null) ?></td>
          <td class="text-end"><?php if ($e['status'] === 'active' && $e['valid_until'] >= today() && $canEdit && Access::special('isp_exempt')): ?>
            <form method="post" class="d-flex gap-1" data-confirm="Revoke the exemption?"><?= csrf_field() ?><input type="hidden" name="action" value="revoke_exemption"><input type="hidden" name="exemption_id" value="<?= (int)$e['id'] ?>">
              <input class="form-control form-control-sm" name="reason" placeholder="Reason" required><button class="btn btn-sm btn-outline-danger">Revoke</button></form>
          <?php endif; ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    <?php if ($canEdit && Access::special('isp_exempt') && $live): ?>
    <details class="mt-2" <?= is_post() && p('action') === 'exempt' ? 'open' : '' ?>><summary class="small">Grant an exemption</summary>
      <form method="post" class="row g-2 mt-1"><?= csrf_field() ?><input type="hidden" name="action" value="exempt"><input type="hidden" name="tab" value="service">
        <?= input_field('valid_from', 'From', fv('valid_from', null, today()), ['class' => 'col-md-6', 'type' => 'date']) ?>
        <?= input_field('valid_until', 'Until', fv('valid_until'), ['class' => 'col-md-6', 'type' => 'date', 'required' => true]) ?>
        <?= input_field('reason', 'Reason', p('action') === 'exempt' ? fv('reason') : '', ['class' => 'col-12', 'required' => true, 'extra' => 'placeholder="e.g. hospitalized subscriber, approved by the GM"']) ?>
        <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-shield-check"></i> Grant</button></div>
      </form></details>
    <?php endif; ?>
  </div></div></div>

  <div class="col-lg-6"><div class="card isp-card h-100"><div class="card-body">
    <h2 class="sec-title">Disconnections and reconnections</h2>
    <?php if (!$discs && !$recons): ?><p class="small text-muted">None.</p><?php endif; ?>
    <?php if ($discs): ?>
    <div class="table-responsive"><table class="table table-sm align-middle isp-table">
      <thead><tr><th>Disconnection</th><th>Reason</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($discs as $d): ?>
        <tr><td><?= safe_link('ispnet.disconnections', ['id' => $d['id']], $d['queue_no']) ?><div class="small text-muted"><?= h(fdatetime($d['executed_at'] ?: $d['created_at'])) ?></div></td>
          <td class="small"><?= h($d['reason']) ?></td><td><?= IspUi::status($d['status'], IspDisconnect::STATUSES) ?><?= $d['skip_reason'] ? '<div class="small text-muted">' . h($d['skip_reason']) . '</div>' : '' ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    <?php if ($recons): ?>
    <div class="table-responsive"><table class="table table-sm align-middle isp-table">
      <thead><tr><th>Reconnection</th><th>Kind</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($recons as $r): ?>
        <tr><td><?= safe_link('ispnet.reconnections', ['id' => $r['id']], $r['recon_no']) ?><div class="small text-muted"><?= h(fdatetime($r['restored_at'] ?: $r['created_at'])) ?></div></td>
          <td class="small"><?= h(IspReconnect::TYPES[$r['recon_type']] ?? $r['recon_type']) ?><?= $r['expires_at'] ? ', until ' . h(fdatetime($r['expires_at'])) : '' ?></td>
          <td><?= IspUi::status($r['status'], IspReconnect::STATUSES) ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </div></div></div>

  <?php $refund = IspBilling::refundable($id); ?>
  <?php if ($refund['total'] > 0.004): ?>
  <div class="col-12"><div class="card isp-card"><div class="card-body">
    <h2 class="sec-title">Final refund to the subscriber</h2>
    <?= IspUi::dl(['Security deposit held' => $refund['deposit'] > 0 ? money($refund['deposit']) : null, 'Unused service paid in advance' => $refund['credit'] > 0 ? money($refund['credit']) : null,
        'To refund' => '<strong>' . money($refund['total']) . '</strong>']) ?>
    <?php if (IspAccounts::balances($id)['open_invoices'] > 0.004): ?>
      <p class="small text-muted mb-0">The account still has an open balance: settle it (or apply the deposit to it) before the refund.</p>
    <?php elseif (Access::can($pk, 'approve')): ?>
    <form method="post" class="row g-2" data-confirm="Record the refund of <?= h(money($refund['total'])) ?> to the subscriber?"><?= csrf_field() ?>
      <input type="hidden" name="action" value="final_refund"><input type="hidden" name="tab" value="service">
      <?= select_field('cash_account_id', 'Paid from', DB::pairs("SELECT id, CONCAT(code, ' - ', name) FROM accounts WHERE is_active = 1 AND account_type = 'Bank' ORDER BY code"), fv('cash_account_id', null, (string)Accts::get('isp_cash')), ['class' => 'col-md-4']) ?>
      <?= input_field('refund_date', 'Date', fv('refund_date', null, today()), ['class' => 'col-md-2', 'type' => 'date']) ?>
      <?= input_field('reason', 'Reason / reference', p('action') === 'final_refund' ? fv('reason') : '', ['class' => 'col-md-6', 'required' => true]) ?>
      <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-cash-coin"></i> Record the refund</button></div>
    </form>
    <?php else: ?>
      <p class="small text-muted mb-0">A user with the approve right on this page records the refund.</p>
    <?php endif; ?>
  </div></div></div>
  <?php endif; ?>

  <?php if (Access::can($pk, 'delete') && in_array($st, ['active', 'suspended', 'disconnected'], true)): ?>
  <div class="col-12"><div class="card isp-card border-danger-subtle"><div class="card-body">
    <h2 class="sec-title">Terminate the subscription</h2>
    <p class="small text-muted">The final invoice is issued up to the end date, the subscriber is removed from the network, company equipment is put up for recovery and the security deposit is applied to the balance.
      What the subscriber still owes stays on the account, and its history is kept.</p>
    <form method="post" class="row g-2" data-confirm="Terminate this subscription? This cannot be undone." data-confirm-tone="danger"><?= csrf_field() ?>
      <input type="hidden" name="action" value="terminate"><input type="hidden" name="tab" value="service">
      <?= input_field('end_date', 'Service ends', fv('end_date', null, today()), ['class' => 'col-md-3', 'type' => 'date']) ?>
      <?= input_field('reason', 'Reason', p('action') === 'terminate' ? fv('reason') : '', ['class' => 'col-md-6', 'required' => true]) ?>
      <?php if ($etfApplies): ?>
        <?= check_field('charge_etf', 'Charge the early termination fee of ' . money($plan['early_termination_fee']) . ' (lock-in until ' . fdate($a['lock_in_until']) . ')', fv('charge_etf', null, '1'), 'col-md-12') ?>
      <?php endif; ?>
      <div class="col-12"><button class="btn btn-sm btn-danger"><i class="bi bi-x-octagon"></i> Terminate</button></div>
    </form>
  </div></div></div>
  <?php endif; ?>
</div>
<?php
// ================================================================ NETWORK
elseif ($tab === 'network'):
    $dev = $a['device_id'] ? IspNetwork::device((int)$a['device_id']) : IspNetwork::defaultDevice();
    $adapter = IspNetwork::adapter($dev);
    $trace = IspInfra::trace($id);
    $ops = DB::all('SELECT o.*, u.full_name AS requester FROM isp_net_ops o LEFT JOIN users u ON u.id = o.requested_by WHERE o.account_id = :a ORDER BY o.id DESC LIMIT 25', ['a' => $id]);
    $wo = DB::row("SELECT onu_serial, router_serial, rx_power_dbm, tx_power_dbm, speed_test_down, speed_test_up, completed_at FROM isp_work_orders WHERE account_id = :a AND status IN ('completed','verified') AND (rx_power_dbm IS NOT NULL OR onu_serial IS NOT NULL) ORDER BY completed_at DESC LIMIT 1", ['a' => $id]);
?>
<div class="row g-3 mt-0">
  <div class="col-lg-6"><div class="card isp-card h-100"><div class="card-body">
    <h2 class="sec-title">Connection</h2>
    <?= IspUi::dl([
        'Network state' => IspUi::status($a['network_state'], IspAccounts::NETWORK_STATES),
        'Device' => $dev ? h($dev['name']) . ' <span class="small text-muted">' . h(IspNetwork::ADAPTER_LABELS[$dev['adapter']] ?? $dev['adapter']) . '</span>' : 'None: changes are done by technicians (work orders)',
        'PPPoE user name' => '<code>' . h((string)$a['pppoe_username']) . '</code>',
        'PPPoE password' => $revealed !== null ? '<code>' . h($revealed) . '</code> <span class="small text-muted">(shown once; this was recorded in the audit trail)</span>'
            : ($mayReveal ? IspUi::actionForm('reveal', 'Show', ['tab' => 'network'], 'btn-outline-secondary', 'eye') : '<span class="text-muted">Hidden</span>'),
        'Plan profile' => h((string)($plan['network_profile'] ?: $plan['code'])) . ' <span class="small text-muted">' . h(IspPlans::speed($plan)) . '</span>',
        'Static IP' => $a['static_ip'] ? h($a['static_ip']) : null,
        'IP pool' => $a['ip_pool'] ? h($a['ip_pool']) : null,
        'MAC address' => $a['mac_address'] ? h($a['mac_address']) : null,
        'Last session' => $a['last_session_at'] ? h(fdatetime($a['last_session_at'])) : null,
        'Drop cable' => $a['drop_length_m'] !== null ? h(qty($a['drop_length_m'])) . ' m' : null,
        'ONU / router' => $wo ? h(trim(($wo['onu_serial'] ?? '') . ' ' . ($wo['router_serial'] ?? ''))) : null,
        'Signal at installation' => $wo && $wo['rx_power_dbm'] !== null ? h($wo['rx_power_dbm'] . ' dBm Rx' . ($wo['tx_power_dbm'] !== null ? ', ' . $wo['tx_power_dbm'] . ' dBm Tx' : '')) : null,
        'Speed test' => $wo && $wo['speed_test_down'] !== null ? h(qty($wo['speed_test_down']) . ' / ' . qty($wo['speed_test_up']) . ' Mbps') : null,
    ]) ?>
    <?php if ($dev && $adapter->simulated()): ?>
      <div class="alert alert-warning py-2 small mb-2"><i class="bi bi-cpu"></i> This account's device is the integration simulator: results are simulated and no real device is changed.</div>
    <?php endif; ?>
    <?php if ($canEdit && !in_array($a['service_status'], ['terminated', 'cancelled'], true)): ?>
    <form method="post" class="row g-2 mt-1"><?= csrf_field() ?><input type="hidden" name="action" value="net_op"><input type="hidden" name="tab" value="network">
      <?php $opOpts = [];
      foreach (['status' => 'Read the status', 'reset_session' => 'Reset the session (disconnect and reconnect the PPPoE session)', 'provision' => 'Provision again', 'change_plan' => 'Send the plan profile again'] as $k => $lbl) {
          if ($adapter->supports($k) && (in_array($k, ['status', 'reset_session'], true) || Access::special('isp_network_admin'))) {
              $opOpts[$k] = $lbl;
          }
      } ?>
      <?= select_field('op', 'Network operation', $opOpts, fv('op'), ['class' => 'col-md-7']) ?>
      <?= input_field('reason', 'Reason', '', ['class' => 'col-md-5', 'extra' => 'placeholder="Optional"']) ?>
      <div class="col-12"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-send"></i> Send</button></div>
    </form>
    <?php endif; ?>
  </div></div></div>
  <div class="col-lg-6"><div class="card isp-card h-100"><div class="card-body">
    <h2 class="sec-title">Path to the OLT</h2>
    <?php if (!$trace): ?><p class="small text-muted">No port assigned to this account.</p><?php else: ?>
    <ol class="isp-trace mb-0">
      <?php foreach ($trace as $t): ?>
        <li><strong><?= h($t['node']['code']) ?></strong> <?= h($t['node']['name']) ?>
          <span class="small text-muted"><?= h(IspInfra::NODE_TYPES[$t['node']['node_type']] ?? $t['node']['node_type']) ?><?= $t['port'] ? ', port ' . (int)$t['port']['port_no'] : '' ?></span>
          <?= IspUi::status($t['node']['status'], IspInfra::NODE_STATUSES) ?></li>
      <?php endforeach; ?>
    </ol>
    <?php if (Access::page('ispnet.infra')): ?><a class="small" href="<?= h(url('ispnet.infra', ['id' => $trace[0]['node']['id']])) ?>">Open the node</a><?php endif; ?>
    <?php endif; ?>
  </div></div></div>
  <div class="col-12"><div class="card isp-card"><div class="card-body">
    <h2 class="sec-title">Network operations</h2>
    <?php if (!$ops): ?><p class="small text-muted">No network operation yet.</p><?php else: ?>
    <div class="table-responsive"><table class="table table-sm align-middle isp-table">
      <thead><tr><th>Operation</th><th>Requested</th><th>Why</th><th>State</th><th class="d-none d-md-table-cell">Result</th></tr></thead><tbody>
      <?php foreach ($ops as $o): ?>
        <tr><td><?= safe_link('ispnet.operations', ['id' => $o['id']], $o['op_no']) ?><div class="small"><?= h(IspNetwork::OP_TYPES[$o['op_type']] ?? $o['op_type']) ?></div></td>
          <td class="small"><?= h(fdatetime($o['created_at'])) ?><div class="text-muted"><?= h((string)$o['requester']) ?></div></td>
          <td class="small"><?= h((string)$o['reason']) ?></td>
          <td><?= IspUi::status($o['state'], IspNetwork::STATES) ?><?= (int)$o['simulated'] ? '<div class="small text-muted">Simulated: no real device was changed</div>' : '' ?></td>
          <td class="small d-none d-md-table-cell text-break"><?= h(mb_substr((string)($o['error'] ?: $o['response']), 0, 220)) ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </div></div></div>
</div>
<?php
// ================================================================ SUPPORT
elseif ($tab === 'support'):
    $tickets = DB::all('SELECT * FROM isp_tickets WHERE account_id = :a ORDER BY id DESC LIMIT 50', ['a' => $id]);
    $wos = DB::all('SELECT w.*, t.name AS tech FROM isp_work_orders w LEFT JOIN isp_technicians t ON t.id = w.technician_id WHERE w.account_id = :a ORDER BY w.id DESC LIMIT 50', ['a' => $id]);
    $reqs = DB::all('SELECT * FROM isp_service_requests WHERE account_id = :a ORDER BY id DESC LIMIT 50', ['a' => $id]);
?>
<div class="row g-3 mt-0">
  <div class="col-12"><div class="card isp-card"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><h2 class="sec-title mb-0">Support tickets</h2>
      <?= Access::can('ispfield.tickets', 'create') ? btn_link(url('ispfield.tickets', ['a' => 'new', 'account_id' => $id]), 'New ticket', 'btn-outline-primary', 'plus-lg') : '' ?></div>
    <?php if (!$tickets): ?><p class="small text-muted mt-2">No ticket.</p><?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table mt-2">
      <thead><tr><th>Ticket</th><th>Opened</th><th>Problem</th><th>Priority</th><th>Status</th><th class="d-none d-md-table-cell">Resolution</th></tr></thead><tbody>
      <?php foreach ($tickets as $t): ?>
        <tr><td><?= safe_link('ispfield.tickets', ['id' => $t['id']], $t['ticket_no']) ?></td><td class="small"><?= h(fdatetime($t['created_at'])) ?></td>
          <td><?= h($t['subject']) ?><div class="small text-muted"><?= h(IspTickets::TYPES[$t['ticket_type']] ?? $t['ticket_type']) ?></div></td>
          <td><?= IspUi::status($t['priority'], IspTickets::PRIORITIES) ?></td><td><?= IspUi::status($t['status'], IspTickets::STATUSES) ?></td>
          <td class="small d-none d-md-table-cell"><?= h((string)$t['resolution']) ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </div></div></div>
  <div class="col-lg-7"><div class="card isp-card h-100"><div class="card-body">
    <h2 class="sec-title">Work orders</h2>
    <?php if (!$wos): ?><p class="small text-muted">No work order.</p><?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
      <thead><tr><th>Work order</th><th>Kind</th><th>Scheduled</th><th>Technician</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($wos as $w): ?>
        <tr><td><?= safe_link('ispfield.workorders', ['id' => $w['id']], $w['wo_no']) ?></td><td><?= h(IspWorkOrders::TYPES[$w['wo_type']] ?? $w['wo_type']) ?></td>
          <td class="small"><?= h(fdate($w['scheduled_date'])) ?> <?= h((string)$w['scheduled_slot']) ?></td><td class="small"><?= h((string)$w['tech']) ?></td>
          <td><?= IspUi::status($w['status'], IspWorkOrders::STATUSES) ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </div></div></div>
  <div class="col-lg-5"><div class="card isp-card h-100"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><h2 class="sec-title mb-0">Service requests</h2>
      <?= Access::can('isp.requests', 'create') ? btn_link(url('isp.requests', ['a' => 'new', 'account_id' => $id]), 'New request', 'btn-outline-primary', 'plus-lg') : '' ?></div>
    <?php if (!$reqs): ?><p class="small text-muted mt-2">No request.</p><?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table mt-2">
      <thead><tr><th>Request</th><th>Kind</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($reqs as $r): ?>
        <tr><td><?= safe_link('isp.requests', ['id' => $r['id']], $r['request_no']) ?><div class="small text-muted"><?= h(fdate($r['created_at'])) ?></div></td>
          <td><?= h(ucfirst(str_replace('_', ' ', (string)$r['request_type']))) ?></td><td><?= IspUi::status($r['status'] === 'done' ? 'completed' : $r['status'], [], null) ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </div></div></div>
</div>
<?php
// ================================================================ EQUIPMENT
elseif ($tab === 'equipment'):
    $eq = IspEquipment::ofAccount($id);
?>
<div class="card isp-card mt-3"><div class="card-body">
  <h2 class="sec-title">Equipment at this subscriber</h2>
  <?php if (!$eq): ?><?= IspUi::empty('No equipment recorded on this account.', 'router') ?><?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
    <thead><tr><th>Item</th><th>Serial no.</th><th>MAC</th><th>Ownership</th><th>Status</th><th>Installed</th><th class="num">Deposit</th><th class="num">Rental</th></tr></thead><tbody>
    <?php foreach ($eq as $e): ?>
      <tr><td><?= h($e['item_code']) ?> <span class="text-muted"><?= h($e['item_name']) ?></span></td>
        <td><?= safe_link('ispfield.equipment', ['id' => $e['id']], (string)$e['serial_no']) ?></td><td class="small"><?= h((string)$e['mac_address']) ?></td>
        <td class="small"><?= h(IspEquipment::OWNERSHIP[$e['ownership']] ?? $e['ownership']) ?></td><td><?= IspUi::status($e['status'], IspEquipment::STATUSES) ?></td>
        <td class="small"><?= h(fdate($e['installed_at'])) ?><?= $e['recovered_at'] ? '<div class="text-muted">Recovered ' . h(fdate($e['recovered_at'])) . '</div>' : '' ?></td>
        <td class="num"><?= money($e['deposit'], true) ?></td><td class="num"><?= money($e['rental_fee'], true) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div></div>
<?php
// ================================================================ HISTORY
elseif ($tab === 'timeline'):
    $types = DB::column('SELECT DISTINCT event_type FROM isp_account_events WHERE account_id = :a ORDER BY event_type', ['a' => $id]);
    $type = in_array((string)g('type'), $types, true) ? (string)g('type') : '';
    $tp = ['a' => $id];
    $tw = '';
    if ($type !== '') {
        $tw = ' AND e.event_type = :t';
        $tp['t'] = $type;
    }
    [$per, $off, $page] = IspUi::paging(50);
    $total = (int)DB::value('SELECT COUNT(*) FROM isp_account_events e WHERE e.account_id = :a' . $tw, $tp);
    $events = DB::all('SELECT e.*, u.full_name FROM isp_account_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.account_id = :a' . $tw . ' ORDER BY e.event_date DESC, e.id DESC LIMIT ' . $per . ' OFFSET ' . $off, $tp);
?>
<div class="card isp-card mt-3"><div class="card-body">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h2 class="sec-title mb-0">Account history</h2>
    <form method="get" class="d-flex gap-2 no-print" action="<?= h(base_url() . '/index.php') ?>"><input type="hidden" name="p" value="<?= h($pk) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="timeline">
      <select class="form-select form-select-sm" name="type" data-autosubmit><?= options(array_combine($types, array_map(fn($t) => ucfirst(str_replace('_', ' ', $t)), $types)) ?: [], $type, 'Everything') ?></select></form>
  </div>
  <?php if (!$events): ?><p class="small text-muted mt-2">Nothing recorded.</p><?php else: ?>
  <ul class="isp-timeline list-unstyled mt-3 mb-0">
    <?php foreach ($events as $e): ?>
      <li class="isp-timeline-item">
        <div class="small text-muted"><?= h(fdatetime($e['event_date'])) ?><?= $e['full_name'] ? ', ' . h($e['full_name']) : '' ?></div>
        <div><strong><?= h(ucfirst(str_replace('_', ' ', (string)$e['event_type']))) ?></strong> <?= h($e['summary']) ?><?= $e['amount'] !== null ? ' <span class="text-muted">(' . money($e['amount']) . ')</span>' : '' ?></div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div></div>
<?= IspUi::pager($total, $per, $page) ?>
<?php
// ================================================================ DOCUMENTS
elseif ($tab === 'documents'):
    $files = DB::all("SELECT f.*, u.full_name FROM isp_files f LEFT JOIN users u ON u.id = f.uploaded_by
        WHERE (f.entity_type = 'account' AND f.entity_id = :a) OR (f.entity_type = 'application' AND f.entity_id = :ap) ORDER BY f.id DESC", ['a' => $id, 'ap' => (int)$a['application_id']]);
?>
<div class="card isp-card mt-3"><div class="card-body">
  <h2 class="sec-title">Documents</h2>
  <p class="small text-muted">IDs, signed contracts, proofs of billing and site photos. Files are kept outside the web folder and opened only through this page.</p>
  <?php if (!$files): ?><p class="small text-muted">No document.</p><?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
    <thead><tr><th>Document</th><th>File</th><th class="num">Size</th><th>Uploaded</th><th></th></tr></thead><tbody>
    <?php foreach ($files as $f): ?>
      <tr><td><?= h((string)$f['label']) ?><?= $f['entity_type'] === 'application' ? '<div class="small text-muted">From the application</div>' : '' ?></td>
        <td class="small"><a href="<?= h(url($pk, ['id' => $id, 'a' => 'file', 'fid' => $f['id']])) ?>" target="_blank" rel="noopener"><?= h((string)$f['original_name']) ?></a></td>
        <td class="num small"><?= h(number_format((int)$f['size_bytes'] / 1024, 0)) ?> KB</td><td class="small"><?= h(fdatetime($f['created_at'])) ?><div class="text-muted"><?= h((string)$f['full_name']) ?></div></td>
        <td class="text-end"><?php if ($f['entity_type'] === 'account' && Access::can($pk, 'delete')): ?>
          <form method="post" class="d-flex gap-1" data-confirm="Remove this document?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_file"><input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
            <input class="form-control form-control-sm" name="reason" placeholder="Reason" required style="min-width:110px"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
        <?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
  <?php if ($canEdit): ?>
  <form method="post" enctype="multipart/form-data" class="row g-2 mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="upload"><input type="hidden" name="tab" value="documents">
    <input type="hidden" name="MAX_FILE_SIZE" value="<?= 5 * 1024 * 1024 ?>">
    <?= input_field('label', 'Document', '', ['class' => 'col-md-4', 'extra' => 'placeholder="e.g. Valid ID, signed contract"']) ?>
    <div class="col-md-5"><label class="form-label">File (JPG, PNG, WEBP or PDF, up to 5 MB)</label><input class="form-control form-control-sm" type="file" name="file" accept=".jpg,.jpeg,.png,.webp,.pdf" required></div>
    <div class="col-md-3 d-flex align-items-end"><button class="btn btn-sm btn-primary"><i class="bi bi-upload"></i> Upload</button></div>
  </form>
  <?php endif; ?>
</div></div>
<?php
// ================================================================ PORTAL
elseif ($tab === 'portal'):
    $pu = DB::row('SELECT * FROM isp_portal_users WHERE isp_customer_id = :c', ['c' => $cust['id']]);
?>
<div class="card isp-card mt-3"><div class="card-body">
  <h2 class="sec-title">Customer portal log-in</h2>
  <?php if (IspSetup::get('isp_portal_enabled') !== '1'): ?><div class="alert alert-warning py-2 small">The customer portal is switched off (ISP Setup, Policies).</div><?php endif; ?>
  <?php if ($portalPassword !== null): ?>
    <div class="alert alert-success">
      <div>Give these to the subscriber. The password is shown only now; the subscriber is asked to change it on the first log-in.</div>
      <div class="mt-2">User name <code><?= h($cust['customer_no']) ?></code>, password <code><?= h($portalPassword) ?></code>
        <button type="button" class="btn btn-sm btn-outline-secondary ms-2" data-copy="<?= h($cust['customer_no'] . ' / ' . $portalPassword) ?>"><i class="bi bi-clipboard"></i> Copy</button></div>
      <?php
        // with several companies the portal is opened with the company's code
        $coCode = '';
        if (Master::enabled() && Tenant::id()) {
            try {
                $coCode = (string)Master::sql()->value("SELECT code FROM company_codes WHERE company_id = :c AND status <> 'retired' ORDER BY id DESC LIMIT 1", ['c' => (int)Tenant::id()]);
            } catch (Throwable $e) {
                $coCode = '';
            }
        } ?>
      <div class="small mt-1">Portal address: <?= h(IspPortal::url('login', $coCode !== '' ? ['co' => $coCode] : [])) ?></div>
    </div>
  <?php endif; ?>
  <?php $pu = DB::row('SELECT * FROM isp_portal_users WHERE isp_customer_id = :c', ['c' => $cust['id']]); ?>
  <?php if (!$pu): ?>
    <p class="small text-muted">The subscriber has no portal log-in yet. Subscribers can register themselves with their account number and mobile number<?= IspSetup::get('isp_portal_self_register') === '1' ? '' : ' (self-registration is switched off)' ?>, or staff can set a password here.</p>
  <?php else: ?>
    <?= IspUi::dl([
        'User name' => '<code>' . h($pu['username']) . '</code>',
        'Status' => IspUi::status($pu['status'] === 'active' ? 'active' : 'inactive', ['active' => 'Active', 'inactive' => 'Disabled']),
        'Last log-in' => $pu['last_login_at'] ? h(fdatetime($pu['last_login_at'])) . ' <span class="small text-muted">' . h((string)$pu['last_login_ip']) . '</span>' : 'Never',
        'Failed attempts' => (int)$pu['failed_attempts'] ? (string)(int)$pu['failed_attempts'] : null,
        'Password' => (int)$pu['must_change_password'] ? 'Temporary (to be changed at the next log-in)' : 'Chosen by the subscriber',
    ]) ?>
  <?php endif; ?>
  <?php if ($canEdit): ?>
  <div class="d-flex flex-wrap gap-2 mt-2">
    <?= IspUi::actionForm('portal_grant', $pu ? 'Reset the password' : 'Create the portal log-in', ['tab' => 'portal'], 'btn-primary', 'key', $pu ? 'Set a new temporary password? The current one stops working.' : '') ?>
    <?php if ($pu): ?><?= IspUi::actionForm('portal_lock', $pu['status'] === 'active' ? 'Disable the log-in' : 'Enable the log-in', ['tab' => 'portal'], 'btn-outline-secondary', $pu['status'] === 'active' ? 'lock' : 'unlock') ?><?php endif; ?>
  </div>
  <?php endif; ?>
</div></div>
<?php
endif;
layout_end();
