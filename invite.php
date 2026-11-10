<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
start_session();

$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$inv = find_invite($token);
$u = current_user();
$err = '';

if ($inv && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'accept' && $u && strcasecmp($u['email'], $inv['email']) === 0) {
        accept_invite($inv, (int) $u['id']);
        flash('Welcome to ' . $inv['tenant_name'] . '.');
        redirect('index.php');
    }
    if ($a === 'create' && !$u && !row('SELECT id FROM users WHERE email = ?', [$inv['email']])) {
        $pw = (string) ($_POST['password'] ?? '');
        $name = trim(mb_substr((string) ($_POST['name'] ?? ''), 0, 120));
        if ($pw !== (string) ($_POST['password2'] ?? '')) $err = 'The two passwords don’t match.';
        elseif ($p = password_problem($pw, $inv['email'])) $err = $p;
        else {
            $id = insert('users', ['email' => $inv['email'], 'name' => $name ?: null, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
                'password_changed_at' => now(), 'is_platform_admin' => 0]);
            accept_invite($inv, $id);
            finish_login(row('SELECT * FROM users WHERE id = ?', [$id]));
            $_SESSION['tid'] = (int) $inv['tenant_id'];
            flash('Your account is ready. Turn on two-step verification in Account and security.');
            redirect('index.php');
        }
    }
    if ($a === 'signin') {   // existing account: sign in, then come straight back here
        $_SESSION['after_login'] = 'invite.php?t=' . $token;
        redirect('login.php');
    }
}
$existing = $inv && !$u ? (bool) row('SELECT id FROM users WHERE email = ?', [$inv['email']]) : false;

page_head('Join a workspace');
?>
<body class="login">
<form method="post" class="login-card form" autocomplete="on">
  <img src="assets/logo.png" alt="<?= e(setting('company_name')) ?>" width="200" height="36">
  <?= csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>">
  <?php if (!$inv): ?>
    <h1>Link not valid</h1><p>This invitation has expired, was already used, or was replaced by a newer link. Ask the person who invited you for a new one.</p>
    <a class="btn tonal wide" href="login.php">Go to sign in</a>
  <?php elseif ($inv['tenant_status'] === 'suspended'): ?>
    <h1>Workspace unavailable</h1><p><?= e($inv['tenant_name']) ?> is currently suspended. Ask the person who invited you.</p>
  <?php elseif ($u && strcasecmp($u['email'], $inv['email']) !== 0): ?>
    <h1>Wrong account</h1><p>This invitation is for <strong><?= e($inv['email']) ?></strong>, but you’re signed in as <?= e($u['email']) ?>. Sign out, then open the link again.</p>
    <button class="btn tonal wide" formaction="logout.php">Sign out</button>
  <?php elseif ($u): ?>
    <h1>Join <?= e($inv['tenant_name']) ?></h1><p>You’ve been invited as <strong><?= e(ROLE_LABELS[$inv['role']]) ?></strong>. <?= e(ROLE_HELP[$inv['role']]) ?></p>
    <button class="btn filled wide" name="action" value="accept">Join workspace</button>
  <?php elseif ($existing): ?>
    <h1>Join <?= e($inv['tenant_name']) ?></h1><p>You already have an account as <strong><?= e($inv['email']) ?></strong>. Sign in to accept the invitation as <?= e(ROLE_LABELS[$inv['role']]) ?>.</p>
    <button class="btn filled wide" name="action" value="signin">Sign in to accept</button>
  <?php else: ?>
    <h1>Join <?= e($inv['tenant_name']) ?></h1><p>Create your account. You’ll join as <strong><?= e(ROLE_LABELS[$inv['role']]) ?></strong> — <?= e(lcfirst(ROLE_HELP[$inv['role']])) ?></p>
    <?php if ($err): ?><div class="test-msg err" role="alert"><?= e($err) ?></div><?php endif; ?>
    <input type="hidden" name="action" value="create">
    <label>Email<input type="email" value="<?= e($inv['email']) ?>" readonly autocomplete="username"></label>
    <label>Your name<input name="name" autocomplete="name" value="<?= e($_POST['name'] ?? '') ?>"></label>
    <label>Password <small>at least 12 characters</small><input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
    <label>Repeat password<input type="password" name="password2" required minlength="12" autocomplete="new-password"></label>
    <button class="btn filled wide">Create account and join</button>
  <?php endif; ?>
</form>
<script src="assets/app.js?v=<?= APP_VERSION ?>"></script></body></html>
