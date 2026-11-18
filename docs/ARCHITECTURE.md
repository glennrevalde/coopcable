# Architecture: one system for the cooperative, the books and the cable / internet operation

G-SMaRT Coop Cable System is one PHP application on one database per company. The cooperative (members, share capital,
savings, loans, teller), the accounting system (general ledger, A/R, A/P, banking, inventory, fixed assets, POS, BIR
reports) and the cable / internet service operation share the same log-in, menu, roles and page access, audit trail,
Party Master, chart of accounts, inventory ledger and general ledger. Nothing is exported or synchronised between them.

```
                      ┌──────────────────────── Party Master (parties) ────────────────────────┐
                      │ one record per person / organization, whatever its roles               │
                      │ member  │ customer │ supplier │ employee │ subscriber (ISP customer)    │
                      └────┬────────┬──────────┬───────────────────────┬───────────────────────┘
                           │        │          │                       │
   Cooperative ────────────┘        │          │        Cable / Internet (app/Isp*.php)
   members, share capital,          │          │        subscribers, accounts, plans, billing,
   savings, loans, teller           │          │        collections, disconnection / reconnection,
        │                           │          │        network adapters, infrastructure, field
        │                 A/R customer   A/P supplier   service, tickets, outages, portal
        │                           │          │                       │
        │                           │          │            IspPosting (the one bridge)
        ▼                           ▼          ▼                       ▼
   ┌──────────────────────────── transactions / journal_lines (general ledger) ───────────────────────────┐
   │  every document balanced, by class (branch) and line of business; posted once (idempotent event keys)  │
   └────────────────────────────────────────────────────────────────────────────────────────────────────────┘
```

## How the parts meet

| Where | What happens |
|---|---|
| Subscriber = party | A subscriber (`isp_customers`) belongs to a party. If the person is already a member, customer or supplier, the same party is used: the Consolidated Party Statement, the Party Center and the member's page show the cable / internet accounts, balances and payments next to savings, loans and store purchases. |
| Member pricing | A plan has a member monthly fee; a subscriber who is an active member of the cooperative is billed at it. |
| Savings auto-debit | A member may pay the monthly bill from a savings account: a savings withdrawal through the cooperative's savings ledger (`Savings::withdraw`, with its holds and minimum balance) and a payment from the clearing account, both in one database transaction. |
| A/R | Every subscriber has an A/R customer record (`isp_customers.customer_id`), so the invoices and payments are ordinary A/R documents: Customer Center, A/R aging, customer statements and the receivables in the balance sheet include them. The subscriber ledger and the GL account A/R - Subscribers are reconciled by the report Subscriber Ledger vs General Ledger. |
| Line of business and branch | Every ISP document posts under the line of business **CI Cable & Internet Services** and the class of the subscriber's branch, so the Statement of Operations by Line of Business and the branch reports separate the cable business from the savings and credit and store operations. |
| Inventory | ONUs, routers, drop cable and materials are items of the inventory ledger. Issuing to a technician is an inventory transfer between sites; materials consumed on a work order are an inventory adjustment at average cost to installation or maintenance cost. Serialised equipment is tracked in `isp_equipment` and its history. |
| Purchasing | Equipment is bought with the ordinary purchase orders, item receipts and bills of the Vendors module. |
| Cash | Cable / internet cashiers work in their own shift (opening cash, denomination count, over / short posted, certification, remittance to the bank). Deposits and bank reconciliation use the Banking module. |
| Agents | A sales agent's commission becomes a bill of the agent's supplier record, paid through A/P. |
| Security | One role system: the ISP roles (Branch Manager, Billing Officer, Cashier, Collection Officer, Accountant, Network Administrator, Technical Supervisor, technicians, Customer Service, Inventory Custodian, Sales Agent, Auditor) have page access and special permissions on the same pages as the cooperative roles. Every change writes the audit trail; documents posted by the cable system cannot be edited or voided from the accounting pages (they are voided through the cable pages, which keep both sides in step). |

## The posting bridge (`app/IspPosting.php`)

