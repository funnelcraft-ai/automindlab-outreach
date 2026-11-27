<?php
declare(strict_types=1);

const SESSION_IDLE_SECONDS = 2 * 3600;       // client app: sign out after 2 h without activity
const SESSION_MAX_SECONDS = 12 * 3600;       // and after 12 h in any case
const ADMIN_IDLE_SECONDS = 30 * 60;          // Admin console: 30 min idle
const ADMIN_MAX_SECONDS = 8 * 3600;          // and 8 h in any case
const HANDOFF_SECONDS = 60;                  // a console → workspace hand-off link works once, for 60 s

/**
 * Two separate areas with separate sign-ins and sessions:
 *   'app'   — the client workspace app at the site root (cookie amlo_sess)
 *   'admin' — the Admin console under /admin/ (cookie amlo_admin, sent only to /admin/)
 * Pages in /admin/ define APP_AREA = 'admin' before loading the bootstrap.
 */
function app_area(): string { return defined('APP_AREA') && APP_AREA === 'admin' ? 'admin' : 'app'; }
function app_base_path(): string { return rtrim((string) (parse_url((string) cfg('base_url'), PHP_URL_PATH) ?? ''), '/'); }
const LOGIN_WINDOW_SECONDS = 15 * 60;
const LOGIN_MAX_FAILS_PER_IP = 10;
const LOGIN_MAX_FAILS_PER_EMAIL = 5;

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') return;
    $admin = app_area() === 'admin';
    session_name($admin ? 'amlo_admin' : 'amlo_sess');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => $admin ? app_base_path() . '/admin/' : '/', 'httponly' => true, 'samesite' => $admin ? 'Strict' : 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

function client_ip(): string {
    // REMOTE_ADDR only: forwarded headers can be forged by the client.
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function current_user(): ?array {
    start_session();
    if (empty($_SESSION['uid'])) return null;
    $now = time();
    $admin = app_area() === 'admin';
    $expired = ($now - (int) ($_SESSION['seen'] ?? 0)) > ($admin ? ADMIN_IDLE_SECONDS : SESSION_IDLE_SECONDS)
        || ($now - (int) ($_SESSION['started'] ?? 0)) > ($admin ? ADMIN_MAX_SECONDS : SESSION_MAX_SECONDS);
    $u = row('SELECT id, email, name, totp_enabled, session_version, is_platform_admin, last_tenant_id FROM users WHERE id = ?', [$_SESSION['uid']]);
    if ($expired || !$u || (int) $u['session_version'] !== (int) ($_SESSION['sv'] ?? 0)
        || ($admin && !(int) $u['is_platform_admin'])                                   // console: platform admins only
        || (!$admin && (int) $u['is_platform_admin'] && empty($_SESSION['via_admin']))  // app: admins only arrive through the console
        || (!$admin && !empty($_SESSION['via_admin']) && !(int) $u['is_platform_admin'])) {
        end_session();
        return null;
    }
    $_SESSION['seen'] = $now;
    $GLOBALS['CURRENT_USER'] = $u;
    return $u;
}

/** Signed-in user with a workspace selected; $minRole guards the whole page. */
function require_login(string $minRole = 'viewer'): array {
    $u = current_user();
    if (!$u) { header('Location: login.php'); exit; }
    // The Admin console requires two-step verification (only the Account page is reachable until it's on).
    if (app_area() === 'admin' && cfg('admin_require_2fa', true) && !(int) $u['totp_enabled']
        && !in_array(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), ['account.php', 'logout.php'], true)) {
        header('Location: account.php?need2fa=1'); exit;
    }
    $t = resolve_workspace($u);
    if ($t === null) {
        http_response_code(403);
        exit('You don’t belong to any workspace yet. Ask your agency to invite you.');
    }
    $_SESSION['tid'] = $t;
    set_tenant($t);
    if (!can($minRole) && !($minRole === 'viewer' && current_role())) deny();
    return $u;
}

function end_session(): void {
    start_session();
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) { session_regenerate_id(true); }
}

