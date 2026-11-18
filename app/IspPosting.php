<?php
declare(strict_types=1);

/**
 * The one bridge between the cable / internet records and the general ledger. Every ISP event with an accounting
 * effect goes through here and nowhere else:
 *
 *   invoice posted          -> INVOICE of the subscriber's customer: Dr A/R - Subscribers, Cr internet / cable TV /
 *                              installation / rental / late charge / reconnection revenue, Cr Output VAT, Cr the
 *                              security deposit liability; a discount line debits Subscriber Discounts
 *   advance payment applied -> the earlier payment's unapplied credit is applied to the new invoice (payment_applications)
 *   payment posted          -> PAYMENT: Dr the cashier's drawer / bank or e-wallet clearing, Cr A/R, applied to the
 *                              allocated invoices; the rest stays as the subscriber's credit (advance payment)
 *   savings auto-debit      -> a savings WITHDRAWAL of the member (Dr savings deposits, Cr auto-debit clearing) and
 *                              the PAYMENT from the clearing account: the clearing account nets to zero
 *   gateway fee             -> JOURNAL: Dr Payment Gateway Fees, Cr the gateway clearing account
 *   payment reversed        -> the payment (and its fee entry and auto-debit) are VOIDED, linked to the original
 *   credit adjustment       -> CREDIT MEMO applied to the invoice: Dr service credits / discounts / bad debts, Cr A/R
 *   debit adjustment        -> a supplemental INVOICE
 *   deposit applied         -> CREDIT MEMO: Dr Subscriber Security Deposits, Cr A/R
 *   materials issued        -> INVENTORY TRANSFER from the stockroom to the technician's van (no ledger effect)
 *   materials consumed      -> INVENTORY ADJUSTMENT: Dr installation / maintenance cost, Cr inventory, at average cost
 *   cashier over / short    -> JOURNAL: Dr cash Cr over-short, or the reverse
 *   agent commission paid   -> BILL of the agent's supplier record: Dr commissions, Cr A/P
 *
 * Idempotency: every event has a unique key (isp_postings.event_key: INV-12, PAY-40, ADJ-3 ...). The row is written in
 * the same database transaction as the ledger document and the ISP record, so a retry or a rerun of a scheduled job
 * finds the key and gets the existing document back instead of posting twice.
 */
final class IspPosting
{
    private static int $active = 0;

    public static function ready(): bool
    {
        return IspSetup::ready();
    }

    /** The ISP module changes its own ledger documents while the returned object lives (Txn refuses others) */
    public static function owning(): object
    {
        self::$active++;
        return new class {
            public function __destruct()
            {
                IspPosting::release();
            }
        };
    }

    /** @internal */
    public static function release(): void
    {
        self::$active = max(0, self::$active - 1);
    }

    public static function isActive(): bool
    {
        return self::$active > 0;
    }

    public static function ownerOf(int $txnId): ?string
    {
        if ($txnId <= 0 || !self::ready()) {
            return null;
        }
        $k = DB::value("SELECT event_key FROM isp_postings WHERE txn_id = :t AND status <> 'failed' ORDER BY id LIMIT 1", ['t' => $txnId]);
        return $k === null ? null : (string)$k;
    }

    /** Why a change made outside the ISP module to one of its documents is refused (null: it may go ahead) */
    public static function guard(int $txnId, string $what): ?string
    {
        if (self::isActive()) {
            return null;
        }
        $key = self::ownerOf($txnId);
        if ($key === null) {
            return null;
        }
        $doc = (string)(DB::value('SELECT txn_no FROM transactions WHERE id = :t', ['t' => $txnId]) ?? $txnId);
        if ($what === 'apply') {
            return "Document $doc is a cable / internet invoice (ISP event $key). It is collected on Billing & Collection > Collect Payment, so the subscriber ledger and the books are settled together.";
        }
        return "Document $doc belongs to the cable / internet module (ISP event $key) and is not " . ($what === 'edit' ? 'edited' : ($what === 'void' ? 'voided' : 'deleted'))
            . ' here. Correct it on Billing & Collection (Adjustments, or the payment reversal); the correction is linked to the original.';
    }

    public static function byEvent(string $key): ?array
    {
        return DB::row('SELECT * FROM isp_postings WHERE event_key = :k', ['k' => $key]);
    }

