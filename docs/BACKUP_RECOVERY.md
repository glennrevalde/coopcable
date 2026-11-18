# Backup and recovery

What must be kept, for every company:

1. **The company database** (`gsmart_coop_cable`, `gsmart_coop_cable_co002`, ...): every record.
2. **The master database** `gsmart_coop_cable_master` when several companies run: the companies, their databases and
   codes, which company each username belongs to, the Super Admins.
3. **The encryption key**: `APP_KEY` (config / environment) or `storage/keys/app.key`. Without it the stored device,
   gateway, SMS and e-mail credentials and PPPoE passwords cannot be read after a restore (they would have to be typed
   in again; nothing else is affected).
4. **Uploaded files**: `storage/isp/` (application IDs, installation photos, payment proofs), `storage/company/`
   (logos), `storage/receipts/`.
5. `config/config.php` and `storage/platform/multi_company.json`.

## Built-in backups

* **System Administration > Backup & Restore**: makes a complete `.sql` backup on demand (written by PHP, no
  `mysqldump` needed), lists the backups, downloads them (the folder is never served directly) and restores one. A
  restore first makes a fresh backup of what is there, so it can be undone.
* The worker's **Backup** job makes one every night and keeps 30 days (ISP Setup > Scheduled Jobs Monitor).
* Backups are in `storage/backups/` (Company 1) and `storage/companies/c002/backups/` ... for the other companies
  (uploads likewise under `storage/companies/c002/isp/`).

Copy them off the server: a backup that lives only on the server it protects is lost with the server.

## Server-level backups (recommended in production)

```bash
# /etc/cron.d/gsmart-backup : every night at 01:30, 14 days kept
30 1 * * * root /usr/local/bin/gsmart-backup.sh
```

```bash
#!/bin/sh
# /usr/local/bin/gsmart-backup.sh
set -e
D=/var/backups/gsmart/$(date +%F)
mkdir -p "$D"
for db in $(mysql -N -e "SHOW DATABASES LIKE 'gsmart\_coop\_cable%'"); do
  mysqldump --single-transaction --routines --triggers "$db" | gzip > "$D/$db.sql.gz"
done
tar czf "$D/files.tgz" -C /var/www/gsmart-coop-cable storage config
find /var/backups/gsmart -maxdepth 1 -type d -mtime +14 -exec rm -rf {} +
# then copy $D to another machine or object storage (rclone, rsync, scp)
```

`--single-transaction` gives a consistent copy without stopping the program (InnoDB).

## Restoring

1. Stop the worker (comment out the cron line / disable the scheduled task) so nothing runs half-way.
2. Restore the databases:
   `gunzip < gsmart_coop_cable.sql.gz | mysql gsmart_coop_cable` (the same for the master and each company), or
   System Administration > Backup & Restore > Restore for one company.
3. Restore `storage/` (keys, uploads, platform flag) and `config/config.php`.
4. Log in, check the latest invoices, payments and the trial balance (**Subscriber Ledger vs General Ledger** report must
   show no difference), then re-enable the worker.

The audit trail cannot be changed or deleted in the database (triggers); a restore brings back the audit trail of the
backup and records the restore itself.

## Moving to another server

Install the program (see DEPLOY_UBUNTU.md or INSTALL_XAMPP.md), restore every database with the same names, copy
`storage/` and `config/config.php` (or set the same `APP_KEY`), and point the payment gateways' webhooks and the
router / RADIUS / OLT allow-lists to the new server.

## Disaster checklist

* Network devices keep their last state: subscribers already enabled stay online while the system is down.
* After a restore to an older point, run **Network & Service Control > Network Operations > Reconcile** so the device
  states and the restored records agree before the next disconnection run.
* Payments received by the gateway while the system was down are delivered again by the gateway (webhook retries);
  duplicates are refused by their event id.
