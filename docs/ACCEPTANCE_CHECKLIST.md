# Manual acceptance checklist

Run on the **sample database** (`gsmart_coop_cable_sample.sql`) with the accounts of [USER_ACCOUNTS.md](USER_ACCOUNTS.md),
then repeat the workflows that matter to you on a copy of your own data. Tick each line; note the document numbers.
The automated suites are `php tests/isp/run.php` (cable / internet) and `php tests/coop/run.php` (cooperative and
accounting); both rebuild a throw-away database of their own.

Abbreviations: **S** Subscribers menu, **B** Billing & Collection, **N** Network & Service Control, **F** Field Service,
**X** ISP Setup.

## 0. Installation

- [ ] The SQL file imports without errors on the target MySQL / MariaDB; both databases exist.
- [ ] Log-in works for the Company Administrator, a technician, a cashier and the Super Admin (platform pages).
- [ ] The dashboard shows "Simulated network" while the simulator is the default device.
- [ ] The worker runs (`php cron/isp_worker.php`) and X > Scheduled Jobs Monitor shows each job's last run.

## A. New subscriber

- [ ] S > Applications & Activation > New: a walk-in application with ID, address, area, plan, privacy consent.
- [ ] Verification, site survey (feasible, a free NAP port reserved, quotation), contract accepted, approval.
- [ ] Approval cannot be done without the required steps (try approving a new application: refused with the reason).
- [ ] The installation work order exists; F > Work Orders & Dispatch: schedule a technician; issue the ONU (serial)
      and materials from the network stockroom (the stock leaves the stockroom for the technician's site).
- [ ] As the installation technician (phone-size browser): F > My Tasks Today: accept, start, checklist, drop length,
      Rx power, ONU serial, photo, customer acceptance, complete.
- [ ] The account is provisioned (N > Network Operations: confirmed, marked Simulated), active, the first invoice
      issued per the plan's policy (installation fee, prorated first month or advance month, deposit), the welcome
      and activation notices queued (X > Notifications log).
- [ ] The materials consumed are in the inventory ledger at average cost, posted to installation cost.

## B. Monthly billing

- [ ] B > Billing Runs > Preview for the next billing date: the accounts due, amounts, warnings.
- [ ] Generate: a draft run; approve; post. Invoices have official SI numbers without gaps; the ledger shows Dr A/R -
      Subscribers, Cr revenue and Output VAT per invoice.
- [ ] Generating the same period again makes no new invoice.
- [ ] A member's account is billed at the member fee.
- [ ] The invoice prints (service invoice with VAT breakdown, TIN, ATP/serial range when set) and the SOA prints.
- [ ] B > Collect Payment as the cashier with an open shift: cash payment allocated to the oldest invoice; partial
      payment leaves the remaining balance; an advance payment leaves a credit applied to the next invoice.
- [ ] GCash reference: recorded as pending, verified by the collection officer, then posted; the same reference is
      refused a second time.
- [ ] Cashier shift close with the denomination count; a shortage is posted to Cashier Over and Short; the accountant
      certifies the shift.

## C. Unpaid subscriber

- [ ] An invoice past its due date becomes overdue (worker: due-date checks); the overdue reminder is queued once.
- [ ] After the grace days N > Disconnection Queue lists the account with its arrears; a disputed or exempted account
      is not listed (or is listed as protected with the reason).
- [ ] Approve; the final notice is sent; execution happens only inside the rule's hours.
- [ ] A payment posted between listing and execution cancels the disconnection (status Skipped: paid).
- [ ] Execution: network operation confirmed, account Disconnected, network state Disabled, notice queued.
- [ ] With the simulator option `fail_ops = disable`: the operation fails, is retried, then a work order is created and
      the account is not shown as disconnected on the device.

## D. Reconnection

- [ ] The disconnected subscriber pays the qualifying amount: a reconnection is requested automatically, executed,
      confirmed, the account active again, the reconnection fee charged per policy, the notice queued.
- [ ] With `fail_ops = enable` on the simulator: the reconnection fails and the account is NOT shown as restored.
- [ ] A manual / temporary reconnection needs the special permission and expires when temporary.

## E. Connectivity complaint

- [ ] F > Support Tickets > New (or from the portal): classified, SLA due time shown; dispatched to a technician as a
      repair work order; materials issued and used; resolved; the customer confirms; closed. The SLA report shows it.

## F. Equipment replacement

- [ ] Replacement work order: the old ONU recorded as returned (damaged) and the new one installed on the account; the
      equipment register and history show both; the network configuration operation is logged.

## G. Cancellation

- [ ] S > the account > Service > Terminate: contract review (lock-in, early-termination fee), final invoice of the
      outstanding balance, disconnection, recovery work order for company-owned equipment, deposit applied or refunded,
      account Terminated; all past invoices and payments remain visible.

## H. Network outage

- [ ] N > Network Outages > declare on a NAP / splitter: affected accounts identified from the port map, announced
      (portal banner + SMS / e-mail queued), repaired, restored, closed; service credits computed for downtime beyond
      the SLA and posted as credit memos after approval.

## I. Incorrect payment / adjustment

- [ ] B > Payments & Verification > a posted payment > Reverse (special permission + reason): the payment and its ledger
      entry voided, the invoices reopened, the account's eligibility recalculated (it can become a disconnection
      candidate again), the audit trail shows who, when and why.
- [ ] B > Adjustments & Write-offs: a credit above the billing officer's limit waits for approval; approved by the
      accountant; the credit memo posted; the invoice balance updated.

## Security and controls

- [ ] A technician opening a billing page gets Access denied, recorded in the audit trail.
- [ ] A branch-limited user sees only the branch's subscribers.
- [ ] PPPoE passwords and device secrets are masked; revealing one is audited.
- [ ] The portal never shows another subscriber's invoice when its id is typed in the address bar.
- [ ] A replayed gateway webhook is ignored (X > Payment Channels & Gateways > events: "Duplicate").
- [ ] Every posted document balances: Financial > Trial Balance; the report Subscriber Ledger vs General Ledger shows
      no difference.

## Portal

- [ ] Register with the account number and the last 4 digits of the mobile number; log in; see balance, invoices, SOA,
      payments; submit a GCash reference with a screenshot (pending); open a ticket; request a plan upgrade; change the
      password; read notifications and the outage announcement. Works on a phone.

## Multi-company

- [ ] As the Super Admin: Companies > New company; log in as its owner; its menus are empty of the first company's
      records but have the chart of accounts, roles, rules and templates; its portal works with its own Company Code.
