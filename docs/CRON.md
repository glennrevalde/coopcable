# Scheduled jobs (the worker)

Recurring billing, overdue marking, late charges, disconnection evaluation and execution, reconnection, network
operation retries and reconciliation, notifications, contract notices, payment-arrangement follow-up, ticket
escalation, outage follow-up and the nightly backup are done by **one worker**, `cron/isp_worker.php`. The program never
needs a person to remember them, but they only happen when the worker runs.

The worker decides itself which jobs are due (each job has its interval or hour on **ISP Setup > Scheduled Jobs
Monitor**, where they can be paused, run by hand and their history read). Every job takes a database lock, so two
overlapping runs never process the same records twice, and every job is idempotent: running it again does not bill,
charge, notify or disconnect twice. A failed job is retried on its next turn and shown in red on the monitor.

With several companies one worker serves every active company (or `--company=N` for one).

## Linux (cron)

```bash
sudo crontab -u www-data -e
```

```cron
# G-SMaRT Coop Cable System: the cable / internet worker every 5 minutes
*/5 * * * * DB_USER=gsmart DB_PASS='a-long-random-password' APP_KEY='base64:...' /usr/bin/php /var/www/gsmart-coop-cable/cron/isp_worker.php >> /var/log/gsmart/isp_worker.log 2>&1
# memorized reports that are e-mailed on a schedule, once a day
0 6 * * * DB_USER=gsmart DB_PASS='a-long-random-password' /usr/bin/php /var/www/gsmart-coop-cable/tools/send_scheduled_reports.php >> /var/log/gsmart/reports.log 2>&1
```

```bash
sudo mkdir -p /var/log/gsmart && sudo chown www-data /var/log/gsmart
```

Run it as the web server's user (`www-data`) so the files it writes in `storage/` (backups, keys) stay readable by
the program. Pass the same `APP_KEY` the web server uses (or rely on `storage/keys/app.key`), otherwise the worker
cannot read device credentials.

## Windows (XAMPP): Task Scheduler

1. Task Scheduler > Create Task. Name: `G-SMaRT ISP worker`. Run whether user is logged on or not.
2. Triggers > New: Daily, repeat task every **5 minutes** for a duration of **Indefinitely**.
3. Actions > New: Program `C:\xampp\php\php.exe`, arguments
   `C:\xampp\htdocs\gsmart-coop-cable\cron\isp_worker.php`, start in `C:\xampp\htdocs\gsmart-coop-cable`.
4. Settings: "Do not start a new instance" if the task is already running.

Or from an administrator command prompt:

```bat
schtasks /Create /TN "G-SMaRT ISP worker" /SC MINUTE /MO 5 /TR "C:\xampp\php\php.exe C:\xampp\htdocs\gsmart-coop-cable\cron\isp_worker.php" /RU SYSTEM
schtasks /Create /TN "G-SMaRT scheduled reports" /SC DAILY /ST 06:00 /TR "C:\xampp\php\php.exe C:\xampp\htdocs\gsmart-coop-cable\tools\send_scheduled_reports.php" /RU SYSTEM
```

## Running by hand

```bash
php cron/isp_worker.php                 # every due job of every active company
php cron/isp_worker.php --company=2     # one company
```

Each job prints one line (processed records, status). A job can also be run from **Scheduled Jobs Monitor > Run now**
(the same code, recorded with the user's name).

## The jobs

| Job | What it does |
|---|---|
| Daily invoice generation | Generates the invoices of the accounts due on their billing day (a draft run; posted at once when billing runs need no approval). Never bills a period twice. |
| Billing due-date checks | Marks unpaid invoices overdue after their due date (hourly). |
| Late-charge assessment | Charges the plan's late fee once per overdue invoice. |
| Payment reminders | Queues the due-date reminders before the due date and the overdue reminders after it (once per invoice). |
| Disconnection eligibility evaluation | Builds the disconnection queue from the rules (grace days, minimum overdue amount and invoices), skipping protected accounts (open dispute, payment arrangement, exemption, payment received), and sends the final notices. |
| Disconnection execution | Executes approved disconnections inside the rule's days and hours, re-checking the balance under a lock first. |
| Reconnection processing | Requests and executes the reconnection of accounts that qualify after payment. |
| Network operation retries | Sends queued network operations and retries failed ones with back-off; a failed final attempt opens a work order. |
| Network reconciliation | Compares the expected subscriber state with the device state and records the differences. |
| Expired temporary reconnections | Disconnects again the temporary reconnections that expired unpaid. |
| Contract expiration notices | Notifies subscribers of contracts ending within 30 days and marks expired ones. |
| Equipment maintenance reminders | Lists infrastructure due for maintenance and equipment pending recovery. |
| Overdue support-ticket alerts | Escalates tickets past their SLA deadline. |
| Daily financial summary | Writes the day's billing and collection totals to the job log. |
| Database backup | Nightly full database backup to `storage/backups` (30 days kept). |
| Notification delivery and retries | Sends queued SMS / e-mail and retries failed ones within their limit. |
| Payment arrangement follow-up | Marks missed installments and ends defaulted arrangements. |

The savings auto-debit of members who chose it runs with the reconnection processing: it pays the due invoices from
the chosen savings account (through the cooperative's savings ledger), and a disconnected member who becomes paid up is
reconnected in the same pass.
