<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '2.4.2');
define('SCHEMA_VERSION', 8);

// Config can live OUTSIDE the web root (recommended): ../outreach-config.php next to public_html.
// Look just above the web root (the folder that contains public_html), else next to the app folder.
$__pos = strpos(APP_ROOT, '/public_html');
$__outside = $__pos !== false ? substr(APP_ROOT, 0, $__pos) : dirname(APP_ROOT);
$__cfgPaths = [$__outside . '/outreach-config.php', APP_ROOT . '/config.php'];
$__cfgFile = null;
foreach ($__cfgPaths as $__p) { if (is_file($__p)) { $__cfgFile = $__p; break; } }
if ($__cfgFile === null) {
    http_response_code(500);
    exit("Configuration missing. Copy config.sample.php to ../outreach-config.php (recommended) or config.php and fill it in.\n");
}
define('CONFIG_FILE', $__cfgFile);
$GLOBALS['CONFIG'] = require $__cfgFile;

date_default_timezone_set('UTC'); // all stored times are UTC
if (!empty($GLOBALS['CONFIG']['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT . '/storage/php-error.log');
// Stack traces are stored in the error log and sent in alerts: keep function arguments (database, inbox and user passwords) out of them.
ini_set('zend.exception_ignore_args', '1');

function cfg(string $key, $default = null) { return $GLOBALS['CONFIG'][$key] ?? $default; }

/**
 * Local test mode (fixture websites on private addresses, mock Google Places, test webhooks over http).
 * It turns off protections, so it only takes effect when base_url is localhost, 127.0.0.1 or a *.test / *.localhost name.
 * Anywhere else it is ignored and reported as a critical problem.
 */
function local_testing(): bool {
    static $on = null;
    if ($on !== null) return $on;
    if (!cfg('local_testing', false)) return $on = false;
    $host = strtolower((string) parse_url((string) cfg('base_url'), PHP_URL_HOST));
    $on = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.test') || str_ends_with($host, '.localhost');
    if (!$on && function_exists('report_error')) {
        report_error("'local_testing' is set in the config file of a public site ($host). It has been ignored; remove it.", ['level' => 'critical', 'source' => 'system', 'fingerprint' => 'local-testing-on-live']);
    }
    return $on;
}

// Developer error log: installed before anything else so even a failed database connection is captured.
require_once __DIR__ . '/errors.php';
install_error_handlers();

/* ---------------- Database ---------------- */
function db(): PDO {
    static $pdo = null, $failed = null;
    if ($failed) throw new RuntimeException('Database unavailable', 0, $failed);
    if ($pdo === null) {
        $dsn = 'mysql:host=' . cfg('db_host') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4';
        try {
        $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        } catch (PDOException $ex) {
            // Can't use the error log (it lives in the database): write it to storage/ and alert via the config-file webhook.
            $failed = $ex; $pdo = null;
            error_fallback('Database connection failed: ' . $ex->getMessage(), ['level' => 'critical']);
            throw new RuntimeException('Database unavailable', 0, $ex);
        }
        $pdo->exec("SET time_zone = '+00:00'");
        $GLOBALS['DB_CONNECTED'] = true;
    }
    return $pdo;
}
function q(string $sql, array $p = []): PDOStatement {
    $t = microtime(true);
    try {
        $s = db()->prepare($sql); $s->execute($p);
    } catch (PDOException $ex) {
        // Many pages show an exception's message to the user. A raw database message names tables, columns and SQL, so the
        // full error goes to the developer log and the user gets only a reference.
        report_error($ex, ['level' => 'error', 'source' => PHP_SAPI === 'cli' ? 'cron' : 'web']);
        throw new RuntimeException('A database error occurred (ref ' . request_id() . '). Try again, and quote this reference if it keeps happening.', 0, $ex);
    }
    $GLOBALS['QUERY_COUNT'] = ($GLOBALS['QUERY_COUNT'] ?? 0) + 1;
    $ms = (microtime(true) - $t) * 1000;
    if ($ms >= SLOW_QUERY_MS) {
        $shape = mb_substr(preg_replace('/\s+/', ' ', $sql), 0, 300);
        report_error("Slow query ({$ms} ms): $shape", ['level' => 'warning', 'source' => 'slow', 'fingerprint' => 'slow-sql|' . md5($shape),
            'title' => 'Slow query: ' . mb_substr($shape, 0, 140), 'context' => ['ms' => (int) $ms, 'rows' => $s->rowCount()]]);
    }
    return $s;
}
function row(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r === false ? null : $r; }
function all(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function val(string $sql, array $p = []) { $v = q($sql, $p)->fetchColumn(); return $v === false ? null : $v; }
/** Tables whose rows belong to one workspace. */
const TENANT_TABLES = ['campaigns', 'search_queries', 'leads', 'emails', 'replies', 'bookings', 'mailboxes', 'suppression', 'events', 'api_usage', 'li_tasks'];

function insert(string $table, array $data): int {
    if (in_array($table, TENANT_TABLES, true) && !array_key_exists('tenant_id', $data)) {
        if (!tid() && !in_array($table, ['events', 'api_usage'], true)) throw new RuntimeException("No workspace selected for insert into $table");
        $data['tenant_id'] = tid();
    }
    $cols = array_keys($data);
    $sql = "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
    q($sql, array_values($data));
    return (int) db()->lastInsertId();
}
function update(string $table, array $data, string $where, array $wp = []): int {
    $set = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($data)));
    return q("UPDATE `$table` SET $set WHERE $where", array_merge(array_values($data), $wp))->rowCount();
}
function now(): string { return gmdate('Y-m-d H:i:s'); }

/* ---------------- Settings ---------------- */
const SECRET_SETTINGS = ['anthropic_key', 'openai_key', 'gemini_key', 'places_key', 'hunter_key', 'alert_webhooks'];

function setting_defaults(): array {
    return [
        'company_name'      => 'Automindlab',
        'legal_name'        => 'Revenue Pixels',
        'postal_address'    => '',
        'website'           => 'https://automindlab.io',
        'cal_link'          => 'https://cal.id/automindlab',
        'cal_prefill'       => '1',
        'notify_email'      => 'hello@automindlab.io',
        'sender_role'       => 'Founder',
        // sending behaviour
        'send_days'         => '1,2,3,4,5',      // ISO weekday numbers, in the LEAD's timezone
        'send_start'        => '08:30',
        'send_end'          => '16:30',
        'min_gap_min'       => '6',
        'max_gap_min'       => '14',
        'test_redirect'     => '',               // if set, every email goes to this address instead
        'dry_run'           => '0',              // 1 = log only, send nothing
        'sending_paused'    => '0',
        // compliance
        'canada_strict'     => '1',              // Canada: only emails published on the business's own site
        'include_unsub_link'=> '1',
        // AI
        'ai_provider'       => 'anthropic',      // anthropic | openai | gemini | mock
        'anthropic_model'   => 'claude-haiku-5-5',
        'openai_model'      => 'gpt-4o-mini',
        'gemini_model'      => 'gemini-2.5-flash',
        'anthropic_key'     => '', 'openai_key' => '', 'gemini_key' => '',
        // $ per 1M tokens (input/output) — used for the cost tracker; edit to match current pricing
        'price_anthropic'   => '1.00/5.00',
        'price_openai'      => '0.15/0.60',
        'price_gemini'      => '0.30/2.50',
        'ai_monthly_budget' => '8',
        // Lead sourcing
        'places_key'        => '',
        'places_monthly_cap'=> '950',            // Text Search Enterprise free tier is 1,000 calls/month
        'hunter_key'        => '',               // optional fallback email finder (US leads only)
        // Cron / webhooks
        'cron_key'          => '',
        'cal_webhook_secret'=> '',
        'last_cron_at'      => '',
        'installed_at'      => '',
        'schema_version'    => '1',
        'places_details_cap'=> '950',             // Place Details Enterprise free tier is also 1,000 calls/month
    ];
}

function setting(string $k, $default = null) {
    if (in_array($k, TENANT_SETTING_KEYS, true)) return tenant_setting($k, $default);
    static $cache = null;
    if ($cache === null || $k === '__reload__') {
        $cache = [];
        try { foreach (all('SELECT k, v FROM settings') as $r) $cache[$r['k']] = $r['v']; } catch (Throwable $e) {}
        if ($k === '__reload__') return null;
    }
    $v = $cache[$k] ?? (setting_defaults()[$k] ?? $default);
    if (in_array($k, SECRET_SETTINGS, true) && $v !== '' && $v !== null) $v = decrypt_str((string) $v);
    return $v ?? $default;
}
function set_setting(string $k, $v): void {
    if (in_array($k, TENANT_SETTING_KEYS, true)) { set_tenant_setting($k, $v); return; }
    $v = (string) $v;
    if (in_array($k, SECRET_SETTINGS, true) && $v !== '') $v = encrypt_str($v);
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
    setting('__reload__');
}

/* ---------------- Crypto (libsodium) ---------------- */
function app_key(): string {
    $k = base64_decode((string) cfg('app_key'), true);
    if ($k === false || strlen($k) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('config.php app_key must be a base64-encoded 32-byte key.');
    }
    return $k;
}
function encrypt_str(string $plain): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'enc:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, app_key()));
}
function decrypt_str(string $stored): string {
    if (strpos($stored, 'enc:') !== 0) return $stored;
    $raw = base64_decode(substr($stored, 4), true);
    if ($raw === false) return '';
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $out = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, app_key());
    return $out === false ? '' : $out;
}
function sign_token(string $data): string { return substr(hash_hmac('sha256', $data, app_key()), 0, 32); }

