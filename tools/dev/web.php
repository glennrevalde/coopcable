<?php
declare(strict_types=1);

/**
 * Developer smoke-test client: logs in through the real log-in page of a running server (php -S or Apache) and opens
 * pages like a browser does (cookies, CSRF token of the page, redirects), reporting PHP errors and failed requests.
 *
 *   php tools/dev/web.php --base=http://127.0.0.1:8101 --user=admin --pass=Admin@12345 get  "p=isp.dashboard"
 *   php tools/dev/web.php ... post "p=isp.subscribers" "action=save&name=Juan"      (the CSRF token is taken from the page)
 *   php tools/dev/web.php ... crawl                  (every menu page the user may open, and every report)
 *   php tools/dev/web.php ... --out=/tmp/page.html get "p=..."   (keeps the HTML)
 *   --prep   sets the user's password to --pass and clears "must change password" first (needs DB access: DB_NAME...)
 *   --portal the customer portal (portal/index.php) instead of the staff pages: --user is the portal username
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
$opt = [];
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) {
        $opt[$m[1]] = $m[2] ?? '1';
    } else {
        $args[] = $a;
    }
}
$base = rtrim($opt['base'] ?? 'http://127.0.0.1:8101', '/');
$user = $opt['user'] ?? 'admin';
$pass = $opt['pass'] ?? 'Admin@12345';
$portal = isset($opt['portal']);
$jar = sys_get_temp_dir() . '/gsc_jar_' . md5($base . $user . ($portal ? 'p' : 's')) . '.txt';

if (isset($opt['prep'])) {
    require __DIR__ . '/../../app/bootstrap.php';
    if ($portal) {
        DB::run('UPDATE isp_portal_users SET password_hash = :h, must_change_password = 0, failed_attempts = 0, status = :s WHERE username = :u',
            ['h' => password_hash($pass, PASSWORD_DEFAULT), 's' => 'active', 'u' => $user]);
    } else {
        DB::run("UPDATE users SET password_hash = :h, must_change_password = 0, failed_attempts = 0, password_changed_at = NOW(), status = 'active' WHERE username = :u",
            ['h' => password_hash($pass, PASSWORD_DEFAULT), 'u' => $user]);
    }
    @unlink($jar);
}

function req(string $url, ?array $post = null): array
{
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 6, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 60, CURLOPT_HEADER => false]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, $body, $final, $err];
}

function problems(string $body): array
{
    $out = [];
    // PHP's own messages (as PHP prints them, not words that happen to be in the page), uncaught exceptions, SQL errors
    $patterns = ['/(?:^|\n|<br \/>\s*)(?:<b>)?(?:Fatal error|Warning|Notice|Deprecated|Parse error)(?:<\/b>)?:\s/', '/Uncaught [A-Z]\w+/', '/Something went wrong/',
        '/\bTypeError\b/', '/SQLSTATE\[/', '/Stack trace:/'];
    foreach ($patterns as $re) {
        if (preg_match($re, $body, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1];
            $out[] = trim(preg_replace('/\s+/', ' ', strip_tags(substr($body, max(0, $pos - 80), 600))));
        }
    }
    return $out;
}

function csrf(string $body): string
{
    return preg_match('/name="_csrf" value="([0-9a-f]+)"/', $body, $m) ? $m[1] : '';
}

function login(): void
{
    global $base, $user, $pass, $portal;
    $entry = $portal ? $base . '/portal/index.php?v=login' : $base . '/login.php';
    [$c, $b, $f] = req($entry);
    if ($portal ? str_contains($f, 'v=home') : str_contains($f, 'index.php')) {
        return;
    }
    [$c, $b, $f] = req($entry, ['_csrf' => csrf($b), 'username' => $user, 'password' => $pass]);
    if ($portal ? !str_contains($f, 'portal/index.php') || str_contains($b, 'name="password"') : !str_contains($f, 'index.php') || str_contains($f, 'change_password')) {
        fwrite(STDERR, "Log-in failed for $user ($c $f)\n" . substr(strip_tags($b), 0, 400) . "\n");
        exit(2);
    }
}

function show(string $label, array $r): bool
{
    global $opt;
    [$code, $body, $final, $err] = $r;
    $probs = problems($body);
    $ok = $err === '' && $code < 400 && !$probs;
    $title = preg_match('/<title>(.*?)<\/title>/s', $body, $m) ? html_entity_decode(trim($m[1])) : '';
    $alerts = [];
    // staff alerts (alert alert-*) and portal alerts (pp-alert pp-alert-*)
    if (preg_match_all('/class="(?:alert alert|pp-alert pp-alert)-(danger|warning|success|info|ok|warn|bad)[^"]*"[^>]*>(.*?)<\/div>/s', $body, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $x) {
            $alerts[] = $x[1] . ': ' . trim(preg_replace('/\s+/', ' ', strip_tags($x[2])));
        }
    }
    echo ($ok ? 'OK  ' : 'ERR ') . $code . ' ' . $label . ' -> ' . preg_replace('#^https?://[^/]+#', '', $final) . ($title ? '  [' . $title . ']' : '') . PHP_EOL;
    foreach ($alerts as $a) {
        echo '     ' . mb_substr($a, 0, 300) . PHP_EOL;
    }
    foreach ($probs as $p) {
        echo '     !! ' . $p . PHP_EOL;
    }
    if ($err) {
        echo '     !! ' . $err . PHP_EOL;
    }
    if (!empty($opt['out'])) {
        file_put_contents($opt['out'], $body);
    }
    return $ok;
}

$cmd = $args[0] ?? 'get';
login();
$entry = $portal ? $base . '/portal/index.php?' : $base . '/index.php?';
if ($cmd === 'get') {
    exit(show($args[1] ?? '', req($entry . ($args[1] ?? ''))) ? 0 : 1);
}
if ($cmd === 'post') {
    [$c, $b] = req($entry . ($args[1] ?? ''));
    parse_str($args[2] ?? '', $data);
    $data['_csrf'] = csrf($b);
    exit(show('POST ' . ($args[1] ?? ''), req($entry . ($args[1] ?? ''), $data)) ? 0 : 1);
}
if ($cmd === 'crawl') {
    // the menu and the report links of the dashboard pages
    [$c, $b] = req($entry . 'p=home.dashboard');
    preg_match_all('/href="[^"]*index\.php\?p=([a-z_]+\.[a-z0-9_]+)"/', $b, $m);
    $keys = array_values(array_unique($m[1]));
    $only = $opt['only'] ?? '';
    $bad = 0;
    foreach ($keys as $k) {
        if ($only !== '' && !preg_match('/^(' . str_replace(',', '|', preg_quote($only, '/')) . ')/', $k)) {
            continue;
        }
        if (in_array($k, ['auth.logout'], true)) {
            continue;
        }
        $bad += show($k, req($entry . 'p=' . $k)) ? 0 : 1;
    }
    echo count($keys) . " pages, $bad with problems\n";
    exit($bad ? 1 : 0);
}
fwrite(STDERR, "Unknown command $cmd\n");
exit(2);
