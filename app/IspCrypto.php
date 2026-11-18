<?php
declare(strict_types=1);

/**
 * Encryption of the secrets the ISP module keeps: PPPoE passwords, network device and RADIUS credentials, SMS / SMTP
 * passwords and payment gateway keys. AES-256-GCM (authenticated), one random IV per value.
 *
 * The key comes from config/config.php ('app' => ['key' => ...], or the APP_KEY environment variable) or, when none
 * is set, from storage/keys/app.key, made on first use (32 random bytes). Back the key up with the database: without
 * it the stored secrets cannot be read (they are typed in again).
 */
final class IspCrypto
{
    private const PREFIX = 'v1:';
    /**
     * The values of the SAMPLE database (tools/isp_demo.php) are encrypted with a published sample key, marked "s1:", so
     * the sample data reads the same on every installation. It protects nothing and is never used for real secrets:
     * everything entered on an installation is encrypted with that installation's own private key ("v1:").
     */
    private const SAMPLE_PREFIX = 's1:';
    public static bool $sample = false;
    private static ?string $key = null;

    private static function sampleKey(): string
    {
        return hash('sha256', 'G-SMaRT Coop Cable System sample data - a public key, never for real secrets', true);
    }

    /** True when the stored value was encrypted with the public sample key (sample data) */
    public static function isSample(?string $stored): bool
    {
        return $stored !== null && str_starts_with($stored, self::SAMPLE_PREFIX);
    }

    private static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $cfg = trim((string)($GLOBALS['CONFIG']['app']['key'] ?? ''));
        if ($cfg !== '') {
            $raw = str_starts_with($cfg, 'base64:') ? base64_decode(substr($cfg, 7), true) : (ctype_xdigit($cfg) && strlen($cfg) === 64 ? hex2bin($cfg) : hash('sha256', $cfg, true));
            if ($raw === false || strlen($raw) !== 32) {
                $raw = hash('sha256', $cfg, true);
            }
            return self::$key = $raw;
        }
        $dir = APP_ROOT . '/storage/keys';
        $file = $dir . '/app.key';
        if (is_file($file)) {
            $raw = base64_decode(trim((string)file_get_contents($file)), true);
            if ($raw !== false && strlen($raw) === 32) {
                return self::$key = $raw;
            }
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        Master::protectDir($dir);
        $raw = random_bytes(32);
        if (@file_put_contents($file, base64_encode($raw)) === false) {
            fail('storage/keys/app.key could not be written. Make the storage folder writable, or set APP_KEY in config/config.php.');
        }
        @chmod($file, 0600);
        return self::$key = $raw;
    }

    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::$sample ? self::sampleKey() : self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            fail('The value could not be encrypted (OpenSSL).');
        }
        return (self::$sample ? self::SAMPLE_PREFIX : self::PREFIX) . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        $sample = str_starts_with($stored, self::SAMPLE_PREFIX);
        if (!$sample && !str_starts_with($stored, self::PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $sample ? self::sampleKey() : self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }

    /** "••••••ab" for a screen that may only show that a secret is set */
    public static function masked(?string $stored): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }
        $p = self::decrypt($stored);
        if ($p === null) {
            return 'set (cannot be read with this key)';
        }
        return str_repeat('•', max(4, min(10, strlen($p) - 2))) . (strlen($p) > 6 ? substr($p, -2) : '');
    }

    /** A readable random password (PPPoE, portal) */
    public static function password(int $len = 10): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $out;
    }
}
