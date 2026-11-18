<?php
declare(strict_types=1);

/**
 * The billing engine: recurring monthly charges (calendar cycle day or anniversary), advance or arrears billing,
 * prorated first and last periods, router rental, static IP and add-ons, promotions, one-time and pending charges,
 * VAT or non-VAT treatment, late charges, and the billing runs (preview, validation, generation, approval, posting).
 *
 * Controls
 *  - an invoice carries period_key (account, kind and period): the same period is never billed twice, so a run can be
 *    generated again, or the scheduler can run twice, without duplicating anything;
 *  - a draft is numbered D-<id>; the official invoice number is given once, when it posts, from one series without
 *    gaps, and never changes afterwards;
 *  - the lines keep the price, the quantity and the VAT they were billed with; a later plan price change never
 *    touches them;
 *  - a posted invoice is never edited or deleted: it is corrected by an adjustment (credit memo / debit invoice), or
 *    voided while nothing was paid or credited on it.
 */
final class IspBilling
{
    public const STATUSES = ['draft' => 'Draft', 'approved' => 'Approved', 'posted' => 'Posted (unpaid)', 'partially_paid' => 'Partially paid', 'paid' => 'Paid',
        'overdue' => 'Overdue', 'disputed' => 'Disputed', 'adjusted' => 'Adjusted', 'voided' => 'Voided'];
    public const OPEN = ['posted', 'partially_paid', 'overdue', 'disputed'];
    public const LINE_TYPES = ['subscription' => 'Monthly subscription', 'prorate' => 'Prorated subscription', 'installation' => 'Installation / activation', 'router_rental' => 'Router rental',
        'static_ip' => 'Static IP', 'addon' => 'Add-on service', 'one_time' => 'One-time charge', 'discount' => 'Discount / promotion', 'late_fee' => 'Late payment charge',
        'reconnection_fee' => 'Reconnection fee', 'outage_credit' => 'Service credit', 'deposit' => 'Security deposit', 'termination_fee' => 'Early termination fee',
        'plan_change' => 'Plan change proration', 'equipment' => 'Equipment', 'adjustment' => 'Adjustment'];

    // ------------------------------------------------------------------ charges

    /**
     * A one-time or pending charge, billed on the account's next invoice (idempotent on $key). A negative amount is a
     * credit. Returns the charge id.
     */
    public static function addCharge(int $accountId, string $type, string $desc, float $amount, string $date, string $key, string $vatMode = 'inclusive', ?int $incomeAccount = null,
                                     ?string $sourceType = null, $sourceId = null): int
    {
        $have = DB::value('SELECT id FROM isp_charges WHERE charge_key = :k', ['k' => $key]);
        if ($have) {
            return (int)$have;
        }
        if (abs($amount) < 0.005) {
            fail('Enter the amount of the charge.');
        }
        $id = DB::insert('isp_charges', ['account_id' => $accountId, 'charge_type' => $type, 'description' => mb_substr($desc, 0, 255), 'amount' => r2($amount), 'qty' => 1,
            'vat_mode' => $vatMode, 'income_account_id' => $incomeAccount, 'charge_date' => $date, 'status' => 'pending', 'charge_key' => mb_substr($key, 0, 80),
            'source_type' => $sourceType, 'source_id' => $sourceId !== null ? (int)$sourceId : null, 'created_by' => Auth::id()]);
        IspAccounts::event($accountId, 'charge', (self::LINE_TYPES[$type] ?? $type) . ': ' . $desc . ' ' . money($amount), 'charge', $id, r2($amount));
        return $id;
    }

    public static function cancelCharge(int $chargeId, string $reason): void
    {
        $c = DB::row('SELECT * FROM isp_charges WHERE id = :id', ['id' => $chargeId]) ?? fail('Charge not found.');
        if ($c['status'] !== 'pending') {
            fail('Only a charge not yet billed can be cancelled; a billed charge is corrected with an adjustment.');
        }
        DB::update('isp_charges', ['status' => 'cancelled', 'cancel_reason' => mb_substr($reason, 0, 255)], 'id = :id', ['id' => $chargeId]);
        IspAccounts::event((int)$c['account_id'], 'charge', 'Charge cancelled: ' . $c['description'] . ' (' . $reason . ')', 'charge', $chargeId);
    }

    // ------------------------------------------------------------------ periods

    /** The next period the account has to be billed for: [start, end, partial (bool)] or null when nothing is due */
    public static function nextPeriod(array $a): ?array
    {
        if (!$a['activation_date']) {
            return null;
        }
        $start = $a['billed_through'] ? IspPlans::addDays($a['billed_through'], 1) : $a['activation_date'];
        [$ps, $pe] = IspPlans::periodOf($start, (int)$a['cycle_day']);
        return [$start, $pe, $start > $ps, $ps];
    }

    /** Is the next period of the account due on the bill date? */
    public static function isDue(array $a, string $billDate): bool
    {
        $np = self::nextPeriod($a);
        if (!$np) {
            return false;
        }
        [$start, $end] = $np;
        if ($a['bill_timing'] === 'arrears') {
            return $billDate > $end;
        }
        return $billDate >= IspPlans::addDays($start, -IspSetup::int('isp_bill_lead_days'));
    }

    public static function dueDate(array $a, string $invoiceDate): string
    {
        if (!empty($a['custom_due_day'])) {
            $d = (int)$a['custom_due_day'];
            $cand = IspPlans::dayInMonth((int)substr($invoiceDate, 0, 4), (int)substr($invoiceDate, 5, 2), $d);
            if ($cand <= IspPlans::addDays($invoiceDate, 4)) {
                $cand = IspPlans::addMonths($cand, 1, $d);
            }
            return $cand;
        }
        return IspPlans::addDays($invoiceDate, max(0, (int)$a['due_days']));
    }

    // ------------------------------------------------------------------ building an invoice

