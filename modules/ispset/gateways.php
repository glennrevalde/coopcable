<?php
declare(strict_types=1);

/**
 * Payment Channels & Gateways: the ledger account each payment channel's money goes to, and the online payment
 * gateway (PayMongo, Xendit or a generic signed webhook). A payment is posted only from an authenticated webhook of
 * the provider, once per provider event; the page the customer returns to never marks anything paid.
 */
$pk = 'ispset.gateways';
const ISPSET_GW_STATUSES = ['received' => 'Received', 'posted' => 'Posted', 'ignored' => 'Ignored (not a payment)', 'rejected' => 'Rejected (signature failed)', 'failed' => 'Not posted (needs a person)'];
$tabs = ['gateway' => 'Online gateway', 'channels' => 'Payment channel accounts', 'events' => 'Gateway events'];
$tab = isset($tabs[g('tab')]) ? (string)g('tab') : 'gateway';
$depositAccts = Accts::depositTo();

/** The address the provider sends its webhooks to */
function ispset_webhook_url(string $provider): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'your-domain'));
    $url = ($https ? 'https' : 'http') . '://' . $host . base_url() . '/api/gateway.php?provider=' . rawurlencode($provider);
    if (Tenant::isMulti()) {
        $code = (string)(Tenant::company()['code'] ?? '');
        $url .= '&co=' . rawurlencode($code !== '' ? $code : 'COMPANY-CODE');
    }
    return $url;
}

handle_post(function () use ($pk, $depositAccts) {
    $action = (string)p('action');
    if ($action === 'gateway') {
        Access::require($pk, 'edit');
        $set = [
            'isp_gateway_provider' => isset(IspGateway::PROVIDERS[p('isp_gateway_provider')]) ? (string)p('isp_gateway_provider') : 'none',
            'isp_gateway_mode' => p('isp_gateway_mode') === 'live' ? 'live' : 'test',
            'isp_gateway_checkout_url' => trim((string)p('isp_gateway_checkout_url')),
            'isp_gateway_fee_pct' => (string)round(to_num(p('isp_gateway_fee_pct')), 4),
            'isp_gateway_fee_fixed' => (string)r2(to_num(p('isp_gateway_fee_fixed'))),
        ];
        if ((float)$set['isp_gateway_fee_pct'] < 0 || (float)$set['isp_gateway_fee_pct'] > 20 || (float)$set['isp_gateway_fee_fixed'] < 0) {
            fail('The gateway fee is 0 to 20 percent plus a fixed amount of zero or more.');
        }
        if ($set['isp_gateway_checkout_url'] !== '' && !preg_match('#^https://#i', $set['isp_gateway_checkout_url'])) {
            fail('The checkout URL starts with https://.');
        }
        if ($set['isp_gateway_provider'] === 'generic' && $set['isp_gateway_checkout_url'] === '') {
            fail('Enter the checkout URL of the generic gateway (with {account_no}, {amount}, {reference} and {return}).');
        }
        $secrets = [];
        foreach (['isp_gateway_secret_enc' => 'gw_secret', 'isp_gateway_webhook_secret_enc' => 'gw_webhook'] as $key => $field) {
            if (p($field . '_clear') === '1') {
                $secrets[$key] = '';
            } elseif ((string)($_POST[$field] ?? '') !== '') {
                $secrets[$key] = (string)IspCrypto::encrypt(trim((string)$_POST[$field]));
            }
        }
        $willHaveWebhook = array_key_exists('isp_gateway_webhook_secret_enc', $secrets) ? $secrets['isp_gateway_webhook_secret_enc'] !== '' : IspSetup::get('isp_gateway_webhook_secret_enc') !== '';
        if ($set['isp_gateway_provider'] !== 'none' && !$willHaveWebhook) {
            fail('Enter the webhook secret (signing secret / verification token): without it no webhook can be verified, so no online payment would ever post.');
        }
        $before = [];
        $after = [];
        foreach ($set as $k => $v) {
            if (IspSetup::get($k) !== $v) {
                $before[$k] = IspSetup::get($k);
                $after[$k] = $v;
            }
        }
        DB::tx(function () use ($set, $secrets, $before, $after) {
            foreach ($set + $secrets as $k => $v) {
                Settings::set($k, $v);
            }
            $changed = array_keys($after);
            foreach ($secrets as $k => $v) {
                $changed[] = $k . ($v === '' ? ' (cleared)' : ' (new value)');
            }
            if ($changed) {
                Audit::log('edit', 'ispset', 'settings', null, 'Payment gateway', ['before' => $before, 'after' => $after, 'reason' => 'Changed: ' . implode(', ', $changed)]);
            }
        });
        redirect(url($pk), 'Gateway settings saved.', 'success');
    }
    if ($action === 'channels') {
        Access::require($pk, 'edit');
        $before = [];
        $after = [];
        foreach (array_keys(IspPayments::CHANNELS) as $ch) {
            $v = (string)(pint('acct_' . $ch) ?? '');
            if ($v !== '' && !isset($depositAccts[(int)$v])) {
                fail('Choose a bank or current-asset account for ' . IspPayments::CHANNELS[$ch] . '.');
            }
            $old = Settings::get('isp_acct_' . $ch, '');
            if ($old !== $v) {
                $before['isp_acct_' . $ch] = $old;
                $after['isp_acct_' . $ch] = $v;
            }
        }
        DB::tx(function () use ($after, $before) {
            foreach ($after as $k => $v) {
                Settings::set($k, $v);
            }
            if ($after) {
                Audit::log('edit', 'ispset', 'settings', null, 'Payment channel accounts', ['before' => $before, 'after' => $after]);
            }
        });
        redirect(url($pk, ['tab' => 'channels']), $after ? 'Channel accounts saved. They apply to payments recorded from now on.' : 'Nothing was changed.', 'success');
    }
    fail('Unknown action.');
});

