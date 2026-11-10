<?php
declare(strict_types=1);
/**
 * Developer error log.
 *
 * Every PHP warning, uncaught exception, fatal error, failed cron job, failing integration and slow page
 * is captured here, grouped by "fingerprint" (same problem = one row with a count), tagged with the
 * workspace, user and request it happened in, and shown on System health (platform admins only).
 * New, returning and fast-growing problems alert the developer team by email and/or chat webhooks.
 *
 * Capturing never throws: if the database itself is down, the error goes to storage/errors-fallback.log
 * and (critical only) straight to the optional config 'alert_webhook'.
 */

const ERROR_LEVELS = ['notice' => 1, 'warning' => 2, 'error' => 3, 'critical' => 4];
const ERROR_SOURCES = ['web' => 'Page', 'cron' => 'Cron', 'job' => 'Pipeline job', 'integration' => 'Integration', 'php' => 'PHP', 'slow' => 'Slow', 'system' => 'System'];
const SLOW_PAGE_MS = 3000;      // pages slower than this are logged
const SLOW_QUERY_MS = 1000;     // single SQL statements slower than this are logged
const ALERT_COUNT_STEPS = [10, 100, 1000, 10000];
const ALERTS_PER_HOUR = 30;     // hard cap so an outage can't flood anyone's inbox

function request_id(): string {
    static $id = null;
    return $id ??= strtoupper(bin2hex(random_bytes(4)));
}
define('REQUEST_STARTED', $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

/** Strip the parts of a message that change every time (ids, numbers, emails, quoted values) so repeats group together. */
function error_normalize(string $msg): string {
    $m = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '<email>', $msg) ?? $msg;
    $m = preg_replace('#https?://([^/\s"\']+)[^\s"\']*#i', '<url:$1>', $m) ?? $m;
    $m = preg_replace('/\b[0-9a-f]{12,}\b/i', '<hex>', $m) ?? $m;
    $m = preg_replace('/(["\'])(?:(?!\1).){1,120}\1/', '<str>', $m) ?? $m;
    $m = preg_replace('/\d+(\.\d+)?/', 'N', $m) ?? $m;
    return mb_substr(trim($m), 0, 300);
}

function error_short_path(string $file): string {
    return ltrim(str_replace(APP_ROOT, '', $file), '/');
}

/**
 * Record a problem. $what is a Throwable or a message.
 * $o: level, source, title (optional), context (array), fingerprint (optional override), file, line.
 */
