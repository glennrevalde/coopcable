<?php
declare(strict_types=1);

/**
 * Payments & Verification: every subscriber collection, the references waiting for verification (portal submissions,
 * unconfirmed transfers), reversals, the online gateway's webhook events, and the printed collection receipt.
 */
require_once __DIR__ . '/_lib.php';

$pk = 'ispbill.payments';
$id = gint('id');
$a = (string)g('a');
// collection officers (edit right) and supervisors (approve right) verify references and apply gateway events
$mayVerify = Access::can($pk, 'edit') || Access::can($pk, 'approve');

// ---------------------------------------------------------------- the proof of payment file
if ($id && $a === 'file') {
    ispbill_send_file((int)gint('file'), 'payment', $id);
}

handle_post(function () use ($pk) {
    $action = (string)p('action');
    $pid = (int)p('id');
    if ($action === 'verify' || $action === 'reject') {
        // collection officers (edit) and supervisors (approve) verify; the person who took the reference does not
        // verify it alone, unless a supervisor
        if (!Access::can($pk, 'edit') && !Access::can($pk, 'approve')) {
            Access::require($pk, 'approve');
        }
        $own = (int)DB::value('SELECT created_by FROM isp_payments WHERE id = :id', ['id' => $pid]) === (int)Auth::id();
        if ($own && !Access::can($pk, 'approve') && !Access::isAdmin()) {
            fail('A payment reference you recorded yourself is verified by another collection officer or a supervisor.');
        }
        $alloc = [];
        foreach ((array)($_POST['alloc'] ?? []) as $iid => $v) {
            if (is_scalar($v) && trim((string)$v) !== '' && to_num($v) > 0) {
                $alloc[(int)$iid] = to_num($v);
            }
        }
        IspPayments::verify($pid, $action === 'verify', trim((string)p('note')), $alloc);
        redirect(url($pk, ['id' => $pid]), $action === 'verify' ? 'Payment verified and posted.' : 'Payment rejected; the subscriber is told.', 'success');
    }
    if ($action === 'reverse') {
        Access::require($pk, 'delete');
        IspPayments::reverse($pid, trim((string)p('reason')));
        redirect(url($pk, ['id' => $pid]), 'Payment reversed. The invoices and the ledger were corrected.', 'success');
    }
    if ($action === 'upload') {
        Access::require($pk, 'edit');
        DB::value('SELECT id FROM isp_payments WHERE id = :id', ['id' => $pid]) ?: fail('Payment not found.');
        ispbill_save_upload('proof', 'payment', $pid, trim((string)p('label')) ?: 'Proof of payment') ?? fail('Choose the file to upload.');
        redirect(url($pk, ['id' => $pid]), 'File attached.', 'success');
    }
    if ($action === 'apply_event') {
        // a verified gateway payment whose account could not be found: a person names the account
        if (!Access::can($pk, 'edit')) {
            Access::require($pk, 'approve');
        }
        $ev = DB::row('SELECT * FROM isp_gateway_events WHERE id = :id', ['id' => (int)p('event_id')]) ?? fail('Gateway event not found.');
        if ((int)$ev['signature_ok'] !== 1 || $ev['status'] !== 'failed' || $ev['payment_id']) {
            fail('Only an authenticated gateway payment that could not be applied can be applied by hand.');
        }
        $acct = IspUi::postedAccount('account_id') ?? fail('Choose the subscriber account the payment belongs to.');
        $amount = (float)$ev['amount'] > 0 ? (float)$ev['amount'] : pnum('amount');
        $payId = IspPayments::collect($acct, $amount, 'gateway', ['source' => 'gateway', 'reference_no' => $ev['reference_no'] ?: $ev['event_id'], 'gateway_event_id' => (int)$ev['id'],
            'gateway_fee' => (float)$ev['fee'], 'notes' => 'Gateway event ' . $ev['event_id'] . ' applied by hand', 'page_key' => $pk]);
        DB::update('isp_gateway_events', ['status' => 'posted', 'payment_id' => $payId, 'message' => 'Applied by hand by ' . (Auth::user()['username'] ?? '')], 'id = :id', ['id' => $ev['id']]);
        Audit::log('apply', 'ispbill', 'isp_gateway_event', (int)$ev['id'], (string)$ev['event_id'], ['amount' => $amount]);
        redirect(url($pk, ['id' => $payId]), 'Gateway payment applied.', 'success');
    }
});