    private static function record(array $row): int
    {
        $row += ['status' => 'posted', 'created_by' => Auth::id(), 'amount' => 0];
        if (!empty($row['txn_id']) && !array_key_exists('party_id', $row)) {
            $row['party_id'] = DB::value('SELECT party_id FROM transactions WHERE id = :t', ['t' => $row['txn_id']]) ?: null;
        }
        if (!empty($row['txn_id']) && !array_key_exists('class_id', $row)) {
            $row['class_id'] = DB::value('SELECT class_id FROM transactions WHERE id = :t', ['t' => $row['txn_id']]) ?: null;
        }
        return DB::insert('isp_postings', $row);
    }

    private static function assertAccount(int $id, string $label): int
    {
        $a = DB::row('SELECT id, is_active, name FROM accounts WHERE id = :id', ['id' => $id]);
        if (!$a) {
            fail($label . ' is not in the chart of accounts. Set it on Financial > Module Default Accounts (Cable & Internet).');
        }
        if ((int)$a['is_active'] !== 1) {
            fail($label . ' (' . $a['name'] . ') is inactive in the chart of accounts.');
        }
        return (int)$a['id'];
    }

    /** The header every ISP ledger document shares */
    private static function header(array $acct, string $date, string $memo, string $ref = ''): array
    {
        return [
            'txn_date' => $date,
            'customer_id' => IspAccounts::arCustomer((int)$acct['isp_customer_id']),
            'class_id' => IspSetup::classOfBranch($acct['branch_id'] ? (int)$acct['branch_id'] : null),
            'business_unit_id' => IspSetup::unitId(),
            'memo' => mb_substr($memo, 0, 255),
            'ref_no' => $ref !== '' ? mb_substr($ref, 0, 60) : (string)$acct['account_no'],
        ];
    }

    /** The income account of an invoice line type */
    public static function incomeAccount(string $lineType, ?int $planAccount = null, bool $catv = false): int
    {
        if ($planAccount) {
            return $planAccount;
        }
        $key = match ($lineType) {
            'subscription', 'prorate', 'plan_change' => $catv ? 'isp_catv_revenue' : 'isp_revenue',
            'installation' => 'isp_install_revenue',
            'router_rental', 'static_ip', 'addon', 'equipment' => 'isp_rental_revenue',
            'late_fee' => 'isp_late_fee_revenue',
            'reconnection_fee' => 'isp_reconnect_revenue',
            'discount' => 'isp_discounts',
            'outage_credit' => 'isp_service_credits',
            'deposit' => 'isp_deposits',
            default => 'isp_other_revenue',
        };
        return Accts::get($key);
    }

    // ------------------------------------------------------------------ invoices

    /** Posts an approved ISP invoice to the books (idempotent on INV-<id>). Returns the ledger transaction id. */
    public static function postInvoice(int $invoiceId): int
    {
        $own = self::owning();
        return DB::tx(function () use ($invoiceId) {
            $key = 'INV-' . $invoiceId;
            if ($p = self::byEvent($key)) {
                return (int)$p['txn_id'];
            }
            $inv = DB::row('SELECT * FROM isp_invoices WHERE id = :id FOR UPDATE', ['id' => $invoiceId]) ?? fail('Invoice not found.');
            $acct = IspAccounts::get((int)$inv['account_id']);
            $lines = [];
            foreach (DB::all('SELECT * FROM isp_invoice_lines WHERE invoice_id = :i ORDER BY line_no', ['i' => $invoiceId]) as $l) {
                if (abs((float)$l['amount']) < 0.005 && abs((float)$l['vat_amount']) < 0.005) {
                    continue;
                }
                $taxCode = (float)$l['vat_amount'] != 0.0 ? IspPlans::vatCode() : null;
                $lines[] = ['line_kind' => 'item', 'item_id' => null, 'account_id' => self::assertAccount((int)$l['income_account_id'], 'The account of "' . $l['description'] . '"'),
                    'description' => mb_substr((string)$l['description'], 0, 255), 'qty' => (float)$l['qty'], 'rate' => (float)$l['qty'] != 0.0 ? r2((float)$l['amount'] / (float)$l['qty']) : (float)$l['amount'],
                    'amount' => r2((float)$l['amount']), 'tax_code_id' => $taxCode, 'tax_amount' => r2((float)$l['vat_amount'])];
            }
            if (!$lines) {
                fail('Invoice ' . $inv['invoice_no'] . ' has nothing to post.');
            }
            $h = self::header($acct, (string)$inv['invoice_date'], 'Service invoice ' . $inv['invoice_no'] . ($inv['period_start'] ? ' for ' . fdate($inv['period_start']) . ' to ' . fdate($inv['period_end']) : ''))
                + ['txn_no' => (string)$inv['invoice_no'], 'due_date' => (string)$inv['due_date'], 'account_id' => self::assertAccount(Accts::get('isp_ar'), 'The subscriber receivable account')];
            $txnId = Txn::save('invoice', $h, $lines, null, ['skip_policy' => true]);
            $t = Txn::load($txnId);
            if (abs((float)$t['total'] - (float)$inv['total']) > 0.009) {
                fail('Invoice ' . $inv['invoice_no'] . ' would post ' . money($t['total']) . ' but its lines total ' . money($inv['total']) . '. Nothing was posted.');
            }
            self::record(['event_key' => $key, 'source_type' => 'invoice', 'source_id' => (string)$invoiceId, 'doc_no' => $inv['invoice_no'], 'isp_account_id' => (int)$inv['account_id'],
                'txn_id' => $txnId, 'txn_type' => 'invoice', 'amount' => (float)$inv['total']]);
            return $txnId;
        });
    }

