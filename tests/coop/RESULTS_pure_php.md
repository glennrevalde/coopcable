# Cooperative module: automated test results

- Run: 2026-11-02 00:24:37 (Asia/Manila)
- Database: 10.11.14-MariaDB-0ubuntu0.24.04.1 (test database `coop_test`, rebuilt from database/schema.sql + triggers + seed + the 2026_11_01, 2026_11_15, 2026_12_20, 2026_12_27, 2027_01_03, 2027_01_10, 2027_01_17, 2027_01_24, 2027_01_31, 2027_02_21 and 2027_04_01 (cable / internet) migrations)
- PHP 8.3.6, decimal engine: pure PHP
- Command: `php tests/coop/run.php --pure-php`
- Result: **112 passed, 0 failed** (1757 individual checks, 11.1s)

| # | Test | Result | Checks | ms |
|---|------|--------|--------|----|
| 1 | Decimal engine: exact sums, half-away-from-zero rounding, the reference ISC / PR figures | PASS | 17 | 32 |
| 2 | Amortization: equal amortization 20,000 at 18 % for 12 months gives 1,833.60 (reference workbook) | PASS | 12 | 0 |
| 3 | Amortization: fixed principal, straight add-on, lump sum and daily modes | PASS | 14 | 0 |
| 4 | Members: profile, duplicate check, status workflow, history and audit trail | PASS | 16 | 33 |
| 5 | Official receipt: share capital and savings in one receipt, one balanced voucher, shift totals | PASS | 19 | 41 |
| 6 | Savings withdrawal: available balance, minimum balance, supervisor approval for share capital | PASS | 18 | 204 |
| 7 | Teller shift close: cash count by denomination, shortage posted with supervisor approval | PASS | 8 | 86 |
| 8 | Check deposit: held until cleared, cleared to the bank, a returned check reverses it | PASS | 17 | 59 |
| 9 | Transfer between two members: one journal voucher, both subsidiary ledgers | PASS | 7 | 29 |
| 10 | Savings interest: average daily balance, rate tier, posting once, void restores the ledger | PASS | 20 | 94 |
| 11 | Dormancy: no member transaction for the product period, fee charged once per quarter, reactivated by a deposit | PASS | 6 | 46 |
| 12 | Time deposit: placement, rate by term, monthly accrual (once), maturity credited to savings | PASS | 22 | 58 |
| 13 | Time deposit: pre-termination at the reduced rate with the fee; renewal and its void | PASS | 15 | 60 |
| 14 | Loan application: eligibility checks, override with a reason, two-level approval by amount | PASS | 15 | 67 |
| 15 | Loan release: 100,000 less 3 % service fee and 5 % share capital = 92,000 net; one release only | PASS | 23 | 89 |
| 16 | Loan collection: penalty after the grace period, allocation penalty-interest-principal, the rest to the principal (version 35) | PASS | 21 | 65 |
| 17 | Loan payment void: schedule and balances rebuilt; payoff cancels the unearned interest | PASS | 17 | 31 |
| 18 | Loan interest accrual and penalty batch: once per date, cleared by the next collection | PASS | 14 | 66 |
| 19 | Waiver: requested by one user, approved by another; write-off and recovery | PASS | 13 | 20 |
| 20 | Loan renewal: the new loan pays off the old one, which is closed as renewed | PASS | 16 | 36 |
| 21 | Net surplus allocation: statutory funds, ISC and PR pools, posted once after the year end | PASS | 16 | 14 |
| 22 | Interest on share capital: average daily balance x rate, maker-checker, posted once, reversed by adjusting entry | PASS | 15 | 73 |
| 23 | Patronage refund: income earned from each member in the period, kept as payable, paid on a disbursement voucher | PASS | 6 | 18 |
| 24 | Branch isolation: a branch teller sees and touches only the members of the branch | PASS | 7 | 20 |
| 25 | Duplicate submission: one-time form token, unique numbers | PASS | 3 | 1 |
| 26 | Concurrency: two withdrawals at the same moment on one account, only the one the balance covers goes through | PASS | 4 | 1449 |
| 27 | Immutability: posted entries are voided with a reason, never deleted; the audit trail cannot be changed | PASS | 6 | 26 |
| 28 | Closed period: no member transaction on or before the closing date without the permission | PASS | 2 | 2 |
| 29 | Ledger integrity at the end: every voucher balances, subsidiary ledgers = control accounts | PASS | 12 | 2 |
| 30 | Batch deductions (payroll): savings and loan lines checked, nothing posted while a line has an error, one voucher | PASS | 18 | 81 |
| 31 | Passbook: printing starts on line 1, Start Line Skip leaves the fold blank, confirmed page by page, reprint and new passbook logged | PASS | 10 | 61 |
| 32 | Opening balances import: members, savings, time deposits and loans checked first, posted all or nothing, ledgers agree | PASS | 34 | 59 |
| 33 | Restructuring through an application: the loan paid off is left out of the limits; the new loan is flagged restructured | PASS | 15 | 83 |
| 34 | Disclosure statement: the effective interest rate discounts the amortizations back to the net proceeds | PASS | 5 | 0 |
| 35 | Regulatory figures: CAPR lines from the ledgers; the reconciliation catches a control-account entry made outside the member pages | PASS | 11 | 36 |
| 36 | Dashboard alerts: what needs attention is counted for the user who can act on it | PASS | 4 | 46 |
| 37 | Certificate of balances: each account on a past date from the ledgers; later postings are left out | PASS | 9 | 47 |
| 38 | Statement of Changes in Equity: the allocation moves the surplus to the funds; the ending total is the equity on the Statement of Financial Condition | PASS | 6 | 42 |
| 39 | Members who have left: paid out only as an approved settlement; only loan payments received; a deceased member is paid to the person named | PASS | 10 | 213 |
| 40 | Check deposits wait in the clearing account of their own code, and Check Clearing empties it | PASS | 12 | 113 |
| 41 | Loan set-up rules in effect: a charge applies from its effective date; products set to income when collected are not accrued | PASS | 4 | 20 |
| 42 | Ledger integrity after the additional cases | PASS | 9 | 2 |
| 43 | Party Master: a member, a customer and a supplier record of one person share one Party ID; nothing is linked by name alone | PASS | 24 | 20 |
| 44 | Acceptance scenario: the member buys on credit, pays, delivers goods, is paid by check, saves, borrows, repays and gets a patronage refund | PASS | 92 | 139 |
| 45 | Nonmembers: a nonmember customer and supplier, member and nonmember prices, members-only and approval rules, nothing in patronage | PASS | 24 | 46 |
| 46 | Two branches and lines of business: the party statement by branch, unit statements, inter-unit eliminations | PASS | 21 | 55 |
| 47 | Posted documents: locked against changes and deletion except by the permission with a reason; voids keep the document | PASS | 16 | 37 |
| 48 | Names on issued documents: an edited master does not change old documents; an authorized reissue corrects them with a reason | PASS | 14 | 16 |
| 49 | Roles and identity: revoke and restore keep history; linking duplicates needs the permission and moves records and documents; a wrong link is moved back | PASS | 28 | 40 |
| 50 | Permissions: confidential member details, page access of the new pages, lines of business switched off | PASS | 9 | 8 |
| 51 | Upgrade of data recorded before the Party Master: one party per record, never merged by name, crosswalk, snapshots, nothing posted changes, safe to run twice | PASS | 14 | 77 |
| 52 | Ledger integrity after the multipurpose cases: every document balances, every subsidiary ledger agrees with its control account | PASS | 21 | 16 |
| 53 | Class required: refused without one or with an inactive one; the voucher and every ledger line carry the class chosen | PASS | 9 | 37 |
| 54 | Journal entry changes: an explanation, a second user with the Approve right, the entry saved with the change; refused when the entry changed meanwhile | PASS | 20 | 52 |
| 55 | Member grouping lists: offered on the member form, renamed on the members that have them, not deleted while in use | PASS | 9 | 13 |
| 56 | Members on the name lists by their type, and on the customer lists | PASS | 8 | 11 |
| 57 | Member defaults: every member has its own customer and supplier record, which follow the member and carry the Customer and Vendor Defaults | PASS | 20 | 36 |
| 58 | One name: the member is listed once on the name pickers; the entry is recorded on the record of the role the member plays | PASS | 30 | 45 |
| 59 | Savings Transaction Center: one transaction number series; the code decides the entry (User Account = the teller's cash, a GL account of its own); cash in the teller's shift | PASS | 25 | 50 |
| 60 | User profile cash account: a teller without a cash account of their own transacts with the cash account of their profile | PASS | 3 | 107 |
| 61 | Error correct: the erroneous transaction is negated in full (negative in its column), the entry reversed, never twice; a voided correction frees it again | PASS | 27 | 101 |
| 62 | Check deposits, memos, back entries and deletion: cheque details required; a memo takes the GL account chosen; a back entry needs a supervisor; a printed transaction is corrected, not deleted | PASS | 23 | 100 |
| 63 | R Check, close and delete account: a returned check is debited (clearing account, or the bank once cleared); closing pays the balance out with the closing code; only an unused account is deleted | PASS | 28 | 135 |
| 64 | Time deposit in the center and its accrued interest withdrawal (CAIW): numbered transactions; the interest paid comes out of the accrual, the principal stays; the settlement pays the rest; a void puts it back | PASS | 22 | 100 |
| 65 | Passbook: line numbers, the account name and number on every page, the fold left blank, a dash in the empty column, error corrections negative, and the print line reset | PASS | 11 | 140 |
| 66 | Loan Collection Form: the figures of the form (balances, dues as of the date, number of amortizations, last payment, payoff) and the open loans | PASS | 14 | 48 |
| 67 | Loan Collection Form: a check payment and its error correction (EC row negative, journal reversed, balances, schedule, status and last payment back, the cheques off the clearing account); the passbook, the validation and the list show both | PASS | 58 | 69 |
| 68 | Loan Collection Form: Error Correct of a cash payment pays it back from the drawer by a cash voucher; a payoff corrected opens the loan again (rebate undone, holds back); a receipt with other items and a cleared check are corrected another way; a bank payment by a journal voucher | PASS | 26 | 117 |
| 69 | Loan Collection Form: Override Payment applies the amount as entered (permission, must add up, within the balances); the list of collections by date range and search | PASS | 15 | 64 |
| 70 | Slip validation: margins (top, right, bottom, left), line spacing and font from Passbook Types; the former twips positions until they are set | PASS | 4 | 6 |
| 71 | Teller menu: Loan Collection and the Savings Transaction Center are under Teller (the page keys and the access stay); a switched-off line still closes them | PASS | 13 | 13 |
| 72 | Daily Collection & Disbursement Summary: every Loan Collection and Savings Transaction Center transaction of the teller, by source, error corrections taken back, net cash = the drawer | PASS | 21 | 198 |
| 73 | Loan Release Form: Cash release is a Cash Voucher of Banking from the cash account chosen, with the voucher ID, the class on every line and the deductions changed by the user | PASS | 33 | 47 |
| 74 | Cheque release: a check of Write Checks numbered with the cheque no., drawn on the bank account chosen; never edited or deleted, voided with the loan; a cheque no. used once | PASS | 23 | 94 |
| 75 | Loan release vouchers: the monitoring list has every voucher of a release (Banking and before), the approver and the preparer; the teller's cash comes from the drawer of the shift only | PASS | 13 | 65 |
| 76 | Internal Ledger: the additional interest and the penalty by the product set-up (missed amortizations, past maturity), the balances; the Loan Ledger Form in passbook view or every entry | PASS | 27 | 57 |
| 77 | Capacity to Pay: the entries saved on a loan application are where the borrower's next application starts; updated entries replace them, others stay | PASS | 9 | 23 |
| 78 | Credit Investigation: the borrower's last investigation is where the next one starts (residence, children, income, assets, references); an income without a source name is saved | PASS | 9 | 21 |
| 79 | Passbook lines as the former program: printing starts on line 1, Start Line Skip and No. of Line Skip leave the fold blank, after Max Lines line 1 of the next page with the header | PASS | 14 | 78 |
| 80 | Loan passbook: its own design, the header (Loan Type, Term, Due Date), the release on line 1, Release, Loan Payment, Loan Balance, Interest, Fines and Teller ID, an error correction negative | PASS | 16 | 71 |
| 81 | Passbook design: fields placed in tups from the Top / Left of their part, formats, condensed print; what is not of its kind is left out; the settings of before are carried; test pages | PASS | 18 | 9 |
| 82 | Delete a loan application: one never released goes with its co-makers, collateral, approvals, requirements and credit investigation; a released one stays | PASS | 16 | 60 |
| 83 | Payment allocation: nothing due, the whole payment goes to the principal; with dues, 1. penalties, 2. interest, 3. principal | PASS | 23 | 85 |
| 84 | Passbook run: unprinted lines on several pages print in one run, page by page (turn the passbook to page 2, 3 ...), and are marked printed together | PASS | 22 | 192 |
| 85 | Passbook run: the header modes of the design on the pages that follow (on a new page, with every printing, never) and a loan passbook over two pages | PASS | 7 | 244 |
| 86 | Account types: the fifteen built-in ones, types of your own behaving as one and linked to the Statement of Cash Flows; unique names; a built-in type keeps its name and what it is | PASS | 14 | 8 |
| 87 | Giving accounts a type: the account keeps behaving as its built-in type for the modules; an account the modules post to keeps its base; a type in use keeps its base and is not deleted | PASS | 13 | 12 |
| 88 | Statements by account type: each type a classification (heading, accounts, total); a main account with its sub-accounts and their total; the summaries show main accounts only; the totals do not change | PASS | 29 | 38 |
| 89 | Statement of Cash Flows: each account type in its section; cash = the types counted as cash and cash equivalents; the statement ties to them whatever the sections | PASS | 10 | 24 |
| 90 | Main accounts on the statements: a sub-account of another built-in type follows its main account; the main account's own entries; a group of one built-in type has no type heading; depreciation stays an operating adjustment | PASS | 13 | 29 |
| 91 | Loan adjustment in the loan passbook: Print to Passbook prints it (code AJ, a decrease as a payment, an increase negative); the adjustments of before are passed over once | PASS | 14 | 92 |
| 92 | Loan Statement of Account: the search box lists the borrowers with outstanding loans (name, loan product, loan no.), narrowed by a name, a loan no. or a product | PASS | 17 | 119 |
| 93 | Interest on share capital and patronage refund: computed for review (nothing posted), the members who owe the cooperative stand out and are removed with the reason, then posted | PASS | 24 | 195 |
| 94 | Interest on share capital: the average daily balance shown day by day equals the basis of the batch, to the centavo | PASS | 17 | 91 |
| 95 | Patronage refund: the income earned shown document by document equals the basis of the batch | PASS | 6 | 38 |
| 96 | Delete a computation never posted: in review, reviewed or approved, with the reason and the rights; a posted batch is reversed instead | PASS | 20 | 751 |
| 97 | Import Member: every required field checked, the member type and dates as written in the file, the same member twice refused; uploaded all or none, Active | PASS | 14 | 72 |
| 98 | Import Savings: each account opened Active, its balance entered on the cut-off date with the savings transaction code chosen (one voucher per date and code against the code's GL account) | PASS | 16 | 28 |
| 99 | Import Time Deposit: each placement Active with its term, rate and maturity, its principal entered with the code chosen; the term in days, months or years | PASS | 10 | 21 |
| 100 | Batch Deposit and Batch Withdrawal: one transaction of the Savings Transaction Center per row (account no. and amount); the withdrawable balance, holds and restrictions checked; all or none | PASS | 14 | 43 |
| 101 | Hold and Unhold: an amount of the balance earmarked (at most what is withdrawable), the rest withdrawable; released one hold or every hold | PASS | 8 | 17 |
| 102 | Official receipt: every GL account as a fee or other item except those of the savings, time deposits, loans receivable and the receivables and inventory of sales | PASS | 18 | 14 |
| 103 | Import Loans: the terms as a former system writes them (months or days, the mode, the amortization type, a rate a year or a month) | PASS | 4 | 2 |
| 104 | Import Loans: a diminishing loan rebuilt from its release (schedule, the totals paid on the due dates in order, the arrears), Active, one voucher against the balancing account | PASS | 15 | 46 |
| 105 | Import Loans: the overdue penalties worked out by the product's terms on the past-due amortizations, less the penalties paid; or as given | PASS | 6 | 29 |
| 106 | Import Loans: the past due interest of a matured loan, worked out on the principal unpaid from the maturity date at the loan's rate, less the interest paid beyond the schedule; or as given | PASS | 12 | 45 |
| 107 | Import Loans: what is refused (product, mode, type, rate, dates, amounts, the same loan twice, a fully paid loan, too much paid, the member, the closing date) | PASS | 14 | 40 |
| 108 | Import Member, the full template: every other detail of the member as a column the file may have; saved on the member and its lists | PASS | 14 | 23 |
| 109 | The checker of the reviews: a row's problems name its cells (by the column's name in the message, or as the check says), the answer to the page by line | PASS | 3 | 16 |
| 110 | Import Loans, after the review: the past due interest of a loan not yet matured in date order and collected first, the loan no. of the former system never drawn again, the accrued interest, a lump sum, the voucher by class and by cut-off date | PASS | 18 | 196 |
| 111 | Import Member, after the review: a column a problem names is shown so it can be filled in; the employer contact up to 40 characters | PASS | 2 | 4 |
| 112 | Inventory Center history: a back-dated movement takes its place by date, and the balances after each movement follow the dates | PASS | 5 | 17 |
