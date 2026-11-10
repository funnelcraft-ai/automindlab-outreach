<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
start_session();
if (current_user()) redirect(after_login_target());
$err = '';
$step = !empty($_SESSION['pending_uid']) && time() - (int) ($_SESSION['pending_at'] ?? 0) <= 300 ? '2fa' : 'password';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['step'] ?? '') === '2fa') {
        $r = attempt_2fa((string) ($_POST['code'] ?? ''));
        if ($r === 'ok') redirect(after_login_target());
        if ($r === 'expired') { $step = 'password'; $err = 'That took too long. Sign in again.'; }
        else { $step = '2fa'; $err = $r; }
    } elseif (($_POST['step'] ?? '') === 'restart') {
        unset($_SESSION['pending_uid'], $_SESSION['pending_at']);
        $step = 'password';
    } else {
        $r = attempt_login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
        if ($r === 'ok') redirect(after_login_target());
        if ($r === '2fa') $step = '2fa'; else $err = $r;
    }
}
$admin = app_area() === 'admin';
page_head($admin ? 'Admin console sign-in' : 'Sign in');
?>
<body class="login<?= $admin ? ' admin-login' : '' ?>">
<form method="post" class="login-card form" autocomplete="on">
  <img src="<?= rel('assets/logo.png') ?>" alt="<?= e(setting('company_name')) ?>" width="200" height="36">
  <?php if ($admin): ?><span class="console-badge"><?= icon('admin_panel_settings', 'fill') ?><span>Admin console</span></span><?php endif; ?>
  <?= csrf_field() ?>
  <?php if ($step === '2fa'): ?>
    <h1>Two-step verification</h1><p>Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>
    <?php if ($err): ?><div class="test-msg err" role="alert"><?= e($err) ?></div><?php endif; ?>
    <input type="hidden" name="step" value="2fa">
    <label>Code<input name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus maxlength="12"></label>
    <button class="btn filled wide">Verify</button>
    <button class="btn text wide" name="step" value="restart" formnovalidate>Use a different account</button>
  <?php else: ?>
    <?php if ($admin): ?><h1>Platform admin sign-in</h1><p>For the agency team that runs the platform. Client teams sign in on the <a href="../login.php">workspace sign-in page</a>.</p>
    <?php else: ?><h1>Sign in</h1><p>to your outreach dashboard</p><?php endif; ?>
    <?php if ($err): ?><div class="test-msg err" role="alert"><?= e($err) ?></div><?php endif; ?>
    <label>Email<input type="email" name="email" required autofocus autocomplete="username" value="<?= e($_POST['email'] ?? '') ?>"></label>
    <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn filled wide">Sign in</button>
  <?php endif; ?>
</form>
<script src="<?= rel('assets/app.js') ?>?v=<?= APP_VERSION ?>"></script></body></html>
