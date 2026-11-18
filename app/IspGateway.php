<?php
declare(strict_types=1);

/**
 * Online payment gateways. A payment is posted only from an AUTHENTICATED webhook of the provider (its signature or
 * token checked against the secret kept encrypted here), once per provider event id. The page a customer returns to
 * after paying never marks anything paid; neither does a screenshot (that is a pending reference for a person to verify).
 *
 *   paymongo  header Paymongo-Signature: t=<time>,te=<test sig>,li=<live sig>; HMAC-SHA256 of "<t>.<body>" with the
 *             webhook secret; events payment.paid / checkout_session.payment.paid; the account number travels in the
 *             metadata (account_no) or the reference / description; amounts in centavos
 *   xendit    header x-callback-token equal to the verification token; invoice callbacks with status PAID, external_id =
 *             "<account_no>-<anything>", amount, fees_paid_amount
 *   generic   header X-Signature: hex HMAC-SHA256 of the raw body with the webhook secret; JSON {event_id, status: "paid",
 *             account_no, amount, fee, reference}
 * The webhook URL is api/gateway.php?provider=paymongo (with &co=<company code> when several companies run).
 */
final class IspGateway
{
    public const PROVIDERS = ['none' => 'None (no online payment)', 'paymongo' => 'PayMongo', 'xendit' => 'Xendit', 'generic' => 'Other (generic signed webhook)'];

