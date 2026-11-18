<?php
declare(strict_types=1);

/** What the adapters share: the device record, its options and secret, the result shape */
abstract class IspAdapterBase implements IspNetAdapter
{
    protected array $device;
    protected array $opt;
    protected array $log = [];

    public function __construct(array $device)
    {
        $this->device = $device;
        $this->opt = self::parseOptions((string)($device['options'] ?? ''));
    }

    /** "key=value" lines (or "key: value") */
    public static function parseOptions(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^([A-Za-z0-9_.\-]+)\s*[=:]\s*(.*)$/', $line, $m)) {
                $out[strtolower($m[1])] = trim($m[2]);
            }
        }
        return $out;
    }

    protected function secret(): string
    {
        return (string)(IspCrypto::decrypt($this->device['secret_enc'] ?? null) ?? '');
    }

    protected function o(string $key, string $default = ''): string
    {
        return $this->opt[strtolower($key)] ?? $default;
    }

    public function simulated(): bool
    {
        return false;
    }

    protected function note(string $cmd, string $result, bool $ok): void
    {
        $this->log[] = [mb_substr($cmd, 0, 255), mb_substr($result, 0, 255), $ok];
    }

    protected function result(bool $ok, bool $confirmed, string $message, string $state = 'unknown'): array
    {
        $r = ['ok' => $ok, 'confirmed' => $ok && $confirmed, 'message' => $message, 'log' => $this->log, 'state' => $state];
        $this->log = [];
        return $r;
    }

    protected function unsupported(string $what): array
    {
        return $this->result(false, false, $this->label() . ' does not ' . $what . '. Use a device that does, or the manual work-order flow.');
    }

    public function supports(string $op): bool
    {
        return true;
    }

    /** An HTTP request with cURL: [status, body, error] */
    protected function http(string $method, string $url, ?string $body = null, array $headers = [], int $timeout = 15): array
    {
        if (!function_exists('curl_init')) {
            return [0, '', 'The PHP cURL extension is not installed.'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => $this->o('verify_tls', '1') !== '0', CURLOPT_SSL_VERIFYHOST => $this->o('verify_tls', '1') !== '0' ? 2 : 0]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $err = $resp === false ? curl_error($ch) : '';
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $resp === false ? '' : (string)$resp, $err];
    }

    /** The rate limit of a plan as "upload/download" (MikroTik style, e.g. 10M/50M) */
    protected function rateLimit(array $plan): string
    {
        $fmt = fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.') . 'M';
        return $fmt($plan['upload_mbps']) . '/' . $fmt($plan['download_mbps']);
    }

    public function changePlan(array $acct, array $plan): array
    {
        return $this->provision($acct, $plan);
    }

    public function resetSession(array $acct): array
    {
        return $this->unsupported('reset sessions');
    }

    public function deprovision(array $acct): array
    {
        return $this->disable($acct);
    }
}
