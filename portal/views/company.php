<?php
declare(strict_types=1);

defined('APP_PORTAL') || exit;   // opened only through portal/index.php

// Several companies run on this system: the subscriber names theirs with its company code (printed on the bills and
// given out by the office), or arrives with ?co=<code> in the link. Only active companies are found.
if (!Master::enabled()) {
    redirect(IspPortal::url('login'));
}
if (isset($_GET['change'])) {
    unset($_SESSION['portal_company'], $_SESSION['portal']);
    redirect(IspPortal::url('company'));
}
portal_post(function () {
    $c = IspPortal::companyByCode((string)p('code'));
    if (!$c) {
        fail('No active company has that code. Check the code on your bill or ask the office.');
    }
    session_regenerate_id(true);
    unset($_SESSION['portal']);
    $_SESSION['portal_company'] = (int)$c['id'];
    redirect(IspPortal::url('login'));
});
if (IspPortal::companyChosen()) {
    redirect(IspPortal::url('login'));
}
portal_start('Find your provider', ['bare' => true]);
?>
<div class="pp-auth">
  <form class="pp-card pp-auth-card" method="post" action="<?= h(IspPortal::url('company')) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <p class="text-muted">Enter the company code printed on your bill or given by your cable and internet provider.</p>
    <label class="form-label" for="code">Company code</label>
    <input class="form-control form-control-lg pp-code" id="code" name="code" value="<?= h(p('code')) ?>" maxlength="20" required autofocus autocapitalize="characters">
    <button class="btn btn-primary w-100 mt-3">Continue</button>
  </form>
</div>
<?php
portal_end();
