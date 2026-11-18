<?php
declare(strict_types=1);

/**
 * Collections: cash at the counter (inside a cashier shift), checks, bank deposits and transfers, GCash and Maya,
 * the online payment gateway (verified webhooks only), collection agents, and the savings auto-debit of members.
 *
 *  - A payment is allocated to the oldest open invoices first (or as the cashier chose); what is left is an advance
 *    payment kept as the subscriber's credit and applied to the next invoices when they post.
 *  - A reference typed by a customer (portal, screenshot of a transfer) is a PENDING payment: it changes nothing in
 *    the books and does not count for disconnection or reconnection until a collection officer verifies it.
 *  - The same bank / e-wallet reference is never posted twice (isp_payments.ref_dedupe is unique).
 *  - A posted payment is never deleted or edited: it is reversed with a reason by a user with the right, and the
 *    ledger, the invoices, the service eligibility and the audit trail follow.
 */
final class IspPayments
{
    public const CHANNELS = ['cash' => 'Cash', 'check' => 'Check', 'bank_deposit' => 'Bank deposit', 'bank_transfer' => 'Bank transfer (InstaPay / PESONet)', 'gcash' => 'GCash',
        'maya' => 'Maya', 'gateway' => 'Online payment gateway', 'agent' => 'Collection agent', 'auto_debit' => 'Savings auto-debit (members)', 'other' => 'Other'];
    public const STATUSES = ['pending' => 'Pending verification', 'posted' => 'Posted', 'rejected' => 'Rejected', 'reversed' => 'Reversed'];
    /** channels that need a reference number */
    public const NEED_REF = ['bank_deposit', 'bank_transfer', 'gcash', 'maya', 'gateway', 'check'];

