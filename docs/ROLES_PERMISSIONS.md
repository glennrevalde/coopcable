# User roles and permissions

Generated from the installed defaults (2026-11-02). Roles, their page access and their special permissions can be
changed by the Company Administrator: **System Administration > User Roles**, **Page Access Configuration** and
**Effective Access** (what a user can really do, all roles combined). Every denied page is recorded in the audit trail.

Rights on a page: **View** (open it), **Create**, **Edit**, **Delete / Void / Reverse**, **Print / Export**, **Approve / Post**.
Special permissions add limits and powers that are not tied to one page (amount limits, network control, sensitive data).
Users may also be limited to a branch (cooperative branch of the user profile) and to inventory sites.

Roles from the highest to the lowest level of authority:

* **Company Administrator**: Everything in every module of the company, including user accounts, roles, page access, the closing date, the ISP set-up and the audit trail.
* **General Manager**: Opens every cooperative, savings, loan, teller, distribution and compliance page, approves loans and transactions, and sees every report.
* **Branch Manager**: Everything of the branch: approves savings accounts, withdrawals, loans and waivers, closes teller shifts, and sees the branch reports.
* **Accountant**: Chart of Accounts, Fixed Assets, Banking and reconciliation, the BIR Reports and Withholding Tax Table, all module reports, and the financial statements.
* **Bookkeeper**: Records cooperative vouchers, posts interest and penalties, runs the subsidiary ledgers and financial reports.
* **Auditor**: View-only access to all transactions, reports, and the audit trail. Cannot create or change anything.
* **Network Administrator**: Network devices and integrations (MikroTik, RADIUS, OLT, TR-069), provisioning, the network operations queue and retries, disconnections and reconnections, infrastructure and the network map, outages.
* **Technical Supervisor**: Dispatches and verifies work orders, assigns technicians, approves installations and equipment replacements, manages tickets, outages and the field reports.
* **Billing Officer**: Billing runs (preview and generation), invoices, adjustments to request, statements of account, disputes and payment arrangements to record, and the billing reports.
* **Collection Officer**: Follows up overdue subscribers: verifies bank / e-wallet payment references, records field collections, payment arrangements and collection notes; sees the aging, delinquency and disconnection lists.
* **Credit Committee**: Reviews and approves loan applications and waivers; views loan reports.
* **Loan Officer**: Loan applications, credit investigation, loan release, collection, loan accounts and loan reports.
* **Membership Officer**: Member records, member statements, savings account opening and the member reports.
* **Customer Service Representative**: Front desk and hotline: subscriber look-up, applications, support tickets, service requests, statements of account, announcements; no collections, adjustments or network commands.
* **Inventory Custodian**: The network stockroom: equipment and material items, receiving, transfers to technicians, returns, damaged stock, counts, serial numbers and the inventory reports.
* **POS Supervisor**: Everything a cashier can do, plus approving voids, refunds, price overrides, and large discounts, and running X- and Z-readings.
* **A/R Clerk**: Customers module transactions and the Customer and Receivables reports.
* **A/P Clerk**: Vendors module transactions, Pay Bills, advances to suppliers, the Vendors and Payables reports, and BIR Form 2307 for suppliers.
* **Inventory Clerk**: Items, sites, receiving, adjustments, assembly builds, disassembly and processing orders, stock counts, and inventory quantity reports.
* **Teller**: Teller shift, official receipts, savings deposits and withdrawals, loan collection and passbook printing.
* **Cashier**: Collects subscriber payments in an open cashier shift, prints collection receipts, counts the cash at the end of the shift; also the POS register at the assigned terminal.
* **Collector**: Loan collection and the collection, due and aging reports.
* **Sales Representative**: Estimates, sales orders, and invoices, and the Sales reports.
* **Sales Agent**: Signs up subscribers: new applications (own), checks coverage and plans, follows the status of the applications and sees own commissions.
* **Maintenance Technician**: Field repairs: own task list, repair, relocation, recovery and replacement work orders and the tickets assigned, materials used and returned. No billing or financial pages.
* **Installation Technician**: Field installer: own task list, accepts and completes installation work orders, records materials used, serial numbers, signal readings, photos and the subscriber acceptance. No billing or financial pages.

The platform **Super Admin** is above every company: it is not a role of a company but an account of the master database
(adds companies, Company Codes, owners, database updates, platform audit log) and can enter any company.

## Special permissions

| Permission | Meaning | Roles |
|---|---|---|
| `view_costs` | View costs and margins | A/P Clerk, Accountant, Auditor, Inventory Custodian |
| `view_bank_balances` | View bank balances outside Banking | Accountant, Auditor |
| `max_discount_pct` | Max discount % without approval | A/R Clerk (10), Cashier (5), POS Supervisor (30), Sales Representative (5) |
| `max_price_override_pct` | Max price change % without approval | A/R Clerk (5), POS Supervisor (20) |
| `max_amount` | Max check / bill payment / adjustment amount without approval | A/P Clerk (100000), A/R Clerk (50000), Inventory Clerk (20000), POS Supervisor (20000) |
| `credit_limit_override` | Can exceed customer credit limits | Company Administrator |
| `coa_changes` | Can change the Chart of Accounts and default accounts | Accountant |
| `closed_period_changes` | Can change transactions on/before the closing date or already reconciled | Company Administrator |
| `design_reports` | Can design report layouts (fonts, headings and footers, columns and rows) and save or share them | Accountant |
| `coop_waive` | Cooperative: can waive loan interest and penalties, and adjust loan balances | Branch Manager, Credit Committee, General Manager |
| `coop_backdate` | Cooperative: can record back-dated member transactions | Bookkeeper, Branch Manager, General Manager |
| `coop_override_allocation` | Cooperative: can override the payment allocation (penalty, interest, principal) on a loan collection | Branch Manager, General Manager, Loan Officer |
| `coop_all_branches` | Cooperative: sees every branch regardless of the branch on the user account | Bookkeeper, Credit Committee, General Manager |
| `coop_max_withdrawal` | Cooperative: max savings withdrawal without supervisor approval | Bookkeeper (50000), Teller (50000) |
| `coop_max_release` | Cooperative: max loan release amount without supervisor approval | Loan Officer (100000), Teller (100000) |
| `coop_view_confidential` | Cooperative: can see confidential member details (birth date, TIN, contact numbers, home address) | Branch Manager, Credit Committee, General Manager, Loan Officer, Membership Officer |
| `party_merge` | Parties: can link records found to be the same person or organization, and move a role to another party | Branch Manager, General Manager |
| `doc_reissue` | Can correct the name, address or TIN printed on an issued document (a reissue, with a reason) | Accountant, Bookkeeper, General Manager |
| `edit_posted_documents` | Can change or delete a posted document while posted documents are locked (with a reason) | Accountant, General Manager |
| `isp_post_billing` | Cable & Internet: can approve and post a billing run (invoices go to the books) | Accountant, Branch Manager, General Manager |
| `isp_reverse_payment` | Cable & Internet: can reverse a posted collection (with a reason; recorded permanently) | Accountant, Branch Manager, General Manager |
| `isp_write_off` | Cable & Internet: can approve billing adjustments, write-offs and penalty waivers | Accountant, Branch Manager, General Manager |
| `isp_max_adjustment` | Cable & Internet: max credit adjustment amount without a second approval | Billing Officer (1000), Branch Manager (20000) |
| `isp_disconnect` | Cable & Internet: can disconnect subscribers (single or bulk, after the eligibility check) | Branch Manager, General Manager, Network Administrator |
| `isp_reconnect` | Cable & Internet: can reconnect subscribers manually, temporarily or as an emergency restoration | Branch Manager, General Manager, Network Administrator, Technical Supervisor |
| `isp_network_admin` | Cable & Internet: can configure network devices and run network commands | General Manager, Network Administrator |
| `isp_view_sensitive` | Cable & Internet: can see PPPoE passwords, device credentials and full subscriber contact details | Branch Manager, General Manager, Network Administrator |
| `isp_exempt` | Cable & Internet: can approve disconnection exemptions, payment arrangements and dispute protection | Branch Manager, General Manager |

## Company Administrator

Every page with every right, every special permission (administrator role).

## General Manager

Opens every cooperative, savings, loan, teller, distribution and compliance page, approves loans and transactions, and sees every report.

