<?php
declare(strict_types=1);

/**
 * SMS, e-mail and in-app notifications to subscribers.
 *  - each message is rendered from its template (ISP Setup > Notifications) and kept in isp_notifications with a
 *    dedupe key (event, reference, account, channel): the same notice is never queued or sent twice, whatever reruns;
 *  - the subscriber's preferences decide the channels (SMS / e-mail); in-app messages show on the portal;
 *  - nothing is sent without a configured provider: such messages are logged as skipped, with the reason;
 *  - delivery is retried with a growing delay, up to five attempts, and every attempt is logged.
 */
final class IspNotify
{
    public const EVENTS = [
        'application_received' => 'Application received', 'application_approved' => 'Application approved', 'installation_schedule' => 'Installation schedule',
        'service_activated' => 'Service activation', 'invoice_generated' => 'Invoice generated', 'due_reminder' => 'Due-date reminder', 'payment_overdue' => 'Payment overdue',
        'final_notice' => 'Final disconnection warning', 'service_disconnected' => 'Service disconnected', 'payment_received' => 'Payment received',
        'service_restored' => 'Service restored', 'technician_dispatched' => 'Technician dispatched', 'ticket_resolved' => 'Ticket resolved', 'network_outage' => 'Network outage',
        'service_maintenance' => 'Service maintenance', 'contract_expiration' => 'Contract expiration', 'payment_pending' => 'Payment reference received',
    ];
    public const CHANNELS = ['sms' => 'SMS', 'email' => 'E-mail', 'inapp' => 'In-app (portal)'];
    private const MAX_ATTEMPTS = 5;

