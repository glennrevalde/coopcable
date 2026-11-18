<?php
declare(strict_types=1);

/**
 * The scheduler of the cable / internet jobs (billing, due dates, late charges, reminders, disconnections,
 * reconnections, network retries and reconciliation, notices, backups ...). Run it every 5 minutes; each job decides
 * from its own interval and hour whether it is due (ISP Setup > Scheduled Jobs).
 *
 *   php cron/isp_worker.php                 every due job (with several companies: every active company)
 *   php cron/isp_worker.php --company=2     one company only
 *   php cron/isp_worker.php --job=billing   one job now, due or not (with --company=N when several companies run)
 *   php cron/isp_worker.php --list          the jobs and when they run next
 *
 * Linux crontab:  0-59/5 * * * * /usr/bin/php /var/www/html/gsmart/cron/isp_worker.php >> /var/log/gsmart_isp.log 2>&1
 * Windows / XAMPP Task Scheduler, every 5 minutes:  C:\xampp\php\php.exe C:\xampp\htdocs\gsmart\cron\isp_worker.php
 *
 * Safe to run more than once: a run that overlaps another exits at once (a file lock), each job is claimed under a
 * database lock, and every job is idempotent (no invoice, payment, notice or network command is made twice).
 * With several companies each company runs in its own PHP process, so nothing loaded for one company (accounts,
 * settings, plans) is ever at hand while another company's jobs run. Exit code: 0 all good, 1 a job failed.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

$opts = getopt('', ['company:', 'job:', 'list', 'help']);
if (isset($opts['help'])) {
    echo "php cron/isp_worker.php [--company=N] [--job=CODE] [--list]\n";
    exit(0);
}

function worker_out(string $line): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL;
}

/** An exclusive lock on a file under storage/ (released when the process ends); false when another run holds it */
function worker_lock(string $name)
{
    $dir = APP_ROOT . '/storage/locks';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        Master::protectDir($dir);
    }
    $fh = @fopen($dir . '/' . $name . '.lock', 'c');
    if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
        return false;
    }
    ftruncate($fh, 0);
    fwrite($fh, getmypid() . ' ' . date('c'));
    return $fh;
}

// ---------------------------------------------------------------- several companies: one process each
if (Master::enabled() && !isset($opts['company'])) {
    $lock = worker_lock('isp_worker');
    if ($lock === false) {
        worker_out('Another run of the ISP worker is still going: this one stops.');
        exit(0);
    }
    $ids = array_map('intval', Master::sql()->column("SELECT id FROM companies WHERE status = 'active' ORDER BY id"));
    if (!function_exists('proc_open')) {
        worker_out('proc_open() is switched off: run this script once per company with --company=N (' . implode(', ', $ids) . ').');
        exit(1);
    }
    $failed = false;
    foreach ($ids as $cid) {
        $cmd = [PHP_BINARY, __FILE__, '--company=' . $cid];
        if (isset($opts['job'])) {
            $cmd[] = '--job=' . (string)$opts['job'];
        }
        if (isset($opts['list'])) {
            $cmd[] = '--list';
        }
        $p = proc_open($cmd, [1 => STDOUT, 2 => STDERR], $pipes);
        $code = is_resource($p) ? proc_close($p) : 1;
        if ($code !== 0) {
            $failed = true;
            worker_out('Company ' . $cid . ': finished with errors (exit code ' . $code . ').');
        }
    }
    exit($failed ? 1 : 0);
}

// ---------------------------------------------------------------- one company (or the only database)
$companyId = isset($opts['company']) ? (int)$opts['company'] : null;
$lock = worker_lock('isp_worker' . ($companyId ? '_c' . $companyId : ''));
if ($lock === false) {
    worker_out(($companyId ? 'Company ' . $companyId . ': a' : 'A') . 'nother run is still going: this one stops.');
    exit(0);
}
try {
    $company = Tenant::bootCli($companyId);
} catch (Throwable $e) {
    worker_out('Company ' . $companyId . ': ' . $e->getMessage());
    exit(1);
}
if ($company && $company['status'] !== 'active') {
    worker_out('Company ' . $company['id'] . ' (' . $company['name'] . ') is not active: nothing runs.');
    exit(0);
}
$label = $company ? 'Company ' . $company['id'] . ' ' . $company['name'] : Settings::get('company_name');
if (!IspSetup::ready()) {
    worker_out($label . ': the cable / internet tables are not installed (run the database update first).');
    exit(1);
}
if (Schema::pending()) {
    worker_out($label . ': the database needs an update before the jobs can run (see Schema / database/migrations).');
    exit(1);
}

if (isset($opts['list'])) {
    worker_out($label . ':');
    foreach (DB::all('SELECT * FROM isp_jobs ORDER BY id') as $j) {
        echo sprintf("  %-15s %-8s every %5d min%s  last %-8s %s  next %s\n", $j['job_code'], (int)$j['is_enabled'] ? 'enabled' : 'off', (int)$j['interval_min'],
            $j['run_hour'] !== null ? ' at ' . str_pad((string)$j['run_hour'], 2, '0', STR_PAD_LEFT) . ':00' : '         ', (string)($j['last_status'] ?: '-'),
            (string)($j['last_finished_at'] ?: ''), (string)($j['next_run_at'] ?: 'now'));
    }
    exit(0);
}

$failed = false;
try {
    if (isset($opts['job'])) {
        $code = (string)$opts['job'];
        $runs = [$code => IspJobs::run($code, 'cron')];
    } else {
        $runs = IspJobs::runDue('cron');
    }
} catch (Throwable $e) {
    worker_out($label . ': ' . ($e instanceof UserError ? $e->getMessage() : get_class($e) . ': ' . $e->getMessage()));
    if (!$e instanceof UserError) {
        error_log('ISP worker: ' . $e);
    }
    exit(1);
}
if (!$runs) {
    worker_out($label . ': no job is due.');
}
foreach ($runs as $code => $r) {
    $status = (string)($r['status'] ?? '?');
    if ($status === 'failed') {
        $failed = true;
    }
    worker_out($label . ': ' . $code . ' ' . $status . (isset($r['processed']) ? ' (' . (int)$r['processed'] . ')' : '') . ' - ' . preg_replace('/\s+/', ' ', mb_substr((string)($r['message'] ?? ''), 0, 300)));
}
exit($failed ? 1 : 0);
