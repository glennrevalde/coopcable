<?php
declare(strict_types=1);

/**
 * Billing adjustments (credits, debits, write-offs, penalty waivers, service credits), billing disputes, payment
 * arrangements (extensions, installments, partial settlements) and disconnection exemptions.
 *
 * An adjustment is requested, approved by a user with the right (never the same user for a write-off) and posted:
 * a credit as a credit memo applied to the invoice, a debit as a charge on the next invoice. Nothing is deleted:
 * a write-off leaves the original receivable and invoice on file, credited.
 */
final class IspCredit
{
    public const ADJ_TYPES = ['credit' => 'Credit adjustment', 'debit' => 'Debit adjustment', 'write_off' => 'Write-off', 'outage_credit' => 'Outage service credit',
        'penalty_waiver' => 'Penalty waiver', 'dispute_credit' => 'Dispute credit', 'deposit' => 'Security deposit applied'];
    public const ADJ_STATUSES = ['pending' => 'Waiting for approval', 'approved' => 'Approved', 'posted' => 'Posted', 'rejected' => 'Rejected'];
    public const DISPUTE_STATUSES = ['open' => 'Open', 'investigating' => 'Investigating', 'resolved_credit' => 'Resolved with credit', 'resolved_no_credit' => 'Resolved, no credit', 'withdrawn' => 'Withdrawn'];
    public const ARR_TYPES = ['extension' => 'Payment extension', 'installment' => 'Installment arrangement', 'partial_settlement' => 'Partial settlement'];
    public const ARR_STATUSES = ['pending' => 'Waiting for approval', 'active' => 'Active', 'completed' => 'Completed', 'defaulted' => 'Defaulted', 'cancelled' => 'Cancelled', 'rejected' => 'Rejected'];

    /** the account each credit type debits */
    private static function creditAccount(string $type): int
    {
        return Accts::get(match ($type) {
            'write_off' => 'isp_bad_debts',
            'penalty_waiver' => 'isp_late_fee_revenue',
            'deposit' => 'isp_deposits',
            default => 'isp_service_credits',
        });
    }

    // ------------------------------------------------------------------ adjustments

    /** Records an adjustment request; $auto (system: deposit, termination credit) approves and posts it at once */
    public static function request(int $accountId, ?int $invoiceId, string $type, float $amount, string $reason, string $sourceType = 'manual', ?int $sourceId = null, bool $auto = false): int
    {
        if (!isset(self::ADJ_TYPES[$type])) {
            fail('Choose the kind of adjustment.');
        }
        $amount = r2($amount);
        if ($amount <= 0) {
            fail('Enter the amount of the adjustment.');
        }
        if (trim($reason) === '') {
            fail('Enter the reason for the adjustment.');
        }
        return DB::tx(function () use ($accountId, $invoiceId, $type, $amount, $reason, $sourceType, $sourceId, $auto) {
            IspAccounts::get($accountId, true) ?? fail('Account not found.');
            if ($type !== 'debit') {
                if (!$invoiceId) {
                    fail('Choose the invoice the credit is applied to.');
                }
                $inv = DB::row('SELECT * FROM isp_invoices WHERE id = :i AND account_id = :a', ['i' => $invoiceId, 'a' => $accountId]) ?? fail('The invoice is not of this account.');
                $pending = (float)DB::value("SELECT COALESCE(SUM(amount), 0) FROM isp_adjustments WHERE invoice_id = :i AND status IN ('pending', 'approved') AND adj_type <> 'debit'", ['i' => $invoiceId]);
                if ($amount > (float)$inv['balance'] - $pending + 0.004) {
                    fail('The credit (' . money($amount) . ') is more than what is open on invoice ' . $inv['invoice_no'] . ' (' . money(max(0, (float)$inv['balance'] - $pending)) . ', after the credits already waiting).');
                }
            }
            $id = DB::insert('isp_adjustments', ['adj_no' => Seq::next('isp_adjustment'), 'account_id' => $accountId, 'invoice_id' => $invoiceId, 'adj_type' => $type, 'amount' => $amount,
                'reason' => mb_substr($reason, 0, 255), 'status' => 'pending', 'requested_by' => Auth::id(), 'source_type' => $sourceType, 'source_id' => $sourceId]);
            IspAccounts::event($accountId, 'adjustment', self::ADJ_TYPES[$type] . ' requested: ' . money($amount) . ' (' . $reason . ')', 'adjustment', $id, $amount);
            Audit::log('create', 'ispbill', 'isp_adjustment', $id, (string)DB::value('SELECT adj_no FROM isp_adjustments WHERE id = :i', ['i' => $id]), ['amount' => $amount, 'reason' => $reason]);
            if ($auto) {
                self::decide($id, true, 'Applied by the system', true);
            }
            return $id;
        });
    }