/* ---------- brute-force protection ---------- */
function login_blocked(string $email): ?string {
    $since = gmdate('Y-m-d H:i:s', time() - LOGIN_WINDOW_SECONDS);
    $ipFails = (int) val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at >= ?', [client_ip(), $since]);
    $emFails = $email === '' ? 0 : (int) val('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0 AND created_at >= ?', [$email, $since]);
    if ($ipFails >= LOGIN_MAX_FAILS_PER_IP || $emFails >= LOGIN_MAX_FAILS_PER_EMAIL) {
        return 'Too many failed attempts. Wait 15 minutes, then try again.';
    }
    return null;
}
function record_login_attempt(string $email, bool $ok): void {
    insert('login_attempts', ['ip' => client_ip(), 'email' => mb_substr($email, 0, 190), 'success' => $ok ? 1 : 0]);
    if (random_int(1, 50) === 1) q('DELETE FROM login_attempts WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
    if (!$ok) usleep(random_int(300000, 700000)); // slow down guessing
}

/**
 * Step 1 of sign-in. Returns 'ok', '2fa' (code needed), or an error message.
 */
function attempt_login(string $email, string $password): string {
    start_session();
    $email = strtolower(trim($email));
    if ($b = login_blocked($email)) return $b;
    $u = row('SELECT * FROM users WHERE email = ?', [$email]);
    // verify against a dummy hash when the user doesn't exist, so timing doesn't reveal valid emails
    $hash = $u['password_hash'] ?? '$2y$10$QBm.h4kBUVLBQkFLsaFXHu2wDAVpTaEku4./LXIj0GAN3KMylY3ne';
    if (!password_verify($password, $hash) || !$u) {
        record_login_attempt($email, false);
        return 'That email and password don’t match. Try again.';
    }
    // Each sign-in page only accepts its own kind of account.
    if (app_area() === 'admin' && !(int) $u['is_platform_admin']) {
        record_login_attempt($email, false);
        return 'That email and password don’t match. Try again.';              // don't reveal that the account exists
    }
    if (app_area() === 'app' && (int) $u['is_platform_admin']) {
        return 'This is a platform admin account. Sign in at the Admin console instead: ' . base_url('admin/');
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$u['id']]);
    }
    if ((int) $u['totp_enabled'] === 1) {
        session_regenerate_id(true);
        $_SESSION['pending_uid'] = (int) $u['id'];
        $_SESSION['pending_at'] = time();
        return '2fa';
    }
    finish_login($u);
    record_login_attempt($email, true);
    return 'ok';
}

/** Step 2 of sign-in when two-factor is on. */
function attempt_2fa(string $code): string {
    start_session();
    $uid = (int) ($_SESSION['pending_uid'] ?? 0);
    if (!$uid || time() - (int) ($_SESSION['pending_at'] ?? 0) > 300) { unset($_SESSION['pending_uid']); return 'expired'; }
    $u = row('SELECT * FROM users WHERE id = ?', [$uid]);
    if (!$u) return 'expired';
    if ($b = login_blocked($u['email'])) return $b;
    $code = preg_replace('/\s+/', '', $code);
    // A code works once per account (not just per browser): the last used time step is kept on the user row.
    $ok = totp_verify(decrypt_str((string) $u['totp_secret']), $code, max((int) ($u['totp_last_step'] ?? 0), (int) ($_SESSION['last_totp_step'] ?? 0)));
    if ($ok) q('UPDATE users SET totp_last_step = ? WHERE id = ?', [(int) $_SESSION['last_totp_step'], $u['id']]);
    if (!$ok && ($remaining = use_recovery_code($u, $code)) !== null) {
        $ok = true;
        log_event(null, 'security', "Recovery code used for {$u['email']} — $remaining left", 'warn');
    }
    if (!$ok) { record_login_attempt($u['email'], false); return 'That code isn’t right. Check your authenticator app and try again.'; }
    unset($_SESSION['pending_uid'], $_SESSION['pending_at']);
    finish_login($u);
    record_login_attempt($u['email'], true);
    return 'ok';
}

function finish_login(array $u): void {
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $u['id'];
    $_SESSION['sv'] = (int) $u['session_version'];
    $_SESSION['started'] = $_SESSION['seen'] = time();
}