/* ---------------- Logging ---------------- */
function log_event(?int $leadId, string $type, string $detail = '', string $level = 'info'): void {
    try {
        insert('events', ['lead_id' => $leadId, 'type' => $type, 'level' => $level, 'detail' => mb_substr($detail, 0, 1000)]);
    } catch (Throwable $e) { error_log("log_event failed: " . $e->getMessage()); }
    if ($level === 'error') report_event($leadId, $type, $detail);
}
function track_usage(string $service, int $calls = 1, int $in = 0, int $out = 0, float $cost = 0.0): void {
    q('INSERT INTO api_usage (day, service, tenant_id, calls, tokens_in, tokens_out, cost_usd) VALUES (?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE calls = calls + VALUES(calls), tokens_in = tokens_in + VALUES(tokens_in),
       tokens_out = tokens_out + VALUES(tokens_out), cost_usd = cost_usd + VALUES(cost_usd)',
       [gmdate('Y-m-d'), $service, tid(), $calls, $in, $out, $cost]);
}
/** This month's usage. $tenant null = whole platform, otherwise one workspace. */
function month_usage(string $servicePrefix, string $col = 'calls', ?int $tenant = null): float {
    $col = in_array($col, ['calls', 'cost_usd', 'tokens_in', 'tokens_out'], true) ? $col : 'calls';
    // 'ai:' (ending in a colon) sums every AI provider; any other name is matched exactly
    $exact = !str_ends_with($servicePrefix, ':');
    $sql = "SELECT COALESCE(SUM($col),0) FROM api_usage WHERE day >= ? AND service " . ($exact ? '= ?' : 'LIKE ?');
    $p = [gmdate('Y-m-01'), $exact ? $servicePrefix : $servicePrefix . '%'];
    if ($tenant !== null) { $sql .= ' AND tenant_id = ?'; $p[] = $tenant; }
    return (float) val($sql, $p);
}

