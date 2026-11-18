<?php
declare(strict_types=1);

defined('APP_PORTAL') || exit;   // opened only through portal/index.php

// The subscriber's own details. The mobile number, e-mail and message preferences are changed here directly (logged);
// the name and billing address are changed by the office on request (they appear on official bills and receipts).
$c = portal_customer();
portal_post(function () use ($c) {
    $mobile = trim((string)p('mobile'));
    $email = trim((string)p('email'));
    $norm = IspNotify::normalizeMobile($mobile);
    if ($mobile !== '' && !preg_match('/^639\d{9}$/', $norm)) {
        fail('Enter a mobile number like 09171234567.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('Enter a valid e-mail address.');
    }
    if ($mobile === '' && $email === '') {
        fail('Keep at least a mobile number or an e-mail address, so we can reach you.');
    }
    $set = ['mobile' => $mobile !== '' ? '0' . substr($norm, 2) : null, 'email' => $email !== '' ? mb_substr($email, 0, 120) : null,
        'notify_sms' => p('notify_sms') === '1' ? 1 : 0, 'notify_email' => p('notify_email') === '1' ? 1 : 0];
    $before = IspAccounts::customer((int)$c['id']);
    $diff = Audit::diff(array_intersect_key($before, $set), $set);
    if (!$diff) {
        redirect(IspPortal::url('profile'), 'Nothing was changed.', 'info');
    }
    DB::update('isp_customers', $set + ['updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $c['id']]);
    foreach (IspPortal::accounts((int)$c['id']) as $acct) {
        IspAccounts::event((int)$acct['id'], 'profile', 'Contact details changed by the subscriber on the portal: ' . implode(', ', array_unique(array_map(fn($d) => str_replace('_', ' ', (string)$d['field']), $diff))));
    }
    Audit::log('edit', 'isp', 'isp_customer', (int)$c['id'], (string)$c['customer_no'], ['user_id' => null, 'username' => 'portal:' . $c['customer_no'], 'roles' => 'Subscriber', 'page_key' => 'portal', 'changes' => $diff]);
    redirect(IspPortal::url('profile'), 'Your details are saved.', 'success');
});
$cust = IspAccounts::customer((int)$c['id']);
$contacts = DB::all('SELECT * FROM isp_contacts WHERE isp_customer_id = :c ORDER BY id', ['c' => $c['id']]);
$pu = $c['portal_user'];
portal_start('Profile', ['actions' => '<a class="btn btn-sm btn-outline-light" href="' . h(IspPortal::url('password')) . '"><i class="bi bi-key"></i> Change password</a>']);
?>
<div class="pp-grid-2">
  <section class="pp-card pp-reveal">
    <h2 class="pp-sec">Subscriber</h2>
    <div class="pp-kv"><span>Name</span><strong><?= h($cust['name']) ?></strong></div>
    <div class="pp-kv"><span>Customer no.</span><strong><?= h($cust['customer_no']) ?></strong></div>
    <div class="pp-kv"><span>Billing address</span><strong><?= h((string)$cust['billing_address']) ?></strong></div>
    <?php if ($cust['tin']): ?><div class="pp-kv"><span>TIN</span><strong><?= h($cust['tin']) ?></strong></div><?php endif; ?>
    <div class="pp-kv"><span>Portal user name</span><strong><?= h($pu['username']) ?></strong></div>
    <?php if ($pu['last_login_at']): ?><div class="pp-kv"><span>Last log-in</span><strong><?= h(fdatetime($pu['last_login_at'])) ?></strong></div><?php endif; ?>
    <p class="small text-muted mt-3 mb-2">To change the name or billing address printed on your bills, send a request; we may ask for a valid ID.</p>
    <a class="btn btn-sm btn-outline-primary" href="<?= h(IspPortal::url('requests', ['new' => 'profile_update'])) ?>">Request a change</a>
    <?php if ($contacts): ?>
    <h3 class="pp-sub mt-4">Authorized contacts</h3>
    <?php foreach ($contacts as $ct): ?><div class="pp-kv"><span><?= h($ct['name']) ?><?= $ct['relationship'] ? ', ' . h($ct['relationship']) : '' ?></span><strong><?= h((string)$ct['mobile']) ?></strong></div><?php endforeach; ?>
    <?php endif; ?>
  </section>
  <form class="pp-card pp-reveal" method="post" action="<?= h(IspPortal::url('profile')) ?>">
    <?= csrf_field() ?>
    <h2 class="pp-sec">How we reach you</h2>
    <div class="mb-3"><label class="form-label" for="mb">Mobile number</label><input class="form-control" id="mb" name="mobile" value="<?= h((string)portal_fv('mobile', $cust)) ?>" inputmode="tel" maxlength="16" placeholder="09171234567"></div>
    <div class="mb-3"><label class="form-label" for="em">E-mail</label><input class="form-control" id="em" type="email" name="email" value="<?= h((string)portal_fv('email', $cust)) ?>" maxlength="120"></div>
    <div class="form-check form-switch mb-2"><input type="hidden" name="notify_sms" value="0"><input class="form-check-input" type="checkbox" role="switch" id="ns" name="notify_sms" value="1" <?= (int)portal_fv('notify_sms', $cust) === 1 ? 'checked' : '' ?>>
      <label class="form-check-label" for="ns">Send me text messages (bills, due dates, outages, service changes)</label></div>
    <div class="form-check form-switch mb-3"><input type="hidden" name="notify_email" value="0"><input class="form-check-input" type="checkbox" role="switch" id="ne" name="notify_email" value="1" <?= (int)portal_fv('notify_email', $cust) === 1 ? 'checked' : '' ?>>
      <label class="form-check-label" for="ne">Send me e-mails</label></div>
    <p class="small text-muted">Notices about your account always appear in your portal notifications. The mobile number is also used to confirm it is you when you reset your password.</p>
    <button class="btn btn-primary">Save</button>
  </form>
</div>
<div class="pp-card pp-reveal small text-muted"><i class="bi bi-shield-lock"></i> <?= h(IspSetup::get('isp_privacy_notice')) ?></div>
<?php
portal_end();
