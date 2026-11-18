<?php
declare(strict_types=1);

/**
 * Scheduled jobs (cron/isp_worker.php runs them every few minutes). Each job:
 *  - is claimed by one process only (a row claim with an expiry, plus a MySQL named lock), so two cron runs that
 *    overlap never run it twice at the same time;
 *  - is safe to run again: every step it takes is idempotent (period keys, event keys, live keys, dedupe keys);
 *  - writes a run record (isp_job_runs) with its result or its error; a failure is retried at the next turn up to
 *    max_retries times in a row, and stays visible on ISP Setup > Scheduled Jobs Monitor.
 */
final class IspJobs
{
    /** A MySQL named lock (per company database); null when another process holds it */
    public static function lock(string $name, int $timeout = 0): ?string
    {
        $key = substr('gsc' . substr(md5(DB::databaseName()), 0, 8) . ':' . $name, 0, 64);
        $got = DB::value('SELECT GET_LOCK(:k, :t)', ['k' => $key, 't' => $timeout]);
        return (int)$got === 1 ? $key : null;
    }

    public static function unlock(?string $key): void
    {
        if ($key) {
            DB::value('SELECT RELEASE_LOCK(:k)', ['k' => $key]);
        }
    }

    /** code => fn(): [processed count, message] */
    private static function handlers(): array
    {
        return [
            'billing' => function () {
                $before = (int)DB::value('SELECT COUNT(*) FROM isp_invoices');
                $run = IspBilling::generate(today(), null, null, 'scheduler');
                $r = DB::row('SELECT * FROM isp_billing_runs WHERE id = :r', ['r' => $run]);
                $made = (int)DB::value('SELECT COUNT(*) FROM isp_invoices') - $before;
                if ((int)$r['invoice_count'] === 0) {
                    DB::update('isp_billing_runs', ['status' => 'cancelled', 'warnings' => 'Nothing due'], 'id = :id', ['id' => $run]);
                }
                return [$made, $r['run_no'] . ': ' . $made . ' invoice(s), ' . money($r['total_amount']) . ', ' . $r['status']];
            },
            'due_check' => fn() => [$n = IspBilling::markOverdue(), $n . ' invoice(s) checked past their due date'],
            'late_fees' => fn() => [$n = IspBilling::assessLateFees(), $n . ' late charge(s)'],
            'reminders' => fn() => self::reminders(),
            'disc_eval' => fn() => [$n = IspDisconnect::evaluate(), $n . ' account(s) queued for disconnection'],
            'disc_execute' => fn() => [$n = IspDisconnect::executeDue(), $n . ' disconnection(s) sent'],
            'reconnect' => function () {
                $n = 0;
                foreach (DB::column("SELECT id FROM isp_accounts WHERE service_status = 'disconnected'") as $id) {
                    if (IspReconnect::onPayment((int)$id, null)) {
                        $n++;
                    }
                }
                foreach (DB::column("SELECT id FROM isp_reconnections WHERE status = 'approved'") as $rid) {
                    IspReconnect::execute((int)$rid);
                }
                $n += IspPayments::runAutoDebits(0);
                return [$n, $n . ' reconnection(s) / auto-debit(s)'];
            },
            'net_ops' => fn() => [($r = IspNetwork::processQueue())['sent'] + $r['verified'], $r['sent'] . ' sent, ' . $r['verified'] . ' verified'],
            'net_reconcile' => function () {
                $d = IspNetwork::reconcile();
                Settings::set('isp_last_reconcile', json_encode(['at' => date('Y-m-d H:i:s'), 'rows' => array_slice($d, 0, 200)]));
                return [count($d), count($d) . ' account(s) differ from the expected state'];
            },
            'temp_recon' => fn() => [$n = IspReconnect::expireTemporary(), $n . ' temporary reconnection(s) expired'],
            'contracts' => fn() => [$n = IspContracts::notices(), $n . ' contract notice(s)'],
            'equipment' => function () {
                $due = (int)DB::value("SELECT COUNT(*) FROM isp_equipment WHERE status = 'for_recovery'");
                $nodes = (int)DB::value("SELECT COUNT(*) FROM isp_net_nodes WHERE status IN ('damaged', 'maintenance')");
                return [$due + $nodes, $due . ' equipment to recover, ' . $nodes . ' network node(s) damaged or under maintenance'];
            },
            'ticket_alerts' => fn() => [$n = IspTickets::escalate(), $n . ' ticket(s) escalated'],
            'daily_summary' => function () {
                $d = today();
                $billed = (float)DB::value("SELECT COALESCE(SUM(total), 0) FROM isp_invoices WHERE invoice_date = :d AND status NOT IN ('draft', 'approved', 'voided')", ['d' => $d]);
                $coll = (float)DB::value("SELECT COALESCE(SUM(amount), 0) FROM isp_payments WHERE pay_date = :d AND status = 'posted'", ['d' => $d]);
                $open = (float)DB::value("SELECT COALESCE(SUM(balance), 0) FROM isp_invoices WHERE status IN ('posted', 'partially_paid', 'overdue', 'disputed')");
                return [1, 'Billed ' . money($billed) . ', collected ' . money($coll) . ', receivables ' . money($open)];
            },
            'backup' => function () {
                $b = Backup::create('Scheduled backup (ISP job)');
                Backup::prune(30);
                return [1, 'Backup ' . basename((string)($b['file'] ?? $b['name'] ?? 'written'))];
            },
            'notify_retry' => fn() => [$n = IspNotify::deliver(), $n . ' message(s) processed'],
            'arrangements' => fn() => [$n = IspCredit::followUp(), $n . ' arrangement(s) defaulted'],
        ];
    }

