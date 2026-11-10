<?php
declare(strict_types=1);
/**
 * One-time installer. Open https://your-app/install.php after creating config.php.
 * It locks itself (404) once an admin user exists, so it is safe to leave in place — e.g. when deploying from Git.
 */
$checks = [
    'PHP 8.1+' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'curl' => extension_loaded('curl'),
    'openssl' => extension_loaded('openssl'),
    'mbstring' => extension_loaded('mbstring'),
    'sodium' => extension_loaded('sodium'),
    'config file present' => is_file((($p = strpos(__DIR__, '/public_html')) !== false ? substr(__DIR__, 0, $p) : dirname(__DIR__)) . '/outreach-config.php') || is_file(__DIR__ . '/config.php'),
    'storage/ writable' => is_writable(__DIR__ . '/storage'),
];
$errors = []; $done = false;
if (!in_array(false, $checks, true)) {
    try {
        require __DIR__ . '/lib/bootstrap.php';
        app_key();
        db();
        $installed = false;
        try { $installed = (int) val('SELECT COUNT(*) FROM users') > 0; } catch (Throwable $e) {}
        if ($installed) { http_response_code(404); exit('Not found.'); }   // locked for good once an admin exists (safe to leave in a Git deploy)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $pass = (string) ($_POST['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
            if ($m = password_problem($pass, $email)) $errors[] = $m;
            if (!$errors) {
                $sql = (string) file_get_contents(__DIR__ . '/schema.sql');
                foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $sql)))) as $stmt) db()->exec($stmt);
                $ws = trim((string) ($_POST['workspace'] ?? '')) ?: 'My agency';
                $uid = insert('users', ['email' => $email, 'password_hash' => password_hash($pass, PASSWORD_DEFAULT), 'is_platform_admin' => 1, 'last_tenant_id' => 1]);
                q('INSERT INTO tenants (id, name, plan, quota_leads, quota_ai_usd, quota_mailboxes) VALUES (1, ?, ?, 2000, 25, 10)', [mb_substr($ws, 0, 190), 'Internal']);
                q("INSERT INTO memberships (user_id, tenant_id, role) VALUES (?, 1, 'owner')", [$uid]);
                set_tenant(1);
                set_tenant_setting('company_name', $ws, 1);
                set_tenant_setting('webhook_key', bin2hex(random_bytes(16)), 1);
                set_setting('cron_key', bin2hex(random_bytes(16)));
                set_setting('health_key', bin2hex(random_bytes(12)));
                set_setting('installed_at', now());
                set_setting('schema_version', (string) SCHEMA_VERSION);
                $done = true;
            }
        }
    } catch (Throwable $ex) {
        $msg = $ex->getMessage();
        if (str_contains($msg, 'app_key')) $msg .= ' Use this freshly generated key: ' . base64_encode(random_bytes(32));
        $errors[] = $msg;
    }
}
function okmark(bool $b): string { return $b ? '<span style="color:#15803d">✔</span>' : '<span style="color:#b91c1c">✘</span>'; }
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install — Outreach</title>
<style>body{font:15px/1.5 system-ui,sans-serif;background:#f6f7f9;color:#1d2433;max-width:520px;margin:40px auto;padding:0 16px}
.card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:16px}
input{width:100%;padding:10px;border:1px solid #d0d5dd;border-radius:8px;margin:4px 0 12px;box-sizing:border-box}
button,a.btn{background:#1d2433;color:#fff;border:0;border-radius:8px;padding:10px 18px;cursor:pointer;text-decoration:none;display:inline-block}
.err{background:#fef2f2;color:#991b1b;padding:10px;border-radius:8px;margin-bottom:12px}</style></head><body>
<h1>Outreach installer</h1>
<div class="card"><h3>Server checks</h3><?php foreach ($checks as $k => $v) echo okmark($v) . ' ' . htmlspecialchars($k) . '<br>'; ?></div>
<?php foreach ($errors as $er) echo '<div class="err">' . htmlspecialchars($er) . '</div>'; ?>
<?php if ($done): ?>
  <div class="card"><h3>Installed ✔</h3><p>Now <strong>delete install.php</strong> from the server, then sign in.</p><a class="btn" href="admin/login.php">Go to the Admin console sign-in</a><p class="small">Your account is a platform admin: it signs in at <code>/admin/</code>. Client teams you invite sign in at the main address.</p></div>
<?php elseif (!in_array(false, $checks, true)): ?>
  <div class="card"><h3>Create admin account</h3><form method="post">
  <label>Your agency's name <small>(your first workspace)</small></label><input name="workspace" required placeholder="Automindlab">
  <label>Email</label><input name="email" type="email" required>
  <label>Password (12+ characters)</label><input name="password" type="password" minlength="12" required>
  <button>Install</button></form></div>
<?php else: ?>
  <div class="card">Fix the failed checks above (Hostinger: hPanel → Advanced → PHP Configuration), then reload.</div>
<?php endif; ?>
</body></html>