/* ---------------- HTTP ---------------- */
/** @return array{status:int, body:string, error:string, json:mixed, url:string} */
function http_request(string $method, string $url, array $headers = [], $body = null, int $timeout = 30): array {
    $ch = curl_init($url);
    $h = [];
    foreach ($headers as $k => $v) $h[] = is_int($k) ? $v : "$k: $v";
    if (is_array($body)) { $body = json_encode($body); $h[] = 'Content-Type: application/json'; }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; AutomindlabBot/1.0; +https://automindlab.io)',
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $resp = curl_exec($ch);
    $out = [
        'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'body' => $resp === false ? '' : (string) $resp,
        'error' => $resp === false ? curl_error($ch) : '',
        'url' => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
    ];
    curl_close($ch);
    $out['json'] = json_decode($out['body'], true);
    return $out;
}

/* ---------------- Small file cache (shared hosting has no Redis/APCu guarantee) ---------------- */
/** Return a cached value, or compute and store it for $ttl seconds. Keys are per workspace unless $global. */
function cache_remember(string $key, int $ttl, callable $fn, bool $global = false) {
    $dir = APP_ROOT . '/storage/cache';
    $file = $dir . '/' . ($global ? 'g' : 't' . tid()) . '-' . preg_replace('/[^a-z0-9_-]/i', '_', $key) . '.cache';
    if (is_file($file) && filemtime($file) > time() - $ttl) {
        $v = @unserialize((string) @file_get_contents($file), ['allowed_classes' => false]);
        if ($v !== false) return $v;
    }
    $v = $fn();
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $tmp = $file . '.' . getmypid();
    if (@file_put_contents($tmp, serialize($v)) !== false) @rename($tmp, $file);   // atomic swap: readers never see half a file
    return $v;
}
function cache_forget(string $key): void { @unlink(APP_ROOT . '/storage/cache/t' . tid() . '-' . preg_replace('/[^a-z0-9_-]/i', '_', $key) . '.cache'); }