| Menu | Page | Rights |
|---|---|---|
| Home | Home Dashboard | View, Create, Edit, Delete, Print, Approve |
| Home | Report Center | View, Create, Edit, Delete, Print, Approve |
| Home | Memorized Reports | View, Create, Edit, Delete, Print, Approve |
| Home | Saved Report Layouts | View, Create, Edit, Delete, Print, Approve |
| Home | Search | View, Create, Edit, Delete, Print, Approve |
| Home | Find | View, Create, Edit, Delete, Print, Approve |
| Subscribers | ISP Operations Dashboard | View, Create, Edit, Delete, Print, Approve |
| Subscribers | Subscriber Center | View, Create, Edit, Delete, Print, Approve |
| Subscribers | Subscriber Account | View, Create, Edit, Delete, Print, Approve |
| Subscribers | Applications & Activation | View, Create, Edit, Delete, Print, Approve |
| Subscribers | Contracts & Renewals | View, Create, Edit, Delete, Print, Approve |
| Subscribers | Service Requests | View, Create, Edit, Delete, Print, Approve |
| Subscribers | Sales Agents & Commissions | View, Create, Edit, Delete, Print, Approve |
| Billing & Collection | Collect Payment | View, Create, Edit, Delete, Print, Approve |
| Billing & Collection | Payments & Verification | View, Create, Edit, Delete, Print, Approve |
| Billing & Collection | Cashier Shifts & Cash Count | View, Create, Edit, Delete, Print, Approve |
| Billing & Collection | Billing Runs | View, Create, Edit, Delete, Print, Approve |
| Billing & Collection | Invoices | View, Create, Edit, Delete, Print, Approve |
| Billing & Collection | Adjustments & Write-offs | View, Create, Edit, Delete, Print, Approve |
| Billing & Collection | Disputes, Arrangements & Exemptions | View, Create, Edit, Delete, Print, Approve |
| Billing & Collection | Statement of Account | View, Create, Edit, Delete, Print, Approve |
| Network & Service Control | Disconnection Queue | View, Create, Edit, Delete, Print, Approve |
| Network & Service Control | Reconnections | View, Create, Edit, Delete, Print, Approve |
| Network & Service Control | Disconnected Accounts & Recovery | View, Create, Edit, Delete, Print, Approve |
| Network & Service Control | Network Operations | View, Create, Edit, Delete, Print, Approve |
| Network & Service Control | Network Devices & Integrations | View, Create, Edit, Delete, Print, Approve |
| Network & Service Control | Infrastructure & Ports | View, Create, Edit, Delete, Print, Approve |
| Network & Service Control | Network Map | View, Create, Edit, Delete, Print, Approve |
| Network & Service Control | Network Outages | View, Create, Edit, Delete, Print, Approve |
| Field Service | My Tasks Today | View, Create, Edit, Delete, Print, Approve |
| Field Service | Work Orders & Dispatch | View, Create, Edit, Delete, Print, Approve |
| Field Service | Support Tickets | View, Create, Edit, Delete, Print, Approve |
| Field Service | Technicians & Teams | View, Create, Edit, Delete, Print, Approve |
| Field Service | Equipment Register | View, Create, Edit, Delete, Print, Approve |
| ISP Setup | Internet Plans & Packages | View, Create, Edit, Delete, Print, Approve |
| ISP Setup | Service Areas | View, Create, Edit, Delete, Print, Approve |
| ISP Setup | Billing & Disconnection Policies | View, Create, Edit, Delete, Print, Approve |
| ISP Setup | Notifications (SMS / E-mail) | View, Create, Edit, Delete, Print, Approve |
| ISP Setup | Payment Channels & Gateways | View, Create, Edit, Delete, Print, Approve |
| ISP Setup | Templates & Announcements | View, Create, Edit, Delete, Print, Approve |
| ISP Setup | Scheduled Jobs Monitor | View, Create, Edit, Delete, Print, Approve |
| Financial | Chart of Accounts | View, Create, Edit, Delete, Print, Approve |
| Financial | Account Types | View, Create, Edit, Delete, Print, Approve |
| Financial | Make Journal Entries | View, Create, Edit, Delete, Print, Approve |
| Financial | Journal Entry Changes | View, Create, Edit, Delete, Print, Approve |
| Financial | Module Default Accounts | View, Create, Edit, Delete, Print, Approve |
| Members & Others | Member Center | View, Create, Edit, Delete, Print, Approve |
| Members & Others | Member Information | View, Create, Edit, Delete, Print, Approve |
| Members & Others | Member Statement of Account | View, Create, Edit, Delete, Print, Approve |
| Members & Others | Cooperative Vouchers | View, Create, Edit, Delete, Print, Approve |
| Members & Others | Opening Balances Import | View, Create, Edit, Delete, Print, Approve |
| Members & Others | Cooperative Profile & Policies | View, Create, Edit, Delete, Print, Approve |
| Members & Others | Branches | View, Create, Edit, Delete, Print, Approve |
| Members & Others | Member Types, Clusters & Other Lists | View, Create, Edit, Delete, Print, Approve |
| Members & Others | Passbook Types | View, Create, Edit, Delete, Print, Approve |
| Members & Others | Document Numbering | View, Create, Edit, Delete, Print, Approve |
| Members & Others | Document Templates | View, Create, Edit, Delete, Print, Approve |
| Members & Others | Lines of Business | View, Create, Edit, Print, Approve |
| Teller | Teller Shift & Cash Count | View, Create, Edit, Delete, Print, Approve |
| Teller | Savings Transaction Center | View, Create, Edit, Delete, Print, Approve |
| Teller | Loan Collection | View, Create, Edit, Delete, Print, Approve |
| Teller | Official Receipt (Member Collection) | View, Create, Edit, Delete, Print, Approve |
| Teller | Member Disbursement (Cash Voucher) | View, Create, Edit, Delete, Print, Approve |
| Teller | Official Receipt List | View, Create, Edit, Delete, Print, Approve |
| Savings & Deposits | Savings Account Center | View, Create, Edit, Delete, Print, Approve |
| Savings & Deposits | Savings Account Transaction | View, Create, Edit, Delete, Print, Approve |
| Savings & Deposits | Transfer Between Accounts | View, Create, Edit, Delete, Print, Approve |
| Savings & Deposits | Post Savings Interest | View, Create, Edit, Delete, Print, Approve |
| Savings & Deposits | Passbook Printing | View, Create, Edit, Delete, Print, Approve |
| Savings & Deposits | Time Deposit Center | View, Create, Edit, Delete, Print, Approve |
| Savings & Deposits | Batch Deductions (Payroll / CBU) | View, Create, Edit, Delete, Print, Approve |
| Savings & Deposits | Check Clearing | View, Create, Edit, Delete, Print, Approve |
| Savings & Deposits | Savings & Deposit Products | View, Create, Edit, Delete, Print, Approve |
| Savings & Deposits | Savings Transaction Codes | View, Create, Edit, Delete, Print, Approve |
| Loans | Loan Application Center | View, Create, Edit, Delete, Print, Approve |
| Loans | Loan Application Form | View, Create, Edit, Delete, Print, Approve |
| Loans | Credit Investigation | View, Create, Edit, Delete, Print, Approve |
| Loans | Loan Release | View, Create, Edit, Delete, Print, Approve |
| Loans | Loan Release Vouchers | View, Create, Edit, Delete, Print, Approve |
| Loans | Loan Accounts Center | View, Create, Edit, Delete, Print, Approve |
| Loans | Loan Adjustments | View, Create, Edit, Delete, Print, Approve |
| Loans | Interest / Penalty Waiving | View, Create, Edit, Delete, Print, Approve |
| Loans | Restructure / Renew / Write Off | View, Create, Edit, Delete, Print, Approve |
| Loans | Loan Documents | View, Create, Edit, Delete, Print, Approve |
| Loans | Post Loan Penalties & Interest Accrual | View, Create, Edit, Delete, Print, Approve |
| Loans | Loan Products | View, Create, Edit, Delete, Print, Approve |
| Loans | Loan Charges | View, Create, Edit, Delete, Print, Approve |
| Loans | Loan Approval Matrix | View, Create, Edit, Delete, Print, Approve |
| Parties | Party Center | View, Create, Edit, Print, Approve |
| Parties | Identity Review | View, Create, Edit, Print, Approve |
| Year-end Distribution | Net Surplus Allocation | View, Create, Edit, Delete, Print, Approve |
| Year-end Distribution | Interest on Share Capital | View, Create, Edit, Delete, Print, Approve |
| Year-end Distribution | Patronage Refund | View, Create, Edit, Delete, Print, Approve |
| Year-end Distribution | Distribution Batches | View, Create, Edit, Delete, Print, Approve |
| CDA Compliance | Officers, Committees & Trainings | View, Create, Edit, Delete, Print, Approve |
| CDA Compliance | Compliance Tracking | View, Create, Edit, Delete, Print, Approve |
| CDA Compliance | Regulatory Calendar | View, Create, Edit, Delete, Print, Approve |
| CDA Compliance | CAPR Data Preparation & Export | View, Create, Edit, Delete, Print, Approve |
| CDA Compliance | Report Field Mappings | View, Create, Edit, Delete, Print, Approve |
| Home | Reports (1): Find Results | View, Print |
| Subscribers | Reports (10): Subscriber Master List, Active Accounts, Newly Activated Accounts, Cancelled Subscriptions, Subscriber Growth, Plan Distribution, Subscribers per Barangay, Contracts Due for Renewal, Agent Performance & Commissions, Customer Notification History | View, Print |
| Billing & Collection | Reports (17): Monthly Billing Register, Invoice Register, Billing Summary, Unpaid Invoices, Billing Adjustments, Late Charges, Daily Collection, Collection by Cashier, Collection by Payment Method, Collection Efficiency, Subscriber Receivables Aging, Unapplied Payments & Credits, Delinquency Report, Plan Revenue & Profitability, Branch Profitability, Revenue per Subscriber, Subscriber Ledger vs General Ledger | View, Print |
| Network & Service Control | Reports (9): Accounts Due for Disconnection, Actual Disconnections, Failed Network Operations, Disconnection Reasons, Reconnection History, Outstanding Disconnected Accounts, Outage History & SLA, Network Capacity Utilization, Network Command Log | View, Print |
| Field Service | Reports (7): Installation Progress, Technician Productivity, Open Work Orders, Ticket Resolution Times, Equipment Failures & Replacements, Installation Profitability, Materials Issued & Returned | View, Print |
| Financial | Reports (12): Trial Balance, Balance Sheet, Balance Sheet Summary, Balance Sheet by Class, Income Statement, Income Statement Summary, Income Statement by Class, Statement of Cash Flows, General Ledger, Subsidiary Ledger, Schedule of Accounts, Journal | View, Print |
| Members & Others | Reports (11): Member Master List, Membership Movement, Share Capital Balances, Capital Build-up, Statement of Financial Condition, Statement of Operations, Statement of Changes in Equity, Statutory Funds, Subsidiary vs General Ledger Reconciliation, Document Print History, Statement of Operations by Line of Business | View, Print |
| Teller | Reports (5): Teller Transaction Journal, Daily Collection & Disbursement Summary, Teller Over / Short, Official Receipt Register, Cash Position | View, Print |
| Savings & Deposits | Reports (10): Savings List, Savings Journal, Savings Account Balances, Schedule of Savings Accounts, Dormant Accounts, Interest Posting Register, Time Deposits by Product, Time Deposit Maturity Schedule, Time Deposit Accrued Interest, Savings Account Statement | View, Print |
| Loans | Reports (17): Loan Product List, Schedule of Loans Receivable, Loan Releases, Loan Due vs. Collection, Loans Due for Collection, Loan Collection List, Loans With No Payments, Loan Statement of Account, Aging of Loans by Maturity, Aging of Loans at PAR, Aging of Loans by Amortization, Loan Portfolio Analysis, Co-maker / Guarantor Exposure, Collateral Register, Schedule of Loans vs GL, Waivers and Adjustments, Written-off Loans and Recoveries | View, Print |
| Parties | Reports (4): Consolidated Party Statement, Party List, Party Role History, Reissued Documents | View, Print |
| Year-end Distribution | Reports (3): Distribution Register, Unclaimed Distributions, Net Surplus Allocation Summary | View, Print |
| CDA Compliance | Reports (2): List of Officers and Committees, Compliance Status | View, Print |

Special permissions: `coop_all_branches`, `coop_backdate`, `coop_override_allocation`, `coop_view_confidential`, `coop_waive`, `doc_reissue`, `edit_posted_documents`, `isp_disconnect`, `isp_exempt`, `isp_network_admin`, `isp_post_billing`, `isp_reconnect`, `isp_reverse_payment`, `isp_view_sensitive`, `isp_write_off`, `party_merge`

## Branch Manager

Everything of the branch: approves savings accounts, withdrawals, loans and waivers, closes teller shifts, and sees the branch reports.

