<?php
declare(strict_types=1);

/**
 * The customer self-service portal (portal/): its own session (a cookie of its own, apart from the staff's), its
 * own accounts (isp_portal_users: one per subscriber), and every read limited to the subscriber's own accounts.
 * With several companies the portal is opened with the company's code (portal/?co=ABC-12345), kept in the session.
 */
final class IspPortal
{
    private const MAX_FAILED = 5;
    private const IDLE_MINUTES = 30;
    private static ?array $customer = null;

    /** Resolves the company of the portal session (multi-company) and the logged-in subscriber */
    public static function boot(): void
    {
        if (!Master::enabled()) {
            return;
        }
        $code = trim((string)($_GET['co'] ?? ''));
        if ($code !== '') {
            $c = self::companyByCode($code);
            if ($c && (int)$c['id'] !== (int)($_SESSION['portal_company'] ?? 0)) {
                // a link of another company: the subscriber of the previous one is logged out first
                unset($_SESSION['portal']);
                $_SESSION['portal_company'] = (int)$c['id'];
            }
        }
        $cid = (int)($_SESSION['portal_company'] ?? 0);
        if (!$cid) {
            DB::block('No company chosen for the portal.');
            return;
        }
        $c = Master::company($cid);
        if (!$c || $c['status'] !== 'active') {
            unset($_SESSION['portal_company'], $_SESSION['portal']);
            DB::block('The company is not available.');
            return;
        }
        // bootCli() is not only for the command line: it opens the company with the multi-company flag set, so its
        // own storage folders and settings are used (Tenant::use() alone would leave the first company's folders)
        Tenant::bootCli((int)$c['id']);
    }

    /** The company of a code (the code is only for finding the company; the subscriber still logs in) */
    public static function companyByCode(string $code): ?array
    {
        $code = CompanyCode::normalize($code);
        if ($code === '' || strlen($code) > 20) {
            return null;
        }
        $row = Master::sql()->row("SELECT k.company_id FROM company_codes k JOIN companies c ON c.id = k.company_id WHERE k.code = :c AND k.status <> 'retired' AND c.status = 'active'", ['c' => $code]);
        return $row ? Master::company((int)$row['company_id'], true) : null;
    }

    public static function companyChosen(): bool
    {
        return !Master::enabled() || (int)($_SESSION['portal_company'] ?? 0) > 0;
    }