| ISP event | Ledger document |
|---|---|
| Invoice posted | INVOICE: Dr A/R - Subscribers; Cr internet / cable TV / installation / rental / late charge / reconnection revenue, Output VAT, security deposits; discounts debit Subscriber Discounts |
| Advance payment applied | the earlier payment's credit applied to the new invoice |
| Payment posted | PAYMENT: Dr cashier's drawer / bank or e-wallet clearing, Cr A/R, applied to the allocated invoices; the rest is the subscriber's credit |
| Savings auto-debit | savings WITHDRAWAL (Dr savings deposits, Cr auto-debit clearing) + PAYMENT from the clearing account |
| Gateway fee | JOURNAL: Dr Payment Gateway Fees, Cr gateway clearing |
| Payment reversed | the payment, its fee entry and auto-debit VOIDED, linked to the original |
| Credit adjustment / outage credit / write-off | CREDIT MEMO applied to the invoice (service credits, discounts or bad debts) |
| Debit adjustment | supplemental INVOICE |
| Deposit applied | CREDIT MEMO: Dr Subscriber Security Deposits, Cr A/R |
| Materials issued / consumed | INVENTORY TRANSFER / INVENTORY ADJUSTMENT at average cost |
| Cashier over / short | JOURNAL against Cashier Over and Short |
| Commission paid | BILL of the agent's supplier |

Each event has a unique key in `isp_postings` (INV-12, PAY-40, ADJ-3 ...), written in the same database transaction as
the ledger document and the ISP record: a retried request or a re-run job finds the key and does not post twice.

## Network control

`app/IspNetwork.php` is the only place that talks to network devices, through an adapter per platform
(`IspAdapterMikrotik` RouterOS API, `IspAdapterRadius` FreeRADIUS SQL, `IspAdapterOltHttp` OLT / ONU platform,
`IspAdapterTr069` GenieACS, `IspAdapterManual` technician confirmation, `IspAdapterSimulator` labelled simulation).
Each command is an operation (`isp_net_ops`) with an idempotency key, a per-account lock, retries with back-off, a log
of every command and answer (`isp_net_logs`, never with secrets), and a final state only when the device confirmed it.
Billing state (`service_status`) and device state (`network_state`) are separate, and the reconciliation job reports
where they differ.

## Disconnection and reconnection safeguards

* Rules per reason (non-payment, contract end, request, fraud, technical, administrative, cancellation) with grace
  days, minimum overdue amount and invoices, final notice, approval, allowed days and hours.
* Protected: open disputes, active payment arrangements, exemptions, accounts with a payment received after the
  candidate was listed. Eligibility is re-checked under a row lock immediately before the command is sent; one live
  queue item per account.
* A payment posted while a disconnection is executing is honoured after the device confirms: the account is
  reconnected at once.
* Reconnection follows the payment rule (full or the configured share of the qualifying arrears, reconnection fee
  policy), or a manual / temporary / arrangement reconnection with the special permission.

## Multi-company

`app/Tenant.php` picks the company of each request; `app/Master.php` is the platform database (companies, their
databases, Company Codes, usernames, Super Admins). `app/Provision.php` creates a new company database from a template
company's structure and set-up lists. Every company's data stays in its own database; the worker loops over the active
companies. The customer portal and the gateway webhook find the company by its code (`?co=`).

## Folders

| Folder | Contents |
|---|---|
| `app/` | Classes (one per file, autoloaded): core (DB, Auth, Access, Audit, Txn, Posting...), cooperative, ISP (`Isp*.php`), reports (`app/reports/*.php`) |
| `modules/` | Pages, one folder per menu (`isp`, `ispbill`, `ispnet`, `ispfield`, `ispset`, `coop`, `customers`, ...) |
| `views/` | Layout of the staff pages and of the platform |
| `portal/` | Customer self-service portal |
| `api/` | Payment gateway webhook |
| `cron/` | The worker |
| `database/` | `schema.sql`, `triggers.sql`, `master_schema.sql`, `migrations/`, the two complete SQL files |
| `tools/` | Installer, demo data, exporter, upgrade, platform maintenance |
| `tests/` | `tests/coop` (cooperative and accounting), `tests/isp` (cable / internet) |
| `storage/` | Backups, uploads, keys, platform flag (never served) |