| Menu | Page | Rights |
|---|---|---|
| Home | Home Dashboard | View, Create, Edit, Delete, Print, Approve |
| Home | Report Center | View, Create, Edit, Delete, Print, Approve |
| Home | Memorized Reports | View, Create, Edit, Delete, Print, Approve |
| Home | Saved Report Layouts | View, Create, Edit, Delete, Print, Approve |
| Home | Search | View, Create, Edit, Delete, Print, Approve |
| Home | Find | View, Create, Edit, Delete, Print, Approve |
| Subscribers | ISP Operations Dashboard | View, Create, Edit, Delete, Print, Approve |
| Subscribers | Subscriber Center | View, Create, Edit, Delete, Print, Approve |
| Subscribers | Subscriber Account | View, Create, Edit, Delete, Print, Approve |
| Subscribers | Applications & Activation | View, Create, Edit, Delete, Print, Approve |
| Subscribers | Contracts & Renewals | View, Create, Edit, Delete, Print, Approve |
| Subscribers | Service Requests | View, Create, Edit, Delete, Print, Approve |
| Subscribers | Sales Agents & Commissions | View, Create, Edit, Delete, Print, Approve |
| Billing & Collection | Collect Payment | View, Create, Edit, Delete, Print, Approve |
| Billing & Collection | Payments & Verification | View, Create, Edit, Delete, Print, Approve |
| Billing & Collection | Cashier Shifts & Cash Count | View, Create, Edit, Delete, Print, Approve |
| Billing & Collection | Billing Runs | View, Create, Edit, Delete, Print, Approve |
| Billing & Collection | Invoices | View, Create, Edit, Delete, Print, Approve |
| Billing & Collection | Adjustments & Write-offs | View, Create, Edit, Delete, Print, Approve |
| Billing & Collection | Disputes, Arrangements & Exemptions | View, Create, Edit, Delete, Print, Approve |
| Billing & Collection | Statement of Account | View, Create, Edit, Delete, Print, Approve |
| Network & Service Control | Disconnection Queue | View, Create, Edit, Print, Approve |
| Network & Service Control | Reconnections | View, Create, Edit, Print, Approve |
| Network & Service Control | Disconnected Accounts & Recovery | View, Create, Edit, Print, Approve |
| Network & Service Control | Network Operations | View, Create, Edit, Print, Approve |
| Network & Service Control | Network Devices & Integrations | View, Create, Edit, Print, Approve |
| Network & Service Control | Infrastructure & Ports | View, Create, Edit, Print, Approve |
| Network & Service Control | Network Map | View, Create, Edit, Print, Approve |
| Network & Service Control | Network Outages | View, Create, Edit, Print, Approve |
| Field Service | My Tasks Today | View, Create, Edit, Delete, Print, Approve |
| Field Service | Work Orders & Dispatch | View, Create, Edit, Delete, Print, Approve |
| Field Service | Support Tickets | View, Create, Edit, Delete, Print, Approve |
| Field Service | Technicians & Teams | View, Create, Edit, Delete, Print, Approve |
| Field Service | Equipment Register | View, Create, Edit, Delete, Print, Approve |
| ISP Setup | Scheduled Jobs Monitor | View, Print |
| Members & Others | Member Center | View, Create, Edit, Delete, Print, Approve |
| Members & Others | Member Information | View, Create, Edit, Delete, Print, Approve |
| Members & Others | Member Statement of Account | View, Create, Edit, Delete, Print, Approve |
| Members & Others | Cooperative Vouchers | View, Create, Edit, Delete, Print, Approve |
| Members & Others | Opening Balances Import | View, Create, Edit, Delete, Print, Approve |
| Teller | Teller Shift & Cash Count | View, Create, Edit, Delete, Print, Approve |
| Teller | Savings Transaction Center | View, Create, Edit, Delete, Print, Approve |
| Teller | Loan Collection | View, Create, Edit, Delete, Print, Approve |
| Teller | Official Receipt (Member Collection) | View, Create, Edit, Delete, Print, Approve |
| Teller | Member Disbursement (Cash Voucher) | View, Create, Edit, Delete, Print, Approve |
| Teller | Official Receipt List | View, Create, Edit, Delete, Print, Approve |
| Savings & Deposits | Savings Account Center | View, Create, Edit, Delete, Print, Approve |
| Savings & Deposits | Savings Account Transaction | View, Create, Edit, Delete, Print, Approve |
| Savings & Deposits | Transfer Between Accounts | View, Create, Edit, Delete, Print, Approve |
| Savings & Deposits | Post Savings Interest | View, Create, Edit, Delete, Print, Approve |
| Savings & Deposits | Passbook Printing | View, Create, Edit, Delete, Print, Approve |
| Savings & Deposits | Time Deposit Center | View, Create, Edit, Delete, Print, Approve |
| Savings & Deposits | Batch Deductions (Payroll / CBU) | View, Create, Edit, Delete, Print, Approve |
| Savings & Deposits | Check Clearing | View, Create, Edit, Delete, Print, Approve |
| Loans | Loan Application Center | View, Create, Edit, Delete, Print, Approve |
| Loans | Loan Application Form | View, Create, Edit, Delete, Print, Approve |
| Loans | Credit Investigation | View, Create, Edit, Delete, Print, Approve |
| Loans | Loan Release | View, Create, Edit, Delete, Print, Approve |
| Loans | Loan Release Vouchers | View, Create, Edit, Delete, Print, Approve |
| Loans | Loan Accounts Center | View, Create, Edit, Delete, Print, Approve |
| Loans | Loan Adjustments | View, Create, Edit, Delete, Print, Approve |
| Loans | Interest / Penalty Waiving | View, Create, Edit, Delete, Print, Approve |
| Loans | Restructure / Renew / Write Off | View, Create, Edit, Delete, Print, Approve |
| Loans | Loan Documents | View, Create, Edit, Delete, Print, Approve |
| Loans | Post Loan Penalties & Interest Accrual | View, Create, Edit, Delete, Print, Approve |
| Parties | Party Center | View, Create, Edit, Print, Approve |
| Parties | Identity Review | View, Create, Edit, Print, Approve |
| Year-end Distribution | Net Surplus Allocation | View, Create, Edit, Delete, Print, Approve |
| Year-end Distribution | Interest on Share Capital | View, Create, Edit, Delete, Print, Approve |
| Year-end Distribution | Patronage Refund | View, Create, Edit, Delete, Print, Approve |
| Year-end Distribution | Distribution Batches | View, Create, Edit, Delete, Print, Approve |
| CDA Compliance | Compliance Tracking | View, Create, Edit, Delete, Print, Approve |
| CDA Compliance | Regulatory Calendar | View, Create, Edit, Delete, Print, Approve |
| CDA Compliance | CAPR Data Preparation & Export | View, Create, Edit, Delete, Print, Approve |
| Home | Reports (1): Find Results | View, Print |
| Subscribers | Reports (10): Subscriber Master List, Active Accounts, Newly Activated Accounts, Cancelled Subscriptions, Subscriber Growth, Plan Distribution, Subscribers per Barangay, Contracts Due for Renewal, Agent Performance & Commissions, Customer Notification History | View, Print |
| Billing & Collection | Reports (17): Monthly Billing Register, Invoice Register, Billing Summary, Unpaid Invoices, Billing Adjustments, Late Charges, Daily Collection, Collection by Cashier, Collection by Payment Method, Collection Efficiency, Subscriber Receivables Aging, Unapplied Payments & Credits, Delinquency Report, Plan Revenue & Profitability, Branch Profitability, Revenue per Subscriber, Subscriber Ledger vs General Ledger | View, Print |
| Network & Service Control | Reports (9): Accounts Due for Disconnection, Actual Disconnections, Failed Network Operations, Disconnection Reasons, Reconnection History, Outstanding Disconnected Accounts, Outage History & SLA, Network Capacity Utilization, Network Command Log | View, Print |
| Field Service | Reports (7): Installation Progress, Technician Productivity, Open Work Orders, Ticket Resolution Times, Equipment Failures & Replacements, Installation Profitability, Materials Issued & Returned | View, Print |
| Members & Others | Reports (11): Member Master List, Membership Movement, Share Capital Balances, Capital Build-up, Statement of Financial Condition, Statement of Operations, Statement of Changes in Equity, Statutory Funds, Subsidiary vs General Ledger Reconciliation, Document Print History, Statement of Operations by Line of Business | View, Print |
| Teller | Reports (5): Teller Transaction Journal, Daily Collection & Disbursement Summary, Teller Over / Short, Official Receipt Register, Cash Position | View, Print |
| Savings & Deposits | Reports (10): Savings List, Savings Journal, Savings Account Balances, Schedule of Savings Accounts, Dormant Accounts, Interest Posting Register, Time Deposits by Product, Time Deposit Maturity Schedule, Time Deposit Accrued Interest, Savings Account Statement | View, Print |
| Loans | Reports (17): Loan Product List, Schedule of Loans Receivable, Loan Releases, Loan Due vs. Collection, Loans Due for Collection, Loan Collection List, Loans With No Payments, Loan Statement of Account, Aging of Loans by Maturity, Aging of Loans at PAR, Aging of Loans by Amortization, Loan Portfolio Analysis, Co-maker / Guarantor Exposure, Collateral Register, Schedule of Loans vs GL, Waivers and Adjustments, Written-off Loans and Recoveries | View, Print |
| Parties | Reports (4): Consolidated Party Statement, Party List, Party Role History, Reissued Documents | View, Print |
| Year-end Distribution | Reports (3): Distribution Register, Unclaimed Distributions, Net Surplus Allocation Summary | View, Print |
| CDA Compliance | Reports (2): List of Officers and Committees, Compliance Status | View, Print |

Special permissions: `coop_backdate`, `coop_override_allocation`, `coop_view_confidential`, `coop_waive`, `isp_disconnect`, `isp_exempt`, `isp_max_adjustment` = 20000, `isp_post_billing`, `isp_reconnect`, `isp_reverse_payment`, `isp_view_sensitive`, `isp_write_off`, `party_merge`

## Accountant

Chart of Accounts, Fixed Assets, Banking and reconciliation, the BIR Reports and Withholding Tax Table, all module reports, and the financial statements.

