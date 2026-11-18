<?php
declare(strict_types=1);

/**
 * What the Network & Service Control pages share: the simulator notice, the labels of simulated results, the names
 * of users and the clean-up of stored device answers before they are shown.
 */

if (!function_exists('ispnet_simulator_on')) {
    /** Is any active device the integration simulator? */
    function ispnet_simulator_on(): bool
    {
        static $on = null;
        return $on ??= (int)DB::value("SELECT COUNT(*) FROM isp_devices WHERE is_active = 1 AND adapter = 'simulator'") > 0;
    }

    /** The notice shown on every page whose results may come from the simulator */
    function ispnet_sim_notice(): string
    {
        if (!ispnet_simulator_on()) {
            return '';
        }
        return '<div class="alert alert-warning d-flex gap-2 align-items-start isp-sim-notice"><i class="bi bi-cpu"></i><div><strong>The integration simulator is switched on.</strong> '
            . 'Operations it answers are marked "Simulated: no real device was changed". They test the workflows only; no subscriber was actually enabled or disabled on the network.'
            . (Access::page('ispnet.devices') ? ' <a href="' . h(url('ispnet.devices')) . '">Network devices</a>' : '') . '</div></div>';
    }

    /** The label of a simulated result (plain text, never a capsule) */
    function ispnet_sim_label($simulated): string
    {
        return (int)$simulated === 1 ? '<span class="isp-sim-label text-warning-emphasis small"><i class="bi bi-cpu"></i> Simulated: no real device was changed</span>' : '';
    }

    /** user id => full name, for the people columns of a list */
    function ispnet_user_names(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        [$in, $p] = DB::in('u', $ids);
        return DB::pairs("SELECT id, full_name FROM users WHERE id IN $in", $p);
    }

    /** A stored device answer or payload with anything that looks like a credential masked */
    function ispnet_scrub(?string $text): string
    {
        $t = (string)$text;
        $t = preg_replace('/("?(?:password|passwd|secret|token|api[_-]?key|authorization|auth)"?\s*[:=]\s*"?)[^",;&\s}]+/i', '$1********', $t) ?? $t;
        return preg_replace('/(=password=)\S+/i', '$1********', $t) ?? $t;
    }

    /** The device label (name and adapter) */
    function ispnet_device_label(?array $d): string
    {
        if (!$d) {
            return 'No device (manual work orders)';
        }
        return $d['name'] . ' (' . (IspNetwork::ADAPTER_LABELS[$d['adapter']] ?? $d['adapter']) . ')';
    }

    /** An account's branch is one the user may see */
    function ispnet_account_visible(int $accountId): bool
    {
        $b = IspSetup::userBranch();
        if ($b === null) {
            return true;
        }
        $ab = DB::value('SELECT branch_id FROM isp_accounts WHERE id = :a', ['a' => $accountId]);
        return $ab === null || (int)$ab === $b;
    }

    /** Stops the request when the user lacks the network administration right */
    function ispnet_require_admin(): void
    {
        if (!Access::special('isp_network_admin')) {
            fail('This needs the permission "Cable & Internet: can configure network devices and run network commands".');
        }
    }
}
