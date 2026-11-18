# Menu map

The side menu of the staff pages: every menu, its groups and pages, with the page key used by page access
(System Administration > Page Access Configuration). A user sees only the pages a role gives.

## Home

- **Home** (5): Home Dashboard `home.dashboard`; Report Center `home.reports`; Memorized Reports `home.memorized`; Saved Report Layouts `home.report_layouts`; Find `home.find`

## Subscribers

- **Dashboard** (1): ISP Operations Dashboard `isp.dashboard`
- **Subscribers** (5): Subscriber Center `isp.subscribers`; Applications & Activation `isp.applications`; Contracts & Renewals `isp.contracts`; Service Requests `isp.requests`; Sales Agents & Commissions `isp.agents`
- **Reports: Subscriber Reports** (10): Subscriber Master List `rpt.isp_master`; Active Accounts `rpt.isp_active`; Newly Activated Accounts `rpt.isp_new_activations`; Cancelled Subscriptions `rpt.isp_cancelled`; Subscriber Growth `rpt.isp_growth`; Plan Distribution `rpt.isp_plan_dist`; Subscribers per Barangay `rpt.isp_by_barangay`; Contracts Due for Renewal `rpt.isp_contracts_due`; Agent Performance & Commissions `rpt.isp_agent_perf`; Customer Notification History `rpt.isp_notif_log`

## Billing & Collection

- **Collections** (3): Collect Payment `ispbill.collect`; Payments & Verification `ispbill.payments`; Cashier Shifts & Cash Count `ispbill.shifts`
- **Billing** (5): Billing Runs `ispbill.runs`; Invoices `ispbill.invoices`; Adjustments & Write-offs `ispbill.adjustments`; Disputes, Arrangements & Exemptions `ispbill.disputes`; Statement of Account `ispbill.soa`
- **Reports: Billing Reports** (6): Monthly Billing Register `rpt.isp_billing_register`; Invoice Register `rpt.isp_invoice_register`; Billing Summary `rpt.isp_billing_summary`; Unpaid Invoices `rpt.isp_unpaid`; Billing Adjustments `rpt.isp_adjustments`; Late Charges `rpt.isp_late_charges`
- **Reports: Collection Reports** (7): Daily Collection `rpt.isp_daily_collection`; Collection by Cashier `rpt.isp_cashier_collection`; Collection by Payment Method `rpt.isp_by_method`; Collection Efficiency `rpt.isp_efficiency`; Subscriber Receivables Aging `rpt.isp_aging`; Unapplied Payments & Credits `rpt.isp_unapplied`; Delinquency Report `rpt.isp_delinquency`
- **Reports: Financial Reports** (4): Plan Revenue & Profitability `rpt.isp_plan_profit`; Branch Profitability `rpt.isp_branch_profit`; Revenue per Subscriber `rpt.isp_arpu`; Subscriber Ledger vs General Ledger `rpt.isp_reconcile`

## Network & Service Control

- **Service Control** (3): Disconnection Queue `ispnet.disconnections`; Reconnections `ispnet.reconnections`; Disconnected Accounts & Recovery `ispnet.recovery`
- **Provisioning** (2): Network Operations `ispnet.operations`; Network Devices & Integrations `ispnet.devices`
- **Infrastructure** (3): Infrastructure & Ports `ispnet.infra`; Network Map `ispnet.map`; Network Outages `ispnet.outages`
- **Reports: Disconnection Reports** (6): Accounts Due for Disconnection `rpt.isp_due_disconnect`; Actual Disconnections `rpt.isp_disconnections`; Failed Network Operations `rpt.isp_failed_ops`; Disconnection Reasons `rpt.isp_disc_reasons`; Reconnection History `rpt.isp_recon_history`; Outstanding Disconnected Accounts `rpt.isp_disc_outstanding`
- **Reports: Technical Reports** (3): Outage History & SLA `rpt.isp_outage_history`; Network Capacity Utilization `rpt.isp_capacity`; Network Command Log `rpt.isp_net_commands`

## Field Service