    public static function get(int $id): ?array
    {
        return DB::row('SELECT p.*, a.account_no, a.account_name, a.isp_customer_id, u.full_name AS collector_name FROM isp_payments p JOIN isp_accounts a ON a.id = p.account_id
                         LEFT JOIN users u ON u.id = p.collector_user_id WHERE p.id = :id', ['id' => $id]);
    }

    /** The account a channel's money goes to (ISP Setup > Payment Channels can point a channel to another account) */
    public static function channelAccount(string $channel, ?int $shiftCash = null): int
    {
        $override = (int)Settings::get('isp_acct_' . $channel, '0');
        if ($override > 0) {
            return $override;
        }
        return self::defaultChannelAccount($channel, $shiftCash);
    }

    /** The account a channel's money goes to when ISP Setup does not point it elsewhere */
    public static function defaultChannelAccount(string $channel, ?int $shiftCash = null): int
    {
        return match ($channel) {
            'cash' => $shiftCash ?: Accts::get('isp_cash'),
            'check', 'other' => Accts::get('undeposited_funds'),
            'bank_deposit', 'bank_transfer', 'agent' => Accts::get('isp_bank_clearing'),
            'gcash', 'maya', 'gateway' => Accts::get('isp_ewallet_clearing'),
            'auto_debit' => Accts::get('isp_autodebit_clearing'),
            default => Accts::get('undeposited_funds'),
        };
    }

    /** The open invoices of an account, oldest due first */
    public static function openInvoices(int $accountId): array
    {
        return DB::all("SELECT * FROM isp_invoices WHERE account_id = :a AND status IN ('posted', 'partially_paid', 'overdue', 'disputed') AND balance > 0 ORDER BY due_date, invoice_date, id",
            ['a' => $accountId]);
    }

    /**
     * Records a payment. $o: pay_date, reference_no, check_no, check_bank, allocations [invoice_id => amount] (empty:
     * oldest first), source (counter / portal / gateway / field / auto_debit / import), status (posted / pending),
     * shift_id, gateway_event_id, gateway_fee, notes, sav_account_id (auto-debit), portal_user_id, collector_user_id,
     * cash_account_id. Returns the payment id.
     */
    public static function collect(int $accountId, float $amount, string $channel, array $o = []): int
    {
        $amount = r2($amount);
        if ($amount <= 0) {
            fail('Enter the amount received.');
        }
        if (!isset(self::CHANNELS[$channel])) {
            fail('Choose how the payment was made.');
        }
        $ref = trim((string)($o['reference_no'] ?? ''));
        if (in_array($channel, self::NEED_REF, true) && $ref === '' && trim((string)($o['check_no'] ?? '')) === '') {
            fail('Enter the reference number of the ' . self::CHANNELS[$channel] . ' payment.');
        }
        $status = ($o['status'] ?? 'posted') === 'pending' ? 'pending' : 'posted';
        $source = (string)($o['source'] ?? 'counter');
        $date = (string)($o['pay_date'] ?? today());
        if (!valid_date($date)) {
            fail('Enter the date of the payment.');
        }
        $id = DB::tx(function () use ($accountId, $amount, $channel, $o, $ref, $status, $source, $date) {
            $a = IspAccounts::get($accountId, true) ?? fail('Account not found.');
            if (in_array($a['service_status'], ['cancelled'], true)) {
                fail('Account ' . $a['account_no'] . ' was cancelled before activation; nothing can be collected on it.');
            }
            $dedupe = null;
            // the reference that identifies the money: the bank / e-wallet reference, or for a check its bank and number
            $checkNo = trim((string)($o['check_no'] ?? ''));
            $refKey = $ref !== '' ? $ref : ($channel === 'check' && $checkNo !== '' ? trim((string)($o['check_bank'] ?? '')) . '#' . $checkNo : '');
            if ($refKey !== '' && $channel !== 'cash') {
                $dedupe = mb_substr($channel . ':' . mb_strtoupper(preg_replace('/\s+/', '', $refKey)), 0, 120);
                $dup = DB::row('SELECT receipt_no, status FROM isp_payments WHERE ref_dedupe = :d', ['d' => $dedupe]);
                if ($dup) {
                    fail('The ' . self::CHANNELS[$channel] . ' reference ' . $ref . ' was already recorded on ' . $dup['receipt_no'] . ' (' . (self::STATUSES[$dup['status']] ?? $dup['status']) . '). A payment is never posted twice.');
                }
            }
            $shiftId = $o['shift_id'] ?? null;
            $cash = $o['cash_account_id'] ?? null;
            if ($status === 'posted' && $source === 'counter') {
                // what a cashier takes at the counter belongs to the open shift (cash is counted, checks are turned over)
                $shift = IspCashier::current((int)Auth::id());
                if ($channel === 'cash') {
                    if (!$shift) {
                        fail('Open your cashier shift first (Billing & Collection > Cashier Shifts & Cash Count): cash is counted at the end of the shift.');
                    }
                    $shiftId = (int)$shift['id'];
                    $cash = (int)$shift['cash_account_id'];
                } elseif ($shift && !$shiftId) {
                    $shiftId = (int)$shift['id'];
                }
            }
            if ($channel === 'auto_debit') {
                $sav = (int)($o['sav_account_id'] ?? $a['autodebit_sav_account_id'] ?? 0);
                if (!$sav || !isset(IspAccounts::savingsOptions((int)$a['isp_customer_id'])[$sav])) {
                    fail('Choose the member\'s savings account to debit.');
                }
                $o['sav_account_id'] = $sav;
            }
            $row = [
                'receipt_no' => Seq::next('isp_receipt'), 'account_id' => $accountId, 'pay_date' => $date, 'received_at' => date('Y-m-d H:i:s'), 'amount' => $amount,
                'channel' => $channel, 'source' => $source, 'reference_no' => $ref !== '' ? mb_substr($ref, 0, 80) : null, 'ref_dedupe' => $dedupe,
                'check_no' => ($o['check_no'] ?? '') ?: null, 'check_bank' => ($o['check_bank'] ?? '') ?: null, 'status' => 'pending', 'shift_id' => $shiftId,
                'collector_user_id' => $o['collector_user_id'] ?? Auth::id(), 'cash_account_id' => $cash ?: self::channelAccount($channel),
                'gateway_event_id' => $o['gateway_event_id'] ?? null, 'gateway_fee' => r2((float)($o['gateway_fee'] ?? 0)),
                'unapplied_amount' => 0, 'portal_user_id' => $o['portal_user_id'] ?? null, 'notes' => ($o['notes'] ?? '') ?: null, 'created_by' => Auth::id(),
            ];
            try {
                $id = DB::insert('isp_payments', $row);
            } catch (PDOException $e) {
                // two cashiers posting the same reference at the same moment: the unique reference refuses the second
                if ((int)($e->errorInfo[1] ?? 0) === 1062 && $dedupe !== null && str_contains($e->getMessage(), 'uq_isp_payments_ref')) {
                    fail('The ' . self::CHANNELS[$channel] . ' reference ' . $refKey . ' was just recorded by another user. A payment is never posted twice.');
                }
                throw $e;
            }
            IspAccounts::event($accountId, 'payment', ($status === 'pending' ? 'Payment reference submitted' : 'Payment received') . ': ' . money($amount) . ' by ' . self::CHANNELS[$channel] . ($ref !== '' ? ' ref. ' . $ref : ''), 'payment', $id, $amount);
            if ($status === 'posted') {
                self::postInternal($id, (array)($o['allocations'] ?? []), (int)($o['sav_account_id'] ?? 0));
            } else {
                IspNotify::event('payment_pending', $accountId, ['reference' => $ref, 'amount' => money($amount)], 'PEND-' . $id);
            }
            Audit::log('create', 'ispbill', 'isp_payment', $id, (string)DB::value('SELECT receipt_no FROM isp_payments WHERE id = :i', ['i' => $id]),
                ['amount' => $amount, 'page_key' => $o['page_key'] ?? 'ispbill.collect', 'reason' => self::CHANNELS[$channel] . ($status === 'pending' ? ' (pending verification)' : '')]);
            return $id;
        });
        if ($status === 'posted') {
            self::afterPosted($id);
        }
        return $id;
    }

    /** Allocates and posts a payment (inside the caller's transaction) */
    private static function postInternal(int $paymentId, array $wanted, int $savAccount = 0): void
    {
        $p = DB::row('SELECT * FROM isp_payments WHERE id = :id FOR UPDATE', ['id' => $paymentId]);
        $left = (float)$p['amount'];
        $open = self::openInvoices((int)$p['account_id']);
        $byId = [];
        foreach ($open as $inv) {
            $byId[(int)$inv['id']] = $inv;
        }
        $plan = [];
        $wanted = array_filter(array_map(fn($v) => r2(to_num($v)), $wanted), fn($v) => $v > 0);
        foreach ($wanted as $iid => $amt) {
            if (!isset($byId[(int)$iid])) {
                fail('An invoice chosen for the payment is not open on this account.');
            }
            if ($amt > (float)$byId[(int)$iid]['balance'] + 0.004) {
                fail('The amount applied to invoice ' . $byId[(int)$iid]['invoice_no'] . ' is more than its balance (' . money($byId[(int)$iid]['balance']) . ').');
            }
            $plan[(int)$iid] = $amt;
        }
        if (r2(array_sum($plan)) > $left + 0.004) {
            fail('The amounts applied (' . money(array_sum($plan)) . ') are more than the payment (' . money($left) . ').');
        }
        if (!$plan) {
            foreach ($open as $inv) {
                if ($left <= 0.004) {
                    break;
                }
                $use = r2(min($left, (float)$inv['balance']));
                $plan[(int)$inv['id']] = $use;
                $left = r2($left - $use);
            }
        }
        $applied = 0.0;
        foreach ($plan as $iid => $amt) {
            DB::insert('isp_payment_allocs', ['payment_id' => $paymentId, 'invoice_id' => $iid, 'amount' => $amt]);
            $applied = r2($applied + $amt);
        }
        if ($p['channel'] === 'auto_debit') {
            [, $savTxn] = IspPosting::postAutoDebit($paymentId, $savAccount, (float)$p['amount'], (string)$p['pay_date']);
            DB::run('UPDATE isp_payments SET sav_txn_id = :s WHERE id = :id', ['s' => $savTxn, 'id' => $paymentId]);
        }
        DB::update('isp_payments', ['status' => 'posted', 'applied_amount' => $applied, 'unapplied_amount' => r2((float)$p['amount'] - $applied),
            'verified_by' => $p['source'] === 'counter' ? null : Auth::id(), 'verified_at' => $p['source'] === 'counter' ? null : date('Y-m-d H:i:s')], 'id = :id', ['id' => $paymentId]);
        $txn = IspPosting::postPayment($paymentId);
        DB::run('UPDATE isp_payments SET txn_id = :t WHERE id = :id', ['t' => $txn, 'id' => $paymentId]);
        foreach (array_keys($plan) as $iid) {
            IspBilling::refresh((int)$iid);
        }
        // a paid initial invoice holds the security deposit
        DB::run("UPDATE isp_accounts a SET a.deposit_status = 'held' WHERE a.id = :a AND a.deposit_status = 'billed'
                   AND EXISTS (SELECT 1 FROM isp_invoices i JOIN isp_invoice_lines l ON l.invoice_id = i.id WHERE i.account_id = a.id AND l.line_type = 'deposit' AND i.status IN ('paid', 'adjusted'))",
            ['a' => $p['account_id']]);
    }

    /** What follows a posted payment (outside its transaction): arrangements, reconnection, commissions, the thank-you */
    private static function afterPosted(int $paymentId): void
    {
        $p = DB::row('SELECT * FROM isp_payments WHERE id = :id', ['id' => $paymentId]);
        $accountId = (int)$p['account_id'];
        IspCredit::onPayment($accountId, (float)$p['amount']);
        $bal = IspAccounts::balances($accountId);
        IspNotify::event('payment_received', $accountId, ['amount' => money($p['amount']), 'receipt_no' => $p['receipt_no'], 'balance' => money(max(0, $bal['balance']))], 'PAY-' . $paymentId);
        IspAgents::onFirstPayment($accountId, $paymentId);
        IspReconnect::onPayment($accountId, $paymentId);
    }

    /** A pending payment (portal reference, unverified transfer) is verified and posted, or rejected */
    public static function verify(int $paymentId, bool $approve, string $note = '', array $allocations = []): void
    {
        DB::tx(function () use ($paymentId, $approve, $note, $allocations) {
            $p = DB::row('SELECT * FROM isp_payments WHERE id = :id FOR UPDATE', ['id' => $paymentId]) ?? fail('Payment not found.');
            if ($p['status'] !== 'pending') {
                fail('Payment ' . $p['receipt_no'] . ' is not waiting for verification.');
            }
            if ($approve) {
                self::postInternal($paymentId, $allocations, (int)DB::value('SELECT autodebit_sav_account_id FROM isp_accounts WHERE id = :a', ['a' => $p['account_id']]));
                IspAccounts::event((int)$p['account_id'], 'payment', 'Payment ' . $p['receipt_no'] . ' verified and posted' . ($note !== '' ? ': ' . $note : ''), 'payment', $paymentId, (float)$p['amount']);
                Audit::log('verify', 'ispbill', 'isp_payment', $paymentId, (string)$p['receipt_no'], ['amount' => $p['amount'], 'reason' => $note]);
            } else {
                if (trim($note) === '') {
                    fail('Enter why the payment is rejected (the subscriber is told).');
                }
                DB::update('isp_payments', ['status' => 'rejected', 'rejected_reason' => mb_substr($note, 0, 255), 'verified_by' => Auth::id(), 'verified_at' => date('Y-m-d H:i:s'),
                    'ref_dedupe' => null], 'id = :id', ['id' => $paymentId]);
                IspAccounts::event((int)$p['account_id'], 'payment', 'Payment reference ' . $p['reference_no'] . ' rejected: ' . $note, 'payment', $paymentId);
                Audit::log('reject', 'ispbill', 'isp_payment', $paymentId, (string)$p['receipt_no'], ['amount' => $p['amount'], 'reason' => $note]);
            }
        });
        if ($approve) {
            self::afterPosted($paymentId);
        }
    }

    /**
     * Reverses a posted payment (Workflow I): the allocations, the ledger payment (with its fee and auto-debit), the
     * invoice balances and the service eligibility are recalculated; everything is in the audit trail.
     */
    public static function reverse(int $paymentId, string $reason, ?array $approver = null): void
    {
        if (trim($reason) === '') {
            fail('Enter the reason for the reversal.');
        }
        if (!Access::special('isp_reverse_payment') && !$approver) {
            fail('Reversing a collection needs the permission "Cable & Internet: can reverse a posted collection", or a supervisor\'s approval.');
        }
        $accountId = DB::tx(function () use ($paymentId, $reason, $approver) {
            $p = DB::row('SELECT * FROM isp_payments WHERE id = :id FOR UPDATE', ['id' => $paymentId]) ?? fail('Payment not found.');
            if ($p['status'] !== 'posted') {
                fail('Only a posted payment can be reversed.');
            }
            $shift = $p['shift_id'] ? DB::row('SELECT status FROM isp_shifts WHERE id = :s', ['s' => $p['shift_id']]) : null;
            if ($shift && $shift['status'] === 'certified') {
                // the cash of a certified shift was counted and turned over: the reversal is a refund of the money
                $reason .= ' (shift already certified: refund the subscriber from the cashier\'s funds)';
            }
            $invoices = array_map('intval', DB::column('SELECT invoice_id FROM isp_payment_allocs WHERE payment_id = :p AND is_reversed = 0', ['p' => $paymentId]));
            DB::run('UPDATE isp_payment_allocs SET is_reversed = 1 WHERE payment_id = :p', ['p' => $paymentId]);
            IspPosting::reversePayment($paymentId, $reason);
            DB::update('isp_payments', ['status' => 'reversed', 'reversed_by' => Auth::id(), 'reversed_at' => date('Y-m-d H:i:s'), 'reversal_reason' => mb_substr($reason, 0, 255),
                'reversal_approved_by' => $approver['id'] ?? null, 'unapplied_amount' => 0, 'ref_dedupe' => $p['ref_dedupe'] ? mb_substr($p['ref_dedupe'] . '#R' . $paymentId, 0, 120) : null], 'id = :id', ['id' => $paymentId]);
            foreach (array_unique($invoices) as $iid) {
                IspBilling::refresh($iid);
            }
            IspAccounts::event((int)$p['account_id'], 'payment', 'Payment ' . $p['receipt_no'] . ' ' . money($p['amount']) . ' reversed: ' . $reason, 'payment', $paymentId, -(float)$p['amount']);
            Audit::log('reverse', 'ispbill', 'isp_payment', $paymentId, (string)$p['receipt_no'], ['amount' => $p['amount'], 'reason' => $reason, 'approved_by' => $approver['username'] ?? null]);
            return (int)$p['account_id'];
        });
        // the account may again qualify for disconnection: evaluated now (the queue still needs its own approval)
        IspDisconnect::evaluateAccount($accountId);
    }

    /** Applies the account's unapplied payments (advance payments) to its open invoices, oldest first */
    public static function applyCredits(int $accountId): float
    {
        return DB::tx(function () use ($accountId) {
            $total = 0.0;
            $payments = DB::all("SELECT * FROM isp_payments WHERE account_id = :a AND status = 'posted' AND unapplied_amount > 0 ORDER BY pay_date, id FOR UPDATE", ['a' => $accountId]);
            foreach ($payments as $p) {
                $left = (float)$p['unapplied_amount'];
                foreach (self::openInvoices($accountId) as $inv) {
                    if ($left <= 0.004) {
                        break;
                    }
                    $use = r2(min($left, (float)$inv['balance']));
                    if ($use <= 0) {
                        continue;
                    }
                    IspPosting::applyCredit((int)$p['id'], (int)$inv['id'], $use);
                    DB::insert('isp_payment_allocs', ['payment_id' => $p['id'], 'invoice_id' => $inv['id'], 'amount' => $use]);
                    $left = r2($left - $use);
                    $total = r2($total + $use);
                    IspBilling::refresh((int)$inv['id']);
                    IspAccounts::event($accountId, 'payment', 'Advance payment ' . $p['receipt_no'] . ' applied to invoice ' . $inv['invoice_no'] . ': ' . money($use), 'payment', (int)$p['id'], $use);
                }
                DB::update('isp_payments', ['applied_amount' => r2((float)$p['amount'] - $left), 'unapplied_amount' => $left], 'id = :id', ['id' => $p['id']]);
            }
            return $total;
        });
    }

    /** Runs the savings auto-debit of every member account with an open balance due within $days */
    public static function runAutoDebits(int $days = 0): int
    {
        $n = 0;
        foreach (DB::all("SELECT a.id, a.autodebit_sav_account_id FROM isp_accounts a WHERE a.autodebit_sav_account_id IS NOT NULL AND a.service_status IN ('active', 'disconnected')") as $a) {
            $due = (float)DB::value("SELECT COALESCE(SUM(balance), 0) FROM isp_invoices WHERE account_id = :a AND status IN ('posted', 'partially_paid', 'overdue') AND due_date <= :d",
                ['a' => $a['id'], 'd' => IspPlans::addDays(today(), $days)]);
            $credit = IspAccounts::balances((int)$a['id'])['credit'];
            $need = r2($due - $credit);
            if ($need <= 0.004) {
                continue;
            }
            $sav = Savings::account((int)$a['autodebit_sav_account_id']);
            if (!$sav || $sav['status'] !== 'active') {
                continue;
            }
            $avail = (float)Savings::available($sav);
            $take = r2(min($need, $avail));
            if ($take <= 0.004) {
                continue;
            }
            try {
                self::collect((int)$a['id'], $take, 'auto_debit', ['source' => 'auto_debit', 'sav_account_id' => (int)$a['autodebit_sav_account_id'],
                    'notes' => 'Automatic savings debit', 'page_key' => 'ispbill.payments']);
                $n++;
            } catch (UserError $e) {
                error_log('Auto-debit of account ' . $a['id'] . ': ' . $e->getMessage());
            }
        }
        return $n;
    }
}