| Menu | Page | Rights |
|---|---|---|
| Home | Home Dashboard | View, Print |
| Home | Report Center | View, Print |
| Home | Memorized Reports | View, Print |
| Home | Saved Report Layouts | View, Print |
| Home | Search | View, Print |
| Home | Find | View, Print |
| Subscribers | ISP Operations Dashboard | View, Print |
| Subscribers | Subscriber Center | View, Print |
| Subscribers | Subscriber Account | View, Print |
| Subscribers | Sales Agents & Commissions | View, Print, Approve |
| Billing & Collection | Payments & Verification | View, Print, Approve |
| Billing & Collection | Cashier Shifts & Cash Count | View, Print, Approve |
| Billing & Collection | Billing Runs | View, Print, Approve |
| Billing & Collection | Invoices | View, Print |
| Billing & Collection | Adjustments & Write-offs | View, Edit, Print, Approve |
| Billing & Collection | Disputes, Arrangements & Exemptions | View, Print |
| Billing & Collection | Statement of Account | View, Print |
| ISP Setup | Billing & Disconnection Policies | View, Print |
| ISP Setup | Payment Channels & Gateways | View, Print |
| Financial | Chart of Accounts | View, Create, Edit, Delete, Print |
| Financial | Account Types | View, Create, Edit, Delete, Print |
| Financial | Class List | View, Create, Edit, Delete, Print |
| Financial | Employees & Other Names | View, Create, Edit, Delete, Print |
| Financial | Memorized Transactions | View, Create, Edit, Delete, Print |
| Financial | Set Up Budgets | View, Create, Edit, Delete, Print |
| Financial | Make Journal Entries | View, Create, Edit, Delete, Print |
| Financial | Journal Entry Changes | View, Create, Edit, Delete, Print |
| Financial | Module Default Accounts | View, Create, Edit, Delete, Print |
| Financial | Terms, Tax Codes & Other Lists | View, Create, Edit, Delete, Print |
| Members & Others | Member Center | View, Print |
| Members & Others | Cooperative Vouchers | View, Create, Print |
| Members & Others | Opening Balances Import | View, Create, Print |
| Teller | Official Receipt List | View, Print |
| Savings & Deposits | Savings Account Center | View, Print |
| Savings & Deposits | Post Savings Interest | View, Create, Print |
| Loans | Loan Accounts Center | View, Print |
| Loans | Post Loan Penalties & Interest Accrual | View, Create, Print |
| Banking | Write Checks | View, Create, Edit, Delete, Print |
| Banking | Cash Voucher | View, Create, Edit, Delete, Print |
| Banking | Use Register | View, Create, Edit, Delete, Print |
| Banking | Make Deposits | View, Create, Edit, Delete, Print |
| Banking | Transfer Funds | View, Create, Edit, Delete, Print |
| Banking | Reconcile | View, Create, Edit, Delete, Print |
| Customers | Customer Center | View, Print |
| Customers | Create Invoices | View, Print |
| Customers | Receive Payments | View, Print |
| Vendors | Vendor Center | View, Print |
| Vendors | Bill Tracker | View, Print |
| Vendors | Enter Bills | View, Print |
| Vendors | Pay Bills | View, Print |
| Vendors | Advances to Suppliers | View, Print |
| Inventory | Inventory Center | View, Print |
| Inventory | Item List | View, Print |
| Inventory | Category List | View, Print |
| Inventory | U/M Set List | View, Print |
| Inventory | Processing Formulas (Multi-Output BOM) | View, Print |
| Inventory | Adjust Quantity/Value on Hand | View, Print |
| Inventory | Disassemble / Process Items | View, Print |
| Fixed Assets | Fixed Asset Register | View, Create, Edit, Delete, Print |
| Fixed Assets | Run Depreciation | View, Create, Edit, Delete, Print |
| Fixed Assets | Dispose of Assets | View, Create, Edit, Delete, Print |
| Fixed Assets | Asset Maintenance | View, Create, Edit, Delete, Print |
| Fixed Assets | Preventive Maintenance | View, Create, Edit, Delete, Print |
| Fixed Assets | Asset Categories | View, Create, Edit, Delete, Print |
| Fixed Assets | Asset Locations | View, Create, Edit, Delete, Print |
| Parties | Party Center | View, Create, Edit, Print |
| Parties | Identity Review | View, Print |
| BIR Reports | Print BIR Form 2307 | View, Create, Edit, Delete, Print |
| BIR Reports | Withholding Tax Table | View, Create, Edit, Delete, Print |
| Year-end Distribution | Net Surplus Allocation | View, Create, Print |
| Year-end Distribution | Interest on Share Capital | View, Create, Print |
| Year-end Distribution | Patronage Refund | View, Create, Print |
| Year-end Distribution | Distribution Batches | View, Create, Print |
| System Administration | Utilities: Verify & Rebuild Data | View, Print |
| Home | Reports (1): Find Results | View, Print |
| Subscribers | Reports (10): Subscriber Master List, Active Accounts, Newly Activated Accounts, Cancelled Subscriptions, Subscriber Growth, Plan Distribution, Subscribers per Barangay, Contracts Due for Renewal, Agent Performance & Commissions, Customer Notification History | View, Print |
| Billing & Collection | Reports (17): Monthly Billing Register, Invoice Register, Billing Summary, Unpaid Invoices, Billing Adjustments, Late Charges, Daily Collection, Collection by Cashier, Collection by Payment Method, Collection Efficiency, Subscriber Receivables Aging, Unapplied Payments & Credits, Delinquency Report, Plan Revenue & Profitability, Branch Profitability, Revenue per Subscriber, Subscriber Ledger vs General Ledger | View, Print |
| Network & Service Control | Reports (9): Accounts Due for Disconnection, Actual Disconnections, Failed Network Operations, Disconnection Reasons, Reconnection History, Outstanding Disconnected Accounts, Outage History & SLA, Network Capacity Utilization, Network Command Log | View, Print |
| Field Service | Reports (7): Installation Progress, Technician Productivity, Open Work Orders, Ticket Resolution Times, Equipment Failures & Replacements, Installation Profitability, Materials Issued & Returned | View, Print |
| Financial | Reports (17): Trial Balance, Balance Sheet, Balance Sheet Summary, Balance Sheet by Class, Trial Balance by Date Range, Trial Balance of Totals, Balance Sheet by Date Range, Income Statement vs Budget, Balance Sheet vs Budget, Income Statement, Income Statement Summary, Income Statement by Class, Statement of Cash Flows, General Ledger, Subsidiary Ledger, Schedule of Accounts, Journal | View, Print |
| Members & Others | Reports (11): Member Master List, Membership Movement, Share Capital Balances, Capital Build-up, Statement of Financial Condition, Statement of Operations, Statement of Changes in Equity, Statutory Funds, Subsidiary vs General Ledger Reconciliation, Document Print History, Statement of Operations by Line of Business | View, Print |
| Teller | Reports (5): Teller Transaction Journal, Daily Collection & Disbursement Summary, Teller Over / Short, Official Receipt Register, Cash Position | View, Print |
| Savings & Deposits | Reports (10): Savings List, Savings Journal, Savings Account Balances, Schedule of Savings Accounts, Dormant Accounts, Interest Posting Register, Time Deposits by Product, Time Deposit Maturity Schedule, Time Deposit Accrued Interest, Savings Account Statement | View, Print |
| Loans | Reports (17): Loan Product List, Schedule of Loans Receivable, Loan Releases, Loan Due vs. Collection, Loans Due for Collection, Loan Collection List, Loans With No Payments, Loan Statement of Account, Aging of Loans by Maturity, Aging of Loans at PAR, Aging of Loans by Amortization, Loan Portfolio Analysis, Co-maker / Guarantor Exposure, Collateral Register, Schedule of Loans vs GL, Waivers and Adjustments, Written-off Loans and Recoveries | View, Print |
| Banking | Reports (4): Reconciliation Summary, Reconciliation Detail, Reconciliation Discrepancy, Check Register | View, Print |
| Customers | Reports (26): A/R Aging Summary, A/R Aging Detail, Customer Balance Summary, Customer Balance Detail, Open Invoices, Collections Report, Average Days to Pay Summary, Average Days to Pay, Accounts Receivable Graph, Unbilled Cost by Job, Transaction List by Customer, Item Price List, Sales by Customer Summary, Forecast vs Actual by Customer, Sales by Customer Detail, Income by Customer Summary, Income by Customer Detail, Sales by Item Summary, Sales by Item Detail, Sales by Rep Summary, Sales by Rep Detail, Inventory Sales by Site, Sales Report Through Inventory Movement, Pending Sales, Open Sales Orders by Customer, Open Sales Orders by Item | View, Print |
| Point of Sale | Reports (10): POS Sales by Transaction No. (detail), POS Sales by Item, POS Sales by Category, POS Sales by Cashier & Payment Method, POS Sales by Payment Method, POS Sales by Hour, POS Daily Sales Summary, X- and Z-Reading Summary, POS Discount Log, POS Void and Refund Log | View, Print |
| Vendors | Reports (10): A/P Aging Summary, A/P Aging Detail, Vendor Balance Summary, Vendor Balance Detail, Unpaid Bills Detail, Transaction List by Vendor, Vendor Contact List, Expenses by Vendor Summary, Expenses by Vendor Detail, Advances to Suppliers | View, Print |
| Inventory | Reports (19): Quantity on Hand by Site, Inventory Valuation Summary, Inventory Valuation Summary by Site, Inventory Valuation Detail, Inventory Stock Status by Item, Inventory Stock Status by Vendor, Inventory Stock Status by Site, Physical Inventory Worksheet, Pending Builds, Pending Builds by Site, Processing & Disassembly Register, Co-product & By-product Cost Allocation, Processing Yield Analysis, Negative Inventory Listing, No. of Days Inventory Has No Movement, Customized Inventory Report, Inventory Movement Summary Report, Inventory Movement Detailed Report, Inventory Movement Transactions | View, Print |
| Fixed Assets | Reports (10): Fixed Asset Register Report, Depreciation Schedule by Asset, Depreciation by Category, Additions and Disposals, Maintenance History, Maintenance Cost by Asset, Spare Parts Used, Maintenance Labor, Upcoming Preventive Maintenance, Completed Preventive Maintenance | View, Print |
| Parties | Reports (4): Consolidated Party Statement, Party List, Party Role History, Reissued Documents | View, Print |
| BIR Reports | Reports (8): Summary of Taxes Withheld from Vendors/Suppliers, Expanded Withholding Tax Register (1% / 2%), Withholding Tax Remittances (0619-E, 1601-EQ, 1600-VT), VAT Withholding Register (1600-VT), Output VAT / Sales Register, Input VAT / Purchases Register, Creditable VAT Withheld Schedule (2550Q Schedule 3), Quarterly VAT Return Worksheet (2550Q) | View, Print |
| Year-end Distribution | Reports (3): Distribution Register, Unclaimed Distributions, Net Surplus Allocation Summary | View, Print |
| CDA Compliance | Reports (2): List of Officers and Committees, Compliance Status | View, Print |

Special permissions: `coa_changes`, `design_reports`, `doc_reissue`, `edit_posted_documents`, `isp_post_billing`, `isp_reverse_payment`, `isp_write_off`, `view_bank_balances`, `view_costs`

## Bookkeeper

Records cooperative vouchers, posts interest and penalties, runs the subsidiary ledgers and financial reports.