layout_start('Payment Channels & Gateways');
echo IspUi::tabs($tabs, $tab, $pk);
$canEdit = Access::can($pk, 'edit');

if ($tab === 'gateway') {
    $v = fn(string $k) => is_post() ? (string)p($k) : IspSetup::get($k);
    $prov = IspSetup::get('isp_gateway_provider');
    $on = IspGateway::enabled();
    ?>
    <div class="row g-3">
      <div class="col-lg-7"><div class="card isp-card"><div class="card-body">
        <h2 class="sec-title">Online payment gateway <?= IspUi::status($on ? 'ok' : 'inactive', ['ok' => 'Offered on the portal (' . (IspSetup::get('isp_gateway_mode') === 'live' ? 'live' : 'test mode') . ')', 'inactive' => 'Not offered']) ?></h2>
        <form method="post" class="row g-2" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="gateway">
          <?= select_field('isp_gateway_provider', 'Provider', IspGateway::PROVIDERS, $v('isp_gateway_provider'), ['class' => 'col-md-6']) ?>
          <?= select_field('isp_gateway_mode', 'Mode', ['test' => 'Test (sandbox keys)', 'live' => 'Live'], $v('isp_gateway_mode'), ['class' => 'col-md-6']) ?>
          <div class="col-md-6"><label class="form-label">API secret key</label><input class="form-control form-control-sm" type="password" name="gw_secret" autocomplete="new-password" placeholder="<?= h(IspCrypto::masked(IspSetup::get('isp_gateway_secret_enc')) ?: 'not set') ?>"<?= $canEdit ? '' : ' disabled' ?>>
            <div class="form-text">Opens the checkout. Stored encrypted; leave empty to keep it.</div></div>
          <div class="col-md-6"><label class="form-label">Webhook secret / verification token</label><input class="form-control form-control-sm" type="password" name="gw_webhook" autocomplete="new-password" placeholder="<?= h(IspCrypto::masked(IspSetup::get('isp_gateway_webhook_secret_enc')) ?: 'not set') ?>"<?= $canEdit ? '' : ' disabled' ?>>
            <div class="form-text">Proves a webhook came from the provider. Stored encrypted.</div></div>
          <?php if (IspSetup::get('isp_gateway_secret_enc') !== ''): ?><?= check_field('gw_secret_clear', 'Remove the saved API key', false, 'col-md-6') ?><?php endif; ?>
          <?php if (IspSetup::get('isp_gateway_webhook_secret_enc') !== ''): ?><?= check_field('gw_webhook_clear', 'Remove the saved webhook secret', false, 'col-md-6') ?><?php endif; ?>
          <?= input_field('isp_gateway_checkout_url', 'Checkout URL template (generic provider)', $v('isp_gateway_checkout_url'), ['class' => 'col-12', 'extra' => 'placeholder="https://pay.example.com/checkout?ref={reference}&amount={amount}&acct={account_no}&return={return}"']) ?>
          <?= input_field('isp_gateway_fee_pct', 'Gateway fee (%)', $v('isp_gateway_fee_pct'), ['type' => 'number', 'step' => '0.0001', 'class' => 'col-md-6']) ?>
          <?= input_field('isp_gateway_fee_fixed', 'Plus a fixed fee per payment', $v('isp_gateway_fee_fixed'), ['type' => 'number', 'class' => 'col-md-6']) ?>
          <div class="col-12 form-text">The fee is posted to Payment Gateway Fees when the provider's event does not state the fee itself; the subscriber is credited the full amount paid.</div>
          <?php if ($canEdit): ?><div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> Save gateway settings</button></div><?php endif; ?>
        </form>
      </div></div></div>
      <div class="col-lg-5"><div class="card isp-card"><div class="card-body">
        <h2 class="sec-title">Webhook address</h2>
        <?php if ($prov === 'none'): ?>
          <p class="small text-muted mb-0">Choose a provider first.</p>
        <?php else: $wh = ispset_webhook_url($prov); ?>
          <p class="small">Register this address at <?= h(IspGateway::PROVIDERS[$prov]) ?> as the webhook for successful payments:</p>
          <div class="input-group input-group-sm mb-2"><input class="form-control" value="<?= h($wh) ?>" readonly aria-label="Webhook URL"><button class="btn btn-outline-secondary" type="button" data-copy="<?= h($wh) ?>"><i class="bi bi-clipboard"></i> Copy</button></div>
          <ul class="small text-muted mb-0 ps-3">
            <li>The server must be reachable from the internet over HTTPS for the provider to call it.</li>
            <?php if ($prov === 'paymongo'): ?><li>Events: <code>checkout_session.payment.paid</code> and <code>payment.paid</code>. The account number travels in the checkout metadata.</li><?php endif; ?>
            <?php if ($prov === 'xendit'): ?><li>Invoice callbacks with status PAID; the external id starts with the account number.</li><?php endif; ?>
            <?php if ($prov === 'generic'): ?><li>JSON <code>{event_id, status: "paid", account_no, amount, fee, reference}</code> signed in <code>X-Signature</code> (HMAC-SHA256 of the body with the webhook secret).</li><?php endif; ?>
            <li>A repeated event is never posted twice; an event whose account is not found is kept for a person to apply.</li>
            <?= Tenant::isMulti() ? '<li>The <code>co</code> part is this company\'s code: it tells the webhook which company the payment belongs to.</li>' : '' ?>
          </ul>
        <?php endif; ?>
      </div></div></div>
    </div>
    <?php
} elseif ($tab === 'channels') {
    ?>
    <div class="card isp-card"><div class="card-body">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="channels">
      <div class="table-responsive"><table class="table table-sm align-middle isp-table">
        <thead><tr><th>Channel</th><th>Money goes to</th><th>Default</th></tr></thead><tbody>
        <?php foreach (IspPayments::CHANNELS as $ch => $label):
            $override = Settings::get('isp_acct_' . $ch, '');
            $def = IspPayments::defaultChannelAccount($ch);
            $defName = (string)DB::value("SELECT CONCAT(code, ' - ', name) FROM accounts WHERE id = :a", ['a' => $def]); ?>
          <tr><td><?= h($label) ?><?= in_array($ch, IspPayments::NEED_REF, true) ? '<div class="small text-muted">reference number required</div>' : '' ?></td>
            <td style="min-width:18rem"><select class="form-select form-select-sm" name="acct_<?= h($ch) ?>"<?= $canEdit ? '' : ' disabled' ?>><?= options($depositAccts, is_post() ? p('acct_' . $ch) : $override, '(the default)') ?></select></td>
            <td class="small text-muted"><?= h($ch === 'cash' ? 'The cash account of the cashier\'s shift, else ' . $defName : $defName) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <p class="small text-muted">Cash goes to the cash account of the cashier's open shift; check, bank and e-wallet payments go to clearing accounts until they are deposited or settled. Change these only when the chart of accounts has other accounts for them.</p>
      <?php if ($canEdit): ?><button class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> Save channel accounts</button><?php endif; ?>
      </form>
    </div></div>
    <?php
} else {
    $prov = isset(IspGateway::PROVIDERS[g('provider')]) ? (string)g('provider') : '';
    $status = isset(ISPSET_GW_STATUSES[g('status')]) ? (string)g('status') : '';
    $q = trim((string)g('q'));
    $p = [];
    $where = ['1 = 1'];
    if ($prov !== '') {
        $where[] = 'e.provider = :pr';
        $p['pr'] = $prov;
    }
    if ($status !== '') {
        $where[] = 'e.status = :st';
        $p['st'] = $status;
    }
    if ($q !== '') {
        $where[] = '(e.account_no LIKE :q1 OR e.reference_no LIKE :q2 OR e.event_id LIKE :q3)';
        $p += ['q1' => "$q%", 'q2' => "%$q%", 'q3' => "%$q%"];
    }
    $sqlFrom = 'FROM isp_gateway_events e LEFT JOIN isp_payments y ON y.id = e.payment_id WHERE ' . implode(' AND ', $where);
    [$per, $off, $page] = IspUi::paging(50);
    $total = (int)DB::value("SELECT COUNT(*) $sqlFrom", $p);
    $rows = DB::all("SELECT e.*, y.receipt_no $sqlFrom ORDER BY e.id DESC LIMIT $per OFFSET $off", $p);
    ?>
    <?= IspUi::filterStart($pk, ['tab' => 'events']) ?>
      <?= IspUi::fSearch($q, 'Account, reference, event id') ?>
      <?= IspUi::fSelect('provider', 'Provider', array_diff_key(IspGateway::PROVIDERS, ['none' => 1]), $prov) ?>
      <?= IspUi::fSelect('status', 'Result', ISPSET_GW_STATUSES, $status) ?>
    <?= IspUi::filterEnd(url($pk, ['tab' => 'events'])) ?>
    <div class="card isp-card"><div class="card-body">
    <?php if (!$rows): ?>
      <?= IspUi::empty('No webhook received yet.', 'broadcast') ?>
    <?php else: ?>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
        <thead><tr><th>Received</th><th>Provider / event</th><th>Signature</th><th>Account</th><th class="num">Amount</th><th class="num">Fee</th><th>Reference</th><th>Result</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td class="small text-nowrap"><?= h(fdatetime($r['created_at'])) ?><div class="text-muted"><?= h((string)$r['remote_ip']) ?></div></td>
            <td class="small"><?= h(IspGateway::PROVIDERS[$r['provider']] ?? $r['provider']) ?><div class="text-muted"><?= h((string)$r['event_type']) ?></div>
              <details><summary class="text-muted">Payload</summary><pre class="small mb-0" style="max-width:32rem;max-height:14rem;overflow:auto;white-space:pre-wrap"><?= h(mb_substr((string)$r['payload'], 0, 4000)) ?></pre></details></td>
            <td><?= IspUi::status((int)$r['signature_ok'] ? 'ok' : 'failed', ['ok' => 'Verified', 'failed' => 'Failed']) ?></td>
            <td><?= h((string)$r['account_no']) ?></td><td class="num"><?= $r['amount'] !== null ? h(money($r['amount'])) : '' ?></td><td class="num"><?= $r['fee'] !== null ? h(money($r['fee'], true)) : '' ?></td>
            <td class="small"><?= h((string)$r['reference_no']) ?></td>
            <td><?= IspUi::status($r['status'], ISPSET_GW_STATUSES) ?><?= $r['payment_id'] ? '<div class="small">' . safe_link('ispbill.payments', ['id' => (int)$r['payment_id']], (string)$r['receipt_no']) . '</div>' : '' ?>
              <?= $r['message'] ? '<div class="small text-muted">' . h($r['message']) . '</div>' : '' ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?= IspUi::pager($total, $per, $page) ?>
      <p class="small text-muted mb-0">An event that was not posted (account not found, not a payment) is applied by a collection officer on Payments &amp; Verification after checking it with the provider.</p>
    <?php endif; ?>
    </div></div>
    <?php
}
layout_end();
