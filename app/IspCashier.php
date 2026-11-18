<?php
declare(strict_types=1);

/**
 * Cashier shifts of the subscriber collections: opening cash, the collections of the shift by payment method, the cash
 * count by denomination at closing, the over / short posted to the books, the supervisor's certification and the
 * remittance of the cash (a fund transfer to the bank or to Undeposited Funds). A cashier never erases a collection:
 * corrections are reversals approved by someone with the right.
 */
final class IspCashier
{
    public const STATUSES = ['open' => 'Open', 'closed' => 'Closed (counted)', 'certified' => 'Certified'];
    public const DENOMINATIONS = ['1000', '500', '200', '100', '50', '20', '10', '5', '1', '0.25'];

    public static function current(int $userId): ?array
    {
        return DB::row("SELECT * FROM isp_shifts WHERE user_id = :u AND status = 'open' ORDER BY id DESC LIMIT 1", ['u' => $userId]);
    }

    public static function open(int $userId, int $cashAccountId, float $opening, ?int $branchId): int
    {
        if (self::current($userId)) {
            fail('You already have an open shift. Close it before opening another.');
        }
        $a = DB::row('SELECT id, account_type FROM accounts WHERE id = :a AND is_active = 1', ['a' => $cashAccountId]);
        if (!$a || $a['account_type'] !== 'Bank') {
            fail('Choose the cash drawer account (a cash account).');
        }
        $id = DB::insert('isp_shifts', ['shift_no' => Seq::next('isp_shift'), 'user_id' => $userId, 'branch_id' => $branchId, 'cash_account_id' => $cashAccountId,
            'opened_at' => date('Y-m-d H:i:s'), 'opening_cash' => r2($opening), 'status' => 'open']);
        Audit::log('open', 'ispbill', 'isp_shift', $id, (string)DB::value('SELECT shift_no FROM isp_shifts WHERE id = :i', ['i' => $id]), ['amount' => $opening]);
        return $id;
    }

    /** The collections of a shift by channel: channel => [count, amount] (posted payments only) */
    public static function totals(int $shiftId): array
    {
        $out = [];
        foreach (DB::all("SELECT channel, COUNT(*) AS n, SUM(amount) AS amt FROM isp_payments WHERE shift_id = :s AND status = 'posted' GROUP BY channel", ['s' => $shiftId]) as $r) {
            $out[$r['channel']] = [(int)$r['n'], r2((float)$r['amt'])];
        }
        return $out;
    }

    public static function expectedCash(array $shift): float
    {
        $cash = (float)DB::value("SELECT COALESCE(SUM(amount), 0) FROM isp_payments WHERE shift_id = :s AND status = 'posted' AND channel = 'cash'", ['s' => $shift['id']]);
        return r2((float)$shift['opening_cash'] + $cash);
    }

    /** Closes the shift with the cash count: $denoms [face value => pieces] */
    public static function close(int $shiftId, array $denoms, string $notes = ''): array
    {
        return DB::tx(function () use ($shiftId, $denoms, $notes) {
            $s = DB::row('SELECT * FROM isp_shifts WHERE id = :id FOR UPDATE', ['id' => $shiftId]) ?? fail('Shift not found.');
            if ($s['status'] !== 'open') {
                fail('The shift is already closed.');
            }
            if ((int)$s['user_id'] !== (int)Auth::id() && !Access::can('ispbill.shifts', 'approve')) {
                fail('A shift is closed by its cashier (or a supervisor).');
            }
            $counted = 0.0;
            $parts = [];
            foreach (self::DENOMINATIONS as $d) {
                $n = max(0, (int)($denoms[$d] ?? 0));
                if ($n) {
                    $counted += (float)$d * $n;
                    $parts[] = $d . '=' . $n;
                }
            }
            $counted = r2($counted);
            $expected = self::expectedCash($s);
            $diff = r2($counted - $expected);
            $txn = IspPosting::postOverShort($shiftId, $diff, (int)$s['cash_account_id'], today(), (string)$s['shift_no']);
            DB::update('isp_shifts', ['closed_at' => date('Y-m-d H:i:s'), 'expected_cash' => $expected, 'counted_cash' => $counted, 'denominations' => implode(';', $parts),
                'over_short' => $diff, 'over_short_txn_id' => $txn, 'status' => 'closed', 'notes' => mb_substr($notes, 0, 255) ?: null], 'id = :id', ['id' => $shiftId]);
            Audit::log('close', 'ispbill', 'isp_shift', $shiftId, (string)$s['shift_no'], ['amount' => $counted, 'reason' => 'Expected ' . money($expected) . ', over/short ' . money($diff)]);
            return ['expected' => $expected, 'counted' => $counted, 'over_short' => $diff];
        });
    }