- **Field** (4): My Tasks Today `ispfield.mytasks`; Work Orders & Dispatch `ispfield.workorders`; Technicians & Teams `ispfield.technicians`; Equipment Register `ispfield.equipment`
- **Support** (1): Support Tickets `ispfield.tickets`
- **Reports: Technical Reports** (7): Installation Progress `rpt.isp_install_progress`; Technician Productivity `rpt.isp_tech_productivity`; Open Work Orders `rpt.isp_open_wo`; Ticket Resolution Times `rpt.isp_ticket_times`; Equipment Failures & Replacements `rpt.isp_equipment_failures`; Installation Profitability `rpt.isp_install_profit`; Materials Issued & Returned `rpt.isp_materials`

## ISP Setup

- **Setup** (7): Internet Plans & Packages `ispset.plans`; Service Areas `ispset.areas`; Billing & Disconnection Policies `ispset.policies`; Notifications (SMS / E-mail) `ispset.notifications`; Payment Channels & Gateways `ispset.gateways`; Templates & Announcements `ispset.templates`; Scheduled Jobs Monitor `ispset.jobs`

## Financial

- **Lists** (5): Chart of Accounts `coa.accounts`; Account Types `coa.account_types`; Class List `coa.classes`; Employees & Other Names `coa.names`; Memorized Transactions `coa.memorized_txns`
- **Setup** (3): Set Up Budgets `coa.budgets`; Module Default Accounts `coa.default_accounts`; Terms, Tax Codes & Other Lists `coa.lists`
- **Transactions** (2): Make Journal Entries `coa.journal`; Journal Entry Changes `coa.je_edits`
- **Reports: Reports** (17): Trial Balance `rpt.trial_balance`; Balance Sheet `rpt.balance_sheet`; Balance Sheet Summary `rpt.balance_sheet_summary`; Balance Sheet by Class `rpt.balance_sheet_class`; Trial Balance by Date Range `rpt.trial_balance_range`; Trial Balance of Totals `rpt.trial_balance_totals`; Balance Sheet by Date Range `rpt.balance_sheet_range`; Income Statement vs Budget `rpt.budget_income`; Balance Sheet vs Budget `rpt.budget_balance`; Income Statement `rpt.income_statement`; Income Statement Summary `rpt.income_statement_summary`; Income Statement by Class `rpt.income_statement_class`; Statement of Cash Flows `rpt.cash_flow`; General Ledger `rpt.general_ledger`; Subsidiary Ledger `rpt.subsidiary_ledger`; Schedule of Accounts `rpt.schedule_of_accounts`; Journal `rpt.journal`

## Members & Others

- **Transactions** (3): Member Center `coop.members`; Member Statement of Account `coop.member_statement`; Opening Balances Import `coop.import`
- **Setup** (7): Cooperative Profile & Policies `coop.profile`; Branches `coop.branches`; Member Types, Clusters & Other Lists `coop.lists`; Passbook Types `coop.passbooks`; Document Numbering `coop.numbering`; Document Templates `coop.templates`; Lines of Business `coop.business_units`
- **Reports: Reports** (11): Member Master List `rpt.coop_members`; Membership Movement `rpt.coop_member_movement`; Share Capital Balances `rpt.coop_share_capital`; Capital Build-up `rpt.coop_cbu`; Statement of Financial Condition `rpt.coop_sfc`; Statement of Operations `rpt.coop_sop`; Statement of Changes in Equity `rpt.coop_sce`; Statutory Funds `rpt.coop_statutory_funds`; Subsidiary vs General Ledger Reconciliation `rpt.coop_sl_gl`; Document Print History `rpt.coop_document_prints`; Statement of Operations by Line of Business `rpt.sop_business_unit`

## Teller

- **Transactions** (5): Teller Shift & Cash Count `teller.shift`; Savings Transaction Center `sav.center`; Loan Collection `loan.collection`; Official Receipt (Member Collection) `teller.or`; Official Receipt List `teller.receipts`
- **Reports: Reports** (5): Teller Transaction Journal `rpt.teller_journal`; Daily Collection & Disbursement Summary `rpt.teller_daily`; Teller Over / Short `rpt.teller_over_short`; Official Receipt Register `rpt.teller_or_list`; Cash Position `rpt.teller_cash_position`

## Savings & Deposits

