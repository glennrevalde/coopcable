<?php
declare(strict_types=1);

/**
 * Scheduled Jobs Monitor: the background jobs of the cable / internet operation (billing, due-date checks, late
 * charges, reminders, disconnection evaluation and execution, reconnections, network retries and reconciliation,
 * notifications, contracts, backups ...), their last result, their failures and their history. Every job is safe to
 * run again and never runs twice at the same time.
 */
$pk = 'ispset.jobs';
$jobs = DB::all('SELECT * FROM isp_jobs');
$order = array_keys(IspSetup::JOBS);
$rank = fn(string $c) => ($i = array_search($c, $order, true)) === false ? 99 : $i;
usort($jobs, fn($a, $b) => $rank($a['job_code']) <=> $rank($b['job_code']));

handle_post(function () use ($pk) {
    $action = (string)p('action');
    $code = (string)p('code');
    $j = DB::row('SELECT * FROM isp_jobs WHERE job_code = :c', ['c' => $code]);
    if (!$j && $action !== 'run_due') {
        fail('Job not found.');
    }
    switch ($action) {
        case 'run':
            Access::require($pk, 'create');
            @set_time_limit(600);
            $r = IspJobs::run($code, 'user:' . Auth::id());
            Audit::log('run', 'ispset', 'isp_job', (int)$j['id'], $code, ['reason' => 'Run by hand: ' . $r['status'] . ' - ' . mb_substr((string)($r['message'] ?? ''), 0, 200)]);
            $type = $r['status'] === 'ok' ? 'success' : ($r['status'] === 'skipped' ? 'info' : 'danger');
            redirect(url($pk, ['code' => $code]), $j['name'] . ': ' . ($r['status'] === 'ok' ? 'done' : $r['status']) . '. ' . (string)($r['message'] ?? ''), $type);
        case 'run_due':
            Access::require($pk, 'create');
            @set_time_limit(900);
            $out = IspJobs::runDue('user:' . Auth::id());
            Audit::log('run', 'ispset', 'isp_job', null, 'Due jobs', ['reason' => count($out) . ' job(s) run by hand']);
            $failed = array_keys(array_filter($out, fn($r) => $r['status'] === 'failed'));
            redirect(url($pk), count($out) . ' due job' . (count($out) === 1 ? '' : 's') . ' run' . ($failed ? '; failed: ' . implode(', ', $failed) : '') . '.', $failed ? 'warning' : 'success');
        case 'save':
            Access::require($pk, 'edit');
            $hour = trim((string)p('run_hour'));
            $set = ['is_enabled' => p('is_enabled') === '1' ? 1 : 0, 'interval_min' => (int)p('interval_min'), 'run_hour' => $hour === '' ? null : (int)$hour, 'max_retries' => (int)p('max_retries')];
            if ($set['interval_min'] < 5 || $set['interval_min'] > 10080) {
                fail('The interval is 5 minutes to 7 days (10080 minutes).');
            }
            if ($set['run_hour'] !== null && ($set['run_hour'] < 0 || $set['run_hour'] > 23)) {
                fail('The daily hour is 0 to 23, or empty to run every interval.');
            }
            if ($set['max_retries'] < 0 || $set['max_retries'] > 20) {
                fail('The retries are 0 to 20.');
            }
            DB::update('isp_jobs', $set + ['next_run_at' => $j['last_finished_at'] ? date('Y-m-d H:i:s', strtotime((string)$j['last_finished_at']) + $set['interval_min'] * 60) : null], 'job_code = :c', ['c' => $code]);
            Audit::log('edit', 'ispset', 'isp_job', (int)$j['id'], $code, ['before' => array_intersect_key($j, $set), 'after' => $set]);
            redirect(url($pk, ['code' => $code]), 'Schedule saved.', 'success');
        case 'reset':
            Access::require($pk, 'edit');
            // clears the failure count and a claim left by a process that died (the MySQL lock is released with it)
            if ($j['locked_until'] && strtotime((string)$j['locked_until']) > time() && $j['last_status'] === 'running' && strtotime((string)$j['last_started_at']) > time() - 1800) {
                fail('The job is running now (started ' . IspUi::ago($j['last_started_at']) . '). Wait for it to finish.');
            }
            DB::update('isp_jobs', ['fail_count' => 0, 'lock_token' => null, 'locked_until' => null, 'last_status' => $j['last_status'] === 'running' ? 'failed' : $j['last_status']], 'job_code = :c', ['c' => $code]);
            DB::run("UPDATE isp_job_runs SET status = 'failed', finished_at = :n, message = CONCAT(COALESCE(message, ''), ' (left running, cleared by hand)') WHERE job_code = :c AND status = 'running'",
                ['n' => date('Y-m-d H:i:s'), 'c' => $code]);
            Audit::log('reset', 'ispset', 'isp_job', (int)$j['id'], $code, ['reason' => 'Failure count and claim cleared']);
            redirect(url($pk, ['code' => $code]), 'The job is ready to run again.', 'success');
    }
    fail('Unknown action.');
});

