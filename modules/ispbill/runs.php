<?php
declare(strict_types=1);

/**
 * Billing Runs: the monthly bill generation. Preview first (a validation report, nothing saved), generate the draft
 * invoices, approve, post (the official invoice numbers and the books). Running it again never bills a period twice.
 */
require_once __DIR__ . '/_lib.php';

$pk = 'ispbill.runs';
$id = gint('id');
const ISPBILL_RUN_STATUSES = ['draft' => 'Draft', 'approved' => 'Approved', 'posted' => 'Posted', 'cancelled' => 'Cancelled'];

handle_post(function () use ($pk) {
    $action = (string)p('action');
    $rid = (int)p('id');
    if ($action === 'generate') {
        Access::require($pk, 'create');
        $date = pdate('bill_date') ?? fail('Enter the billing date.');
        $cycle = pint('cycle_day') ?: null;
        $branch = IspSetup::userBranch() ?? (pint('branch_id') ?: null);
        $rid = IspBilling::generate($date, $cycle, $branch, 'manual');
        $r = DB::row('SELECT * FROM isp_billing_runs WHERE id = :i', ['i' => $rid]);
        redirect(url($pk, ['id' => $rid]), 'Billing run ' . $r['run_no'] . ': ' . (int)$r['invoice_count'] . ' invoice(s), ' . money($r['total_amount'])
            . ($r['status'] === 'posted' ? ', posted.' : ', waiting for approval.'), (int)$r['invoice_count'] ? 'success' : 'warning');
    }
    if ($action === 'approve') {
        Access::require($pk, 'approve');
        IspBilling::approveRun($rid);
        redirect(url($pk, ['id' => $rid]), 'Run approved. Post it to issue the invoice numbers and record them in the books.', 'success');
    }
    if ($action === 'post') {
        Access::require($pk, 'approve');
        $res = IspBilling::postRun($rid);
        redirect(url($pk, ['id' => $rid]), $res['posted'] . ' invoice(s) posted' . ($res['errors'] ? '; ' . count($res['errors']) . ' could not be posted (see the warnings).' : '.'), $res['errors'] ? 'warning' : 'success');
    }
    if ($action === 'cancel') {
        Access::require($pk, 'delete');
        if (trim((string)p('reason')) === '') {
            fail('Enter the reason for cancelling the run.');
        }
        IspBilling::cancelRun($rid, trim((string)p('reason')));
        redirect(url($pk, ['id' => $rid]), 'Run cancelled. No invoice number was used; the pending charges are free again.', 'success');
    }
    if ($action === 'overdue') {
        Access::require($pk, 'approve');
        $n = IspBilling::markOverdue();
        redirect(url($pk), $n . ' invoice(s) checked against their due dates.', 'success');
    }
    if ($action === 'late_fees') {
        Access::require($pk, 'approve');
        IspBilling::markOverdue();
        $n = IspBilling::assessLateFees();
        redirect(url($pk), $n . ' late payment charge(s) added; they are billed on the next invoice of each account.', 'success');
    }
});