/** Where to go after signing in: a pending invite link, otherwise the dashboard. Only local invite paths are accepted. */
function after_login_target(): string {
    $t = (string) ($_SESSION['after_login'] ?? '');
    unset($_SESSION['after_login']);
    if (app_area() === 'app' && preg_match('/^invite\.php\?t=[a-f0-9]{48}$/', $t)) return $t;
    return 'index.php';            // the app dashboard, or — in /admin/ — the console overview
}

/* ---------- passwords ---------- */
function password_problem(string $pw, string $email = ''): ?string {
    if (strlen($pw) < 12) return 'Use at least 12 characters.';
    if (strlen($pw) > 200) return 'Use at most 200 characters.';
    $low = strtolower($pw);
    if ($email !== '' && str_contains($low, strtolower(strtok($email, '@')))) return 'Don’t include your email name in the password.';
    foreach (['password', '123456', 'qwerty', 'automindlab', 'outreach', 'letmein', 'welcome'] as $weak) {
        if (str_contains($low, $weak)) return 'That password is too easy to guess. Avoid common words like “' . $weak . '”.';
    }
    if (count(array_unique(str_split($pw))) < 6) return 'Use more varied characters.';
    return null;
}

/** Change the password and sign out every other session. */
function change_password(int $uid, string $current, string $new): ?string {
    $u = row('SELECT * FROM users WHERE id = ?', [$uid]);
    if ($u && ($b = login_blocked((string) $u['email']))) return $b;     // a stolen session mustn't be able to guess the current password without limit
    if (!$u || !password_verify($current, $u['password_hash'])) { record_login_attempt((string) ($u['email'] ?? ''), false); return 'Your current password isn’t right.'; }
    if ($p = password_problem($new, $u['email'])) return $p;
    if (password_verify($new, $u['password_hash'])) return 'Choose a password you haven’t used here before.';
    q('UPDATE users SET password_hash = ?, password_changed_at = ?, session_version = session_version + 1 WHERE id = ?',
        [password_hash($new, PASSWORD_DEFAULT), now(), $uid]);
    $_SESSION['sv'] = (int) $u['session_version'] + 1;          // keep this session, end the others
    session_regenerate_id(true);
    log_event(null, 'security', "Password changed for {$u['email']}; other sessions signed out", 'warn');
    return null;
}

/* ---------- CSRF, flash ---------- */
function csrf_token(): string {
    start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
function verify_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(csrf_token(), (string) ($_POST['_csrf'] ?? ''))) {
        http_response_code(400);
        exit('This form expired. Go back, reload the page and try again.');
    }
}
function flash(?string $msg = null, string $type = 'ok'): ?array {
    start_session();
    if ($msg !== null) { $_SESSION['flash'][] = [$type, $msg]; return null; }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}
function redirect(string $to): void { header('Location: ' . $to); exit; }

/* ---------- Admin console → workspace hand-off ---------- */
/** Create a one-time link that opens $tenantId in the client app as this platform admin. */
function create_handoff(int $userId, int $tenantId, string $next): string {
    $token = bin2hex(random_bytes(24));
    q('DELETE FROM login_handoffs WHERE expires_at < ?', [now()]);
    insert('login_handoffs', ['token_hash' => hash('sha256', $token), 'user_id' => $userId, 'tenant_id' => $tenantId,
        'next' => mb_substr($next, 0, 120), 'expires_at' => gmdate('Y-m-d H:i:s', time() + HANDOFF_SECONDS)]);
    return $token;
}
/** Use a hand-off link (app side). Returns the row, or null if unknown, used or expired. */
function use_handoff(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
    $h = row('SELECT * FROM login_handoffs WHERE token_hash = ?', [hash('sha256', $token)]);
    if (!$h || $h['used_at'] || $h['expires_at'] < now()) return null;
    if (q('UPDATE login_handoffs SET used_at = ? WHERE id = ? AND used_at IS NULL', [now(), $h['id']])->rowCount() !== 1) return null;
    return $h;
}
/** Link to an Admin console page from either area. */
function admin_href(string $p = ''): string { return (app_area() === 'admin' ? '' : 'admin/') . $p; }
/** Link to a site-root file (assets, app pages) from either area. */
function rel(string $p): string { return (app_area() === 'admin' ? '../' : '') . $p; }
