<?php
declare(strict_types=1);

/**
 * The disconnection engine (Workflow C): rules, eligibility, the queue, approval, the final notice, execution through
 * the network layer and the verification of the result.
 *
 *   candidate -> approved -> notified (final notice sent) -> executing -> disconnected
 *                                                                     \-> failed (retried / reported)
 *   skipped / cancelled: the reason went away (a verified payment, an arrangement, an exemption, a dispute)
 *
 * Safeguards: only verified (posted) payments count, never pending references; disputed invoices and accounts under an
 * approved arrangement or exemption are protected as the rule says; one live queue item per account (live_key);
 * eligibility is checked again under a lock of the account just before the command is sent (recheck) and again after
 * the device confirms (a payment that arrived during the command is reconnected at once).
 */
final class IspDisconnect
{
    public const REASON_TYPES = ['overdue' => 'Overdue payment', 'contract_end' => 'Contract ended', 'customer_request' => 'Customer-requested suspension', 'fraud' => 'Fraud / unauthorized connection',
        'technical' => 'Technical disconnection', 'administrative' => 'Administrative disconnection', 'cancellation' => 'Permanent cancellation'];
    public const STATUSES = ['candidate' => 'Candidate (waiting for approval)', 'approved' => 'Approved', 'notified' => 'Final notice sent', 'executing' => 'Executing',
        'disconnected' => 'Disconnected (confirmed)', 'failed' => 'Failed', 'skipped' => 'Skipped (no longer eligible)', 'cancelled' => 'Cancelled'];
    /** reasons that need a person to reconnect (never automatic after a payment) */
    public const BLOCKING = ['fraud', 'technical', 'administrative', 'cancellation'];

    public static function rule(string $type): ?array
    {
        return DB::row('SELECT * FROM isp_disc_rules WHERE rule_type = :t AND is_active = 1 ORDER BY id LIMIT 1', ['t' => $type]);
    }

    /**
     * The overdue arrears that qualify an account for disconnection under a rule:
     * ['amount' => qualifying arrears less the subscriber's unapplied credit, 'invoices' => count, 'oldest_due' => date,
     *  'disputed' => amount left out because it is disputed, 'protected' => reason or null]
     */
    public static function arrears(int $accountId, array $rule, ?string $asOf = null): array
    {
        $asOf ??= today();
        $cutoff = IspPlans::addDays($asOf, -max(0, (int)$rule['grace_days']));
        $excluded = array_filter(array_map('trim', explode(',', (string)$rule['exclude_types'])));
        $amount = 0.0;
        $count = 0;
        $oldest = null;
        $disputed = 0.0;
        foreach (DB::all("SELECT * FROM isp_invoices WHERE account_id = :a AND status IN ('posted', 'partially_paid', 'overdue', 'disputed') AND balance > 0 AND due_date < :c ORDER BY due_date",
                         ['a' => $accountId, 'c' => $cutoff]) as $inv) {
            if ((int)$rule['protect_disputes'] === 1 && DB::value("SELECT id FROM isp_disputes WHERE invoice_id = :i AND status IN ('open', 'investigating') AND protect = 1", ['i' => $inv['id']])) {
                $disputed += (float)$inv['balance'];
                continue;
            }
            $bal = (float)$inv['balance'];
            if ($excluded) {
                // the excluded kinds of charges (a security deposit) are not arrears: taken off what is unpaid
                [$in, $p] = DB::in('x', $excluded);
                $ex = (float)DB::value("SELECT COALESCE(SUM(amount + vat_amount), 0) FROM isp_invoice_lines WHERE invoice_id = :i AND line_type IN $in", $p + ['i' => $inv['id']]);
                $bal = max(0, $bal - $ex);
            }
            if ($bal <= 0.004) {
                continue;
            }
            $amount += $bal;
            $count++;
            $oldest ??= $inv['due_date'];
        }
        $credit = (float)DB::value("SELECT COALESCE(SUM(unapplied_amount), 0) FROM isp_payments WHERE account_id = :a AND status = 'posted'", ['a' => $accountId]);
        return ['amount' => r2(max(0, $amount - $credit)), 'invoices' => $count, 'oldest_due' => $oldest, 'disputed' => r2($disputed),
            'protected' => IspCredit::protection($accountId, $rule, $asOf)];
    }

