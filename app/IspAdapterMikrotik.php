<?php
declare(strict_types=1);

/**
 * MikroTik RouterOS (PPPoE server / BNG) through the RouterOS API.
 *
 *   method = ppp_secret (default)   one PPP secret per subscriber (/ppp/secret): provision adds it with the plan's
 *                                   profile, disable sets disabled=yes and removes the active session, enable sets
 *                                   disabled=no; every change is read back (/ppp/secret/print) before it is confirmed
 *   method = address_list           static-IP subscribers: disable adds the IP to a firewall address list that the
 *                                   router drops (list = isp-blocked), enable removes it
 * Options: method, profile_default (the PPP profile when a plan has none; the plan's speed lives in its PPP profile,
 * e.g. rate-limit 10M/50M), local_address, list (address list name), timeout, verify_tls = 0 (self-signed certificate).
 */
final class IspAdapterMikrotik extends IspAdapterBase
{
    public function label(): string
    {
        return 'MikroTik RouterOS API';
    }

    public function supports(string $op): bool
    {
        return true;
    }

    private function api(): IspRouterOsApi
    {
        $host = trim((string)$this->device['host']);
        if ($host === '') {
            throw new RuntimeException('The router has no host / IP address.');
        }
        $tls = (int)$this->device['use_tls'] === 1;
        $port = (int)($this->device['port'] ?: ($tls ? 8729 : 8728));
        $api = new IspRouterOsApi((int)$this->o('timeout', '8'));
        $api->connect($host, $port, $tls, (string)$this->device['username'], $this->secret(), $this->o('verify_tls', '1') !== '0');
        return $api;
    }

    /** Runs $fn with a connected API; any error becomes a failed result (never a success) */
    private function with(callable $fn): array
    {
        try {
            $api = $this->api();
        } catch (Throwable $e) {
            $this->note('connect ' . $this->device['host'], $e->getMessage(), false);
            return $this->result(false, false, $e->getMessage());
        }
        try {
            return $fn($api);
        } catch (Throwable $e) {
            $this->note('error', $e->getMessage(), false);
            return $this->result(false, false, $e->getMessage());
        } finally {
            $api->close();
        }
    }

    private function cmd(IspRouterOsApi $api, array $words, string $shown): array
    {
        $r = $api->talk($words);
        $trap = $api->trap($r);
        $this->note($shown, $trap !== '' ? 'trap: ' . $trap : 'done', $trap === '');
        if ($trap !== '') {
            throw new RuntimeException($shown . ': ' . $trap);
        }
        return $r;
    }

    private function secretRow(IspRouterOsApi $api, string $user): ?array
    {
        $rows = $api->rows($this->cmd($api, ['/ppp/secret/print', '?name=' . $user], '/ppp/secret/print ?name=' . $user));
        return $rows[0] ?? null;
    }

    private function profile(array $plan): string
    {
        return trim((string)($plan['network_profile'] ?? '')) ?: $this->o('profile_default', 'default');
    }

    public function test(): array
    {
        return $this->with(function (IspRouterOsApi $api) {
            $r = $api->rows($this->cmd($api, ['/system/resource/print'], '/system/resource/print'));
            $v = $r[0]['version'] ?? '?';
            return $this->result(true, true, 'Connected to RouterOS ' . $v . ' (' . ($r[0]['board-name'] ?? '') . ')');
        });
    }

    public function provision(array $acct, array $plan): array
    {
        if ($this->o('method') === 'address_list') {
            return $this->enable($acct, $plan);
        }
        return $this->with(function (IspRouterOsApi $api) use ($acct, $plan) {
            $user = (string)$acct['pppoe_username'];
            $pass = (string)(IspAccounts::pppoePassword($acct) ?? '');
            $attrs = ['=profile=' . $this->profile($plan), '=service=pppoe', '=comment=' . $acct['account_no'] . ' ' . mb_substr((string)$acct['account_name'], 0, 40), '=disabled=no'];
            if (!empty($acct['static_ip'])) {
                $attrs[] = '=remote-address=' . $acct['static_ip'];
                if ($this->o('local_address') !== '') {
                    $attrs[] = '=local-address=' . $this->o('local_address');
                }
            }
            $row = $this->secretRow($api, $user);
            if ($row) {
                $this->cmd($api, array_merge(['/ppp/secret/set', '=.id=' . $row['.id'], '=password=' . $pass], $attrs), '/ppp/secret/set ' . $user . ' profile=' . $this->profile($plan));
            } else {
                $this->cmd($api, array_merge(['/ppp/secret/add', '=name=' . $user, '=password=' . $pass], $attrs), '/ppp/secret/add ' . $user . ' profile=' . $this->profile($plan));
            }
            $check = $this->secretRow($api, $user);
            $ok = $check && ($check['disabled'] ?? 'false') === 'false' && ($check['profile'] ?? '') === $this->profile($plan);
            return $this->result(true, $ok, $ok ? 'PPP secret ' . $user . ' is in place and enabled' : 'The secret was written but did not read back as expected', $ok ? 'enabled' : 'unknown');
        });
    }