// ================================================================ one run
if ($id) {
    $r = DB::row('SELECT r.*, u.full_name AS creator, ua.full_name AS approver, up.full_name AS poster FROM isp_billing_runs r LEFT JOIN users u ON u.id = r.created_by
                    LEFT JOIN users ua ON ua.id = r.approved_by LEFT JOIN users up ON up.id = r.posted_by WHERE r.id = :id', ['id' => $id]) ?? fail('Billing run not found.');
    $invs = DB::all('SELECT i.*, a.account_no, a.account_name FROM isp_invoices i JOIN isp_accounts a ON a.id = i.account_id WHERE i.run_id = :r ORDER BY a.account_no', ['r' => $id]);
    $sum = ['subtotal' => 0.0, 'vat' => 0.0, 'total' => 0.0, 'balance' => 0.0];
    foreach ($invs as $i) {
        $sum['subtotal'] += (float)$i['subtotal'];
        $sum['vat'] += (float)$i['vat_total'];
        $sum['total'] += (float)$i['total'];
        $sum['balance'] += in_array($i['status'], IspBilling::OPEN, true) ? (float)$i['balance'] : 0;
    }
    $canPost = Access::can($pk, 'approve') && Access::special('isp_post_billing');
    $actions = '';
    if ($r['status'] === 'draft' && $canPost && $invs) {
        $actions .= IspUi::actionForm('approve', 'Approve', ['id' => $id], 'btn-success', 'check2', 'Approve billing run ' . $r['run_no'] . ' (' . count($invs) . ' invoices, ' . money($sum['total']) . ')?') . ' ';
    }
    if ($r['status'] === 'approved' && $canPost) {
        $actions .= IspUi::actionForm('post', 'Post invoices', ['id' => $id], 'btn-primary', 'send-check', 'Post the run? Each invoice gets its official number and is recorded in the books; it cannot be deleted afterwards.') . ' ';
    }
    if (in_array($r['status'], ['posted', 'approved'], true) && $invs && Access::can('ispbill.invoices', 'print')) {
        $actions .= btn_link(url('ispbill.invoices', ['a' => 'print', 'run_id' => $id]), 'Print all invoices', 'btn-outline-secondary', 'printer') . ' ';
    }
    if (in_array($r['status'], ['draft', 'approved'], true) && Access::can($pk, 'delete')) {
        $actions .= '<button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelRun"><i class="bi bi-x-circle"></i> Cancel run</button>';
    }
    layout_start('Billing run ' . $r['run_no'], ['actions' => $actions]);
    ?>
    <div class="card isp-card mb-3"><div class="card-body">
      <div class="isp-head"><div><h2 class="isp-head-title">Bill date <?= h(fdate($r['bill_date'])) ?></h2>
        <div class="isp-head-sub"><?= IspUi::status($r['status'], ISPBILL_RUN_STATUSES) ?> <span class="ms-2"><?= $r['cycle_day'] ? 'Cycle day ' . (int)$r['cycle_day'] : 'Every cycle day' ?><?= $r['branch_id'] ? ', ' . h((string)(IspSetup::branches()[$r['branch_id']] ?? 'branch ' . $r['branch_id'])) : '' ?></span></div></div>
        <div class="isp-head-actions"><?= btn_link(url($pk), 'All runs', 'btn-outline-secondary', 'list-ul') ?></div></div>
      <div class="kpi-grid mt-2">
        <?= IspUi::kpi('Invoices', number_format(count($invs)), 'in this run', null, 'receipt', 'info') ?>
        <?= IspUi::kpi('Net of VAT', money($sum['subtotal']), 'subscription and charges', null, 'calculator', 'idle') ?>
        <?= IspUi::kpi('VAT', money($sum['vat']), IspSetup::vatRegistered() ? IspSetup::get('isp_vat_rate') . '% output VAT' : 'non-VAT registered', null, 'percent', 'idle') ?>
        <?= IspUi::kpi('Total billed', money($sum['total']), $r['status'] === 'posted' ? money($sum['balance']) . ' still open' : 'not yet in the books', null, 'cash-stack', $r['status'] === 'posted' ? 'ok' : 'warn') ?>
      </div>
      <?= IspUi::dl(['Source' => h(ucfirst((string)$r['source'])), 'Generated' => h(trim(($r['creator'] ?? '') . ', ' . fdatetime($r['created_at']), ', ')),
          'Approved' => $r['approved_at'] ? h($r['approver'] . ', ' . fdatetime($r['approved_at'])) : '', 'Posted' => $r['posted_at'] ? h($r['poster'] . ', ' . fdatetime($r['posted_at'])) : '']) ?>
      <?php if (trim((string)$r['warnings']) !== ''): ?>
      <details class="mt-2" <?= substr_count((string)$r['warnings'], "\n") < 8 ? 'open' : '' ?>><summary class="small fw-semibold"><i class="bi bi-exclamation-triangle text-warning"></i> Warnings and messages (<?= count(array_filter(explode("\n", (string)$r['warnings']))) ?>)</summary>
        <ul class="small mb-0 mt-1"><?php foreach (array_filter(explode("\n", (string)$r['warnings'])) as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul></details>
      <?php endif; ?>
    </div></div>
    <div class="card isp-card"><div class="card-body">
      <h2 class="sec-title">Invoices</h2>
      <?php if (!$invs): echo IspUi::empty('No invoice in this run.', 'receipt'); else: ?>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
        <thead><tr><th>Invoice</th><th>Account</th><th>Period</th><th>Due</th><th>Status</th><th class="num">Net</th><th class="num">VAT</th><th class="num">Total</th><th class="num">Open</th></tr></thead>
        <tbody><?php foreach ($invs as $i): ?>
          <tr><td><?= safe_link('ispbill.invoices', ['id' => $i['id']], (string)$i['invoice_no']) ?></td><td><?= IspUi::acct($i['account_id'], $i['account_no'], $i['account_name']) ?></td>
            <td class="small"><?= $i['period_start'] ? h(fdate($i['period_start']) . ' - ' . fdate($i['period_end'])) : '' ?></td><td><?= h(fdate($i['due_date'])) ?></td>
            <td><?= IspUi::status($i['status'], IspBilling::STATUSES) ?></td><td class="num"><?= money($i['subtotal']) ?></td><td class="num"><?= money($i['vat_total']) ?></td>
            <td class="num"><?= money($i['total']) ?></td><td class="num"><?= in_array($i['status'], IspBilling::OPEN, true) ? money($i['balance']) : '' ?></td></tr>
        <?php endforeach; ?></tbody>
        <tfoot><tr><th colspan="5">Total</th><th class="num"><?= money($sum['subtotal']) ?></th><th class="num"><?= money($sum['vat']) ?></th><th class="num"><?= money($sum['total']) ?></th><th class="num"><?= money($sum['balance']) ?></th></tr></tfoot>
      </table></div>
      <?php endif; ?>
    </div></div>
    <?php if (in_array($r['status'], ['draft', 'approved'], true) && Access::can($pk, 'delete')): ?>
    <div class="modal fade" id="cancelRun" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$id ?>">
      <div class="modal-header"><h5 class="modal-title">Cancel billing run <?= h($r['run_no']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><p class="small">The draft invoices are removed (no official number was used yet) and their pending charges are free for the next run.</p>
        <label class="form-label">Reason</label><textarea class="form-control" name="reason" rows="2" required maxlength="240"></textarea></div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep the run</button><button class="btn btn-danger">Cancel run</button></div>
    </form></div></div>
    <?php endif; ?>
    <?php
    layout_end();
    return;
}

// ================================================================ preview, generate, the list
$billDate = gdate('bill_date', today()) ?? today();
$cycle = gint('cycle_day');
$branch = IspSetup::userBranch() ?? gint('branch_id');
$doPreview = g('preview') === '1';
$cycles = [];
for ($d = 1; $d <= 28; $d++) {
    $cycles[$d] = 'Day ' . $d;
}
$actions = '';
if (Access::can($pk, 'approve')) {
    $actions .= IspUi::actionForm('overdue', 'Mark overdue', [], 'btn-outline-secondary', 'alarm', 'Mark every unpaid invoice past its due date overdue?') . ' '
        . IspUi::actionForm('late_fees', 'Assess late charges', [], 'btn-outline-secondary', 'hourglass-bottom', 'Add the late payment charge of each overdue invoice under its plan\'s policy (once per invoice)?');
}
layout_start('Billing Runs', ['actions' => $actions]);
$waiting = DB::all("SELECT * FROM isp_billing_runs WHERE status IN ('draft', 'approved') ORDER BY id");
foreach ($waiting as $w): ?>
<div class="alert alert-info d-flex gap-2 align-items-center"><i class="bi bi-hourglass-split"></i>
  <div>Run <a href="<?= h(url($pk, ['id' => $w['id']])) ?>"><?= h($w['run_no']) ?></a> of <?= h(fdate($w['bill_date'])) ?> is <?= h(strtolower(ISPBILL_RUN_STATUSES[$w['status']])) ?>: <?= (int)$w['invoice_count'] ?> invoice(s), <?= money($w['total_amount']) ?><?= $w['status'] === 'draft' ? ', waiting for approval' : ', waiting to be posted' ?>.</div></div>
<?php endforeach; ?>

<div class="card isp-card mb-3"><div class="card-body">
  <h2 class="sec-title">New billing run</h2>
  <form method="get" action="<?= h(base_url() . '/index.php') ?>" class="row g-2 align-items-end">
    <input type="hidden" name="p" value="<?= h($pk) ?>"><input type="hidden" name="preview" value="1">
    <?= input_field('bill_date', 'Billing date', $billDate, ['type' => 'date', 'class' => 'col-sm-6 col-md-3', 'required' => true]) ?>
    <?= select_field('cycle_day', 'Billing cycle', $cycles, $cycle, ['class' => 'col-sm-6 col-md-3', 'blank' => 'Every cycle day due']) ?>
    <?php if (IspSetup::userBranch() === null && IspSetup::branches()): ?>
    <?= select_field('branch_id', 'Branch', IspSetup::branches(), $branch, ['class' => 'col-sm-6 col-md-3', 'blank' => 'All branches']) ?>
    <?php endif; ?>
    <div class="col-sm-6 col-md-3 d-grid"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> Preview</button></div>
  </form>
  <p class="small text-muted mt-2 mb-0">The preview lists every active account due on the date with its lines and warnings, and saves nothing. Accounts already invoiced for the period are shown and skipped.
    <?= IspSetup::get('isp_runs_need_approval') === '1' ? 'Generated invoices are drafts until the run is approved and posted.' : 'Generated invoices post at once (ISP Setup: runs do not need approval).' ?></p>
</div></div>

<?php if ($doPreview):
    $rows = IspBilling::preview($billDate, $cycle, $branch);
    $new = array_filter($rows, fn($r) => !$r['duplicate'] && $r['calc']['lines']);
    $dups = array_filter($rows, fn($r) => $r['duplicate']);
    $total = array_sum(array_map(fn($r) => $r['calc']['total'], $new));
    $warn = array_sum(array_map(fn($r) => count(array_diff($r['calc']['warnings'], ['Nothing to bill.'])), $new)); ?>
<div class="card isp-card mb-3"><div class="card-body">
  <div class="isp-head"><div><h2 class="isp-head-title">Preview for <?= h(fdate($billDate)) ?></h2>
    <div class="isp-head-sub"><?= count($new) ?> to bill, <?= money($total) ?><?= $dups ? ', ' . count($dups) . ' already invoiced (skipped)' : '' ?><?= $warn ? ', ' . $warn . ' warning(s)' : '' ?></div></div>
    <div class="isp-head-actions">
      <?php if ($new && Access::can($pk, 'create')): ?>
      <form method="post" class="d-inline" data-confirm="Generate <?= count($new) ?> invoice(s) for <?= h(money($total)) ?>?">
        <?= csrf_field() ?><input type="hidden" name="action" value="generate"><input type="hidden" name="bill_date" value="<?= h($billDate) ?>">
        <input type="hidden" name="cycle_day" value="<?= h((string)$cycle) ?>"><input type="hidden" name="branch_id" value="<?= h((string)$branch) ?>">
        <button class="btn btn-sm btn-primary"><i class="bi bi-lightning-charge"></i> Generate invoices</button></form>
      <?php endif; ?></div></div>
  <?php if (!$rows): echo IspUi::empty('No active account is due for billing on this date.', 'calendar-check'); else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
    <thead><tr><th>Account</th><th>Plan</th><th>Period</th><th>Lines</th><th class="num">Net</th><th class="num">VAT</th><th class="num">Total</th><th>Checks</th></tr></thead>
    <tbody><?php foreach (array_slice($rows, 0, 1000) as $r): $a = $r['account']; $c = $r['calc']; ?>
      <tr class="<?= $r['duplicate'] ? 'text-muted' : '' ?>"><td><?= IspUi::acct($a['id'], $a['account_no'], $a['account_name']) ?></td><td class="small"><?= h((string)(IspPlans::get((int)$a['plan_id'])['name'] ?? '')) ?></td>
        <td class="small text-nowrap"><?= h(fdate($r['period_start']) . ' - ' . fdate($r['period_end'])) ?></td>
        <td class="small"><?= implode('<br>', array_map(fn($l) => h($l['description']) . ' <span class="text-muted">' . money($l['amount'] + $l['vat_amount']) . '</span>', $c['lines'])) ?></td>
        <td class="num"><?= money($c['subtotal']) ?></td><td class="num"><?= money($c['vat']) ?></td><td class="num fw-semibold"><?= money($c['total']) ?></td>
        <td class="small"><?php if ($r['duplicate']): ?><?= IspUi::status('skipped', ['skipped' => 'Already invoiced: ' . $r['duplicate']]) ?><?php else: ?>
          <?php foreach (array_diff($c['warnings'], ['Nothing to bill.']) as $w): ?><div class="text-warning-emphasis"><i class="bi bi-exclamation-triangle"></i> <?= h($w) ?></div><?php endforeach; ?>
          <?= !$c['lines'] ? '<span class="text-muted">Nothing to bill</span>' : '' ?><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody>
    <tfoot><tr><th colspan="6">To bill</th><th class="num"><?= money($total) ?></th><th></th></tr></tfoot>
  </table></div>
  <?php if (count($rows) > 1000): ?><p class="small text-muted">The first 1,000 accounts are shown; the run bills all <?= count($rows) ?>.</p><?php endif; ?>
  <?php endif; ?>
</div></div>
<?php endif;

$st = (string)g('status');
$p = [];
$where = '1 = 1';
if ($st !== '' && isset(ISPBILL_RUN_STATUSES[$st])) {
    $where .= ' AND r.status = :s';
    $p['s'] = $st;
}
[$per, $off, $page] = IspUi::paging(30);
$total = (int)DB::value("SELECT COUNT(*) FROM isp_billing_runs r WHERE $where", $p);
$runs = DB::all("SELECT r.*, u.full_name AS creator FROM isp_billing_runs r LEFT JOIN users u ON u.id = r.created_by WHERE $where ORDER BY r.id DESC LIMIT $per OFFSET $off", $p);
?>
<div class="card isp-card"><div class="card-body">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><h2 class="sec-title mb-0">Runs</h2>
    <?= IspUi::filterStart($pk) . IspUi::fSelect('status', 'Status', ISPBILL_RUN_STATUSES, $st) . IspUi::filterEnd() ?></div>
  <?php if (!$runs): echo IspUi::empty('No billing run yet.', 'calendar3'); else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
    <thead><tr><th>Run</th><th>Bill date</th><th>Cycle</th><th>Source</th><th>Status</th><th class="num">Invoices</th><th class="num">Total</th><th>Generated by</th></tr></thead>
    <tbody><?php foreach ($runs as $r): ?>
      <tr><td><a href="<?= h(url($pk, ['id' => $r['id']])) ?>"><?= h($r['run_no']) ?></a></td><td><?= h(fdate($r['bill_date'])) ?></td><td><?= $r['cycle_day'] ? 'Day ' . (int)$r['cycle_day'] : 'All' ?></td>
        <td><?= h(ucfirst((string)$r['source'])) ?></td><td><?= IspUi::status($r['status'], ISPBILL_RUN_STATUSES) ?><?= trim((string)$r['warnings']) !== '' ? ' <i class="bi bi-exclamation-triangle text-warning" title="Has warnings"></i>' : '' ?></td>
        <td class="num"><?= (int)$r['invoice_count'] ?></td><td class="num"><?= money($r['total_amount']) ?></td><td class="small"><?= h((string)$r['creator']) ?>, <?= h(fdatetime($r['created_at'])) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?= IspUi::pager($total, $per, $page) ?>
  <?php endif; ?>
</div></div>
<?php
layout_end();
