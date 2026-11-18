<?php
declare(strict_types=1);

/**
 * The network provisioning layer: one operation queue (isp_net_ops) between the business records and the devices.
 *
 *   requested -> queued -> sent -> confirmed            the device read back the state asked for
 *                               \-> failed               retried safely up to its limit, then reported (and a work order)
 *   manual                                               no device: a work order, confirmed when the technician completes it
 *   cancelled                                            the reason went away before the command was sent
 *
 *  - one live operation per account and intent (idem_key), and one command at a time per account (a database lock),
 *    so a scheduler that runs twice never sends a command twice;
 *  - a disconnection is checked again just before it is sent (IspDisconnect::recheck): a payment posted in the
 *    meantime cancels it;
 *  - the account's network state changes only on a confirmed operation; a paid account whose reconnection failed
 *    stays shown as not connected;
 *  - simulator answers are marked simulated everywhere.
 */
final class IspNetwork
{
    public const ADAPTERS = ['simulator' => 'IspAdapterSimulator', 'mikrotik' => 'IspAdapterMikrotik', 'radius' => 'IspAdapterRadius', 'olt_http' => 'IspAdapterOltHttp',
        'tr069' => 'IspAdapterTr069', 'manual' => 'IspAdapterManual'];
    public const ADAPTER_LABELS = ['mikrotik' => 'MikroTik RouterOS (API)', 'radius' => 'FreeRADIUS (SQL)', 'olt_http' => 'OLT / ONU platform (HTTP API)', 'tr069' => 'TR-069 ACS (GenieACS)',
        'manual' => 'Manual (work orders)', 'simulator' => 'Integration simulator (testing only)'];
    public const OP_TYPES = ['provision' => 'Provision', 'activate' => 'Activate', 'disconnect' => 'Disconnect', 'reconnect' => 'Reconnect', 'suspend' => 'Suspend',
        'change_plan' => 'Change plan profile', 'reset_session' => 'Reset session', 'status' => 'Read status', 'deprovision' => 'Remove from network'];
    public const STATES = ['requested' => 'Requested', 'queued' => 'Queued', 'sent' => 'Sent, waiting for confirmation', 'confirmed' => 'Confirmed by the device', 'failed' => 'Failed',
        'cancelled' => 'Cancelled', 'manual' => 'Manual work order'];
    /** the state each operation leaves the subscriber in */
    private const RESULT_STATE = ['provision' => 'enabled', 'activate' => 'enabled', 'reconnect' => 'enabled', 'change_plan' => 'enabled', 'disconnect' => 'disabled',
        'suspend' => 'disabled', 'deprovision' => 'not_provisioned'];

    public static function device(int $id): ?array
    {
        return DB::row('SELECT * FROM isp_devices WHERE id = :id', ['id' => $id]);
    }

    /** The default device of new accounts (null: no device, the manual flow) */
    public static function defaultDevice(): ?array
    {
        return DB::row('SELECT * FROM isp_devices WHERE is_active = 1 ORDER BY is_default DESC, id LIMIT 1');
    }

    public static function adapter(?array $device): IspNetAdapter
    {
        if (!$device || !(int)$device['is_active']) {
            return new IspAdapterManual(['options' => '', 'secret_enc' => null]);
        }
        $cls = self::ADAPTERS[$device['adapter']] ?? 'IspAdapterManual';
        return new $cls($device);
    }

    private static function accountDevice(array $a): ?array
    {
        return $a['device_id'] ? self::device((int)$a['device_id']) : self::defaultDevice();
    }