- **Transactions** (8): Savings Account Center `sav.accounts`; Savings Account Transaction `sav.transact`; Transfer Between Accounts `sav.transfer`; Post Savings Interest `sav.interest`; Passbook Printing `sav.passbook`; Time Deposit Center `sav.td`; Batch Deductions (Payroll / CBU) `sav.batch`; Check Clearing `sav.clearing`
- **Setup** (2): Savings & Deposit Products `sav.products`; Savings Transaction Codes `sav.txn_codes`
- **Reports: Reports** (9): Savings List `rpt.sav_list`; Savings Journal `rpt.sav_journal`; Savings Account Balances `rpt.sav_balances`; Schedule of Savings Accounts `rpt.sav_schedule`; Dormant Accounts `rpt.sav_dormant`; Interest Posting Register `rpt.sav_interest`; Time Deposits by Product `rpt.td_list`; Time Deposit Maturity Schedule `rpt.td_maturity`; Time Deposit Accrued Interest `rpt.td_accrued`

## Loans

- **Transactions** (8): Loan Application Center `loan.applications`; Loan Release `loan.release`; Loan Release Vouchers `loan.vouchers`; Loan Accounts Center `loan.accounts`; Loan Adjustments `loan.adjustments`; Interest / Penalty Waiving `loan.waivers`; Restructure / Renew / Write Off `loan.restructure`; Post Loan Penalties & Interest Accrual `loan.penalties`
- **Setup** (3): Loan Products `loan.products`; Loan Charges `loan.charges`; Loan Approval Matrix `loan.approval_matrix`
- **Reports: Reports** (7): Loan Product List `rpt.loan_products`; Schedule of Loans Receivable `rpt.loan_schedule_recv`; Co-maker / Guarantor Exposure `rpt.loan_comaker`; Collateral Register `rpt.loan_collateral`; Schedule of Loans vs GL `rpt.loan_gl_compare`; Waivers and Adjustments `rpt.loan_waivers`; Written-off Loans and Recoveries `rpt.loan_writeoffs`
- **Reports: Loans Summary** (6): Loan Releases `rpt.loan_releases`; Loan Due vs. Collection `rpt.loan_due_vs_coll`; Loans Due for Collection `rpt.loan_due`; Loan Collection List `rpt.loan_collections`; Loans With No Payments `rpt.loan_no_payments`; Loan Statement of Account `rpt.loan_soa`
- **Reports: Loans Aging** (4): Aging of Loans by Maturity `rpt.loan_aging_maturity`; Aging of Loans at PAR `rpt.loan_aging_par`; Aging of Loans by Amortization `rpt.loan_aging_amort`; Loan Portfolio Analysis `rpt.loan_portfolio`

## Banking

- **Transactions** (6): Write Checks `bank.write_checks`; Cash Voucher `bank.cash_voucher`; Use Register `bank.register`; Make Deposits `bank.deposits`; Transfer Funds `bank.transfers`; Reconcile `bank.reconcile`
- **Reports: Reports** (4): Reconciliation Summary `rpt.recon_summary`; Reconciliation Detail `rpt.recon_detail`; Reconciliation Discrepancy `rpt.recon_discrepancy`; Check Register `rpt.check_register`

## Company

- **Company** (3): Company Profile & Code `company.profile`; Employees & Users `company.users`; Join Requests `company.join_requests`

## Customers

- **Transactions** (12): Customer Center `cust.center`; Create Estimates `cust.estimates`; Create Sales Orders `cust.sales_orders`; Create Invoices `cust.invoices`; Create Batch Invoices `cust.batch_invoices`; Enter Sales Receipts `cust.sales_receipts`; Enter Statement Charges `cust.statement_charges`; Create Statements `cust.statements`; Assess Finance Charges `cust.finance_charges`; Set Up Forecast `cust.forecast`; Receive Payments `cust.payments`; Create Credit Memos/Refunds `cust.credit_memos`
- **Reports: Customer and Receivables Reports** (12): A/R Aging Summary `rpt.ar_aging_summary`; A/R Aging Detail `rpt.ar_aging_detail`; Customer Balance Summary `rpt.customer_balance_summary`; Customer Balance Detail `rpt.customer_balance_detail`; Open Invoices `rpt.open_invoices`; Collections Report `rpt.collections`; Average Days to Pay Summary `rpt.avg_days_summary`; Average Days to Pay `rpt.avg_days_detail`; Accounts Receivable Graph `rpt.ar_graph`; Unbilled Cost by Job `rpt.unbilled_costs`; Transaction List by Customer `rpt.txn_list_customer`; Item Price List `rpt.item_price_list`
- **Reports: Sales Reports** (14): Sales by Customer Summary `rpt.sales_customer_summary`; Forecast vs Actual by Customer `rpt.forecast_vs_actual`; Sales by Customer Detail `rpt.sales_customer_detail`; Income by Customer Summary `rpt.income_customer_summary`; Income by Customer Detail `rpt.income_customer_detail`; Sales by Item Summary `rpt.sales_item_summary`; Sales by Item Detail `rpt.sales_item_detail`; Sales by Rep Summary `rpt.sales_rep_summary`; Sales by Rep Detail `rpt.sales_rep_detail`; Inventory Sales by Site `rpt.inventory_sales_site`; Sales Report Through Inventory Movement `rpt.sales_inv_movement`; Pending Sales `rpt.pending_sales`; Open Sales Orders by Customer `rpt.open_so_customer`; Open Sales Orders by Item `rpt.open_so_item`