    /** The due-date reminders (before) and the overdue reminders (after), once per invoice each */
    private static function reminders(): array
    {
        $n = 0;
        $before = IspSetup::int('isp_reminder_days');
        foreach (DB::all("SELECT * FROM isp_invoices WHERE status IN ('posted', 'partially_paid') AND balance > 0 AND due_date = :d", ['d' => IspPlans::addDays(today(), $before)]) as $i) {
            IspNotify::event('due_reminder', (int)$i['account_id'], ['amount' => money($i['balance']), 'due_date' => fdate($i['due_date']), 'invoice_no' => $i['invoice_no']], 'DUE-' . $i['id']);
            $n++;
        }
        $after = IspSetup::int('isp_overdue_reminder_days');
        foreach (DB::all("SELECT * FROM isp_invoices WHERE status = 'overdue' AND balance > 0 AND due_date <= :d", ['d' => IspPlans::addDays(today(), -$after)]) as $i) {
            IspNotify::event('payment_overdue', (int)$i['account_id'], ['amount' => money($i['balance']), 'due_date' => fdate($i['due_date']), 'invoice_no' => $i['invoice_no']], 'OVD-' . $i['id']);
            $n++;
        }
        return [$n, $n . ' reminder(s) queued'];
    }

    /** Is the job due now? */
    public static function isDue(array $j, ?int $now = null): bool
    {
        $now ??= time();
        if ((int)$j['is_enabled'] !== 1) {
            return false;
        }
        if ($j['locked_until'] && strtotime((string)$j['locked_until']) > $now) {
            return false;
        }
        if ($j['last_status'] === 'failed' && (int)$j['fail_count'] < (int)$j['max_retries']) {
            // a failed job is retried at the next turn (at most every 5 minutes)
            return !$j['last_finished_at'] || strtotime((string)$j['last_finished_at']) <= $now - 300;
        }
        if ($j['run_hour'] !== null && $j['run_hour'] !== '') {
            $todayAt = strtotime(date('Y-m-d', $now) . ' ' . sprintf('%02d:00:00', (int)$j['run_hour']));
            $last = $j['last_started_at'] ? strtotime((string)$j['last_started_at']) : 0;
            return $now >= $todayAt && $last < $todayAt;
        }
        return !$j['next_run_at'] || strtotime((string)$j['next_run_at']) <= $now;
    }

    /** Runs one job (claimed, logged, never twice at once). Returns the run record. */
    public static function run(string $code, string $trigger = 'cron'): array
    {
        $handlers = self::handlers();
        if (!isset($handlers[$code])) {
            fail('Unknown job ' . $code . '.');
        }
        $j = DB::row('SELECT * FROM isp_jobs WHERE job_code = :c', ['c' => $code]) ?? fail('Job ' . $code . ' is not set up.');
        $lock = self::lock('job_' . $code, 0);
        if (!$lock) {
            return ['status' => 'skipped', 'message' => 'Already running in another process'];
        }
        $token = bin2hex(random_bytes(8));
        $claimed = DB::run('UPDATE isp_jobs SET lock_token = :t, locked_until = :u, last_started_at = :s, last_status = \'running\' WHERE job_code = :c AND (locked_until IS NULL OR locked_until < :n)',
            ['t' => $token, 'u' => date('Y-m-d H:i:s', time() + 1800), 's' => date('Y-m-d H:i:s'), 'c' => $code, 'n' => date('Y-m-d H:i:s')])->rowCount();
        if (!$claimed) {
            self::unlock($lock);
            return ['status' => 'skipped', 'message' => 'Claimed by another process'];
        }
        $runId = DB::insert('isp_job_runs', ['job_code' => $code, 'started_at' => date('Y-m-d H:i:s'), 'status' => 'running', 'trigger_by' => $trigger]);
        $status = 'ok';
        $processed = 0;
        try {
            [$processed, $message] = IspSetup::asSystem($handlers[$code]);
        } catch (Throwable $e) {
            if (DB::inTransaction()) {
                DB::rollBack();
            }
            $status = 'failed';
            $message = $e instanceof UserError ? $e->getMessage() : get_class($e) . ': ' . $e->getMessage();
            error_log('ISP job ' . $code . ': ' . $e);
        } finally {
            self::unlock($lock);
        }
        $fails = $status === 'failed' ? (int)$j['fail_count'] + 1 : 0;
        DB::update('isp_job_runs', ['finished_at' => date('Y-m-d H:i:s'), 'status' => $status, 'processed' => (int)$processed, 'message' => mb_substr((string)$message, 0, 4000)], 'id = :id', ['id' => $runId]);
        DB::update('isp_jobs', ['lock_token' => null, 'locked_until' => null, 'last_finished_at' => date('Y-m-d H:i:s'), 'last_status' => $status, 'last_message' => mb_substr((string)$message, 0, 255),
            'fail_count' => $fails, 'next_run_at' => date('Y-m-d H:i:s', time() + max(1, (int)$j['interval_min']) * 60)], 'job_code = :c', ['c' => $code]);
        return ['status' => $status, 'message' => $message, 'processed' => $processed, 'run_id' => $runId];
    }

    /** cron: every job that is due (the order matters: bill, then check due dates, then the service control) */
    public static function runDue(string $trigger = 'cron'): array
    {
        $out = [];
        $order = array_keys(IspSetup::JOBS);
        $jobs = DB::all('SELECT * FROM isp_jobs');
        usort($jobs, fn($a, $b) => array_search($a['job_code'], $order, true) <=> array_search($b['job_code'], $order, true));
        foreach ($jobs as $j) {
            if (self::isDue($j)) {
                $out[$j['job_code']] = self::run((string)$j['job_code'], $trigger);
            }
        }
        return $out;
    }
}
