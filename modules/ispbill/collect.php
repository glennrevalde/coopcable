<?php
declare(strict_types=1);

/**
 * Collect Payment: the cashier's counter screen. Find the subscriber, see what is open, take the money (any channel),
 * apply it to the invoices (oldest first unless the cashier chooses), print the collection receipt.
 */
require_once __DIR__ . '/_lib.php';

$pk = 'ispbill.collect';
$uid = (int)Auth::id();
$shift = IspCashier::current($uid);
$accountId = IspUi::postedAccount('account_id');

handle_post(function () use ($pk, $uid) {
    if ((string)p('action') !== 'collect') {
        return;
    }
    Access::require($pk, 'create');
    $accountId = IspUi::postedAccount('account_id') ?? fail('Choose the subscriber account.');
    $channel = (string)p('channel');
    $pending = p('as_pending') === '1' && $channel !== 'cash';
    $shift = IspCashier::current($uid);
    if (!$pending && $channel === 'check' && !$shift) {
        fail('Open your cashier shift first: checks received at the counter are counted with the shift.');
    }
    $date = (string)p('pay_date', today());
    if ($date !== today() && !Access::can($pk, 'approve')) {
        fail('Only a supervisor records a payment with a date other than today.');
    }
    $alloc = [];
    foreach ((array)($_POST['alloc'] ?? []) as $iid => $v) {
        if (is_scalar($v) && trim((string)$v) !== '' && to_num($v) > 0) {
            $alloc[(int)$iid] = to_num($v);
        }
    }
    $proof = $_FILES['proof'] ?? null;
    if (is_array($proof) && ($proof['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        // checked before anything is saved: a rejected file never leaves a payment without its proof
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$_FILES['proof']['tmp_name']);
        if (!isset(ISPBILL_FILE_TYPES[$mime]) || (int)$_FILES['proof']['size'] > ISPBILL_FILE_MAX) {
            fail('The proof of payment must be a JPG, PNG, WEBP or PDF file of at most 5 MB.');
        }
    }
    $id = IspPayments::collect($accountId, pnum('amount'), $channel, [
        'pay_date' => $date, 'reference_no' => trim((string)p('reference_no')), 'check_no' => trim((string)p('check_no')), 'check_bank' => trim((string)p('check_bank')),
        'allocations' => $alloc, 'source' => 'counter', 'status' => $pending ? 'pending' : 'posted', 'shift_id' => $shift ? (int)$shift['id'] : null,
        'sav_account_id' => pint('sav_account_id'), 'notes' => trim((string)p('notes')), 'page_key' => $pk,
    ]);
    ispbill_save_upload('proof', 'payment', $id, 'Proof of payment (counter)');
    redirect(url($pk, ['done' => $id]), $pending ? 'Payment reference recorded: it waits for verification before it counts.' : 'Payment posted.', 'success');
});

$account = $accountId ? IspAccounts::get($accountId) : null;
$done = gint('done') ? IspPayments::get((int)gint('done')) : null;

$actions = ($shift ? btn_link(url('ispbill.shifts'), 'Shift ' . $shift['shift_no'], 'btn-outline-secondary', 'cash-stack') : '')
    . ' ' . page_link('ispbill.payments', [], 'Payments', 'btn-outline-secondary', 'list-ul');
layout_start('Collect Payment', ['actions' => $actions]);

if ($done): ?>
<div class="card isp-card mb-3 border-success">
  <div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
    <div>
      <div class="fw-semibold"><i class="bi bi-check-circle-fill text-success"></i>
        <?= $done['status'] === 'pending' ? 'Reference ' . h($done['reference_no']) . ' recorded, waiting for verification' : 'Received ' . money($done['amount']) . ' from ' . h($done['account_name']) ?></div>
      <div class="small text-muted"><?= h($done['receipt_no']) ?>, <?= h(IspPayments::CHANNELS[$done['channel']] ?? $done['channel']) ?>
        <?php if ((float)$done['unapplied_amount'] > 0): ?>, <?= money($done['unapplied_amount']) ?> kept as advance credit<?php endif; ?></div>
    </div>
    <div class="d-flex gap-1 flex-wrap">
      <?php if (Access::can('ispbill.payments', 'print') || Access::can($pk, 'print')): ?>
      <a class="btn btn-sm btn-primary" target="_blank" href="<?= h(url('ispbill.payments', ['id' => $done['id'], 'a' => 'print'])) ?>"><i class="bi bi-printer"></i> Print receipt</a>
      <?php endif; ?>
      <a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pk, ['account_id' => $done['account_id']])) ?>">Same subscriber</a>
      <a class="btn btn-sm btn-outline-primary" href="<?= h(url($pk)) ?>" autofocus><i class="bi bi-plus-lg"></i> Next payment</a>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!$shift): ?>