| Menu | Page | Rights |
|---|---|---|
| Home | Home Dashboard | View, Create, Edit, Print |
| Home | Report Center | View, Create, Edit, Print |
| Home | Memorized Reports | View, Create, Edit, Print |
| Home | Saved Report Layouts | View, Create, Edit, Print |
| Home | Search | View, Create, Edit, Print |
| Home | Find | View, Create, Edit, Print |
| Financial | Chart of Accounts | View, Create, Edit, Print |
| Financial | Account Types | View, Create, Edit, Print |
| Financial | Make Journal Entries | View, Create, Edit, Print |
| Financial | Journal Entry Changes | View, Create, Edit, Print |
| Financial | Module Default Accounts | View, Create, Edit, Print |
| Members & Others | Member Center | View, Create, Edit, Print |
| Members & Others | Member Information | View, Create, Edit, Print |
| Members & Others | Member Statement of Account | View, Create, Edit, Print |
| Members & Others | Cooperative Vouchers | View, Create, Edit, Print |
| Members & Others | Opening Balances Import | View, Create, Edit, Print |
| Members & Others | Member Types, Clusters & Other Lists | View, Create, Edit, Print |
| Members & Others | Passbook Types | View, Create, Edit, Print |
| Members & Others | Document Templates | View, Create, Edit, Print |
| Teller | Savings Transaction Center | View, Create, Edit, Print |
| Teller | Official Receipt List | View, Create, Edit, Print |
| Savings & Deposits | Savings Account Center | View, Create, Edit, Print |
| Savings & Deposits | Savings Account Transaction | View, Create, Edit, Print |
| Savings & Deposits | Transfer Between Accounts | View, Create, Edit, Print |
| Savings & Deposits | Post Savings Interest | View, Create, Edit, Print |
| Savings & Deposits | Passbook Printing | View, Create, Edit, Print |
| Savings & Deposits | Time Deposit Center | View, Create, Edit, Print |
| Savings & Deposits | Batch Deductions (Payroll / CBU) | View, Create, Edit, Print |
| Savings & Deposits | Check Clearing | View, Create, Edit, Print |
| Savings & Deposits | Savings Transaction Codes | View, Create, Edit, Print |
| Loans | Loan Application Center | View, Create, Edit, Print |
| Loans | Loan Application Form | View, Create, Edit, Print |
| Loans | Credit Investigation | View, Create, Edit, Print |
| Loans | Loan Release | View, Create, Edit, Print |
| Loans | Loan Release Vouchers | View, Create, Edit, Print |
| Loans | Loan Accounts Center | View, Create, Edit, Print |
| Loans | Loan Adjustments | View, Create, Edit, Print |
| Loans | Interest / Penalty Waiving | View, Create, Edit, Print |
| Loans | Restructure / Renew / Write Off | View, Create, Edit, Print |
| Loans | Loan Documents | View, Create, Edit, Print |
| Loans | Post Loan Penalties & Interest Accrual | View, Create, Edit, Print |
| Parties | Party Center | View, Create, Edit, Print |
| Parties | Identity Review | View, Print |
| Year-end Distribution | Net Surplus Allocation | View, Create, Edit, Print |
| Year-end Distribution | Interest on Share Capital | View, Create, Edit, Print |
| Year-end Distribution | Patronage Refund | View, Create, Edit, Print |
| Year-end Distribution | Distribution Batches | View, Create, Edit, Print |
| Home | Reports (1): Find Results | View, Print |
| Financial | Reports (10): Trial Balance, Balance Sheet, Balance Sheet Summary, Income Statement, Income Statement Summary, Statement of Cash Flows, General Ledger, Subsidiary Ledger, Schedule of Accounts, Journal | View, Print |
| Members & Others | Reports (11): Member Master List, Membership Movement, Share Capital Balances, Capital Build-up, Statement of Financial Condition, Statement of Operations, Statement of Changes in Equity, Statutory Funds, Subsidiary vs General Ledger Reconciliation, Document Print History, Statement of Operations by Line of Business | View, Print |
| Teller | Reports (5): Teller Transaction Journal, Daily Collection & Disbursement Summary, Teller Over / Short, Official Receipt Register, Cash Position | View, Print |
| Savings & Deposits | Reports (10): Savings List, Savings Journal, Savings Account Balances, Schedule of Savings Accounts, Dormant Accounts, Interest Posting Register, Time Deposits by Product, Time Deposit Maturity Schedule, Time Deposit Accrued Interest, Savings Account Statement | View, Print |
| Loans | Reports (17): Loan Product List, Schedule of Loans Receivable, Loan Releases, Loan Due vs. Collection, Loans Due for Collection, Loan Collection List, Loans With No Payments, Loan Statement of Account, Aging of Loans by Maturity, Aging of Loans at PAR, Aging of Loans by Amortization, Loan Portfolio Analysis, Co-maker / Guarantor Exposure, Collateral Register, Schedule of Loans vs GL, Waivers and Adjustments, Written-off Loans and Recoveries | View, Print |
| Parties | Reports (4): Consolidated Party Statement, Party List, Party Role History, Reissued Documents | View, Print |
| Year-end Distribution | Reports (3): Distribution Register, Unclaimed Distributions, Net Surplus Allocation Summary | View, Print |

Special permissions: `coop_all_branches`, `coop_backdate`, `coop_max_withdrawal` = 50000, `doc_reissue`

## Auditor

View-only access to all transactions, reports, and the audit trail. Cannot create or change anything.

| Menu | Page | Rights |
|---|---|---|
| Home | Home Dashboard | View, Print |
| Home | Report Center | View, Print |
| Home | Memorized Reports | View, Print |
| Home | Saved Report Layouts | View, Print |
| Home | Search | View, Print |
| Home | Find | View, Print |
| Subscribers | ISP Operations Dashboard | View, Print |
| Subscribers | Subscriber Center | View, Print |
| Subscribers | Subscriber Account | View, Print |
| Subscribers | Applications & Activation | View, Print |
| Subscribers | Contracts & Renewals | View, Print |
| Subscribers | Service Requests | View, Print |
| Subscribers | Sales Agents & Commissions | View, Print |
| Billing & Collection | Payments & Verification | View, Print |
| Billing & Collection | Billing Runs | View, Print |
| Billing & Collection | Invoices | View, Print |
| Billing & Collection | Adjustments & Write-offs | View, Print |
| Billing & Collection | Disputes, Arrangements & Exemptions | View, Print |
| Billing & Collection | Statement of Account | View, Print |
| Network & Service Control | Disconnection Queue | View, Print |
| Network & Service Control | Reconnections | View, Print |
| Network & Service Control | Disconnected Accounts & Recovery | View, Print |
| Network & Service Control | Network Operations | View, Print |
| Network & Service Control | Network Devices & Integrations | View, Print |
| Network & Service Control | Infrastructure & Ports | View, Print |
| Network & Service Control | Network Map | View, Print |
| Network & Service Control | Network Outages | View, Print |
| Field Service | Work Orders & Dispatch | View, Print |
| Field Service | Support Tickets | View, Print |
| Field Service | Technicians & Teams | View, Print |
| Field Service | Equipment Register | View, Print |
| ISP Setup | Internet Plans & Packages | View, Print |
| ISP Setup | Service Areas | View, Print |
| ISP Setup | Billing & Disconnection Policies | View, Print |
| ISP Setup | Notifications (SMS / E-mail) | View, Print |
| ISP Setup | Payment Channels & Gateways | View, Print |
| ISP Setup | Templates & Announcements | View, Print |
| ISP Setup | Scheduled Jobs Monitor | View, Print |
| Financial | Chart of Accounts | View, Print |
| Financial | Account Types | View, Print |
| Financial | Class List | View, Print |
| Financial | Employees & Other Names | View, Print |
| Financial | Memorized Transactions | View, Print |
| Financial | Set Up Budgets | View, Print |
| Financial | Make Journal Entries | View, Print |
| Financial | Journal Entry Changes | View, Print |
| Financial | Module Default Accounts | View, Print |
| Financial | Terms, Tax Codes & Other Lists | View, Print |
| Members & Others | Member Center | View, Print |
| Members & Others | Member Information | View, Print |
| Members & Others | Member Statement of Account | View, Print |
| Members & Others | Cooperative Vouchers | View, Print |
| Members & Others | Opening Balances Import | View, Print |
| Members & Others | Cooperative Profile & Policies | View, Print |
| Members & Others | Branches | View, Print |
| Members & Others | Member Types, Clusters & Other Lists | View, Print |
| Members & Others | Passbook Types | View, Print |
| Members & Others | Document Numbering | View, Print |
| Members & Others | Document Templates | View, Print |
| Members & Others | Lines of Business | View, Print |
| Teller | Savings Transaction Center | View, Print |
| Teller | Loan Collection | View, Print |
| Teller | Official Receipt List | View, Print |
| Savings & Deposits | Savings Account Center | View, Print |
| Savings & Deposits | Savings Account Transaction | View, Print |
| Savings & Deposits | Transfer Between Accounts | View, Print |
| Savings & Deposits | Post Savings Interest | View, Print |
| Savings & Deposits | Passbook Printing | View, Print |
| Savings & Deposits | Time Deposit Center | View, Print |
| Savings & Deposits | Batch Deductions (Payroll / CBU) | View, Print |
| Savings & Deposits | Check Clearing | View, Print |
| Savings & Deposits | Savings & Deposit Products | View, Print |
| Savings & Deposits | Savings Transaction Codes | View, Print |
| Loans | Loan Application Center | View, Print |
| Loans | Loan Application Form | View, Print |
| Loans | Credit Investigation | View, Print |
| Loans | Loan Release | View, Print |
| Loans | Loan Release Vouchers | View, Print |
| Loans | Loan Accounts Center | View, Print |
| Loans | Loan Adjustments | View, Print |
| Loans | Interest / Penalty Waiving | View, Print |
| Loans | Restructure / Renew / Write Off | View, Print |
| Loans | Loan Documents | View, Print |
| Loans | Post Loan Penalties & Interest Accrual | View, Print |
| Loans | Loan Products | View, Print |
| Loans | Loan Charges | View, Print |
| Loans | Loan Approval Matrix | View, Print |
| Banking | Write Checks | View, Print |
| Banking | Cash Voucher | View, Print |
| Banking | Use Register | View, Print |
| Banking | Make Deposits | View, Print |
| Banking | Transfer Funds | View, Print |
| Banking | Reconcile | View, Print |
| Customers | Customer Center | View, Print |
| Customers | Create Estimates | View, Print |
| Customers | Create Sales Orders | View, Print |
| Customers | Create Invoices | View, Print |
| Customers | Create Batch Invoices | View, Print |
| Customers | Enter Sales Receipts | View, Print |
| Customers | Enter Statement Charges | View, Print |
| Customers | Create Statements | View, Print |
| Customers | Assess Finance Charges | View, Print |
| Customers | Set Up Forecast | View, Print |
| Customers | Receive Payments | View, Print |
| Customers | Create Credit Memos/Refunds | View, Print |
| Point of Sale | POS Receipts, Returns & Voids | View, Print |
| Point of Sale | Approval Codes | View, Print |
| Point of Sale | POS Terminals | View, Print |
| Vendors | Vendor Center | View, Print |
| Vendors | Bill Tracker | View, Print |
| Vendors | Enter Bills | View, Print |
| Vendors | Supplier Debit Memos | View, Print |
| Vendors | Pay Bills | View, Print |
| Vendors | Receipt Management | View, Print |
| Vendors | Create Purchase Orders | View, Print |
| Vendors | Receive Items and Enter Bills | View, Print |
| Vendors | Receive Items | View, Print |
| Vendors | Enter Bill for Received Items | View, Print |
| Vendors | Advances to Suppliers | View, Print |
| Inventory | Inventory Center | View, Print |
| Inventory | Item List | View, Print |
| Inventory | Category List | View, Print |
| Inventory | Inventory Site List | View, Print |
| Inventory | U/M Set List | View, Print |
| Inventory | Processing Formulas (Multi-Output BOM) | View, Print |
| Inventory | Transfer Inventory | View, Print |
| Inventory | Adjust Quantity/Value on Hand | View, Print |
| Inventory | Build Assemblies | View, Print |
| Inventory | Disassemble / Process Items | View, Print |
| Inventory | Physical Inventory Count | View, Print |
| Inventory | Change Item Prices | View, Print |
| Inventory | Set Item Prices and Levels | View, Print |
| Fixed Assets | Fixed Asset Register | View, Print |
| Fixed Assets | Run Depreciation | View, Print |
| Fixed Assets | Dispose of Assets | View, Print |
| Fixed Assets | Asset Maintenance | View, Print |
| Fixed Assets | Preventive Maintenance | View, Print |
| Fixed Assets | Asset Categories | View, Print |
| Fixed Assets | Asset Locations | View, Print |
| Parties | Party Center | View, Print |
| Parties | Identity Review | View, Print |
| BIR Reports | Print BIR Form 2307 | View, Print |
| BIR Reports | Withholding Tax Table | View, Print |
| Year-end Distribution | Net Surplus Allocation | View, Print |
| Year-end Distribution | Interest on Share Capital | View, Print |
| Year-end Distribution | Patronage Refund | View, Print |
| Year-end Distribution | Distribution Batches | View, Print |
| CDA Compliance | Officers, Committees & Trainings | View, Print |
| CDA Compliance | Compliance Tracking | View, Print |
| CDA Compliance | Regulatory Calendar | View, Print |
| CDA Compliance | CAPR Data Preparation & Export | View, Print |
| CDA Compliance | Report Field Mappings | View, Print |
| Home | Reports (1): Find Results | View, Print |
| Subscribers | Reports (10): Subscriber Master List, Active Accounts, Newly Activated Accounts, Cancelled Subscriptions, Subscriber Growth, Plan Distribution, Subscribers per Barangay, Contracts Due for Renewal, Agent Performance & Commissions, Customer Notification History | View, Print |
| Billing & Collection | Reports (17): Monthly Billing Register, Invoice Register, Billing Summary, Unpaid Invoices, Billing Adjustments, Late Charges, Daily Collection, Collection by Cashier, Collection by Payment Method, Collection Efficiency, Subscriber Receivables Aging, Unapplied Payments & Credits, Delinquency Report, Plan Revenue & Profitability, Branch Profitability, Revenue per Subscriber, Subscriber Ledger vs General Ledger | View, Print |
| Network & Service Control | Reports (9): Accounts Due for Disconnection, Actual Disconnections, Failed Network Operations, Disconnection Reasons, Reconnection History, Outstanding Disconnected Accounts, Outage History & SLA, Network Capacity Utilization, Network Command Log | View, Print |
| Field Service | Reports (7): Installation Progress, Technician Productivity, Open Work Orders, Ticket Resolution Times, Equipment Failures & Replacements, Installation Profitability, Materials Issued & Returned | View, Print |
| Financial | Reports (17): Trial Balance, Balance Sheet, Balance Sheet Summary, Balance Sheet by Class, Trial Balance by Date Range, Trial Balance of Totals, Balance Sheet by Date Range, Income Statement vs Budget, Balance Sheet vs Budget, Income Statement, Income Statement Summary, Income Statement by Class, Statement of Cash Flows, General Ledger, Subsidiary Ledger, Schedule of Accounts, Journal | View, Print |
| Members & Others | Reports (11): Member Master List, Membership Movement, Share Capital Balances, Capital Build-up, Statement of Financial Condition, Statement of Operations, Statement of Changes in Equity, Statutory Funds, Subsidiary vs General Ledger Reconciliation, Document Print History, Statement of Operations by Line of Business | View, Print |
| Teller | Reports (5): Teller Transaction Journal, Daily Collection & Disbursement Summary, Teller Over / Short, Official Receipt Register, Cash Position | View, Print |
| Savings & Deposits | Reports (10): Savings List, Savings Journal, Savings Account Balances, Schedule of Savings Accounts, Dormant Accounts, Interest Posting Register, Time Deposits by Product, Time Deposit Maturity Schedule, Time Deposit Accrued Interest, Savings Account Statement | View, Print |
| Loans | Reports (17): Loan Product List, Schedule of Loans Receivable, Loan Releases, Loan Due vs. Collection, Loans Due for Collection, Loan Collection List, Loans With No Payments, Loan Statement of Account, Aging of Loans by Maturity, Aging of Loans at PAR, Aging of Loans by Amortization, Loan Portfolio Analysis, Co-maker / Guarantor Exposure, Collateral Register, Schedule of Loans vs GL, Waivers and Adjustments, Written-off Loans and Recoveries | View, Print |
| Banking | Reports (4): Reconciliation Summary, Reconciliation Detail, Reconciliation Discrepancy, Check Register | View, Print |
| Customers | Reports (26): A/R Aging Summary, A/R Aging Detail, Customer Balance Summary, Customer Balance Detail, Open Invoices, Collections Report, Average Days to Pay Summary, Average Days to Pay, Accounts Receivable Graph, Unbilled Cost by Job, Transaction List by Customer, Item Price List, Sales by Customer Summary, Forecast vs Actual by Customer, Sales by Customer Detail, Income by Customer Summary, Income by Customer Detail, Sales by Item Summary, Sales by Item Detail, Sales by Rep Summary, Sales by Rep Detail, Inventory Sales by Site, Sales Report Through Inventory Movement, Pending Sales, Open Sales Orders by Customer, Open Sales Orders by Item | View, Print |
| Point of Sale | Reports (10): POS Sales by Transaction No. (detail), POS Sales by Item, POS Sales by Category, POS Sales by Cashier & Payment Method, POS Sales by Payment Method, POS Sales by Hour, POS Daily Sales Summary, X- and Z-Reading Summary, POS Discount Log, POS Void and Refund Log | View, Print |
| Vendors | Reports (10): A/P Aging Summary, A/P Aging Detail, Vendor Balance Summary, Vendor Balance Detail, Unpaid Bills Detail, Transaction List by Vendor, Vendor Contact List, Expenses by Vendor Summary, Expenses by Vendor Detail, Advances to Suppliers | View, Print |
| Inventory | Reports (19): Quantity on Hand by Site, Inventory Valuation Summary, Inventory Valuation Summary by Site, Inventory Valuation Detail, Inventory Stock Status by Item, Inventory Stock Status by Vendor, Inventory Stock Status by Site, Physical Inventory Worksheet, Pending Builds, Pending Builds by Site, Processing & Disassembly Register, Co-product & By-product Cost Allocation, Processing Yield Analysis, Negative Inventory Listing, No. of Days Inventory Has No Movement, Customized Inventory Report, Inventory Movement Summary Report, Inventory Movement Detailed Report, Inventory Movement Transactions | View, Print |
| Fixed Assets | Reports (10): Fixed Asset Register Report, Depreciation Schedule by Asset, Depreciation by Category, Additions and Disposals, Maintenance History, Maintenance Cost by Asset, Spare Parts Used, Maintenance Labor, Upcoming Preventive Maintenance, Completed Preventive Maintenance | View, Print |
| Parties | Reports (4): Consolidated Party Statement, Party List, Party Role History, Reissued Documents | View, Print |
| BIR Reports | Reports (8): Summary of Taxes Withheld from Vendors/Suppliers, Expanded Withholding Tax Register (1% / 2%), Withholding Tax Remittances (0619-E, 1601-EQ, 1600-VT), VAT Withholding Register (1600-VT), Output VAT / Sales Register, Input VAT / Purchases Register, Creditable VAT Withheld Schedule (2550Q Schedule 3), Quarterly VAT Return Worksheet (2550Q) | View, Print |
| Year-end Distribution | Reports (3): Distribution Register, Unclaimed Distributions, Net Surplus Allocation Summary | View, Print |
| CDA Compliance | Reports (2): List of Officers and Committees, Compliance Status | View, Print |
| System Administration | Reports (7): Audit Trail, Transaction History, Voided and Deleted Transactions Summary, Voided and Deleted Transactions Detail, User Activity, Login History, User Access and Permission Changes | View, Print |

