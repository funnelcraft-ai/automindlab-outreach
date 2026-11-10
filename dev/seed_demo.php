<?php
declare(strict_types=1);
/**
 * Local demo data (dev only — refuses to run unless local test mode is on).
 * After install.php, run:  php dev/seed_demo.php
 * It sets up workspace #1 so the whole pipeline runs offline against the dev fixtures:
 *   - AI provider: offline mock; Google Places: the mock on 127.0.0.1:8090
 *   - workspace details, Test sending mode (everything goes to dev@outreach.test), a 24/7 sending window
 *   - an inbox using the local SMTP catcher (and local IMAP if you run it)
 *   - an active HVAC campaign for "Dallas, TX" with LinkedIn steps on
 *   - a client-app owner account (printed at the end)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/bootstrap.php';
if (!local_testing()) { fwrite(STDERR, "Refusing: local_testing is off (or base_url is not local).\n"); exit(1); }
try { $installed = (int) val('SELECT COUNT(*) FROM users') > 0; } catch (Throwable $e) { $installed = false; }
if (!$installed) { fwrite(STDERR, "Run install.php first (open " . base_url('install.php') . ").\n"); exit(1); }

set_tenant(1);
// Platform
set_setting('ai_provider', 'mock');
set_setting('places_key', 'dev-places-key');
set_setting('ai_monthly_budget', '5');
// Workspace #1
foreach ([
    'legal_name' => 'Demo Agency LLC', 'postal_address' => '1 Demo Street, Dallas, TX 75001', 'website' => 'https://automindlab.io',
    'cal_link' => 'https://cal.id/automindlab', 'test_redirect' => 'dev@outreach.test', 'dry_run' => '0', 'sending_paused' => '0',
    'send_days' => '1,2,3,4,5,6,7', 'send_start' => '00:00', 'send_end' => '23:59', 'min_gap_min' => '0', 'max_gap_min' => '0',
] as $k => $v) set_tenant_setting($k, $v, 1);

if (!val("SELECT id FROM mailboxes WHERE email = 'dev@outreach.test'")) {
    insert('mailboxes', ['label' => 'Dev catcher', 'provider' => 'custom', 'from_name' => 'Dev Sender', 'email' => 'dev@outreach.test',
        'smtp_host' => '127.0.0.1', 'smtp_port' => 2525, 'smtp_secure' => 'none', 'imap_host' => '127.0.0.1', 'imap_port' => 143,
        'username' => 'dev@outreach.test', 'password_enc' => encrypt_str('devpass'), 'signature' => "Dev Sender\nDemo Agency",
        'daily_limit' => 50, 'ramp_start' => 50, 'ramp_step' => 0, 'warmup_started' => gmdate('Y-m-d'), 'active' => 1]);
}

if (!val("SELECT id FROM campaigns WHERE name = 'Demo · HVAC Dallas'")) {
    $p = preset_by_key('hvac');
    $id = insert('campaigns', ['name' => 'Demo · HVAC Dallas', 'vertical' => 'hvac', 'keywords' => 'HVAC contractor', 'locations' => 'Dallas, TX',
        'icp' => $p[2], 'offer' => preset_offer(), 'proof' => '', 'min_reviews' => 5, 'max_reviews' => 1500, 'min_rating' => 3.5,
        'daily_new_leads' => 20, 'fit_threshold' => 50, 'approval_mode' => 'manual', 'auto_min_score' => 80, 'followups' => 3, 'step_delays' => '3,4,7',
        'status' => 'active', 'linkedin_enabled' => 1, 'li_connect_after_days' => 0, 'li_followup_days' => 4]);
    sync_campaign_queries($id);
}

$email = 'owner@demo.test';
$pw = 'Demo-' . bin2hex(random_bytes(5));
$uid = (int) val('SELECT id FROM users WHERE email = ?', [$email]);
if ($uid) q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $uid]);
else $uid = insert('users', ['email' => $email, 'name' => 'Demo Owner', 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'password_changed_at' => now(), 'is_platform_admin' => 0]);
q("INSERT INTO memberships (user_id, tenant_id, role) VALUES (?, 1, 'owner') ON DUPLICATE KEY UPDATE role = 'owner'", [$uid]);

echo "Demo ready.\n"
   . "  Client app:     " . base_url('login.php') . "   $email / $pw\n"
   . "  Admin console:  " . base_url('admin/') . "   (the account you created in install.php)\n"
   . "  Run the pipeline:  php cron.php find,enrich,draft 1   then approve drafts in the Review queue, then  php cron.php send,linkedin 1\n";