<div class="alert alert-warning d-flex gap-2 align-items-start"><i class="bi bi-exclamation-triangle-fill"></i>
  <div>Your cashier shift is not open. Cash and checks are received only in an open shift (they are counted when it closes).
    <?php if (Access::can('ispbill.shifts', 'create')): ?><a href="<?= h(url('ispbill.shifts')) ?>">Open a shift</a>.<?php endif; ?>
    Bank, GCash, Maya and other references can still be recorded.</div></div>
<?php endif; ?>

<form class="card isp-card mb-3 no-print" method="get" action="<?= h(base_url() . '/index.php') ?>">
  <input type="hidden" name="p" value="<?= h($pk) ?>">
  <div class="card-body row g-2 align-items-end">
    <?= IspUi::accountField('account_id', $account ? (int)$account['id'] : null, 'Find the subscriber', 'col-md-9', true) ?>
    <div class="col-md-3 d-grid"><button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Open account</button></div>
  </div>
</form>

<?php if (!$account):
    if (g('account_id_text') !== '') {
        echo '<div class="alert alert-warning">No account matches "' . h(g('account_id_text')) . '". Type the account number, or pick from the list that appears as you type.</div>';
    }
    // the latest collections of this cashier
    $mine = DB::all('SELECT p.*, a.account_no, a.account_name FROM isp_payments p JOIN isp_accounts a ON a.id = p.account_id WHERE p.collector_user_id = :u AND p.pay_date = :d ORDER BY p.id DESC LIMIT 15',
        ['u' => $uid, 'd' => today()]);
    ?>
    <div class="card isp-card"><div class="card-body">
      <h2 class="sec-title">Your collections today</h2>
      <?php if (!$mine): echo IspUi::empty('Nothing collected yet today.', 'receipt'); else: ?>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
        <thead><tr><th>Receipt</th><th>Account</th><th>Channel</th><th>Status</th><th class="num">Amount</th></tr></thead>
        <tbody><?php foreach ($mine as $r): ?>
          <tr><td><a href="<?= h(url('ispbill.payments', ['id' => $r['id']])) ?>"><?= h($r['receipt_no']) ?></a></td><td><?= IspUi::acct($r['account_id'], $r['account_no'], $r['account_name']) ?></td>
            <td><?= h(IspPayments::CHANNELS[$r['channel']] ?? $r['channel']) ?></td><td><?= IspUi::status($r['status'], IspPayments::STATUSES) ?></td><td class="num"><?= money($r['amount']) ?></td></tr>
        <?php endforeach; ?></tbody>
        <tfoot><tr><th colspan="4">Posted total</th><th class="num"><?= money(array_sum(array_map(fn($r) => $r['status'] === 'posted' ? (float)$r['amount'] : 0, $mine))) ?></th></tr></tfoot>
      </table></div>
      <?php endif; ?>
    </div></div>
<?php
    layout_end();
    return;
endif;

