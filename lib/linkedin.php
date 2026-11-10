<?php
declare(strict_types=1);
/**
 * Assisted LinkedIn outreach.
 *
 * LinkedIn doesn't allow software to send connection requests or messages for its members, and its API offers no
 * way to do so. So this channel does everything EXCEPT the clicking:
 *   - finds the LinkedIn link a business publishes on its own website (never scrapes LinkedIn),
 *   - writes a short connection note and follow-up messages with the same AI research as the emails,
 *   - schedules them as tasks a person completes by hand in their own LinkedIn (with a safe daily limit),
 *   - and records the outcomes, which feed the same stop rules, replies and dashboards as email.
 */

const LI_NOTE_MAX = 200;              // safe for free accounts (Premium allows a little more)
const LI_DEFAULT_DAILY_CAP = 20;      // connection requests per person per day
const LI_REQUEST_EXPIRE_DAYS = 21;    // a request not accepted after this is closed
const LI_STATES = [
    'none' => 'Not started', 'queued' => 'Connect task due', 'requested' => 'Request sent', 'connected' => 'Connected',
    'messaged' => 'Messaged', 'replied' => 'Replied', 'closed' => 'Closed',
];
const LI_TERMINAL_LEAD_STATUSES = ['replied', 'booked', 'unsubscribed', 'bounced', 'disqualified', 'skipped', 'error'];

/** Validate and tidy a LinkedIn link. Returns [url, 'person'|'company'] or null. */
function li_normalize(string $url): ?array {
    $url = trim(html_entity_decode($url));
    if ($url === '') return null;
    if (!preg_match('~^(?:https?://)?(?:[a-z]{2,3}\.)?linkedin\.com/(in|company)/([A-Za-z0-9\-_%.]{2,100})~i', $url, $m)) return null;
    $slug = rtrim($m[2], '.');
    return ['https://www.linkedin.com/' . strtolower($m[1]) . '/' . $slug . '/', strtolower($m[1]) === 'in' ? 'person' : 'company'];
}

/** The LinkedIn link a business publishes on its own website (a person's profile is preferred over a company page). */
function li_from_html(string $html): ?array {
    if (!preg_match_all('~https?://(?:[a-z]{2,3}\.)?linkedin\.com/(?:in|company)/[A-Za-z0-9\-_%.]{2,100}~i', $html, $m)) return null;
    $found = array_filter(array_map('li_normalize', array_unique($m[0])));
    if (!$found) return null;
    usort($found, fn($a, $b) => ($a[1] === 'person' ? 0 : 1) <=> ($b[1] === 'person' ? 0 : 1));
    return $found[0];
}

function li_daily_cap(): int { return max(1, min(100, (int) (setting('li_daily_cap') ?: LI_DEFAULT_DAILY_CAP))); }