    /** Is the account eligible under the rule now? [bool, why-not or why, arrears] */
    public static function eligible(array $a, array $rule, ?string $asOf = null): array
    {
        if ($a['service_status'] !== 'active') {
            return [false, 'the account is ' . (IspAccounts::SERVICE_STATUSES[$a['service_status']] ?? $a['service_status']), null];
        }
        $ar = self::arrears((int)$a['id'], $rule, $asOf);
        if ($ar['protected']) {
            return [false, 'protected: ' . $ar['protected'], $ar];
        }
        if ($ar['invoices'] < max(1, (int)$rule['min_overdue_invoices']) || $ar['amount'] < max(0.01, (float)$rule['min_overdue_amount'])) {
            return [false, 'the qualifying arrears (' . money($ar['amount']) . ', ' . $ar['invoices'] . ' invoice(s)) are below the rule', $ar];
        }
        return [true, money($ar['amount']) . ' overdue since ' . fdate($ar['oldest_due']) . ' (' . $ar['invoices'] . ' invoice(s), grace ' . (int)$rule['grace_days'] . ' days)', $ar];
    }

    /** The scheduler's evaluation: candidates for every active rule of overdue accounts. Returns how many were queued. */
    public static function evaluate(?string $asOf = null): int
    {
        $rule = self::rule('overdue');
        if (!$rule) {
            return 0;
        }
        $n = 0;
        foreach (DB::column("SELECT DISTINCT a.id FROM isp_accounts a JOIN isp_invoices i ON i.account_id = a.id
                              WHERE a.service_status = 'active' AND i.status IN ('posted', 'partially_paid', 'overdue') AND i.balance > 0 AND i.due_date < :d",
                            ['d' => IspPlans::addDays($asOf ?? today(), -max(0, (int)$rule['grace_days']))]) as $id) {
            if (self::evaluateAccount((int)$id, $asOf)) {
                $n++;
            }
        }
        // cancel live items whose account is no longer eligible (paid, arranged, exempted)
        foreach (DB::all("SELECT q.*, a.service_status FROM isp_disc_queue q JOIN isp_accounts a ON a.id = q.account_id WHERE q.status IN ('candidate', 'approved', 'notified') AND q.reason_type = 'overdue'") as $q) {
            $a = IspAccounts::get((int)$q['account_id']);
            [$ok, $why] = self::eligible($a, $rule, $asOf);
            if (!$ok) {
                self::skipped((int)$q['id'], ucfirst($why));
            }
        }
        // final notices of approved items
        self::sendNotices();
        return $n;
    }

    /** Queues one account when it is eligible (and has no live item). Returns the queue id or null. */
    public static function evaluateAccount(int $accountId, ?string $asOf = null): ?int
    {
        $rule = self::rule('overdue');
        if (!$rule) {
            return null;
        }
        return DB::tx(function () use ($accountId, $asOf, $rule) {
            $a = IspAccounts::get($accountId, true);
            if (!$a) {
                return null;
            }
            [$ok, $why, $ar] = self::eligible($a, $rule, $asOf);
            if (!$ok) {
                return null;
            }
            if (DB::value('SELECT id FROM isp_disc_queue WHERE live_key = :k', ['k' => (string)$accountId])) {
                return null;
            }
            $id = DB::insert('isp_disc_queue', ['queue_no' => Seq::next('isp_disc'), 'account_id' => $accountId, 'rule_id' => $rule['id'], 'reason_type' => 'overdue',
                'reason' => mb_substr('Nonpayment: ' . $why, 0, 255), 'eligible_amount' => $ar['amount'], 'oldest_due' => $ar['oldest_due'], 'live_key' => (string)$accountId,
                'status' => (int)$rule['require_approval'] === 1 ? 'candidate' : 'approved', 'initiated_by' => 'scheduler', 'created_by' => Auth::id()]);
            IspAccounts::event($accountId, 'disconnection', 'Queued for disconnection: ' . $why, 'disc_queue', $id, $ar['amount']);
            return $id;
        });
    }