    public static function decide(int $adjId, bool $approve, string $note, bool $system = false): void
    {
        DB::tx(function () use ($adjId, $approve, $note, $system) {
            $adj = DB::row('SELECT * FROM isp_adjustments WHERE id = :id FOR UPDATE', ['id' => $adjId]) ?? fail('Adjustment not found.');
            if ($adj['status'] !== 'pending') {
                fail('Adjustment ' . $adj['adj_no'] . ' was already decided.');
            }
            if (!$system) {
                if (!Access::special('isp_write_off')) {
                    fail('Approving adjustments needs the permission "Cable & Internet: can approve billing adjustments, write-offs and penalty waivers".');
                }
                if ((int)$adj['requested_by'] === (int)Auth::id() && in_array($adj['adj_type'], ['write_off'], true) && !Access::isAdmin()) {
                    fail('A write-off is approved by someone other than the user who asked for it.');
                }
                $limit = Access::limit('isp_max_adjustment');
                if ($approve && $limit !== null && (float)$adj['amount'] > $limit && !Access::isAdmin()) {
                    fail('The adjustment (' . money($adj['amount']) . ') is above your approval limit of ' . money($limit) . '.');
                }
            }
            if (!$approve) {
                if (trim($note) === '') {
                    fail('Enter why the adjustment is rejected.');
                }
                DB::update('isp_adjustments', ['status' => 'rejected', 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s'), 'rejected_reason' => mb_substr($note, 0, 255)], 'id = :id', ['id' => $adjId]);
                IspAccounts::event((int)$adj['account_id'], 'adjustment', 'Adjustment ' . $adj['adj_no'] . ' rejected: ' . $note, 'adjustment', $adjId);
                Audit::log('reject', 'ispbill', 'isp_adjustment', $adjId, (string)$adj['adj_no'], ['amount' => $adj['amount'], 'reason' => $note]);
                return;
            }
            DB::update('isp_adjustments', ['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $adjId]);
            self::post($adjId);
            Audit::log('approve', 'ispbill', 'isp_adjustment', $adjId, (string)$adj['adj_no'], ['amount' => $adj['amount'], 'reason' => $note]);
        });
        $accountId = (int)DB::value('SELECT account_id FROM isp_adjustments WHERE id = :i', ['i' => $adjId]);
        if ($approve && $accountId) {
            // a credit can settle the arrears: the account may qualify for reconnection
            IspReconnect::onPayment($accountId, null);
        }
    }

    private static function post(int $adjId): void
    {
        $adj = DB::row('SELECT * FROM isp_adjustments WHERE id = :id', ['id' => $adjId]);
        $a = IspAccounts::get((int)$adj['account_id']);
        if ($adj['adj_type'] === 'debit') {
            $cid = IspBilling::addCharge((int)$adj['account_id'], 'debit_adjustment', 'Debit adjustment ' . $adj['adj_no'] . ': ' . $adj['reason'], (float)$adj['amount'], today(),
                'ADJ-' . $adjId, 'inclusive', Accts::get('isp_other_revenue'), 'adjustment', $adjId);
            DB::update('isp_adjustments', ['status' => 'posted'], 'id = :id', ['id' => $adjId]);
            IspAccounts::event((int)$adj['account_id'], 'adjustment', 'Debit adjustment ' . $adj['adj_no'] . ' ' . money($adj['amount']) . ' added to the next invoice', 'adjustment', $adjId, (float)$adj['amount']);
            return;
        }
        $txn = IspPosting::postCredit('ADJ-' . $adjId, $a, (int)$adj['invoice_id'], [[self::creditAccount($adj['adj_type']), (float)$adj['amount'], self::ADJ_TYPES[$adj['adj_type']] . ': ' . $adj['reason']]],
            today(), (string)$adj['adj_no'], self::ADJ_TYPES[$adj['adj_type']] . ' ' . $adj['adj_no'] . ' on invoice ' . DB::value('SELECT invoice_no FROM isp_invoices WHERE id = :i', ['i' => $adj['invoice_id']]),
            'adjustment', (string)$adjId);
        DB::update('isp_adjustments', ['status' => 'posted', 'txn_id' => $txn], 'id = :id', ['id' => $adjId]);
        IspBilling::refresh((int)$adj['invoice_id']);
        IspAccounts::event((int)$adj['account_id'], 'adjustment', self::ADJ_TYPES[$adj['adj_type']] . ' ' . $adj['adj_no'] . ' ' . money($adj['amount']) . ' posted', 'adjustment', $adjId, -(float)$adj['amount']);
    }

    // ------------------------------------------------------------------ disputes

    public static function openDispute(int $accountId, ?int $invoiceId, float $amount, string $reason, bool $protect): int
    {
        if (trim($reason) === '') {
            fail('Enter what the subscriber disputes.');
        }
        return DB::tx(function () use ($accountId, $invoiceId, $amount, $reason, $protect) {
            if ($invoiceId && !DB::value('SELECT id FROM isp_invoices WHERE id = :i AND account_id = :a', ['i' => $invoiceId, 'a' => $accountId])) {
                fail('The invoice is not of this account.');
            }
            $id = DB::insert('isp_disputes', ['dispute_no' => Seq::next('isp_dispute'), 'account_id' => $accountId, 'invoice_id' => $invoiceId, 'amount_disputed' => r2($amount),
                'reason' => mb_substr($reason, 0, 255), 'status' => 'open', 'protect' => $protect ? 1 : 0, 'opened_by' => Auth::id()]);
            if ($invoiceId) {
                IspBilling::refresh($invoiceId);
            }
            IspAccounts::event($accountId, 'dispute', 'Billing dispute opened: ' . money($amount) . ' (' . $reason . ')' . ($protect ? ', protected from disconnection' : ''), 'dispute', $id);
            Audit::log('create', 'ispbill', 'isp_dispute', $id, (string)DB::value('SELECT dispute_no FROM isp_disputes WHERE id = :i', ['i' => $id]), ['reason' => $reason]);
            return $id;
        });
    }

    public static function noteDispute(int $id, string $note): void
    {
        $d = DB::row('SELECT * FROM isp_disputes WHERE id = :id', ['id' => $id]) ?? fail('Dispute not found.');
        if (!in_array($d['status'], ['open', 'investigating'], true)) {
            fail('The dispute is closed.');
        }
        DB::update('isp_disputes', ['status' => 'investigating', 'investigation' => trim(($d['investigation'] ?? '') . "\n" . date('Y-m-d H:i') . ' ' . (Auth::user()['username'] ?? '') . ': ' . $note)], 'id = :id', ['id' => $id]);
    }

    /** Resolves a dispute: with a credit (a dispute credit adjustment, approved as any other), without, or withdrawn */
    public static function resolveDispute(int $id, string $outcome, float $credit, string $resolution): void
    {
        DB::tx(function () use ($id, $outcome, $credit, $resolution) {
            $d = DB::row('SELECT * FROM isp_disputes WHERE id = :id FOR UPDATE', ['id' => $id]) ?? fail('Dispute not found.');
            if (!in_array($d['status'], ['open', 'investigating'], true)) {
                fail('The dispute is already resolved.');
            }
            if (!isset(self::DISPUTE_STATUSES[$outcome]) || in_array($outcome, ['open', 'investigating'], true)) {
                fail('Choose how the dispute is resolved.');
            }
            if (trim($resolution) === '') {
                fail('Enter the resolution (the subscriber is told).');
            }
            $adj = null;
            if ($outcome === 'resolved_credit') {
                $adj = self::request((int)$d['account_id'], $d['invoice_id'] ? (int)$d['invoice_id'] : null, 'dispute_credit', $credit, 'Dispute ' . $d['dispute_no'] . ': ' . $resolution, 'dispute', $id);
            }
            DB::update('isp_disputes', ['status' => $outcome, 'resolution' => mb_substr($resolution, 0, 255), 'adjustment_id' => $adj, 'resolved_by' => Auth::id(), 'resolved_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            if ($d['invoice_id']) {
                IspBilling::refresh((int)$d['invoice_id']);
            }
            IspAccounts::event((int)$d['account_id'], 'dispute', 'Dispute ' . $d['dispute_no'] . ' ' . strtolower(self::DISPUTE_STATUSES[$outcome]) . ': ' . $resolution, 'dispute', $id);
            Audit::log('resolve', 'ispbill', 'isp_dispute', $id, (string)$d['dispute_no'], ['reason' => $resolution]);
        });
    }

    // ------------------------------------------------------------------ arrangements

    /** $schedule: [[due_date, amount], ...] */
    public static function createArrangement(int $accountId, string $type, array $schedule, bool $protect, bool $waive, string $notes, ?string $consequence = null): int
    {
        if (!isset(self::ARR_TYPES[$type])) {
            fail('Choose the kind of arrangement.');
        }
        $schedule = array_values(array_filter($schedule, fn($s) => valid_date((string)$s[0]) && r2((float)$s[1]) > 0));
        if (!$schedule) {
            fail('Enter at least one due date and amount.');
        }
        usort($schedule, fn($x, $y) => strcmp($x[0], $y[0]));
        return DB::tx(function () use ($accountId, $type, $schedule, $protect, $waive, $notes, $consequence) {
            if (DB::value("SELECT id FROM isp_arrangements WHERE account_id = :a AND status IN ('pending', 'active')", ['a' => $accountId])) {
                fail('The account already has a payment arrangement in force or waiting for approval.');
            }
            $total = r2(array_sum(array_map(fn($s) => (float)$s[1], $schedule)));
            $id = DB::insert('isp_arrangements', ['arrangement_no' => Seq::next('isp_arrangement'), 'account_id' => $accountId, 'arr_type' => $type, 'total_amount' => $total, 'status' => 'pending',
                'protect' => $protect ? 1 : 0, 'waive_penalties' => $waive ? 1 : 0, 'expires_on' => end($schedule)[0], 'notes' => mb_substr($notes, 0, 255) ?: null, 'created_by' => Auth::id()]
                + ($consequence ? ['consequence' => mb_substr($consequence, 0, 255)] : []));
            foreach ($schedule as [$due, $amt]) {
                DB::insert('isp_arrangement_lines', ['arrangement_id' => $id, 'due_date' => $due, 'amount' => r2((float)$amt)]);
            }
            IspAccounts::event($accountId, 'arrangement', self::ARR_TYPES[$type] . ' recorded: ' . money($total) . ' in ' . count($schedule) . ' payment(s), waiting for approval', 'arrangement', $id);
            Audit::log('create', 'ispbill', 'isp_arrangement', $id, (string)DB::value('SELECT arrangement_no FROM isp_arrangements WHERE id = :i', ['i' => $id]), ['amount' => $total]);
            return $id;
        });
    }

    public static function decideArrangement(int $id, bool $approve, string $note): void
    {
        if (!Access::special('isp_exempt')) {
            fail('Approving payment arrangements needs the permission "Cable & Internet: can approve disconnection exemptions, payment arrangements and dispute protection".');
        }
        DB::tx(function () use ($id, $approve, $note) {
            $r = DB::row('SELECT * FROM isp_arrangements WHERE id = :id FOR UPDATE', ['id' => $id]) ?? fail('Arrangement not found.');
            if ($r['status'] !== 'pending') {
                fail('The arrangement was already decided.');
            }
            DB::update('isp_arrangements', ['status' => $approve ? 'active' : 'rejected', 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s'),
                'notes' => trim(($r['notes'] ?? '') . ($note !== '' ? ' / ' . $note : ''))], 'id = :id', ['id' => $id]);
            if ($approve) {
                // a waiting disconnection of the account is cancelled while the arrangement is kept
                DB::run("UPDATE isp_disc_queue SET status = 'cancelled', skip_reason = :r, live_key = NULL WHERE account_id = :a AND status IN ('candidate', 'approved', 'notified')",
                    ['r' => 'Payment arrangement ' . $r['arrangement_no'] . ' approved', 'a' => $r['account_id']]);
            }
            IspAccounts::event((int)$r['account_id'], 'arrangement', 'Arrangement ' . $r['arrangement_no'] . ($approve ? ' approved' : ' rejected') . ($note !== '' ? ': ' . $note : ''), 'arrangement', $id);
            Audit::log($approve ? 'approve' : 'reject', 'ispbill', 'isp_arrangement', $id, (string)$r['arrangement_no'], ['reason' => $note]);
        });
        if ($approve) {
            $acct = (int)DB::value('SELECT account_id FROM isp_arrangements WHERE id = :i', ['i' => $id]);
            IspReconnect::onPayment($acct, null);
        }
    }

    /** A payment is counted against the arrangement's lines in order */
    public static function onPayment(int $accountId, float $amount): void
    {
        $r = DB::row("SELECT * FROM isp_arrangements WHERE account_id = :a AND status = 'active'", ['a' => $accountId]);
        if (!$r) {
            return;
        }
        DB::tx(function () use ($r, $amount) {
            $left = $amount;
            foreach (DB::all("SELECT * FROM isp_arrangement_lines WHERE arrangement_id = :r AND status <> 'paid' ORDER BY due_date", ['r' => $r['id']]) as $l) {
                if ($left <= 0.004) {
                    break;
                }
                $need = r2((float)$l['amount'] - (float)$l['paid_amount']);
                $use = r2(min($need, $left));
                DB::update('isp_arrangement_lines', ['paid_amount' => r2((float)$l['paid_amount'] + $use), 'status' => $use + 0.004 >= $need ? 'paid' : $l['status']], 'id = :id', ['id' => $l['id']]);
                $left = r2($left - $use);
            }
            if (!DB::value("SELECT id FROM isp_arrangement_lines WHERE arrangement_id = :r AND status <> 'paid'", ['r' => $r['id']])) {
                DB::update('isp_arrangements', ['status' => 'completed'], 'id = :id', ['id' => $r['id']]);
                IspAccounts::event((int)$r['account_id'], 'arrangement', 'Arrangement ' . $r['arrangement_no'] . ' completed', 'arrangement', (int)$r['id']);
            }
        });
    }

    /** The follow-up job: a missed due date ends the arrangement (its protection with it) */
    public static function followUp(?string $asOf = null): int
    {
        $asOf ??= today();
        $n = 0;
        foreach (DB::all("SELECT l.*, r.account_id, r.arrangement_no FROM isp_arrangement_lines l JOIN isp_arrangements r ON r.id = l.arrangement_id
                           WHERE r.status = 'active' AND l.status = 'open' AND l.due_date < :d", ['d' => $asOf]) as $l) {
            DB::tx(function () use ($l, &$n) {
                DB::update('isp_arrangement_lines', ['status' => 'missed'], 'id = :id', ['id' => $l['id']]);
                DB::update('isp_arrangements', ['status' => 'defaulted'], 'id = :id', ['id' => $l['arrangement_id']]);
                IspAccounts::event((int)$l['account_id'], 'arrangement', 'Arrangement ' . $l['arrangement_no'] . ' defaulted: the payment due ' . fdate($l['due_date']) . ' was missed', 'arrangement', (int)$l['arrangement_id']);
                $n++;
            });
        }
        return $n;
    }

    // ------------------------------------------------------------------ exemptions and protection

    public static function grantExemption(int $accountId, string $from, string $until, string $reason): int
    {
        if (!Access::special('isp_exempt')) {
            fail('Exemptions need the permission "Cable & Internet: can approve disconnection exemptions".');
        }
        if (!valid_date($from) || !valid_date($until) || $until < $from) {
            fail('Enter the period of the exemption.');
        }
        if (trim($reason) === '') {
            fail('Enter the reason for the exemption.');
        }
        $id = DB::insert('isp_exemptions', ['account_id' => $accountId, 'reason' => mb_substr($reason, 0, 255), 'valid_from' => $from, 'valid_until' => $until, 'approved_by' => Auth::id()]);
        IspAccounts::event($accountId, 'exemption', 'Disconnection exemption ' . fdate($from) . ' to ' . fdate($until) . ': ' . $reason, 'exemption', $id);
        Audit::log('create', 'ispbill', 'isp_exemption', $id, (string)$accountId, ['reason' => $reason]);
        return $id;
    }

    public static function revokeExemption(int $id, string $reason): void
    {
        $e = DB::row('SELECT * FROM isp_exemptions WHERE id = :id', ['id' => $id]) ?? fail('Exemption not found.');
        DB::update('isp_exemptions', ['status' => 'revoked'], 'id = :id', ['id' => $id]);
        IspAccounts::event((int)$e['account_id'], 'exemption', 'Exemption revoked: ' . $reason, 'exemption', $id);
    }

    /** Why the account may not be disconnected now (null: nothing protects it). $rule decides which protections count. */
    public static function protection(int $accountId, ?array $rule = null, ?string $asOf = null): ?string
    {
        $asOf ??= today();
        $ex = DB::row("SELECT * FROM isp_exemptions WHERE account_id = :a AND status = 'active' AND valid_from <= :d AND valid_until >= :d2", ['a' => $accountId, 'd' => $asOf, 'd2' => $asOf]);
        if ($ex) {
            return 'Disconnection exemption until ' . fdate($ex['valid_until']) . ': ' . $ex['reason'];
        }
        if (!$rule || (int)$rule['protect_arrangements'] === 1) {
            $arr = DB::row("SELECT * FROM isp_arrangements WHERE account_id = :a AND status = 'active' AND protect = 1", ['a' => $accountId]);
            if ($arr) {
                return 'Payment arrangement ' . $arr['arrangement_no'] . ' in force';
            }
        }
        return null;
    }
}
