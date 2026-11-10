<?php
/**
 * Pipeline runner. Schedule every 5 minutes in Hostinger hPanel → Advanced → Cron Jobs:
 *   /usr/bin/php /home/USER/domains/DOMAIN/public_html/cron.php
 * One cron job serves every workspace: each run takes turns across active workspaces
 * (least recently run first) so a big workspace can't starve the others.
 * Optional: run only some jobs →  php cron.php send,inbox
 * Optional: one workspace only  →  php cron.php send,inbox 3
 * Time budget per run: 50 s by default (fits a 5-minute cron on shared hosting). On a VPS you can run cron every
 * minute and/or raise 'cron_budget' in the config file; overlapping runs are skipped automatically.
 * Web fallback (if CLI cron is unavailable): https://your-app/cron.php?key=CRON_KEY
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    $key = (string) setting('cron_key');
    if ($key === '' || !hash_equals($key, (string) ($_GET['key'] ?? ''))) { http_response_code(403); exit('forbidden'); }
    header('Content-Type: application/json');
}
$arg = PHP_SAPI === 'cli' ? ($argv[1] ?? '') : (string) ($_GET['jobs'] ?? '');
$jobs = $arg !== '' ? array_map('trim', explode(',', $arg)) : ['send', 'inbox', 'find', 'enrich', 'draft', 'linkedin'];
$only = PHP_SAPI === 'cli' ? (int) ($argv[2] ?? 0) : (int) ($_GET['w'] ?? 0);

$started = microtime(true);
$startedAt = now();
flush_alerts();                                      // anything queued since the last run goes out first
$result = run_jobs($jobs, max(20, min(290, (int) cfg('cron_budget', 50))), $only ?: null);
$skipped = isset($result['locked']);
$errors = 0;
if (!$skipped) foreach ($result as $byJob) foreach ((array) $byJob as $r) if (is_array($r) && isset($r['error'])) $errors++;
$maintenance = (!$skipped && !$only && $arg === '') ? daily_maintenance() : null;
if (!$skipped) {
    // Cron is clearly alive again: close the "not running" / "stuck" problems and tell the team it recovered.
    foreach (['cron-stale' => 'Cron is running again.', 'cron-stuck' => 'Cron runs are completing again.'] as $fpKey => $msg) {
        if (q("UPDATE error_groups SET status = 'resolved', resolved_at = ? WHERE fingerprint = ? AND status = 'open'", [now(), sha1($fpKey)])->rowCount()) {
            foreach (alert_targets()['hooks'] as $h) alert_post($h, "RECOVERED — $msg " . base_url('admin/health.php'));
        }
    }
}
flush_alerts();
try {
    q('INSERT INTO cron_runs (started_at, duration_ms, tenants, jobs, errors, peak_mb, skipped, summary) VALUES (?,?,?,?,?,?,?,?)', [
        $startedAt, (int) round((microtime(true) - $started) * 1000), $skipped ? 0 : count($result), mb_substr(implode(',', $jobs), 0, 120),
        $errors, round(memory_get_peak_usage(true) / 1048576, 1), $skipped ? 1 : 0, mb_substr(json_encode($result), 0, 8000)]);
} catch (Throwable $ex) { report_error($ex, ['level' => 'warning', 'source' => 'cron']); }
echo json_encode(['at' => now(), 'result' => $result, 'maintenance' => $maintenance], JSON_PRETTY_PRINT), "\n";