    /** Voids the ledger invoice of an ISP invoice nobody paid or credited yet */
    public static function voidInvoice(int $invoiceId, string $reason): void
    {
        $own = self::owning();
        DB::tx(function () use ($invoiceId, $reason) {
            $p = self::byEvent('INV-' . $invoiceId);
            if (!$p || $p['status'] !== 'posted') {
                return;
            }
            Txn::void((int)$p['txn_id'], mb_substr('Invoice voided: ' . $reason, 0, 255));
            DB::update('isp_postings', ['status' => 'voided', 'reason' => mb_substr($reason, 0, 255), 'voided_by' => Auth::id(), 'voided_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $p['id']]);
        });
    }

    public static function invoiceTxnId(int $invoiceId): ?int
    {
        $p = self::byEvent('INV-' . $invoiceId);
        return $p && $p['status'] === 'posted' ? (int)$p['txn_id'] : null;
    }

    // ------------------------------------------------------------------ payments

    /**
     * Posts a verified payment: one ledger payment, applied to the ledger invoices of its allocations (isp_payment_allocs).
     * The unapplied rest stays on the payment as the subscriber's credit.
     */
    public static function postPayment(int $paymentId): int
    {
        $own = self::owning();
        return DB::tx(function () use ($paymentId) {
            $key = 'PAY-' . $paymentId;
            if ($p = self::byEvent($key)) {
                return (int)$p['txn_id'];
            }
            $pay = DB::row('SELECT * FROM isp_payments WHERE id = :id FOR UPDATE', ['id' => $paymentId]) ?? fail('Payment not found.');
            $acct = IspAccounts::get((int)$pay['account_id']);
            $apps = [];
            foreach (DB::all('SELECT * FROM isp_payment_allocs WHERE payment_id = :p AND is_reversed = 0', ['p' => $paymentId]) as $al) {
                $tid = self::invoiceTxnId((int)$al['invoice_id']) ?? fail('An allocated invoice is not posted to the books.');
                $apps[] = ['target_txn_id' => $tid, 'amount' => r2((float)$al['amount'])];
            }
            $cash = self::assertAccount((int)$pay['cash_account_id'], 'The account the payment went to');
            $h = self::header($acct, (string)$pay['pay_date'], 'Collection receipt ' . $pay['receipt_no'] . ' (' . IspPayments::CHANNELS[$pay['channel']] . ($pay['reference_no'] ? ' ref. ' . $pay['reference_no'] : '') . ')', (string)($pay['reference_no'] ?: $acct['account_no']))
                + ['txn_no' => (string)$pay['receipt_no'], 'account_id' => $cash, 'account2_id' => Accts::get('isp_ar'), 'total' => r2((float)$pay['amount']),
                   'discount_amount' => 0, 'withholding_amount' => 0];
            $txnId = Txn::save('payment', $h, [], null, ['keep_totals' => true, 'applications' => $apps, 'skip_policy' => true]);
            self::record(['event_key' => $key, 'source_type' => 'payment', 'source_id' => (string)$paymentId, 'doc_no' => $pay['receipt_no'], 'isp_account_id' => (int)$pay['account_id'],
                'txn_id' => $txnId, 'txn_type' => 'payment', 'amount' => (float)$pay['amount']]);
            // the gateway's fee: Dr gateway fees, Cr the clearing account the gross amount went to
            if ((float)$pay['gateway_fee'] > 0.004) {
                $feeId = Txn::save('journal', ['txn_date' => (string)$pay['pay_date'], 'txn_no' => 'FEE-' . $pay['receipt_no'], 'class_id' => $h['class_id'], 'business_unit_id' => $h['business_unit_id'],
                    'memo' => 'Payment gateway fee on ' . $pay['receipt_no']], [
                    ['line_kind' => 'expense', 'account_id' => Accts::get('isp_gateway_fees'), 'amount' => r2((float)$pay['gateway_fee']), 'description' => 'Gateway fee ' . $pay['receipt_no']],
                    ['line_kind' => 'expense', 'account_id' => $cash, 'amount' => -r2((float)$pay['gateway_fee']), 'description' => 'Gateway fee ' . $pay['receipt_no']],
                ], null, ['skip_policy' => true]);
                self::record(['event_key' => 'FEE-' . $paymentId, 'source_type' => 'gateway_fee', 'source_id' => (string)$paymentId, 'doc_no' => 'FEE-' . $pay['receipt_no'],
                    'isp_account_id' => (int)$pay['account_id'], 'txn_id' => $feeId, 'txn_type' => 'journal', 'amount' => (float)$pay['gateway_fee']]);
                DB::run('UPDATE isp_payments SET fee_txn_id = :t WHERE id = :id', ['t' => $feeId, 'id' => $paymentId]);
            }
            return $txnId;
        });
    }

    /** Applies more of a posted payment (its unapplied credit) to a posted invoice, in the books as on the ISP side */
    public static function applyCredit(int $paymentId, int $invoiceId, float $amount): void
    {
        $own = self::owning();
        DB::tx(function () use ($paymentId, $invoiceId, $amount) {
            $pTxn = (int)(self::byEvent('PAY-' . $paymentId)['txn_id'] ?? 0) ?: fail('The payment is not in the books.');
            $iTxn = self::invoiceTxnId($invoiceId) ?? fail('The invoice is not in the books.');
            $amount = r2($amount);
            $pt = Txn::load($pTxn);
            if (Balances::creditRemaining($pt) + 0.004 < $amount) {
                fail('The payment has only ' . money(Balances::creditRemaining($pt)) . ' of credit left in the books.');
            }
            DB::insert('payment_applications', ['payment_txn_id' => $pTxn, 'target_txn_id' => $iTxn, 'amount' => $amount]);
            Balances::refreshApplications($pTxn);
            $n = (int)DB::value("SELECT COUNT(*) FROM isp_postings WHERE source_type = 'credit_apply' AND source_id LIKE :s", ['s' => $paymentId . ':%']) + 1;
            self::record(['event_key' => 'APL-' . $paymentId . '-' . $invoiceId . '-' . $n, 'source_type' => 'credit_apply', 'source_id' => $paymentId . ':' . $invoiceId,
                'doc_no' => (string)$pt['txn_no'], 'isp_account_id' => (int)DB::value('SELECT account_id FROM isp_payments WHERE id = :p', ['p' => $paymentId]),
                'txn_id' => $pTxn, 'txn_type' => 'payment', 'amount' => $amount]);
            Audit::log('apply_credit', 'ispbill', 'transaction', $pTxn, (string)$pt['txn_no'], ['amount' => $amount, 'reason' => 'Advance payment applied to an ISP invoice']);
        });
    }

    /** Voids the payment, its gateway fee entry and its savings auto-debit (a reversal approved on the ISP side) */
    public static function reversePayment(int $paymentId, string $reason): void
    {
        $own = self::owning();
        DB::tx(function () use ($paymentId, $reason) {
            foreach (['PAY-', 'FEE-', 'SAV-'] as $prefix) {
                $p = self::byEvent($prefix . $paymentId);
                if (!$p || $p['status'] !== 'posted') {
                    continue;
                }
                $t = Txn::load((int)$p['txn_id']);
                if ($t && (int)$t['is_voided'] === 0) {
                    Txn::void((int)$p['txn_id'], mb_substr('Reversal of collection: ' . $reason, 0, 255));
                }
                DB::update('isp_postings', ['status' => 'voided', 'reason' => mb_substr($reason, 0, 255), 'voided_by' => Auth::id(), 'voided_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $p['id']]);
            }
            // the credit applications made later from this payment went with the void of the payment
            DB::run("UPDATE isp_postings SET status = 'voided', voided_by = :u, voided_at = :t WHERE source_type = 'credit_apply' AND source_id LIKE :s AND status = 'posted'",
                ['u' => Auth::id(), 't' => date('Y-m-d H:i:s'), 's' => $paymentId . ':%']);
            $pay = DB::row('SELECT receipt_no, account_id, amount FROM isp_payments WHERE id = :id', ['id' => $paymentId]);
            self::record(['event_key' => 'REV-PAY-' . $paymentId, 'source_type' => 'reversal', 'source_id' => (string)$paymentId, 'doc_no' => $pay['receipt_no'] ?? null,
                'isp_account_id' => (int)($pay['account_id'] ?? 0) ?: null, 'txn_id' => null, 'txn_type' => 'void', 'amount' => (float)($pay['amount'] ?? 0), 'reason' => mb_substr($reason, 0, 255),
                'reversal_of_id' => self::byEvent('PAY-' . $paymentId)['id'] ?? null, 'party_id' => null, 'class_id' => null]);
        });
    }

    /**
     * The savings withdrawal of a member's auto-debit: Dr the member's savings, Cr the auto-debit clearing account.
     * Returns [txn_id, sav_txn_id].
     */
    public static function postAutoDebit(int $paymentId, int $savAccountId, float $amount, string $date): array
    {
        $own = self::owning();
        return DB::tx(function () use ($paymentId, $savAccountId, $amount, $date) {
            if ($p = self::byEvent('SAV-' . $paymentId)) {
                return [(int)$p['txn_id'], (int)DB::value('SELECT sav_txn_id FROM isp_payments WHERE id = :id', ['id' => $paymentId])];
            }
            $pay = DB::row('SELECT * FROM isp_payments WHERE id = :id', ['id' => $paymentId]);
            $code = DB::value("SELECT code FROM sav_txn_codes WHERE entry_side = 'debit' AND is_active = 1 AND is_error_correct = 0 AND code IN ('CW', 'WD', 'SWD') ORDER BY FIELD(code, 'CW', 'WD', 'SWD') LIMIT 1")
                ?: DB::value("SELECT code FROM sav_txn_codes WHERE entry_side = 'debit' AND is_active = 1 AND is_error_correct = 0 AND is_check_deposit = 0 ORDER BY id LIMIT 1");
            if (!$code) {
                fail('No savings withdrawal code is set up (Savings & Deposits > Savings Transaction Codes).');
            }
            [$txnId, $savId] = Savings::withdraw($savAccountId, (string)$code, Dec::fix((string)r2($amount)), $date, Accts::get('isp_autodebit_clearing'), [
                'description' => 'Auto-debit for cable / internet receipt ' . $pay['receipt_no'], 'or_no' => $pay['receipt_no'], 'ref_type' => 'isp_payment', 'ref_id' => $paymentId,
                'txn_no' => 'ADB-' . $pay['receipt_no'],
            ]);
            self::record(['event_key' => 'SAV-' . $paymentId, 'source_type' => 'auto_debit', 'source_id' => (string)$paymentId, 'doc_no' => $pay['receipt_no'],
                'isp_account_id' => (int)$pay['account_id'], 'txn_id' => $txnId, 'txn_type' => 'coop_cv', 'amount' => r2($amount)]);
            return [$txnId, $savId];
        });
    }

    // ------------------------------------------------------------------ adjustments, deposits

    /**
     * A credit (service credit, discount, write-off, waiver, deposit application) as a credit memo applied to the
     * invoice's ledger document, or a debit adjustment as a supplemental invoice. Idempotent on ADJ-<id> / DEP-<id>.
     * $lines: [[account_id, amount, description]] (positive amounts). Returns the ledger transaction id.
     */
    public static function postCredit(string $key, array $acct, ?int $invoiceId, array $lines, string $date, string $docNo, string $memo, string $source, string $sourceId): int
    {
        $own = self::owning();
        return DB::tx(function () use ($key, $acct, $invoiceId, $lines, $date, $docNo, $memo, $source, $sourceId) {
            if ($p = self::byEvent($key)) {
                return (int)$p['txn_id'];
            }
            $total = r2(array_sum(array_map(fn($l) => (float)$l[1], $lines)));
            $apps = [];
            if ($invoiceId) {
                $tid = self::invoiceTxnId($invoiceId) ?? fail('The invoice is not in the books.');
                $open = Balances::open(Txn::load($tid));
                if ($open + 0.004 < $total) {
                    fail('The credit (' . money($total) . ') is more than the open balance of the invoice (' . money($open) . ').');
                }
                $apps[] = ['target_txn_id' => $tid, 'amount' => $total];
            }
            $tl = [];
            foreach ($lines as [$accountId, $amount, $desc]) {
                $tl[] = ['line_kind' => 'item', 'account_id' => self::assertAccount((int)$accountId, 'The account of the adjustment'), 'description' => mb_substr((string)$desc, 0, 255),
                    'qty' => 1, 'rate' => r2((float)$amount), 'amount' => r2((float)$amount), 'tax_amount' => 0];
            }
            $h = self::header($acct, $date, $memo) + ['txn_no' => mb_substr($docNo, 0, 30), 'account_id' => Accts::get('isp_ar')];
            $txnId = Txn::save('credit_memo', $h, $tl, null, ['skip_policy' => true, 'applications' => $apps]);
            self::record(['event_key' => $key, 'source_type' => $source, 'source_id' => $sourceId, 'doc_no' => $docNo, 'isp_account_id' => (int)$acct['id'],
                'txn_id' => $txnId, 'txn_type' => 'credit_memo', 'amount' => $total]);
            return $txnId;
        });
    }

    public static function voidByKey(string $key, string $reason): void
    {
        $own = self::owning();
        DB::tx(function () use ($key, $reason) {
            $p = self::byEvent($key);
            if (!$p || $p['status'] !== 'posted') {
                return;
            }
            if ($p['txn_id'] && (int)(Txn::load((int)$p['txn_id'])['is_voided'] ?? 1) === 0) {
                Txn::void((int)$p['txn_id'], mb_substr($reason, 0, 255));
            }
            DB::update('isp_postings', ['status' => 'voided', 'reason' => mb_substr($reason, 0, 255), 'voided_by' => Auth::id(), 'voided_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $p['id']]);
        });
    }

    // ------------------------------------------------------------------ inventory

    /** Materials issued to a technician: a stock transfer from the stockroom to the technician's site */
    public static function postIssue(string $key, int $fromSite, int $toSite, array $lines, string $date, string $docNo, string $memo, ?int $accountId = null): int
    {
        $own = self::owning();
        return DB::tx(function () use ($key, $fromSite, $toSite, $lines, $date, $docNo, $memo, $accountId) {
            if ($p = self::byEvent($key)) {
                return (int)$p['txn_id'];
            }
            $tl = [];
            foreach ($lines as [$itemId, $qty, $desc]) {
                if ((float)$qty > 0) {
                    $tl[] = ['line_kind' => 'item', 'item_id' => (int)$itemId, 'qty' => (float)$qty, 'description' => mb_substr((string)$desc, 0, 255), 'rate' => 0, 'amount' => 0, 'tax_amount' => 0];
                }
            }
            if (!$tl) {
                fail('Nothing to issue.');
            }
            $txnId = Txn::save('inventory_transfer', ['txn_date' => $date, 'txn_no' => mb_substr($docNo, 0, 30), 'site_id' => $fromSite, 'site2_id' => $toSite,
                'business_unit_id' => IspSetup::unitId(), 'memo' => mb_substr($memo, 0, 255)], $tl, null, ['skip_policy' => true]);
            self::record(['event_key' => $key, 'source_type' => 'issuance', 'source_id' => $key, 'doc_no' => $docNo, 'isp_account_id' => $accountId, 'txn_id' => $txnId,
                'txn_type' => 'inventory_transfer', 'amount' => 0]);
            return $txnId;
        });
    }

    /** Materials consumed on a work order: an inventory adjustment out of the technician's site, charged to the cost account */
    public static function postConsumption(string $key, int $site, int $expenseAccount, array $lines, string $date, string $docNo, string $memo, ?array $acct = null): int
    {
        $own = self::owning();
        return DB::tx(function () use ($key, $site, $expenseAccount, $lines, $date, $docNo, $memo, $acct) {
            if ($p = self::byEvent($key)) {
                return (int)$p['txn_id'];
            }
            $tl = [];
            foreach ($lines as [$itemId, $qty, $desc]) {
                if ((float)$qty > 0) {
                    $tl[] = ['line_kind' => 'item', 'item_id' => (int)$itemId, 'qty' => -(float)$qty, 'description' => mb_substr((string)$desc, 0, 255), 'site_id' => $site,
                        'rate' => 0, 'amount' => 0, 'tax_amount' => 0, 'value_change' => 0];
                }
            }
            if (!$tl) {
                fail('Nothing was consumed.');
            }
            $h = ['txn_date' => $date, 'txn_no' => mb_substr($docNo, 0, 30), 'account_id' => self::assertAccount($expenseAccount, 'The cost account of the materials'), 'site_id' => $site,
                'business_unit_id' => IspSetup::unitId(), 'class_id' => $acct ? IspSetup::classOfBranch($acct['branch_id'] ? (int)$acct['branch_id'] : null) : null,
                'memo' => mb_substr($memo, 0, 255)];
            if ($acct) {
                $h['customer_id'] = IspAccounts::arCustomer((int)$acct['isp_customer_id']);
            }
            $txnId = Txn::save('inventory_adjustment', $h, $tl, null, ['skip_policy' => true]);
            $value = (float)DB::value('SELECT COALESCE(SUM(debit), 0) FROM journal_lines WHERE txn_id = :t', ['t' => $txnId]);
            self::record(['event_key' => $key, 'source_type' => 'consumption', 'source_id' => $key, 'doc_no' => $docNo, 'isp_account_id' => $acct['id'] ?? null, 'txn_id' => $txnId,
                'txn_type' => 'inventory_adjustment', 'amount' => r2($value)]);
            return $txnId;
        });
    }

    /**
     * Company equipment recovered from a subscriber back into the stockroom: it was charged to installation cost when it
     * was installed, so its return is an INVENTORY ADJUSTMENT: Dr inventory at the unit's cost, Cr the cost account.
     * $lines [[item id, qty, unit cost (0: the item's average cost), description]]
     */
    public static function postReturn(string $key, int $site, int $costAccount, array $lines, string $date, string $docNo, string $memo, ?array $acct = null): int
    {
        $own = self::owning();
        return DB::tx(function () use ($key, $site, $costAccount, $lines, $date, $docNo, $memo, $acct) {
            if ($p = self::byEvent($key)) {
                return (int)$p['txn_id'];
            }
            $tl = [];
            foreach ($lines as [$itemId, $qty, $unit, $desc]) {
                if ((float)$qty > 0) {
                    $tl[] = ['line_kind' => 'item', 'item_id' => (int)$itemId, 'qty' => (float)$qty, 'description' => mb_substr((string)$desc, 0, 255), 'site_id' => $site,
                        'rate' => r2(max(0.0, (float)$unit)), 'amount' => 0, 'tax_amount' => 0, 'value_change' => 0];
                }
            }
            if (!$tl) {
                fail('Nothing was returned.');
            }
            $h = ['txn_date' => $date, 'txn_no' => mb_substr($docNo, 0, 30), 'account_id' => self::assertAccount($costAccount, 'The cost account of the returned equipment'), 'site_id' => $site,
                'business_unit_id' => IspSetup::unitId(), 'class_id' => $acct ? IspSetup::classOfBranch($acct['branch_id'] ? (int)$acct['branch_id'] : null) : null,
                'memo' => mb_substr($memo, 0, 255)];
            $txnId = Txn::save('inventory_adjustment', $h, $tl, null, ['skip_policy' => true]);
            $value = (float)DB::value('SELECT COALESCE(SUM(debit), 0) FROM journal_lines WHERE txn_id = :t', ['t' => $txnId]);
            self::record(['event_key' => $key, 'source_type' => 'equipment_return', 'source_id' => $key, 'doc_no' => $docNo, 'isp_account_id' => $acct['id'] ?? null, 'txn_id' => $txnId,
                'txn_type' => 'inventory_adjustment', 'amount' => r2($value)]);
            return $txnId;
        });
    }

    // ------------------------------------------------------------------ cashier, commissions

    /** The over / short of a closed cashier shift (positive: over) */
    public static function postOverShort(int $shiftId, float $diff, int $cashAccount, string $date, string $shiftNo): ?int
    {
        if (abs($diff) < 0.005) {
            return null;
        }
        $own = self::owning();
        return DB::tx(function () use ($shiftId, $diff, $cashAccount, $date, $shiftNo) {
            if ($p = self::byEvent('OVS-' . $shiftId)) {
                return (int)$p['txn_id'];
            }
            $os = Accts::get('isp_cash_over_short');
            $txnId = Txn::save('journal', ['txn_date' => $date, 'txn_no' => 'OVS-' . $shiftNo, 'business_unit_id' => IspSetup::unitId(),
                'memo' => 'Cash ' . ($diff > 0 ? 'over' : 'short') . ' of cashier shift ' . $shiftNo], [
                ['line_kind' => 'expense', 'account_id' => $cashAccount, 'amount' => r2($diff), 'description' => 'Cash count of shift ' . $shiftNo],
                ['line_kind' => 'expense', 'account_id' => $os, 'amount' => -r2($diff), 'description' => 'Cash ' . ($diff > 0 ? 'over' : 'short') . ' ' . $shiftNo],
            ], null, ['skip_policy' => true]);
            self::record(['event_key' => 'OVS-' . $shiftId, 'source_type' => 'over_short', 'source_id' => (string)$shiftId, 'doc_no' => 'OVS-' . $shiftNo, 'txn_id' => $txnId,
                'txn_type' => 'journal', 'amount' => abs(r2($diff))]);
            return $txnId;
        });
    }

    /** A bill of the agent's supplier record for approved commissions */
    public static function postCommissionBill(int $agentId, int $vendorId, array $commissions, string $date): int
    {
        $own = self::owning();
        return DB::tx(function () use ($agentId, $vendorId, $commissions, $date) {
            $ids = array_map(fn($c) => (int)$c['id'], $commissions);
            sort($ids);
            $key = 'COM-' . $agentId . '-' . md5(implode(',', $ids));
            if ($p = self::byEvent($key)) {
                return (int)$p['txn_id'];
            }
            $lines = [];
            foreach ($commissions as $c) {
                $lines[] = ['line_kind' => 'expense', 'account_id' => Accts::get('isp_commission_expense'), 'amount' => r2((float)$c['amount']), 'qty' => 1, 'rate' => r2((float)$c['amount']),
                    'description' => mb_substr('Commission ' . $c['basis'] . ' ' . ($c['account_no'] ?? ''), 0, 255), 'tax_amount' => 0];
            }
            $txnId = Txn::save('bill', ['txn_date' => $date, 'vendor_id' => $vendorId, 'account_id' => Accts::get('ap'), 'due_date' => IspPlans::addDays($date, 15),
                'business_unit_id' => IspSetup::unitId(), 'memo' => 'Sales agent commissions'], $lines, null, ['skip_policy' => true]);
            self::record(['event_key' => $key, 'source_type' => 'commission', 'source_id' => (string)$agentId, 'doc_no' => (string)(Txn::load($txnId)['txn_no'] ?? ''), 'txn_id' => $txnId,
                'txn_type' => 'bill', 'amount' => r2(array_sum(array_map(fn($c) => (float)$c['amount'], $commissions)))]);
            return $txnId;
        });
    }

    // ------------------------------------------------------------------ checks

    /** The subscriber ledger against the books: [isp open total, ledger open total, difference, list of differences] */
    public static function reconcile(): array
    {
        $isp = (float)DB::value("SELECT COALESCE(SUM(balance), 0) FROM isp_invoices WHERE status IN ('posted', 'partially_paid', 'overdue', 'disputed')");
        $ar = Accts::get('isp_ar');
        $gl = (float)DB::value('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines WHERE account_id = :a', ['a' => $ar]);
        $credit = (float)DB::value("SELECT COALESCE(SUM(unapplied_amount), 0) FROM isp_payments WHERE status = 'posted'");
        $rows = [];
        foreach (DB::all("SELECT i.id, i.invoice_no, i.balance, i.account_id, p.txn_id FROM isp_invoices i LEFT JOIN isp_postings p ON p.event_key = CONCAT('INV-', i.id) AND p.status = 'posted'
                           WHERE i.status IN ('posted', 'partially_paid', 'overdue', 'disputed', 'paid')") as $i) {
            if (!$i['txn_id']) {
                $rows[] = ['invoice_no' => $i['invoice_no'], 'isp' => (float)$i['balance'], 'gl' => 0.0, 'note' => 'not in the books'];
                continue;
            }
            $t = Txn::load((int)$i['txn_id']);
            $open = Balances::open($t);
            if (abs($open - (float)$i['balance']) > 0.009) {
                $rows[] = ['invoice_no' => $i['invoice_no'], 'isp' => (float)$i['balance'], 'gl' => $open, 'note' => 'open balances differ'];
            }
        }
        return ['isp_open' => r2($isp), 'isp_credit' => r2($credit), 'isp_net' => r2($isp - $credit), 'gl' => r2($gl), 'difference' => r2(($isp - $credit) - $gl), 'rows' => $rows];
    }
}
