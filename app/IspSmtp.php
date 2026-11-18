<?php
declare(strict_types=1);

/** A small SMTP client (STARTTLS or SSL, AUTH LOGIN) for the subscriber e-mails: no library needed */
final class IspSmtp
{
    /** @var resource|null */
    private $s = null;

    public function __construct(private string $host, private int $port, private string $secure, private string $user, private string $pass)
    {
    }

    private function read(): string
    {
        $out = '';
        while (($line = fgets($this->s, 515)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $out;
    }

    private function cmd(string $c, array $ok): string
    {
        fwrite($this->s, $c . "\r\n");
        $r = $this->read();
        if (!in_array((int)substr($r, 0, 3), $ok, true)) {
            throw new RuntimeException('SMTP ' . (str_starts_with($c, 'AUTH') || strlen($c) > 40 ? strtok($c, ' ') : $c) . ': ' . trim($r));
        }
        return $r;
    }

    public function send(string $from, string $fromName, string $to, string $subject, string $body): string
    {
        $remote = ($this->secure === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . ($this->port ?: 587);
        $this->s = @stream_socket_client($remote, $no, $str, 15);
        if (!$this->s) {
            throw new RuntimeException('SMTP server unreachable: ' . $str);
        }
        stream_set_timeout($this->s, 20);
        try {
            $this->read();
            $this->cmd('EHLO ' . (gethostname() ?: 'localhost'), [250]);
            if ($this->secure === 'tls') {
                $this->cmd('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($this->s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('SMTP: STARTTLS failed.');
                }
                $this->cmd('EHLO ' . (gethostname() ?: 'localhost'), [250]);
            }
            if ($this->user !== '') {
                $this->cmd('AUTH LOGIN', [334]);
                $this->cmd(base64_encode($this->user), [334]);
                $this->cmd(base64_encode($this->pass), [235]);
            }
            $this->cmd('MAIL FROM:<' . $from . '>', [250]);
            $this->cmd('RCPT TO:<' . $to . '>', [250, 251]);
            $this->cmd('DATA', [354]);
            $id = bin2hex(random_bytes(8)) . '@' . (substr(strrchr($from, '@'), 1) ?: 'localhost');
            $headers = 'Date: ' . date('r') . "\r\n" . 'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . ">\r\n" . 'To: <' . $to . ">\r\n"
                . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n" . 'Message-ID: <' . $id . ">\r\n" . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
            $data = $headers . "\r\n" . chunk_split(base64_encode($body)) . "\r\n.";
            $this->cmd($data, [250]);
            $this->cmd('QUIT', [221]);
            return $id;
        } finally {
            @fclose($this->s);
            $this->s = null;
        }
    }
}