Special permissions: `view_bank_balances`, `view_costs`

## Network Administrator

Network devices and integrations (MikroTik, RADIUS, OLT, TR-069), provisioning, the network operations queue and retries, disconnections and reconnections, infrastructure and the network map, outages.

| Menu | Page | Rights |
|---|---|---|
| Home | Home Dashboard | View |
| Subscribers | ISP Operations Dashboard | View, Print |
| Subscribers | Subscriber Center | View, Print |
| Subscribers | Subscriber Account | View, Edit, Print |
| Network & Service Control | Disconnection Queue | View, Create, Edit, Delete, Print, Approve |
| Network & Service Control | Reconnections | View, Create, Edit, Delete, Print, Approve |
| Network & Service Control | Disconnected Accounts & Recovery | View, Create, Edit, Delete, Print, Approve |
| Network & Service Control | Network Operations | View, Create, Edit, Delete, Print, Approve |
| Network & Service Control | Network Devices & Integrations | View, Create, Edit, Delete, Print, Approve |
| Network & Service Control | Infrastructure & Ports | View, Create, Edit, Delete, Print, Approve |
| Network & Service Control | Network Map | View, Create, Edit, Delete, Print, Approve |
| Network & Service Control | Network Outages | View, Create, Edit, Delete, Print, Approve |
| Field Service | Work Orders & Dispatch | View, Create, Edit, Print |
| Field Service | Support Tickets | View, Create, Edit, Print |
| Field Service | Equipment Register | View, Print |
| ISP Setup | Scheduled Jobs Monitor | View, Create, Edit, Print |
| Network & Service Control | Reports (9): Accounts Due for Disconnection, Actual Disconnections, Failed Network Operations, Disconnection Reasons, Reconnection History, Outstanding Disconnected Accounts, Outage History & SLA, Network Capacity Utilization, Network Command Log | View, Print |
| Field Service | Reports (7): Installation Progress, Technician Productivity, Open Work Orders, Ticket Resolution Times, Equipment Failures & Replacements, Installation Profitability, Materials Issued & Returned | View, Print |

Special permissions: `isp_disconnect`, `isp_network_admin`, `isp_reconnect`, `isp_view_sensitive`

## Technical Supervisor

Dispatches and verifies work orders, assigns technicians, approves installations and equipment replacements, manages tickets, outages and the field reports.

| Menu | Page | Rights |
|---|---|---|
| Home | Home Dashboard | View |
| Subscribers | ISP Operations Dashboard | View, Print |
| Subscribers | Subscriber Center | View, Print |
| Subscribers | Subscriber Account | View, Edit, Print |
| Subscribers | Applications & Activation | View, Create, Edit, Print, Approve |
| Subscribers | Service Requests | View, Create, Edit, Print |
| Network & Service Control | Reconnections | View, Create, Print |
| Network & Service Control | Disconnected Accounts & Recovery | View, Create, Edit, Print |
| Network & Service Control | Network Operations | View, Print |
| Network & Service Control | Infrastructure & Ports | View, Create, Edit, Print |
| Network & Service Control | Network Map | View, Print |
| Network & Service Control | Network Outages | View, Create, Edit, Print, Approve |
| Field Service | My Tasks Today | View, Create, Edit, Delete, Print, Approve |
| Field Service | Work Orders & Dispatch | View, Create, Edit, Delete, Print, Approve |
| Field Service | Support Tickets | View, Create, Edit, Delete, Print, Approve |
| Field Service | Technicians & Teams | View, Create, Edit, Delete, Print, Approve |
| Field Service | Equipment Register | View, Create, Edit, Delete, Print, Approve |
| Inventory | Inventory Center | View, Print |
| Inventory | Transfer Inventory | View, Create, Print |
| Network & Service Control | Reports (3): Outage History & SLA, Network Capacity Utilization, Network Command Log | View, Print |
| Field Service | Reports (7): Installation Progress, Technician Productivity, Open Work Orders, Ticket Resolution Times, Equipment Failures & Replacements, Installation Profitability, Materials Issued & Returned | View, Print |
| Inventory | Reports (1): Quantity on Hand by Site | View, Print |

Special permissions: `isp_reconnect`

## Billing Officer

Billing runs (preview and generation), invoices, adjustments to request, statements of account, disputes and payment arrangements to record, and the billing reports.

| Menu | Page | Rights |
|---|---|---|
| Home | Home Dashboard | View |
| Home | Report Center | View, Print |
| Subscribers | ISP Operations Dashboard | View, Print |
| Subscribers | Subscriber Center | View, Print |
| Subscribers | Subscriber Account | View, Edit, Print |
| Subscribers | Contracts & Renewals | View, Print |
| Subscribers | Service Requests | View, Create, Edit, Print |
| Billing & Collection | Payments & Verification | View, Print |
| Billing & Collection | Billing Runs | View, Create, Edit, Print |
| Billing & Collection | Invoices | View, Create, Edit, Print |
| Billing & Collection | Adjustments & Write-offs | View, Create, Edit, Print |
| Billing & Collection | Disputes, Arrangements & Exemptions | View, Create, Edit, Print |
| Billing & Collection | Statement of Account | View, Print |
| Network & Service Control | Disconnection Queue | View, Print |
| Subscribers | Reports (2): Subscriber Master List, Plan Distribution | View, Print |
| Billing & Collection | Reports (13): Monthly Billing Register, Invoice Register, Billing Summary, Unpaid Invoices, Billing Adjustments, Late Charges, Daily Collection, Collection by Cashier, Collection by Payment Method, Collection Efficiency, Subscriber Receivables Aging, Unapplied Payments & Credits, Delinquency Report | View, Print |