    public function enable(array $acct, array $plan): array
    {
        return $this->with(function (IspRouterOsApi $api) use ($acct, $plan) {
            if ($this->o('method') === 'address_list') {
                $ip = (string)$acct['static_ip'];
                $list = $this->o('list', 'isp-blocked');
                foreach ($api->rows($this->cmd($api, ['/ip/firewall/address-list/print', '?list=' . $list, '?address=' . $ip], 'address-list print ' . $list . ' ' . $ip)) as $r) {
                    $this->cmd($api, ['/ip/firewall/address-list/remove', '=.id=' . $r['.id']], 'address-list remove ' . $ip);
                }
                $left = $api->rows($api->talk(['/ip/firewall/address-list/print', '?list=' . $list, '?address=' . $ip]));
                return $this->result(true, !$left, !$left ? $ip . ' is no longer blocked' : $ip . ' is still on the list', !$left ? 'enabled' : 'disabled');
            }
            $user = (string)$acct['pppoe_username'];
            $row = $this->secretRow($api, $user);
            if (!$row) {
                // never provisioned on this router: provision now
                $this->log[] = ['enable ' . $user, 'not on the router: provisioning', true];
                $api->close();
                return $this->provision($acct, $plan);
            }
            $this->cmd($api, ['/ppp/secret/set', '=.id=' . $row['.id'], '=disabled=no'], '/ppp/secret/set ' . $user . ' disabled=no');
            $check = $this->secretRow($api, $user);
            $ok = $check && ($check['disabled'] ?? '') === 'false';
            return $this->result(true, $ok, $ok ? $user . ' enabled on the router' : $user . ' did not read back enabled', $ok ? 'enabled' : 'unknown');
        });
    }

    public function disable(array $acct): array
    {
        return $this->with(function (IspRouterOsApi $api) use ($acct) {
            if ($this->o('method') === 'address_list') {
                $ip = (string)$acct['static_ip'];
                $list = $this->o('list', 'isp-blocked');
                if (!$api->rows($api->talk(['/ip/firewall/address-list/print', '?list=' . $list, '?address=' . $ip]))) {
                    $this->cmd($api, ['/ip/firewall/address-list/add', '=list=' . $list, '=address=' . $ip, '=comment=' . $acct['account_no']], 'address-list add ' . $list . ' ' . $ip);
                }
                $there = $api->rows($api->talk(['/ip/firewall/address-list/print', '?list=' . $list, '?address=' . $ip]));
                return $this->result(true, (bool)$there, $there ? $ip . ' blocked' : 'the block did not read back', $there ? 'disabled' : 'unknown');
            }
            $user = (string)$acct['pppoe_username'];
            $row = $this->secretRow($api, $user);
            if (!$row) {
                return $this->result(true, true, $user . ' has no PPP secret on the router: nothing can log in', 'absent');
            }
            $this->cmd($api, ['/ppp/secret/set', '=.id=' . $row['.id'], '=disabled=yes'], '/ppp/secret/set ' . $user . ' disabled=yes');
            foreach ($api->rows($this->cmd($api, ['/ppp/active/print', '?name=' . $user], '/ppp/active/print ?name=' . $user)) as $s) {
                $this->cmd($api, ['/ppp/active/remove', '=.id=' . $s['.id']], '/ppp/active/remove ' . $user);
            }
            $check = $this->secretRow($api, $user);
            $active = $api->rows($api->talk(['/ppp/active/print', '?name=' . $user]));
            $ok = $check && ($check['disabled'] ?? '') === 'true' && !$active;
            return $this->result(true, $ok, $ok ? $user . ' disabled and its session closed' : $user . ' did not read back disabled', $ok ? 'disabled' : 'unknown');
        });
    }

    public function changePlan(array $acct, array $plan): array
    {
        $r = $this->provision($acct, $plan);
        if ($r['ok'] && $this->o('method') !== 'address_list') {
            // the new profile applies to a new session
            $this->resetSession($acct);
        }
        return $r;
    }

    public function resetSession(array $acct): array
    {
        return $this->with(function (IspRouterOsApi $api) use ($acct) {
            $user = (string)$acct['pppoe_username'];
            $n = 0;
            foreach ($api->rows($this->cmd($api, ['/ppp/active/print', '?name=' . $user], '/ppp/active/print ?name=' . $user)) as $s) {
                $this->cmd($api, ['/ppp/active/remove', '=.id=' . $s['.id']], '/ppp/active/remove ' . $user);
                $n++;
            }
            return $this->result(true, true, $n ? 'Session of ' . $user . ' reset' : $user . ' had no active session', 'enabled');
        });
    }

    public function status(array $acct): array
    {
        return $this->with(function (IspRouterOsApi $api) use ($acct) {
            $user = (string)$acct['pppoe_username'];
            $row = $this->secretRow($api, $user);
            $active = $api->rows($this->cmd($api, ['/ppp/active/print', '?name=' . $user], '/ppp/active/print ?name=' . $user));
            $state = !$row ? 'absent' : (($row['disabled'] ?? '') === 'true' ? 'disabled' : 'enabled');
            $s = $active[0] ?? null;
            return $this->result(true, true, 'Secret ' . $state . ($s ? ', online since ' . ($s['uptime'] ?? '?') . ' at ' . ($s['address'] ?? '') : ', offline'), $state)
                + ['online' => (bool)$s, 'session' => $s ? ('uptime ' . ($s['uptime'] ?? '') . ', ' . ($s['address'] ?? '') . ', caller ' . ($s['caller-id'] ?? '')) : ''];
        });
    }

    public function deprovision(array $acct): array
    {
        return $this->with(function (IspRouterOsApi $api) use ($acct) {
            $user = (string)$acct['pppoe_username'];
            foreach ($api->rows($api->talk(['/ppp/active/print', '?name=' . $user])) as $s) {
                $this->cmd($api, ['/ppp/active/remove', '=.id=' . $s['.id']], '/ppp/active/remove ' . $user);
            }
            $row = $this->secretRow($api, $user);
            if ($row) {
                $this->cmd($api, ['/ppp/secret/remove', '=.id=' . $row['.id']], '/ppp/secret/remove ' . $user);
            }
            $gone = !$this->secretRow($api, $user);
            return $this->result(true, $gone, $gone ? $user . ' removed from the router' : $user . ' is still on the router', $gone ? 'absent' : 'unknown');
        });
    }
}
