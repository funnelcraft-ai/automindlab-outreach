<?php
declare(strict_types=1);

require_once APP_ROOT . '/vendor/phpmailer/Exception.php';
require_once APP_ROOT . '/vendor/phpmailer/PHPMailer.php';
require_once APP_ROOT . '/vendor/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

/** Today's sending allowance for a mailbox, following its warm-up ramp. */
function mailbox_limit_today(array $mb): int {
    $target = (int) $mb['daily_limit'];
    if (empty($mb['warmup_started'])) return $target;
    $days = (int) floor((time() - strtotime($mb['warmup_started'] . ' 00:00:00')) / 86400);
    return max(1, min($target, (int) $mb['ramp_start'] + max(0, $days) * (int) $mb['ramp_step']));
}
function mailbox_sent_today(array $mb): int {
    return ($mb['sent_date'] ?? null) === gmdate('Y-m-d') ? (int) $mb['sent_today'] : 0;
}

function mailbox_password(array $mb): string { return decrypt_str((string) $mb['password_enc']); }

function make_mailer(array $mb): PHPMailer {
    $m = new PHPMailer(true);
    $m->isSMTP();
    $m->Host = $mb['smtp_host'];
    $m->Port = (int) $mb['smtp_port'];
    $m->SMTPAuth = $mb['username'] !== '';
    $m->Username = $mb['username'];
    $m->Password = mailbox_password($mb);
    $sec = strtolower((string) $mb['smtp_secure']);
    if ($sec === 'ssl') $m->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    elseif ($sec === 'tls') $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    else { $m->SMTPSecure = ''; $m->SMTPAutoTLS = false; }
    $m->Timeout = 20;
    $m->CharSet = 'UTF-8';
    $m->Encoding = 'quoted-printable';
    $m->XMailer = ' '; // don't advertise the library
    $m->setFrom($mb['email'], $mb['from_name']);
    return $m;
}

/** Fill placeholders the AI leaves in copy. */
function render_placeholders(string $text, array $lead, array $mb): string {
    $first = trim((string) ($lead['contact_name'] ?? ''));
    return strtr($text, [
        '{{cal_link}}' => booking_link($lead),
        '{{first_name}}' => $first !== '' ? $first : 'there',
        '{{business}}' => (string) $lead['business_name'],
        '{{city}}' => (string) ($lead['city'] ?? ''),
        '{{sender_name}}' => explode(' ', (string) $mb['from_name'])[0],
    ]);
}

function booking_link(array $lead): string {
    $link = (string) setting('cal_link');
    if (setting('cal_prefill') === '1' && !empty($lead['email'])) {
        $q = ['email' => $lead['email']];
        if (!empty($lead['contact_name'])) $q['name'] = $lead['contact_name'];
        $link .= (str_contains($link, '?') ? '&' : '?') . http_build_query($q);
    }
    return $link;
}

/** Compose the final plain-text body: copy + signature + compliance footer. */
function compose_body(string $body, array $lead, array $mb): string {
    $body = rtrim(render_placeholders($body, $lead, $mb));
    $sig = trim((string) ($mb['signature'] ?? ''));
    if ($sig === '') {
        $sig = $mb['from_name'] . "\n" . setting('sender_role') . ', ' . setting('company_name') . "\n" . preg_replace('~^https?://~', '', (string) setting('website'));
    }
    $footer = [];
    $addr = trim((string) setting('postal_address'));
    $legal = trim((string) setting('legal_name'));
    if ($addr !== '') $footer[] = ($legal !== '' ? $legal . ', ' : '') . preg_replace('/\s*\n\s*/', ', ', $addr);
    if (setting('include_unsub_link') === '1') $footer[] = "Not relevant? Reply \"no\" or opt out here: " . unsubscribe_url((int) $lead['id']);
    else $footer[] = 'Not relevant? Just reply "no" and I won\'t email again.';
    return $body . "\n\n" . $sig . "\n\n--\n" . implode("\n", $footer) . "\n";
}

/**
 * Send one email. $thread = ['in_reply_to' => msgid, 'references' => 'msgid1 msgid2'].
 * Returns the Message-ID used.
 */
function send_mail(array $mb, string $to, string $toName, string $subject, string $body, array $thread = [], ?int $leadId = null): string {
    $domain = substr(strrchr($mb['email'], '@'), 1);
    $msgId = '<' . bin2hex(random_bytes(12)) . '.' . time() . '@' . $domain . '>';
    $redirect = trim((string) setting('test_redirect'));
    if ($redirect !== '') { $subject = "[TEST → $to] $subject"; $to = $redirect; }

    if (setting('dry_run') === '1') {
        log_event($leadId, 'dry_run', "Would send to $to: $subject");
        return $msgId;
    }
    $m = make_mailer($mb);
    $m->addAddress($to, $toName);
    $m->Subject = $subject;
    $m->Body = PHPMailer::normalizeBreaks($body);
    $m->isHTML(false);
    $m->MessageID = $msgId;
    if (!empty($thread['in_reply_to'])) {
        $m->addCustomHeader('In-Reply-To', $thread['in_reply_to']);
        $m->addCustomHeader('References', trim($thread['references'] ?? $thread['in_reply_to']));
    }
    if ($leadId) {
        $unsub = unsubscribe_url($leadId);
        $m->addCustomHeader('List-Unsubscribe', '<mailto:' . $mb['email'] . '?subject=unsubscribe>, <' . $unsub . '>');
        $m->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
    }
    try {
        $m->send();
    } catch (Throwable $ex) {
        $last = trim((string) $m->getSMTPInstance()->getLastReply());
        throw new RuntimeException(trim($ex->getMessage() . ($last !== '' ? ' | ' . $last : '')));
    }
    return $msgId;
}

function test_smtp(array $mb): string {
    $m = make_mailer($mb);
    if (!$m->smtpConnect()) throw new RuntimeException('SMTP connect failed');
    $m->smtpClose();
    return 'SMTP login OK';
}
