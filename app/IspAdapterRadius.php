<?php
declare(strict_types=1);

/**
 * FreeRADIUS with its SQL module (rlm_sql): the standard tables radcheck, radreply, radusergroup and radacct, in the
 * RADIUS server's own database, reached with a database user of its own that may only write those tables (never the
 * database administrator of this system).
 *
 *   provision  radcheck Cleartext-Password, radusergroup = the plan's group (network profile), radreply the rate
 *              limit attribute (Mikrotik-Rate-Limit by default) and Framed-IP-Address for a static IP
 *   disable    radcheck Auth-Type := Reject (new logins are refused); the open session is closed by the NAS adapter
 *              named in nas_device_id (a MikroTik), if any; otherwise at the next re-authentication
 *   enable     the Reject row is removed
 *   status     the rows read back, online from radacct (a session with no stop time)
 * Options: database (the RADIUS database name), rate_attr (default Mikrotik-Rate-Limit; empty: none),
 * group_prefix, nas_device_id (a MikroTik device that closes sessions), charset.
 */
final class IspAdapterRadius extends IspAdapterBase
{
    private ?PDO $db = null;

    public function label(): string
    {
        return 'FreeRADIUS (SQL)';
    }

    private function db(): PDO
    {
        if ($this->db) {
            return $this->db;
        }
        $name = $this->o('database', 'radius');
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            throw new RuntimeException('Invalid RADIUS database name.');
        }
        $port = (int)($this->device['port'] ?: 3306);
        $this->db = new PDO('mysql:host=' . $this->device['host'] . ';port=' . $port . ';dbname=' . $name . ';charset=' . $this->o('charset', 'utf8'), (string)$this->device['username'], $this->secret(), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 8,
        ]);
        return $this->db;
    }

    private function q(string $sql, array $p = []): PDOStatement
    {
        $st = $this->db()->prepare($sql);
        $st->execute($p);
        return $st;
    }

    private function with(callable $fn): array
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            $this->note('radius', $e->getMessage(), false);
            return $this->result(false, false, 'RADIUS: ' . $e->getMessage());
        }
    }

    private function upsert(string $table, string $user, string $attr, string $op, string $value): void
    {
        $id = $this->q("SELECT id FROM `$table` WHERE username = ? AND attribute = ?", [$user, $attr])->fetchColumn();
        if ($id) {
            $this->q("UPDATE `$table` SET op = ?, value = ? WHERE id = ?", [$op, $value, $id]);
        } else {
            $this->q("INSERT INTO `$table` (username, attribute, op, value) VALUES (?, ?, ?, ?)", [$user, $attr, $op, $value]);
        }
        $this->note("$table $user $attr $op " . ($attr === 'Cleartext-Password' ? '********' : $value), 'ok', true);
    }

    public function test(): array
    {
        return $this->with(function () {
            $n = (int)$this->q('SELECT COUNT(*) FROM radcheck')->fetchColumn();
            return $this->result(true, true, 'Connected to the RADIUS database (' . $n . ' radcheck rows)');
        });
    }

    public function provision(array $acct, array $plan): array
    {
        return $this->with(function () use ($acct, $plan) {
            $user = (string)$acct['pppoe_username'];
            $this->upsert('radcheck', $user, 'Cleartext-Password', ':=', (string)(IspAccounts::pppoePassword($acct) ?? ''));
            $this->q("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type' AND value = 'Reject'", [$user]);
            $group = $this->o('group_prefix') . (trim((string)$plan['network_profile']) ?: $plan['code']);
            $this->q('DELETE FROM radusergroup WHERE username = ?', [$user]);
            $this->q('INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)', [$user, $group]);
            $this->note('radusergroup ' . $user . ' ' . $group, 'ok', true);
            $attr = $this->o('rate_attr', 'Mikrotik-Rate-Limit');
            if ($attr !== '') {
                $this->upsert('radreply', $user, $attr, ':=', $this->rateLimit($plan));
            }
            if (!empty($acct['static_ip'])) {
                $this->upsert('radreply', $user, 'Framed-IP-Address', ':=', (string)$acct['static_ip']);
            } else {
                $this->q("DELETE FROM radreply WHERE username = ? AND attribute = 'Framed-IP-Address'", [$user]);
            }
            $ok = (bool)$this->q("SELECT id FROM radcheck WHERE username = ? AND attribute = 'Cleartext-Password'", [$user])->fetchColumn();
            return $this->result(true, $ok, $ok ? 'RADIUS user ' . $user . ' in group ' . $group : 'The RADIUS user did not read back', $ok ? 'enabled' : 'unknown');
        });
    }

    public function enable(array $acct, array $plan): array
    {
        return $this->with(function () use ($acct, $plan) {
            $user = (string)$acct['pppoe_username'];
            if (!$this->q("SELECT id FROM radcheck WHERE username = ? AND attribute = 'Cleartext-Password'", [$user])->fetchColumn()) {
                return $this->provision($acct, $plan);
            }
            $this->q("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type' AND value = 'Reject'", [$user]);
            $this->note('radcheck ' . $user . ' remove Auth-Type Reject', 'ok', true);
            $left = $this->q("SELECT id FROM radcheck WHERE username = ? AND attribute = 'Auth-Type' AND value = 'Reject'", [$user])->fetchColumn();
            return $this->result(true, !$left, !$left ? $user . ' may log in again' : $user . ' is still rejected', !$left ? 'enabled' : 'disabled');
        });
    }

    public function disable(array $acct): array
    {
        $r = $this->with(function () use ($acct) {
            $user = (string)$acct['pppoe_username'];
            $this->upsert('radcheck', $user, 'Auth-Type', ':=', 'Reject');
            $ok = (bool)$this->q("SELECT id FROM radcheck WHERE username = ? AND attribute = 'Auth-Type' AND value = 'Reject'", [$user])->fetchColumn();
            return $this->result(true, $ok, $ok ? $user . ' rejected at login' : 'The reject did not read back', $ok ? 'disabled' : 'unknown');
        });
        // the open session: closed by the NAS (a MikroTik device), when one is named
        $nas = (int)$this->o('nas_device_id', '0');
        if ($r['ok'] && $nas && ($dev = IspNetwork::device($nas))) {
            $k = IspNetwork::adapter($dev)->resetSession($acct);
            $r['log'] = array_merge($r['log'], $k['log']);
            $r['message'] .= $k['ok'] ? '; session closed on ' . $dev['name'] : '; the session could not be closed on ' . $dev['name'] . ' (' . $k['message'] . ')';
        }
        return $r;
    }

    public function status(array $acct): array
    {
        return $this->with(function () use ($acct) {
            $user = (string)$acct['pppoe_username'];
            $has = $this->q("SELECT id FROM radcheck WHERE username = ? AND attribute = 'Cleartext-Password'", [$user])->fetchColumn();
            $rej = $this->q("SELECT id FROM radcheck WHERE username = ? AND attribute = 'Auth-Type' AND value = 'Reject'", [$user])->fetchColumn();
            $s = null;
            try {
                $s = $this->q('SELECT acctstarttime, framedipaddress, nasipaddress FROM radacct WHERE username = ? AND acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1', [$user])->fetch() ?: null;
            } catch (Throwable $e) {
                $s = null;
            }
            $state = !$has ? 'absent' : ($rej ? 'disabled' : 'enabled');
            return $this->result(true, true, 'RADIUS user ' . $state . ($s ? ', online since ' . $s['acctstarttime'] : ', no open session'), $state)
                + ['online' => (bool)$s, 'session' => $s ? $s['framedipaddress'] . ' via ' . $s['nasipaddress'] . ' since ' . $s['acctstarttime'] : ''];
        });
    }

    public function resetSession(array $acct): array
    {
        $nas = (int)$this->o('nas_device_id', '0');
        if ($nas && ($dev = IspNetwork::device($nas))) {
            return IspNetwork::adapter($dev)->resetSession($acct);
        }
        return $this->unsupported('close sessions by itself (name the NAS in nas_device_id)');
    }

    public function deprovision(array $acct): array
    {
        return $this->with(function () use ($acct) {
            $user = (string)$acct['pppoe_username'];
            foreach (['radcheck', 'radreply', 'radusergroup'] as $t) {
                $this->q("DELETE FROM `$t` WHERE username = ?", [$user]);
                $this->note("$t delete $user", 'ok', true);
            }
            return $this->result(true, true, 'RADIUS user ' . $user . ' removed', 'absent');
        });
    }
}