    /** The supervisor certifies the count (never the cashier of the shift) */
    public static function certify(int $shiftId, string $note): void
    {
        $s = DB::row('SELECT * FROM isp_shifts WHERE id = :id', ['id' => $shiftId]) ?? fail('Shift not found.');
        if ($s['status'] !== 'closed') {
            fail('Only a closed shift is certified.');
        }
        if ((int)$s['user_id'] === (int)Auth::id()) {
            fail('The cash count is certified by a supervisor, not by the cashier of the shift.');
        }
        DB::update('isp_shifts', ['status' => 'certified', 'certified_by' => Auth::id(), 'certified_at' => date('Y-m-d H:i:s'), 'notes' => trim(($s['notes'] ?? '') . ' ' . $note) ?: null], 'id = :id', ['id' => $shiftId]);
        Audit::log('certify', 'ispbill', 'isp_shift', $shiftId, (string)$s['shift_no'], ['reason' => $note]);
    }

    /** Remits the counted cash: a fund transfer from the drawer to a bank account or Undeposited Funds */
    public static function remit(int $shiftId, int $toAccount, float $amount, string $ref): int
    {
        return DB::tx(function () use ($shiftId, $toAccount, $amount, $ref) {
            $s = DB::row('SELECT * FROM isp_shifts WHERE id = :id FOR UPDATE', ['id' => $shiftId]) ?? fail('Shift not found.');
            if (!in_array($s['status'], ['closed', 'certified'], true)) {
                fail('Close the shift before remitting its cash.');
            }
            if ($s['remit_txn_id']) {
                fail('The cash of this shift was already remitted.');
            }
            $amount = r2($amount);
            if ($amount <= 0 || $amount > (float)$s['counted_cash'] + 0.004) {
                fail('Enter the amount remitted (at most the cash counted, ' . money($s['counted_cash']) . ').');
            }
            $own = IspPosting::owning();
            $txn = Txn::save('transfer', ['txn_date' => today(), 'account_id' => (int)$s['cash_account_id'], 'account2_id' => $toAccount, 'total' => $amount, 'status' => 'open',
                'memo' => 'Remittance of cashier shift ' . $s['shift_no'], 'ref_no' => $ref ?: $s['shift_no'], 'business_unit_id' => IspSetup::unitId()], [], null, ['keep_totals' => true, 'skip_policy' => true]);
            DB::update('isp_shifts', ['remitted_amount' => $amount, 'remit_txn_id' => $txn], 'id = :id', ['id' => $shiftId]);
            Audit::log('remit', 'ispbill', 'isp_shift', $shiftId, (string)$s['shift_no'], ['amount' => $amount]);
            return $txn;
        });
    }

    /** Cash accounts a cashier can open a shift on */
    public static function drawers(): array
    {
        return DB::pairs("SELECT id, CONCAT(code, ' - ', name) FROM accounts WHERE is_active = 1 AND account_type = 'Bank' AND (name LIKE '%Cash%' OR code LIKE '100%') ORDER BY code");
    }
}