## Point of Sale

- **Transactions** (4): POS Register `pos.register`; Shifts, Cash Drops & Readings `pos.shifts`; POS Receipts, Returns & Voids `pos.receipts`; Approval Codes `pos.approval_codes`
- **Setup** (1): POS Terminals `pos.terminals`
- **Reports: Reports** (9): POS Sales by Transaction No. (detail) `rpt.pos_sales_txn`; POS Sales by Item `rpt.pos_sales_item`; POS Sales by Category `rpt.pos_sales_category`; POS Sales by Cashier & Payment Method `rpt.pos_sales_cashier`; POS Sales by Hour `rpt.pos_sales_hour`; POS Daily Sales Summary `rpt.pos_daily_summary`; X- and Z-Reading Summary `rpt.pos_readings`; POS Discount Log `rpt.pos_discount_log`; POS Void and Refund Log `rpt.pos_void_log`

## Vendors

- **Transactions** (11): Vendor Center `vend.center`; Bill Tracker `vend.bill_tracker`; Enter Bills `vend.bills`; Supplier Debit Memos `vend.debit_memos`; Pay Bills `vend.pay_bills`; Receipt Management `vend.receipts`; Create Purchase Orders `vend.purchase_orders`; Receive Items and Enter Bills `vend.receive_bill`; Receive Items `vend.receive_items`; Enter Bill for Received Items `vend.bill_received`; Advances to Suppliers `vend.advances`
- **Reports: Vendors and Payables Reports** (10): A/P Aging Summary `rpt.ap_aging_summary`; A/P Aging Detail `rpt.ap_aging_detail`; Vendor Balance Summary `rpt.vendor_balance_summary`; Vendor Balance Detail `rpt.vendor_balance_detail`; Unpaid Bills Detail `rpt.unpaid_bills`; Transaction List by Vendor `rpt.txn_list_vendor`; Vendor Contact List `rpt.vendor_contacts`; Expenses by Vendor Summary `rpt.expenses_vendor_summary`; Expenses by Vendor Detail `rpt.expenses_vendor_detail`; Advances to Suppliers `rpt.vendor_advances`

## Inventory

- **Transactions** (8): Inventory Center `inv.center`; Transfer Inventory `inv.transfers`; Adjust Quantity/Value on Hand `inv.adjust`; Build Assemblies `inv.build`; Disassemble / Process Items `inv.process`; Physical Inventory Count `inv.physical_count`; Change Item Prices `inv.change_prices`; Set Item Prices and Levels `inv.set_prices`
- **Lists** (5): Item List `inv.items`; Category List `inv.categories`; Inventory Site List `inv.sites`; U/M Set List `inv.uom`; Processing Formulas (Multi-Output BOM) `inv.formulas`
- **Reports: Inventory Reports** (18): Quantity on Hand by Site `rpt.qoh_by_site`; Inventory Valuation Summary `rpt.inv_valuation_summary`; Inventory Valuation Summary by Site `rpt.inv_valuation_site`; Inventory Valuation Detail `rpt.inv_valuation_detail`; Inventory Stock Status by Item `rpt.stock_status_item`; Inventory Stock Status by Vendor `rpt.stock_status_vendor`; Inventory Stock Status by Site `rpt.stock_status_site`; Physical Inventory Worksheet `rpt.physical_worksheet`; Pending Builds `rpt.pending_builds`; Pending Builds by Site `rpt.pending_builds_site`; Processing & Disassembly Register `rpt.processing_register`; Co-product & By-product Cost Allocation `rpt.coproduct_costs`; Processing Yield Analysis `rpt.process_yield`; Negative Inventory Listing `rpt.negative_inventory`; No. of Days Inventory Has No Movement `rpt.inv_no_movement`; Customized Inventory Report `rpt.inv_custom`; Inventory Movement Summary Report `rpt.inv_movement_summary`; Inventory Movement Detailed Report `rpt.inv_movement_detail`

