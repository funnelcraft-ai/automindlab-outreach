<?php
declare(strict_types=1);

/*
 * Multi-tenancy: every client is a workspace (tenant). All outreach data carries tenant_id.
 * Platform admins (you) manage workspaces and the shared Google Places + AI keys;
 * each workspace has its own campaigns, leads, inboxes, settings and team.
 */

const ROLE_RANK = ['viewer' => 1, 'member' => 2, 'admin' => 3, 'owner' => 4];
const ROLE_LABELS = ['viewer' => 'Viewer', 'member' => 'Member', 'admin' => 'Admin', 'owner' => 'Owner'];
const ROLE_HELP = [
    'viewer' => 'Can see everything, change nothing.',
    'member' => 'Reviews and approves leads, edits drafts, answers replies.',
    'admin' => 'Also manages campaigns, inboxes, workspace settings and the team.',
    'owner' => 'Full control, including other owners and admins.',
];

/** Settings that belong to a workspace (everything else is platform-wide). */
const TENANT_SETTING_KEYS = ['company_name', 'legal_name', 'postal_address', 'website', 'cal_link', 'cal_prefill', 'notify_email', 'sender_role',
    'send_days', 'send_start', 'send_end', 'min_gap_min', 'max_gap_min', 'test_redirect', 'dry_run', 'sending_paused',
    'canada_strict', 'include_unsub_link', 'cal_webhook_secret', 'webhook_key', 'last_run_at', 'ws_api_status', 'quota_note', 'li_daily_cap'];

function tenant_setting_defaults(): array {
    return [
        'company_name' => (string) (tenant()['name'] ?? ''), 'legal_name' => '', 'postal_address' => '', 'website' => '',
        'cal_link' => '', 'cal_prefill' => '1', 'notify_email' => '', 'sender_role' => 'Founder',
        'send_days' => '1,2,3,4,5', 'send_start' => '08:30', 'send_end' => '16:30', 'min_gap_min' => '6', 'max_gap_min' => '14',
        'test_redirect' => '', 'dry_run' => '0', 'sending_paused' => '1',   // new workspaces start with sending paused
        'canada_strict' => '1', 'include_unsub_link' => '1', 'cal_webhook_secret' => '', 'webhook_key' => '', 'last_run_at' => '', 'li_daily_cap' => '20',
    ];
}

function tid(): int { return (int) ($GLOBALS['TENANT_ID'] ?? 0); }

function set_tenant(?int $id): void {
    $GLOBALS['TENANT_ID'] = (int) $id;
    $GLOBALS['TENANT_ROW'] = null;
    $GLOBALS['TENANT_SETTINGS'] = null;
}

function tenant(): ?array {
    if (!tid()) return null;
    if (empty($GLOBALS['TENANT_ROW']) || (int) $GLOBALS['TENANT_ROW']['id'] !== tid()) {
        $GLOBALS['TENANT_ROW'] = row('SELECT * FROM tenants WHERE id = ?', [tid()]);
    }
    return $GLOBALS['TENANT_ROW'];
}

function tenant_setting(string $k, $default = null) {
    if (!tid()) {
        // Outside a workspace (sign-in, invite pages) the agency's own workspace (#1) provides the brand name.
        if ($k === 'company_name') return (string) (val("SELECT v FROM tenant_settings WHERE tenant_id = 1 AND k = 'company_name'") ?: val('SELECT name FROM tenants WHERE id = 1') ?: '');
        return tenant_setting_defaults()[$k] ?? $default;
    }
    if (!is_array($GLOBALS['TENANT_SETTINGS'] ?? null)) {
        $GLOBALS['TENANT_SETTINGS'] = [];
        foreach (all('SELECT k, v FROM tenant_settings WHERE tenant_id = ?', [tid()]) as $r) $GLOBALS['TENANT_SETTINGS'][$r['k']] = $r['v'];
    }
    return $GLOBALS['TENANT_SETTINGS'][$k] ?? (tenant_setting_defaults()[$k] ?? $default);
}