$open = IspPayments::openInvoices((int)$account['id']);
$bal = IspAccounts::balances((int)$account['id']);
$savOpts = IspAccounts::savingsOptions((int)$account['isp_customer_id']);
$channels = IspPayments::CHANNELS;
if (!$savOpts) {
    unset($channels['auto_debit']);
}
unset($channels['gateway']); // gateway payments come only from the provider's verified webhook
$chosen = (string)fv('channel', null, $shift ? 'cash' : 'gcash');
echo ispbill_account_panel($account);
$arr = DB::row("SELECT arrangement_no, arr_type FROM isp_arrangements WHERE account_id = :a AND status = 'active'", ['a' => $account['id']]);
$disc = DB::row("SELECT queue_no, status FROM isp_disc_queue WHERE account_id = :a AND status IN ('candidate','approved','notified')", ['a' => $account['id']]);
if ($account['service_status'] === 'disconnected'): ?>
<div class="alert alert-info d-flex gap-2"><i class="bi bi-plug"></i><div>The service is disconnected. When this payment settles what the reconnection rules require, the reconnection is requested automatically.</div></div>
<?php elseif ($disc): ?>
<div class="alert alert-warning d-flex gap-2"><i class="bi bi-scissors"></i><div>The account is in the disconnection queue (<?= h($disc['queue_no']) ?>, <?= h(str_replace('_', ' ', $disc['status'])) ?>). Paying the overdue balance removes it at the final check.</div></div>
<?php endif; ?>
<?php if ($arr): ?><div class="alert alert-info py-2">Payment arrangement <?= h($arr['arrangement_no']) ?> is in force: this payment counts toward its schedule.</div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="row g-3" data-once>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="collect">
  <input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>">
  <div class="col-lg-7">
    <div class="card isp-card h-100"><div class="card-body">
      <h2 class="sec-title">Open invoices</h2>
      <?php if (!$open): ?>
        <?= IspUi::empty('No open invoice. A payment now is kept as an advance credit and applied to the next invoice.', 'check2-circle') ?>
      <?php else: ?>
      <p class="small text-muted mb-2">Leave the amounts empty to apply the payment to the oldest invoices first. Whatever is not applied stays as an advance credit.</p>
      <div class="table-responsive"><table class="table table-sm align-middle isp-table">
        <thead><tr><th>Invoice</th><th>Period</th><th>Due</th><th>Status</th><th class="num">Balance</th><th class="num" style="width:9rem">Apply</th></tr></thead>
        <tbody><?php foreach ($open as $inv): ?>
          <tr><td><?= safe_link('ispbill.invoices', ['id' => $inv['id']], (string)$inv['invoice_no']) ?></td>
            <td class="small"><?= $inv['period_start'] ? h(fdate($inv['period_start']) . ' - ' . fdate($inv['period_end'])) : h(ucfirst($inv['kind'])) ?></td>
            <td class="<?= $inv['due_date'] < today() ? 'text-danger' : '' ?>"><?= h(fdate($inv['due_date'])) ?></td>
            <td><?= IspUi::status($inv['status'], IspBilling::STATUSES) ?></td>
            <td class="num"><?= money($inv['balance']) ?></td>
            <td><input class="form-control form-control-sm text-end" name="alloc[<?= (int)$inv['id'] ?>]" inputmode="decimal" value="<?= h((string)($_POST['alloc'][$inv['id']] ?? '')) ?>" placeholder="auto" data-alloc data-balance="<?= h((string)$inv['balance']) ?>"></td></tr>
        <?php endforeach; ?></tbody>
        <tfoot><tr><th colspan="4">Total open</th><th class="num"><?= money($bal['open_invoices']) ?></th><th></th></tr>
          <?php if ($bal['credit'] > 0): ?><tr><td colspan="4" class="small text-muted">Advance credit already on file (applied to the next invoices)</td><td class="num">(<?= money($bal['credit']) ?>)</td><td></td></tr><?php endif; ?></tfoot>
      </table></div>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-lg-5">
    <div class="card isp-card"><div class="card-body row g-2">
      <h2 class="sec-title">Payment</h2>
      <div class="col-6"><label class="form-label">Amount received</label>
        <input class="form-control form-control-lg text-end fw-semibold" name="amount" inputmode="decimal" required autofocus value="<?= h((string)fv('amount', null, $bal['balance'] > 0 ? number_format($bal['balance'], 2, '.', '') : '')) ?>"></div>
      <?php if (Access::can($pk, 'approve')): ?>
      <?= input_field('pay_date', 'Date', fv('pay_date', null, today()), ['type' => 'date', 'class' => 'col-6']) ?>
      <?php else: ?>
      <div class="col-6"><label class="form-label">Date</label><div class="form-control form-control-sm bg-body-tertiary"><?= h(fdate(today())) ?></div><input type="hidden" name="pay_date" value="<?= h(today()) ?>"></div>
      <?php endif; ?>
      <div class="col-12"><label class="form-label">Paid by</label>
        <select class="form-select form-select-sm" name="channel" id="payChannel"><?= options($channels, $chosen) ?></select></div>
      <div class="col-md-6" data-show-for="bank_deposit bank_transfer gcash maya agent other"><label class="form-label">Reference no.</label>
        <input class="form-control form-control-sm" name="reference_no" value="<?= h((string)fv('reference_no')) ?>" maxlength="80" autocomplete="off"></div>
      <div class="col-md-6" data-show-for="check"><label class="form-label">Check no.</label><input class="form-control form-control-sm" name="check_no" value="<?= h((string)fv('check_no')) ?>" maxlength="40"></div>
      <div class="col-md-6" data-show-for="check"><label class="form-label">Bank</label><input class="form-control form-control-sm" name="check_bank" value="<?= h((string)fv('check_bank')) ?>" maxlength="60"></div>
      <?php if ($savOpts): ?>
      <div class="col-12" data-show-for="auto_debit"><label class="form-label">Member's savings account</label>
        <select class="form-select form-select-sm" name="sav_account_id"><?= options($savOpts, fv('sav_account_id', null, $account['autodebit_sav_account_id'])) ?></select></div>
      <?php endif; ?>
      <div class="col-12" data-show-for="bank_deposit bank_transfer gcash maya agent other check"><label class="form-label">Proof of payment (optional)</label>
        <input class="form-control form-control-sm" type="file" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf"></div>
      <div class="col-12" data-show-for="bank_deposit bank_transfer gcash maya agent other check">
        <div class="form-check"><input type="hidden" name="as_pending" value="0"><input class="form-check-input" type="checkbox" name="as_pending" value="1" id="asPending" <?= checked(fv('as_pending') === '1') ?>>
          <label class="form-check-label small" for="asPending">The money is not confirmed yet: record the reference for verification (it does not count until a collection officer verifies it)</label></div></div>
      <div class="col-12"><label class="form-label">Notes</label><input class="form-control form-control-sm" name="notes" value="<?= h((string)fv('notes')) ?>" maxlength="255"></div>
      <div class="col-12 d-grid mt-2"><button class="btn btn-primary"><i class="bi bi-check2-circle"></i> Post payment</button></div>
      <div class="col-12 small text-muted">The receipt number is given when the payment is saved. A posted payment is never edited or deleted: a mistake is reversed by a supervisor, with a reason.</div>
    </div></div>
  </div>
</form>
<script>
(function () {
  var sel = document.getElementById('payChannel');
  function sync() {
    document.querySelectorAll('[data-show-for]').forEach(function (el) {
      var on = el.getAttribute('data-show-for').split(' ').indexOf(sel.value) >= 0;
      el.hidden = !on;
      el.querySelectorAll('input,select').forEach(function (i) { i.disabled = !on; });
    });
  }
  sel.addEventListener('change', sync);
  sync();
})();
</script>
<?php
layout_end();