Special permissions: `isp_max_adjustment` = 1000

## Collection Officer

Follows up overdue subscribers: verifies bank / e-wallet payment references, records field collections, payment arrangements and collection notes; sees the aging, delinquency and disconnection lists.

| Menu | Page | Rights |
|---|---|---|
| Home | Home Dashboard | View |
| Subscribers | ISP Operations Dashboard | View, Print |
| Subscribers | Subscriber Center | View, Print |
| Subscribers | Subscriber Account | View, Edit, Print |
| Billing & Collection | Collect Payment | View, Create, Print |
| Billing & Collection | Payments & Verification | View, Create, Edit, Print |
| Billing & Collection | Cashier Shifts & Cash Count | View, Create, Print |
| Billing & Collection | Disputes, Arrangements & Exemptions | View, Create, Edit, Print |
| Billing & Collection | Statement of Account | View, Print |
| Network & Service Control | Disconnection Queue | View, Print |
| Network & Service Control | Disconnected Accounts & Recovery | View, Create, Edit, Print |
| Billing & Collection | Reports (8): Unpaid Invoices, Daily Collection, Collection by Cashier, Collection by Payment Method, Collection Efficiency, Subscriber Receivables Aging, Unapplied Payments & Credits, Delinquency Report | View, Print |
| Network & Service Control | Reports (2): Accounts Due for Disconnection, Outstanding Disconnected Accounts | View, Print |

## Credit Committee

Reviews and approves loan applications and waivers; views loan reports.

| Menu | Page | Rights |
|---|---|---|
| Home | Home Dashboard | View, Print, Approve |
| Home | Report Center | View, Print, Approve |
| Loans | Loan Application Center | View, Print, Approve |
| Loans | Loan Application Form | View, Print, Approve |
| Loans | Credit Investigation | View, Print, Approve |
| Loans | Loan Accounts Center | View, Print, Approve |
| Loans | Interest / Penalty Waiving | View, Print, Approve |
| Loans | Loan Documents | View, Print, Approve |
| Parties | Party Center | View, Print |
| Loans | Reports (17): Loan Product List, Schedule of Loans Receivable, Loan Releases, Loan Due vs. Collection, Loans Due for Collection, Loan Collection List, Loans With No Payments, Loan Statement of Account, Aging of Loans by Maturity, Aging of Loans at PAR, Aging of Loans by Amortization, Loan Portfolio Analysis, Co-maker / Guarantor Exposure, Collateral Register, Schedule of Loans vs GL, Waivers and Adjustments, Written-off Loans and Recoveries | View, Print |
| Parties | Reports (1): Consolidated Party Statement | View, Print |

Special permissions: `coop_all_branches`, `coop_view_confidential`, `coop_waive`

## Loan Officer

Loan applications, credit investigation, loan release, collection, loan accounts and loan reports.

| Menu | Page | Rights |
|---|---|---|
| Home | Home Dashboard | View, Create, Edit, Print |
| Home | Report Center | View, Create, Edit, Print |
| Home | Memorized Reports | View, Create, Edit, Print |
| Home | Saved Report Layouts | View, Create, Edit, Print |
| Home | Search | View, Create, Edit, Print |
| Home | Find | View, Create, Edit, Print |
| Members & Others | Member Center | View, Create, Edit, Print |
| Members & Others | Member Information | View, Create, Edit, Print |
| Members & Others | Member Statement of Account | View, Create, Edit, Print |
| Savings & Deposits | Savings Account Center | View, Create, Edit, Print |
| Loans | Loan Application Center | View, Create, Edit, Print |
| Loans | Loan Application Form | View, Create, Edit, Print |
| Loans | Credit Investigation | View, Create, Edit, Print |
| Loans | Loan Release | View, Create, Edit, Print |
| Loans | Loan Release Vouchers | View, Create, Edit, Print |
| Loans | Loan Accounts Center | View, Create, Edit, Print |
| Loans | Loan Adjustments | View, Create, Edit, Print |
| Loans | Interest / Penalty Waiving | View, Create, Edit, Print |
| Loans | Restructure / Renew / Write Off | View, Create, Edit, Print |
| Loans | Loan Documents | View, Create, Edit, Print |
| Loans | Post Loan Penalties & Interest Accrual | View, Create, Edit, Print |
| Parties | Party Center | View, Create, Edit, Print |
| Home | Reports (1): Find Results | View, Print |
| Members & Others | Reports (1): Member Master List | View, Print |
| Loans | Reports (17): Loan Product List, Schedule of Loans Receivable, Loan Releases, Loan Due vs. Collection, Loans Due for Collection, Loan Collection List, Loans With No Payments, Loan Statement of Account, Aging of Loans by Maturity, Aging of Loans at PAR, Aging of Loans by Amortization, Loan Portfolio Analysis, Co-maker / Guarantor Exposure, Collateral Register, Schedule of Loans vs GL, Waivers and Adjustments, Written-off Loans and Recoveries | View, Print |
| Parties | Reports (2): Consolidated Party Statement, Party List | View, Print |

Special permissions: `coop_max_release` = 100000, `coop_override_allocation`, `coop_view_confidential`

## Membership Officer

Member records, member statements, savings account opening and the member reports.

| Menu | Page | Rights |
|---|---|---|
| Home | Home Dashboard | View, Create, Edit, Print |
| Home | Report Center | View, Create, Edit, Print |
| Home | Memorized Reports | View, Create, Edit, Print |
| Home | Saved Report Layouts | View, Create, Edit, Print |
| Home | Search | View, Create, Edit, Print |
| Home | Find | View, Create, Edit, Print |
| Members & Others | Member Center | View, Create, Edit, Print |
| Members & Others | Member Information | View, Create, Edit, Print |
| Members & Others | Member Statement of Account | View, Create, Edit, Print |
| Members & Others | Member Types, Clusters & Other Lists | View, Create, Edit, Print |
| Members & Others | Passbook Types | View, Create, Edit, Print |
| Savings & Deposits | Savings Account Center | View, Create, Edit, Print |
| Savings & Deposits | Passbook Printing | View, Create, Edit, Print |
| Parties | Party Center | View, Create, Edit, Print |
| Home | Reports (1): Find Results | View, Print |
| Members & Others | Reports (10): Member Master List, Membership Movement, Share Capital Balances, Capital Build-up, Statement of Financial Condition, Statement of Operations, Statement of Changes in Equity, Statutory Funds, Subsidiary vs General Ledger Reconciliation, Document Print History | View, Print |
| Savings & Deposits | Reports (3): Savings List, Savings Account Balances, Savings Account Statement | View, Print |
| Parties | Reports (2): Consolidated Party Statement, Party List | View, Print |

Special permissions: `coop_view_confidential`

## Customer Service Representative

Front desk and hotline: subscriber look-up, applications, support tickets, service requests, statements of account, announcements; no collections, adjustments or network commands.

| Menu | Page | Rights |
|---|---|---|
| Home | Home Dashboard | View |
| Subscribers | ISP Operations Dashboard | View, Print |
| Subscribers | Subscriber Center | View, Print |
| Subscribers | Subscriber Account | View, Edit, Print |
| Subscribers | Applications & Activation | View, Create, Edit, Print |
| Subscribers | Contracts & Renewals | View, Print |
| Subscribers | Service Requests | View, Create, Edit, Print |
| Billing & Collection | Payments & Verification | View, Print |
| Billing & Collection | Invoices | View, Print |
| Billing & Collection | Disputes, Arrangements & Exemptions | View, Create, Print |
| Billing & Collection | Statement of Account | View, Print |
| Network & Service Control | Network Outages | View, Print |
| Field Service | Support Tickets | View, Create, Edit, Print |
| ISP Setup | Templates & Announcements | View, Create, Edit, Print |
| Subscribers | Reports (2): Subscriber Master List, Customer Notification History | View, Print |

## Inventory Custodian

The network stockroom: equipment and material items, receiving, transfers to technicians, returns, damaged stock, counts, serial numbers and the inventory reports.

| Menu | Page | Rights |
|---|---|---|
| Home | Home Dashboard | View |
| Field Service | Work Orders & Dispatch | View, Print |
| Field Service | Equipment Register | View, Create, Edit, Delete, Print |
| Vendors | Create Purchase Orders | View, Create, Print |
| Vendors | Receive Items | View, Create, Edit, Print |
| Inventory | Inventory Center | View, Print |
| Inventory | Item List | View, Create, Edit, Print |
| Inventory | Category List | View, Create, Edit, Print |
| Inventory | Inventory Site List | View, Create, Edit, Print |
| Inventory | Transfer Inventory | View, Create, Edit, Print |
| Inventory | Adjust Quantity/Value on Hand | View, Create, Edit, Print |
| Inventory | Physical Inventory Count | View, Print |
| Field Service | Reports (2): Equipment Failures & Replacements, Materials Issued & Returned | View, Print |
| Inventory | Reports (19): Quantity on Hand by Site, Inventory Valuation Summary, Inventory Valuation Summary by Site, Inventory Valuation Detail, Inventory Stock Status by Item, Inventory Stock Status by Vendor, Inventory Stock Status by Site, Physical Inventory Worksheet, Pending Builds, Pending Builds by Site, Processing & Disassembly Register, Co-product & By-product Cost Allocation, Processing Yield Analysis, Negative Inventory Listing, No. of Days Inventory Has No Movement, Customized Inventory Report, Inventory Movement Summary Report, Inventory Movement Detailed Report, Inventory Movement Transactions | View, Print |

Special permissions: `view_costs`

## POS Supervisor

Everything a cashier can do, plus approving voids, refunds, price overrides, and large discounts, and running X- and Z-readings.

| Menu | Page | Rights |
|---|---|---|
| Home | Home Dashboard | View |
| Home | Report Center | View |
| Point of Sale | POS Register | View, Create, Edit, Delete, Print, Approve |
| Point of Sale | Shifts, Cash Drops & Readings | View, Create, Edit, Delete, Print, Approve |
| Point of Sale | POS Receipts, Returns & Voids | View, Create, Edit, Delete, Print, Approve |
| Point of Sale | Approval Codes | View, Create, Edit, Delete, Print, Approve |
| Point of Sale | POS Terminals | View, Print |
| Point of Sale | Reports (10): POS Sales by Transaction No. (detail), POS Sales by Item, POS Sales by Category, POS Sales by Cashier & Payment Method, POS Sales by Payment Method, POS Sales by Hour, POS Daily Sales Summary, X- and Z-Reading Summary, POS Discount Log, POS Void and Refund Log | View, Print |

Special permissions: `max_amount` = 20000, `max_discount_pct` = 30, `max_price_override_pct` = 20

## A/R Clerk

Customers module transactions and the Customer and Receivables reports.

