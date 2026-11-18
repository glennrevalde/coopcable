<?php
declare(strict_types=1);

/** Internet plans and packages: prices, VAT, eligibility, promotions and the proration of partial months. */
final class IspPlans
{
    public const CATEGORIES = ['residential' => 'Residential', 'commercial' => 'Commercial / business', 'dedicated' => 'Dedicated connection', 'bundle' => 'Cable TV + internet bundle'];
    public const SERVICE_TYPES = ['postpaid' => 'Postpaid', 'prepaid' => 'Prepaid'];
    public const TECHNOLOGIES = ['fiber' => 'Fiber (FTTH)', 'cable' => 'Coaxial cable', 'wireless' => 'Fixed wireless'];
    public const VAT_MODES = ['inclusive' => 'Price includes VAT', 'exclusive' => 'VAT added to the price', 'exempt' => 'VAT-exempt', 'zero' => 'Zero-rated'];
    public const LATE_FEE_TYPES = ['none' => 'No late charge', 'fixed' => 'Fixed amount', 'percent' => 'Percent of the overdue amount'];
    public const STATUSES = ['active' => 'Available', 'inactive' => 'Not offered', 'retired' => 'Retired (existing subscribers only)'];

    private static array $cache = [];

    public static function get(int $id): ?array
    {
        return self::$cache[$id] ??= DB::row('SELECT * FROM isp_plans WHERE id = :id', ['id' => $id]);
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    /** id => "code name - price" of the plans offered (plus $keep) */
    public static function options(bool $offeredOnly = true, ?int $keep = null, ?int $areaId = null): array
    {
        $out = [];
        foreach (DB::all('SELECT * FROM isp_plans ORDER BY sort_order, monthly_fee, name') as $p) {
            if ($offeredOnly && (int)$p['id'] !== (int)$keep && !self::offered($p, today(), $areaId)) {
                continue;
            }
            $out[(int)$p['id']] = $p['name'] . ' (' . self::speed($p) . ') ' . money($p['monthly_fee']) . '/mo';
        }
        return $out;
    }

    public static function speed(array $p): string
    {
        $d = rtrim(rtrim(number_format((float)$p['download_mbps'], 2, '.', ''), '0'), '.');
        $u = rtrim(rtrim(number_format((float)$p['upload_mbps'], 2, '.', ''), '0'), '.');
        return $d . '/' . $u . ' Mbps';
    }

    /** Is the plan offered on this date (and in this area, when the plan is limited to some areas)? */
    public static function offered(array $p, string $date, ?int $areaId = null): bool
    {
        if ($p['status'] !== 'active') {
            return false;
        }
        if (!empty($p['available_from']) && $date < $p['available_from']) {
            return false;
        }
        if (!empty($p['available_to']) && $date > $p['available_to']) {
            return false;
        }
        if ($areaId) {
            return self::eligibleIn((int)$p['id'], $areaId);
        }
        return true;
    }

    /** A plan with no areas listed is offered everywhere; otherwise in the listed areas and the places inside them */
    public static function eligibleIn(int $planId, int $areaId): bool
    {
        $areas = array_map('intval', DB::column('SELECT area_id FROM isp_plan_areas WHERE plan_id = :p', ['p' => $planId]));
        if (!$areas) {
            return true;
        }
        $seen = 0;
        for ($id = $areaId; $id && $seen < 10; $seen++) {
            if (in_array($id, $areas, true)) {
                return true;
            }
            $id = (int)(DB::value('SELECT parent_id FROM isp_areas WHERE id = :id', ['id' => $id]) ?: 0);
        }
        return false;
    }

    /** The monthly fee the account pays: the member price for a cooperative member who has it */
    public static function priceFor(array $plan, array $account): float
    {
        if ((int)($account['member_price'] ?? 0) === 1 && $plan['member_monthly_fee'] !== null && (float)$plan['member_monthly_fee'] > 0) {
            return (float)$plan['member_monthly_fee'];
        }
        return (float)$plan['monthly_fee'];
    }

    /**
     * Net, VAT and gross of a gross price under a VAT mode (the company's VAT registration decides first).
     * Returns [net, vat, gross, tax_code_id].
     */
    public static function vatSplit(float $amount, string $mode): array
    {
        $amount = r2($amount);
        if (!IspSetup::vatRegistered()) {
            return [$amount, 0.0, $amount, self::taxCode('NT')];
        }
        $rate = IspSetup::vatRate() / 100;
        return match ($mode) {
            'exclusive' => [$amount, r2($amount * $rate), r2($amount + r2($amount * $rate)), self::vatCode()],
            'exempt' => [$amount, 0.0, $amount, self::taxCode('EX')],
            'zero' => [$amount, 0.0, $amount, self::taxCode('ZR')],
            default => [r2($amount / (1 + $rate)), r2($amount - r2($amount / (1 + $rate))), $amount, self::vatCode()],
        };
    }

    private static ?int $vatCode = null;

    public static function vatCode(): ?int
    {
        if (self::$vatCode === null) {
            $id = DB::value("SELECT id FROM tax_codes WHERE code = 'VAT12' AND is_active = 1")
                ?: DB::value('SELECT id FROM tax_codes WHERE rate = :r AND is_active = 1 ORDER BY id LIMIT 1', ['r' => IspSetup::float('isp_vat_rate')]);
            self::$vatCode = $id ? (int)$id : 0;
        }
        return self::$vatCode ?: null;
    }

    public static function taxCode(string $code): ?int
    {
        $id = DB::value('SELECT id FROM tax_codes WHERE code = :c', ['c' => $code]);
        return $id ? (int)$id : null;
    }

    /** Days between two dates, inclusive */
    public static function days(string $from, string $to): int
    {
        return (int)((strtotime($to) - strtotime($from)) / 86400) + 1;
    }

    /** The share of a month a partial period is (its days over the days of the month the period ends in) */
    public static function prorateFactor(string $from, string $to, string $periodStart, string $periodEnd): float
    {
        $full = self::days($periodStart, $periodEnd);
        $part = self::days(max($from, $periodStart), min($to, $periodEnd));
        if ($full <= 0 || $part <= 0) {
            return 0.0;
        }
        return min(1.0, $part / $full);
    }

    /** A date with its day of month clamped to the month (the 31st of February is the 28th / 29th) */
    public static function dayInMonth(int $year, int $month, int $day): string
    {
        $last = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
        return sprintf('%04d-%02d-%02d', $year, $month, min($day, $last));
    }

    public static function addMonths(string $date, int $months, ?int $anchorDay = null): string
    {
        $y = (int)substr($date, 0, 4);
        $m = (int)substr($date, 5, 2) + $months;
        $d = $anchorDay ?? (int)substr($date, 8, 2);
        while ($m > 12) {
            $m -= 12;
            $y++;
        }
        while ($m < 1) {
            $m += 12;
            $y--;
        }
        return self::dayInMonth($y, $m, $d);
    }

    public static function addDays(string $date, int $days): string
    {
        return date('Y-m-d', strtotime($date . ' ' . ($days >= 0 ? '+' : '') . $days . ' days'));
    }

    /**
     * The billing period that contains $date for a cycle day: [start, end]. A cycle day of 1 gives calendar months;
     * 15 gives the 15th to the 14th of the next month. Days past the end of a short month are clamped.
     */
    public static function periodOf(string $date, int $cycleDay): array
    {
        $cycleDay = max(1, min(28, $cycleDay));
        $y = (int)substr($date, 0, 4);
        $m = (int)substr($date, 5, 2);
        $start = self::dayInMonth($y, $m, $cycleDay);
        if ($date < $start) {
            $start = self::addMonths($start, -1, $cycleDay);
        }
        $end = self::addDays(self::addMonths($start, 1, $cycleDay), -1);
        return [$start, $end];
    }

    /** Promotional discount % for the n-th billed month (1 = the first) */
    public static function promoPct(array $plan, int $monthNo): float
    {
        if ((float)$plan['promo_discount_pct'] <= 0 || (int)$plan['promo_months'] <= 0 || $monthNo > (int)$plan['promo_months']) {
            return 0.0;
        }
        return (float)$plan['promo_discount_pct'];
    }

    /** The late charge of an overdue amount under the plan's policy */
    public static function lateFee(array $plan, float $overdue): float
    {
        return match ($plan['late_fee_type']) {
            'fixed' => r2((float)$plan['late_fee_value']),
            'percent' => r2($overdue * (float)$plan['late_fee_value'] / 100),
            default => 0.0,
        };
    }

    public static function save(array $f, ?int $id): int
    {
        foreach (['code', 'name'] as $k) {
            if (trim((string)($f[$k] ?? '')) === '') {
                fail('Enter the plan ' . $k . '.');
            }
        }
        if ((float)$f['monthly_fee'] < 0 || (float)$f['download_mbps'] <= 0) {
            fail('Enter the monthly fee and the download speed.');
        }
        if (!empty($f['available_from']) && !empty($f['available_to']) && $f['available_to'] < $f['available_from']) {
            fail('The availability ends before it starts.');
        }
        $dup = DB::value('SELECT id FROM isp_plans WHERE code = :c AND id <> :id', ['c' => $f['code'], 'id' => (int)$id]);
        if ($dup) {
            fail('Another plan already has the code ' . $f['code'] . '.');
        }
        $areas = $f['area_ids'] ?? [];
        unset($f['area_ids']);
        return DB::tx(function () use ($f, $id, $areas) {
            if ($id) {
                $before = self::get($id);
                // a price change never touches an issued invoice: the invoice lines keep the price they were billed at
                DB::update('isp_plans', $f + ['updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
                Audit::log('edit', 'ispset', 'isp_plan', $id, (string)$f['code'], ['page_key' => 'ispset.plans', 'before' => $before, 'after' => $f]);
            } else {
                $id = DB::insert('isp_plans', $f + ['created_by' => Auth::id()]);
                Audit::log('create', 'ispset', 'isp_plan', $id, (string)$f['code'], ['page_key' => 'ispset.plans', 'after' => $f]);
            }
            DB::run('DELETE FROM isp_plan_areas WHERE plan_id = :p', ['p' => $id]);
            foreach (array_unique(array_map('intval', (array)$areas)) as $a) {
                if ($a > 0) {
                    DB::insert('isp_plan_areas', ['plan_id' => $id, 'area_id' => $a]);
                }
            }
            self::flush();
            return $id;
        });
    }
}