function report_error($what, array $o = []): void {
    static $busy = false;
    if ($busy) return;                // an error while reporting an error: give up quietly
    $busy = true;
    try {
        $level = $o['level'] ?? 'error';
        $source = $o['source'] ?? (PHP_SAPI === 'cli' ? 'cron' : 'web');
        if ($what instanceof Throwable) {
            $class = get_class($what);
            $message = $what->getMessage();
            $file = error_short_path($what->getFile());
            $line = $what->getLine();
            $trace = error_short_path($what->getTraceAsString());
            if ($what->getPrevious()) $trace .= "\nCaused by: " . get_class($what->getPrevious()) . ': ' . $what->getPrevious()->getMessage();
        } else {
            $class = $o['class'] ?? '';
            $message = (string) $what;
            $file = isset($o['file']) ? error_short_path((string) $o['file']) : '';
            $line = (int) ($o['line'] ?? 0);
            $trace = $o['trace'] ?? '';
        }
        $title = mb_substr($o['title'] ?? (($class ? preg_replace('/^.*\\\\/', '', $class) . ': ' : '') . $message), 0, 250);
        $fp = sha1($o['fingerprint'] ?? ($source . '|' . $class . '|' . error_normalize($message) . '|' . $file . '|' . ($what instanceof Throwable || $file ? $line : '')));
        $ctx = $o['context'] ?? [];
        $tenant = function_exists('tid') ? (tid() ?: null) : null;
        $user = $GLOBALS['CURRENT_USER']['id'] ?? null;
        $url = PHP_SAPI === 'cli' ? 'cli: ' . implode(' ', array_slice($GLOBALS['argv'] ?? [], 0, 3)) : (($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?'));
        $now = gmdate('Y-m-d H:i:s');

        $g = row('SELECT id, status, muted_until, count, tenants, level FROM error_groups WHERE fingerprint = ?', [$fp]);
        if ($g) {
            $tenants = json_decode((string) $g['tenants'], true) ?: [];
            if ($tenant && !in_array($tenant, $tenants, true) && count($tenants) < 50) $tenants[] = $tenant;
            $regressed = $g['status'] === 'resolved';
            $mutedExpired = $g['status'] === 'muted' && $g['muted_until'] && $g['muted_until'] < $now;
            $lvl = ERROR_LEVELS[$level] > ERROR_LEVELS[$g['level']] ? $level : $g['level'];
            q('UPDATE error_groups SET count = count + 1, last_seen = ?, tenants = ?, level = ?, message = ?, trace = IF(? <> \'\', ?, trace),
                 status = IF(? OR ?, \'open\', status), regressed = IF(?, 1, regressed), resolved_at = IF(?, NULL, resolved_at) WHERE id = ?',
              [$now, json_encode($tenants), $lvl, mb_substr($message, 0, 5000), $trace, mb_substr($trace, 0, 16000),
               $regressed ? 1 : 0, $mutedExpired ? 1 : 0, $regressed ? 1 : 0, $regressed ? 1 : 0, $g['id']]);
            $id = (int) $g['id']; $count = (int) $g['count'] + 1; $status = ($regressed || $mutedExpired) ? 'open' : $g['status'];
            $level = $lvl;
        } else {
            q('INSERT INTO error_groups (fingerprint, level, source, title, message, file, line, trace, count, first_seen, last_seen, tenants, status)
               VALUES (?,?,?,?,?,?,?,?,1,?,?,?,\'open\') ON DUPLICATE KEY UPDATE count = count + 1, last_seen = VALUES(last_seen)',
              [$fp, $level, $source, $title, mb_substr($message, 0, 5000), mb_substr($file, 0, 250), $line ?: null, mb_substr((string) $trace, 0, 16000), $now, $now, json_encode($tenant ? [$tenant] : [])]);
            $id = (int) db()->lastInsertId(); $count = 1; $status = 'open'; $regressed = false;
            if (!$id) $id = (int) val('SELECT id FROM error_groups WHERE fingerprint = ?', [$fp]);
        }
        q('INSERT INTO error_samples (group_id, at, tenant_id, user_id, request_id, url, context) VALUES (?,?,?,?,?,?,?)',
          [$id, $now, $tenant, $user, request_id(), mb_substr($url, 0, 500), $ctx ? mb_substr(json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), 0, 4000) : null]);
        if ($count % 25 === 0) q('DELETE FROM error_samples WHERE group_id = ? AND id < (SELECT m FROM (SELECT MIN(id) m FROM (SELECT id FROM error_samples WHERE group_id = ? ORDER BY id DESC LIMIT 25) k) x)', [$id, $id]);

        // Should the team hear about it?
        if ($status === 'open' && ERROR_LEVELS[$level] >= ERROR_LEVELS[(string) setting('alert_min_level', 'error')]) {
            $last = (string) val('SELECT last_notified_at FROM error_groups WHERE id = ?', [$id]);
            $why = $count === 1 ? 'new' : (!empty($regressed) ? 'back again after being resolved' : (in_array($count, ALERT_COUNT_STEPS, true) ? "happened $count times" : null));
            if (!$why && $level === 'critical' && (!$last || strtotime($last . ' UTC') < time() - 7200)) $why = 'still happening';
            if ($why) q('UPDATE error_groups SET notify_pending = 1, notify_reason = ? WHERE id = ?', [$why, $id]);
            if ($why && $level === 'critical') $GLOBALS['FLUSH_ALERTS_NOW'] = true;
        }
    } catch (Throwable $inner) {
        error_fallback($what, $o, $inner);
    } finally {
        $busy = false;
    }
}

/** Database unavailable: keep a local record, and for critical problems try the config-file webhook directly. */
function error_fallback($what, array $o, ?Throwable $why = null): void {
    $line = json_encode([
        'at' => gmdate('c'), 'request' => request_id(), 'level' => $o['level'] ?? 'error',
        'message' => $what instanceof Throwable ? get_class($what) . ': ' . $what->getMessage() . ' @ ' . error_short_path($what->getFile()) . ':' . $what->getLine() : (string) $what,
        'logging_failed_because' => $why?->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
    @file_put_contents(APP_ROOT . '/storage/errors-fallback.log', $line . "\n", FILE_APPEND | LOCK_EX);
    $hook = (string) cfg('alert_webhook', '');
    if ($hook !== '' && ($o['level'] ?? '') === 'critical') {
        $last = @filemtime(APP_ROOT . '/storage/fallback-alert.stamp');
        if (!$last || $last < time() - 900) {
            @touch(APP_ROOT . '/storage/fallback-alert.stamp');
            alert_post($hook, "CRITICAL — Outreach app can't record errors (database unreachable?)\n" . mb_substr($line, 0, 900));
        }
    }
}

/* ---------- PHP hooks ---------- */

function error_page(int $code = 500): void {
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    while (ob_get_level()) @ob_end_clean();
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $ref = request_id();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Something went wrong</title>'
       . '<style>body{font:16px/1.5 system-ui,sans-serif;background:#f5f6fb;color:#1b1b21;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px}'
       . 'main{background:#fff;border-radius:28px;padding:36px 32px;max-width:440px}h1{font-size:22px;margin:0 0 8px}p{margin:0 0 16px;color:#46464f}'
       . 'code{background:#eef0f8;padding:2px 8px;border-radius:8px;font-size:15px}a{display:inline-block;background:#3a43d6;color:#fff;padding:10px 20px;border-radius:20px;text-decoration:none}</style></head>'
       . '<body><main><h1>Something went wrong on our side</h1><p>The development team has been notified automatically. Please try again in a minute.</p>'
       . '<p>If it keeps happening, quote this reference: <code>' . $ref . '</code></p><a href="index.php">Back to dashboard</a></main></body></html>';
}

function install_error_handlers(): void {
    set_error_handler(function (int $no, string $msg, string $file = '', int $line = 0): bool {
        if (!(error_reporting() & $no)) return false;            // silenced with @
        $level = match (true) {
            in_array($no, [E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING], true) => 'warning',
            in_array($no, [E_USER_ERROR, E_RECOVERABLE_ERROR], true) => 'error',
            default => 'notice',                                   // notices and deprecations
        };
        report_error($msg, ['level' => $level, 'source' => 'php', 'file' => $file, 'line' => $line, 'class' => 'PHP ' . $level,
            'trace' => error_short_path((new Exception())->getTraceAsString())]);
        return false;                                              // also let PHP write its own log line
    });
    set_exception_handler(function (Throwable $ex): void {
        report_error($ex, ['level' => 'critical']);
        error_log('Uncaught ' . get_class($ex) . ': ' . $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine() . ' [ref ' . request_id() . ']');
        error_page(500);
        if (PHP_SAPI === 'cli') fwrite(STDERR, 'Fatal: ' . $ex->getMessage() . ' [ref ' . request_id() . "]\n");
    });
    register_shutdown_function('error_shutdown');
}

function error_shutdown(): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        if (str_contains($e['message'], 'Allowed memory size')) @ini_set('memory_limit', (string) (memory_get_usage(true) + 64 * 1048576));   // room to record it
        report_error($e['message'], ['level' => 'critical', 'source' => 'php', 'file' => $e['file'], 'line' => $e['line'], 'class' => 'PHP fatal']);
        error_page(500);
    }
    // Everything below runs after the page has gone out, so the visitor never waits for it.
    if (PHP_SAPI !== 'cli') {
        if (function_exists('litespeed_finish_request')) @litespeed_finish_request();      // Hostinger (LiteSpeed)
        elseif (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();      // PHP-FPM
    }
    try {
        if (empty($GLOBALS['DB_CONNECTED']) && empty($GLOBALS['FLUSH_ALERTS_NOW'])) return;
        if (PHP_SAPI !== 'cli') {
            record_page_time();
            check_cron_heartbeat();
        }
        if (!empty($GLOBALS['FLUSH_ALERTS_NOW'])) flush_alerts(4);
    } catch (Throwable $ignored) {}
}

/** Per-page timing: daily hits / average / slowest per page, and slow pages become warnings. */
function record_page_time(): void {
    $ms = (int) round((microtime(true) - REQUEST_STARTED) * 1000);
    $path = basename(strtok((string) ($_SERVER['SCRIPT_NAME'] ?? 'unknown'), '?')) ?: 'unknown';
    if (in_array($path, ['cron.php'], true)) return;
    $slow = $ms >= SLOW_PAGE_MS ? 1 : 0;
    q('INSERT INTO perf_daily (day, path, hits, total_ms, max_ms, slow_hits, queries) VALUES (?,?,1,?,?,?,?)
       ON DUPLICATE KEY UPDATE hits = hits + 1, total_ms = total_ms + VALUES(total_ms), max_ms = GREATEST(max_ms, VALUES(max_ms)),
       slow_hits = slow_hits + VALUES(slow_hits), queries = queries + VALUES(queries)',
      [gmdate('Y-m-d'), mb_substr($path, 0, 80), $ms, $ms, $slow, (int) ($GLOBALS['QUERY_COUNT'] ?? 0)]);
    // The "Run a step now" buttons do real work on purpose, so they aren't slow pages.
    $busyAction = $path === 'index.php' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if ($slow && !$busyAction) {
        report_error("Slow page: $path took {$ms} ms", ['level' => 'warning', 'source' => 'slow', 'fingerprint' => "slow-page|$path",
            'title' => "Slow page: $path", 'context' => ['ms' => $ms, 'queries' => (int) ($GLOBALS['QUERY_COUNT'] ?? 0), 'memory_mb' => round(memory_get_peak_usage() / 1048576, 1)]]);
    }
}

/** If the 5-minute cron has gone quiet, raise one critical problem (checked at most once a minute). */
function check_cron_heartbeat(): void {
    if ((string) setting('installed_at') === '') return;
    $stamp = APP_ROOT . '/storage/heartbeat.check';
    $t = @filemtime($stamp);
    if ($t && $t > time() - 60) return;
    @touch($stamp);
    $last = (string) setting('last_cron_at');
    $since = $last ? (int) floor((time() - strtotime($last . ' UTC')) / 60) : null;
    $installedMin = (int) floor((time() - strtotime((string) setting('installed_at') . ' UTC')) / 60);
    if (($since === null && $installedMin > 30) || ($since !== null && $since > 20)) {
        report_error('Cron has not run for ' . ($since === null ? 'ever' : "$since minutes") . '. Nothing is being found, sent or read until it runs again.',
            ['level' => 'critical', 'source' => 'system', 'fingerprint' => 'cron-stale', 'title' => 'Cron job is not running']);
    }
}

/* ---------- alerts ---------- */

function alert_targets(): array {
    $hooks = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) setting('alert_webhooks', '')) ?: [])));
    $emails = array_values(array_filter(array_map('trim', explode(',', (string) setting('alert_emails', ''))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    return ['hooks' => $hooks, 'emails' => $emails];
}

/** Post a plain-text message to one webhook, in the format that service expects. */
function alert_post(string $url, string $text): ?string {
    if (!preg_match('#^https://#i', $url) && !local_testing()) return 'Webhook URLs must start with https://';
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (str_ends_with($host, 'discord.com') || str_ends_with($host, 'discordapp.com')) $body = ['content' => mb_substr($text, 0, 1900)];
    elseif ($host === 'api.telegram.org') {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $qs);
        $body = ['chat_id' => $qs['chat_id'] ?? '', 'text' => mb_substr($text, 0, 4000), 'disable_web_page_preview' => true];
        $url = strtok($url, '?');
    } else $body = ['text' => mb_substr($text, 0, 3900)];      // Slack, Google Chat, Microsoft Teams workflows, n8n, Make, Zapier
    $r = http_request('POST', $url, [], $body, 6);
    if ($r['error'] !== '') return $r['error'];
    return $r['status'] >= 200 && $r['status'] < 300 ? null : 'HTTP ' . $r['status'] . ' ' . mb_substr($r['body'], 0, 160);
}

/** Send an email alert through the inbox chosen on System health. */
function alert_email(array $to, string $subject, string $text): ?string {
    $id = (int) setting('alert_mailbox_id', 0);
    $mb = $id ? row('SELECT * FROM mailboxes WHERE id = ?', [$id]) : null;
    if (!$mb) return 'No sending inbox chosen for alert emails';
    try {
        $m = make_mailer($mb);
        $m->Timeout = 8;
        foreach ($to as $addr) $m->addAddress($addr);
        $m->Subject = $subject;
        $m->Body = $text;
        $m->isHTML(false);
        $m->send();
        return null;
    } catch (Throwable $ex) { return $ex->getMessage(); }
}

function alert_text(array $g): string {
    $names = [];
    foreach (json_decode((string) $g['tenants'], true) ?: [] as $t) $names[] = (string) (val('SELECT name FROM tenants WHERE id = ?', [$t]) ?? "#$t");
    $lines = [
        strtoupper($g['level']) . ' — ' . $g['title'],
        'Why you’re hearing about it: ' . ($g['notify_reason'] ?: 'new'),
        'Where: ' . (ERROR_SOURCES[$g['source']] ?? $g['source']) . ($g['file'] ? ' · ' . $g['file'] . ($g['line'] ? ':' . $g['line'] : '') : ''),
        'Workspaces: ' . ($names ? implode(', ', array_slice($names, 0, 8)) . (count($names) > 8 ? ' +' . (count($names) - 8) : '') : 'platform-wide'),
        'Count: ' . $g['count'] . ' · first ' . $g['first_seen'] . ' UTC · last ' . $g['last_seen'] . ' UTC',
    ];
    $s = row('SELECT request_id, url FROM error_samples WHERE group_id = ? ORDER BY id DESC LIMIT 1', [$g['id']]);
    if ($s) $lines[] = 'Last request: ' . $s['url'] . ' (ref ' . $s['request_id'] . ')';
    if ($g['message'] && $g['message'] !== $g['title']) $lines[] = 'Message: ' . mb_substr((string) $g['message'], 0, 400);
    $lines[] = 'Open: ' . base_url('admin/health.php?id=' . $g['id']);
    return implode("\n", $lines);
}

/** Deliver queued alerts. Called after critical errors (once the page has gone out) and at the start of every cron run. */
function flush_alerts(int $max = 10): array {
    $sent = [];
    try {
        $t = alert_targets();
        if (!$t['hooks'] && !$t['emails']) { q('UPDATE error_groups SET notify_pending = 0 WHERE notify_pending = 1'); return []; }
        $hourKey = gmdate('YmdH');
        $used = setting('alerts_hour') === $hourKey ? (int) setting('alerts_hour_count', 0) : 0;
        $pending = all('SELECT * FROM error_groups WHERE notify_pending = 1 ORDER BY level DESC, last_seen DESC LIMIT ' . (int) $max);
        foreach ($pending as $g) {
            if (q('UPDATE error_groups SET notify_pending = 0 WHERE id = ? AND notify_pending = 1', [$g['id']])->rowCount() !== 1) continue; // another process took it
            if ($used >= ALERTS_PER_HOUR) {
                if (setting('alerts_storm') !== $hourKey) {
                    set_setting('alerts_storm', $hourKey);
                    foreach ($t['hooks'] as $h) alert_post($h, 'Outreach app: more than ' . ALERTS_PER_HOUR . ' alerts this hour — pausing alerts until the next hour. Open ' . base_url('admin/health.php'));
                }
                continue;
            }
            $text = alert_text($g);
            $errs = [];
            foreach ($t['hooks'] as $h) if ($err = alert_post($h, $text)) $errs[] = parse_url($h, PHP_URL_HOST) . ': ' . $err;
            if ($t['emails'] && ($err = alert_email($t['emails'], '[Outreach ' . strtoupper($g['level']) . '] ' . mb_substr($g['title'], 0, 120), $text))) $errs[] = 'email: ' . $err;
            q('UPDATE error_groups SET last_notified_at = ?, notified_count = notified_count + 1 WHERE id = ?', [now(), $g['id']]);
            $used++;
            $sent[] = ['id' => $g['id'], 'errors' => $errs];
            if ($errs) set_setting('alert_last_failure', now() . ' — ' . implode('; ', $errs));
        }
        set_setting('alerts_hour', $hourKey); set_setting('alerts_hour_count', (string) $used);
    } catch (Throwable $ex) { error_fallback('flush_alerts failed: ' . $ex->getMessage(), ['level' => 'error']); }
    return $sent;
}

/** Once a day: housekeeping, and an optional summary for the team. */
function daily_maintenance(): array {
    $today = gmdate('Y-m-d');
    if (setting('maintenance_day') === $today) return ['skipped' => 'already ran today'];
    set_setting('maintenance_day', $today);
    $out = [];
    $purge = [
        'error_samples'  => ['at', 30],
        'cron_runs'      => ['started_at', 14],
        'perf_daily'     => ['day', 60],
        'login_attempts' => ['created_at', 30],
        'events'         => ['created_at', max(30, (int) setting('events_keep_days', 365))],
    ];
    foreach ($purge as $table => [$col, $days]) {
        $n = 0;
        do { $c = q("DELETE FROM `$table` WHERE `$col` < ? LIMIT 5000", [gmdate('Y-m-d H:i:s', time() - $days * 86400)])->rowCount(); $n += $c; } while ($c === 5000);
        $out[$table] = $n;
    }
    $out['resolved_groups'] = q("DELETE FROM error_groups WHERE status = 'resolved' AND last_seen < ?", [gmdate('Y-m-d H:i:s', time() - 90 * 86400)])->rowCount();
    q("UPDATE error_groups SET status = 'open', muted_until = NULL WHERE status = 'muted' AND muted_until IS NOT NULL AND muted_until < ?", [now()]);
    $out['invitations'] = q('DELETE FROM invitations WHERE accepted_at IS NULL AND expires_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)])->rowCount();
    foreach (glob(APP_ROOT . '/storage/cache/*.cache') ?: [] as $f) if (@filemtime($f) < time() - 86400) @unlink($f);
    if (setting('alert_digest', '1') === '1') $out['digest'] = send_digest();
    return $out;
}

function send_digest(): string {
    $t = alert_targets();
    if (!$t['hooks'] && !$t['emails']) return 'no targets';
    $since = gmdate('Y-m-d H:i:s', time() - 86400);
    $open = all("SELECT level, COUNT(*) n FROM error_groups WHERE status = 'open' GROUP BY level");
    $byLevel = array_column($open, 'n', 'level');
    $top = all("SELECT g.id, g.level, g.title, COUNT(s.id) n FROM error_samples s JOIN error_groups g ON g.id = s.group_id WHERE s.at >= ? GROUP BY g.id ORDER BY n DESC LIMIT 5", [$since]);
    $runs = row('SELECT COUNT(*) n, ROUND(AVG(duration_ms)) avg_ms, MAX(duration_ms) max_ms, SUM(skipped) skipped, SUM(errors) errs FROM cron_runs WHERE started_at >= ?', [$since]);
    $slow = all('SELECT path, hits, ROUND(total_ms / hits) avg_ms, max_ms FROM perf_daily WHERE day = ? ORDER BY total_ms / hits DESC LIMIT 3', [gmdate('Y-m-d', time() - 86400)]);
    $lines = ['Outreach app — daily health, ' . gmdate('D j M Y'),
        'Open problems: ' . (int) ($byLevel['critical'] ?? 0) . ' critical, ' . (int) ($byLevel['error'] ?? 0) . ' errors, ' . (int) ($byLevel['warning'] ?? 0) . ' warnings',
        'Cron (24h): ' . (int) $runs['n'] . ' runs, avg ' . (int) $runs['avg_ms'] . ' ms, slowest ' . (int) $runs['max_ms'] . ' ms, ' . (int) $runs['skipped'] . ' skipped (overlap), ' . (int) $runs['errs'] . ' job errors',
        'Workspaces active: ' . (int) val("SELECT COUNT(*) FROM tenants WHERE status = 'active'")];
    if ($top) { $lines[] = 'Most frequent in the last 24h:'; foreach ($top as $r) $lines[] = "  • {$r['n']}× [{$r['level']}] " . mb_substr($r['title'], 0, 110); }
    if ($slow) { $lines[] = 'Slowest pages yesterday (avg / max):'; foreach ($slow as $r) $lines[] = "  • {$r['path']}: {$r['avg_ms']} / {$r['max_ms']} ms over {$r['hits']} views"; }
    $lines[] = 'Open: ' . base_url('admin/health.php');
    $text = implode("\n", $lines);
    $errs = [];
    foreach ($t['hooks'] as $h) if ($e = alert_post($h, $text)) $errs[] = $e;
    if ($t['emails'] && ($e = alert_email($t['emails'], '[Outreach] Daily health summary', $text))) $errs[] = $e;
    return $errs ? implode('; ', $errs) : 'sent';
}

/* ---------- operational events that the developer team should see ---------- */
const REPORTABLE_EVENTS = [
    'find_error' => 'integration', 'draft_error' => 'integration', 'send_error' => 'integration', 'imap_error' => 'integration',
    'mailbox_paused' => 'integration', 'booking_error' => 'integration', 'enrich_error' => 'integration',
];
/** Called by log_event() for error-level events: per-lead noise (a prospect's site being down) stays in the activity log only. */
function report_event(?int $leadId, string $type, string $detail): void {
    $src = REPORTABLE_EVENTS[$type] ?? null;      // job_* failures are reported with their stack trace by run_jobs()
    if ($src === null) return;
    if ($type === 'enrich_error' && $leadId !== null) return;
    report_error($detail, ['level' => in_array($type, ['mailbox_paused', 'imap_error', 'send_error'], true) ? 'warning' : 'error', 'source' => $src,
        'title' => str_replace('_', ' ', ucfirst($type)) . ': ' . mb_substr($detail, 0, 180),
        'fingerprint' => "event|$type|" . error_normalize($detail), 'context' => ['lead_id' => $leadId, 'event' => $type]]);
}
