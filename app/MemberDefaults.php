<?php
declare(strict_types=1);

/**
 * One name for a cooperative member in every line of business.
 *
 * A member who buys from the cooperative, sells to it or subscribes to its cable / internet service is recorded once,
 * on the membership form. The books still need a customer record (receivables, sales, subscriptions) and a supplier
 * record (payables, purchases), but those are the member's own records, kept by the program:
 *  - they are made with the member (Members::ensureCustomerRoles / ensureVendorRoles, on the member's party);
 *  - their name, TIN and contact details follow the member record (syncRoles);
 *  - their defaults are entered on the member form, on the tabs Customer Defaults and Vendor Defaults (saveCustomer,
 *    saveVendor), so no second form is filled for the same person;
 *  - the name pickers list the member once (Names, code m<member id>) and the role the member plays is chosen by
 *    the account of the entry (receivable: the customer record, payable: the supplier record).
 */
final class MemberDefaults
{
    /** customer columns kept on the Customer Defaults tab (plus credit_limit and wht_ids) */
    public const CUSTOMER_IDS = ['terms_id', 'tax_code_id', 'payment_method_id', 'customer_type_id', 'sales_rep_id', 'billing_group_id'];
    /** supplier columns kept on the Vendor Defaults tab (plus credit_limit, account_no and wht_ids) */
    public const VENDOR_IDS = ['terms_id', 'default_expense_account_id', 'tax_code_id'];

    private static ?bool $ready = null;
    private static ?array $index = null;

    /** The member defaults are installed (upgrade 2026_12_27: the cash account of a user profile) */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                DB::value('SELECT COUNT(coop_cash_account_id) FROM roles WHERE 1 = 0');
                self::$ready = true;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    public static function reset(): void
    {
        self::$index = null;
    }

    // ------------------------------------------------------------------ the member behind a record

