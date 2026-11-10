<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$id = (int) ($_GET['l'] ?? 0);
$t = (string) ($_GET['t'] ?? '');
$valid = $id > 0 && hash_equals(sign_token('unsub:' . $id), $t);
$lead = $valid ? row('SELECT * FROM leads WHERE id = ?', [$id]) : null;
if ($lead) set_tenant((int) $lead['tenant_id']);   // the lead's workspace: its suppression list, its company name
$done = false;

// POST = confirmation button or RFC 8058 one-click (List-Unsubscribe-Post) from the mail client
if ($lead && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($lead['email']) suppress($lead['email'], 'unsubscribe_link');
    cancel_sequence($id, 'unsubscribed via link');
    update('leads', ['status' => 'unsubscribed', 'unsubscribed_at' => now()], 'id = ? AND tenant_id = ?', [$id, tid()]);
    log_event($id, 'unsubscribe', 'Unsubscribed via link');
    $done = true;
    if (isset($_POST['List-Unsubscribe'])) { http_response_code(200); exit('ok'); }
}
$company = e(setting('company_name'));
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>Unsubscribe — <?= $company ?></title>
<style>@font-face{font-family:"Google Sans Flex";src:url(assets/google-sans-flex.woff2) format("woff2");font-weight:400 700}body{font:16px/1.5 "Google Sans Flex",system-ui,-apple-system,Segoe UI,sans-serif;background:#f1effb;color:#1b1a22;display:grid;place-items:center;min-height:100vh;margin:0}
.card{background:#fff;padding:36px;border-radius:28px;max-width:420px;text-align:center}.card img{height:28px;margin-bottom:20px}
button{background:#2417ee;color:#fff;border:0;border-radius:24px;padding:12px 24px;font-size:15px;font-weight:600;cursor:pointer}</style></head><body><div class="card"><img src="assets/logo.png" alt="<?= $company ?>">
<?php if (!$lead): ?>
  <h2>Link not valid</h2><p>Reply to the email with “unsubscribe” and we'll remove you right away.</p>
<?php elseif ($done || $lead['status'] === 'unsubscribed'): ?>
  <h2>You're unsubscribed</h2><p><?= e($lead['email']) ?> won't receive further emails from <?= $company ?>.</p>
<?php else: ?>
  <h2>Unsubscribe?</h2><p>Stop all emails from <?= $company ?> to <strong><?= e($lead['email']) ?></strong>.</p>
  <form method="post"><button type="submit">Yes, unsubscribe me</button></form>
<?php endif; ?>
</div></body></html>
