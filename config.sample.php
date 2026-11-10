<?php
/**
 * Automindlab Outreach — configuration
 * Recommended: save this file ONE LEVEL ABOVE public_html as  outreach-config.php
 * (e.g. /home/u123456789/domains/outreach.automindlab.io/outreach-config.php) so it can never be served by the web server.
 * The app also accepts config.php inside the app folder.
 * Everything else (API keys, mailboxes, campaigns) is managed from the dashboard.
 */
return [
    // MySQL database (Hostinger hPanel → Databases → MySQL Databases)
    'db_host' => 'localhost',
    'db_name' => 'u123456789_outreach',
    'db_user' => 'u123456789_outreach',
    'db_pass' => 'CHANGE_ME',

    // Public URL of this app, no trailing slash. Used for unsubscribe + webhook links.
    'base_url' => 'https://outreach.automindlab.io',

    // 32-byte secret, base64. Generate once and NEVER change it after install
    // (it encrypts stored API keys and mailbox passwords).
    // Run:  php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
    'app_key' => 'PASTE_BASE64_KEY_HERE',

    // Timezone used for the dashboard display and daily counters.
    'app_timezone' => 'Asia/Kolkata',

    // Optional: a Slack / Discord / Google Chat / Telegram webhook used ONLY when the database is down
    // (normal alerts are set up on System health). Leave empty to skip.
    'alert_webhook' => '',

    // The Admin console (/admin/) requires two-step verification for every platform admin. Leave true.
    'admin_require_2fa' => true,

    // Seconds of work per cron run (20–290). Keep 50 on shared hosting with a 5-minute cron.
    // On a VPS with a 1-minute cron, 50 is still right; with a 5-minute cron you can raise it to ~240.
    'cron_budget' => 50,

    // Set true only while debugging.
    'debug' => false,
];