## Fixed Assets

- **Transactions** (5): Fixed Asset Register `fa.register`; Run Depreciation `fa.depreciation`; Dispose of Assets `fa.disposal`; Asset Maintenance `fa.maintenance`; Preventive Maintenance `fa.pm`
- **Setup** (2): Asset Categories `fa.categories`; Asset Locations `fa.locations`
- **Reports: Reports** (10): Fixed Asset Register Report `rpt.fa_register`; Depreciation Schedule by Asset `rpt.fa_schedule`; Depreciation by Category `rpt.fa_by_category`; Additions and Disposals `rpt.fa_additions`; Maintenance History `rpt.fa_maint_history`; Maintenance Cost by Asset `rpt.fa_maint_cost`; Spare Parts Used `rpt.fa_maint_parts`; Maintenance Labor `rpt.fa_maint_labor`; Upcoming Preventive Maintenance `rpt.fa_pm_upcoming`; Completed Preventive Maintenance `rpt.fa_pm_log`

## Parties

- **Transactions** (2): Party Center `party.center`; Identity Review `party.review`
- **Reports: Reports** (4): Consolidated Party Statement `party.statement`; Party List `rpt.party_list`; Party Role History `rpt.party_role_history`; Reissued Documents `rpt.doc_reissues`

## BIR Reports

- **Transactions** (1): Print BIR Form 2307 `bir.form_2307`
- **Setup** (1): Withholding Tax Table `bir.wht_table`
- **Reports: BIR Reports** (8): Summary of Taxes Withheld from Vendors/Suppliers `rpt.bir_wht_summary`; Expanded Withholding Tax Register (1% / 2%) `rpt.bir_ewt_register`; Withholding Tax Remittances (0619-E, 1601-EQ, 1600-VT) `rpt.bir_remittance`; VAT Withholding Register (1600-VT) `rpt.bir_vat_withholding`; Output VAT / Sales Register `rpt.bir_sales_register`; Input VAT / Purchases Register `rpt.bir_purchase_register`; Creditable VAT Withheld Schedule (2550Q Schedule 3) `rpt.bir_cvat_withheld`; Quarterly VAT Return Worksheet (2550Q) `rpt.bir_2550q`

## Year-end Distribution

- **Transactions** (4): Net Surplus Allocation `dist.surplus`; Interest on Share Capital `dist.isc`; Patronage Refund `dist.pr`; Distribution Batches `dist.batches`
- **Reports: Reports** (3): Distribution Register `rpt.dist_register`; Unclaimed Distributions `rpt.dist_unclaimed`; Net Surplus Allocation Summary `rpt.dist_summary`

## CDA Compliance

- **Setup** (2): Officers, Committees & Trainings `cda.officers`; Report Field Mappings `cda.mappings`
- **Transactions** (3): Compliance Tracking `cda.compliance`; Regulatory Calendar `cda.calendar`; CAPR Data Preparation & Export `cda.capr`
- **Reports: Reports** (2): List of Officers and Committees `rpt.cda_officers`; Compliance Status `rpt.cda_compliance`

## System Administration

- **Setup** (7): User Accounts `admin.users`; User Role Configuration `admin.roles`; Page Access Configuration `admin.page_access`; Effective Access (View as User) `admin.effective_access`; Company, Security & Closing Date `admin.settings`; Utilities: Verify & Rebuild Data `admin.utilities`; Multi-Company Setup `admin.multi_company`
- **Utilities** (2): Import from CSV `admin.import`; Backup & Restore `admin.backup`
- **Reports: Audit Reports** (7): Audit Trail `admin.audit_trail`; Transaction History `admin.txn_history`; Voided and Deleted Transactions Summary `rpt.voided_deleted_summary`; Voided and Deleted Transactions Detail `rpt.voided_deleted_detail`; User Activity `rpt.user_activity`; Login History `rpt.login_history`; User Access and Permission Changes `rpt.user_access`

367 pages and reports in the menu.
