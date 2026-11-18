<?php
declare(strict_types=1);

/**
 * An OLT / ONU management platform reached through its northbound HTTP API (the vendor's EMS / NMS, or a local
 * middleware that speaks to the OLT). OLT commands differ between vendors and models, so this adapter assumes none:
 * the URLs of the platform's own documented operations are written in the device options, with {serial}, {onu_id},
 * {port}, {account_no}, {profile}, {down}, {up} filled in from the subscriber.
 *
 * Options:
 *   base_url          https://ems.example.net/api
 *   auth_header       Authorization: Bearer {secret}         (the device secret is put in {secret})
 *   enable_url        POST {base}/onu/{serial}/activate
 *   disable_url       POST {base}/onu/{serial}/deactivate
 *   provision_url     POST {base}/onu       body: {"serial":"{serial}","profile":"{profile}"}
 *   provision_body    the JSON body of provision (placeholders allowed)
 *   status_url        GET {base}/onu/{serial}
 *   state_field       admin_state     the field of the status answer that holds the state
 *   enabled_value     enabled         the value meaning enabled
 *   online_field      oper_state / online_value up
 * A 2xx answer is "accepted"; the adapter then reads the status back and only a matching state is "confirmed".
 */
final class IspAdapterOltHttp extends IspAdapterBase
{
    public function label(): string
    {
        return 'OLT / ONU platform (HTTP API)';
    }

    public function supports(string $op): bool
    {
        return match ($op) {
            'enable', 'disable' => $this->o($op . '_url') !== '',
            'provision', 'change_plan' => $this->o('provision_url') !== '' || $this->o('enable_url') !== '',
            'status' => $this->o('status_url') !== '',
            default => false,
        };
    }

    private function fill(string $tpl, array $acct, array $plan = []): string
    {
        $serial = (string)(DB::value("SELECT onu_serial FROM isp_work_orders WHERE account_id = :a AND onu_serial IS NOT NULL ORDER BY id DESC LIMIT 1", ['a' => $acct['id']]) ?? '');
        $port = $acct['port_id'] ? (string)(DB::value('SELECT CONCAT(n.code, \'/\', p.port_no) FROM isp_net_ports p JOIN isp_net_nodes n ON n.id = p.node_id WHERE p.id = :p', ['p' => $acct['port_id']]) ?? '') : '';
        return strtr($tpl, ['{base}' => rtrim($this->o('base_url'), '/'), '{serial}' => rawurlencode($serial), '{onu_id}' => rawurlencode($serial), '{port}' => rawurlencode($port),
            '{account_no}' => rawurlencode((string)$acct['account_no']), '{profile}' => (string)($plan['network_profile'] ?? ''), '{down}' => (string)($plan['download_mbps'] ?? ''),
            '{up}' => (string)($plan['upload_mbps'] ?? ''), '{secret}' => $this->secret()]);
    }

    private function call(string $spec, array $acct, array $plan = [], ?string $body = null): array
    {
        if (!preg_match('/^(GET|POST|PUT|PATCH|DELETE)\s+(\S+)$/i', trim($spec), $m)) {
            return [0, '', 'The operation URL must be "METHOD url".'];
        }
        $url = $this->fill($m[2], $acct, $plan);
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($this->o('auth_header') !== '') {
            $headers[] = $this->fill($this->o('auth_header'), $acct, $plan);
        }
        [$code, $resp, $err] = $this->http(strtoupper($m[1]), $url, $body !== null ? $this->fill($body, $acct, $plan) : null, $headers);
        $shown = strtoupper($m[1]) . ' ' . preg_replace('/([?&](token|key|secret)=)[^&]+/i', '$1***', $url);
        $this->note($shown, $err !== '' ? $err : 'HTTP ' . $code, $err === '' && $code >= 200 && $code < 300);
        return [$code, $resp, $err];
    }

    private function state(array $acct): string
    {
        if ($this->o('status_url') === '') {
            return 'unknown';
        }
        [$code, $resp, $err] = $this->call($this->o('status_url'), $acct);
        if ($err !== '' || $code < 200 || $code >= 300) {
            return 'unknown';
        }
        $j = json_decode($resp, true);
        $v = is_array($j) ? ($j[$this->o('state_field', 'admin_state')] ?? null) : null;
        if ($v === null) {
            return 'unknown';
        }
        return (string)$v === $this->o('enabled_value', 'enabled') ? 'enabled' : 'disabled';
    }

    private function op(string $which, array $acct, array $plan, string $want): array
    {
        $spec = $this->o($which . '_url');
        if ($spec === '') {
            return $this->unsupported($which . ' (no ' . $which . '_url in the options)');
        }
        [$code, $resp, $err] = $this->call($spec, $acct, $plan, $which === 'provision' ? ($this->o('provision_body') ?: null) : null);
        if ($err !== '' || $code < 200 || $code >= 300) {
            return $this->result(false, false, 'The platform refused ' . $which . ': ' . ($err ?: 'HTTP ' . $code . ' ' . mb_substr($resp, 0, 120)));
        }
        $state = $this->state($acct);
        return $this->result(true, $state === $want, $state === $want ? 'The platform confirms ' . $want : 'Accepted; the platform reads ' . $state, $state);
    }

    public function test(): array
    {
        if ($this->o('base_url') === '') {
            return $this->result(false, false, 'Set base_url in the options.');
        }
        [$code, , $err] = $this->http('GET', rtrim($this->o('base_url'), '/') . '/', null, $this->o('auth_header') !== '' ? [strtr($this->o('auth_header'), ['{secret}' => $this->secret()])] : []);
        $this->note('GET ' . $this->o('base_url'), $err ?: 'HTTP ' . $code, $err === '' && $code > 0 && $code < 500);
        return $this->result($err === '' && $code > 0 && $code < 500, true, $err ?: 'The platform answers (HTTP ' . $code . ')');
    }

    public function provision(array $acct, array $plan): array
    {
        return $this->o('provision_url') !== '' ? $this->op('provision', $acct, $plan, 'enabled') : $this->enable($acct, $plan);
    }

    public function enable(array $acct, array $plan): array
    {
        return $this->op('enable', $acct, $plan, 'enabled');
    }

    public function disable(array $acct): array
    {
        return $this->op('disable', $acct, [], 'disabled');
    }

    public function status(array $acct): array
    {
        $state = $this->state($acct);
        return $this->result($state !== 'unknown', true, 'The platform reads ' . $state, $state) + ['online' => $state === 'enabled', 'session' => ''];
    }
}
