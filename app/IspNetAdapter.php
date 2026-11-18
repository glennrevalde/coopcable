<?php
declare(strict_types=1);

/**
 * A network integration adapter. Each vendor or platform has its own (the commands differ); none claims what its
 * device did not confirm. Every method returns:
 *   ['ok' => bool, 'confirmed' => bool, 'message' => string, 'log' => [[command, result, ok], ...], 'state' => enabled|disabled|absent|unknown]
 * "ok" means the command was accepted; "confirmed" means the adapter read the device back and it is in the state asked.
 */
interface IspNetAdapter
{
    /** The adapter's label */
    public function label(): string;

    /** True for the integration simulator: its answers are never shown as real network results */
    public function simulated(): bool;

    /** What the adapter can do: provision, enable, disable, change_plan, reset_session, status, deprovision */
    public function supports(string $op): bool;

    public function test(): array;

    public function provision(array $acct, array $plan): array;

    public function enable(array $acct, array $plan): array;

    public function disable(array $acct): array;

    public function changePlan(array $acct, array $plan): array;

    public function resetSession(array $acct): array;

    public function status(array $acct): array;

    public function deprovision(array $acct): array;
}