    /**
     * Who stands behind the records the name pickers know:
     * ['m' => [member id => row], 'c' => [customer id => member id], 'v' => [vendor id => member id], 'o' => [other name id => member id]]
     * Only the member's own customer (not its jobs) and supplier records, and its member name (not an employee record).
     */
    public static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }
        $out = ['m' => [], 'c' => [], 'v' => [], 'o' => []];
        try {
            foreach (DB::all('SELECT m.id, m.member_no, m.full_name, m.status, m.party_id, m.other_name_id, t.name AS type_name
                                FROM members m LEFT JOIN member_types t ON t.id = m.member_type_id ORDER BY m.full_name, m.id') as $r) {
                $out['m'][(int)$r['id']] = $r;
                if ($r['other_name_id']) {
                    $out['o'][(int)$r['other_name_id']] = (int)$r['id'];
                }
            }
            foreach (DB::all("SELECT id, member_id FROM other_names WHERE member_id IS NOT NULL AND name_type <> 'employee'") as $r) {
                $out['o'][(int)$r['id']] ??= (int)$r['member_id'];
            }
            if (Party::ready()) {
                foreach (DB::all('SELECT c.id, m.id AS mid FROM customers c JOIN members m ON m.party_id = c.party_id WHERE c.parent_id IS NULL ORDER BY m.id') as $r) {
                    $out['c'][(int)$r['id']] ??= (int)$r['mid'];
                }
                foreach (DB::all('SELECT v.id, m.id AS mid FROM vendors v JOIN members m ON m.party_id = v.party_id ORDER BY m.id') as $r) {
                    $out['v'][(int)$r['id']] ??= (int)$r['mid'];
                }
            }
        } catch (Throwable $e) {
            // the cooperative tables are not installed
        }
        return self::$index = $out;
    }

    /** The member a customer, supplier or other-name record belongs to (null: not a member's own record) */
    public static function memberOf(string $kind, int $id): ?int
    {
        return self::index()[$kind][$id] ?? null;
    }

    /** "Regular Member: Santos, Maria" as the name pickers show a member */
    public static function label(int $memberId): string
    {
        $m = self::index()['m'][$memberId] ?? null;
        return $m ? Members::kindLabel($m['type_name'], $m['status']) . ': ' . $m['full_name'] : '';
    }

    /** The line the Customer Center and Vendor Center show on a member's own record ($tab customer | vendor) */
    public static function centerNote(int $memberId, string $tab): string
    {
        $m = self::index()['m'][$memberId] ?? null;
        if (!$m) {
            return '';
        }
        $name = h($m['full_name'] . ' (' . $m['member_no'] . ')');
        $link = Access::page('coop.member') ? '<a href="' . h(url('coop.member', ['id' => $memberId, 'dtab' => $tab])) . '">' . $name . '</a>' : $name;
        return '<div class="small text-muted member-note">' . h(Members::kindLabel($m['type_name'], $m['status'])) . ' ' . $link
            . '. The ' . ($tab === 'vendor' ? 'supplier' : 'customer') . ' details are kept on the member\'s ' . ($tab === 'vendor' ? 'Vendor' : 'Customer') . ' Defaults tab; the name and contact details follow the member record.</div>';
    }

    // ------------------------------------------------------------------ the member's own records

    public static function customerOf(array $m): ?array
    {
        if (!Party::ready() || empty($m['party_id'])) {
            return null;
        }
        return DB::row('SELECT * FROM customers WHERE party_id = :p AND parent_id IS NULL ORDER BY is_active DESC, id LIMIT 1', ['p' => $m['party_id']]);
    }

    public static function vendorOf(array $m): ?array
    {
        if (!Party::ready() || empty($m['party_id'])) {
            return null;
        }
        return DB::row('SELECT * FROM vendors WHERE party_id = :p ORDER BY is_active DESC, id LIMIT 1', ['p' => $m['party_id']]);
    }

    /** The member's customer record, made now when there is none (or switched on again when it was switched off) */
    public static function ensureCustomer(int $memberId): int
    {
        $m = Coop::member($memberId) ?? fail('Member not found.');
        if (empty($m['party_id'])) {
            Party::syncMember($memberId);
            $m = Coop::member($memberId);
        }
        $c = self::customerOf($m);
        if ($c && (int)$c['is_active'] === 1) {
            return (int)$c['id'];
        }
        if ($c) {
            Party::restore('customer', (int)$c['id'], 'Member ' . $m['full_name'] . ' transacts as a customer', 'coop.member');
            self::reset();
            return (int)$c['id'];
        }
        Members::ensureCustomerRoles($memberId);
        $c = self::customerOf($m) ?? fail('Member ' . $m['full_name'] . ' has no customer record and one could not be made.');
        self::reset();
        return (int)$c['id'];
    }

    /** The member's supplier record, made now when there is none (or switched on again when it was switched off) */
    public static function ensureVendor(int $memberId): int
    {
        $m = Coop::member($memberId) ?? fail('Member not found.');
        if (empty($m['party_id'])) {
            Party::syncMember($memberId);
            $m = Coop::member($memberId);
        }
        $v = self::vendorOf($m);
        if ($v && (int)$v['is_active'] === 1) {
            return (int)$v['id'];
        }
        if ($v) {
            Party::restore('supplier', (int)$v['id'], 'Member ' . $m['full_name'] . ' transacts as a supplier', 'coop.member');
            self::reset();
            return (int)$v['id'];
        }
        Members::ensureVendorRoles($memberId);
        $v = self::vendorOf($m) ?? fail('Member ' . $m['full_name'] . ' has no supplier record and one could not be made.');
        self::reset();
        return (int)$v['id'];
    }

    // ------------------------------------------------------------------ one name: the records follow the member

    /**
     * The member's customer and supplier records carry the member's name, TIN and contact
     * details (called on every save of the member). A record with the same name as someone else's gets a number.
     */
    public static function syncRoles(int $memberId, string $pageKey = 'coop.member'): void
    {
        if (!Party::ready()) {
            return;
        }
        $m = Coop::member($memberId);
        if (!$m || empty($m['party_id'])) {
            return;
        }
        $name = mb_substr(trim((string)$m['full_name']), 0, 110);
        $addr = Members::address($m);
        $institution = ($m['member_kind'] ?? 'individual') !== 'individual';
        $why = 'Follows the member record of ' . $m['member_no'] . ' (one name for the member in every line of business)';
        if ($c = self::customerOf($m)) {
            $set = self::changes($c, [
                'name' => self::uniqueName('customers', $name, (int)$c['id'], 'parent_id IS NULL'),
                // a blank on the member does not erase what the record has
                'tin' => self::nn($m['tin']) ?? $c['tin'], 'mobile' => self::nn($m['mobile']) ?? $c['mobile'], 'email' => self::nn($m['email']) ?? $c['email'],
                'billing_address' => $addr !== '' ? $addr : $c['billing_address'],
                'company_name' => $institution ? mb_substr((string)$m['full_name'], 0, 120) : $c['company_name'],
            ]);
            if ($set) {
                DB::update('customers', $set, 'id = :id', ['id' => $c['id']]);
                Audit::log('edit', 'cust', 'customer', (int)$c['id'], (string)($set['name'] ?? $c['name']), ['page_key' => $pageKey, 'before' => $c, 'after' => array_merge($c, $set), 'reason' => $why]);
            }
        }
        if ($v = self::vendorOf($m)) {
            $set = self::changes($v, [
                'name' => self::uniqueName('vendors', $name, (int)$v['id']),
                'tin' => self::nn($m['tin']) ?? $v['tin'], 'phone' => self::nn($m['mobile']) ?? $v['phone'], 'email' => self::nn($m['email']) ?? $v['email'],
                'address' => $addr !== '' ? $addr : $v['address'],
                'company_name' => $institution ? mb_substr((string)$m['full_name'], 0, 120) : $v['company_name'],
            ]);
            if ($set) {
                DB::update('vendors', $set, 'id = :id', ['id' => $v['id']]);
                Audit::log('edit', 'vend', 'vendor', (int)$v['id'], (string)($set['name'] ?? $v['name']), ['page_key' => $pageKey, 'before' => $v, 'after' => array_merge($v, $set), 'reason' => $why]);
            }
        }
        self::reset();
        Lookup::reset();
        Names::reset();
    }

    // ------------------------------------------------------------------ Customer Defaults and Vendor Defaults

    /** The Customer Defaults tab as posted: ['terms_id' => .., 'credit_limit' => .., 'wht_ids' => '1,2' | null ...] */
    public static function postedCustomer(): array
    {
        $f = [];
        foreach (self::CUSTOMER_IDS as $k) {
            $f[$k] = pint('cust_' . $k);
        }
        $f['credit_limit'] = r2(pnum('cust_credit_limit'));
        $f['wht_ids'] = self::postedWht('cust_wht_ids');
        return $f;
    }

    public static function postedVendor(): array
    {
        $f = [];
        foreach (self::VENDOR_IDS as $k) {
            $f[$k] = pint('vend_' . $k);
        }
        $f['credit_limit'] = r2(pnum('vend_credit_limit'));
        $f['account_no'] = mb_substr(trim((string)p('vend_account_no')), 0, 255) ?: null;
        $f['wht_ids'] = self::postedWht('vend_wht_ids');
        return $f;
    }

    private static function postedWht(string $field): ?string
    {
        if (!Withholding::ready()) {
            return null;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST[$field] ?? [])), fn($i) => Withholding::get($i) !== null)));
        return $ids ? implode(',', $ids) : null;
    }

    /**
     * Writes the Customer Defaults on the member's customer record (made now when there is none). A change of the
     * credit limit by a user who may not approve on Customer Center needs a reason, as on the customer form.
     */
    public static function saveCustomer(int $memberId, array $f, string $reason, string $pageKey = 'coop.member'): void
    {
        $cid = self::ensureCustomer($memberId);
        $c = DB::row('SELECT * FROM customers WHERE id = :id', ['id' => $cid]);
        $set = [];
        foreach (self::CUSTOMER_IDS as $k) {
            if (array_key_exists($k, $f)) {
                $set[$k] = $f[$k] ? (int)$f[$k] : null;
            }
        }
        if (array_key_exists('credit_limit', $f)) {
            if ((float)$f['credit_limit'] < 0) {
                fail('The credit limit cannot be negative.');
            }
            $set['credit_limit'] = r2((float)$f['credit_limit']);
        }
        if (array_key_exists('wht_ids', $f) && Withholding::ready()) {
            $set['wht_ids'] = $f['wht_ids'];
        }
        $set = self::changes($c, $set);
        if (!$set) {
            return;
        }
        if (array_key_exists('credit_limit', $set) && !Access::can('cust.center', 'approve') && !Access::isAdmin() && trim($reason) === '') {
            fail('Enter a reason for changing the credit limit (Customer Defaults).');
        }
        DB::update('customers', $set, 'id = :id', ['id' => $cid]);
        Audit::log('edit', 'cust', 'customer', $cid, (string)$c['name'], ['page_key' => $pageKey, 'before' => $c, 'after' => array_merge($c, $set),
            'reason' => trim($reason) !== '' ? $reason : 'Customer Defaults of the member']);
        Lookup::reset();
    }

    /** Writes the Vendor Defaults on the member's supplier record (made now when there is none) */
    public static function saveVendor(int $memberId, array $f, string $pageKey = 'coop.member'): void
    {
        $vid = self::ensureVendor($memberId);
        $v = DB::row('SELECT * FROM vendors WHERE id = :id', ['id' => $vid]);
        $set = [];
        foreach (self::VENDOR_IDS as $k) {
            if (array_key_exists($k, $f)) {
                $set[$k] = $f[$k] ? (int)$f[$k] : null;
            }
        }
        if (!empty($set['default_expense_account_id']) && !DB::value('SELECT id FROM accounts WHERE id = :a', ['a' => $set['default_expense_account_id']])) {
            fail('Choose the default expense account from the chart of accounts.');
        }
        if (array_key_exists('credit_limit', $f)) {
            if ((float)$f['credit_limit'] < 0) {
                fail('The credit limit with the vendor cannot be negative.');
            }
            $set['credit_limit'] = r2((float)$f['credit_limit']);
        }
        if (array_key_exists('account_no', $f)) {
            $set['account_no'] = $f['account_no'];
        }
        if (array_key_exists('wht_ids', $f) && Withholding::ready()) {
            $set['wht_ids'] = $f['wht_ids'];
        }
        $set = self::changes($v, $set);
        if (!$set) {
            return;
        }
        DB::update('vendors', $set, 'id = :id', ['id' => $vid]);
        Audit::log('edit', 'vend', 'vendor', $vid, (string)$v['name'], ['page_key' => $pageKey, 'before' => $v, 'after' => array_merge($v, $set), 'reason' => 'Vendor Defaults of the member']);
        Lookup::reset();
    }

    // ------------------------------------------------------------------ helpers

    /** The fields of $new that differ from $old */
    private static function changes(array $old, array $new): array
    {
        $set = [];
        foreach ($new as $k => $v) {
            if (!array_key_exists($k, $old)) {
                continue;
            }
            $a = $old[$k];
            if (is_numeric($a) && is_numeric($v) && !is_string($v)) {
                if ((float)$a !== (float)$v) {
                    $set[$k] = $v;
                }
            } elseif ((string)($a ?? '') !== (string)($v ?? '')) {
                $set[$k] = $v;
            }
        }
        return $set;
    }

    private static function nn($v): ?string
    {
        $v = trim((string)($v ?? ''));
        return $v === '' ? null : $v;
    }

    /** $name, or "$name (2)" when another record already has it */
    private static function uniqueName(string $table, string $name, int $selfId, string $where = '1 = 1'): string
    {
        $base = mb_substr(trim($name), 0, 110);
        $try = $base;
        for ($n = 2; $n < 500; $n++) {
            if (!DB::value("SELECT id FROM $table WHERE name = :n AND id <> :id AND $where", ['n' => $try, 'id' => $selfId])) {
                return $try;
            }
            $try = $base . ' (' . $n . ')';
        }
        return $base . ' ' . bin2hex(random_bytes(2));
    }
}
