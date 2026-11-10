<?php
/**
 * Health check for uptime monitors (UptimeRobot, Better Stack, Hostinger's own monitor…).
 *   https://your-app/healthz.php?key=HEALTH_KEY   (the full URL is on System health → Server)
 * 200 = database reachable and cron ran in the last 20 minutes. 503 = something needs attention.
 * Without the key it only answers ok / not ok, so it reveals nothing.
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$checks = [];
try { $checks['database'] = (int) val('SELECT 1') === 1; } catch (Throwable $e) { $checks['database'] = false; }
$last = $checks['database'] ? (string) setting('last_cron_at') : '';
$age = $last ? (int) floor((time() - strtotime($last . ' UTC')) / 60) : null;
$checks['cron'] = $age !== null && $age <= 20;
$checks['storage'] = is_writable(APP_ROOT . '/storage');
$ok = !in_array(false, $checks, true);
http_response_code($ok ? 200 : 503);

$key = $checks['database'] ? (string) setting('health_key') : '';
if ($key === '' || !hash_equals($key, (string) ($_GET['key'] ?? ''))) { echo json_encode(['ok' => $ok]); exit; }
echo json_encode([
    'ok' => $ok,
    'checks' => $checks,
    'cron_minutes_ago' => $age,
    'open_critical' => (int) val("SELECT COUNT(*) FROM error_groups WHERE status = 'open' AND level = 'critical'"),
    'open_errors' => (int) val("SELECT COUNT(*) FROM error_groups WHERE status = 'open' AND level = 'error'"),
    'version' => APP_VERSION,
    'at' => gmdate('c'),
], JSON_PRETTY_PRINT);
