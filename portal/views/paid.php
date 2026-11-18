<?php
declare(strict_types=1);

defined('APP_PORTAL') || exit;   // opened only through portal/index.php

// "I paid": a GCash, Maya or bank payment made outside the office is reported here with its reference number and,
// optionally, a photo of the confirmation. It is recorded as waiting for verification: staff confirm it against the
// provider's records (Billing & Collection > Payments & Verification) before it reduces the balance.
$c = portal_customer();
$a = portal_account();
if (!$a) {
    redirect(IspPortal::url('home'));
}
$proofOn = IspSetup::get('isp_portal_proof_upload') === '1';
portal_post(function () use ($c, $proofOn) {
    $acct = portal_account((int)p('account'));
    $channel = (string)p('channel');
    if (!isset(portal_pay_channels()[$channel])) {
        fail('Choose how you paid.');
    }
    $date = (string)p('pay_date');
    if (!valid_date($date) || $date > today() || $date < date('Y-m-d', strtotime('-60 days'))) {
        fail('Enter the date you paid (within the last 60 days).');
    }
    $file = $proofOn ? portal_upload_check('proof') : null;
    $puid = (int)$c['portal_user']['id'];
    $id = IspPayments::collect((int)$acct['id'], to_num(p('amount')), $channel, ['status' => 'pending', 'source' => 'portal', 'portal_user_id' => $puid,
        'reference_no' => trim((string)p('reference_no')), 'pay_date' => $date, 'notes' => mb_substr(trim('Reported on the portal. ' . trim((string)p('notes'))), 0, 255), 'page_key' => 'portal']);
    if ($file) {
        portal_upload_store($file, 'payment', $id, $puid, 'Proof of payment (portal)');
    }
    redirect(IspPortal::url('receipt', ['id' => $id]), 'Thank you. Your payment details were received and will be applied once verified.', 'success');
});
$amount = is_post() ? (string)p('amount') : (g('amount') !== '' ? number_format(to_num(g('amount')), 2, '.', '') : ((float)$a['balance'] > 0 ? number_format((float)$a['balance'], 2, '.', '') : ''));
portal_start('Report a payment', ['back' => [IspPortal::url('payments', ['account' => (int)$a['id']]), 'Payments'], 'actions' => portal_account_switch($a, 'paid')]);
?>
<div class="pp-grid-2">
  <form class="pp-card pp-reveal" method="post" enctype="multipart/form-data" action="<?= h(IspPortal::url('paid', ['account' => (int)$a['id']])) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="account" value="<?= (int)$a['id'] ?>">
    <div class="row g-3">
      <div class="col-12"><label class="form-label">Paid by</label>
        <div class="pp-choice">
        <?php foreach (portal_pay_channels() as $k => $label): ?>
          <label class="pp-choice-opt"><input type="radio" name="channel" value="<?= h($k) ?>" <?= (p('channel') ?: 'gcash') === $k ? 'checked' : '' ?> required>
            <span><i class="bi bi-<?= ['gcash' => 'phone', 'maya' => 'phone-vibrate', 'bank_transfer' => 'bank', 'bank_deposit' => 'cash-stack'][$k] ?? 'wallet2' ?>"></i><?= h($label) ?></span></label>
        <?php endforeach; ?>
        </div></div>
      <div class="col-sm-6"><label class="form-label" for="amt">Amount paid</label><input class="form-control" id="amt" name="amount" value="<?= h($amount) ?>" inputmode="decimal" required></div>
      <div class="col-sm-6"><label class="form-label" for="dt">Date paid</label><input class="form-control" id="dt" type="date" name="pay_date" value="<?= h((string)(p('pay_date') ?: today())) ?>" max="<?= h(today()) ?>" required></div>
      <div class="col-12"><label class="form-label" for="ref">Reference number</label><input class="form-control" id="ref" name="reference_no" value="<?= h(p('reference_no')) ?>" maxlength="60" required autocomplete="off">
        <div class="form-text">The reference number on your GCash / Maya receipt, or the transaction or deposit slip number of the bank.</div></div>
      <?php if ($proofOn): ?>
      <div class="col-12"><label class="form-label" for="proof">Screenshot or photo of the receipt (optional)</label><input class="form-control" id="proof" type="file" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf">
        <div class="form-text">JPG, PNG, WebP or PDF, up to 5 MB. A screenshot alone does not confirm a payment: we check the reference.</div></div>
      <?php endif; ?>
      <div class="col-12"><label class="form-label" for="nt">Note (optional)</label><input class="form-control" id="nt" name="notes" value="<?= h(p('notes')) ?>" maxlength="150"></div>
      <div class="col-12"><button class="btn btn-primary"><i class="bi bi-send"></i> Send for verification</button></div>
    </div>
  </form>
  <aside class="pp-card pp-reveal">
    <h2 class="pp-sec">Account <?= h($a['account_no']) ?></h2>
    <div class="pp-kv"><span>Amount due</span><strong><?= h(money(max(0, (float)$a['balance']))) ?></strong></div>
    <?php if (($d = portal_next_due((int)$a['id'])) !== null): ?><div class="pp-kv"><span>Due date</span><strong><?= h(fdate($d)) ?></strong></div><?php endif; ?>
    <?php if (($instr = IspSetup::get('isp_payment_instructions')) !== ''): ?><p class="small mt-3 mb-0"><?= nl2br(h($instr)) ?></p><?php endif; ?>
    <?php if (IspGateway::enabled()): ?><a class="btn btn-outline-primary btn-sm mt-3" href="<?= h(IspPortal::url('pay', ['account' => (int)$a['id']])) ?>"><i class="bi bi-credit-card"></i> Or pay online now</a><?php endif; ?>
  </aside>
</div>
<?php
portal_end();
