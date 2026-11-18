<?php
declare(strict_types=1);

/**
 * A client of the MikroTik RouterOS API (TCP 8728, or 8729 with TLS), the documented binary protocol: every word is
 * sent with its length, a sentence ends with an empty word, replies are !re / !done / !trap / !fatal.
 * Login is the post-6.43 form (/login =name= =password=). Use a RouterOS user of its own with only the api, read and
 * write policies on the PPP and firewall menus, never the router's admin account, and keep the API service limited
 * to the management network ("available from" in IP > Services).
 */
final class IspRouterOsApi
{
    /** @var resource|null */
    private $sock = null;
    private int $timeout;

    public function __construct(int $timeout = 8)
    {
        $this->timeout = $timeout;
    }

    public function connect(string $host, int $port, bool $tls, string $user, string $pass, bool $verifyTls = true): void
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $verifyTls, 'verify_peer_name' => $verifyTls, 'allow_self_signed' => !$verifyTls]]);
        $uri = ($tls ? 'tls://' : 'tcp://') . $host . ':' . $port;
        $errNo = 0;
        $errStr = '';
        $s = @stream_socket_client($uri, $errNo, $errStr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) {
            throw new RuntimeException('RouterOS API ' . $host . ':' . $port . ' unreachable: ' . ($errStr ?: 'connection failed'));
        }
        stream_set_timeout($s, $this->timeout);
        $this->sock = $s;
        $r = $this->talk(['/login', '=name=' . $user, '=password=' . $pass]);
        if ($this->trap($r)) {
            throw new RuntimeException('RouterOS login refused: ' . $this->trap($r));
        }
    }

    public function close(): void
    {
        if ($this->sock) {
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    /** Sends one sentence and reads to !done: a list of [type, attributes] */
    public function talk(array $words): array
    {
        if (!$this->sock) {
            throw new RuntimeException('RouterOS API is not connected.');
        }
        foreach ($words as $w) {
            $this->writeWord((string)$w);
        }
        $this->writeWord('');
        $out = [];
        while (true) {
            $sentence = $this->readSentence();
            if (!$sentence) {
                throw new RuntimeException('RouterOS API: the connection closed.');
            }
            $type = array_shift($sentence);
            $attrs = [];
            foreach ($sentence as $w) {
                if (preg_match('/^=([^=]*)=(.*)$/s', $w, $m)) {
                    $attrs[$m[1]] = $m[2];
                } elseif (str_starts_with($w, '.tag=')) {
                    $attrs['.tag'] = substr($w, 5);
                }
            }
            $out[] = [$type, $attrs];
            if ($type === '!done' || $type === '!fatal') {
                break;
            }
        }
        return $out;
    }

    /** The message of a !trap / !fatal reply, else '' */
    public function trap(array $reply): string
    {
        foreach ($reply as [$type, $a]) {
            if ($type === '!trap' || $type === '!fatal') {
                return (string)($a['message'] ?? 'error');
            }
        }
        return '';
    }

    /** The !re rows of a print */
    public function rows(array $reply): array
    {
        return array_values(array_map(fn($r) => $r[1], array_filter($reply, fn($r) => $r[0] === '!re')));
    }

    private function writeWord(string $w): void
    {
        $len = strlen($w);
        if ($len < 0x80) {
            $h = chr($len);
        } elseif ($len < 0x4000) {
            $len |= 0x8000;
            $h = chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        } elseif ($len < 0x200000) {
            $len |= 0xC00000;
            $h = chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        } elseif ($len < 0x10000000) {
            $len |= 0xE0000000;
            $h = chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        } else {
            $h = chr(0xF0) . chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        }
        if (@fwrite($this->sock, $h . $w) === false) {
            throw new RuntimeException('RouterOS API: write failed.');
        }
    }

    private function readByte(): int
    {
        $c = @fread($this->sock, 1);
        if ($c === false || $c === '') {
            $meta = stream_get_meta_data($this->sock);
            throw new RuntimeException('RouterOS API: ' . (!empty($meta['timed_out']) ? 'no answer (timeout)' : 'connection lost'));
        }
        return ord($c);
    }

    private function readLength(): int
    {
        $b = $this->readByte();
        if (($b & 0x80) === 0) {
            return $b;
        }
        if (($b & 0xC0) === 0x80) {
            return (($b & 0x3F) << 8) | $this->readByte();
        }
        if (($b & 0xE0) === 0xC0) {
            return (($b & 0x1F) << 16) | ($this->readByte() << 8) | $this->readByte();
        }
        if (($b & 0xF0) === 0xE0) {
            return (($b & 0x0F) << 24) | ($this->readByte() << 16) | ($this->readByte() << 8) | $this->readByte();
        }
        return ($this->readByte() << 24) | ($this->readByte() << 16) | ($this->readByte() << 8) | $this->readByte();
    }

    private function readSentence(): array
    {
        $words = [];
        while (true) {
            $len = $this->readLength();
            if ($len === 0) {
                return $words;
            }
            $w = '';
            while (strlen($w) < $len) {
                $chunk = @fread($this->sock, $len - strlen($w));
                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('RouterOS API: connection lost while reading.');
                }
                $w .= $chunk;
            }
            $words[] = $w;
        }
    }
}
