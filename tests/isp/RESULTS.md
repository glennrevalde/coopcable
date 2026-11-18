# Cable / internet system: automated test results

- Date: 2026-11-02 00:18:54 PST
- Command: `DB_NAME=gsc_i DB_PASS='' php tests/isp/run.php`
- PHP 8.3.6, database server 10.11.14-MariaDB-0ubuntu0.24.04.1
- Database: `gsc_i` (dropped and rebuilt by tools/install.php --drop, fixtures by tests/isp/fixtures.php)
- 55 tests, **55 passed, 0 failed**, 727 checks, 9.6 s

| # | Test | Result | Checks | ms |
|---|---|---|---:|---:|
| 1 | R01 New customer creates an application | PASS | 16 | 10 |
| 2 | R02 Installation cannot proceed without the required approval | PASS | 20 | 76 |
| 3 | R03 Inventory is deducted correctly after material issuance and consumption | PASS | 16 | 68 |
| 4 | R04 Service activation triggers the correct billing policy | PASS | 25 | 155 |
| 5 | R05 Monthly invoices are generated without duplicates | PASS | 12 | 85 |
| 6 | R06 Prorated billing is calculated correctly | PASS | 6 | 91 |
| 7 | R07 A partial payment updates the remaining receivable | PASS | 10 | 65 |
| 8 | R08 An advance payment creates the appropriate credit | PASS | 10 | 62 |
| 9 | R09 An unpaid customer becomes overdue | PASS | 8 | 61 |
| 10 | R10 A customer is disconnected only after satisfying the configured conditions | PASS | 21 | 155 |
| 11 | R11 A verified last-minute payment prevents an incorrect disconnection | PASS | 10 | 109 |
| 12 | R12 A protected customer is not disconnected | PASS | 15 | 231 |
| 13 | R13 Duplicate scheduler execution does not duplicate network commands | PASS | 15 | 123 |
| 14 | R14 A network disconnection failure is correctly reported | PASS | 11 | 90 |
| 15 | R15 Payment qualification triggers reconnection processing | PASS | 11 | 41 |
| 16 | R16 A reconnection failure does not falsely mark the network service as restored | PASS | 9 | 112 |
| 17 | R17 A payment reversal recalculates the ledger and the service eligibility | PASS | 15 | 74 |
| 18 | R18 Plan upgrades and downgrades preserve invoice history | PASS | 8 | 46 |
| 19 | R19 Customer cancellation preserves historical transactions | PASS | 10 | 90 |
| 20 | R20 Posted accounting transactions always balance | PASS | 10 | 11 |
| 21 | R21 Unauthorized users cannot access restricted operations | PASS | 19 | 164 |
| 22 | R22 Gateway duplicate webhooks cannot double-post a payment | PASS | 20 | 88 |
| 23 | R23 Billing disputes follow the approval and protection policies | PASS | 13 | 66 |
| 24 | R24 Failed scheduled jobs can be retried safely | PASS | 10 | 109 |
| 25 | R25 Reports reconcile with the underlying transactions | PASS | 44 | 92 |
| 26 | WA Workflow A: new subscriber from application to activation, initial billing and notification | PASS | 23 | 86 |
| 27 | WB Workflow B: normal monthly billing, approval, posting, notification, collection and accounting | PASS | 22 | 102 |
| 28 | WC Workflow C: unpaid subscriber from overdue invoice to disconnection | PASS | 14 | 136 |
| 29 | WD Workflow D: subscriber reconnection after a verified payment | PASS | 10 | 83 |
| 30 | WE Workflow E: connectivity complaint from ticket to closure | PASS | 15 | 75 |
| 31 | WF Workflow F: equipment replacement with the old unit returned | PASS | 10 | 97 |
| 32 | WG Workflow G: subscription cancellation with final invoice, equipment recovery and deposit settlement | PASS | 18 | 119 |
| 33 | WH Workflow H: network outage from detection to billing credits | PASS | 19 | 153 |
| 34 | WI Workflow I: incorrect payment and adjustment corrected with the ledger and eligibility recalculated | PASS | 17 | 188 |
| 35 | B01 Month-end billing and leap years | PASS | 16 | 109 |
| 36 | B02 Zero balances | PASS | 11 | 66 |
| 37 | B03 Payment allocation errors | PASS | 8 | 93 |
| 38 | B04 Concurrent cashier transactions, cash over / short and certification | PASS | 18 | 91 |
| 39 | B05 Account state conflicts | PASS | 15 | 87 |
| 40 | B06 Network downtime: with the device unreachable, operations become manual work orders and nothing is reported done before | PASS | 8 | 78 |
| 41 | B07 Credentials and secrets are stored encrypted, never in plain text | PASS | 15 | 9 |
| 42 | B08 Branch data isolation for a branch-limited user | PASS | 9 | 73 |
| 43 | B09 Portal ownership: a subscriber sees only their own accounts | PASS | 16 | 555 |
| 44 | B10 Cooperative integration: member price and the savings auto-debit | PASS | 12 | 88 |
| 45 | B11 VAT treatment: inclusive, exclusive, exempt, zero-rated and a non-VAT company | PASS | 5 | 5 |
| 46 | B12 Payment references are recognized however they are typed | PASS | 4 | 71 |
| 47 | B13 Temporary reconnection expires unpaid and the service is disconnected again | PASS | 8 | 171 |
| 48 | B14 Partial-payment reconnection and the prepaid reconnection fee policies | PASS | 8 | 185 |
| 49 | B15 Payment arrangements: a missed installment ends the protection; a kept one completes | PASS | 7 | 102 |
| 50 | B16 Sales agent commissions: activation, first payment, payment by bill, clawback | PASS | 10 | 75 |
| 51 | B17 Support SLA escalation and recurring complaints | PASS | 6 | 43 |
| 52 | B18 Security deposit refund after termination | PASS | 10 | 61 |
| 53 | B19 Contract expiration notices and renewal | PASS | 6 | 44 |
| 54 | B20 Every scheduled job runs cleanly (the backup job excepted: it writes to the program folder) | PASS | 19 | 124 |
| 55 | Z01 Final: every posted transaction balances and the subscriber ledger equals the general ledger | PASS | 4 | 19 |

R01-R25 are the required tests of the specification (section 38), WA-WI the end-to-end workflows A to I (section 35), B01-B12 the boundary cases. After every test the runner also checks that no posted transaction is unbalanced.
