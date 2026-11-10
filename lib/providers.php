<?php
declare(strict_types=1);

/**
 * Mailbox provider presets. Choosing one fills in the server settings on the Mailboxes screen.
 * Any provider that gives an SMTP + IMAP login with a password works.
 */
function mail_providers(): array {
    $zohoHelp = fn(string $tld, string $mx) => [
        "Use a paid plan (Mail Lite or higher) — the free plan has no IMAP/SMTP access.",
        "In Zoho Mail: Settings → Mail Accounts → IMAP → tick <b>IMAP Access</b>.",
        "Turn on two-factor sign-in, then create an app password at <b>accounts.zoho.$tld → Security → App Passwords</b> and paste it below.",
        "DNS for your sending domain: MX <code>mx.zoho.$tld</code> (10), <code>mx2.zoho.$tld</code> (20), <code>mx3.zoho.$tld</code> (50); SPF <code>v=spf1 include:$mx ~all</code>; DKIM from Zoho Admin → Domains → Email Configuration.",
    ];
    return [
        'zoho_in' => ['label' => 'Zoho Mail — India account (zoho.in)', 'smtp_host' => 'smtppro.zoho.in', 'smtp_port' => 465, 'smtp_secure' => 'ssl',
            'imap_host' => 'imappro.zoho.in', 'imap_port' => 993, 'help' => $zohoHelp('in', 'zohomail.in'),
            'note' => 'Pick this if you sign in at mail.zoho.in.'],
        'zoho_com' => ['label' => 'Zoho Mail — global account (zoho.com)', 'smtp_host' => 'smtppro.zoho.com', 'smtp_port' => 465, 'smtp_secure' => 'ssl',
            'imap_host' => 'imappro.zoho.com', 'imap_port' => 993, 'help' => $zohoHelp('com', 'zohomail.com'),
            'note' => 'Pick this if you sign in at mail.zoho.com.'],
        'zoho_eu' => ['label' => 'Zoho Mail — EU account (zoho.eu)', 'smtp_host' => 'smtppro.zoho.eu', 'smtp_port' => 465, 'smtp_secure' => 'ssl',
            'imap_host' => 'imappro.zoho.eu', 'imap_port' => 993, 'help' => $zohoHelp('eu', 'zohomail.eu'),
            'note' => 'Pick this if you sign in at mail.zoho.eu.'],
        'google' => ['label' => 'Google Workspace / Gmail', 'smtp_host' => 'smtp.gmail.com', 'smtp_port' => 587, 'smtp_secure' => 'tls',
            'imap_host' => 'imap.gmail.com', 'imap_port' => 993,
            'help' => ["Turn on 2-Step Verification for the account.", "Create an app password at <b>myaccount.google.com/apppasswords</b> and paste it below (spaces are removed automatically).",
                "If App passwords is missing, a Workspace admin must allow 2-Step Verification."], 'note' => ''],
        'hostinger' => ['label' => 'Hostinger Email', 'smtp_host' => 'smtp.hostinger.com', 'smtp_port' => 465, 'smtp_secure' => 'ssl',
            'imap_host' => 'imap.hostinger.com', 'imap_port' => 993,
            'help' => ["Use the mailbox password you set in hPanel → Emails.", "Deliverability for cold email is weaker than Google or Zoho — fine for testing."], 'note' => ''],
        'custom' => ['label' => 'Mailforge, Maildoso or another SMTP provider', 'smtp_host' => '', 'smtp_port' => 587, 'smtp_secure' => 'tls',
            'imap_host' => '', 'imap_port' => 993,
            'help' => ["Paste the SMTP and IMAP host, port and password your provider gives you for each mailbox.", "Microsoft 365 / Outlook mailboxes won’t work long-term: Microsoft is switching off password sign-in for SMTP."], 'note' => ''],
    ];
}

/** Guess the provider of an existing mailbox from its SMTP host. */
function guess_provider(array $mb): string {
    if (!empty($mb['provider'])) return (string) $mb['provider'];
    foreach (mail_providers() as $k => $p) if ($p['smtp_host'] !== '' && strcasecmp($p['smtp_host'], (string) $mb['smtp_host']) === 0) return $k;
    if (preg_match('/zoho\.in$/i', (string) $mb['smtp_host'])) return 'zoho_in';
    if (preg_match('/zoho\.com$/i', (string) $mb['smtp_host'])) return 'zoho_com';
    return 'custom';
}

/** Turn a raw connection error into a plain next step. */
function mailbox_error_hint(string $err, string $provider): string {
    $zoho = str_starts_with($provider, 'zoho');
    if (preg_match('/535|authenticat|AUTHENTICATIONFAILED|Invalid credentials|LOGIN failed|Username and Password not accepted|password/i', $err)) {
        if ($zoho) return 'Zoho rejected the login. Use an app password (not your normal password), make sure IMAP Access is ticked in Zoho Mail settings, and check you picked the right region (zoho.in vs zoho.com).';
        if ($provider === 'google') return 'Google rejected the login. Use a 16-character app password with 2-Step Verification on — your normal password won’t work.';
        return 'The server rejected the login. Check the username (usually the full email address) and password.';
    }
    if (preg_match('/connect|timed out|Connection refused|getaddrinfo|php_network/i', $err)) {
        return 'Couldn’t reach the mail server. Check the host names and ports' . ($zoho ? ' — paid Zoho accounts on your own domain use smtppro / imappro servers.' : '.');
    }
    if (preg_match('/certificate|SSL|TLS/i', $err)) return 'Secure connection failed. Try SSL on port 465, or STARTTLS on port 587.';
    return '';
}