/** Start of "today" in the app's time zone, as a UTC timestamp string. */
function li_today_start(): string {
    $tz = new DateTimeZone((string) cfg('app_timezone', 'UTC'));
    return (new DateTime('today', $tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function li_requests_today(int $userId): int {
    return (int) val("SELECT COUNT(*) FROM li_tasks WHERE tenant_id = ? AND kind = 'connect' AND status = 'done' AND done_by = ? AND done_at >= ?", [tid(), $userId, li_today_start()]);
}

function li_enabled_anywhere(): bool {
    return (bool) val('SELECT 1 FROM campaigns WHERE tenant_id = ? AND linkedin_enabled = 1 LIMIT 1', [tid()])
        || (bool) val("SELECT 1 FROM li_tasks WHERE tenant_id = ? AND status = 'pending' LIMIT 1", [tid()]);
}

function li_due_count(): int {
    return (int) val("SELECT COUNT(*) FROM li_tasks WHERE tenant_id = ? AND status = 'pending' AND due_at <= ?", [tid(), now()]);
}

/** Text for a task, with placeholders filled. LinkedIn messages never carry an email signature or footer. */
function li_render(string $text, array $lead): string {
    $first = trim((string) ($lead['contact_name'] ?? ''));
    return trim(strtr($text, [
        '{{cal_link}}' => booking_link($lead),
        '{{first_name}}' => $first !== '' ? $first : 'there',
        '{{business}}' => (string) $lead['business_name'],
        '{{city}}' => (string) ($lead['city'] ?? ''),
    ]));
}

/** Safety checks for LinkedIn copy (same rules as email: no foreign links, emails, phone numbers or injected instructions). */
function li_safety_issues(array $lead, array $campaign): array {
    $as = [];
    foreach (['li_note' => 'note', 'li_msg1' => 'message', 'li_msg2' => 'follow-up'] as $col => $label) if (trim((string) $lead[$col]) !== '') $as[] = ['subject' => '', 'body' => (string) $lead[$col], 'label' => $label];
    $issues = draft_safety_issues($as, $campaign);
    $out = [];
    foreach ($issues as $i) $out[] = preg_replace_callback('/^Email (\d+)/', fn($m) => 'LinkedIn ' . ($as[(int) $m[1] - 1]['label'] ?? 'text'), $i);
    if (mb_strlen((string) $lead['li_note']) > LI_NOTE_MAX) $out[] = 'LinkedIn note is over ' . LI_NOTE_MAX . ' characters';
    return $out;
}

/** Store AI-written LinkedIn copy on the lead (called from the main draft, or on its own). */
function li_store_copy(int $leadId, array $li): void {
    $note = trim((string) ($li['connection_note'] ?? ''));
    if (mb_strlen($note) > LI_NOTE_MAX) $note = rtrim(mb_substr($note, 0, LI_NOTE_MAX - 1)) . '…';
    update('leads', ['li_note' => $note ?: null, 'li_msg1' => trim((string) ($li['message'] ?? '')) ?: null,
        'li_msg2' => trim((string) ($li['followup'] ?? '')) ?: null], 'id = ? AND tenant_id = ?', [$leadId, tid()]);
}

/** Write LinkedIn copy for a lead that was drafted before LinkedIn was switched on for its campaign. */
function li_draft_copy(array $lead, array $campaign): void {
    [$sys, $user] = prompt_linkedin_only($campaign, $lead);
    $d = ai_json($sys, $user, 900, 'linkedin');
    if (empty($d['linkedin']['connection_note'])) throw new RuntimeException('AI returned no LinkedIn note');
    li_store_copy((int) $lead['id'], $d['linkedin']);
    log_event((int) $lead['id'], 'linkedin', 'LinkedIn note and messages drafted');
}

function li_add_task(array $lead, string $kind, string $due): void {
    if (val("SELECT 1 FROM li_tasks WHERE lead_id = ? AND kind = ? AND status = 'pending'", [$lead['id'], $kind])) return;
    $col = ['connect' => 'li_note', 'message' => 'li_msg1', 'followup' => 'li_msg2'][$kind];
    insert('li_tasks', ['lead_id' => $lead['id'], 'kind' => $kind, 'body' => (string) $lead[$col], 'due_at' => $due, 'status' => 'pending']);
}

/** Cancel open LinkedIn tasks for a lead (called by cancel_sequence on reply, booking, opt-out, bounce…). */
function li_cancel(int $leadId, string $why): void {
    $n = q("UPDATE li_tasks SET status = 'cancelled', outcome = ? WHERE lead_id = ? AND tenant_id = ? AND status = 'pending'", [mb_substr($why, 0, 40), $leadId, tid()])->rowCount();
    q("UPDATE leads SET li_state = 'closed' WHERE id = ? AND tenant_id = ? AND li_state IN ('queued','requested','connected','messaged')", [$leadId, tid()]);
    if ($n) log_event($leadId, 'linkedin', "$n LinkedIn task(s) cancelled: $why");
}

/**
 * Cron job: create connect tasks after the first email has gone out, write missing copy, close stale requests.
 * Creating a task never contacts anyone; a person does that.
 */
function job_linkedin(float $deadline, int $maxDrafts = 4): array {
    $stats = ['created' => 0, 'drafted' => 0, 'expired' => 0, 'cleaned' => 0];
    // 1. Safety net: nothing stays open for a lead that has finished.
    $stats['cleaned'] = q("UPDATE li_tasks t JOIN leads l ON l.id = t.lead_id SET t.status = 'cancelled', t.outcome = 'lead finished'
        WHERE t.tenant_id = ? AND t.status = 'pending' AND l.status IN ('" . implode("','", LI_TERMINAL_LEAD_STATUSES) . "')", [tid()])->rowCount();

    // 2. Connection requests nobody accepted within LI_REQUEST_EXPIRE_DAYS are closed.
    foreach (all("SELECT l.id FROM leads l WHERE l.tenant_id = ? AND l.li_state = 'requested' AND l.li_requested_at < ? LIMIT 200",
            [tid(), gmdate('Y-m-d H:i:s', time() - LI_REQUEST_EXPIRE_DAYS * 86400)]) as $r) {
        update('leads', ['li_state' => 'closed'], 'id = ?', [$r['id']]);
        log_event((int) $r['id'], 'linkedin', 'Connection request not accepted after ' . LI_REQUEST_EXPIRE_DAYS . ' days — closed');
        $stats['expired']++;
    }

    // 3. New connect tasks: LinkedIn on for the campaign, a link on file, first email sent N days ago.
    $rows = all("SELECT l.*, c.li_connect_after_days, c.linkedin_enabled FROM leads l
        JOIN campaigns c ON c.id = l.campaign_id
        JOIN emails e ON e.lead_id = l.id AND e.step = 1 AND e.status = 'sent'
        WHERE l.tenant_id = ? AND c.linkedin_enabled = 1 AND c.status = 'active' AND l.linkedin_url IS NOT NULL AND l.li_state = 'none'
          AND l.status IN ('contacted','completed') AND e.sent_at <= DATE_SUB(?, INTERVAL c.li_connect_after_days DAY)
        ORDER BY e.sent_at LIMIT 100", [tid(), now()]);
    $drafted = 0;
    foreach ($rows as $lead) {
        if (!deadline_left($deadline)) break;
        if (trim((string) $lead['li_note']) === '') {
            if ($drafted >= $maxDrafts) continue;
            $c = row('SELECT * FROM campaigns WHERE id = ?', [$lead['campaign_id']]);
            try { li_draft_copy($lead, $c); $drafted++; $stats['drafted']++; }
            catch (Throwable $ex) {
                log_event((int) $lead['id'], 'draft_error', 'LinkedIn copy: ' . $ex->getMessage(), 'error');
                if (str_contains($ex->getMessage(), 'budget') || str_contains($ex->getMessage(), 'key missing')) break;
                continue;
            }
            $lead = row('SELECT * FROM leads WHERE id = ?', [$lead['id']]) + $lead;
        }
        if (li_safety_issues($lead, row('SELECT * FROM campaigns WHERE id = ?', [$lead['campaign_id']]) ?? [])) {
            log_event((int) $lead['id'], 'linkedin', 'LinkedIn copy held: check it on the lead page', 'warn');
            update('leads', ['li_state' => 'closed'], 'id = ?', [$lead['id']]);
            continue;
        }
        li_add_task($lead, 'connect', now());
        update('leads', ['li_state' => 'queued'], 'id = ?', [$lead['id']]);
        $stats['created']++;
    }
    return $stats;
}

/**
 * Record what a person did on LinkedIn. One function for the task list and the lead page.
 * Returns a message for the flash.
 */
function li_record(int $leadId, string $action, int $userId): string {
    $lead = row('SELECT l.*, c.li_followup_days FROM leads l LEFT JOIN campaigns c ON c.id = l.campaign_id WHERE l.id = ? AND l.tenant_id = ?', [$leadId, tid()])
        ?? throw new RuntimeException('Lead not found.');
    $task = function (string $kind) use ($leadId) { return row("SELECT * FROM li_tasks WHERE lead_id = ? AND kind = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", [$leadId, $kind]); };
    $close = function (?array $t, string $status, string $outcome) use ($userId) {
        if ($t) q('UPDATE li_tasks SET status = ?, outcome = ?, done_by = ?, done_at = ? WHERE id = ?', [$status, $outcome, $userId, now(), $t['id']]);
    };
    $name = $lead['business_name'];
    switch ($action) {
        case 'sent_request':
        case 'sent_request_no_note':
            $close($task('connect'), 'done', $action === 'sent_request' ? 'sent' : 'sent without note');
            update('leads', ['li_state' => 'requested', 'li_requested_at' => now()], 'id = ?', [$leadId]);
            log_event($leadId, 'linkedin', 'LinkedIn connection request sent' . ($action === 'sent_request' ? ' with note' : ' without a note'));
            return "Request to $name recorded. It moves to “Waiting for acceptance”.";
        case 'already_connected':
        case 'accepted':
            $close($task('connect'), 'done', $action === 'accepted' ? 'sent' : 'already connected');
            update('leads', ['li_state' => 'connected', 'li_connected_at' => now()], 'id = ?', [$leadId]);
            if (trim((string) $lead['li_msg1']) !== '') li_add_task($lead, 'message', now());
            log_event($leadId, 'linkedin', $action === 'accepted' ? 'LinkedIn connection accepted' : 'Already connected on LinkedIn');
            return "Connected with $name. Their first message is in your tasks now.";
        case 'not_accepted':
            update('leads', ['li_state' => 'closed'], 'id = ?', [$leadId]);
            log_event($leadId, 'linkedin', 'LinkedIn request marked as not accepted');
            return 'Closed.';
        case 'not_found':
            $close($task('connect'), 'skipped', 'not found');
            update('leads', ['li_state' => 'closed'], 'id = ?', [$leadId]);
            log_event($leadId, 'linkedin', 'LinkedIn: couldn’t find the right person');
            return 'Skipped. Email continues as normal.';
        case 'sent_message':
            $close($task('message'), 'done', 'sent');
            update('leads', ['li_state' => 'messaged'], 'id = ?', [$leadId]);
            if (trim((string) $lead['li_msg2']) !== '') li_add_task($lead, 'followup', gmdate('Y-m-d H:i:s', time() + max(1, (int) ($lead['li_followup_days'] ?? 4)) * 86400));
            log_event($leadId, 'linkedin', 'LinkedIn message sent');
            return "Message to $name recorded" . (trim((string) $lead['li_msg2']) !== '' ? '. A follow-up is scheduled.' : '.');
        case 'sent_followup':
            $close($task('followup'), 'done', 'sent');
            log_event($leadId, 'linkedin', 'LinkedIn follow-up sent');
            return 'Follow-up recorded. That’s the last LinkedIn step.';
        case 'skip_message':
            foreach (['message', 'followup'] as $k) $close($task($k), 'skipped', 'skipped');
            log_event($leadId, 'linkedin', 'LinkedIn message skipped');
            return 'Skipped.';
        case 'replied':
        case 'not_interested':
        case 'asked_to_stop':
            $cat = ['replied' => 'interested', 'not_interested' => 'not_interested', 'asked_to_stop' => 'unsubscribe'][$action];
            foreach (['connect', 'message', 'followup'] as $k) $close($task($k), 'done', 'replied');
            insert('replies', ['lead_id' => $leadId, 'mailbox_id' => $lead['mailbox_id'], 'message_id' => 'linkedin-' . bin2hex(random_bytes(8)),
                'from_email' => (string) ($lead['email'] ?: 'LinkedIn'), 'from_name' => $lead['contact_name'], 'subject' => 'LinkedIn reply',
                'body' => 'Reply received on LinkedIn (recorded by hand).', 'received_at' => now(), 'kind' => 'reply', 'category' => $cat,
                'summary' => 'LinkedIn reply', 'handled' => 1, 'channel' => 'linkedin']);
            update('leads', ['li_state' => 'replied'], 'id = ?', [$leadId]);
            if ($action === 'asked_to_stop') {
                if ($lead['email']) suppress($lead['email'], 'asked on LinkedIn');
                update('leads', ['status' => 'unsubscribed', 'unsubscribed_at' => now()], 'id = ?', [$leadId]);
            } elseif (!in_array($lead['status'], ['booked'], true)) {
                update('leads', ['status' => 'replied', 'replied_at' => now(), 'reply_category' => $cat], 'id = ?', [$leadId]);
            }
            cancel_sequence($leadId, 'replied on LinkedIn');
            log_event($leadId, 'reply', 'Replied on LinkedIn (' . str_replace('_', ' ', $cat) . ')');
            return $action === 'asked_to_stop' ? "$name won’t be contacted again." : "Reply from $name recorded. Remaining emails were cancelled — answer them on LinkedIn.";
    }
    throw new RuntimeException('Unknown action.');
}

/** LinkedIn numbers for the workspace dashboard. */
function li_stats(): array {
    $since = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
    $r = row("SELECT SUM(kind = 'connect' AND outcome IN ('sent','sent without note')) requests, SUM(kind IN ('message','followup') AND status = 'done') messages
              FROM li_tasks WHERE tenant_id = ? AND done_at >= ?", [tid(), $since]) ?: [];
    return [
        'due' => li_due_count(),
        'requests' => (int) ($r['requests'] ?? 0),
        'accepted' => (int) val('SELECT COUNT(*) FROM leads WHERE tenant_id = ? AND li_connected_at >= ?', [tid(), $since]),
        'messages' => (int) ($r['messages'] ?? 0),
        'replies' => (int) val("SELECT COUNT(*) FROM replies WHERE tenant_id = ? AND channel = 'linkedin' AND received_at >= ?", [tid(), $since]),
        'waiting' => (int) val("SELECT COUNT(*) FROM leads WHERE tenant_id = ? AND li_state = 'requested'", [tid()]),
    ];
}
