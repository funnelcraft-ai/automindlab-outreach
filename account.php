<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$me = require_login();
$user = row('SELECT * FROM users WHERE id = ?', [$me['id']]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = (string) ($_POST['action'] ?? '');
    try {
        if ($a === 'password') {
            $new = (string) ($_POST['new_password'] ?? '');
            if ($new !== (string) ($_POST['confirm_password'] ?? '')) throw new RuntimeException('The two new passwords don’t match.');
            if ($err = change_password((int) $user['id'], (string) ($_POST['current_password'] ?? ''), $new)) throw new RuntimeException($err);
            flash('Password changed. Any other signed-in browsers have been signed out.');
        } elseif ($a === 'start_2fa') {
            $_SESSION['pending_totp'] = totp_new_secret();
        } elseif ($a === 'cancel_2fa') {
            unset($_SESSION['pending_totp']);
        } elseif ($a === 'confirm_2fa') {
            $secret = (string) ($_SESSION['pending_totp'] ?? '');
            if ($secret === '' || !totp_verify($secret, preg_replace('/\s+/', '', (string) ($_POST['code'] ?? '')))) {
                throw new RuntimeException('That code didn’t match. Check the time on your phone is set automatically and try the newest code.');
            }
            $codes = new_recovery_codes();
            update('users', ['totp_secret' => encrypt_str($secret), 'totp_enabled' => 1, 'recovery_codes' => hash_recovery_codes($codes)], 'id = ?', [$user['id']]);
            unset($_SESSION['pending_totp']);
            $_SESSION['show_codes'] = $codes;
            log_event(null, 'security', "Two-step verification turned on for {$user['email']}", 'warn');
            flash('Two-step verification is on. Save your recovery codes now.');
        } elseif ($a === 'disable_2fa' || $a === 'new_codes') {
            if ($b = login_blocked((string) $user['email'])) throw new RuntimeException($b);
            if (!password_verify((string) ($_POST['current_password'] ?? ''), $user['password_hash'])) { record_login_attempt((string) $user['email'], false); throw new RuntimeException('Your current password isn’t right.'); }
            // Same rule as sign-in: a code works once, so one just used (or sniffed) can't be replayed here.
            if (!totp_verify(decrypt_str((string) $user['totp_secret']), preg_replace('/\s+/', '', (string) ($_POST['code'] ?? '')), (int) ($user['totp_last_step'] ?? 0))) { record_login_attempt((string) $user['email'], false); throw new RuntimeException('That code didn’t match.'); }
            q('UPDATE users SET totp_last_step = ? WHERE id = ?', [(int) ($_SESSION['last_totp_step'] ?? 0), $user['id']]);
            if ($a === 'disable_2fa') {
                update('users', ['totp_secret' => null, 'totp_enabled' => 0, 'recovery_codes' => null], 'id = ?', [$user['id']]);
                log_event(null, 'security', "Two-step verification turned off for {$user['email']}", 'warn');
                flash('Two-step verification is off.');
            } else {
                $codes = new_recovery_codes();
                update('users', ['recovery_codes' => hash_recovery_codes($codes)], 'id = ?', [$user['id']]);
                $_SESSION['show_codes'] = $codes;
                flash('New recovery codes created. The old ones no longer work.');
            }
        } elseif ($a === 'signout_others') {
            q('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$user['id']]);
            $_SESSION['sv'] = (int) $user['session_version'] + 1;
            session_regenerate_id(true);
            flash('Signed out of every other browser.');
        }
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); }
    redirect('account.php');
}

$pending = (string) ($_SESSION['pending_totp'] ?? '');
$showCodes = $_SESSION['show_codes'] ?? null;
unset($_SESSION['show_codes']);
$codesLeft = count(json_decode((string) $user['recovery_codes'], true) ?: []);
$attempts = all('SELECT * FROM login_attempts WHERE email = ? ORDER BY id DESC LIMIT 10', [$user['email']]);
$installLeft = is_file(__DIR__ . '/install.php');

page_header('Account and security', 'account', 'Your sign-in details for ' . $user['email'] . '.');
?>
<?php if (app_area() === 'admin' && !(int) $me['totp_enabled']): ?><div class="banner bad"><?= icon('shield') ?><span>The Admin console requires two-step verification. Turn it on below to continue — every other console page is locked until you do.</span></div><?php endif; ?>
<?php if ($showCodes): ?>
<section class="panel codes-panel">
  <div class="panel-head"><div><h2>Your recovery codes</h2><p class="muted">Each code works once if you lose your phone. They won’t be shown again — save them in your password manager.</p></div></div>
  <ol class="codes"><?php foreach ($showCodes as $c): ?><li><code><?= e($c) ?></code></li><?php endforeach; ?></ol>
  <span class="copy wide-copy"><input readonly value="<?= e(implode('  ', $showCodes)) ?>"><button type="button" class="icon-btn" data-copy aria-label="Copy all codes"><?= icon('content_copy') ?></button></span>
</section>
<?php endif; ?>

