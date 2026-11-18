# User accounts

Every account of the two delivered databases, from the lowest level of authority to the highest. Both databases have
the same accounts; what differs is the first log-in:

* **Clean database** (`gsmart_coop_cable_clean.sql`): every account, the Super Admin included, must choose a new
  password at its first log-in. Deactivate the accounts you do not need (System Administration > User Accounts).
* **Sample database** (`gsmart_coop_cable_sample.sql`): the passwords below work as they are (only `admin` is asked for a
  new one at the first log-in). For training only: change or deactivate them before real use.

Staff and the Super Admin log in at `http://<server>/gsmart-coop-cable/` (the same page: it finds the company by
the username). Subscribers log in to the portal at `http://<server>/gsmart-coop-cable/portal/?co=<Company Code>`.

| Database | Company | Company Code |
|---|---|---|
| Clean | Your Cooperative Name | `YCN-3PPN5` |
| Sample | Sample Multi-Purpose Cooperative | `SMP-HWBRC` |

## Customer portal (subscribers), sample database only

| Level | Username | Password | Subscriber |
|---|---|---|---|
| 0 | `CUS-000001` | `Portal@2027` | Maria Santos, account 1000001 (cooperative member, savings auto-debit) |
| 0 | `CUS-000004` | `Portal@2027` | Maricel Dizon, account 1000004 (reconnected after paying) |
| 0 | `CUS-000011` | `Portal@2027` | Bulihan Hardware & Construction Supply, account 1000011 (business) |

Other subscribers create their own portal login (account number + last 4 digits of the mobile number), or the office
gives one from the account page (Portal tab).

## Company users (both databases)

| Level | Username | Password | Name | Role | Branch | What the role does |
|---|---|---|---|---|---|---|
| 1 | `agent1` | `Demo@12345` | Ramon Dela Cruz | Sales Agent |  | New applications of their own, coverage and plans, their commissions |
| 2 | `installer1` | `Demo@12345` | Jomar Pascual | Installation Technician | Head Office | My Tasks Today: installation work orders, materials, serials, signal readings, photos, customer acceptance; network map |
| 3 | `maintech1` | `Demo@12345` | Rodel Manalo | Maintenance Technician | Head Office | My Tasks Today: repairs, relocations, recoveries, replacements, assigned tickets; infrastructure and map |
| 4 | `collector1` | `Demo@12345` | Alvin Soriano | Collector | Head Office | Loan collection and the collection / due / aging reports (cooperative) |
| 5 | `teller1` | `Demo@12345` | Teresa Villanueva | Teller | Head Office | Teller shift, official receipts, savings deposits and withdrawals, loan collection, passbooks (cooperative) |
| 5 | `teller2` | `Demo@12345` | Paolo Mercado | Teller (sample database only) | Poblacion Branch | Teller shift, official receipts, savings deposits and withdrawals, loan collection, passbooks (cooperative) |
| 6 | `cashier1` | `Demo@12345` | Ella Ramos | Cashier | Head Office | Collect subscriber payments in a cashier shift, receipts, cash count; POS register at the assigned counter |
| 7 | `csr1` | `Demo@12345` | Kristine Lopez | Customer Service Representative |  | Subscriber look-up, applications, tickets, service requests, statements, announcements |
| 8 | `salesrep` | `Demo@12345` | Maria Santos | Sales Representative |  | Estimates, sales orders, invoices, sales reports (accounting) |
| 9 | `invclerk` | `Demo@12345` | Dan Villanueva | Inventory Clerk |  | Items, sites, receiving, adjustments, builds, counts (accounting) |
| 10 | `custodian1` | `Demo@12345` | Noel Bautista | Inventory Custodian |  | Network stockroom: equipment and materials, receiving, transfers to technicians, returns, serials |
| 11 | `arclerk` | `Demo@12345` | Ben Garcia | A/R Clerk |  | Customer transactions and receivables reports (accounting) |
| 12 | `apclerk` | `Demo@12345` | Carla Lim | A/P Clerk |  | Vendor transactions, Pay Bills, advances, payables reports, BIR 2307 |
| 13 | `membership` | `Demo@12345` | Nina Bautista | Membership Officer | Head Office | Member records and statements, savings account opening (cooperative) |
| 14 | `collections1` | `Demo@12345` | Liza Mendoza | Collection Officer |  | Overdue follow-up, payment-reference verification, arrangements, aging and disconnection lists |
| 15 | `billing1` | `Demo@12345` | Janet Ocampo | Billing Officer |  | Billing runs, invoices, adjustments to request, statements, disputes, billing reports |
| 16 | `loanofficer` | `Demo@12345` | Marvin Uy | Loan Officer | Head Office | Loan applications, credit investigation, release, collection (cooperative) |
| 17 | `creditcom` | `Demo@12345` | Eduardo Santos | Credit Committee |  | Loan and waiver approvals (cooperative) |
| 18 | `supervisor` | `Demo@12345` | Fred Tan | POS Supervisor |  | Cashier rights plus approvals of voids, refunds, overrides; X / Z readings |
| 19 | `bookkeeper` | `Demo@12345` | Joy Fernandez | Bookkeeper |  | Cooperative vouchers, interest and penalty posting, subsidiary ledgers |
| 20 | `accountant` | `Demo@12345` | Ana Cruz | Accountant |  | General ledger, banking, subscriber receivables reconciliation, adjustment and shift approvals, every report |
| 21 | `netadmin1` | `Demo@12345` | Arnold Castillo | Network Administrator |  | Network devices and integrations, provisioning, operations queue, disconnections and reconnections, infrastructure, outages |
| 22 | `techsup1` | `Demo@12345` | Rey Gonzales | Technical Supervisor |  | Dispatch and verification of work orders, technicians, applications, tickets, outages, field reports |
| 23 | `auditor` | `Demo@12345` | Grace Uy | Auditor |  | View-only: every transaction, report and the audit trail |
| 24 | `branchmgr` | `Demo@12345` | Carlo Reyes | Branch Manager | Poblacion Branch | Everything of the branch: approvals of billing, collections, disconnections, field service; cooperative branch approvals |
| 25 | `manager` | `Demo@12345` | Rosario Dizon | General Manager |  | Every cooperative and cable / internet page with approval rights, every report |
| 26 | `admin` | `Admin@12345` | System Administrator | Company Administrator |  | Everything in the company: users, roles, page access, settings, closing date, audit trail (also the Company Owner) |
| 27 | `superadmin` | `SuperAdmin@2027` | Platform Super Admin | **Super Admin** (master database) | all companies | Adds companies, Company Codes and owners, updates every company database, enters any company, platform audit log |

`admin` is also the **Company Owner** of Company 1 on the platform. A Super Admin is not a user of any company: it
works inside a company through the platform's Companies page, and everything it does there is in that company's audit
trail. To reset a forgotten Super Admin password: `php tools/platform.php reset-superadmin superadmin <new password>`.

Passwords follow the company's password policy (System Administration > Company, Security & Closing Date): at least 8
characters with an upper-case letter, a number and a symbol; the Super Admin at least 10. Five failed attempts lock an
account; the Company Administrator unlocks it.