    public static function login(string $username, string $password): void
    {
        $username = trim($username);
        $u = DB::row('SELECT * FROM isp_portal_users WHERE username = :u', ['u' => $username]);
        if (!$u) {
            // the customer number or an account number of the subscriber also works as the user name
            $cid = DB::value("SELECT c.id FROM isp_customers c LEFT JOIN isp_accounts a ON a.isp_customer_id = c.id WHERE c.customer_no = :n OR a.account_no = :n2 LIMIT 1", ['n' => $username, 'n2' => $username]);
            $u = $cid ? DB::row('SELECT * FROM isp_portal_users WHERE isp_customer_id = :c', ['c' => $cid]) : null;
        }
        if (!$u || $u['status'] === 'inactive') {
            password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            fail('The user name or password is not correct.');
        }
        if ($u['status'] === 'locked') {
            fail('This portal account is locked after too many failed attempts. Call or visit our office to unlock it.');
        }
        if (!password_verify($password, (string)$u['password_hash'])) {
            $n = (int)$u['failed_attempts'] + 1;
            DB::update('isp_portal_users', ['failed_attempts' => $n, 'status' => $n >= self::MAX_FAILED ? 'locked' : $u['status']], 'id = :id', ['id' => $u['id']]);
            fail('The user name or password is not correct.' . ($n >= self::MAX_FAILED ? ' The account is now locked.' : ''));
        }
        session_regenerate_id(true);
        $_SESSION['portal'] = ['puid' => (int)$u['id'], 'cid' => (int)$u['isp_customer_id'], 'last' => time()];
        DB::update('isp_portal_users', ['failed_attempts' => 0, 'last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => client_ip()], 'id = :id', ['id' => $u['id']]);
        Audit::log('portal_login', 'isp', 'isp_portal_user', (int)$u['id'], (string)$u['username'], ['user_id' => null, 'username' => 'portal:' . $u['username'], 'roles' => 'Subscriber']);
    }

    public static function logout(): void
    {
        unset($_SESSION['portal']);
        session_regenerate_id(true);
    }

    /** The logged-in subscriber, or null (idle sessions end) */
    public static function customer(): ?array
    {
        if (self::$customer !== null) {
            return self::$customer;
        }
        $s = $_SESSION['portal'] ?? null;
        if (!$s || DB::isBlocked()) {
            return null;
        }
        if (time() - (int)$s['last'] > self::IDLE_MINUTES * 60) {
            self::logout();
            flash('warning', 'You were logged out after ' . self::IDLE_MINUTES . ' minutes without activity.');
            return null;
        }
        $_SESSION['portal']['last'] = time();
        $u = DB::row("SELECT * FROM isp_portal_users WHERE id = :id AND status = 'active'", ['id' => $s['puid']]);
        if (!$u || (int)$u['isp_customer_id'] !== (int)$s['cid']) {
            self::logout();
            return null;
        }
        $c = IspAccounts::customer((int)$u['isp_customer_id']);
        return self::$customer = $c ? $c + ['portal_user' => $u] : null;
    }

    public static function require(): array
    {
        $c = self::customer();
        if (!$c) {
            redirect(self::url('login'));
        }
        return $c;
    }

    /** The subscriber's own accounts */
    public static function accounts(int $customerId): array
    {
        $rows = DB::all("SELECT a.*, p.name AS plan_name, p.download_mbps, p.upload_mbps FROM isp_accounts a JOIN isp_plans p ON p.id = a.plan_id
                          WHERE a.isp_customer_id = :c AND a.service_status <> 'cancelled' ORDER BY a.account_no", ['c' => $customerId]);
        foreach ($rows as &$r) {
            $r += IspAccounts::balances((int)$r['id']);
        }
        return $rows;
    }

    /** An account of the logged-in subscriber, or a refusal (never another subscriber's record) */
    public static function account(int $id): array
    {
        $c = self::require();
        $a = IspAccounts::get($id);
        if (!$a || (int)$a['isp_customer_id'] !== (int)$c['id']) {
            Audit::log('access_denied', 'isp', 'isp_account', $id, 'portal', ['username' => 'portal:' . $c['customer_no'], 'reason' => 'Subscriber tried to open an account that is not theirs']);
            if (!headers_sent()) {
                http_response_code(403);
            }
            fail('That account is not yours.');
        }
        return $a;
    }

    public static function url(string $page = 'home', array $q = []): string
    {
        return base_url() . '/portal/index.php?' . http_build_query(['v' => $page] + $q);
    }

    /** Staff: gives a subscriber portal access with a temporary password (shown once). Returns the password. */
    public static function grant(int $customerId): string
    {
        $c = IspAccounts::customer($customerId) ?? fail('Subscriber not found.');
        $pw = IspCrypto::password(10);
        $u = DB::row('SELECT * FROM isp_portal_users WHERE isp_customer_id = :c', ['c' => $customerId]);
        if ($u) {
            DB::update('isp_portal_users', ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'status' => 'active', 'failed_attempts' => 0, 'must_change_password' => 1], 'id = :id', ['id' => $u['id']]);
        } else {
            DB::insert('isp_portal_users', ['isp_customer_id' => $customerId, 'username' => (string)$c['customer_no'], 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change_password' => 1]);
        }
        Audit::log('portal_access', 'isp', 'isp_customer', $customerId, (string)$c['customer_no'], ['reason' => 'Portal password set by staff']);
        return $pw;
    }

    /** Self-registration: the account number and the last four digits of the mobile number on file prove who it is */
    public static function register(string $accountNo, string $mobileLast4, string $password, string $password2): void
    {
        if (IspSetup::get('isp_portal_self_register') !== '1') {
            fail('Online registration is switched off. Ask our office for your portal password.');
        }
        $a = IspAccounts::byNo($accountNo);
        $digits = $a ? preg_replace('/\D/', '', (string)$a['mobile']) : '';
        if (!$a || strlen($digits) < 4 || substr($digits, -4) !== preg_replace('/\D/', '', $mobileLast4)) {
            fail('We could not match that account number with the mobile number on file.');
        }
        $existing = DB::row('SELECT * FROM isp_portal_users WHERE isp_customer_id = :c', ['c' => $a['isp_customer_id']]);
        if ($existing && $existing['status'] !== 'inactive') {
            fail('This subscriber already has a portal account. Log in with your customer number ' . $a['customer_no'] . ', or use "Forgot password".');
        }
        if ($existing) {
            // a portal account switched off by the office is not re-opened online
            fail('The portal account of this subscriber was closed by our office. Please call or visit us to open it again.');
        }
        self::checkNewPassword($password, $password2);
        $id = DB::insert('isp_portal_users', ['isp_customer_id' => (int)$a['isp_customer_id'], 'username' => (string)$a['customer_no'],
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'must_change_password' => 0]);
        IspAccounts::event((int)$a['id'], 'portal', 'Registered on the customer portal');
        Audit::log('portal_register', 'isp', 'isp_portal_user', $id, (string)$a['customer_no'], ['user_id' => null, 'username' => 'portal:' . $a['customer_no'], 'roles' => 'Subscriber']);
    }

    /**
     * Forgotten password: the same proof as the registration (account number and the last four digits of the mobile
     * number on file). A wrong proof counts as a failed log-in, so the lock-out of login() protects it too.
     * Returns the user name to log in with.
     */
    public static function resetPassword(string $accountNo, string $mobileLast4, string $password, string $password2): string
    {
        $a = IspAccounts::byNo($accountNo);
        $u = $a ? DB::row('SELECT * FROM isp_portal_users WHERE isp_customer_id = :c', ['c' => $a['isp_customer_id']]) : null;
        if (!$u || $u['status'] === 'inactive') {
            fail('We could not find a portal account for that account number. Register first, or ask our office.');
        }
        if ($u['status'] === 'locked') {
            fail('This portal account is locked after too many failed attempts. Call or visit our office to unlock it.');
        }
        $digits = preg_replace('/\D/', '', (string)$a['mobile']);
        if (strlen($digits) < 4 || substr($digits, -4) !== preg_replace('/\D/', '', $mobileLast4)) {
            $n = (int)$u['failed_attempts'] + 1;
            DB::update('isp_portal_users', ['failed_attempts' => $n, 'status' => $n >= self::MAX_FAILED ? 'locked' : $u['status']], 'id = :id', ['id' => $u['id']]);
            fail('We could not match that account number with the mobile number on file.' . ($n >= self::MAX_FAILED ? ' The portal account is now locked.' : ''));
        }
        self::checkNewPassword($password, $password2);
        DB::update('isp_portal_users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'must_change_password' => 0, 'failed_attempts' => 0], 'id = :id', ['id' => $u['id']]);
        Audit::log('portal_password_reset', 'isp', 'isp_portal_user', (int)$u['id'], (string)$u['username'], ['user_id' => null, 'username' => 'portal:' . $u['username'], 'roles' => 'Subscriber']);
        return (string)$u['username'];
    }

    private static function checkNewPassword(string $password, string $password2): void
    {
        if ($password !== $password2) {
            fail('The two passwords are not the same.');
        }
        if (strlen($password) < 8 || !preg_match('/\d/', $password) || !preg_match('/[A-Za-z]/', $password)) {
            fail('Choose a password of at least 8 characters with letters and numbers.');
        }
    }

    public static function changePassword(array $c, string $old, string $new, string $new2): void
    {
        $u = $c['portal_user'];
        if (!password_verify($old, (string)$u['password_hash'])) {
            fail('The current password is not correct.');
        }
        if ($new === $old) {
            fail('Choose a password different from the current one.');
        }
        self::checkNewPassword($new, $new2);
        DB::update('isp_portal_users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'must_change_password' => 0], 'id = :id', ['id' => $u['id']]);
        Audit::log('portal_password_change', 'isp', 'isp_portal_user', (int)$u['id'], (string)$u['username'], ['user_id' => null, 'username' => 'portal:' . $u['username'], 'roles' => 'Subscriber']);
        self::$customer = null;
    }
}