<div class="grid2">
<form method="post" class="panel form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="password">
  <div class="panel-head"><div><h2>Change password</h2><p class="muted"><?= $user['password_changed_at'] ? 'Last changed ' . e(local_time($user['password_changed_at'], 'M j, Y')) . '.' : 'Not changed since setup.' ?> At least 12 characters.</p></div></div>
  <input type="email" name="username" value="<?= e($user['email']) ?>" autocomplete="username" hidden>
  <label class="field">Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
  <label class="field">New password<input type="password" name="new_password" required minlength="12" autocomplete="new-password"></label>
  <label class="field">Repeat new password<input type="password" name="confirm_password" required minlength="12" autocomplete="new-password"></label>
  <div class="actions"><button class="btn filled" data-press><?= icon('key') ?>Change password</button></div>
</form>

<section class="panel">
  <div class="panel-head"><div><h2>Two-step verification</h2><p class="muted">Asks for a 6-digit code from your phone after your password.</p></div>
    <?= (int) $user['totp_enabled'] ? '<span class="chip-status ok">' . icon('verified_user', 'fill') . 'On</span>' : '<span class="chip-status idle">' . icon('shield') . 'Off</span>' ?></div>
  <?php if ((int) $user['totp_enabled']): ?>
    <p><?= $codesLeft ?> recovery code<?= $codesLeft === 1 ? '' : 's' ?> left.</p>
    <form method="post" class="form"><?= csrf_field() ?>
      <div class="two"><label class="field">Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
        <label class="field">Code from your app<input name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" required autocomplete="one-time-code"></label></div>
      <div class="actions"><button name="action" value="new_codes" class="btn tonal" data-press>Create new recovery codes</button>
        <button name="action" value="disable_2fa" class="btn text danger" data-confirm="Turn off two-step verification?" data-press>Turn off</button></div>
    </form>
  <?php elseif ($pending): ?>
    <ol class="steps">
      <li>Open Google Authenticator, Microsoft Authenticator or your password manager and scan this code.</li>
      <li>Enter the 6-digit code it shows.</li>
    </ol>
    <div class="qr-row"><div class="qr" data-qr="<?= e(totp_uri($pending, $user['email'])) ?>" role="img" aria-label="QR code for your authenticator app"></div>
      <div><p class="small muted">Can’t scan? Enter this key:</p><code class="key"><?= e(trim(chunk_split($pending, 4, ' '))) ?></code></div></div>
    <form method="post" class="form"><?= csrf_field() ?>
      <label class="field">6-digit code<input name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" required autofocus autocomplete="one-time-code"></label>
      <div class="actions"><button name="action" value="confirm_2fa" class="btn filled" data-press><?= icon('verified_user') ?>Turn on</button>
        <button name="action" value="cancel_2fa" class="btn text" formnovalidate data-press>Cancel</button></div>
    </form>
  <?php else: ?>
    <form method="post"><?= csrf_field() ?><div class="actions"><button name="action" value="start_2fa" class="btn filled" data-press><?= icon('shield') ?>Set up two-step verification</button></div></form>
  <?php endif; ?>
</section>
</div>

<section class="panel">
  <div class="panel-head"><div><h2>Sign-in activity</h2><p class="muted">Last 10 attempts for your email. Sessions end after 2 hours idle or 12 hours in total.</p></div>
    <form method="post"><?= csrf_field() ?><button name="action" value="signout_others" class="btn tonal small" data-press><?= icon('logout') ?>Sign out other browsers</button></form></div>
  <table class="t"><tr><th>When</th><th>Result</th><th>IP address</th></tr>
  <?php foreach ($attempts as $at): ?><tr><td><?= e(local_time($at['created_at'], 'M j, H:i')) ?></td>
    <td><?= $at['success'] ? '<span class="badge good">Signed in</span>' : '<span class="badge bad">Failed</span>' ?></td><td><code><?= e($at['ip']) ?></code></td></tr><?php endforeach; ?>
  <?php if (!$attempts): ?><tr><td colspan="3" class="muted">No sign-ins recorded yet.</td></tr><?php endif; ?></table>
</section>

<section class="panel">
  <div class="panel-head"><div><h2>Server checks</h2><p class="muted">Settings outside the app that affect security.</p></div></div>
  <ul class="checklist">
    <?php foreach ([
        ['Installer locked (it answers “not found” once an admin exists)', true],
        ['Config file stored outside public_html (outreach-config.php)', str_ends_with(CONFIG_FILE, 'outreach-config.php') && !str_contains(CONFIG_FILE, '/public_html/')],
        ['Served over HTTPS', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'],
        ['Two-step verification on', (int) $user['totp_enabled'] === 1],
        ['Debug mode off', !cfg('debug')],
    ] as [$label, $ok]): ?>
      <li class="<?= $ok ? 'done' : 'todo' ?>"><?= icon($ok ? 'check_circle' : 'radio_button_unchecked', $ok ? 'fill' : '') ?><span><?= e($label) ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>
<script src="<?= rel('assets/qrcode.js') ?>?v=<?= APP_VERSION ?>"></script>
<?php page_footer();