| Menu | Page | Rights |
|---|---|---|
| Home | Home Dashboard | View, Print |
| Home | Report Center | View, Print |
| Home | Memorized Reports | View, Print |
| Home | Saved Report Layouts | View, Print |
| Home | Search | View, Print |
| Home | Find | View, Print |
| Customers | Customer Center | View, Create, Edit, Print |
| Customers | Create Estimates | View, Create, Edit, Print |
| Customers | Create Sales Orders | View, Create, Edit, Print |
| Customers | Create Invoices | View, Create, Edit, Print |
| Customers | Create Batch Invoices | View, Create, Edit, Print |
| Customers | Enter Sales Receipts | View, Create, Edit, Print |
| Customers | Enter Statement Charges | View, Create, Edit, Print |
| Customers | Create Statements | View, Create, Edit, Print |
| Customers | Assess Finance Charges | View, Create, Edit, Print |
| Customers | Set Up Forecast | View, Create, Edit, Print |
| Customers | Receive Payments | View, Create, Edit, Print |
| Customers | Create Credit Memos/Refunds | View, Create, Edit, Print |
| Inventory | Item List | View |
| Parties | Party Center | View, Create, Edit, Print |
| Home | Reports (1): Find Results | View, Print |
| Customers | Reports (12): A/R Aging Summary, A/R Aging Detail, Customer Balance Summary, Customer Balance Detail, Open Invoices, Collections Report, Average Days to Pay Summary, Average Days to Pay, Accounts Receivable Graph, Unbilled Cost by Job, Transaction List by Customer, Item Price List | View, Print |
| Parties | Reports (2): Consolidated Party Statement, Party List | View, Print |

Special permissions: `max_amount` = 50000, `max_discount_pct` = 10, `max_price_override_pct` = 5

## A/P Clerk

Vendors module transactions, Pay Bills, advances to suppliers, the Vendors and Payables reports, and BIR Form 2307 for suppliers.

| Menu | Page | Rights |
|---|---|---|
| Home | Home Dashboard | View, Print |
| Home | Report Center | View, Print |
| Home | Memorized Reports | View, Print |
| Home | Saved Report Layouts | View, Print |
| Home | Search | View, Print |
| Home | Find | View, Print |
| Vendors | Vendor Center | View, Create, Edit, Print |
| Vendors | Bill Tracker | View, Create, Edit, Print |
| Vendors | Enter Bills | View, Create, Edit, Print |
| Vendors | Supplier Debit Memos | View, Create, Edit, Print |
| Vendors | Pay Bills | View, Create, Edit, Print |
| Vendors | Receipt Management | View, Create, Edit, Print |
| Vendors | Create Purchase Orders | View, Create, Edit, Print |
| Vendors | Receive Items and Enter Bills | View, Create, Edit, Print |
| Vendors | Receive Items | View, Create, Edit, Print |
| Vendors | Enter Bill for Received Items | View, Create, Edit, Print |
| Vendors | Advances to Suppliers | View, Create, Edit, Print |
| Inventory | Item List | View |
| Parties | Party Center | View, Create, Edit, Print |
| BIR Reports | Print BIR Form 2307 | View, Print |
| BIR Reports | Withholding Tax Table | View |
| Home | Reports (1): Find Results | View, Print |
| Vendors | Reports (10): A/P Aging Summary, A/P Aging Detail, Vendor Balance Summary, Vendor Balance Detail, Unpaid Bills Detail, Transaction List by Vendor, Vendor Contact List, Expenses by Vendor Summary, Expenses by Vendor Detail, Advances to Suppliers | View, Print |
| Parties | Reports (2): Consolidated Party Statement, Party List | View, Print |
| BIR Reports | Reports (4): Summary of Taxes Withheld from Vendors/Suppliers, Expanded Withholding Tax Register (1% / 2%), Withholding Tax Remittances (0619-E, 1601-EQ, 1600-VT), VAT Withholding Register (1600-VT) | View, Print |

Special permissions: `max_amount` = 100000, `view_costs`

## Inventory Clerk

Items, sites, receiving, adjustments, assembly builds, disassembly and processing orders, stock counts, and inventory quantity reports.

| Menu | Page | Rights |
|---|---|---|
| Home | Home Dashboard | View, Print |
| Home | Report Center | View, Print |
| Home | Memorized Reports | View, Print |
| Home | Saved Report Layouts | View, Print |
| Home | Search | View, Print |
| Home | Find | View, Print |
| Vendors | Create Purchase Orders | View, Print |
| Vendors | Receive Items | View, Create, Edit, Print |
| Inventory | Inventory Center | View, Print |
| Inventory | Item List | View, Create, Edit, Print |
| Inventory | Category List | View, Create, Edit, Delete, Print |
| Inventory | Inventory Site List | View, Create, Edit, Print |
| Inventory | U/M Set List | View, Create, Edit, Print |
| Inventory | Processing Formulas (Multi-Output BOM) | View, Create, Edit, Print |
| Inventory | Transfer Inventory | View, Create, Edit, Print |
| Inventory | Adjust Quantity/Value on Hand | View, Create, Edit, Print |
| Inventory | Build Assemblies | View, Create, Edit, Print |
| Inventory | Disassemble / Process Items | View, Create, Edit, Print |
| Inventory | Physical Inventory Count | View, Print |
| Home | Reports (1): Find Results | View, Print |
| Inventory | Reports (14): Quantity on Hand by Site, Inventory Stock Status by Item, Inventory Stock Status by Vendor, Inventory Stock Status by Site, Physical Inventory Worksheet, Pending Builds, Pending Builds by Site, Processing & Disassembly Register, Processing Yield Analysis, Negative Inventory Listing, No. of Days Inventory Has No Movement, Inventory Movement Summary Report, Inventory Movement Detailed Report, Inventory Movement Transactions | View, Print |

Special permissions: `max_amount` = 20000

## Teller

Teller shift, official receipts, savings deposits and withdrawals, loan collection and passbook printing.

| Menu | Page | Rights |
|---|---|---|
| Home | Home Dashboard | View, Create, Print |
| Members & Others | Member Center | View, Create, Print |
| Teller | Teller Shift & Cash Count | View, Create, Print |
| Teller | Savings Transaction Center | View, Create, Print |
| Teller | Loan Collection | View, Create, Print |
| Teller | Official Receipt (Member Collection) | View, Create, Print |
| Teller | Member Disbursement (Cash Voucher) | View, Create, Print |
| Teller | Official Receipt List | View, Create, Print |
| Savings & Deposits | Savings Account Center | View, Create, Print |
| Savings & Deposits | Savings Account Transaction | View, Create, Print |
| Savings & Deposits | Passbook Printing | View, Create, Print |
| Savings & Deposits | Time Deposit Center | View, Create, Print |
| Savings & Deposits | Check Clearing | View, Create, Print |
| Parties | Party Center | View, Print |
| Teller | Reports (3): Teller Transaction Journal, Daily Collection & Disbursement Summary, Official Receipt Register | View, Print |
| Savings & Deposits | Reports (1): Savings Account Statement | View, Print |
| Parties | Reports (1): Consolidated Party Statement | View, Print |

Special permissions: `coop_max_release` = 100000, `coop_max_withdrawal` = 50000

## Cashier

Collects subscriber payments in an open cashier shift, prints collection receipts, counts the cash at the end of the shift; also the POS register at the assigned terminal.

| Menu | Page | Rights |
|---|---|---|
| Home | Home Dashboard | View |
| Subscribers | Subscriber Center | View, Print |
| Subscribers | Subscriber Account | View, Print |
| Billing & Collection | Collect Payment | View, Create, Print |
| Billing & Collection | Payments & Verification | View, Create, Print |
| Billing & Collection | Cashier Shifts & Cash Count | View, Create, Print |
| Billing & Collection | Statement of Account | View, Print |
| Point of Sale | POS Register | View, Create, Print |
| Point of Sale | Shifts, Cash Drops & Readings | View, Create, Print |
| Point of Sale | POS Receipts, Returns & Voids | View, Create, Delete, Print |
| Billing & Collection | Reports (2): Daily Collection, Collection by Cashier | View, Print |

Special permissions: `max_discount_pct` = 5

## Collector

Loan collection and the collection, due and aging reports.

| Menu | Page | Rights |
|---|---|---|
| Home | Home Dashboard | View, Create, Print |
| Teller | Teller Shift & Cash Count | View, Create, Print |
| Teller | Loan Collection | View, Create, Print |
| Teller | Official Receipt (Member Collection) | View, Create, Print |
| Loans | Loan Accounts Center | View, Create, Print |
| Parties | Party Center | View, Print |
| Teller | Reports (1): Teller Transaction Journal | View, Print |
| Loans | Reports (8): Loan Due vs. Collection, Loans Due for Collection, Loan Collection List, Loans With No Payments, Loan Statement of Account, Aging of Loans by Maturity, Aging of Loans at PAR, Aging of Loans by Amortization | View, Print |
| Parties | Reports (1): Consolidated Party Statement | View, Print |

## Sales Representative

Estimates, sales orders, and invoices, and the Sales reports.

| Menu | Page | Rights |
|---|---|---|
| Home | Home Dashboard | View, Print |
| Home | Report Center | View, Print |
| Home | Memorized Reports | View, Print |
| Home | Saved Report Layouts | View, Print |
| Home | Search | View, Print |
| Home | Find | View, Print |
| Customers | Customer Center | View, Print |
| Customers | Create Estimates | View, Create, Edit, Print |
| Customers | Create Sales Orders | View, Create, Edit, Print |
| Customers | Create Invoices | View, Create, Edit, Print |
| Home | Reports (1): Find Results | View, Print |
| Customers | Reports (15): Item Price List, Sales by Customer Summary, Forecast vs Actual by Customer, Sales by Customer Detail, Income by Customer Summary, Income by Customer Detail, Sales by Item Summary, Sales by Item Detail, Sales by Rep Summary, Sales by Rep Detail, Inventory Sales by Site, Sales Report Through Inventory Movement, Pending Sales, Open Sales Orders by Customer, Open Sales Orders by Item | View, Print |
| Inventory | Reports (1): Inventory Movement Transactions | View, Print |

Special permissions: `max_discount_pct` = 5

## Sales Agent

Signs up subscribers: new applications (own), checks coverage and plans, follows the status of the applications and sees own commissions.

| Menu | Page | Rights |
|---|---|---|
| Subscribers | Applications & Activation | View, Create, Print |
| Subscribers | Sales Agents & Commissions | View, Print |

## Maintenance Technician

Field repairs: own task list, repair, relocation, recovery and replacement work orders and the tickets assigned, materials used and returned. No billing or financial pages.

| Menu | Page | Rights |
|---|---|---|
| Network & Service Control | Infrastructure & Ports | View, Print |
| Network & Service Control | Network Map | View, Print |
| Field Service | My Tasks Today | View, Create, Edit, Print |
| Field Service | Work Orders & Dispatch | View, Edit, Print |
| Field Service | Support Tickets | View, Edit, Print |

## Installation Technician

Field installer: own task list, accepts and completes installation work orders, records materials used, serial numbers, signal readings, photos and the subscriber acceptance. No billing or financial pages.

| Menu | Page | Rights |
|---|---|---|
| Network & Service Control | Network Map | View, Print |
| Field Service | My Tasks Today | View, Create, Edit, Print |
| Field Service | Work Orders & Dispatch | View, Edit, Print |