    /**
     * The lines of the account's invoice for a period (nothing is saved). $periodStart..$periodEnd may be a part of a
     * billing period (prorated). Returns ['lines' => [...], 'subtotal', 'vat', 'total', 'discount', 'charge_ids', 'warnings'].
     */
    public static function compute(array $a, ?string $periodStart, ?string $periodEnd, string $invoiceDate, bool $withCharges = true, bool $withRecurring = true): array
    {
        $plan = IspPlans::get((int)$a['plan_id']);
        $lines = [];
        $warnings = [];
        $chargeIds = [];
        if ($withRecurring && $periodStart && $periodEnd) {
            [$ps, $pe] = IspPlans::periodOf($periodStart, (int)$a['cycle_day']);
            $factor = !empty($a['_no_prorate']) ? 1.0 : IspPlans::prorateFactor($periodStart, $periodEnd, $ps, $pe);
            $partial = $factor < 0.99999;
            $fee = IspPlans::priceFor($plan, $a);
            $label = $plan['name'] . ' (' . IspPlans::speed($plan) . ')' . ((int)$a['member_price'] === 1 ? ', member rate' : '') . ', ' . fdate($periodStart) . ' to ' . fdate($periodEnd);
            if ($partial) {
                $label .= ' (' . IspPlans::days($periodStart, $periodEnd) . ' of ' . IspPlans::days($ps, $pe) . ' days)';
            }
            $sub = r2($fee * $factor);
            $lines[] = self::line($partial ? 'prorate' : 'subscription', $label, $sub, $plan['vat_mode'], IspPosting::incomeAccount('subscription', $plan['income_account_id'] ? (int)$plan['income_account_id'] : null, (int)$plan['includes_catv'] === 1),
                ['plan_id' => (int)$plan['id'], 'plan_price' => $fee, 'qty' => r2($factor * 10000) / 10000, 'unit_price' => $fee, 'period_start' => $periodStart, 'period_end' => $periodEnd]);
            // promotion: the first N billed months
            $monthNo = (int)DB::value("SELECT COUNT(*) FROM isp_invoices WHERE account_id = :a AND kind IN ('regular', 'initial') AND status <> 'voided' AND period_start IS NOT NULL", ['a' => $a['id']]) + 1;
            $promo = IspPlans::promoPct($plan, $monthNo);
            if ($promo > 0) {
                $lines[] = self::line('discount', 'Promo ' . rtrim(rtrim(number_format($promo, 2), '0'), '.') . '% off (month ' . $monthNo . ' of ' . (int)$plan['promo_months'] . ')', -r2($sub * $promo / 100), $plan['vat_mode'], Accts::get('isp_discounts'));
            }
            if ((int)$a['router_rental'] === 1 && (float)$plan['router_rental_fee'] > 0) {
                $lines[] = self::line('router_rental', 'Router rental' . ($partial ? ' (prorated)' : ''), r2((float)$plan['router_rental_fee'] * $factor), $plan['vat_mode'], Accts::get('isp_rental_revenue'));
            }
            if (!empty($a['static_ip']) && (float)$plan['static_ip_fee'] > 0) {
                $lines[] = self::line('static_ip', 'Static IP ' . $a['static_ip'] . ($partial ? ' (prorated)' : ''), r2((float)$plan['static_ip_fee'] * $factor), $plan['vat_mode'], Accts::get('isp_rental_revenue'));
            }
            foreach (DB::all('SELECT * FROM isp_account_addons WHERE account_id = :a AND is_active = 1 AND start_date <= :e AND (end_date IS NULL OR end_date >= :s)',
                         ['a' => $a['id'], 'e' => $periodEnd, 's' => $periodStart]) as $ad) {
                $f = IspPlans::prorateFactor(max($periodStart, $ad['start_date']), $ad['end_date'] ? min($periodEnd, $ad['end_date']) : $periodEnd, $ps, $pe);
                $lines[] = self::line('addon', $ad['description'] . ($f < 0.99999 ? ' (prorated)' : ''), r2((float)$ad['amount'] * $f), (int)$ad['taxable'] === 1 ? 'inclusive' : 'exempt', Accts::get('isp_rental_revenue'));
            }
        }
        if ($withCharges) {
            // the charges first, then the credits: a credit is weighed against everything the invoice carries
            $pending = DB::all("SELECT * FROM isp_charges WHERE account_id = :a AND status = 'pending' AND charge_date <= :d ORDER BY (amount < 0), charge_date, id", ['a' => $a['id'], 'd' => $invoiceDate]);
            $sumSoFar = array_sum(array_map(fn($l) => $l['amount'] + $l['vat_amount'], $lines));
            foreach ($pending as $c) {
                // a credit is used only up to what the invoice can take; the rest waits for the next invoice
                if ((float)$c['amount'] < 0 && $sumSoFar + (float)$c['amount'] < -0.004) {
                    $warnings[] = 'Credit "' . $c['description'] . '" waits for the next invoice (more than this invoice).';
                    continue;
                }
                $type = $c['charge_type'] === 'debit_adjustment' ? 'adjustment' : $c['charge_type'];
                $acc = $c['income_account_id'] ? (int)$c['income_account_id'] : IspPosting::incomeAccount($type === 'plan_change' ? 'subscription' : $type, null, (int)$plan['includes_catv'] === 1);
                $mode = $type === 'deposit' ? 'exempt' : (string)$c['vat_mode'];
                $l = self::line($type, (string)$c['description'], (float)$c['amount'], $mode, $acc, ['charge_id' => (int)$c['id']]);
                $lines[] = $l;
                $sumSoFar += $l['amount'] + $l['vat_amount'];
                $chargeIds[] = (int)$c['id'];
            }
        }
        $subtotal = r2(array_sum(array_column($lines, 'amount')));
        $vat = r2(array_sum(array_column($lines, 'vat_amount')));
        $discount = r2(-array_sum(array_map(fn($l) => $l['line_type'] === 'discount' ? $l['amount'] + $l['vat_amount'] : 0, $lines)));
        if (!$lines) {
            $warnings[] = 'Nothing to bill.';
        }
        if ($plan['status'] === 'inactive') {
            $warnings[] = 'The plan ' . $plan['code'] . ' is no longer offered; the account is billed at its price until it is migrated.';
        }
        if (trim((string)$a['mobile']) === '' && trim((string)$a['email']) === '') {
            $warnings[] = 'No mobile number or e-mail: the subscriber is not notified.';
        }
        return ['lines' => $lines, 'subtotal' => $subtotal, 'vat' => $vat, 'total' => r2($subtotal + $vat), 'discount' => $discount, 'charge_ids' => $chargeIds, 'warnings' => $warnings];
    }