function set_tenant_setting(string $k, $v, ?int $tenantId = null): void {
    $t = $tenantId ?? tid();
    if (!$t) throw new RuntimeException('No workspace selected.');
    q('INSERT INTO tenant_settings (tenant_id, k, v) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$t, $k, (string) $v]);
    if ($t === tid()) $GLOBALS['TENANT_SETTINGS'] = null;
}

/* ---------- roles ---------- */
function is_platform_admin(?array $u = null): bool {
    $u = $u ?? ($GLOBALS['CURRENT_USER'] ?? null);
    return $u && (int) ($u['is_platform_admin'] ?? 0) === 1;
}

function current_role(): ?string {
    $u = $GLOBALS['CURRENT_USER'] ?? null;
    if (!$u || !tid()) return null;
    if (is_platform_admin($u)) return 'owner';
    if (!array_key_exists('ROLE', $GLOBALS) || ($GLOBALS['ROLE_TID'] ?? 0) !== tid()) {
        $GLOBALS['ROLE'] = val('SELECT role FROM memberships WHERE user_id = ? AND tenant_id = ?', [$u['id'], tid()]) ?: null;
        $GLOBALS['ROLE_TID'] = tid();
    }
    return $GLOBALS['ROLE'];
}

function can(string $role): bool {
    $r = current_role();
    if (!$r) return false;
    if (($t = tenant()) && $t['status'] === 'suspended' && !is_platform_admin()) return false; // suspended = read-only
    return ROLE_RANK[$r] >= ROLE_RANK[$role];
}

/** Where to send someone back to after a refused POST: the page they came from if it's on this site, else the dashboard (never an outside URL). */
function safe_back(): string {
    $p = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    $mine = strtolower((string) parse_url((string) cfg('base_url'), PHP_URL_HOST));
    if (!$p || $mine === '' || strtolower((string) ($p['host'] ?? '')) !== $mine || !in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true)) return 'index.php';
    return '/' . ltrim((string) ($p['path'] ?? ''), '/\\') . (isset($p['query']) ? '?' . $p['query'] : '');   // a single leading slash: "//evil.test" would be an outside link
}

function deny(string $msg = 'You don’t have permission to do that in this workspace.'): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { flash($msg, 'err'); redirect(safe_back()); }
    http_response_code(403);
    page_header('Not allowed', '');
    echo '<section class="panel"><p>' . e($msg) . '</p><p><a class="btn tonal" href="index.php">Back to dashboard</a></p></section>';
    page_footer();
    exit;
}
function require_role(string $role): void { if (!can($role)) deny(); }
function require_platform_admin(): void { if (!is_platform_admin()) deny('Only platform admins can open this page.'); }

/* ---------- workspace selection ---------- */
function user_workspaces(array $u): array {
    if (is_platform_admin($u)) {
        return all("SELECT t.*, 'owner' AS role FROM tenants t ORDER BY t.status = 'active' DESC, t.name");
    }
    return all('SELECT t.*, m.role FROM memberships m JOIN tenants t ON t.id = m.tenant_id WHERE m.user_id = ? ORDER BY t.name', [$u['id']]);
}

/** Pick the workspace for this request: the one in the session if allowed, else the user's last or first one. */
function resolve_workspace(array $u): ?int {
    $allowed = array_map(fn($t) => (int) $t['id'], user_workspaces($u));
    if (!$allowed) return null;
    foreach ([(int) ($_SESSION['tid'] ?? 0), (int) ($u['last_tenant_id'] ?? 0)] as $cand) {
        if ($cand && in_array($cand, $allowed, true)) return $cand;
    }
    return $allowed[0];
}

