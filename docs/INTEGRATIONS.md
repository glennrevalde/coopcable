# Integrations and credentials

Every integration below is configured inside the program (no code changes). Credentials are entered in write-only
fields, stored encrypted (AES-256-GCM with the installation key: `APP_KEY` or `storage/keys/app.key`), shown masked,
never written to the audit trail or the command logs. Each integration has a test button; nothing is reported done
that the other side did not confirm.

Until a real network device is configured, the **integration simulator** is the default device. It sends nothing to
any network; every result it gives is stored and shown as **Simulated**, and the dashboard warns while it is in use.

---

## 1. Network devices (Network & Service Control > Network Devices & Integrations)

One device is the default; a branch may have its own. An account may be pinned to a device (its BNG / RADIUS). Each
device has: name, adapter, host, port, TLS, username, secret, options (one `key = value` per line), branch, active.

The plan's **network profile** (ISP Setup > Internet Plans & Packages) names the speed profile on the device: the
MikroTik PPP profile, the RADIUS group or the OLT service profile. The PPPoE username and password of each account are
on its page (Network tab); the password is generated and encrypted, revealed only to users with the network or
sensitive-data permission (each reveal is audited).

### MikroTik RouterOS (PPPoE server / BNG): adapter `mikrotik`

* Enable the API on the router: `/ip service set api disabled=no port=8728` (or `api-ssl` 8729 with a certificate)
  and allow only the billing server: `/ip service set api address=<server ip>/32`.
* Create a dedicated user with the `write`, `api`, `read` policies: `/user add name=gsmart group=full password=...`
  (or a custom group with `api,read,write,policy,test`).
* One PPP profile per plan with its rate limit, e.g. `/ppp profile add name=FIBER100 rate-limit=50M/100M local-address=10.0.0.1 remote-address=pool-100`;
  put `FIBER100` in the plan's network profile.
* Device fields: host = router IP, port 8728 (8729 with TLS), username, secret = the API password.
* Options:
  * `method = ppp_secret` (default): one `/ppp/secret` per subscriber; disconnection sets `disabled=yes` and removes
    the active session, reconnection sets `disabled=no`; every change is read back before it is confirmed.
  * `method = address_list`: static-IP subscribers; disconnection adds the IP to the address list `list` (default
    `isp-blocked`) that a firewall rule drops: `/ip firewall filter add chain=forward src-address-list=isp-blocked action=drop`.
  * `profile_default`, `local_address`, `list`, `timeout` (seconds), `verify_tls = 0` for a self-signed certificate.

### FreeRADIUS (SQL): adapter `radius`

* FreeRADIUS 3 with `rlm_sql` on MySQL / MariaDB (standard tables `radcheck`, `radreply`, `radusergroup`, `radacct`).
* Create a database user that may only write those tables:
  `GRANT SELECT, INSERT, UPDATE, DELETE ON radius.radcheck TO 'gsmart'@'billing-ip'` (and the same for `radreply`,
  `radusergroup`; `SELECT` on `radacct`).
* Device fields: host / port = the RADIUS database server, username / secret = that database user.
* Options: `database = radius`, `rate_attr = Mikrotik-Rate-Limit` (empty for none), `group_prefix`,
  `nas_device_id = <id of a MikroTik device>` so a disconnection also closes the open session (otherwise it ends at
  the next re-authentication), `charset`.
* Disconnection adds `Auth-Type := Reject` to `radcheck`; reconnection removes it. Status reads the rows back and the
  online state from `radacct`.

### OLT / ONU platform (HTTP API): adapter `olt_http`

