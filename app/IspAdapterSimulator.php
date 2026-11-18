<?php
declare(strict_types=1);

/**
 * THE INTEGRATION SIMULATOR (development and testing only). It sends nothing to any network: it answers the way a
 * device would, so the provisioning, disconnection and reconnection workflows can be tried end to end. Every operation
 * it answers is marked simulated (isp_net_ops.simulated, isp_net_logs.simulated) and every page shows it as SIMULATED,
 * never as a real network result.
 *
 * Device options (Network Devices > the simulator > Options), for testing failures:
 *   fail_accounts = 1000004, 1000007   these account numbers always fail
 *   fail_ops      = disable            these operations always fail (provision, enable, disable, change_plan ...)
 *   fail_rate     = 0.1                a share of operations fails at random
 *   unconfirmed   = 1                  the command is "accepted" but never confirmed (tests the verification step)
 */
final class IspAdapterSimulator extends IspAdapterBase
{
    public function label(): string
    {
        return 'Integration simulator (not a real network)';
    }

    public function simulated(): bool
    {
        return true;
    }

    private function fails(array $acct, string $op): ?string
    {
        $accts = array_filter(array_map('trim', explode(',', $this->o('fail_accounts'))));
        if (in_array((string)$acct['account_no'], $accts, true)) {
            return 'simulated failure for account ' . $acct['account_no'] . ' (fail_accounts)';
        }
        $ops = array_filter(array_map('trim', explode(',', $this->o('fail_ops'))));
        if (in_array($op, $ops, true)) {
            return 'simulated failure of every ' . $op . ' (fail_ops)';
        }
        $rate = (float)$this->o('fail_rate', '0');
        if ($rate > 0 && mt_rand() / mt_getrandmax() < $rate) {
            return 'simulated random failure (fail_rate ' . $rate . ')';
        }
        return null;
    }

    private function run(array $acct, string $op, string $state, string $what): array
    {
        $cmd = 'SIM ' . $op . ' ' . $acct['pppoe_username'];
        if ($why = $this->fails($acct, $op)) {
            $this->note($cmd, 'timeout: ' . $why, false);
            return $this->result(false, false, 'SIMULATED: ' . $why);
        }
        $this->note($cmd, 'ok (simulated)', true);
        if ($this->o('unconfirmed') === '1') {
            return $this->result(true, false, 'SIMULATED: accepted, not confirmed', 'unknown');
        }
        return $this->result(true, true, 'SIMULATED: ' . $what, $state);
    }

    public function test(): array
    {
        $this->note('SIM ping', 'pong (simulated)', true);
        return $this->result(true, true, 'SIMULATED: the simulator answers; no real device was contacted.');
    }

    public function provision(array $acct, array $plan): array
    {
        return $this->run($acct, 'provision', 'enabled', 'subscriber ' . $acct['pppoe_username'] . ' provisioned with profile ' . ($plan['network_profile'] ?: $this->rateLimit($plan)));
    }

    public function enable(array $acct, array $plan): array
    {
        return $this->run($acct, 'enable', 'enabled', 'subscriber ' . $acct['pppoe_username'] . ' enabled');
    }

    public function disable(array $acct): array
    {
        return $this->run($acct, 'disable', 'disabled', 'subscriber ' . $acct['pppoe_username'] . ' disabled and its session closed');
    }

    public function changePlan(array $acct, array $plan): array
    {
        return $this->run($acct, 'change_plan', 'enabled', 'profile of ' . $acct['pppoe_username'] . ' set to ' . ($plan['network_profile'] ?: $this->rateLimit($plan)));
    }

    public function resetSession(array $acct): array
    {
        return $this->run($acct, 'reset_session', 'enabled', 'session of ' . $acct['pppoe_username'] . ' reset');
    }

    public function deprovision(array $acct): array
    {
        return $this->run($acct, 'deprovision', 'absent', 'subscriber ' . $acct['pppoe_username'] . ' removed');
    }

    /** The state the simulator last confirmed for the account */
    public function status(array $acct): array
    {
        $last = DB::value("SELECT command FROM isp_net_logs WHERE account_id = :a AND simulated = 1 AND ok = 1 AND command LIKE 'SIM %' AND command NOT LIKE 'SIM status%' AND command NOT LIKE 'SIM reset%'
                            ORDER BY id DESC LIMIT 1", ['a' => $acct['id']]);
        $state = 'absent';
        if ($last) {
            $op = explode(' ', (string)$last)[1] ?? '';
            $state = match ($op) {
                'disable' => 'disabled',
                'deprovision' => 'absent',
                default => 'enabled',
            };
        }
        $this->note('SIM status ' . $acct['pppoe_username'], $state . ' (simulated)', true);
        return $this->result(true, true, 'SIMULATED state: ' . $state, $state) + ['online' => $state === 'enabled', 'session' => $state === 'enabled' ? 'simulated session' : ''];
    }
}