// ================================================================ the printed receipt
if ($id && $a === 'print') {
    Access::require($pk, 'print');
    $pay = IspPayments::get($id) ?? fail('Payment not found.');
    $acct = IspAccounts::get((int)$pay['account_id']);
    $cust = IspAccounts::customer((int)$acct['isp_customer_id']);
    $allocs = DB::all('SELECT al.*, i.invoice_no, i.period_start, i.period_end, i.kind FROM isp_payment_allocs al JOIN isp_invoices i ON i.id = al.invoice_id WHERE al.payment_id = :p ORDER BY al.id', ['p' => $id]);
    $isAck = $pay['status'] !== 'posted' && $pay['status'] !== 'reversed';
    $title = $isAck ? IspSetup::get('isp_ack_title') : IspSetup::get('isp_receipt_title');
    Audit::log('print', 'ispbill', 'isp_payment', $id, (string)$pay['receipt_no']);
    layout_start($title . ' ' . $pay['receipt_no'], ['bare' => true, 'no_title' => true]);
    ?>
    <div class="no-print d-flex gap-2 mb-3"><button class="btn btn-sm btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
      <a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pk, ['id' => $id])) ?>">Back to the payment</a></div>
    <div class="isp-doc isp-receipt">
      <?= IspUi::letterhead($title, '<div class="doc-no">No. <strong>' . h($pay['receipt_no']) . '</strong></div><div>Date ' . h(fdate($pay['pay_date'])) . '</div>') ?>
      <?php if ($pay['status'] === 'reversed'): ?><div class="doc-stamp">REVERSED <?= h(fdate($pay['reversed_at'])) ?>: <?= h($pay['reversal_reason']) ?></div><?php endif; ?>
      <?php if ($pay['status'] === 'rejected'): ?><div class="doc-stamp">REJECTED: <?= h($pay['rejected_reason']) ?></div><?php endif; ?>
      <?php if ($pay['status'] === 'pending'): ?><div class="doc-stamp">PENDING VERIFICATION: not yet credited to the account</div><?php endif; ?>
      <table class="table table-sm doc-meta">
        <tr><th style="width:28%">Received from</th><td><strong><?= h($cust['name'] ?? $acct['account_name']) ?></strong><?= ($cust['tin'] ?? '') !== '' ? '<br>TIN ' . h($cust['tin']) : '' ?></td></tr>
        <tr><th>Account</th><td><?= h($acct['account_no'] . ' ' . $acct['account_name']) ?><br><span class="small"><?= h((string)($cust['billing_address'] ?: $acct['install_address'])) ?></span></td></tr>
        <tr><th>The sum of</th><td><strong><?= h(ucfirst(amount_in_words((float)$pay['amount']))) ?></strong> (PHP <?= money($pay['amount']) ?>)</td></tr>
        <tr><th>Paid by</th><td><?= h(IspPayments::CHANNELS[$pay['channel']] ?? $pay['channel']) ?><?= $pay['reference_no'] ? ', ref. ' . h($pay['reference_no']) : '' ?><?= $pay['check_no'] ? ', check no. ' . h($pay['check_no']) . ($pay['check_bank'] ? ' ' . h($pay['check_bank']) : '') : '' ?></td></tr>
      </table>
      <table class="table table-sm doc-lines">
        <thead><tr><th>In payment of</th><th>Period</th><th class="num">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($allocs as $al): ?>
          <tr><td>Invoice <?= h($al['invoice_no']) ?><?= (int)$al['is_reversed'] ? ' (reversed)' : '' ?></td><td><?= $al['period_start'] ? h(fdate($al['period_start']) . ' - ' . fdate($al['period_end'])) : h(ucfirst($al['kind'])) ?></td><td class="num"><?= money($al['amount']) ?></td></tr>
        <?php endforeach; ?>
        <?php $adv = r2((float)$pay['amount'] - array_sum(array_map(fn($x) => (float)$x['amount'], $allocs))); if ($adv > 0.004 && $pay['status'] === 'posted'): ?>
          <tr><td>Advance payment (credited to the next invoices)</td><td></td><td class="num"><?= money($adv) ?></td></tr>
        <?php elseif (!$allocs): ?>
          <tr><td colspan="2"><?= $isAck ? 'To be applied to the account once verified' : 'Account ' . h($acct['account_no']) ?></td><td class="num"><?= money($pay['amount']) ?></td></tr>
        <?php endif; ?>
        </tbody>
        <tfoot><tr><th colspan="2">Total</th><th class="num"><?= money($pay['amount']) ?></th></tr></tfoot>
      </table>
      <div class="doc-foot row g-3">
        <div class="col-7 small">
          <?php if ($isAck): ?><p class="mb-1">This acknowledges the payment reference only. It is credited to the account once verified.</p><?php endif; ?>
          <p class="mb-1">This document is not valid for claim of input tax.</p>
          <?= ispbill_bir_foot() ?>
        </div>
        <div class="col-5 text-center small"><div class="doc-sign"><?= h($pay['collector_name'] ?: '') ?></div>Cashier / authorized representative<div class="text-muted"><?= h(fdatetime($pay['received_at'])) ?></div></div>
      </div>
    </div>
    <?php if (g('autoprint') === '1'): ?><script>window.addEventListener('load', function () { window.print(); });</script><?php endif; ?>
    <?php
    layout_end();
    return;
}

