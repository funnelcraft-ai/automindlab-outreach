<?php
/**
 * Booking webhook for cal.id / Cal.com.
 * Subscriber URL (one per workspace, shown on its Connections page):
 *   https://your-app/webhook-cal.php?w=WORKSPACE_ID&key=WORKSPACE_WEBHOOK_KEY
 * Events: Booking Created, Booking Rescheduled, Booking Cancelled.
 * If you also set a webhook secret in both places, the X-Cal-Signature-256 HMAC is verified.
 * Older single-workspace URLs (?key=CRON_KEY, no w) keep working for workspace 1.
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
header('Content-Type: application/json');

$raw = (string) file_get_contents('php://input');
$w = (int) ($_GET['w'] ?? 1);
if ($w < 1 || !row('SELECT id FROM tenants WHERE id = ?', [$w])) { http_response_code(401); exit('{"error":"unauthorized"}'); }
set_tenant($w);
$secret = (string) setting('cal_webhook_secret');
$key = (string) setting('webhook_key');
$given = (string) ($_GET['key'] ?? '');

$sig = $_SERVER['HTTP_X_CAL_SIGNATURE_256'] ?? '';
$okSig = $secret !== '' && $sig !== '' && hash_equals(hash_hmac('sha256', $raw, $secret), strtolower((string) $sig));
$okKey = $key !== '' && hash_equals($key, $given);
if (!$okKey && !isset($_GET['w']) && $w === 1) { $ck = (string) setting('cron_key'); $okKey = $ck !== '' && hash_equals($ck, $given); }
if (!$okSig && !$okKey) { http_response_code(401); exit('{"error":"unauthorized"}'); }

$data = json_decode($raw, true);
if (!is_array($data)) { http_response_code(400); exit('{"error":"bad json"}'); }
set_api_status('calid', true, 'Webhook received: ' . ($data['triggerEvent'] ?? 'event'));
if (($data['triggerEvent'] ?? '') === 'PING') exit('{"ok":true,"ping":true}');
try {
    $r = handle_booking($data);
    echo json_encode(['ok' => true, 'lead' => $r]);
} catch (Throwable $ex) {
    log_event(null, 'booking_error', $ex->getMessage(), 'error');
    http_response_code(500);
    echo json_encode(['error' => 'internal']);
}