$code = (string)g('code');
$job = null;
foreach ($jobs as $j) {
    if ($j['job_code'] === $code) {
        $job = $j;
    }
}
$canRun = Access::can($pk, 'create');
$canEdit = Access::can($pk, 'edit');
$tone = fn(?string $s) => match ($s) { 'ok' => 'ok', 'failed' => 'bad', 'running' => 'warn', 'skipped' => 'idle', default => 'idle' };
$statusLabels = ['ok' => 'Done', 'failed' => 'Failed', 'running' => 'Running', 'skipped' => 'Skipped'];

// ------------------------------------------------------------------ one job
if ($job) {
    [$per, $off, $page] = IspUi::paging(50);
    $total = (int)DB::value('SELECT COUNT(*) FROM isp_job_runs WHERE job_code = :c', ['c' => $code]);
    $runs = DB::all("SELECT * FROM isp_job_runs WHERE job_code = :c ORDER BY id DESC LIMIT $per OFFSET $off", ['c' => $code]);
    $stats = DB::row("SELECT COUNT(*) AS n, SUM(status = 'failed') AS failed, AVG(TIMESTAMPDIFF(SECOND, started_at, finished_at)) AS secs FROM isp_job_runs WHERE job_code = :c AND started_at >= :d",
        ['c' => $code, 'd' => date('Y-m-d', strtotime('-30 days'))]);
    layout_start($job['name'], ['actions' => btn_link(url($pk), 'All jobs', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <div class="isp-head">
      <div><h2 class="isp-head-title"><?= h($job['job_code']) ?></h2><div class="isp-head-sub"><?= h((string)$job['description']) ?></div></div>
      <div class="isp-head-actions">
        <?php if ($canRun): ?><?= IspUi::actionForm('run', 'Run now', ['code' => $code], 'btn-primary', 'play-fill', 'Run "' . $job['name'] . '" now? It does only what is due and is safe to run again.') ?><?php endif; ?>
        <?php if ($canEdit && ((int)$job['fail_count'] > 0 || $job['locked_until'])): ?><?= IspUi::actionForm('reset', 'Clear failures', ['code' => $code], 'btn-outline-secondary', 'arrow-counterclockwise') ?><?php endif; ?>
      </div>
    </div>
    <div class="row g-3">
      <div class="col-lg-4">
        <div class="card isp-card"><div class="card-body">
          <h2 class="sec-title">Status</h2>
          <?= IspUi::dl(['Last run' => $job['last_started_at'] ? h(fdatetime($job['last_started_at'])) . ' (' . h(IspUi::ago($job['last_started_at'])) . ')' : 'Never',
              'Result' => $job['last_status'] ? IspUi::status($job['last_status'], $statusLabels, $tone($job['last_status'])) : '', 'Message' => h((string)$job['last_message']),
              'Failures in a row' => (int)$job['fail_count'] ? (int)$job['fail_count'] . ' of ' . (int)$job['max_retries'] . ' retries' : '',
              'Next run' => !(int)$job['is_enabled'] ? 'Disabled' : ($job['run_hour'] !== null ? 'Daily at ' . sprintf('%02d:00', (int)$job['run_hour']) : ($job['next_run_at'] ? h(fdatetime($job['next_run_at'])) : 'At the next scheduler turn')),
              'Runs, last 30 days' => (int)$stats['n'] . ((int)$stats['failed'] ? ', ' . (int)$stats['failed'] . ' failed' : ''),
              'Average duration' => $stats['secs'] !== null ? h(round((float)$stats['secs'], 1) . ' s') : '']) ?>
        </div></div>
        <div class="card isp-card mt-3"><div class="card-body">
          <h2 class="sec-title">Schedule</h2>
          <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="code" value="<?= h($code) ?>">
            <?= check_field('is_enabled', 'Enabled', (string)fv('is_enabled', $job) === '1', 'col-12') ?>
            <?= input_field('interval_min', 'Every (minutes)', fv('interval_min', $job), ['type' => 'number', 'step' => '1', 'class' => 'col-6']) ?>
            <?= input_field('run_hour', 'Daily at hour (0-23, empty: every interval)', fv('run_hour', $job), ['type' => 'number', 'step' => '1', 'class' => 'col-6']) ?>
            <?= input_field('max_retries', 'Retries after a failure', fv('max_retries', $job), ['type' => 'number', 'step' => '1', 'class' => 'col-6']) ?>
            <?php if ($canEdit): ?><div class="col-12"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-check-lg"></i> Save schedule</button></div><?php endif; ?>
          </form>
        </div></div>
      </div>
      <div class="col-lg-8"><div class="card isp-card"><div class="card-body">
        <h2 class="sec-title">History</h2>
        <?php if (!$runs): ?><?= IspUi::empty('The job has not run yet.', 'clock-history') ?><?php else: ?>
        <div class="table-responsive"><table class="table table-sm align-middle isp-table"><thead><tr><th>Started</th><th>Duration</th><th>Result</th><th class="num">Processed</th><th>Message</th><th>By</th></tr></thead><tbody>
        <?php foreach ($runs as $r): ?>
          <tr><td class="small text-nowrap"><?= h(fdatetime($r['started_at'])) ?></td><td class="small"><?= $r['finished_at'] ? h((string)max(0, strtotime((string)$r['finished_at']) - strtotime((string)$r['started_at']))) . ' s' : '' ?></td>
            <td><?= IspUi::status($r['status'], $statusLabels, $tone($r['status'])) ?></td><td class="num"><?= (int)$r['processed'] ?></td>
            <td class="small" style="max-width:28rem;white-space:pre-wrap"><?= h(mb_substr((string)$r['message'], 0, 600)) ?></td>
            <td class="small"><?= h(str_starts_with((string)$r['trigger_by'], 'user:') ? 'By hand: ' . (string)DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => (int)substr((string)$r['trigger_by'], 5)]) : (string)$r['trigger_by']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?= IspUi::pager($total, $per, $page) ?>
        <?php endif; ?>
      </div></div></div>
    </div>
    <?php
    layout_end();
    exit;
}

// ------------------------------------------------------------------ every job
$lastAny = DB::value('SELECT MAX(started_at) FROM isp_job_runs WHERE trigger_by = \'cron\'');
$failedN = count(array_filter($jobs, fn($j) => $j['last_status'] === 'failed'));
$cronStale = !$lastAny || strtotime((string)$lastAny) < time() - 3600;
layout_start('Scheduled Jobs Monitor', ['actions' => $canRun ? IspUi::actionForm('run_due', 'Run the due jobs now', [], 'btn-primary', 'play-fill', 'Run every job that is due now?') : '']);
?>
<div class="kpi-grid">
  <?= IspUi::kpi('Scheduler', $cronStale ? 'Not running' : 'Running', $lastAny ? 'last turn ' . IspUi::ago($lastAny) : 'no scheduled run recorded yet', null, 'clock', $cronStale ? 'bad' : 'ok') ?>
  <?= IspUi::kpi('Jobs failing', number_format($failedN), 'retried at the next turn', null, 'exclamation-triangle', $failedN ? 'bad' : 'ok') ?>
  <?= IspUi::kpi('Jobs enabled', count(array_filter($jobs, fn($j) => (int)$j['is_enabled'] === 1)) . ' of ' . count($jobs), '', null, 'toggles', 'info') ?>
</div>
<div class="card isp-card"><div class="card-body">
  <div class="table-responsive"><table class="table table-sm table-hover align-middle isp-table">
    <thead><tr><th>Job</th><th>Runs</th><th>Last run</th><th>Result</th><th>Message</th><th>Next</th></tr></thead><tbody>
    <?php foreach ($jobs as $j): ?>
      <tr<?= (int)$j['is_enabled'] ? '' : ' class="text-muted"' ?>><td><a href="<?= h(url($pk, ['code' => $j['job_code']])) ?>"><?= h($j['name']) ?></a><div class="small text-muted"><?= h((string)$j['description']) ?></div></td>
        <td class="small text-nowrap"><?= !(int)$j['is_enabled'] ? 'Disabled' : ($j['run_hour'] !== null ? 'Daily ' . sprintf('%02d:00', (int)$j['run_hour']) : 'Every ' . ((int)$j['interval_min'] >= 60 && (int)$j['interval_min'] % 60 === 0 ? ((int)$j['interval_min'] / 60) . ' h' : (int)$j['interval_min'] . ' min')) ?></td>
        <td class="small text-nowrap"><?= $j['last_started_at'] ? h(IspUi::ago($j['last_started_at'])) : 'Never' ?></td>
        <td><?= $j['last_status'] ? IspUi::status($j['last_status'], $statusLabels, $tone($j['last_status'])) : '' ?><?= (int)$j['fail_count'] ? '<div class="small text-muted">' . (int)$j['fail_count'] . ' in a row</div>' : '' ?></td>
        <td class="small" style="max-width:22rem"><?= h(mb_substr((string)$j['last_message'], 0, 140)) ?></td>
        <td class="small text-nowrap"><?= (int)$j['is_enabled'] && $j['run_hour'] === null && $j['next_run_at'] ? h(IspUi::ago($j['next_run_at'])) : '' ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
</div></div>
<div class="card isp-card mt-3"><div class="card-body">
  <h2 class="sec-title">Setting up the scheduler</h2>
  <p class="small">One command runs every due job of every active company. Have the server run it every 5 minutes; a turn that overlaps a previous one skips the jobs still running.</p>
  <div class="row g-3">
    <div class="col-lg-6"><div class="small fw-semibold mb-1">Linux (crontab -e)</div>
      <pre class="small border rounded p-2 mb-0" style="white-space:pre-wrap">*/5 * * * * php <?= h(APP_ROOT) ?>/cron/isp_worker.php &gt;&gt; <?= h(APP_ROOT) ?>/storage/isp_worker.log 2&gt;&amp;1</pre></div>
    <div class="col-lg-6"><div class="small fw-semibold mb-1">Windows / XAMPP (Task Scheduler, every 5 minutes)</div>
      <pre class="small border rounded p-2 mb-0" style="white-space:pre-wrap">schtasks /create /tn "GSMaRT Coop Cable jobs" /sc minute /mo 5 /tr "C:\xampp\php\php.exe <?= h(str_replace('/', '\\', APP_ROOT)) ?>\cron\isp_worker.php"</pre></div>
  </div>
  <p class="small text-muted mt-2 mb-0">One company only: add <code>--company=N</code>. Run one job: <code>--job=billing</code>. The jobs only do what is due, so running them again is safe: invoices, collections, notices and network commands are never duplicated.</p>
</div></div>
<?php
layout_end();