/** Lead counts per campaign in one pass over the workspace's leads (instead of one sub-query per number per campaign). */
function campaign_stats(): array {
    $out = [];
    foreach (all("SELECT campaign_id, SUM(status <> 'no_website') leads, SUM(status = 'drafted') review, SUM(last_sent_at IS NOT NULL) contacted,
                  SUM(replied_at IS NOT NULL) replied, SUM(booked_at IS NOT NULL) booked FROM leads WHERE tenant_id = ? GROUP BY campaign_id", [tid()]) as $r) {
        $out[(int) $r['campaign_id']] = array_map('intval', $r);
    }
    foreach (all("SELECT campaign_id, COUNT(*) n FROM search_queries WHERE tenant_id = ? AND status = 'pending' GROUP BY campaign_id", [tid()]) as $r) {
        $out[(int) $r['campaign_id']]['pending_q'] = (int) $r['n'];
    }
    return $out;
}

/* ---------------- Misc helpers ---------------- */
function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function base_url(string $path = ''): string { return rtrim((string) cfg('base_url'), '/') . '/' . ltrim($path, '/'); }
function local_time(?string $utc, string $fmt = 'M j, H:i'): string {
    if (!$utc) return '—';
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone((string) cfg('app_timezone', 'UTC')));
    return $d->format($fmt);
}
/** Suppressed in this workspace, or globally (tenant 0: hard bounces apply to every workspace). */
function is_suppressed(string $email): bool {
    $email = strtolower(trim($email));
    $domain = substr(strrchr($email, '@') ?: '', 1);
    return (bool) val("SELECT 1 FROM suppression WHERE tenant_id IN (0, ?) AND ((type='email' AND value=?) OR (type='domain' AND value=?))", [tid(), $email, $domain]);
}
function suppress(string $value, string $reason, string $type = 'email', bool $global = false): void {
    q('INSERT IGNORE INTO suppression (tenant_id, value, type, reason) VALUES (?,?,?,?)', [$global ? 0 : tid(), strtolower(trim($value)), $type, $reason]);
}
function unsubscribe_url(int $leadId): string {
    return base_url('unsubscribe.php?l=' . $leadId . '&t=' . sign_token('unsub:' . $leadId));
}

foreach (['tenancy', 'auth', 'totp', 'providers', 'ai', 'leadfinder', 'enrich', 'mailer', 'imap', 'pipeline', 'presets', 'connections', 'linkedin', 'layout'] as $lib) {
    require_once __DIR__ . "/$lib.php";
}

/* ---------------- Security headers (all web responses) ---------------- */
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'; object-src 'none'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') header('Strict-Transport-Security: max-age=31536000');
}

