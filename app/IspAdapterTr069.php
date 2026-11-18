<?php
declare(strict_types=1);

/**
 * A TR-069 auto-configuration server with the GenieACS northbound API (port 7557). TR-069 manages the subscriber's
 * device (status, last inform, reboot, parameters); it does not authorize internet access, so it does not enable or
 * disable subscribers: pair it with a MikroTik or RADIUS device for that.
 * Options: base_url (http://acs.local:7557), serial_param (_deviceId._SerialNumber), auth_header.
 */
final class IspAdapterTr069 extends IspAdapterBase
{
    public function label(): string
    {
        return 'TR-069 ACS (GenieACS NBI)';
    }

    public function supports(string $op): bool
    {
        return in_array($op, ['status', 'reset_session'], true);
    }

    private function serial(array $acct): string
    {
        return (string)(DB::value("SELECT COALESCE(router_serial, onu_serial) FROM isp_work_orders WHERE account_id = :a AND (router_serial IS NOT NULL OR onu_serial IS NOT NULL) ORDER BY id DESC LIMIT 1",
            ['a' => $acct['id']]) ?? '');
    }

    private function headers(): array
    {
        $h = ['Accept: application/json', 'Content-Type: application/json'];
        if ($this->o('auth_header') !== '') {
            $h[] = strtr($this->o('auth_header'), ['{secret}' => $this->secret()]);
        }
        return $h;
    }

    private function deviceId(array $acct): ?string
    {
        $serial = $this->serial($acct);
        if ($serial === '') {
            return null;
        }
        $q = rawurlencode(json_encode([$this->o('serial_param', '_deviceId._SerialNumber') => $serial]));
        [$code, $resp, $err] = $this->http('GET', rtrim($this->o('base_url'), '/') . '/devices/?query=' . $q . '&projection=_id,_lastInform', null, $this->headers());
        $this->note('GET /devices ?serial=' . $serial, $err ?: 'HTTP ' . $code, $err === '' && $code === 200);
        $j = json_decode($resp, true);
        return is_array($j) && isset($j[0]['_id']) ? (string)$j[0]['_id'] : null;
    }

    public function test(): array
    {
        [$code, , $err] = $this->http('GET', rtrim($this->o('base_url'), '/') . '/devices/?projection=_id&limit=1', null, $this->headers());
        $this->note('GET /devices limit 1', $err ?: 'HTTP ' . $code, $err === '' && $code === 200);
        return $this->result($err === '' && $code === 200, true, $err ?: 'The ACS answers (HTTP ' . $code . ')');
    }

    public function provision(array $acct, array $plan): array
    {
        return $this->unsupported('authorize subscribers (TR-069 manages the device only)');
    }

    public function enable(array $acct, array $plan): array
    {
        return $this->unsupported('enable subscribers (TR-069 manages the device only)');
    }

    public function disable(array $acct): array
    {
        return $this->unsupported('disable subscribers (TR-069 manages the device only)');
    }

    public function status(array $acct): array
    {
        $id = $this->deviceId($acct);
        if (!$id) {
            return $this->result(false, false, 'The device of the subscriber is not on the ACS (no serial number, or not seen yet).');
        }
        [$code, $resp] = $this->http('GET', rtrim($this->o('base_url'), '/') . '/devices/?query=' . rawurlencode(json_encode(['_id' => $id])) . '&projection=_lastInform', null, $this->headers());
        $j = json_decode($resp, true);
        $last = $j[0]['_lastInform'] ?? null;
        $recent = $last && strtotime((string)$last) > time() - 900;
        return $this->result(true, true, 'Device ' . $id . ($last ? ', last inform ' . $last : ''), 'unknown') + ['online' => $recent, 'session' => $last ? 'last inform ' . $last : ''];
    }

    public function resetSession(array $acct): array
    {
        $id = $this->deviceId($acct);
        if (!$id) {
            return $this->result(false, false, 'The device of the subscriber is not on the ACS.');
        }
        [$code, , $err] = $this->http('POST', rtrim($this->o('base_url'), '/') . '/devices/' . rawurlencode($id) . '/tasks?connection_request', json_encode(['name' => 'reboot']), $this->headers());
        $this->note('POST /devices/' . $id . '/tasks reboot', $err ?: 'HTTP ' . $code, $err === '' && $code >= 200 && $code < 300);
        $ok = $err === '' && $code >= 200 && $code < 300;
        return $this->result($ok, $ok && $code === 200, $ok ? ($code === 200 ? 'The device rebooted' : 'Reboot queued until the device connects') : 'The ACS refused the reboot');
    }
}