    /** A disconnection asked by a person (fraud, technical, administrative, customer request, cancellation) */
    public static function queueManual(int $accountId, string $type, string $reason, bool $executeNow = false): int
    {
        if (!isset(self::REASON_TYPES[$type])) {
            fail('Choose the reason for the disconnection.');
        }
        if (trim($reason) === '') {
            fail('Enter the details of the reason.');
        }
        if (!Access::special('isp_disconnect')) {
            fail('Disconnecting subscribers needs the permission "Cable & Internet: can disconnect subscribers".');
        }
        $id = DB::tx(function () use ($accountId, $type, $reason) {
            $a = IspAccounts::get($accountId, true) ?? fail('Account not found.');
            if (!in_array($a['service_status'], ['active', 'suspended'], true)) {
                fail('Account ' . $a['account_no'] . ' is not connected (' . (IspAccounts::SERVICE_STATUSES[$a['service_status']] ?? $a['service_status']) . ').');
            }
            if ($live = DB::row('SELECT * FROM isp_disc_queue WHERE live_key = :k', ['k' => (string)$accountId])) {
                fail('Account ' . $a['account_no'] . ' is already in the disconnection queue (' . $live['queue_no'] . ').');
            }
            $rule = self::rule($type);
            $id = DB::insert('isp_disc_queue', ['queue_no' => Seq::next('isp_disc'), 'account_id' => $accountId, 'rule_id' => $rule['id'] ?? null, 'reason_type' => $type,
                'reason' => mb_substr(self::REASON_TYPES[$type] . ': ' . $reason, 0, 255), 'eligible_amount' => IspAccounts::balances($accountId)['overdue'], 'live_key' => (string)$accountId,
                'status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s'), 'initiated_by' => 'user:' . Auth::id(), 'created_by' => Auth::id()]);
            IspAccounts::event($accountId, 'disconnection', 'Disconnection requested: ' . self::REASON_TYPES[$type] . ' (' . $reason . ')', 'disc_queue', $id);
            Audit::log('create', 'ispnet', 'isp_disc_queue', $id, (string)$a['account_no'], ['reason' => $reason]);
            return $id;
        });
        if ($executeNow) {
            self::execute($id, true);
        }
        return $id;
    }

    /** Approves candidates (bulk, after the preview); the final notice goes out when the rule asks for one */
    public static function approve(array $ids): int
    {
        if (!Access::special('isp_disconnect')) {
            fail('Approving disconnections needs the permission "Cable & Internet: can disconnect subscribers".');
        }
        $n = 0;
        foreach ($ids as $id) {
            DB::tx(function () use ($id, &$n) {
                $q = DB::row('SELECT * FROM isp_disc_queue WHERE id = :id FOR UPDATE', ['id' => (int)$id]);
                if (!$q || $q['status'] !== 'candidate') {
                    return;
                }
                DB::update('isp_disc_queue', ['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
                Audit::log('approve', 'ispnet', 'isp_disc_queue', (int)$id, (string)$q['queue_no'], ['reason' => $q['reason']]);
                $n++;
            });
        }
        self::sendNotices();
        return $n;
    }

    /** Final notices: approved items get the warning and their execution time */
    public static function sendNotices(): int
    {
        $n = 0;
        foreach (DB::all("SELECT q.*, r.final_notice_hours FROM isp_disc_queue q LEFT JOIN isp_disc_rules r ON r.id = q.rule_id WHERE q.status = 'approved'") as $q) {
            $hours = (int)($q['final_notice_hours'] ?? 0);
            DB::tx(function () use ($q, $hours, &$n) {
                if ($hours > 0 && $q['reason_type'] === 'overdue') {
                    $when = date('Y-m-d H:i:s', time() + $hours * 3600);
                    IspNotify::event('final_notice', (int)$q['account_id'], ['amount' => money($q['eligible_amount']), 'date' => fdatetime($when)], 'FINAL-' . $q['id']);
                    DB::update('isp_disc_queue', ['status' => 'notified', 'final_notice_at' => date('Y-m-d H:i:s'), 'execute_after' => $when], 'id = :id', ['id' => $q['id']]);
                    IspAccounts::event((int)$q['account_id'], 'disconnection', 'Final disconnection notice sent; disconnection after ' . fdatetime($when), 'disc_queue', (int)$q['id']);
                } else {
                    DB::update('isp_disc_queue', ['status' => 'notified', 'execute_after' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $q['id']]);
                }
                $n++;
            });
        }
        return $n;
    }

    /** The scheduler's execution: notified items whose time came, inside their rule's days and hours */
    public static function executeDue(bool $ignoreWindow = false): int
    {
        $n = 0;
        foreach (DB::all("SELECT q.*, r.run_days, r.run_from_hour, r.run_to_hour, r.auto_execute, r.require_approval FROM isp_disc_queue q LEFT JOIN isp_disc_rules r ON r.id = q.rule_id
                           WHERE q.status = 'notified' AND (q.execute_after IS NULL OR q.execute_after <= :n)", ['n' => date('Y-m-d H:i:s')]) as $q) {
            if (!$ignoreWindow) {
                $days = array_map('intval', explode(',', (string)($q['run_days'] ?? '1,2,3,4,5')));
                $h = (int)date('G');
                if (!in_array((int)date('N'), $days, true) || $h < (int)($q['run_from_hour'] ?? 9) || $h >= (int)($q['run_to_hour'] ?? 16)) {
                    continue;
                }
                // the scheduler executes on its own only where the rule says so (or the item was approved by a person)
                if ((int)($q['auto_execute'] ?? 0) !== 1 && !$q['approved_by']) {
                    continue;
                }
            }
            self::execute((int)$q['id'], true);
            $n++;
        }
        return $n;
    }

    /** Sends the disconnection of one queue item (approved or notified) through the network layer */
    public static function execute(int $queueId, bool $system = false): string
    {
        $q = DB::tx(function () use ($queueId, $system) {
            $q = DB::row('SELECT * FROM isp_disc_queue WHERE id = :id FOR UPDATE', ['id' => $queueId]) ?? fail('Queue item not found.');
            if (!in_array($q['status'], ['approved', 'notified'], true)) {
                fail('Queue item ' . $q['queue_no'] . ' is ' . (self::STATUSES[$q['status']] ?? $q['status']) . '; only an approved item is executed.');
            }
            if (!$system && !Access::special('isp_disconnect')) {
                fail('Executing disconnections needs the permission "Cable & Internet: can disconnect subscribers".');
            }
            DB::update('isp_disc_queue', ['status' => 'executing', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $queueId]);
            return $q;
        });
        $op = $q['reason_type'] === 'customer_request' ? 'suspend' : 'disconnect';
        $opId = IspNetwork::request((int)$q['account_id'], $op, $q['reason'], 'disc_queue', $queueId, false);
        DB::run('UPDATE isp_disc_queue SET net_op_id = :o WHERE id = :id', ['o' => $opId, 'id' => $queueId]);
        return IspNetwork::execute($opId);
    }

    /** Called by the network layer just before the command: a reason to stop, or null to go ahead */
    public static function recheck(int $queueId): ?string
    {
        $q = DB::row('SELECT * FROM isp_disc_queue WHERE id = :id', ['id' => $queueId]);
        if (!$q || $q['status'] !== 'executing') {
            return 'the queue item is no longer waiting for execution';
        }
        $a = IspAccounts::get((int)$q['account_id'], true);
        if ($q['reason_type'] !== 'overdue') {
            return in_array($a['service_status'], ['active', 'suspended'], true) ? null : 'the account is ' . $a['service_status'];
        }
        $rule = $q['rule_id'] ? DB::row('SELECT * FROM isp_disc_rules WHERE id = :r', ['r' => $q['rule_id']]) : self::rule('overdue');
        [$ok, $why] = self::eligible($a, $rule);
        return $ok ? null : ucfirst($why) . ' (checked just before sending)';
    }

    public static function skipped(int $queueId, string $why): void
    {
        $q = DB::row('SELECT * FROM isp_disc_queue WHERE id = :id', ['id' => $queueId]);
        if (!$q || in_array($q['status'], ['disconnected', 'skipped', 'cancelled'], true)) {
            return;
        }
        DB::update('isp_disc_queue', ['status' => 'skipped', 'skip_reason' => mb_substr($why, 0, 255), 'live_key' => null, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $queueId]);
        IspAccounts::event((int)$q['account_id'], 'disconnection', 'Disconnection ' . $q['queue_no'] . ' not done: ' . $why, 'disc_queue', $queueId);
    }

    public static function cancel(int $queueId, string $reason): void
    {
        if (trim($reason) === '') {
            fail('Enter the reason.');
        }
        $q = DB::row('SELECT * FROM isp_disc_queue WHERE id = :id', ['id' => $queueId]) ?? fail('Queue item not found.');
        if (!in_array($q['status'], ['candidate', 'approved', 'notified', 'failed'], true)) {
            fail('Only a waiting or failed item is cancelled.');
        }
        DB::update('isp_disc_queue', ['status' => 'cancelled', 'skip_reason' => mb_substr($reason, 0, 255), 'live_key' => null, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $queueId]);
        if ($q['net_op_id']) {
            DB::run("UPDATE isp_net_ops SET state = 'cancelled' WHERE id = :o AND state IN ('queued', 'failed', 'manual')", ['o' => $q['net_op_id']]);
        }
        IspAccounts::event((int)$q['account_id'], 'disconnection', 'Disconnection ' . $q['queue_no'] . ' cancelled: ' . $reason, 'disc_queue', $queueId);
        Audit::log('cancel', 'ispnet', 'isp_disc_queue', $queueId, (string)$q['queue_no'], ['reason' => $reason]);
    }

    /** The device confirmed: the account is disconnected (and reconnected at once if it was paid in the meantime) */
    public static function confirmed(int $queueId, int $opId): void
    {
        $q = DB::row('SELECT * FROM isp_disc_queue WHERE id = :id', ['id' => $queueId]);
        if (!$q || $q['status'] === 'disconnected') {
            return;
        }
        DB::update('isp_disc_queue', ['status' => 'disconnected', 'executed_at' => date('Y-m-d H:i:s'), 'live_key' => null, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $queueId]);
        $a = IspAccounts::get((int)$q['account_id']);
        if ($q['reason_type'] === 'customer_request') {
            if ($a['service_status'] === 'active') {
                IspAccounts::setStatus((int)$a['id'], 'suspended', $q['reason']);
            }
        } elseif (in_array($a['service_status'], ['active', 'suspended'], true)) {
            IspAccounts::setStatus((int)$a['id'], 'disconnected', $q['reason'], ['disconnected_at' => date('Y-m-d H:i:s'), 'disconnect_reason' => mb_substr($q['reason'], 0, 255)]);
            IspNotify::event('service_disconnected', (int)$a['id'], ['reason' => self::REASON_TYPES[$q['reason_type']] ?? 'nonpayment',
                'amount' => money(IspAccounts::balances((int)$a['id'])['balance'])], 'DISC-' . $queueId);
        }
        // a payment posted while the command was on its way: restored at once
        if ($q['reason_type'] === 'overdue') {
            DB::afterCommit(fn() => IspReconnect::onPayment((int)$a['id'], null));
        }
    }

    public static function failed(int $queueId, string $message): void
    {
        $q = DB::row('SELECT * FROM isp_disc_queue WHERE id = :id', ['id' => $queueId]);
        if (!$q) {
            return;
        }
        DB::update('isp_disc_queue', ['status' => 'failed', 'skip_reason' => mb_substr($message, 0, 255), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $queueId]);
        IspAccounts::event((int)$q['account_id'], 'disconnection', 'Disconnection ' . $q['queue_no'] . ' FAILED (the account is still connected): ' . $message, 'disc_queue', $queueId);
    }
}