OLT commands differ between vendors and models, so the adapter assumes none: you enter the URLs of your platform's
documented northbound API (the vendor's EMS / NMS or a local middleware). Placeholders `{serial}`, `{onu_id}`, `{port}`,
`{account_no}`, `{profile}`, `{down}`, `{up}`, `{secret}`, `{base}` are filled in per subscriber.

```
base_url       = https://ems.example.net/api
auth_header    = Authorization: Bearer {secret}
provision_url  = POST {base}/onu
provision_body = {"serial":"{serial}","profile":"{profile}","account":"{account_no}"}
enable_url     = POST {base}/onu/{serial}/activate
disable_url    = POST {base}/onu/{serial}/deactivate
status_url     = GET {base}/onu/{serial}
state_field    = admin_state
enabled_value  = enabled
online_field   = oper_state
online_value   = up
```

A 2xx answer is "accepted"; the adapter then reads the status back, and only a matching state is "confirmed".

### TR-069 ACS (GenieACS): adapter `tr069`

Status, last inform, reboot and parameters of the subscriber's CPE through the GenieACS northbound API (port 7557).
TR-069 does not authorize internet access, so it does not enable or disable subscribers: pair it with a MikroTik or
RADIUS device for that. Options: `base_url = http://acs.local:7557`, `serial_param = _deviceId._SerialNumber`,
`auth_header`.

### Manual: adapter `manual`

No device: each activation, disconnection or reconnection becomes a work order, confirmed only when the technician or
network administrator completes it.

### Simulator: adapter `simulator` (development and testing)

Answers like a device, marked simulated everywhere. Options for testing failures: `fail_accounts = 1000004, 1000007`,
`fail_ops = disable`, `fail_rate = 0.1`, `unconfirmed = 1`.

### Retries and failures

Each operation has an idempotency key and a per-account lock, is retried with a growing delay (ISP Setup > Billing &
Disconnection Policies: retry minutes; the operation's attempt limit), and after the last attempt opens a work order for
a technician. **Network Operations** lists every operation with its command log, retry and cancel buttons, and the
reconciliation of billing state against device state.

---

## 2. Payment gateways (ISP Setup > Payment Channels & Gateways)

Online payments are posted **only** from the gateway's authenticated webhook, once per gateway event id. The page the
customer returns to after paying never marks anything paid. Payment screenshots sent through the portal are pending
references that a collection officer verifies.

Webhook URL to register at the gateway (HTTPS):

```
https://your-domain/gsmart-coop-cable/api/gateway.php?provider=paymongo&co=<COMPANY CODE>
```

(`&co=` is needed when several companies run; the page shows the exact URL.)

| Provider | Secret key | Webhook verification | Account matching |
|---|---|---|---|
| PayMongo | secret key `sk_live_...` / `sk_test_...` (checkout sessions) | `Paymongo-Signature` header: HMAC-SHA256 of `timestamp.body` with the webhook secret `whsk_...` (test or live signature by the mode) | metadata `account_no`, else the account number in the description / reference |
| Xendit | secret API key (invoices) | `x-callback-token` header equal to the callback verification token | `external_id` = `<account no>-...` |
| Generic | (optional) | `X-Signature` header: hex HMAC-SHA256 of the raw body with the webhook secret | JSON `{event_id, status: "paid", account_no, amount, fee, reference}` |

Mode `test` / `live`, the fee percentage / fixed fee (posted to Payment Gateway Fees), and for the generic provider a
checkout URL template with `{account_no}`, `{amount}`, `{reference}`, `{return}`. Every webhook is kept in
`isp_gateway_events` (signature result, status, message); an event that names an unknown account is kept for a person
to apply.

GCash / Maya **without a gateway**: subscribers pay to the company's GCash / Maya account and submit the reference
number (and a screenshot) on the portal or at the counter; the payment is recorded as pending and posted when verified
against the e-wallet statement (Billing & Collection > Payments & Verification > Pending). A reference number can be
used only once.

---

## 3. SMS (ISP Setup > Notifications (SMS / E-mail))

* **Semaphore** (Philippines): API key, sender name (registered with Semaphore). URL default
  `https://api.semaphore.co/api/v4/messages`.
* **Generic HTTP**: any provider that accepts `POST` JSON `{to, message, sender}` with `Authorization: Bearer <key>`.
* Numbers are normalised to `63XXXXXXXXXX`. Each message is queued with a de-duplication key (the same notice is never
  sent twice), delivered by the worker, retried up to five times; the log shows each attempt.

## 4. E-mail (SMTP)

Host, port (587 TLS / 465 SSL / 25), security, username, password, from address and name. The program speaks SMTP
itself (`app/IspSmtp.php`), no PHP mail() set-up needed. Use an app password for Gmail / Microsoft 365.

## 5. Templates

ISP Setup > Templates & Announcements: one template per event and channel (SMS, e-mail, portal): application received, application approved, installation
schedule, service activation, invoice generated, due-date reminder, payment overdue, final disconnection warning,
service disconnected, payment received, payment reference received, service restored, technician dispatched, ticket
resolved, network outage, service maintenance, contract expiration. Variables are written in braces, e.g. `{name}`,
`{account_no}`, `{amount}`, `{due_date}`, `{invoice_no}`, `{company}`; the template page lists the ones each event has.

## 6. Security notes

* Keep the router API, RADIUS database and OLT / ACS APIs on a management network or VPN; allow only the billing server.
* Give each integration its own least-privilege account.
* Back up the installation key with the database (see BACKUP_RECOVERY.md).
* The sample database's demonstration secrets are encrypted with a published sample key (values starting `s1:`); they
  protect nothing and are only sample data. Everything entered on an installation is encrypted with its own key.