    /** One invoice line from a gross amount and its VAT mode */
    private static function line(string $type, string $desc, float $gross, string $vatMode, int $account, array $extra = []): array
    {
        [$net, $vat] = IspPlans::vatSplit($gross, $vatMode);
        return $extra + ['line_type' => $type, 'description' => mb_substr($desc, 0, 255), 'qty' => $extra['qty'] ?? 1, 'unit_price' => $extra['unit_price'] ?? $gross,
            'amount' => $net, 'vat_amount' => $vat, 'vat_mode' => $vatMode, 'income_account_id' => $account];
    }

    /**
     * Saves a draft invoice from compute(). The period key makes a second invoice for the same account, kind and period
     * impossible (the database refuses it): returns the existing one instead. Returns the invoice id, or null when
     * nothing is billed.
     */
    public static function createDraft(array $a, string $kind, ?string $ps, ?string $pe, string $invoiceDate, array $calc, ?int $runId = null, ?string $periodKey = null): ?int
    {
        if (!$calc['lines'] || $calc['total'] < -0.004) {
            return null;
        }
        $periodKey ??= $ps ? 'A' . $a['id'] . ':' . $kind . ':' . $ps : null;
        if ($periodKey && ($have = DB::value('SELECT id FROM isp_invoices WHERE period_key = :k', ['k' => $periodKey]))) {
            return (int)$have;
        }
        $bal = IspAccounts::balances((int)$a['id']);
        $id = DB::insert('isp_invoices', [
            'invoice_no' => 'D-' . bin2hex(random_bytes(6)), 'account_id' => (int)$a['id'], 'run_id' => $runId, 'kind' => $kind, 'period_key' => $periodKey,
            'period_start' => $ps, 'period_end' => $pe, 'invoice_date' => $invoiceDate, 'due_date' => self::dueDate($a, $invoiceDate), 'status' => 'draft',
            'vat_registered' => IspSetup::vatRegistered() ? 1 : 0, 'subtotal' => $calc['subtotal'], 'discount_total' => $calc['discount'], 'vat_total' => $calc['vat'],
            'total' => $calc['total'], 'balance' => $calc['total'], 'previous_balance' => $bal['balance'], 'created_by' => Auth::id(),
        ]);
        DB::run('UPDATE isp_invoices SET invoice_no = :n WHERE id = :id', ['n' => 'D-' . $id, 'id' => $id]);
        $n = 0;
        foreach ($calc['lines'] as $l) {
            DB::insert('isp_invoice_lines', ['invoice_id' => $id, 'line_no' => ++$n, 'line_type' => $l['line_type'], 'description' => $l['description'], 'qty' => $l['qty'],
                'unit_price' => $l['unit_price'], 'amount' => $l['amount'], 'vat_amount' => $l['vat_amount'], 'vat_mode' => $l['vat_mode'], 'income_account_id' => $l['income_account_id'],
                'plan_id' => $l['plan_id'] ?? null, 'plan_price' => $l['plan_price'] ?? null, 'charge_id' => $l['charge_id'] ?? null,
                'period_start' => $l['period_start'] ?? null, 'period_end' => $l['period_end'] ?? null]);
            if (!empty($l['charge_id'])) {
                // reserved by this draft; billed when it posts, released again if the draft is cancelled
                DB::run("UPDATE isp_charges SET invoice_id = :i WHERE id = :c AND status = 'pending'", ['i' => $id, 'c' => $l['charge_id']]);
            }
        }
        return $id;
    }