function switch_workspace(int $tenantId): bool {
    $u = $GLOBALS['CURRENT_USER'] ?? null;
    if (!$u) return false;
    $allowed = array_map(fn($t) => (int) $t['id'], user_workspaces($u));
    if (!in_array($tenantId, $allowed, true)) return false;
    $_SESSION['tid'] = $tenantId;
    update('users', ['last_tenant_id' => $tenantId], 'id = ?', [$u['id']]);
    set_tenant($tenantId);
    return true;
}

/* ---------- quotas ---------- */
function tenant_month_leads(?int $t = null): int {
    return (int) val('SELECT COUNT(*) FROM leads WHERE tenant_id = ? AND created_at >= ?', [$t ?? tid(), gmdate('Y-m-01 00:00:00')]);
}
function quota_status(?int $t = null): array {
    $t = $t ?? tid();
    $row = row('SELECT * FROM tenants WHERE id = ?', [$t]) ?? [];
    return [
        'leads_used' => tenant_month_leads($t), 'leads_limit' => (int) ($row['quota_leads'] ?? 0),
        'ai_used' => month_usage('ai:', 'cost_usd', $t), 'ai_limit' => (float) ($row['quota_ai_usd'] ?? 0),
        'mailboxes_used' => (int) val('SELECT COUNT(*) FROM mailboxes WHERE tenant_id = ?', [$t]), 'mailboxes_limit' => (int) ($row['quota_mailboxes'] ?? 0),
    ];
}

/** Fetch a row that must belong to the current workspace (guards every ?id= in the UI). */
function owned_row(string $table, int $id): ?array {
    if (!in_array($table, TENANT_TABLES, true)) throw new InvalidArgumentException('not a workspace table');
    return row("SELECT * FROM `$table` WHERE id = ? AND tenant_id = ?", [$id, tid()]);
}

/* ---------- invitations ---------- */
const INVITE_DAYS = 7;

/** Create (or refresh) an invitation and return the one-time link. Only the token's hash is stored. */
function create_invite(int $tenantId, string $email, string $role, ?int $byUser = null): string {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
    if (!isset(ROLE_RANK[$role])) throw new RuntimeException('Pick a role.');
    $token = bin2hex(random_bytes(24));
    q('DELETE FROM invitations WHERE tenant_id = ? AND email = ? AND accepted_at IS NULL', [$tenantId, $email]);
    insert('invitations', ['tenant_id' => $tenantId, 'email' => $email, 'role' => $role, 'token_hash' => hash('sha256', $token),
        'invited_by' => $byUser, 'expires_at' => gmdate('Y-m-d H:i:s', time() + INVITE_DAYS * 86400)]);
    return base_url('invite.php?t=' . $token);
}

function find_invite(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
    return row('SELECT i.*, t.name AS tenant_name, t.status AS tenant_status FROM invitations i JOIN tenants t ON t.id = i.tenant_id
                WHERE i.token_hash = ? AND i.accepted_at IS NULL AND i.expires_at > ?', [hash('sha256', $token), now()]);
}

function accept_invite(array $inv, int $userId): void {
    q('INSERT INTO memberships (user_id, tenant_id, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE role = VALUES(role)', [$userId, $inv['tenant_id'], $inv['role']]);
    q('UPDATE invitations SET accepted_at = ? WHERE id = ?', [now(), $inv['id']]);
    update('users', ['last_tenant_id' => $inv['tenant_id']], 'id = ?', [$userId]);
    $_SESSION['tid'] = (int) $inv['tenant_id'];
    $prev = tid(); set_tenant((int) $inv['tenant_id']);
    log_event(null, 'team', $inv['email'] . ' joined as ' . (ROLE_LABELS[$inv['role']] ?? $inv['role']));
    set_tenant($prev);
}

/** Owners in a workspace, counting only real memberships (platform admins are extra). */
function owner_count(int $tenantId): int {
    return (int) val("SELECT COUNT(*) FROM memberships WHERE tenant_id = ? AND role = 'owner'", [$tenantId]);
}
