# Page conventions (cable / internet pages)

Every page is a plain PHP file under `modules/<module>/`, registered in `app/IspPages.php` (page key => file).
`index.php` has already checked the log-in and the page access before the file runs; `$PAGE` is the page definition.

## Structure of a page file

```php
<?php
declare(strict_types=1);

$pk = 'ispbill.invoices';                       // the page key (for url(), Access::can(), Audit page_key)
handle_post(function () use ($pk) {             // every POST: errors thrown with fail() show on the re-rendered page
    $action = (string)p('action');
    if ($action === 'void') {
        Access::require($pk, 'delete');         // create / edit / delete / print / approve
        IspBilling::void((int)p('id'), trim((string)p('reason')));
        redirect(url($pk, ['id' => (int)p('id')]), 'Invoice voided.', 'success');
    }
});
layout_start('Invoices', ['actions' => page_link($pk, ['a' => 'new'], 'New', 'btn-primary', 'plus-lg', 'create')]);
// ... the list or the record ...
layout_end();
```

* Business rules live in the `app/Isp*.php` classes; pages collect input, call them, and show the result. A page
  never writes ledger rows itself.
* Rights: `Access::require($pk, 'create'|'edit'|'delete'|'print'|'approve')` before every change; special rights with
  `Access::special('isp_disconnect')` etc. Buttons the user may not use are not shown.
* Lists: GET filter bar (`IspUi::filterStart()` ... `IspUi::filterEnd()`), paging (`IspUi::paging()`, `IspUi::pager()`),
  limited to the user's branch with `IspSetup::branchSql()`.
* Statuses are plain text with a signal light: `IspUi::status($value, Class::STATUSES)`. No pills, capsules or badges.
* Account picker: `IspUi::accountField('account_id', $id)` in the form, `IspUi::postedAccount('account_id')` on POST.
  Account links: `IspUi::acct($id, $no, $name)`.
* Money: `money()`; dates: `fdate()`, `fdatetime()`; escaping: `h()` on every value printed.

## Markup

* Page bar: the title given to `layout_start()`; actions in `['actions' => ...]`. No hero banners, no eyebrow labels
  above titles, no "a · b" separators.
* Sections: `<div class="card isp-card"><div class="card-body">` with `<h2 class="sec-title">Title</h2>`.
* Record header: `<div class="isp-head"><div><h2 class="isp-head-title">..</h2><div class="isp-head-sub">..</div></div><div class="isp-head-actions">..</div></div>`.
* Tables: `<div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">`;
  numbers right-aligned with `class="num"`.
* KPI tiles: `<div class="kpi-grid">` + `IspUi::kpi()`.
* Detail pairs: `IspUi::dl(['Label' => html, ...])`.
* Tabs: `IspUi::tabs([...], $current, $pk, $keep)`.
* Forms: `row g-2` with `input_field()`, `select_field()`, `check_field()`; small buttons (`btn-sm`).
* Confirmation: `data-confirm="..."` on a form or button (assets/js/dialog.js).
* Printed documents: `IspUi::letterhead()` inside `<div class="isp-doc">`; screen-only parts carry `no-print`.