    /** Queues the notice of an event for an account, on each channel the subscriber accepts. Returns how many were queued. */
    public static function event(string $code, ?int $accountId, array $vars, string $ref, ?int $ispCustomerId = null): int
    {
        if (!IspSetup::ready()) {
            return 0;
        }
        $a = $accountId ? IspAccounts::get($accountId) : null;
        $c = $a ? IspAccounts::customer((int)$a['isp_customer_id']) : ($ispCustomerId ? IspAccounts::customer($ispCustomerId) : null);
        if (!$c) {
            return 0;
        }
        $base = ['name' => $c['name'], 'company' => Settings::get('company_name'), 'account_no' => $a['account_no'] ?? '', 'plan' => $a['plan_name'] ?? '',
            'customer_no' => $c['customer_no'], 'date' => fdate(today())];
        $vars = $vars + $base;
        $n = 0;
        $targets = ['inapp' => (string)$c['customer_no']];
        if ((int)$c['notify_sms'] === 1 && trim((string)$c['mobile']) !== '') {
            $targets['sms'] = self::normalizeMobile((string)$c['mobile']);
        }
        if ((int)$c['notify_email'] === 1 && filter_var((string)$c['email'], FILTER_VALIDATE_EMAIL)) {
            $targets['email'] = (string)$c['email'];
        }
        foreach ($targets as $ch => $to) {
            $tpl = DB::row('SELECT * FROM isp_notif_templates WHERE event_code = :e AND channel = :c AND is_active = 1', ['e' => $code, 'c' => $ch]);
            if (!$tpl) {
                continue;
            }
            // once per event, reference, recipient account and channel: one outage reference reaches every affected account
            $key = mb_substr($code . ':' . $ref . ':' . ($accountId ? 'A' . $accountId : 'C' . $c['id']) . ':' . $ch, 0, 120);
            if (DB::value('SELECT id FROM isp_notifications WHERE dedupe_key = :k', ['k' => $key])) {
                continue;
            }
            $body = self::render((string)$tpl['body'], $vars);
            $status = 'queued';
            $err = null;
            if ($ch === 'inapp') {
                $status = 'sent';
            } elseif (!self::configured($ch)) {
                $status = 'skipped';
                $err = 'No ' . self::CHANNELS[$ch] . ' provider is configured (ISP Setup > Notifications): not sent.';
            }
            try {
                DB::insert('isp_notifications', ['event_code' => $code, 'channel' => $ch, 'account_id' => $accountId, 'isp_customer_id' => (int)$c['id'], 'recipient' => mb_substr($to, 0, 160),
                    'subject' => $tpl['subject'] !== null ? mb_substr(self::render((string)$tpl['subject'], $vars), 0, 160) : null, 'body' => $body, 'dedupe_key' => $key,
                    'status' => $status, 'last_error' => $err, 'sent_at' => $status === 'sent' ? date('Y-m-d H:i:s') : null, 'next_try_at' => $status === 'queued' ? date('Y-m-d H:i:s') : null]);
                $n++;
            } catch (PDOException $e) {
                // the same key queued by a parallel process: nothing to do
                if ((int)($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
            }
        }
        return $n;
    }

    /** One notice to many accounts (an outage, a maintenance window) */
    public static function broadcast(string $code, array $accountIds, array $vars, string $ref): int
    {
        $n = 0;
        foreach ($accountIds as $id) {
            $n += self::event($code, (int)$id, $vars, $ref . '-' . $id);
        }
        return $n;
    }

    public static function render(string $tpl, array $vars): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', fn($m) => array_key_exists($m[1], $vars) ? (string)$vars[$m[1]] : $m[0], $tpl);
    }

    public static function normalizeMobile(string $m): string
    {
        $d = preg_replace('/\D/', '', $m);
        if (str_starts_with($d, '09') && strlen($d) === 11) {
            return '63' . substr($d, 1);
        }
        return $d;
    }

    public static function configured(string $ch): bool
    {
        if ($ch === 'sms') {
            $p = IspSetup::get('isp_sms_provider');
            return $p !== 'none' && ($p === 'semaphore' ? IspCrypto::decrypt(IspSetup::get('isp_sms_api_key_enc')) : IspSetup::get('isp_sms_api_url')) != '';
        }
        if ($ch === 'email') {
            return IspSetup::get('isp_smtp_host') !== '' && filter_var(IspSetup::get('isp_mail_from'), FILTER_VALIDATE_EMAIL) !== false;
        }
        return true;
    }

    /** The delivery job: queued messages whose time came. Returns how many were tried. */
    public static function deliver(int $limit = 100): int
    {
        $n = 0;
        foreach (DB::all("SELECT * FROM isp_notifications WHERE status = 'queued' AND (next_try_at IS NULL OR next_try_at <= :n) ORDER BY id LIMIT " . (int)$limit, ['n' => date('Y-m-d H:i:s')]) as $m) {
            // claim it (one sender per message)
            $claimed = DB::run("UPDATE isp_notifications SET next_try_at = :t WHERE id = :id AND status = 'queued'", ['t' => date('Y-m-d H:i:s', time() + 600), 'id' => $m['id']])->rowCount();
            if (!$claimed) {
                continue;
            }
            $n++;
            try {
                if (!self::configured($m['channel'])) {
                    DB::update('isp_notifications', ['status' => 'skipped', 'last_error' => 'No provider configured'], 'id = :id', ['id' => $m['id']]);
                    continue;
                }
                $ref = $m['channel'] === 'sms' ? self::sendSms((string)$m['recipient'], (string)$m['body']) : self::sendMail((string)$m['recipient'], (string)($m['subject'] ?? ''), (string)$m['body']);
                DB::update('isp_notifications', ['status' => 'sent', 'sent_at' => date('Y-m-d H:i:s'), 'attempts' => (int)$m['attempts'] + 1, 'provider_ref' => mb_substr($ref, 0, 120), 'last_error' => null],
                    'id = :id', ['id' => $m['id']]);
            } catch (Throwable $e) {
                $att = (int)$m['attempts'] + 1;
                DB::update('isp_notifications', ['status' => $att >= self::MAX_ATTEMPTS ? 'failed' : 'queued', 'attempts' => $att, 'last_error' => mb_substr($e->getMessage(), 0, 255),
                    'next_try_at' => date('Y-m-d H:i:s', time() + 300 * $att * $att)], 'id = :id', ['id' => $m['id']]);
            }
        }
        return $n;
    }

    public static function sendSms(string $to, string $text): string
    {
        $provider = IspSetup::get('isp_sms_provider');
        $key = (string)IspCrypto::decrypt(IspSetup::get('isp_sms_api_key_enc'));
        if ($provider === 'semaphore') {
            $body = http_build_query(['apikey' => $key, 'number' => $to, 'message' => $text, 'sendername' => IspSetup::get('isp_sms_sender')]);
            [$code, $resp, $err] = self::post(IspSetup::get('isp_sms_api_url') ?: 'https://api.semaphore.co/api/v4/messages', $body, ['Content-Type: application/x-www-form-urlencoded']);
        } else {
            $body = json_encode(['to' => $to, 'message' => $text, 'sender' => IspSetup::get('isp_sms_sender')]);
            [$code, $resp, $err] = self::post(IspSetup::get('isp_sms_api_url'), (string)$body, ['Content-Type: application/json', 'Authorization: Bearer ' . $key]);
        }
        if ($err !== '' || $code < 200 || $code >= 300) {
            throw new RuntimeException('SMS provider: ' . ($err ?: 'HTTP ' . $code . ' ' . mb_substr($resp, 0, 120)));
        }
        $j = json_decode($resp, true);
        return (string)($j[0]['message_id'] ?? $j['message_id'] ?? $j['id'] ?? 'HTTP ' . $code);
    }

    public static function sendMail(string $to, string $subject, string $body): string
    {
        $smtp = new IspSmtp(IspSetup::get('isp_smtp_host'), (int)IspSetup::get('isp_smtp_port'), IspSetup::get('isp_smtp_secure'),
            IspSetup::get('isp_smtp_user'), (string)IspCrypto::decrypt(IspSetup::get('isp_smtp_pass_enc')));
        return $smtp->send(IspSetup::get('isp_mail_from'), IspSetup::get('isp_mail_from_name') ?: Settings::get('company_name'), $to, $subject, $body);
    }

    private static function post(string $url, string $body, array $headers): array
    {
        if ($url === '' || !function_exists('curl_init')) {
            return [0, '', $url === '' ? 'No API URL' : 'The PHP cURL extension is not installed.'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $resp = curl_exec($ch);
        $err = $resp === false ? curl_error($ch) : '';
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $resp === false ? '' : (string)$resp, $err];
    }

    /** The in-app inbox of a subscriber (portal) */
    public static function inbox(int $ispCustomerId, int $limit = 30): array
    {
        return DB::all("SELECT * FROM isp_notifications WHERE isp_customer_id = :c AND channel = 'inapp' ORDER BY id DESC LIMIT " . (int)$limit, ['c' => $ispCustomerId]);
    }
}