    /**
     * Asks for a network operation on an account (idempotent on the account, the operation and its source).
     * Returns the operation id. $now: send it at once (otherwise the scheduler sends it).
     */
    public static function request(int $accountId, string $op, string $reason, string $sourceType = 'manual', $sourceId = null, bool $now = true): int
    {
        if (!isset(self::OP_TYPES[$op])) {
            fail('Unknown network operation.');
        }
        $key = mb_substr($accountId . ':' . $op . ':' . $sourceType . ':' . (string)($sourceId ?? 'x'), 0, 80);
        $id = DB::tx(function () use ($accountId, $op, $reason, $sourceType, $sourceId, $key) {
            $have = DB::row('SELECT * FROM isp_net_ops WHERE idem_key = :k', ['k' => $key]);
            if ($have) {
                return (int)$have['id'];
            }
            $a = IspAccounts::get($accountId) ?? fail('Account not found.');
            $dev = self::accountDevice($a);
            $id = DB::insert('isp_net_ops', ['op_no' => Seq::next('isp_netop'), 'account_id' => $accountId, 'device_id' => $dev['id'] ?? null, 'op_type' => $op,
                'reason' => mb_substr($reason, 0, 255), 'source_type' => $sourceType, 'source_id' => $sourceId !== null ? (int)$sourceId : null, 'idem_key' => $key,
                'state' => 'queued', 'simulated' => ($dev['adapter'] ?? '') === 'simulator' ? 1 : 0, 'max_attempts' => 5, 'requested_by' => Auth::id(), 'next_try_at' => date('Y-m-d H:i:s')]);
            if (in_array($op, ['activate', 'provision', 'reconnect', 'change_plan'], true)) {
                DB::run("UPDATE isp_accounts SET network_state = 'provisioning' WHERE id = :a AND network_state IN ('not_provisioned', 'failed', 'unknown')", ['a' => $accountId]);
            }
            return $id;
        });
        if ($now) {
            self::execute($id);
        }
        return $id;
    }

