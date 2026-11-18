# Installing on Windows with XAMPP (Apache + MySQL / MariaDB)

This is the quickest way to run the G-SMaRT Coop Cable System on one office PC or a small office server.

## 1. What you need

| | |
|---|---|
| XAMPP | Any current XAMPP for Windows (PHP 8.1 or newer). PHP 8.2 or 8.3 recommended. |
| PHP extensions | `pdo_mysql`, `mbstring`, `openssl`, `curl` (all on in XAMPP). `bcmath` is optional (faster exact decimals). |
| Database | MySQL 5.5.3 or newer, or MariaDB 5.5 or newer. The SQL files are written for MySQL 5.5. |
| Browser | Any current browser. Every stylesheet, script and icon is inside the program folder; nothing is loaded from the internet. |

PHP and MySQL versions are separate: the database can be as old as MySQL 5.5, but the program needs PHP 8.1+. Old XAMPP
packages that bundle MySQL 5.5 also bundle PHP 5 and will not run the program; point a PHP 8 install at your MySQL 5.5
server instead (`config/config.php`).

## 2. Copy the program

1. Unzip `GSMaRT_Coop_Cable_System.zip` into `C:\xampp\htdocs\` so that you have
   `C:\xampp\htdocs\gsmart-coop-cable\index.php`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.

## 3. Create the database (pick one file)

Open <http://localhost/phpmyadmin>, choose **Import**, pick one file from `gsmart-coop-cable\database\` and click **Go**
(or from a command prompt: `C:\xampp\mysql\bin\mysql -u root -p < gsmart_coop_cable_clean.sql`):

| File | What it holds |
|---|---|
| `gsmart_coop_cable_clean.sql` | Clean data only: the complete structure and set-up (chart of accounts with the cable / internet accounts, tax codes, roles and page access, the cooperative set-up lists, the cable / internet set-up: disconnection rules, message templates, scheduled jobs, contract template), one user per role, and the Super Admin. No customers, members, plans, subscribers or transactions. Use it to start real operations. |
| `gsmart_coop_cable_sample.sql` | Everything in the clean file plus sample data in every module: accounting transactions, a sample cooperative (branches, members, savings, loans), and a sample cable / internet operation (service areas, plans, fiber network, technicians, about 70 subscribers with six months of billing, collections, disconnections and reconnections, tickets, outages, inventory). Use it for training and evaluation. |

Each file creates **two databases**: `gsmart_coop_cable` (Company 1) and `gsmart_coop_cable_master` (the platform: Super
Admin, companies, Company Codes). Importing drops and recreates every table in them, so never import over a database in
use. The two files cannot be loaded side by side under the same names: import one.

Importing needs a MySQL user with CREATE, INSERT, REFERENCES and TRIGGER rights (root on XAMPP). If phpMyAdmin stops
with "max_allowed_packet" or a time-out on the sample file, import it from the command prompt instead.

## 4. Settings

`config/config.php`:

* `db.host`, `db.port`, `db.user`, `db.pass`: your MySQL server. XAMPP's root user has an empty password out of the box:
  set `'pass' => ''` if you have not given it one.
* `db.name` stays `gsmart_coop_cable` (the platform knows Company 1 by this name).
* `app.key`: the key that encrypts network-device, RADIUS, SMS, e-mail and payment-gateway credentials and PPPoE
  passwords (AES-256-GCM). Leave it empty and the program creates `storage/keys/app.key` on first use; **back that file
  up with the database**, without it the stored credentials cannot be read and must be typed in again.
* `app.timezone` is `Asia/Manila`.

`storage/` must be writable by Apache (it is on Windows). `storage/platform/multi_company.json` switches the
multi-company platform on; keep it.

## 5. Log in

* Staff: <http://localhost/gsmart-coop-cable/> with one of the accounts in [USER_ACCOUNTS.md](USER_ACCOUNTS.md).
* Super Admin (adds companies, Company Codes, updates every company database):
  <http://localhost/gsmart-coop-cable/> with the Super Admin username; the same log-in page sends it to the platform.
* Subscribers: <http://localhost/gsmart-coop-cable/portal/?co=COMPANY-CODE> (the Company Code is on the platform's
  Companies page and on Company > Company Profile & Code).

Change every password after the first log-in. The clean database asks for a new password at the first log-in of each
account.

## 6. First set-up of a real operation (clean database)

1. **Company > Company Profile & Code**: name, address, TIN, logo.
2. **Financial > Chart of Accounts > Module Default Accounts > Cable & Internet**: check the receivable, revenue, cash,
   clearing, deposit, discount, gateway-fee, write-off and commission accounts.
3. **Members & Others > Branches** (cooperative branches double as service branches) and **ISP Setup > Service Areas**
   (province, municipality, barangay, purok) with the serviceable flag.
4. **ISP Setup > Internet Plans & Packages**: speeds, monthly fee and member fee, VAT mode, installation fee, contract and
   lock-in, early-termination fee, late charge, reconnection fee, promo, area eligibility.
5. **ISP Setup > Billing & Disconnection Policies**: VAT registration, BIR ATP / serial range for the service invoice, billing
   cycle day, due days, advance or arrears billing, grace days and the disconnection rules, SLA hours, portal options.
6. **Network & Service Control > Network Devices & Integrations**: add the MikroTik / RADIUS / OLT / TR-069 device (see
   [INTEGRATIONS.md](INTEGRATIONS.md)) and **Test connection**. Until a real device is set as default, the built-in
   simulator answers and every result says "Simulated".
7. **ISP Setup > Notifications (SMS / E-mail)** and **Payment Channels & Gateways**, if used.
8. **Infrastructure & Ports**: OLT, PON cards, splitters, NAPs and their ports; **Field Service > Technicians**.
9. **Inventory > Item List / Sites**: the network stockroom, ONUs, routers and materials; set the stockroom in the
   policies page.
10. Schedule the worker ([CRON.md](CRON.md)): billing, overdue marking, late charges, disconnection, reconnection,
    notifications and the other jobs run only when it runs.

## 7. Adding another company

Log in as the Super Admin, open **Companies > New company**, enter the company and its owner. The platform creates a
new database (`gsmart_coop_cable_co002` ...), copies the structure and the set-up lists of a template company (chart of
accounts, roles and page access, numbering, cable / internet rules, templates and jobs) and nothing else. Each company
has its own Company Code, users, subscribers, books and portal (`portal/?co=<its code>`). The worker runs every active
company.

## 8. Updating the program

Copy the new program files over the old ones (keep `config/config.php` and `storage/`). If the database needs an
update the program says so after log-in and names the SQL files to run; with several companies the Super Admin runs
them for every company from the platform (**Database updates**), or from a command prompt: `php tools/upgrade.php`.