/* ---------------- Schema migrations (run once, automatically) ---------------- */
function maybe_migrate(): void {
    if ((string) setting('installed_at') === '') return;           // not installed yet
    $v = (int) setting('schema_version', 1);
    if ($v >= SCHEMA_VERSION) return;
    $lock = fopen(APP_ROOT . '/storage/migrate.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
    try {
        if ($v < 2) migrate_v2();
        if ($v < 3) add_column_if_missing('mailboxes', 'provider', 'provider VARCHAR(30) NULL');
        if ($v < 4) migrate_v4();
        if ($v < 5) migrate_v5();
        if ($v < 6) migrate_v6();
        if ($v < 7) migrate_v7();
        if ($v < 8) migrate_v8();
        set_setting('schema_version', (string) SCHEMA_VERSION);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
function add_column_if_missing(string $table, string $col, string $ddl): void {
    $has = val('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $col]);
    if (!(int) $has) db()->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
}
function migrate_v2(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45) NOT NULL, email VARCHAR(190) NULL,
        success TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ip (ip, created_at), KEY idx_email (email, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    add_column_if_missing('users', 'totp_secret', 'totp_secret TEXT NULL');
    add_column_if_missing('users', 'totp_enabled', 'totp_enabled TINYINT(1) NOT NULL DEFAULT 0');
    add_column_if_missing('users', 'recovery_codes', 'recovery_codes TEXT NULL');
    add_column_if_missing('users', 'session_version', 'session_version INT NOT NULL DEFAULT 1');
    add_column_if_missing('users', 'password_changed_at', 'password_changed_at DATETIME NULL');
    add_column_if_missing('leads', 'search_location', 'search_location VARCHAR(190) NULL');
    // Google Maps Platform terms: only place_id may be stored. Remove Google-sourced fields from existing leads.
    q("UPDATE leads SET rating = NULL, reviews = NULL, maps_url = NULL, address = NULL, phone = NULL, category = NULL,
        business_name = IF(site_excerpt IS NULL OR site_excerpt = '', 'Pending research', business_name),
        website = IF(site_excerpt IS NULL OR site_excerpt = '', NULL, website),
        domain = IF(site_excerpt IS NULL OR site_excerpt = '', NULL, domain)");
    foreach (all("SELECT id, site_excerpt, website FROM leads WHERE site_excerpt IS NOT NULL AND site_excerpt <> ''") as $l) {
        $title = preg_match('/^Title: (.*)$/m', (string) $l['site_excerpt'], $m) ? trim($m[1]) : '';
        $name = $title !== '' ? name_from_title($title, (string) site_domain((string) $l['website'])) : null;
        update('leads', ['business_name' => mb_substr($name ?: (string) site_domain((string) $l['website']) ?: 'Unknown business', 0, 255)], 'id = ?', [$l['id']]);
    }
    log_event(null, 'migrate', 'Upgraded to schema v2: account security tables, Google Places data purge');
}
function index_exists(string $table, string $index): bool {
    return (bool) (int) val('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?', [$table, $index]);
}
/** v4: multi-tenant. Existing data becomes the first workspace; existing users become platform admins + owners. */
function migrate_v4(): void {
    $ddl = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    db()->exec("CREATE TABLE IF NOT EXISTS tenants (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL,
        status ENUM('active','suspended') NOT NULL DEFAULT 'active', plan VARCHAR(60) NOT NULL DEFAULT 'Starter',
        quota_leads INT NOT NULL DEFAULT 600, quota_ai_usd DECIMAL(8,2) NOT NULL DEFAULT 8.00, quota_mailboxes INT NOT NULL DEFAULT 3,
        notes TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) $ddl");
    db()->exec("CREATE TABLE IF NOT EXISTS memberships (user_id INT UNSIGNED NOT NULL, tenant_id INT UNSIGNED NOT NULL,
        role ENUM('owner','admin','member','viewer') NOT NULL DEFAULT 'member', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, tenant_id), KEY idx_tenant (tenant_id)) $ddl");
    db()->exec("CREATE TABLE IF NOT EXISTS invitations (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id INT UNSIGNED NOT NULL,
        email VARCHAR(190) NOT NULL, role ENUM('owner','admin','member','viewer') NOT NULL DEFAULT 'member', token_hash CHAR(64) NOT NULL,
        invited_by INT UNSIGNED NULL, expires_at DATETIME NOT NULL, accepted_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_token (token_hash), KEY idx_tenant (tenant_id)) $ddl");
    db()->exec("CREATE TABLE IF NOT EXISTS tenant_settings (tenant_id INT UNSIGNED NOT NULL, k VARCHAR(80) NOT NULL, v MEDIUMTEXT NULL,
        PRIMARY KEY (tenant_id, k)) $ddl");
    add_column_if_missing('users', 'is_platform_admin', 'is_platform_admin TINYINT(1) NOT NULL DEFAULT 0');
    add_column_if_missing('users', 'last_tenant_id', 'last_tenant_id INT UNSIGNED NULL');
    add_column_if_missing('users', 'name', 'name VARCHAR(120) NULL');

    if (!(int) val('SELECT COUNT(*) FROM tenants')) {
        $name = (string) (val("SELECT v FROM settings WHERE k = 'company_name'") ?: 'Automindlab');
        q('INSERT INTO tenants (id, name, plan, quota_leads, quota_ai_usd, quota_mailboxes) VALUES (1, ?, ?, 2000, 25, 10)', [$name, 'Internal']);
    }
    // tenant_id on every workspace table; existing rows go to workspace 1
    foreach (['campaigns', 'search_queries', 'leads', 'emails', 'replies', 'bookings', 'mailboxes', 'suppression'] as $t) {
        add_column_if_missing($t, 'tenant_id', 'tenant_id INT UNSIGNED NOT NULL DEFAULT 1 FIRST');
        db()->exec("ALTER TABLE `$t` ALTER COLUMN tenant_id DROP DEFAULT");          // new rows must name their workspace
        if (!index_exists($t, 'idx_tenant')) db()->exec("ALTER TABLE `$t` ADD KEY idx_tenant (tenant_id)");
    }
    add_column_if_missing('events', 'tenant_id', 'tenant_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER id');
    db()->exec('UPDATE events SET tenant_id = 1 WHERE tenant_id = 0');
    if (!index_exists('events', 'idx_tenant')) db()->exec('ALTER TABLE events ADD KEY idx_tenant (tenant_id, id)');
    add_column_if_missing('api_usage', 'tenant_id', 'tenant_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER service');
    db()->exec('UPDATE api_usage SET tenant_id = 1 WHERE tenant_id = 0');
    // uniqueness is now per workspace
    if (index_exists('leads', 'uq_place')) db()->exec('ALTER TABLE leads DROP INDEX uq_place, ADD UNIQUE KEY uq_place (tenant_id, place_id)');
    if (index_exists('api_usage', 'uq_day_service')) db()->exec('ALTER TABLE api_usage DROP INDEX uq_day_service, ADD UNIQUE KEY uq_day_service (day, service, tenant_id)');
    $pk = all("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppression' AND CONSTRAINT_NAME = 'PRIMARY'");
    if (count($pk) === 1) db()->exec('ALTER TABLE suppression DROP PRIMARY KEY, ADD PRIMARY KEY (tenant_id, value)');
    db()->exec("UPDATE suppression SET tenant_id = 0 WHERE reason = 'bounce'");      // bounces apply to every workspace

    // workspace-level settings move from the platform table to workspace 1 (with the values v1–v3 used as defaults)
    $old = ['company_name' => 'Automindlab', 'legal_name' => 'Revenue Pixels', 'postal_address' => '', 'website' => 'https://automindlab.io',
        'cal_link' => 'https://cal.id/automindlab', 'cal_prefill' => '1', 'notify_email' => 'hello@automindlab.io', 'sender_role' => 'Founder',
        'send_days' => '1,2,3,4,5', 'send_start' => '08:30', 'send_end' => '16:30', 'min_gap_min' => '6', 'max_gap_min' => '14',
        'test_redirect' => '', 'dry_run' => '0', 'sending_paused' => '0', 'canada_strict' => '1', 'include_unsub_link' => '1', 'cal_webhook_secret' => ''];
    foreach ($old as $k => $def) {
        $v = val('SELECT v FROM settings WHERE k = ?', [$k]);
        q('INSERT IGNORE INTO tenant_settings (tenant_id, k, v) VALUES (1, ?, ?)', [$k, $v === null ? $def : $v]);
        q('DELETE FROM settings WHERE k = ?', [$k]);
    }
    // keep the existing cal.id webhook URL (platform cron key) working for workspace 1
    q('INSERT IGNORE INTO tenant_settings (tenant_id, k, v) VALUES (1, ?, ?)', ['webhook_key', (string) (val("SELECT v FROM settings WHERE k = 'cron_key'") ?: bin2hex(random_bytes(16)))]);
    // existing users: platform admins and owners of workspace 1
    q('UPDATE users SET is_platform_admin = 1, last_tenant_id = 1');
    q("INSERT IGNORE INTO memberships (user_id, tenant_id, role) SELECT id, 1, 'owner' FROM users");
    try { insert('events', ['tenant_id' => 1, 'lead_id' => null, 'type' => 'migrate', 'level' => 'info', 'detail' => 'Upgraded to multi-workspace (schema v4); existing data is in workspace 1']); } catch (Throwable $e) {}
}
/** v5: developer error log, cron run history, page timings, and composite indexes for many workspaces. */
function migrate_v5(): void {
    foreach (v5_tables() as $ddl) db()->exec($ddl);
    foreach (V5_INDEXES as [$table, $name, $cols]) if (!index_exists($table, $name)) db()->exec("ALTER TABLE `$table` ADD KEY `$name` ($cols)");
    if ((string) setting('health_key') === '') set_setting('health_key', bin2hex(random_bytes(12)));
}
/** v8: assisted LinkedIn outreach (tasks a person completes by hand; nothing is sent to LinkedIn by the app). */
function migrate_v8(): void {
    db()->exec(V8_TABLE);
    foreach (V8_COLUMNS as [$t, $c, $ddl]) add_column_if_missing($t, $c, $ddl);
    if (!index_exists('leads', 'idx_t_li_state')) db()->exec('ALTER TABLE `leads` ADD KEY `idx_t_li_state` (tenant_id, li_state)');
}
const V8_TABLE = "CREATE TABLE IF NOT EXISTS li_tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id INT UNSIGNED NOT NULL, lead_id INT UNSIGNED NOT NULL,
    kind ENUM('connect','message','followup') NOT NULL, body TEXT NULL, due_at DATETIME NOT NULL,
    status ENUM('pending','done','skipped','cancelled') NOT NULL DEFAULT 'pending', outcome VARCHAR(40) NULL,
    done_by INT UNSIGNED NULL, done_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_t_status_due (tenant_id, status, due_at), KEY idx_lead (lead_id), KEY idx_t_done (tenant_id, done_by, done_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
const V8_COLUMNS = [
    ['leads', 'linkedin_url', 'linkedin_url VARCHAR(300) NULL'],
    ['leads', 'linkedin_kind', "linkedin_kind ENUM('person','company') NULL"],
    ['leads', 'li_state', "li_state VARCHAR(12) NOT NULL DEFAULT 'none'"],
    ['leads', 'li_note', 'li_note TEXT NULL'],
    ['leads', 'li_msg1', 'li_msg1 TEXT NULL'],
    ['leads', 'li_msg2', 'li_msg2 TEXT NULL'],
    ['leads', 'li_requested_at', 'li_requested_at DATETIME NULL'],
    ['leads', 'li_connected_at', 'li_connected_at DATETIME NULL'],
    ['campaigns', 'linkedin_enabled', 'linkedin_enabled TINYINT(1) NOT NULL DEFAULT 0'],
    ['campaigns', 'li_connect_after_days', 'li_connect_after_days TINYINT UNSIGNED NOT NULL DEFAULT 1'],
    ['campaigns', 'li_followup_days', 'li_followup_days TINYINT UNSIGNED NOT NULL DEFAULT 4'],
    ['replies', 'channel', "channel VARCHAR(10) NOT NULL DEFAULT 'email'"],
];
/** v7: one-time links that open a client workspace from the Admin console (separate sign-ins). */
function migrate_v7(): void {
    db()->exec(V7_TABLE);
    add_column_if_missing('users', 'totp_last_step', 'totp_last_step BIGINT NOT NULL DEFAULT 0');
}
const V7_TABLE = "CREATE TABLE IF NOT EXISTS login_handoffs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, token_hash CHAR(64) NOT NULL, user_id INT UNSIGNED NOT NULL, tenant_id INT UNSIGNED NOT NULL,
    next VARCHAR(120) NULL, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token (token_hash), KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
/** v6: indexes for the Admin console's cross-workspace totals. */
function migrate_v6(): void {
    foreach (V6_INDEXES as [$table, $name, $cols]) if (!index_exists($table, $name)) db()->exec("ALTER TABLE `$table` ADD KEY `$name` ($cols)");
}
const V6_INDEXES = [
    ['emails', 'idx_status_sent_t', 'status, sent_at, tenant_id'],
    ['replies', 'idx_kind_received_t', 'kind, received_at, tenant_id'],
    ['events', 'idx_type_id', 'type, id'],
];
const V5_INDEXES = [
    ['leads', 'idx_t_status', 'tenant_id, status, id'],
    ['leads', 'idx_t_created', 'tenant_id, created_at'],
    ['leads', 'idx_t_updated', 'tenant_id, updated_at'],
    ['leads', 'idx_t_status_upd', 'tenant_id, status, updated_at'],
    ['leads', 'idx_t_domain', 'tenant_id, domain'],
    ['leads', 'idx_t_email', 'tenant_id, email'],
    ['leads', 'idx_c_status', 'campaign_id, status'],
    ['leads', 'idx_t_camp_cover', 'tenant_id, campaign_id, status, last_sent_at, replied_at, booked_at'],   // campaign stats without reading rows
    ['leads', 'idx_mb_status', 'mailbox_id, status, updated_at'],
    ['emails', 'idx_t_status_sent', 'tenant_id, status, sent_at'],
    ['emails', 'idx_t_status_sched', 'tenant_id, status, scheduled_for'],
    ['emails', 'idx_mb_status_sent', 'mailbox_id, status, sent_at'],
    ['replies', 'idx_t_kind_handled', 'tenant_id, kind, handled'],
    ['replies', 'idx_t_kind_received', 'tenant_id, kind, received_at'],
    ['replies', 'idx_mb_kind_received', 'mailbox_id, kind, received_at'],
    ['events', 'idx_t_level', 'tenant_id, level, id'],
    ['search_queries', 'idx_t_c_status', 'tenant_id, campaign_id, status'],
    ['bookings', 'idx_t_start', 'tenant_id, start_time'],
];
function v5_tables(): array {
    return [
        "CREATE TABLE IF NOT EXISTS error_groups (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, fingerprint CHAR(40) NOT NULL,
            level ENUM('notice','warning','error','critical') NOT NULL DEFAULT 'error', source VARCHAR(20) NOT NULL DEFAULT 'web',
            title VARCHAR(255) NOT NULL, message TEXT NULL, file VARCHAR(255) NULL, line INT NULL, trace MEDIUMTEXT NULL,
            count INT UNSIGNED NOT NULL DEFAULT 0, first_seen DATETIME NOT NULL, last_seen DATETIME NOT NULL, tenants VARCHAR(500) NULL,
            status ENUM('open','resolved','muted') NOT NULL DEFAULT 'open', muted_until DATETIME NULL, resolved_at DATETIME NULL, regressed TINYINT(1) NOT NULL DEFAULT 0,
            notify_pending TINYINT(1) NOT NULL DEFAULT 0, notify_reason VARCHAR(80) NULL, last_notified_at DATETIME NULL, notified_count INT UNSIGNED NOT NULL DEFAULT 0,
            note VARCHAR(500) NULL,
            UNIQUE KEY uq_fp (fingerprint), KEY idx_status_seen (status, last_seen), KEY idx_seen (last_seen), KEY idx_pending (notify_pending)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS error_samples (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, group_id INT UNSIGNED NOT NULL, at DATETIME NOT NULL,
            tenant_id INT UNSIGNED NULL, user_id INT UNSIGNED NULL, request_id CHAR(8) NOT NULL, url VARCHAR(500) NULL, context TEXT NULL,
            KEY idx_group (group_id, id), KEY idx_at (at), KEY idx_request (request_id), KEY idx_tenant (tenant_id, at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS cron_runs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, started_at DATETIME NOT NULL, duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            tenants SMALLINT UNSIGNED NOT NULL DEFAULT 0, jobs VARCHAR(120) NULL, errors SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            peak_mb DECIMAL(7,1) NULL, skipped TINYINT(1) NOT NULL DEFAULT 0, summary TEXT NULL, KEY idx_started (started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS perf_daily (
            day DATE NOT NULL, path VARCHAR(80) NOT NULL, hits INT UNSIGNED NOT NULL DEFAULT 0, total_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
            max_ms INT UNSIGNED NOT NULL DEFAULT 0, slow_hits INT UNSIGNED NOT NULL DEFAULT 0, queries INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (day, path)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}
try { maybe_migrate(); } catch (Throwable $e) { error_log('migration failed: ' . $e->getMessage()); }