    /**
     * Posts a draft or approved invoice: the official number, the ledger invoice, the account's billed-through date, the
     * pending charges marked billed, advance payments applied, the subscriber notified.
     */
    public static function post(int $invoiceId, bool $notify = true): void
    {
        DB::tx(function () use ($invoiceId, $notify) {
            $inv = DB::row('SELECT * FROM isp_invoices WHERE id = :id FOR UPDATE', ['id' => $invoiceId]) ?? fail('Invoice not found.');
            if (!in_array($inv['status'], ['draft', 'approved'], true)) {
                return;
            }
            $a = IspAccounts::get((int)$inv['account_id'], true);
            $no = Seq::next('isp_invoice');
            DB::update('isp_invoices', ['invoice_no' => $no, 'status' => 'posted', 'posted_by' => Auth::id(), 'posted_at' => date('Y-m-d H:i:s'), 'balance' => $inv['total'],
                'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $invoiceId]);
            $txnId = IspPosting::postInvoice($invoiceId);
            DB::run('UPDATE isp_invoices SET txn_id = :t WHERE id = :id', ['t' => $txnId, 'id' => $invoiceId]);
            foreach (DB::column('SELECT charge_id FROM isp_invoice_lines WHERE invoice_id = :i AND charge_id IS NOT NULL', ['i' => $invoiceId]) as $cid) {
                DB::run("UPDATE isp_charges SET status = 'billed', invoice_id = :i WHERE id = :c", ['i' => $invoiceId, 'c' => $cid]);
            }
            if ($inv['period_end'] && (!$a['billed_through'] || $inv['period_end'] > $a['billed_through'])) {
                $next = IspPlans::addDays($inv['period_end'], 1);
                DB::update('isp_accounts', ['billed_through' => $inv['period_end'], 'next_bill_date' => $a['bill_timing'] === 'arrears' ? IspPlans::addDays(IspPlans::periodOf($next, (int)$a['cycle_day'])[1], 1)
                    : IspPlans::addDays($next, -IspSetup::int('isp_bill_lead_days'))], 'id = :id', ['id' => $a['id']]);
            }
            if (DB::value("SELECT id FROM isp_invoice_lines WHERE invoice_id = :i AND line_type = 'deposit'", ['i' => $invoiceId]) && $a['deposit_status'] === 'none') {
                DB::run("UPDATE isp_accounts SET deposit_status = 'billed' WHERE id = :id", ['id' => $a['id']]);
            }
            IspAccounts::event((int)$a['id'], 'invoice', 'Invoice ' . $no . ' ' . money($inv['total']) . ($inv['period_start'] ? ' for ' . fdate($inv['period_start']) . ' to ' . fdate($inv['period_end']) : '') . ', due ' . fdate($inv['due_date']),
                'invoice', $invoiceId, (float)$inv['total']);
            if (IspSetup::get('isp_auto_apply_credit') === '1') {
                IspPayments::applyCredits((int)$a['id']);
            }
            if ($notify) {
                $fresh = DB::row('SELECT * FROM isp_invoices WHERE id = :id', ['id' => $invoiceId]);
                if ((float)$fresh['balance'] > 0.004) {
                    IspNotify::event('invoice_generated', (int)$a['id'], ['invoice_no' => $no, 'amount' => money($fresh['balance']), 'due_date' => fdate($fresh['due_date']),
                        'period' => $inv['period_start'] ? fdate($inv['period_start']) . ' - ' . fdate($inv['period_end']) : 'charges'], 'INV-' . $invoiceId);
                }
            }
        });
    }

    /** Recomputes an invoice's paid, credited and open amounts and its status from its allocations and credits */
    public static function refresh(int $invoiceId): void
    {
        $inv = DB::row('SELECT * FROM isp_invoices WHERE id = :id', ['id' => $invoiceId]);
        if (!$inv || in_array($inv['status'], ['draft', 'approved', 'voided'], true)) {
            return;
        }
        $paid = r2((float)DB::value('SELECT COALESCE(SUM(al.amount), 0) FROM isp_payment_allocs al JOIN isp_payments p ON p.id = al.payment_id
                                      WHERE al.invoice_id = :i AND al.is_reversed = 0 AND p.status = \'posted\'', ['i' => $invoiceId]));
        $credit = r2((float)DB::value("SELECT COALESCE(SUM(amount), 0) FROM isp_adjustments WHERE invoice_id = :i AND status = 'posted' AND adj_type <> 'debit'", ['i' => $invoiceId]));
        $balance = r2((float)$inv['total'] - $paid - $credit);
        if ($balance <= 0.004) {
            $status = $credit > 0.004 && $paid <= 0.004 ? 'adjusted' : 'paid';
        } elseif (DB::value("SELECT id FROM isp_disputes WHERE invoice_id = :i AND status IN ('open', 'investigating')", ['i' => $invoiceId])) {
            $status = 'disputed';
        } elseif ($inv['due_date'] < today()) {
            $status = 'overdue';
        } elseif ($paid > 0.004 || $credit > 0.004) {
            $status = 'partially_paid';
        } else {
            $status = 'posted';
        }
        DB::update('isp_invoices', ['paid_amount' => $paid, 'credit_amount' => $credit, 'balance' => max(0, $balance), 'status' => $status,
            'overdue_since' => $status === 'overdue' ? ($inv['overdue_since'] ?: IspPlans::addDays($inv['due_date'], 1)) : ($balance <= 0.004 ? $inv['overdue_since'] : null),
            'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $invoiceId]);
    }

    /** Voids a posted invoice nothing was paid or credited on (with the ledger document), or cancels a draft */
    public static function void(int $invoiceId, string $reason, string $pageKey = 'ispbill.invoices'): void
    {
        DB::tx(function () use ($invoiceId, $reason, $pageKey) {
            $inv = DB::row('SELECT * FROM isp_invoices WHERE id = :id FOR UPDATE', ['id' => $invoiceId]) ?? fail('Invoice not found.');
            if (trim($reason) === '') {
                fail('Enter the reason.');
            }
            if ($inv['status'] === 'voided') {
                fail('The invoice is already voided.');
            }
            if ((float)$inv['paid_amount'] > 0.004 || (float)$inv['credit_amount'] > 0.004) {
                fail('Invoice ' . $inv['invoice_no'] . ' has payments or credits applied. Correct it with an adjustment instead (a credit or debit adjustment), or reverse the payment first.');
            }
            if (in_array($inv['status'], self::OPEN, true) || $inv['status'] === 'paid') {
                IspPosting::voidInvoice($invoiceId, $reason);
            }
            // the period can be billed again; the charges go back to pending
            DB::update('isp_invoices', ['status' => 'voided', 'voided_by' => Auth::id(), 'voided_at' => date('Y-m-d H:i:s'), 'void_reason' => mb_substr($reason, 0, 255),
                'balance' => 0, 'period_key' => $inv['period_key'] ? mb_substr($inv['period_key'] . '#V' . $invoiceId, 0, 60) : null], 'id = :id', ['id' => $invoiceId]);
            DB::run("UPDATE isp_charges SET status = 'pending', invoice_id = NULL WHERE invoice_id = :i", ['i' => $invoiceId]);
            if ($inv['period_end'] && in_array($inv['status'], array_merge(self::OPEN, ['paid']), true)) {
                // the billed-through date steps back when the last billed period is voided
                $last = DB::value("SELECT MAX(period_end) FROM isp_invoices WHERE account_id = :a AND status NOT IN ('voided', 'draft', 'approved') AND period_end IS NOT NULL", ['a' => $inv['account_id']]);
                DB::run('UPDATE isp_accounts SET billed_through = :b WHERE id = :a', ['b' => $last ?: null, 'a' => $inv['account_id']]);
            }
            IspAccounts::event((int)$inv['account_id'], 'invoice', 'Invoice ' . $inv['invoice_no'] . ' voided: ' . $reason, 'invoice', $invoiceId);
            Audit::log('void', 'ispbill', 'isp_invoice', $invoiceId, (string)$inv['invoice_no'], ['page_key' => $pageKey, 'reason' => $reason, 'amount' => $inv['total']]);
        });
    }

    // ------------------------------------------------------------------ single invoices

    /** The first invoice at activation: installation, deposit and (advance billing) the first, prorated period */
    public static function initialInvoice(int $accountId, string $date): ?int
    {
        return DB::tx(function () use ($accountId, $date) {
            $a = IspAccounts::get($accountId, true);
            $plan = IspPlans::get((int)$a['plan_id']);
            // the installation quotation the applicant accepted is what is billed (the plan's fee when there was none)
            $quote = $a['application_id'] ? DB::row('SELECT quote_installation, quote_materials FROM isp_applications WHERE id = :i', ['i' => $a['application_id']]) : null;
            $fee = $quote && $quote['quote_installation'] !== null ? (float)$quote['quote_installation'] : (float)$plan['installation_fee'];
            if ($fee > 0) {
                self::addCharge($accountId, 'installation', 'Installation and activation fee', $fee, $date, 'INST-' . $accountId, $plan['vat_mode']);
            }
            if ($quote && (float)$quote['quote_materials'] > 0) {
                self::addCharge($accountId, 'installation', 'Installation materials (as quoted)', (float)$quote['quote_materials'], $date, 'INSTM-' . $accountId, $plan['vat_mode']);
            }
            if ((float)$a['security_deposit'] > 0) {
                self::addCharge($accountId, 'deposit', 'Refundable security deposit (equipment)', (float)$a['security_deposit'], $date, 'DEP-' . $accountId, 'exempt', Accts::get('isp_deposits'));
            }
            $ps = $pe = null;
            $withRecurring = false;
            // advance billing (and every prepaid plan) bills the first period at once; arrears billing waits for its end
            if ($a['bill_timing'] !== 'arrears' || $plan['service_type'] === 'prepaid') {
                [$start, $end] = self::nextPeriod($a) ?? [null, null];
                if ($start) {
                    $ps = $start;
                    $pe = $end;
                    $withRecurring = true;
                    // no proration: the first, partial period is charged the full monthly fee
                    $a['_no_prorate'] = (int)$a['prorate_first'] !== 1;
                }
            }
            $calc = self::compute($a, $ps, $pe, $date, true, $withRecurring);
            $id = self::createDraft($a, 'initial', $ps, $pe, $date, $calc, null, 'A' . $accountId . ':initial');
            if ($id) {
                self::post($id);
            }
            return $id;
        });
    }

    /** The final invoice of a termination: the service up to $endDate (or a credit for the unused days), plus pending charges */
    public static function finalInvoice(int $accountId, string $endDate): ?int
    {
        return DB::tx(function () use ($accountId, $endDate) {
            $a = IspAccounts::get($accountId, true);
            $plan = IspPlans::get((int)$a['plan_id']);
            $ps = $pe = null;
            $recurring = false;
            if ($a['activation_date'] && $a['service_status'] === 'active') {
                if (!$a['billed_through'] || $a['billed_through'] < $endDate) {
                    $ps = $a['billed_through'] ? IspPlans::addDays($a['billed_through'], 1) : $a['activation_date'];
                    $pe = $endDate;
                    $recurring = $ps <= $pe;
                } elseif ($a['billed_through'] > $endDate) {
                    // advance billing went past the end: the unused days are credited
                    [$bs, $be] = IspPlans::periodOf($endDate, (int)$a['cycle_day']);
                    $factor = IspPlans::prorateFactor(IspPlans::addDays($endDate, 1), $a['billed_through'], $bs, $be);
                    $credit = r2(IspPlans::priceFor($plan, $a) * $factor);
                    if ($credit > 0) {
                        self::addCharge($accountId, 'plan_change', 'Unused service ' . fdate(IspPlans::addDays($endDate, 1)) . ' to ' . fdate($a['billed_through']), -$credit, $endDate, 'FINC-' . $accountId, $plan['vat_mode']);
                    }
                }
            }
            if ($recurring) {
                // the last period may span the end of one billing period and the start of the next
                [, $cutEnd] = IspPlans::periodOf($ps, (int)$a['cycle_day']);
                if ($pe > $cutEnd) {
                    $first = self::compute($a, $ps, $cutEnd, $endDate, false, true);
                    $second = self::compute($a, IspPlans::addDays($cutEnd, 1), $pe, $endDate, true, true);
                    $calc = ['lines' => array_merge($first['lines'], $second['lines']), 'subtotal' => r2($first['subtotal'] + $second['subtotal']), 'vat' => r2($first['vat'] + $second['vat']),
                        'total' => r2($first['total'] + $second['total']), 'discount' => r2($first['discount'] + $second['discount']), 'charge_ids' => $second['charge_ids'], 'warnings' => []];
                } else {
                    $calc = self::compute($a, $ps, $pe, $endDate, true, true);
                }
            } else {
                $calc = self::compute($a, null, null, $endDate, true, false);
            }
            if ($calc['total'] < -0.004) {
                // more credit than charges: the credit is given as an adjustment after the invoice
                $calc = self::compute($a, $ps, $pe, $endDate, false, $recurring);
            }
            $id = self::createDraft($a, 'final', $ps, $pe, $endDate, $calc, null, 'A' . $accountId . ':final');
            if ($id) {
                self::post($id, false);
            }
            // any credit left (the unused days of a period billed in advance) is applied to what is still open, oldest
            // first; what is left after that stays as the subscriber's unused service credit, paid back with the deposit
            // in the final settlement (refundDeposit)
            $left = DB::all("SELECT * FROM isp_charges WHERE account_id = :a AND status = 'pending' AND amount < 0 ORDER BY id", ['a' => $accountId]);
            foreach ($left as $c) {
                $need = r2(-(float)$c['amount']);
                $lastInvoice = null;
                foreach (DB::all("SELECT id, balance FROM isp_invoices WHERE account_id = :a AND status IN ('posted', 'partially_paid', 'overdue', 'disputed') AND balance > 0 ORDER BY due_date, id", ['a' => $accountId]) as $open) {
                    if ($need <= 0.004) {
                        break;
                    }
                    $use = r2(min($need, (float)$open['balance']));
                    if ($use <= 0) {
                        continue;
                    }
                    IspCredit::request($accountId, (int)$open['id'], 'credit', $use, $c['description'], 'termination', (int)$c['id'], true);
                    $need = r2($need - $use);
                    $lastInvoice = (int)$open['id'];
                }
                if ($need <= 0.004) {
                    DB::update('isp_charges', ['status' => 'billed', 'invoice_id' => $lastInvoice], 'id = :id', ['id' => $c['id']]);
                } elseif ($need + 0.004 < -(float)$c['amount']) {
                    DB::update('isp_charges', ['amount' => -$need, 'description' => mb_substr($c['description'] . ' (balance after applying to open invoices)', 0, 255)], 'id = :id', ['id' => $c['id']]);
                }
            }
            return $id;
        });
    }

    /** A manual invoice of the pending charges only (one-time services) */
    public static function chargesInvoice(int $accountId, string $date): ?int
    {
        return DB::tx(function () use ($accountId, $date) {
            $a = IspAccounts::get($accountId, true);
            $calc = self::compute($a, null, null, $date, true, false);
            if (!$calc['lines'] || $calc['total'] <= 0.004) {
                fail('There is no pending charge to bill (or the credits are more than the charges).');
            }
            $id = self::createDraft($a, 'manual', null, null, $date, $calc, null, null);
            self::post($id);
            return $id;
        });
    }

    /** The security deposit applied to the open balance; the rest becomes refundable. Returns [applied, refundable]. */
    public static function applyDeposit(int $accountId, string $reason): array
    {
        return DB::tx(function () use ($accountId, $reason) {
            $a = IspAccounts::get($accountId, true);
            $dep = (float)$a['security_deposit'];
            if ($dep <= 0 || !in_array($a['deposit_status'], ['billed', 'held'], true)) {
                return [0.0, 0.0];
            }
            $applied = 0.0;
            $left = $dep;
            foreach (DB::all("SELECT * FROM isp_invoices WHERE account_id = :a AND status IN ('posted', 'partially_paid', 'overdue', 'disputed') AND balance > 0 ORDER BY due_date, id", ['a' => $accountId]) as $inv) {
                if ($left <= 0.004) {
                    break;
                }
                $use = r2(min($left, (float)$inv['balance']));
                IspCredit::request($accountId, (int)$inv['id'], 'deposit', $use, $reason, 'deposit', $accountId, true);
                $applied = r2($applied + $use);
                $left = r2($left - $use);
            }
            $paid = $a['deposit_status'] === 'held';
            DB::update('isp_accounts', ['deposit_status' => $left > 0.004 && $paid ? 'held' : 'applied', 'security_deposit' => $paid ? $left : 0], 'id = :id', ['id' => $accountId]);
            IspAccounts::event($accountId, 'deposit', 'Security deposit applied to the balance: ' . money($applied) . ($left > 0.004 && $paid ? ', refundable ' . money($left) : ''));
            return [$applied, $paid ? $left : 0.0];
        });
    }

    /** The final settlement owed to a subscriber: the deposit still held and the unused service credit not applied */
    public static function refundable(int $accountId): array
    {
        $a = IspAccounts::get($accountId);
        $dep = $a && $a['deposit_status'] === 'held' ? r2((float)$a['security_deposit']) : 0.0;
        $credit = $a && $a['service_status'] === 'terminated'
            ? r2(-(float)DB::value("SELECT COALESCE(SUM(amount), 0) FROM isp_charges WHERE account_id = :a AND status = 'pending' AND amount < 0", ['a' => $accountId])) : 0.0;
        return ['deposit' => $dep, 'credit' => $credit, 'total' => r2($dep + $credit)];
    }

    /**
     * Pays the subscriber back in cash what the company still owes after the balance was settled: the security deposit
     * still held (Dr Subscriber Security Deposits) and, on a terminated account, the unused service credit of a period
     * billed in advance (Dr Service Credits); Cr the cash or bank account paid from. Once per account.
     */
    public static function refundDeposit(int $accountId, int $cashAccount, string $date, string $reason): int
    {
        $own = IspPosting::owning();
        return DB::tx(function () use ($accountId, $cashAccount, $date, $reason) {
            $a = IspAccounts::get($accountId, true);
            $r = self::refundable($accountId);
            if ($r['total'] <= 0.004) {
                fail('There is no paid deposit or unused service credit to refund on this account.');
            }
            if (IspAccounts::balances($accountId)['open_invoices'] > 0.004) {
                fail('The account still has an open balance: apply the deposit to it first.');
            }
            if (trim($reason) === '') {
                fail('Enter the reason of the refund.');
            }
            // one posting per refund: the first is DRF-<account id>, any later one (a deposit refunded before the
            // termination, then the unused service after it) DRF-<account id>-2 ...
            $made = (int)DB::value("SELECT COUNT(*) FROM isp_postings WHERE event_key = :k OR event_key LIKE :k2", ['k' => 'DRF-' . $accountId, 'k2' => 'DRF-' . $accountId . '-%']);
            $key = 'DRF-' . $accountId . ($made ? '-' . ($made + 1) : '');
            $cust = IspAccounts::arCustomer((int)$a['isp_customer_id']);
            $lines = [];
            if ($r['deposit'] > 0) {
                $lines[] = ['line_kind' => 'expense', 'account_id' => Accts::get('isp_deposits'), 'amount' => $r['deposit'], 'description' => 'Deposit refund ' . $a['account_no'], 'customer_id' => $cust];
            }
            if ($r['credit'] > 0) {
                $lines[] = ['line_kind' => 'expense', 'account_id' => Accts::get('isp_service_credits'), 'amount' => $r['credit'], 'description' => 'Unused service refunded ' . $a['account_no'], 'customer_id' => $cust];
            }
            $lines[] = ['line_kind' => 'expense', 'account_id' => $cashAccount, 'amount' => -$r['total'], 'description' => 'Refund to subscriber ' . $a['account_no']];
            $txn = Txn::save('journal', ['txn_date' => $date, 'txn_no' => 'DRF-' . $a['account_no'], 'business_unit_id' => IspSetup::unitId(),
                'class_id' => IspSetup::classOfBranch($a['branch_id'] ? (int)$a['branch_id'] : null), 'memo' => 'Final refund ' . $a['account_no'] . ': ' . $reason], $lines, null, ['skip_policy' => true]);
            DB::insert('isp_postings', ['event_key' => $key, 'source_type' => 'deposit_refund', 'source_id' => (string)$accountId, 'doc_no' => 'DRF-' . $a['account_no'],
                'isp_account_id' => $accountId, 'txn_id' => $txn, 'txn_type' => 'journal', 'amount' => $r['total'], 'status' => 'posted', 'created_by' => Auth::id()]);
            if ($r['deposit'] > 0) {
                DB::update('isp_accounts', ['deposit_status' => 'refunded', 'security_deposit' => 0], 'id = :id', ['id' => $accountId]);
            }
            if ($r['credit'] > 0) {
                DB::run("UPDATE isp_charges SET status = 'refunded', cancel_reason = :r WHERE account_id = :a AND status = 'pending' AND amount < 0",
                    ['a' => $accountId, 'r' => mb_substr('Refunded in DRF-' . $a['account_no'], 0, 255)]);
            }
            IspAccounts::event($accountId, 'deposit', 'Refunded to the subscriber: ' . money($r['total']) . ($r['deposit'] > 0 ? ' (deposit ' . money($r['deposit']) . ')' : '')
                . ($r['credit'] > 0 ? ' (unused service ' . money($r['credit']) . ')' : ''), 'journal', $txn, $r['total']);
            Audit::log('refund', 'isp', 'isp_account', $accountId, (string)$a['account_no'], ['reason' => $reason, 'amount' => $r['total']]);
            return $txn;
        });
    }

    // ------------------------------------------------------------------ runs

    /** The accounts a run would bill on $billDate (optionally one cycle day and branch) */
    public static function dueAccounts(string $billDate, ?int $cycleDay = null, ?int $branchId = null): array
    {
        $sql = "SELECT a.*, c.mobile, c.email, c.name AS customer_name FROM isp_accounts a JOIN isp_customers c ON c.id = a.isp_customer_id
                 WHERE a.service_status = 'active' AND a.activation_date IS NOT NULL AND a.activation_date <= :d";
        $p = ['d' => $billDate];
        if ($cycleDay) {
            $sql .= ' AND a.cycle_day = :cy';
            $p['cy'] = $cycleDay;
        }
        if ($branchId) {
            $sql .= ' AND a.branch_id = :br';
            $p['br'] = $branchId;
        }
        $out = [];
        foreach (DB::all($sql . ' ORDER BY a.account_no', $p) as $a) {
            if (self::isDue($a, $billDate)) {
                $out[] = $a;
            }
        }
        return $out;
    }

    /** The billing preview and validation report: nothing is saved */
    public static function preview(string $billDate, ?int $cycleDay = null, ?int $branchId = null): array
    {
        $rows = [];
        foreach (self::dueAccounts($billDate, $cycleDay, $branchId) as $a) {
            [$ps, $pe] = self::nextPeriod($a);
            if ($a['bill_timing'] === 'arrears' && $pe >= $billDate) {
                continue;
            }
            $key = 'A' . $a['id'] . ':regular:' . $ps;
            $dup = DB::value('SELECT invoice_no FROM isp_invoices WHERE period_key = :k', ['k' => $key]);
            $calc = self::compute(IspAccounts::get((int)$a['id']), $ps, $pe, $billDate);
            $rows[] = ['account' => $a, 'period_start' => $ps, 'period_end' => $pe, 'calc' => $calc, 'duplicate' => $dup];
        }
        return $rows;
    }

    /**
     * Generates a billing run: one draft invoice per due account. Safe to run again: accounts already invoiced for the
     * period are skipped. With $autoPost (no approval needed) the invoices post at once. Returns the run id.
     */
    public static function generate(string $billDate, ?int $cycleDay = null, ?int $branchId = null, string $source = 'manual', ?bool $autoPost = null): int
    {
        $autoPost ??= IspSetup::get('isp_runs_need_approval') !== '1';
        $lock = IspJobs::lock('isp_billing_generate', 30);
        try {
            $runId = DB::tx(fn() => DB::insert('isp_billing_runs', ['run_no' => Seq::next('isp_run'), 'bill_date' => $billDate, 'cycle_day' => $cycleDay, 'branch_id' => $branchId,
                'status' => 'draft', 'created_by' => Auth::id(), 'source' => $source]));
            $warnings = [];
            $count = 0;
            $total = 0.0;
            foreach (self::preview($billDate, $cycleDay, $branchId) as $r) {
                if ($r['duplicate']) {
                    continue;
                }
                try {
                    $id = DB::tx(function () use ($r, $billDate, $runId) {
                        $a = IspAccounts::get((int)$r['account']['id'], true);
                        // checked again under the lock of the account row: a second run never bills the same period
                        $np = self::nextPeriod($a);
                        if (!$np || $np[0] !== $r['period_start']) {
                            return null;
                        }
                        $calc = self::compute($a, $r['period_start'], $r['period_end'], $billDate);
                        return self::createDraft($a, 'regular', $r['period_start'], $r['period_end'], $billDate, $calc, $runId);
                    });
                } catch (UserError $e) {
                    $warnings[] = $r['account']['account_no'] . ': ' . $e->getMessage();
                    continue;
                }
                if ($id) {
                    $count++;
                    $total += (float)DB::value('SELECT total FROM isp_invoices WHERE id = :i', ['i' => $id]);
                    foreach ($r['calc']['warnings'] as $w) {
                        if ($w !== 'Nothing to bill.') {
                            $warnings[] = $r['account']['account_no'] . ': ' . $w;
                        }
                    }
                }
            }
            DB::update('isp_billing_runs', ['invoice_count' => $count, 'total_amount' => r2($total), 'warnings' => $warnings ? implode("\n", array_slice($warnings, 0, 500)) : null], 'id = :id', ['id' => $runId]);
            Audit::log('create', 'ispbill', 'isp_billing_run', $runId, (string)DB::value('SELECT run_no FROM isp_billing_runs WHERE id = :i', ['i' => $runId]),
                ['page_key' => 'ispbill.runs', 'amount' => $total, 'reason' => $count . ' draft invoices for ' . $billDate]);
            if ($autoPost && $count) {
                self::approveRun($runId, true);
                self::postRun($runId, true);
            }
            return $runId;
        } finally {
            IspJobs::unlock($lock);
        }
    }

    public static function approveRun(int $runId, bool $system = false): void
    {
        DB::tx(function () use ($runId, $system) {
            $r = DB::row('SELECT * FROM isp_billing_runs WHERE id = :id FOR UPDATE', ['id' => $runId]) ?? fail('Billing run not found.');
            if ($r['status'] !== 'draft') {
                fail('Only a draft run can be approved.');
            }
            if (!$system && !Access::special('isp_post_billing')) {
                fail('Approving a billing run needs the permission "Cable & Internet: can approve and post a billing run".');
            }
            DB::update('isp_billing_runs', ['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $runId]);
            DB::run("UPDATE isp_invoices SET status = 'approved', approved_by = :u, approved_at = :t WHERE run_id = :r AND status = 'draft'", ['u' => Auth::id(), 't' => date('Y-m-d H:i:s'), 'r' => $runId]);
            Audit::log('approve', 'ispbill', 'isp_billing_run', $runId, (string)$r['run_no'], ['page_key' => 'ispbill.runs']);
        });
    }

    /** Posts every approved invoice of a run, one by one (a failure is reported and the rest still posts) */
    public static function postRun(int $runId, bool $system = false): array
    {
        $r = DB::row('SELECT * FROM isp_billing_runs WHERE id = :id', ['id' => $runId]) ?? fail('Billing run not found.');
        if ($r['status'] !== 'approved') {
            fail('The run is ' . $r['status'] . '; it posts once approved.');
        }
        if (!$system && !Access::special('isp_post_billing')) {
            fail('Posting a billing run needs the permission "Cable & Internet: can approve and post a billing run".');
        }
        $ok = 0;
        $errors = [];
        foreach (DB::column("SELECT id FROM isp_invoices WHERE run_id = :r AND status = 'approved' ORDER BY id", ['r' => $runId]) as $iid) {
            try {
                self::post((int)$iid);
                $ok++;
            } catch (UserError $e) {
                $errors[] = 'Invoice D-' . $iid . ': ' . $e->getMessage();
            }
        }
        DB::update('isp_billing_runs', ['status' => $errors ? 'approved' : 'posted', 'posted_by' => Auth::id(), 'posted_at' => date('Y-m-d H:i:s'),
            'warnings' => trim(($r['warnings'] ?? '') . ($errors ? "\n" . implode("\n", $errors) : ''))], 'id = :id', ['id' => $runId]);
        Audit::log('post', 'ispbill', 'isp_billing_run', $runId, (string)$r['run_no'], ['page_key' => 'ispbill.runs', 'reason' => $ok . ' invoices posted' . ($errors ? ', ' . count($errors) . ' failed' : '')]);
        return ['posted' => $ok, 'errors' => $errors];
    }

    /** Cancels a run that has not posted: its drafts are removed (no number was used), their charges go back to pending */
    public static function cancelRun(int $runId, string $reason): void
    {
        DB::tx(function () use ($runId, $reason) {
            $r = DB::row('SELECT * FROM isp_billing_runs WHERE id = :id FOR UPDATE', ['id' => $runId]) ?? fail('Billing run not found.');
            if (!in_array($r['status'], ['draft', 'approved'], true)) {
                fail('A posted run is not cancelled; correct its invoices one by one.');
            }
            if (DB::value("SELECT id FROM isp_invoices WHERE run_id = :r AND status NOT IN ('draft', 'approved')", ['r' => $runId])) {
                fail('Some invoices of the run are already posted; cancel the rest one by one.');
            }
            foreach (DB::column('SELECT id FROM isp_invoices WHERE run_id = :r', ['r' => $runId]) as $iid) {
                DB::run("UPDATE isp_charges SET invoice_id = NULL WHERE invoice_id = :i AND status = 'pending'", ['i' => $iid]);
                DB::run('DELETE FROM isp_invoice_lines WHERE invoice_id = :i', ['i' => $iid]);
                DB::run('DELETE FROM isp_invoices WHERE id = :i', ['i' => $iid]);
            }
            DB::update('isp_billing_runs', ['status' => 'cancelled', 'warnings' => trim(($r['warnings'] ?? '') . "\nCancelled: " . $reason)], 'id = :id', ['id' => $runId]);
            Audit::log('cancel', 'ispbill', 'isp_billing_run', $runId, (string)$r['run_no'], ['page_key' => 'ispbill.runs', 'reason' => $reason]);
        });
    }

    // ------------------------------------------------------------------ due dates and late charges

    /** Marks unpaid invoices past their due date overdue. Returns how many changed. */
    public static function markOverdue(?string $asOf = null): int
    {
        $asOf ??= today();
        $n = 0;
        foreach (DB::column("SELECT id FROM isp_invoices WHERE status IN ('posted', 'partially_paid') AND balance > 0 AND due_date < :d", ['d' => $asOf]) as $id) {
            self::refresh((int)$id);
            $n++;
        }
        return $n;
    }

    /** One late charge per overdue invoice under its plan's policy (billed on the next invoice). Returns how many. */
    public static function assessLateFees(?string $asOf = null): int
    {
        $asOf ??= today();
        $after = IspSetup::int('isp_late_fee_after_days');
        $n = 0;
        $rows = DB::all("SELECT i.*, a.plan_id FROM isp_invoices i JOIN isp_accounts a ON a.id = i.account_id
                          WHERE i.status = 'overdue' AND i.balance > 0 AND i.late_fee_charge_id IS NULL AND i.kind <> 'final' AND a.service_status IN ('active', 'disconnected')");
        foreach ($rows as $inv) {
            if (IspPlans::addDays($inv['due_date'], $after) >= $asOf) {
                continue;
            }
            // a payment arrangement that waives penalties, or an exemption, protects the account
            if (DB::value("SELECT id FROM isp_arrangements WHERE account_id = :a AND status = 'active' AND waive_penalties = 1", ['a' => $inv['account_id']])) {
                continue;
            }
            $plan = IspPlans::get((int)$inv['plan_id']);
            $fee = IspPlans::lateFee($plan, (float)$inv['balance']);
            if ($fee <= 0) {
                continue;
            }
            DB::tx(function () use ($inv, $fee, $plan, $asOf, &$n) {
                $cid = self::addCharge((int)$inv['account_id'], 'late_fee', 'Late payment charge on invoice ' . $inv['invoice_no'] . ' (due ' . fdate($inv['due_date']) . ')', $fee, $asOf,
                    'LATE-' . $inv['id'], $plan['vat_mode'], null, 'invoice', (int)$inv['id']);
                DB::run('UPDATE isp_invoices SET late_fee_charge_id = :c WHERE id = :i', ['c' => $cid, 'i' => $inv['id']]);
                $n++;
            });
        }
        return $n;
    }

    /** After a disconnection the unbilled days are not charged: billing resumes from the reconnection date */
    public static function resumeFrom(int $accountId, string $date): void
    {
        $a = IspAccounts::get($accountId);
        $through = IspPlans::addDays($date, -1);
        if (!$a['billed_through'] || $a['billed_through'] < $through) {
            DB::run('UPDATE isp_accounts SET billed_through = :b WHERE id = :id', ['b' => $through, 'id' => $accountId]);
            IspAccounts::event($accountId, 'billing', 'Billing resumes from ' . fdate($date) . ' (no charge while disconnected)');
        }
    }
}