    /** Sends one queued operation (one command at a time per account) */
    public static function execute(int $opId): string
    {
        $op = DB::row('SELECT * FROM isp_net_ops WHERE id = :id', ['id' => $opId]);
        if (!$op) {
            return 'missing';
        }
        $lock = IspJobs::lock('isp_netop_' . $op['account_id'], 20);
        if (!$lock) {
            return 'busy';
        }
        try {
            // claim it: only a queued operation whose time has come is sent, and only by one process
            $claimed = DB::tx(function () use ($opId) {
                $op = DB::row('SELECT * FROM isp_net_ops WHERE id = :id FOR UPDATE', ['id' => $opId]);
                if (!$op || $op['state'] !== 'queued') {
                    return null;
                }
                // just before a disconnection / reconnection is sent, its reason is checked again under the lock
                $stop = match ($op['source_type']) {
                    'disc_queue' => IspDisconnect::recheck((int)$op['source_id']),
                    'reconnection' => IspReconnect::recheck((int)$op['source_id']),
                    default => null,
                };
                if ($stop !== null) {
                    DB::update('isp_net_ops', ['state' => 'cancelled', 'error' => mb_substr($stop, 0, 255), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $opId]);
                    self::log($op, 'cancelled before sending: ' . $stop, '', true, false, 'info');
                    if ($op['source_type'] === 'disc_queue') {
                        IspDisconnect::skipped((int)$op['source_id'], $stop);
                    } elseif ($op['source_type'] === 'reconnection') {
                        IspReconnect::cancelled((int)$op['source_id'], $stop);
                    }
                    return null;
                }
                DB::update('isp_net_ops', ['state' => 'sent', 'attempts' => (int)$op['attempts'] + 1, 'sent_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $opId]);
                $op['attempts'] = (int)$op['attempts'] + 1;
                return $op;
            });
            if (!$claimed) {
                return 'skipped';
            }
            return self::send($claimed);
        } finally {
            IspJobs::unlock($lock);
        }
    }

    private static function send(array $op): string
    {
        $a = IspAccounts::get((int)$op['account_id']);
        $plan = IspPlans::get((int)$a['plan_id']);
        $dev = $op['device_id'] ? self::device((int)$op['device_id']) : null;
        $ad = self::adapter($dev);
        // a command that would repeat what the device already has is not sent again: the state is read instead
        if (in_array($op['op_type'], ['disconnect', 'suspend', 'reconnect', 'activate'], true) && $ad->supports('status') && !($ad instanceof IspAdapterManual)) {
            $want = self::RESULT_STATE[$op['op_type']];
            $now = $ad->status($a);
            self::writeLog($op, $now, $ad->simulated());
            if ($now['ok'] && $now['state'] === $want) {
                return self::finish($op, true, 'Already ' . $want . ' on the device: no command sent (' . $now['message'] . ')');
            }
        }
        try {
            $r = match ($op['op_type']) {
                'provision', 'activate' => $a['network_state'] === 'not_provisioned' || $a['network_state'] === 'provisioning' ? $ad->provision($a, $plan) : $ad->enable($a, $plan),
                'reconnect' => $ad->enable($a, $plan),
                'disconnect', 'suspend' => $ad->disable($a),
                'change_plan' => $ad->changePlan($a, $plan),
                'reset_session' => $ad->resetSession($a),
                'deprovision' => $ad->deprovision($a),
                'status' => $ad->status($a),
                default => ['ok' => false, 'confirmed' => false, 'message' => 'Unknown operation', 'log' => []],
            };
        } catch (Throwable $e) {
            $r = ['ok' => false, 'confirmed' => false, 'message' => 'Adapter error: ' . $e->getMessage(), 'log' => [['adapter', $e->getMessage(), false]]];
        }
        self::writeLog($op, $r, $ad->simulated());
        if (!empty($r['manual'])) {
            return self::toManual($op, $a, $r['message']);
        }
        if ($r['ok'] && !$r['confirmed'] && $ad->supports('status')) {
            // accepted but not confirmed: read the device back once
            $check = $ad->status($a);
            self::writeLog($op, $check, $ad->simulated());
            $want = self::RESULT_STATE[$op['op_type']] ?? null;
            if ($want && $check['ok'] && ($check['state'] === $want || ($want === 'not_provisioned' && $check['state'] === 'absent'))) {
                $r['confirmed'] = true;
                $r['message'] .= '; confirmed by reading the device';
            }
        }
        if ($r['ok'] && ($r['confirmed'] || $op['op_type'] === 'status')) {
            return self::finish($op, true, $r['message']);
        }
        if ($r['ok'] && !$r['confirmed']) {
            // left as sent: the scheduler reads the device again before it calls it done or failed
            DB::update('isp_net_ops', ['response' => mb_substr($r['message'], 0, 2000), 'next_try_at' => date('Y-m-d H:i:s', time() + 120), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $op['id']]);
            return 'sent';
        }
        return self::fail($op, $r['message']);
    }

    private static function writeLog(array $op, array $r, bool $sim): void
    {
        foreach ((array)($r['log'] ?? []) as [$cmd, $res, $ok]) {
            self::log($op, (string)$cmd, (string)$res, (bool)$ok, $sim);
        }
    }

    private static function log(array $op, string $cmd, string $result, bool $ok, bool $sim, string $dir = 'out'): void
    {
        DB::insert('isp_net_logs', ['op_id' => $op['id'], 'device_id' => $op['device_id'], 'account_id' => $op['account_id'], 'direction' => $dir,
            'command' => mb_substr($cmd, 0, 255), 'result' => mb_substr($result, 0, 255), 'ok' => $ok ? 1 : 0, 'simulated' => $sim ? 1 : 0, 'user_id' => Auth::id()]);
    }

    private static function finish(array $op, bool $ok, string $message): string
    {
        DB::tx(function () use ($op, $message) {
            DB::update('isp_net_ops', ['state' => 'confirmed', 'confirmed_at' => date('Y-m-d H:i:s'), 'response' => mb_substr($message, 0, 2000), 'error' => null,
                'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $op['id']]);
            if (isset(self::RESULT_STATE[$op['op_type']])) {
                self::setState((int)$op['account_id'], self::RESULT_STATE[$op['op_type']], $op, $message);
            }
            match ($op['source_type']) {
                'disc_queue' => IspDisconnect::confirmed((int)$op['source_id'], (int)$op['id']),
                'reconnection' => IspReconnect::confirmed((int)$op['source_id'], (int)$op['id']),
                default => null,
            };
        });
        return 'confirmed';
    }

    private static function fail(array $op, string $message): string
    {
        $final = (int)$op['attempts'] >= (int)$op['max_attempts'];
        DB::tx(function () use ($op, $message, $final) {
            $wait = max(1, IspSetup::int('isp_disc_retry_minutes')) * max(1, (int)$op['attempts']);
            DB::update('isp_net_ops', ['state' => $final ? 'failed' : 'queued', 'error' => mb_substr($message, 0, 255), 'failed_at' => date('Y-m-d H:i:s'),
                'next_try_at' => $final ? null : date('Y-m-d H:i:s', time() + $wait * 60), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $op['id']]);
            IspAccounts::event((int)$op['account_id'], 'network', (self::OP_TYPES[$op['op_type']] ?? $op['op_type']) . ' ' . ($final ? 'FAILED after ' . $op['attempts'] . ' attempts' : 'failed, will retry')
                . ': ' . $message, 'net_op', (int)$op['id']);
            if ($final) {
                if (in_array($op['op_type'], ['activate', 'provision', 'reconnect'], true)) {
                    DB::run("UPDATE isp_accounts SET network_state = 'failed' WHERE id = :a", ['a' => $op['account_id']]);
                }
                match ($op['source_type']) {
                    'disc_queue' => IspDisconnect::failed((int)$op['source_id'], $message),
                    'reconnection' => IspReconnect::failed((int)$op['source_id'], $message),
                    default => null,
                };
                // the network administrator gets a task
                $woType = in_array($op['op_type'], ['disconnect', 'suspend'], true) ? 'disconnection' : (in_array($op['op_type'], ['reconnect', 'activate', 'provision'], true) ? 'reconnection' : 'maintenance');
                $wo = IspWorkOrders::create(['wo_type' => $woType, 'account_id' => (int)$op['account_id'], 'net_op_id' => (int)$op['id'], 'priority' => 'high',
                    'description' => 'Network operation ' . $op['op_no'] . ' (' . (self::OP_TYPES[$op['op_type']] ?? $op['op_type']) . ') failed: ' . mb_substr($message, 0, 150)]);
                DB::run('UPDATE isp_net_ops SET work_order_id = :w WHERE id = :id', ['w' => $wo, 'id' => $op['id']]);
            }
        });
        return $final ? 'failed' : 'retry';
    }

    /** No device: a work order; the operation waits for the technician's confirmation */
    private static function toManual(array $op, array $a, string $message): string
    {
        DB::tx(function () use ($op, $a, $message) {
            // activation by hand is a task of the network administrator once the installation is done
            $type = match ($op['op_type']) {
                'disconnect', 'suspend', 'deprovision' => 'disconnection',
                'reconnect' => 'reconnection',
                default => 'maintenance',
            };
            $wo = IspWorkOrders::create(['wo_type' => $type, 'account_id' => (int)$a['id'], 'net_op_id' => (int)$op['id'], 'priority' => $type === 'reconnection' ? 'high' : 'normal',
                'address' => $a['install_address'], 'area_id' => $a['area_id'],
                'description' => (self::OP_TYPES[$op['op_type']] ?? $op['op_type']) . ' ' . $a['account_no'] . ' by hand: ' . mb_substr((string)$op['reason'], 0, 120)]);
            DB::update('isp_net_ops', ['state' => 'manual', 'work_order_id' => $wo, 'response' => mb_substr($message, 0, 2000), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $op['id']]);
        });
        return 'manual';
    }

    /** The technician completed (or could not do) the work order of a manual operation */
    public static function confirmManual(int $opId, bool $done, string $note): void
    {
        $op = DB::row('SELECT * FROM isp_net_ops WHERE id = :id', ['id' => $opId]);
        if (!$op || !in_array($op['state'], ['manual', 'failed', 'sent'], true)) {
            return;
        }
        self::log($op, 'manual confirmation', $note, $done, false, 'info');
        if ($done) {
            self::finish($op, true, 'Done by hand: ' . $note);
        } else {
            $op['attempts'] = $op['max_attempts'];
            DB::update('isp_net_ops', ['state' => 'failed', 'error' => mb_substr('Not done: ' . $note, 0, 255)], 'id = :id', ['id' => $opId]);
            match ($op['source_type']) {
                'disc_queue' => IspDisconnect::failed((int)$op['source_id'], $note),
                'reconnection' => IspReconnect::failed((int)$op['source_id'], $note),
                default => null,
            };
        }
    }

    private static function setState(int $accountId, string $state, array $op, string $message): void
    {
        $a = IspAccounts::get($accountId);
        if ($a['network_state'] !== $state) {
            DB::update('isp_accounts', ['network_state' => $state, 'last_session_at' => $state === 'enabled' ? date('Y-m-d H:i:s') : $a['last_session_at']], 'id = :id', ['id' => $accountId]);
        }
        IspAccounts::event($accountId, 'network', (self::OP_TYPES[$op['op_type']] ?? $op['op_type']) . ' confirmed' . ((int)$op['simulated'] === 1 ? ' (SIMULATED)' : '') . ': ' . mb_substr($message, 0, 160), 'net_op', (int)$op['id']);
    }

    /** A user retries a failed operation (three more attempts) */
    public static function retry(int $opId): void
    {
        $op = DB::row('SELECT * FROM isp_net_ops WHERE id = :id', ['id' => $opId]) ?? fail('Operation not found.');
        if (!in_array($op['state'], ['failed', 'sent'], true)) {
            fail('Only a failed (or unconfirmed) operation is retried.');
        }
        DB::update('isp_net_ops', ['state' => 'queued', 'max_attempts' => (int)$op['attempts'] + 3, 'next_try_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $opId]);
        if ($op['source_type'] === 'disc_queue') {
            DB::run("UPDATE isp_disc_queue SET status = 'executing' WHERE id = :q AND status = 'failed'", ['q' => $op['source_id']]);
        } elseif ($op['source_type'] === 'reconnection') {
            DB::run("UPDATE isp_reconnections SET status = 'executing' WHERE id = :q AND status = 'failed'", ['q' => $op['source_id']]);
        }
        Audit::log('retry', 'ispnet', 'isp_net_op', $opId, (string)$op['op_no']);
        self::execute($opId);
    }

    public static function cancel(int $opId, string $reason): void
    {
        $op = DB::row('SELECT * FROM isp_net_ops WHERE id = :id', ['id' => $opId]) ?? fail('Operation not found.');
        if (!in_array($op['state'], ['queued', 'failed', 'manual', 'requested'], true)) {
            fail('A sent or confirmed operation is not cancelled.');
        }
        DB::update('isp_net_ops', ['state' => 'cancelled', 'error' => mb_substr('Cancelled: ' . $reason, 0, 255), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $opId]);
        Audit::log('cancel', 'ispnet', 'isp_net_op', $opId, (string)$op['op_no'], ['reason' => $reason]);
    }

    /** The scheduler: queued operations whose time came, and sent ones still waiting for confirmation */
    public static function processQueue(int $limit = 100): array
    {
        $done = ['sent' => 0, 'verified' => 0];
        foreach (DB::column("SELECT id FROM isp_net_ops WHERE state = 'queued' AND (next_try_at IS NULL OR next_try_at <= :n) ORDER BY id LIMIT " . (int)$limit, ['n' => date('Y-m-d H:i:s')]) as $id) {
            self::execute((int)$id);
            $done['sent']++;
        }
        foreach (DB::all("SELECT * FROM isp_net_ops WHERE state = 'sent' AND next_try_at IS NOT NULL AND next_try_at <= :n ORDER BY id LIMIT 50", ['n' => date('Y-m-d H:i:s')]) as $op) {
            $a = IspAccounts::get((int)$op['account_id']);
            $ad = self::adapter($op['device_id'] ? self::device((int)$op['device_id']) : null);
            $want = self::RESULT_STATE[$op['op_type']] ?? null;
            $r = $ad->status($a);
            self::writeLog($op, $r, $ad->simulated());
            if ($want && $r['ok'] && ($r['state'] === $want || ($want === 'not_provisioned' && $r['state'] === 'absent'))) {
                self::finish($op, true, 'Confirmed on verification: ' . $r['message']);
            } elseif ((int)$op['attempts'] >= (int)$op['max_attempts']) {
                self::fail($op, 'Never confirmed by the device: ' . $r['message']);
            } else {
                DB::update('isp_net_ops', ['state' => 'queued', 'next_try_at' => date('Y-m-d H:i:s', time() + 300)], 'id = :id', ['id' => $op['id']]);
            }
            $done['verified']++;
        }
        return $done;
    }

    /**
     * Network reconciliation: the expected state of each account (from its service status) against what its device
     * reads. Returns the differences; nothing is changed except the stored network state of a device that disagrees.
     */
    public static function reconcile(int $limit = 500): array
    {
        $out = [];
        foreach (DB::all("SELECT a.id FROM isp_accounts a WHERE a.service_status IN ('active', 'disconnected', 'suspended') AND a.network_state <> 'not_provisioned' ORDER BY a.id LIMIT " . (int)$limit) as $row) {
            $a = IspAccounts::get((int)$row['id']);
            $dev = self::accountDevice($a);
            $ad = self::adapter($dev);
            if (!$ad->supports('status') || $ad instanceof IspAdapterManual) {
                continue;
            }
            $r = $ad->status($a);
            if (!$r['ok']) {
                $out[] = ['account_id' => $a['id'], 'account_no' => $a['account_no'], 'expected' => '?', 'actual' => 'unreadable', 'note' => $r['message']];
                continue;
            }
            $expected = $a['service_status'] === 'active' ? 'enabled' : 'disabled';
            $actual = $r['state'] === 'absent' ? 'not_provisioned' : $r['state'];
            if ($actual !== 'unknown' && $actual !== $a['network_state']) {
                DB::run('UPDATE isp_accounts SET network_state = :s WHERE id = :id', ['s' => $actual, 'id' => $a['id']]);
            }
            if ($actual !== $expected && $actual !== 'unknown') {
                $out[] = ['account_id' => $a['id'], 'account_no' => $a['account_no'], 'expected' => $expected, 'actual' => $actual, 'note' => $r['message'] . ($ad->simulated() ? ' (SIMULATED)' : '')];
            }
        }
        return $out;
    }

    public static function saveDevice(array $f, ?int $id, ?string $secret): int
    {
        if (trim((string)$f['name']) === '' || !isset(self::ADAPTERS[$f['adapter']])) {
            fail('Enter the device name and choose its adapter.');
        }
        if (in_array($f['adapter'], ['mikrotik', 'radius'], true) && trim((string)$f['host']) === '') {
            fail('Enter the host or IP address of the device.');
        }
        if ($secret !== null && $secret !== '') {
            $f['secret_enc'] = IspCrypto::encrypt($secret);
        }
        if (!empty($f['is_default'])) {
            DB::run('UPDATE isp_devices SET is_default = 0');
        }
        if ($id) {
            DB::update('isp_devices', $f + ['updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            Audit::log('edit', 'ispnet', 'isp_device', $id, (string)$f['name'], ['reason' => 'Network device settings (credentials are never written to the audit trail)']);
        } else {
            $id = DB::insert('isp_devices', $f);
            Audit::log('create', 'ispnet', 'isp_device', $id, (string)$f['name']);
        }
        return $id;
    }
}