// ================================================================ one payment
if ($id) {
    $pay = IspPayments::get($id) ?? fail('Payment not found.');
    $acct = IspAccounts::get((int)$pay['account_id']);
    $bp = [];
    if (IspSetup::branchSql('a.branch_id', $bp) !== '' && $acct['branch_id'] && (int)$acct['branch_id'] !== (int)IspSetup::userBranch()) {
        Access::denyPage($pk, 'open another branch\'s payment');
    }
    $allocs = DB::all('SELECT al.*, i.invoice_no, i.period_start, i.period_end, i.kind, i.status AS inv_status FROM isp_payment_allocs al JOIN isp_invoices i ON i.id = al.invoice_id WHERE al.payment_id = :p ORDER BY al.id', ['p' => $id]);
    $files = ispbill_files('payment', $id);
    $ev = $pay['gateway_event_id'] ? DB::row('SELECT * FROM isp_gateway_events WHERE id = :i', ['i' => $pay['gateway_event_id']]) : null;
    $names = DB::pairs('SELECT id, full_name FROM users WHERE id IN (' . implode(',', array_map('intval', array_filter([$pay['verified_by'], $pay['reversed_by'], $pay['reversal_approved_by'], $pay['created_by']]) ?: [0])) . ')');
    $actions = '';
    if ($pay['status'] !== 'rejected' && Access::can($pk, 'print')) {
        $actions .= btn_link(url($pk, ['id' => $id, 'a' => 'print']), 'Print receipt', 'btn-primary', 'printer') . ' ';
    }
    if ($pay['status'] === 'posted' && Access::can($pk, 'delete') && Access::special('isp_reverse_payment')) {
        $actions .= '<button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#reverseModal"><i class="bi bi-arrow-counterclockwise"></i> Reverse</button>';
    }
    layout_start('Payment ' . $pay['receipt_no'], ['actions' => $actions]);
    ?>
    <div class="card isp-card mb-3"><div class="card-body">
      <div class="isp-head"><div>
        <h2 class="isp-head-title"><?= money($pay['amount']) ?> <span class="fw-normal text-muted">by <?= h(IspPayments::CHANNELS[$pay['channel']] ?? $pay['channel']) ?></span></h2>
        <div class="isp-head-sub"><?= IspUi::status($pay['status'], IspPayments::STATUSES) ?> <span class="ms-2"><?= IspUi::acct($pay['account_id'], $pay['account_no'], $pay['account_name']) ?></span></div>
      </div><div class="isp-head-actions"><?= btn_link(url($pk), 'All payments', 'btn-outline-secondary', 'list-ul') ?></div></div>
      <?= IspUi::dl([
          'Receipt no.' => h($pay['receipt_no']), 'Payment date' => h(fdate($pay['pay_date'])), 'Received' => h(fdatetime($pay['received_at'])),
          'Source' => h(ucfirst(str_replace('_', ' ', (string)$pay['source']))), 'Reference' => h((string)$pay['reference_no']),
          'Check' => $pay['check_no'] ? h($pay['check_no'] . ' ' . $pay['check_bank']) : '', 'Collector' => h((string)$pay['collector_name']),
          'Shift' => $pay['shift_id'] ? safe_link('ispbill.shifts', ['id' => $pay['shift_id']], (string)DB::value('SELECT shift_no FROM isp_shifts WHERE id = :s', ['s' => $pay['shift_id']])) : '',
          'Applied' => money($pay['applied_amount']), 'Advance credit left' => (float)$pay['unapplied_amount'] > 0 ? money($pay['unapplied_amount']) : '',
          'Gateway fee' => (float)$pay['gateway_fee'] > 0 ? money($pay['gateway_fee']) : '',
          'Ledger' => trim(ispbill_txn($pay['txn_id'] ? (int)$pay['txn_id'] : null) . ' ' . ispbill_txn($pay['fee_txn_id'] ? (int)$pay['fee_txn_id'] : null, 'gateway fee') . ' ' . ispbill_txn($pay['sav_txn_id'] ? (int)$pay['sav_txn_id'] : null, 'savings debit')),
          'Verified' => $pay['verified_at'] ? h(($names[$pay['verified_by']] ?? '') . ', ' . fdatetime($pay['verified_at'])) : '',
          'Rejected because' => h((string)$pay['rejected_reason']),
          'Reversed' => $pay['reversed_at'] ? h(($names[$pay['reversed_by']] ?? '') . ', ' . fdatetime($pay['reversed_at']) . ': ' . $pay['reversal_reason']) : '',
          'Notes' => h((string)$pay['notes']),
      ]) ?>
    </div></div>

    <?php if ($pay['status'] === 'pending'):
        $open = IspPayments::openInvoices((int)$pay['account_id']); ?>
    <div class="card isp-card mb-3 border-warning"><div class="card-body">
      <h2 class="sec-title">Verify the payment</h2>
      <p class="small text-muted">Check the reference against the bank or e-wallet statement before posting it. Posting credits the account, updates the invoices and may reconnect the service.</p>
      <?php if ($mayVerify): ?>
      <form method="post" class="row g-2">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
        <?php if ($open): ?>
        <div class="col-12"><div class="table-responsive"><table class="table table-sm align-middle isp-table">
          <thead><tr><th>Invoice</th><th>Due</th><th class="num">Balance</th><th class="num" style="width:9rem">Apply</th></tr></thead>
          <tbody><?php foreach ($open as $inv): ?><tr><td><?= h($inv['invoice_no']) ?></td><td><?= h(fdate($inv['due_date'])) ?></td><td class="num"><?= money($inv['balance']) ?></td>
            <td><input class="form-control form-control-sm text-end" name="alloc[<?= (int)$inv['id'] ?>]" placeholder="auto" inputmode="decimal"></td></tr><?php endforeach; ?></tbody>
        </table></div></div>
        <?php endif; ?>
        <div class="col-md-8"><label class="form-label">Note (required to reject: the subscriber is told)</label><input class="form-control form-control-sm" name="note" maxlength="255"></div>
        <div class="col-md-4 d-flex gap-1 align-items-end">
          <button class="btn btn-sm btn-success" name="action" value="verify"><i class="bi bi-check2"></i> Verify and post</button>
          <button class="btn btn-sm btn-outline-danger" name="action" value="reject" data-confirm="Reject this payment reference?"><i class="bi bi-x-lg"></i> Reject</button></div>
      </form>
      <?php else: ?><p class="mb-0">Waiting for a collection officer to verify it.</p><?php endif; ?>
    </div></div>
    <?php endif; ?>

    <div class="row g-3">
      <div class="col-lg-7"><div class="card isp-card h-100"><div class="card-body">
        <h2 class="sec-title">Applied to</h2>
        <?php if (!$allocs): echo IspUi::empty($pay['status'] === 'posted' ? 'Not applied to an invoice: the whole amount is an advance credit.' : 'Nothing applied yet.', 'receipt'); else: ?>
        <div class="table-responsive"><table class="table table-sm isp-table">
          <thead><tr><th>Invoice</th><th>Period</th><th>Invoice status</th><th class="num">Amount</th></tr></thead>
          <tbody><?php foreach ($allocs as $al): ?>
            <tr class="<?= (int)$al['is_reversed'] ? 'text-decoration-line-through text-muted' : '' ?>"><td><?= safe_link('ispbill.invoices', ['id' => $al['invoice_id']], (string)$al['invoice_no']) ?></td>
              <td class="small"><?= $al['period_start'] ? h(fdate($al['period_start']) . ' - ' . fdate($al['period_end'])) : h(ucfirst($al['kind'])) ?></td>
              <td><?= IspUi::status($al['inv_status'], IspBilling::STATUSES) ?></td><td class="num"><?= money($al['amount']) ?></td></tr>
          <?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
        <?php if ($ev): ?>
        <h2 class="sec-title mt-3">Gateway event</h2>
        <?= IspUi::dl(['Provider' => h(IspGateway::PROVIDERS[$ev['provider']] ?? $ev['provider']), 'Event' => h($ev['event_id'] . ' ' . $ev['event_type']),
            'Signature' => (int)$ev['signature_ok'] ? IspUi::status('ok', ['ok' => 'Verified']) : IspUi::status('failed', ['failed' => 'Failed']), 'Received' => h(fdatetime($ev['created_at']))]) ?>
        <?php endif; ?>
      </div></div></div>
      <div class="col-lg-5"><div class="card isp-card h-100"><div class="card-body">
        <h2 class="sec-title">Proof of payment</h2>
        <?php if (!$files): ?><p class="small text-muted">No file attached.</p><?php else: ?>
        <ul class="list-unstyled mb-3"><?php foreach ($files as $f): ?>
          <li class="mb-1"><i class="bi bi-<?= $f['mime'] === 'application/pdf' ? 'file-earmark-pdf' : 'image' ?>"></i>
            <a href="<?= h(url($pk, ['id' => $id, 'a' => 'file', 'file' => $f['id']])) ?>" target="_blank" rel="noopener"><?= h($f['original_name']) ?></a>
            <span class="small text-muted"><?= h($f['label']) ?>, <?= h(fdatetime($f['created_at'])) ?><?= $f['uploader'] ? ', ' . h($f['uploader']) : ($f['portal_user_id'] ? ', from the portal' : '') ?></span></li>
        <?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if (Access::can($pk, 'edit') && $pay['status'] !== 'rejected'): ?>
        <form method="post" enctype="multipart/form-data" class="row g-2">
          <?= csrf_field() ?><input type="hidden" name="action" value="upload"><input type="hidden" name="id" value="<?= (int)$id ?>">
          <div class="col-12"><input class="form-control form-control-sm" type="file" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf" required></div>
          <div class="col-8"><input class="form-control form-control-sm" name="label" placeholder="Description (deposit slip, screenshot)" maxlength="120"></div>
          <div class="col-4 d-grid"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-paperclip"></i> Attach</button></div>
        </form>
        <?php endif; ?>
      </div></div></div>
    </div>

    <?php if ($pay['status'] === 'posted' && Access::can($pk, 'delete') && Access::special('isp_reverse_payment')): ?>
    <div class="modal fade" id="reverseModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="reverse"><input type="hidden" name="id" value="<?= (int)$id ?>">
      <div class="modal-header"><h5 class="modal-title">Reverse payment <?= h($pay['receipt_no']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <p class="small">The allocations, the ledger payment<?= (float)$pay['gateway_fee'] > 0 ? ', its gateway fee' : '' ?><?= $pay['sav_txn_id'] ? ', the savings debit' : '' ?> and the invoice balances are reversed. The account may again qualify for disconnection. Nothing is deleted.</p>
        <label class="form-label">Reason (required, recorded permanently)</label><textarea class="form-control" name="reason" rows="2" required maxlength="240"></textarea></div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Reverse payment</button></div>
    </form></div></div>
    <?php endif; ?>
    <?php
    layout_end();
    return;
}

// ================================================================ lists
$tab = (string)g('tab', 'all');
$tabs = ['all' => 'All payments', 'pending' => 'Waiting for verification', 'reversed' => 'Reversed and rejected', 'gateway' => 'Gateway events'];
if (!isset($tabs[$tab])) {
    $tab = 'all';
}
$nPending = (int)DB::value("SELECT COUNT(*) FROM isp_payments WHERE status = 'pending'");
if ($nPending) {
    $tabs['pending'] .= ' (' . $nPending . ')';
}
$actions = page_link('ispbill.collect', [], 'Collect payment', 'btn-primary', 'cash-coin', 'create');
layout_start('Payments & Verification', ['actions' => $actions]);
echo IspUi::tabs($tabs, $tab, $pk);

if ($tab === 'gateway') {
    $st = (string)g('status');
    $p = [];
    $where = '1 = 1';
    if ($st !== '' && in_array($st, ['received', 'posted', 'failed', 'rejected', 'ignored'], true)) {
        $where .= ' AND e.status = :s';
        $p['s'] = $st;
    }
    if (($q = trim((string)g('q'))) !== '') {
        $where .= ' AND (e.event_id LIKE :q OR e.account_no LIKE :q2 OR e.reference_no LIKE :q3)';
        $p += ['q' => "%$q%", 'q2' => "%$q%", 'q3' => "%$q%"];
    }
    [$per, $off, $page] = IspUi::paging(50);
    $total = (int)DB::value("SELECT COUNT(*) FROM isp_gateway_events e WHERE $where", $p);
    $rows = DB::all("SELECT e.*, y.receipt_no FROM isp_gateway_events e LEFT JOIN isp_payments y ON y.id = e.payment_id WHERE $where ORDER BY e.id DESC LIMIT $per OFFSET $off", $p);
    echo IspUi::filterStart($pk, ['tab' => 'gateway']) . IspUi::fSearch((string)g('q'), 'Event, account or reference')
        . IspUi::fSelect('status', 'Status', ['received' => 'Received', 'posted' => 'Posted', 'failed' => 'Not applied', 'rejected' => 'Signature failed', 'ignored' => 'Ignored'], $st)
        . IspUi::filterEnd(url($pk, ['tab' => 'gateway']));
    ?>
    <div class="card isp-card"><div class="card-body">
      <p class="small text-muted">Only an authenticated webhook of the configured provider posts a payment, once per provider event. A failed signature is never posted. An authenticated payment whose account could not be found can be applied here by a supervisor.</p>
      <?php if (!$rows): echo IspUi::empty('No gateway event yet.', 'globe'); else: ?>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
        <thead><tr><th>Received</th><th>Provider</th><th>Event</th><th>Signature</th><th>Account</th><th class="num">Amount</th><th class="num">Fee</th><th>Status</th><th>Result</th></tr></thead>
        <tbody><?php foreach ($rows as $e): ?>
          <tr><td class="small text-nowrap"><?= h(fdatetime($e['created_at'])) ?></td><td><?= h(IspGateway::PROVIDERS[$e['provider']] ?? $e['provider']) ?></td>
            <td class="small"><?= h($e['event_id']) ?><div class="text-muted"><?= h($e['event_type']) ?></div></td>
            <td><?= (int)$e['signature_ok'] ? IspUi::status('ok', ['ok' => 'Verified']) : IspUi::status('failed', ['failed' => 'Failed']) ?></td>
            <td><?= h((string)$e['account_no']) ?></td><td class="num"><?= $e['amount'] !== null ? money($e['amount']) : '' ?></td><td class="num"><?= $e['fee'] !== null ? money($e['fee']) : '' ?></td>
            <td><?= IspUi::status($e['status'], ['received' => 'Received', 'posted' => 'Posted', 'failed' => 'Not applied', 'rejected' => 'Rejected', 'ignored' => 'Ignored']) ?></td>
            <td class="small"><?= $e['payment_id'] ? '<a href="' . h(url($pk, ['id' => $e['payment_id']])) . '">' . h($e['receipt_no']) . '</a> ' : '' ?><?= h((string)$e['message']) ?>
              <?php if ((int)$e['signature_ok'] === 1 && $e['status'] === 'failed' && !$e['payment_id'] && $mayVerify): ?>
              <form method="post" class="row g-1 mt-1">
                <?= csrf_field() ?><input type="hidden" name="action" value="apply_event"><input type="hidden" name="event_id" value="<?= (int)$e['id'] ?>">
                <?= IspUi::accountField('account_id', null, 'Apply to account', 'col-12') ?>
                <?php if ((float)$e['amount'] <= 0): ?><div class="col-6"><input class="form-control form-control-sm" name="amount" placeholder="Amount" inputmode="decimal" required></div><?php endif; ?>
                <div class="col-6"><button class="btn btn-sm btn-outline-primary" data-confirm="Post this gateway payment to the chosen account?">Apply</button></div>
              </form>
              <?php endif; ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <?= IspUi::pager($total, $per, $page) ?>
      <?php endif; ?>
    </div></div>
    <?php
    layout_end();
    return;
}

$from = gdate('from', $tab === 'all' ? date('Y-m-01') : date('Y-m-d', strtotime('-90 days')));
$to = gdate('to', today());
$channel = (string)g('channel');
$status = (string)g('status');
$cashier = gint('cashier');
$q = trim((string)g('q'));
$p = ['f' => $from, 't' => $to];
$where = 'p.pay_date BETWEEN :f AND :t';
if ($tab === 'pending') {
    $where = "p.status = 'pending'";
    $p = [];
} elseif ($tab === 'reversed') {
    $where .= " AND p.status IN ('reversed', 'rejected')";
} elseif ($status !== '' && isset(IspPayments::STATUSES[$status])) {
    $where .= ' AND p.status = :st';
    $p['st'] = $status;
}
if ($channel !== '' && isset(IspPayments::CHANNELS[$channel])) {
    $where .= ' AND p.channel = :ch';
    $p['ch'] = $channel;
}
if ($cashier) {
    $where .= ' AND p.collector_user_id = :cu';
    $p['cu'] = $cashier;
}
if ($q !== '') {
    $where .= ' AND (p.receipt_no LIKE :q1 OR p.reference_no LIKE :q2 OR a.account_no LIKE :q3 OR a.account_name LIKE :q4 OR p.check_no LIKE :q5)';
    $p += ['q1' => "%$q%", 'q2' => "%$q%", 'q3' => "$q%", 'q4' => "%$q%", 'q5' => "$q%"];
}
$where .= IspSetup::branchSql('a.branch_id', $p);
[$per, $off, $page] = IspUi::paging(50);
$sum = DB::row("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN p.status = 'posted' THEN p.amount END), 0) AS posted, COALESCE(SUM(CASE WHEN p.status = 'pending' THEN p.amount END), 0) AS pending,
                       COALESCE(SUM(CASE WHEN p.status = 'posted' THEN p.gateway_fee END), 0) AS fees
                  FROM isp_payments p JOIN isp_accounts a ON a.id = p.account_id WHERE $where", $p);
$rows = DB::all("SELECT p.*, a.account_no, a.account_name, u.full_name AS collector
                   FROM isp_payments p JOIN isp_accounts a ON a.id = p.account_id LEFT JOIN users u ON u.id = p.collector_user_id
                  WHERE $where ORDER BY " . ($tab === 'pending' ? 'p.id' : 'p.pay_date DESC, p.id DESC') . " LIMIT $per OFFSET $off", $p);

echo IspUi::filterStart($pk, ['tab' => $tab]) . IspUi::fSearch($q, 'Receipt, reference, check or account');
if ($tab !== 'pending') {
    echo IspUi::fDate('from', 'From', $from) . IspUi::fDate('to', 'To', $to);
}
echo IspUi::fSelect('channel', 'Channel', IspPayments::CHANNELS, $channel);
if ($tab === 'all') {
    echo IspUi::fSelect('status', 'Status', IspPayments::STATUSES, $status);
}
echo IspUi::fSelect('cashier', 'Collected by', ispbill_cashiers(), $cashier) . IspUi::filterEnd(url($pk, ['tab' => $tab]));
?>
<div class="kpi-grid mb-3">
  <?= IspUi::kpi('Payments', number_format((int)$sum['n']), $tab === 'pending' ? 'waiting' : fdate($from) . ' to ' . fdate($to), null, 'receipt', 'info') ?>
  <?= IspUi::kpi('Posted', money($sum['posted']), 'credited to the accounts', null, 'check2-circle', 'ok') ?>
  <?= IspUi::kpi('Pending verification', money($sum['pending']), 'not yet credited', $tab !== 'pending' ? url($pk, ['tab' => 'pending']) : null, 'hourglass-split', 'warn') ?>
  <?php if ((float)$sum['fees'] > 0): ?><?= IspUi::kpi('Gateway fees', money($sum['fees']), 'charged by the provider', null, 'globe', 'idle') ?><?php endif; ?>
</div>
<div class="card isp-card"><div class="card-body">
  <?php if (!$rows): echo IspUi::empty($tab === 'pending' ? 'No payment is waiting for verification.' : 'No payment matches.', 'receipt'); else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
    <thead><tr><th>Receipt</th><th>Date</th><th>Account</th><th>Channel</th><th>Reference</th><th>Collected by</th><th>Status</th><th class="num">Amount</th><th class="num">Advance</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr><td><a href="<?= h(url($pk, ['id' => $r['id']])) ?>"><?= h($r['receipt_no']) ?></a></td><td class="text-nowrap"><?= h(fdate($r['pay_date'])) ?></td>
        <td><?= IspUi::acct($r['account_id'], $r['account_no'], $r['account_name']) ?></td><td><?= h(IspPayments::CHANNELS[$r['channel']] ?? $r['channel']) ?></td>
        <td class="small"><?= h((string)($r['reference_no'] ?: $r['check_no'])) ?></td><td class="small"><?= h((string)$r['collector']) ?><?= $r['source'] !== 'counter' ? ' <span class="text-muted">(' . h(str_replace('_', ' ', (string)$r['source'])) . ')</span>' : '' ?></td>
        <td><?= IspUi::status($r['status'], IspPayments::STATUSES) ?></td><td class="num"><?= money($r['amount']) ?></td><td class="num"><?= money($r['unapplied_amount'], true) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?= IspUi::pager((int)$sum['n'], $per, $page) ?>
  <?php endif; ?>
</div></div>
<?php
layout_end();
