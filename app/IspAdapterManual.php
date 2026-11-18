<?php
declare(strict_types=1);

/**
 * No network device: every operation becomes a work order for a technician or the network administrator, and is
 * confirmed only when the person who did it completes that work order (manual activation, disconnection and
 * reconnection). Nothing is ever reported done before that.
 */
final class IspAdapterManual extends IspAdapterBase
{
    public function label(): string
    {
        return 'Manual (work orders)';
    }

    public function supports(string $op): bool
    {
        return true;
    }

    private function manual(string $what): array
    {
        $this->note('manual: ' . $what, 'work order needed', true);
        return $this->result(true, false, 'Manual operation: ' . $what . ' (a work order confirms it)', 'unknown') + ['manual' => true];
    }

    public function test(): array
    {
        return $this->result(true, true, 'Manual mode: operations become work orders.');
    }

    public function provision(array $acct, array $plan): array
    {
        return $this->manual('set up the subscriber on the network');
    }

    public function enable(array $acct, array $plan): array
    {
        return $this->manual('connect / enable the subscriber');
    }

    public function disable(array $acct): array
    {
        return $this->manual('disconnect the subscriber');
    }

    public function changePlan(array $acct, array $plan): array
    {
        return $this->manual('change the speed profile to ' . $plan['name']);
    }

    public function resetSession(array $acct): array
    {
        return $this->manual('reset the subscriber session');
    }

    public function status(array $acct): array
    {
        return $this->result(false, false, 'No device to read the state from (manual mode).', 'unknown') + ['online' => false, 'session' => ''];
    }

    public function deprovision(array $acct): array
    {
        return $this->manual('remove the subscriber from the network');
    }
}