    /** Verifies and processes one webhook. Returns [http status, message]. */
    public static function handle(string $provider, string $raw, array $headers, string $ip): array
    {
        $provider = strtolower($provider);
        if (!isset(self::PROVIDERS[$provider]) || $provider === 'none') {
            return [404, 'Unknown provider'];
        }
        if ($provider !== IspSetup::get('isp_gateway_provider')) {
            return [409, 'This provider is not the one configured'];
        }
        $h = array_change_key_case($headers, CASE_LOWER);
        $secret = (string)IspCrypto::decrypt(IspSetup::get('isp_gateway_webhook_secret_enc'));
        $ok = $secret !== '' && self::verify($provider, $raw, $h, $secret);
        $j = json_decode($raw, true);
        $ev = is_array($j) ? self::parse($provider, $j) : null;
        $eventId = (string)(($ev['event_id'] ?? '') !== '' ? $ev['event_id'] : 'sha-' . hash('sha256', $raw));
        $row = ['provider' => $provider, 'event_id' => mb_substr($eventId, 0, 120), 'event_type' => mb_substr((string)($ev['type'] ?? ''), 0, 60), 'signature_ok' => $ok ? 1 : 0,
            'account_no' => isset($ev['account_no']) ? mb_substr((string)$ev['account_no'], 0, 30) : null, 'amount' => $ev['amount'] ?? null, 'fee' => $ev['fee'] ?? null,
            'reference_no' => isset($ev['reference']) ? mb_substr((string)$ev['reference'], 0, 80) : null, 'status' => 'received', 'payload' => mb_substr($raw, 0, 60000), 'remote_ip' => $ip];
        if (!$ok) {
            // a notice that fails the check never takes the event id: a forged notice sent first cannot block the
            // provider's real one (the id is unique); it is kept, shortened, for the log only
            $row['event_id'] = mb_substr('unverified:' . bin2hex(random_bytes(4)) . ':' . $eventId, 0, 120);
            $row['payload'] = mb_substr($raw, 0, 4000);
            DB::insert('isp_gateway_events', ['status' => 'rejected', 'message' => 'Signature / token check failed'] + $row);
            return [401, 'Signature check failed'];
        }
        if (DB::value('SELECT id FROM isp_gateway_events WHERE provider = :p AND event_id = :e', ['p' => $provider, 'e' => $row['event_id']])) {
            return [200, 'Duplicate event ignored'];
        }
        try {
            $id = DB::insert('isp_gateway_events', $row);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                return [200, 'Duplicate event ignored'];
            }
            throw $e;
        }
        if (!$ev || empty($ev['paid'])) {
            DB::update('isp_gateway_events', ['status' => 'ignored', 'message' => 'Not a successful payment event'], 'id = :id', ['id' => $id]);
            return [200, 'Ignored'];
        }
        $acct = $ev['account_no'] ? IspAccounts::byNo((string)$ev['account_no']) : null;
        if (!$acct || (float)$ev['amount'] <= 0) {
            DB::update('isp_gateway_events', ['status' => 'failed', 'message' => 'Account ' . ($ev['account_no'] ?? '?') . ' not found or no amount: kept for a person to apply'], 'id = :id', ['id' => $id]);
            return [200, 'Recorded for review'];
        }
        // the fee the provider reports in the event; when it reports none, the fee set on ISP Setup > Payment Channels & Gateways
        $fee = (float)($ev['fee'] ?? 0);
        if ($fee <= 0) {
            $fee = r2((float)$ev['amount'] * IspSetup::float('isp_gateway_fee_pct') / 100 + IspSetup::float('isp_gateway_fee_fixed'));
        }
        try {
            $pid = IspSetup::asSystem(fn() => IspPayments::collect((int)$acct['id'], (float)$ev['amount'], 'gateway', ['source' => 'gateway', 'reference_no' => $ev['reference'] ?: $eventId,
                'gateway_event_id' => $id, 'gateway_fee' => $fee, 'notes' => self::PROVIDERS[$provider] . ' ' . ($ev['type'] ?? ''), 'page_key' => 'ispbill.payments']));
            DB::update('isp_gateway_events', ['status' => 'posted', 'payment_id' => $pid, 'message' => 'Posted'], 'id = :id', ['id' => $id]);
            return [200, 'Posted'];
        } catch (UserError $e) {
            DB::update('isp_gateway_events', ['status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 255)], 'id = :id', ['id' => $id]);
            return [200, 'Recorded: ' . $e->getMessage()];
        }
    }

    public static function verify(string $provider, string $raw, array $h, string $secret): bool
    {
        return match ($provider) {
            'paymongo' => (function () use ($raw, $h, $secret) {
                $sig = (string)($h['paymongo-signature'] ?? '');
                $parts = [];
                foreach (explode(',', $sig) as $kv) {
                    [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
                    $parts[$k] = $v;
                }
                if (empty($parts['t'])) {
                    return false;
                }
                $calc = hash_hmac('sha256', $parts['t'] . '.' . $raw, $secret);
                $given = IspSetup::get('isp_gateway_mode') === 'live' ? ($parts['li'] ?? '') : ($parts['te'] ?? '');
                return $given !== '' && hash_equals($calc, $given) && abs(time() - (int)$parts['t']) < 86400;
            })(),
            'xendit' => hash_equals($secret, (string)($h['x-callback-token'] ?? '')),
            'generic' => hash_equals(hash_hmac('sha256', $raw, $secret), strtolower((string)($h['x-signature'] ?? ''))),
            default => false,
        };
    }

    /** The provider's event in one shape: event_id, type, paid, account_no, amount, fee, reference */
    public static function parse(string $provider, array $j): ?array
    {
        if ($provider === 'paymongo') {
            $attr = $j['data']['attributes'] ?? [];
            $type = (string)($attr['type'] ?? '');
            $obj = $attr['data']['attributes'] ?? [];
            $pay = $obj['payments'][0]['attributes'] ?? $obj;
            $meta = $obj['metadata'] ?? ($pay['metadata'] ?? []);
            $acct = $meta['account_no'] ?? null;
            if (!$acct && preg_match('/\b(\d{6,})\b/', (string)($obj['description'] ?? $obj['reference_number'] ?? ''), $m)) {
                $acct = $m[1];
            }
            return ['event_id' => (string)($j['data']['id'] ?? ''), 'type' => $type, 'paid' => in_array($type, ['payment.paid', 'checkout_session.payment.paid'], true),
                'account_no' => $acct, 'amount' => isset($pay['amount']) ? r2((int)$pay['amount'] / 100) : null, 'fee' => isset($pay['fee']) ? r2((int)$pay['fee'] / 100) : 0,
                'reference' => (string)($pay['external_reference_number'] ?? $obj['reference_number'] ?? ($attr['data']['id'] ?? ''))];
        }
        if ($provider === 'xendit') {
            $ext = (string)($j['external_id'] ?? '');
            return ['event_id' => (string)($j['id'] ?? $ext), 'type' => 'invoice.' . strtolower((string)($j['status'] ?? '')), 'paid' => strtoupper((string)($j['status'] ?? '')) === 'PAID',
                'account_no' => explode('-', $ext)[0] ?: null, 'amount' => isset($j['paid_amount']) ? r2((float)$j['paid_amount']) : (isset($j['amount']) ? r2((float)$j['amount']) : null),
                'fee' => r2((float)($j['fees_paid_amount'] ?? 0)), 'reference' => (string)($j['payment_id'] ?? $j['id'] ?? $ext)];
        }
        return ['event_id' => (string)($j['event_id'] ?? ''), 'type' => (string)($j['type'] ?? 'payment'), 'paid' => strtolower((string)($j['status'] ?? '')) === 'paid',
            'account_no' => isset($j['account_no']) ? (string)$j['account_no'] : null, 'amount' => isset($j['amount']) ? r2((float)$j['amount']) : null,
            'fee' => r2((float)($j['fee'] ?? 0)), 'reference' => (string)($j['reference'] ?? $j['event_id'] ?? '')];
    }

    /** Is online payment offered on the portal? */
    public static function enabled(): bool
    {
        return IspSetup::get('isp_gateway_provider') !== 'none' && IspCrypto::decrypt(IspSetup::get('isp_gateway_secret_enc')) !== null;
    }

    /**
     * A checkout for an account and amount: PayMongo checkout session (API), Xendit invoice (API), or the generic
     * checkout URL template. Returns the URL the customer is sent to.
     */
    public static function checkout(array $acct, float $amount, string $returnUrl): string
    {
        $provider = IspSetup::get('isp_gateway_provider');
        $secret = (string)IspCrypto::decrypt(IspSetup::get('isp_gateway_secret_enc'));
        $ref = $acct['account_no'] . '-' . date('YmdHis');
        if ($provider === 'paymongo') {
            $body = json_encode(['data' => ['attributes' => ['line_items' => [['name' => 'Account ' . $acct['account_no'], 'amount' => (int)round($amount * 100), 'currency' => 'PHP', 'quantity' => 1]],
                'payment_method_types' => ['gcash', 'paymaya', 'card', 'grab_pay'], 'description' => 'Payment for account ' . $acct['account_no'], 'reference_number' => $ref,
                'metadata' => ['account_no' => $acct['account_no']], 'success_url' => $returnUrl, 'cancel_url' => $returnUrl]]]);
            [$code, $resp, $err] = self::post('https://api.paymongo.com/v1/checkout_sessions', (string)$body, ['Content-Type: application/json', 'Authorization: Basic ' . base64_encode($secret . ':')]);
            $j = json_decode($resp, true);
            if ($err === '' && $code >= 200 && $code < 300 && !empty($j['data']['attributes']['checkout_url'])) {
                return (string)$j['data']['attributes']['checkout_url'];
            }
            fail('The payment gateway did not open a checkout' . ($err ? ': ' . $err : ' (HTTP ' . $code . ')') . '. Please pay at our office or by GCash / bank transfer.');
        }
        if ($provider === 'xendit') {
            $body = json_encode(['external_id' => $ref, 'amount' => r2($amount), 'description' => 'Payment for account ' . $acct['account_no'], 'success_redirect_url' => $returnUrl, 'currency' => 'PHP']);
            [$code, $resp, $err] = self::post('https://api.xendit.co/v2/invoices', (string)$body, ['Content-Type: application/json', 'Authorization: Basic ' . base64_encode($secret . ':')]);
            $j = json_decode($resp, true);
            if ($err === '' && $code >= 200 && $code < 300 && !empty($j['invoice_url'])) {
                return (string)$j['invoice_url'];
            }
            fail('The payment gateway did not open a checkout. Please pay at our office or by GCash / bank transfer.');
        }
        $tpl = IspSetup::get('isp_gateway_checkout_url');
        if ($tpl === '') {
            fail('Online payment is not set up.');
        }
        return strtr($tpl, ['{account_no}' => rawurlencode((string)$acct['account_no']), '{amount}' => number_format($amount, 2, '.', ''), '{reference}' => rawurlencode($ref), '{return}' => rawurlencode($returnUrl)]);
    }

    private static function post(string $url, string $body, array $headers): array
    {
        if (!function_exists('curl_init')) {
            return [0, '', 'The PHP cURL extension is not installed.'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $resp = curl_exec($ch);
        $err = $resp === false ? curl_error($ch) : '';
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $resp === false ? '' : (string)$resp, $err];
    }
}
