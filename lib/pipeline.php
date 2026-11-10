<?php
declare(strict_types=1);

/*
 * Lead statuses:
 *  new → enriched → drafted (review) → queued → contacted → completed
 *  side exits: no_website, no_email, skipped, disqualified, error,
 *              replied, interested, not_interested, booked, bounced, unsubscribed
 */
const STATUS_LABELS = [
    'new' => 'Found', 'no_website' => 'No website', 'no_email' => 'No email', 'skipped' => 'Skipped',
    'enriched' => 'Researched', 'disqualified' => 'Not a fit', 'drafted' => 'Needs review', 'queued' => 'Queued',
    'contacted' => 'In sequence', 'completed' => 'Sequence done', 'replied' => 'Replied', 'interested' => 'Interested',
    'not_interested' => 'Not interested', 'booked' => 'Booked', 'bounced' => 'Bounced', 'unsubscribed' => 'Unsubscribed', 'error' => 'Error',
];

function deadline_left(float $deadline): bool { return microtime(true) < $deadline; }

/* ======================= 1. FIND ======================= */

function sync_campaign_queries(int $campaignId): void {
    $c = row('SELECT * FROM campaigns WHERE id = ? AND tenant_id = ?', [$campaignId, tid()]);
    if (!$c) return;
    $kws = array_filter(array_map('trim', explode("\n", $c['keywords'])));
    $locs = array_filter(array_map('trim', explode("\n", $c['locations'])));
    foreach ($kws as $k) foreach ($locs as $l) {
        q('INSERT IGNORE INTO search_queries (tenant_id, campaign_id, keyword, location) VALUES (?,?,?,?)', [tid(), $campaignId, mb_substr($k, 0, 190), mb_substr($l, 0, 190)]);
    }
}

function job_find(float $deadline, int $maxCalls = 3): array {
    $stats = ['calls' => 0, 'added' => 0];
    if (setting('places_key') === '' || setting('places_key') === null) return $stats + ['note' => 'no Places key'];
    $quota = (int) (tenant()['quota_leads'] ?? 0);
    foreach (all("SELECT * FROM campaigns WHERE status = 'active' AND tenant_id = ? ORDER BY id", [tid()]) as $c) {
        while ($stats['calls'] < $maxCalls && deadline_left($deadline)) {
            if ($quota > 0 && tenant_month_leads() >= $quota) {
                if (tenant_setting('quota_note') !== gmdate('Ym')) { log_event(null, 'quota', "Monthly lead quota of $quota reached — lead search paused until next month", 'warn'); set_tenant_setting('quota_note', gmdate('Ym')); }
                return $stats + ['note' => 'lead quota reached'];
            }
            $today = (int) val("SELECT COUNT(*) FROM leads WHERE tenant_id = ? AND campaign_id = ? AND created_at >= ?", [tid(), $c['id'], gmdate('Y-m-d 00:00:00')]);
            if ($today >= (int) $c['daily_new_leads']) break;
            $sq = row("SELECT * FROM search_queries WHERE tenant_id = ? AND campaign_id = ? AND status = 'pending' ORDER BY (last_run_at IS NOT NULL), last_run_at, id LIMIT 1", [tid(), $c['id']]);
            if (!$sq) break;
            $country = location_country($sq['location']);
            try {
                $res = places_search($sq['keyword'] . ' in ' . $sq['location'], $sq['next_page_token'] ?: null, $country);
            } catch (Throwable $ex) {
                update('search_queries', ['status' => str_contains($ex->getMessage(), 'cap reached') ? 'pending' : 'error', 'error' => mb_substr($ex->getMessage(), 0, 255), 'last_run_at' => now()], 'id = ?', [$sq['id']]);
                log_event(null, 'find_error', $ex->getMessage(), 'error');
                return $stats;
            }
            $stats['calls']++;
            $added = 0;
            [$city, $region] = split_location($sq['location']);
            $room = $quota > 0 ? max(0, $quota - tenant_month_leads()) : PHP_INT_MAX;   // never overshoot the monthly allowance
            foreach ($res['places'] as $p) {
                if ($added >= $room) break;
                $f = place_filter_view($p);                       // in-memory only
                if (!$f['place_id'] || $f['status'] !== 'OPERATIONAL') continue;
                if ($f['country'] && !in_array($f['country'], ['US', 'CA'], true)) continue;
                if ($f['reviews'] < (int) $c['min_reviews'] || $f['reviews'] > (int) $c['max_reviews']) continue;
                if ($f['rating'] < (float) $c['min_rating']) continue;
                if (!$f['has_site'] || is_platform_domain($f['domain'])) continue;
                if (val('SELECT 1 FROM leads WHERE tenant_id = ? AND place_id = ?', [tid(), $f['place_id']])) continue;
                if (val('SELECT 1 FROM leads WHERE tenant_id = ? AND domain = ?', [tid(), $f['domain']]) || val("SELECT 1 FROM suppression WHERE tenant_id IN (0, ?) AND type='domain' AND value = ?", [tid(), $f['domain']])) continue;
                insert('leads', [
                    'campaign_id' => $c['id'], 'place_id' => mb_substr($f['place_id'], 0, 190), 'status' => 'new',
                    'business_name' => 'Pending research', 'category' => mb_substr($sq['keyword'], 0, 190),
                    'search_location' => mb_substr($sq['location'], 0, 190), 'city' => mb_substr($city, 0, 120) ?: null,
                    'region' => mb_substr($region, 0, 20) ?: null, 'country' => $country,
                    'timezone' => region_timezone($region, $country),
                ]);
                $added++;
            }
            $pages = (int) $sq['pages_done'] + 1;
            $done = !$res['next'] || $pages >= 3;
            update('search_queries', ['pages_done' => $pages, 'results' => (int) $sq['results'] + count($res['places']),
                'next_page_token' => $done ? null : $res['next'], 'status' => $done ? 'done' : 'pending', 'last_run_at' => now(), 'error' => null], 'id = ?', [$sq['id']]);
            $stats['added'] += $added;
            log_event(null, 'find', "\"{$sq['keyword']} in {$sq['location']}\" page $pages: " . count($res['places']) . " results, $added new leads");
        }
    }
    return $stats;
}

/* ======================= 2. ENRICH ======================= */

function job_enrich(float $deadline, int $max = 6): array {
    $stats = ['processed' => 0, 'with_email' => 0];
    foreach (all("SELECT * FROM leads WHERE tenant_id = ? AND status = 'new' ORDER BY id LIMIT $max", [tid()]) as $lead) {
        if (!deadline_left($deadline)) break;
        $stats['processed']++;
        if (enrich_lead($lead)) $stats['with_email']++;
    }
    return $stats;
}

/** Research one lead's website. Returns true when a usable email was found. */
function enrich_lead(array $lead): bool {
    try {
        $isCanada = $lead['country'] === 'CA';
        $strict = $isCanada && setting('canada_strict') === '1';
        $url = (string) $lead['website'];
        if ($url === '' && $lead['place_id']) {                    // fetch the website address on demand; never stored
            $d = places_details((string) $lead['place_id']);
            if ($d['status'] !== 'OPERATIONAL' || !$d['website']) {
                update('leads', ['status' => 'no_website', 'error' => $d['status'] !== 'OPERATIONAL' ? 'No longer operating' : 'No website listed'], 'id = ?', [$lead['id']]);
                return false;
            }
            $url = (string) $d['website'];
        }
        $r = enrich_website($url, !$strict);
        if (!$r['ok']) {
            update('leads', ['status' => 'no_email', 'error' => $r['error'], 'attempts' => $lead['attempts'] + 1], 'id = ?', [$lead['id']]);
            log_event((int) $lead['id'], 'enrich', 'Skipped: ' . $r['error']);
            return false;
        }
        // Everything stored from here on comes from the business's own website.
        $domain = site_domain($r['final_url']);
        $biz = $r['business'];
        $data = ['signals' => json_encode($r['signals']), 'site_excerpt' => $r['excerpt'], 'error' => null,
            'website' => mb_substr($r['final_url'], 0, 255), 'domain' => $domain,
            'business_name' => mb_substr($biz['name'] ?: ($domain ?: 'Unknown business'), 0, 255),
            'phone' => $biz['phone'] ? mb_substr($biz['phone'], 0, 40) : null,
            'address' => $biz['address'] ? mb_substr($biz['address'], 0, 255) : null];
        if (!empty($r['linkedin'])) { $data['linkedin_url'] = $r['linkedin'][0]; $data['linkedin_kind'] = $r['linkedin'][1]; }
        if ($biz['city']) $data['city'] = mb_substr($biz['city'], 0, 120);
        if ($biz['region']) $data['region'] = mb_substr($biz['region'], 0, 20);
        if ($domain && val('SELECT 1 FROM leads WHERE tenant_id = ? AND domain = ? AND id <> ?', [tid(), $domain, $lead['id']])) {
            update('leads', $data + ['status' => 'skipped', 'error' => 'Same website as another lead'], 'id = ?', [$lead['id']]);
            return false;
        }
        $email = $r['email'];
        $reason = null;
        if (!$email) $reason = 'No email published on website';
        elseif ($r['no_solicit_notice']) $reason = 'Website says no solicitation';
        elseif (is_suppressed($email)) $reason = 'Email is on the suppression list';
        elseif (val('SELECT 1 FROM leads WHERE tenant_id = ? AND email = ? AND id <> ?', [tid(), $email, $lead['id']])) $reason = 'Email already used by another lead';
        elseif (!email_domain_ok($email)) $reason = 'Email domain has no mail server';
        elseif ($strict && $r['email_source'] === 'hunter.io') $reason = 'Canada: email not published on their site';

        if ($reason) {
            $data['status'] = ($reason === 'No email published on website') ? 'no_email' : 'skipped';
            $data['error'] = $reason;
            if ($email) $data['email'] = $email;
        } else {
            $data += ['email' => $email, 'email_source' => mb_substr((string) $r['email_source'], 0, 255), 'email_found_at' => now(), 'status' => 'enriched'];
        }
        update('leads', $data, 'id = ?', [$lead['id']]);
        log_event((int) $lead['id'], 'enrich', $reason ? "Skipped: $reason" : "Found $email on {$r['email_source']}");
        return $reason === null;
    } catch (Throwable $ex) {
        $msg = $ex->getMessage();
        if (str_contains($msg, 'cap reached') || str_contains($msg, 'key missing')) { log_event(null, 'enrich_error', $msg, 'error'); return false; } // retry later
        update('leads', ['status' => 'error', 'error' => mb_substr($msg, 0, 255)], 'id = ?', [$lead['id']]);
        log_event((int) $lead['id'], 'enrich_error', $msg, 'error');
        return false;
    }
}

/* ======================= 3. AI RESEARCH + DRAFT ======================= */

function draft_lead(array $lead): string {
    $c = row('SELECT * FROM campaigns WHERE id = ? AND tenant_id = ?', [$lead['campaign_id'], tid()]);
    if (!$c) throw new RuntimeException('Campaign missing');
    [$sys, $user] = prompt_research_and_draft($c, $lead);
    $d = ai_json($sys, $user, 2500, 'draft');
    $emails = $d['emails'] ?? [];
    $want = 1 + (int) $c['followups'];
    if (count($emails) < 1 || empty($emails[0]['body'])) throw new RuntimeException('AI returned no emails');
    $score = max(0, min(100, (int) ($d['fit_score'] ?? 0)));
    $flags = draft_safety_issues(array_slice($emails, 0, $want), $c);
    $research = ['observations' => $d['observations'] ?? [], 'automation_ideas' => $d['automation_ideas'] ?? [], 'provider' => ai_provider(), 'flags' => $flags];
    q("DELETE FROM emails WHERE lead_id = ? AND status IN ('draft','approved','cancelled')", [$lead['id']]);
    $subject1 = trim((string) ($emails[0]['subject'] ?? '')) ?: 'quick question';
    foreach (array_slice($emails, 0, $want) as $i => $em) {
        $step = $i + 1;
        insert('emails', ['lead_id' => $lead['id'], 'step' => $step,
            'subject' => mb_substr($step === 1 ? $subject1 : 'Re: ' . $subject1, 0, 255),
            'body' => trim((string) $em['body']), 'status' => 'draft']);
    }
    if (!empty($c['linkedin_enabled']) && !empty($d['linkedin']['connection_note'])) {
        li_store_copy((int) $lead['id'], $d['linkedin']);
        $lr = row('SELECT li_note, li_msg1, li_msg2 FROM leads WHERE id = ?', [$lead['id']]);
        foreach (li_safety_issues($lr, $c) as $f) $flags[] = $f;
        $research['flags'] = $flags;
    }
    $status = $score < (int) $c['fit_threshold'] ? 'disqualified' : 'drafted';
    $first = trim((string) ($d['contact_first_name'] ?? ''));
    $aiName = trim(strip_tags((string) ($d['business_name'] ?? '')));
    $nameUpdate = (mb_strlen($aiName) >= 2 && mb_strlen($aiName) <= 100) ? ['business_name' => $aiName] : [];
    update('leads', $nameUpdate + ['fit_score' => $score, 'fit_reason' => mb_substr((string) ($d['fit_reason'] ?? ''), 0, 1000),
        'research' => json_encode($research), 'contact_name' => $first !== '' ? mb_substr($first, 0, 120) : null,
        'status' => $status, 'error' => null], 'id = ?', [$lead['id']]);
    log_event((int) $lead['id'], 'draft', "Fit $score/100 — " . ($status === 'drafted' ? 'drafted sequence' : 'below threshold'));
    if ($flags) log_event((int) $lead['id'], 'draft_flagged', 'Held for manual review: ' . implode('; ', $flags), 'warn');
    if ($status === 'drafted' && !$flags && $c['approval_mode'] === 'auto' && $score >= (int) $c['auto_min_score']) {
        approve_lead((int) $lead['id'], 'auto');
        return 'queued';
    }
    return $status;
}

function job_draft(float $deadline, int $max = 3): array {
    $stats = ['drafted' => 0, 'disqualified' => 0, 'queued' => 0];
    foreach (all("SELECT l.* FROM leads l JOIN campaigns c ON c.id = l.campaign_id WHERE l.tenant_id = ? AND l.status = 'enriched' AND c.status = 'active' ORDER BY l.id LIMIT $max", [tid()]) as $lead) {
        if (!deadline_left($deadline)) break;
        try {
            $s = draft_lead($lead);
            $stats[$s === 'disqualified' ? 'disqualified' : ($s === 'queued' ? 'queued' : 'drafted')]++;
        } catch (Throwable $ex) {
            $msg = $ex->getMessage();
            log_event((int) $lead['id'], 'draft_error', $msg, 'error');
            if (str_contains($msg, 'budget') || str_contains($msg, 'key missing')) break; // stop, retry later
            $att = (int) $lead['attempts'] + 1;
            update('leads', ['attempts' => $att, 'error' => mb_substr($msg, 0, 255), 'status' => $att >= 3 ? 'error' : 'enriched'], 'id = ?', [$lead['id']]);
        }
    }
    return $stats;
}

/**
 * Checks AI-written copy for things it should never contain (possible prompt injection from a scraped website).
 * Any finding blocks auto-approval and is shown on the lead page.
 */
function draft_safety_issues(array $emails, array $campaign): array {
    $allowed = array_filter([site_domain((string) setting('website')), site_domain((string) setting('cal_link'))]);
    if (preg_match_all('~https?://([^/\s)>"\']+)~i', $campaign['offer'] . ' ' . $campaign['proof'], $m)) foreach ($m[1] as $h) $allowed[] = strtolower(preg_replace('/^www\./', '', $h));
    $issues = [];
    foreach ($emails as $i => $em) {
        $n = $i + 1;
        $text = ($em['subject'] ?? '') . "\n" . ($em['body'] ?? '');
        if (preg_match_all('~(?:https?://|www\.)([^/\s)>"\']+)~i', $text, $m)) {
            foreach ($m[1] as $h) {
                $h = strtolower(preg_replace('/^www\./', '', rtrim($h, '.,;')));
                $ok = false;
                foreach ($allowed as $a) if ($h === $a || str_ends_with($h, '.' . $a)) $ok = true;
                if (!$ok) $issues[] = "Email $n links to $h";
            }
        }
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text)) $issues[] = "Email $n contains an email address";
        if (preg_match('/\(?\b\d{3}\)?[\s.-]\d{3}[\s.-]\d{4}\b/', $text)) $issues[] = "Email $n contains a phone number";
        if (str_word_count(strip_tags((string) ($em['body'] ?? ''))) > 220) $issues[] = "Email $n is unusually long";
        if (preg_match('/(ignore (all |any )?(previous|prior) instructions|api key|password|wire transfer|gift card|bitcoin|crypto|western union)/i', $text, $mm)) $issues[] = "Email $n mentions \"" . strtolower($mm[1]) . "\"";
    }
    return array_values(array_unique($issues));
}

/* ======================= 4. APPROVE ======================= */

function pick_mailbox(): ?array {
    // the active mailbox with the fewest leads currently assigned & in flight
    return row("SELECT m.*, (SELECT COUNT(*) FROM leads l WHERE l.mailbox_id = m.id AND l.status IN ('queued','contacted')) AS load_n
                FROM mailboxes m WHERE m.active = 1 AND m.tenant_id = ? ORDER BY load_n ASC, m.id ASC LIMIT 1", [tid()]);
}

function approve_lead(int $leadId, string $by = 'manual'): bool {
    $lead = row('SELECT * FROM leads WHERE id = ? AND tenant_id = ?', [$leadId, tid()]);
    if (!$lead || !in_array($lead['status'], ['drafted', 'disqualified'], true) || !$lead['email']) return false;
    if (is_suppressed($lead['email'])) { update('leads', ['status' => 'unsubscribed'], 'id = ?', [$leadId]); return false; }
    $mb = $lead['mailbox_id'] ? row('SELECT * FROM mailboxes WHERE id = ? AND active = 1 AND tenant_id = ?', [$lead['mailbox_id'], tid()]) : null;
    $mb = $mb ?: pick_mailbox();
    if (!$mb) throw new RuntimeException('Add an active mailbox before approving leads.');
    update('emails', ['status' => 'approved', 'mailbox_id' => $mb['id']], "lead_id = ? AND status = 'draft'", [$leadId]);
    update('emails', ['status' => 'scheduled', 'scheduled_for' => now()], "lead_id = ? AND step = 1 AND status = 'approved'", [$leadId]);
    update('leads', ['status' => 'queued', 'mailbox_id' => $mb['id']], 'id = ?', [$leadId]);
    log_event($leadId, 'approve', "Approved ($by), sending from {$mb['email']}");
    return true;
}

function cancel_sequence(int $leadId, string $why): void {
    $n = q("UPDATE emails SET status = 'cancelled' WHERE lead_id = ? AND status IN ('draft','approved','scheduled')", [$leadId])->rowCount();
    if ($n) log_event($leadId, 'sequence_stopped', "$n pending email(s) cancelled: $why");
    li_cancel($leadId, $why);    // the same events stop LinkedIn steps
}

/* ======================= 5. SEND ======================= */

function in_send_window(?string $tz): bool {
    try { $now = new DateTime('now', new DateTimeZone($tz ?: 'America/Chicago')); } catch (Throwable $e) { $now = new DateTime('now', new DateTimeZone('America/Chicago')); }
    $days = array_map('intval', explode(',', (string) setting('send_days')));
    if (!in_array((int) $now->format('N'), $days, true)) return false;
    $hm = $now->format('H:i');
    return $hm >= (string) setting('send_start') && $hm <= (string) setting('send_end');
}

function job_send(float $deadline): array {
    $stats = ['sent' => 0, 'failed' => 0];
    if (setting('sending_paused') === '1') return $stats + ['note' => 'sending paused'];
    foreach (all('SELECT * FROM mailboxes WHERE active = 1 AND tenant_id = ? ORDER BY last_sent_at IS NOT NULL, last_sent_at', [tid()]) as $mb) {
        if (!deadline_left($deadline)) break;
        if ($mb['next_send_after'] && strtotime($mb['next_send_after'] . ' UTC') > time()) continue;
        if (mailbox_sent_today($mb) >= mailbox_limit_today($mb)) continue;
        // safety: auto-pause an inbox whose 7-day bounce rate goes above 5% (protects the sending domain)
        $wk = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
        $sent7 = (int) val("SELECT COUNT(*) FROM emails WHERE mailbox_id = ? AND status = 'sent' AND sent_at >= ?", [$mb['id'], $wk]);
        $bounced7 = (int) val("SELECT COUNT(*) FROM leads WHERE mailbox_id = ? AND status = 'bounced' AND updated_at >= ?", [$mb['id'], $wk]);
        if ($sent7 >= 20 && $bounced7 / $sent7 > 0.05) {
            update('mailboxes', ['active' => 0, 'last_error' => "Auto-paused: $bounced7 bounces in $sent7 sends (7 days). Clean your list, then re-activate."], 'id = ?', [$mb['id']]);
            log_event(null, 'mailbox_paused', "{$mb['email']} auto-paused for high bounce rate", 'error');
            continue;
        }
        $candidates = all("SELECT e.*, l.timezone FROM emails e JOIN leads l ON l.id = e.lead_id
                           WHERE e.tenant_id = ? AND e.status = 'scheduled' AND e.scheduled_for <= ? AND e.mailbox_id = ? AND l.status IN ('queued','contacted')
                           ORDER BY (e.step = 1), e.scheduled_for LIMIT 25", [tid(), now(), $mb['id']]);
        foreach ($candidates as $em) {
            if (!in_send_window($em['timezone'])) continue;
            $ok = send_sequence_email((int) $em['id'], $mb);
            $stats[$ok ? 'sent' : 'failed']++;
            break; // one email per mailbox per run keeps a human-like pace
        }
    }
    return $stats;
}

function send_sequence_email(int $emailId, array $mb): bool {
    $em = row('SELECT * FROM emails WHERE id = ? AND tenant_id = ?', [$emailId, tid()]);
    if (!$em) return false;
    $lead = row('SELECT * FROM leads WHERE id = ? AND tenant_id = ?', [$em['lead_id'], tid()]);
    if (!$lead['email'] || is_suppressed($lead['email'])) {
        cancel_sequence((int) $lead['id'], 'suppressed');
        update('leads', ['status' => 'unsubscribed'], 'id = ?', [$lead['id']]);
        return false;
    }
    $prev = all("SELECT message_id FROM emails WHERE lead_id = ? AND status = 'sent' AND step < ? AND message_id IS NOT NULL ORDER BY step", [$lead['id'], $em['step']]);
    $refs = array_column($prev, 'message_id');
    $thread = $refs ? ['in_reply_to' => end($refs), 'references' => implode(' ', $refs)] : [];
    $body = compose_body($em['body'], $lead, $mb);
    $subject = render_placeholders($em['subject'], $lead, $mb);
    $toName = (string) ($lead['contact_name'] ?? '');
    try {
        $msgId = send_mail($mb, $lead['email'], $toName, $subject, $body, $thread, (int) $lead['id']);
    } catch (Throwable $ex) {
        $msg = mb_substr($ex->getMessage(), 0, 255);
        update('emails', ['status' => 'failed', 'error' => $msg], 'id = ?', [$emailId]);
        $hardBounce = (bool) preg_match('/\b5\.1\.[0-9]\b|user unknown|does not exist|recipient.*(rejected|invalid)/i', $msg);
        if ($hardBounce) {
            update('leads', ['status' => 'bounced', 'error' => $msg], 'id = ?', [$lead['id']]);
            suppress($lead['email'], 'bounce', 'email', true);
            cancel_sequence((int) $lead['id'], 'hard bounce');
        } else {
            // retry this email in 30 min, and slow this mailbox down
            update('emails', ['status' => 'scheduled', 'scheduled_for' => gmdate('Y-m-d H:i:s', time() + 1800)], 'id = ?', [$emailId]);
        }
        update('mailboxes', ['last_error' => $msg, 'next_send_after' => gmdate('Y-m-d H:i:s', time() + 1800)], 'id = ?', [$mb['id']]);
        log_event((int) $lead['id'], 'send_error', "{$mb['email']}: $msg", 'error');
        return false;
    }
    $sentToday = mailbox_sent_today($mb) + 1;
    $gap = random_int((int) setting('min_gap_min', 6) * 60, max((int) setting('min_gap_min', 6), (int) setting('max_gap_min', 14)) * 60);
    update('mailboxes', ['sent_today' => $sentToday, 'sent_date' => gmdate('Y-m-d'), 'last_sent_at' => now(),
        'next_send_after' => gmdate('Y-m-d H:i:s', time() + $gap), 'last_error' => null], 'id = ?', [$mb['id']]);
    update('emails', ['status' => 'sent', 'sent_at' => now(), 'message_id' => $msgId, 'error' => null], 'id = ?', [$emailId]);

    // schedule the next step
    $c = row('SELECT step_delays FROM campaigns WHERE id = ? AND tenant_id = ?', [$lead['campaign_id'], tid()]);
    $delays = array_map('intval', explode(',', (string) ($c['step_delays'] ?? '3,4,7')));
    $next = row("SELECT id, step FROM emails WHERE lead_id = ? AND step > ? AND status = 'approved' ORDER BY step LIMIT 1", [$lead['id'], $em['step']]);
    if ($next) {
        $days = $delays[$em['step'] - 1] ?? end($delays) ?: 4;
        $when = time() + $days * 86400 + random_int(0, 3 * 3600);
        update('emails', ['status' => 'scheduled', 'scheduled_for' => gmdate('Y-m-d H:i:s', $when)], 'id = ?', [$next['id']]);
    }
    update('leads', ['status' => $next ? 'contacted' : 'completed', 'current_step' => $em['step'], 'last_sent_at' => now()], 'id = ?', [$lead['id']]);
    log_event((int) $lead['id'], 'sent', "Step {$em['step']} sent from {$mb['email']}" . (setting('dry_run') === '1' ? ' (dry run)' : ''));
    return true;
}

/* ======================= 6. INBOX: replies + bounces ======================= */

function job_inbox(float $deadline, bool $force = false): array {
    $stats = ['checked' => 0, 'replies' => 0, 'bounces' => 0];
    foreach (all('SELECT * FROM mailboxes WHERE active = 1 AND tenant_id = ?', [tid()]) as $mb) {
        if (!deadline_left($deadline)) break;
        if (!$force && $mb['last_imap_check'] && strtotime($mb['last_imap_check'] . ' UTC') > time() - 600) continue;
        try {
            $r = check_mailbox($mb, $deadline);
            $stats['checked']++; $stats['replies'] += $r['replies']; $stats['bounces'] += $r['bounces'];
        } catch (Throwable $ex) {
            update('mailboxes', ['last_error' => mb_substr('IMAP: ' . $ex->getMessage(), 0, 255), 'last_imap_check' => now()], 'id = ?', [$mb['id']]);
            log_event(null, 'imap_error', "{$mb['email']}: " . $ex->getMessage(), 'error');
        }
    }
    return $stats;
}

function check_mailbox(array $mb, float $deadline): array {
    $out = ['replies' => 0, 'bounces' => 0];
    $imap = new MiniImap($mb['imap_host'], (int) $mb['imap_port'], (int) $mb['imap_port'] === 993);
    $imap->login($mb['username'], mailbox_password($mb));
    $imap->select('INBOX');
    $uids = array_slice($imap->newUids((int) $mb['last_imap_uid']), 0, 40);
    $maxUid = (int) $mb['last_imap_uid'];
    foreach ($uids as $uid) {
        if (!deadline_left($deadline)) break;
        $raw = $imap->fetchRaw($uid);
        $maxUid = max($maxUid, $uid);
        if ($raw === '') continue;
        $kind = process_incoming($mb, $raw);
        if ($kind === 'reply') $out['replies']++;
        if ($kind === 'bounce') $out['bounces']++;
    }
    $imap->logout();
    update('mailboxes', ['last_imap_uid' => $maxUid, 'last_imap_check' => now()], 'id = ?', [$mb['id']]);
    return $out;
}

/** Returns 'reply' | 'bounce' | 'auto' | 'ignored'. */
function process_incoming(array $mb, string $raw): string {
    [$head, $body] = mime_split($raw);
    $h = mime_headers($head);
    [$from, $fromName] = parse_address($h['from'] ?? '');
    $subject = mime_decode_header($h['subject'] ?? '');
    $msgId = trim($h['message-id'] ?? '') ?: '<nomsgid-' . md5($raw) . '>';
    if ($from === strtolower($mb['email'])) return 'ignored';
    if (val('SELECT 1 FROM replies WHERE mailbox_id = ? AND message_id = ?', [$mb['id'], mb_substr($msgId, 0, 255)])) return 'ignored';
    $text = mime_body_text($h, $body);
    $date = isset($h['date']) && strtotime($h['date']) ? gmdate('Y-m-d H:i:s', strtotime($h['date'])) : now();

    // ---- bounce?
    $isBounce = preg_match('/^(mailer-daemon|postmaster)@/i', $from)
        || str_contains(strtolower($h['content-type'] ?? ''), 'report-type=delivery-status')
        || preg_match('/(delivery status notification \(failure\)|undeliverable|mail delivery (failed|subsystem)|returned to sender|address not found)/i', $subject);
    if ($isBounce) {
        $lead = null;
        if (preg_match('/Final-Recipient:\s*rfc822;\s*<?([^\s>]+)/i', $raw, $m)) $lead = row('SELECT * FROM leads WHERE tenant_id = ? AND email = ?', [tid(), strtolower($m[1])]);
        if (!$lead && preg_match('/^X-Failed-Recipients:\s*(\S+)/im', $head, $m)) $lead = row('SELECT * FROM leads WHERE tenant_id = ? AND email = ?', [tid(), strtolower(trim($m[1], ' ,'))]);
        if (!$lead) {
            preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $raw, $all);
            foreach (array_unique(array_map('strtolower', $all[0])) as $cand) {
                if ($cand === strtolower($mb['email'])) continue;
                if ($lead = row("SELECT * FROM leads WHERE tenant_id = ? AND email = ? AND status IN ('queued','contacted','completed')", [tid(), $cand])) break;
            }
        }
        if (!$lead) return 'ignored';
        insert('replies', ['lead_id' => $lead['id'], 'mailbox_id' => $mb['id'], 'message_id' => mb_substr($msgId, 0, 255), 'from_email' => mb_substr($from, 0, 190),
            'subject' => mb_substr($subject, 0, 255), 'body' => mb_substr($text, 0, 4000), 'received_at' => $date, 'kind' => 'bounce', 'category' => 'bounce', 'handled' => 1]);
        update('leads', ['status' => 'bounced'], 'id = ?', [$lead['id']]);
        suppress($lead['email'], 'bounce', 'email', true);
        cancel_sequence((int) $lead['id'], 'bounced');
        log_event((int) $lead['id'], 'bounce', "Bounce received for {$lead['email']}", 'warn');
        return 'bounce';
    }

    // ---- match to a lead
    $lead = null;
    $refs = trim(($h['in-reply-to'] ?? '') . ' ' . ($h['references'] ?? ''));
    if ($refs !== '' && preg_match_all('/<[^>]+>/', $refs, $ids)) {
        foreach (array_reverse($ids[0]) as $id) {
            $e = row('SELECT lead_id FROM emails WHERE tenant_id = ? AND message_id = ?', [tid(), $id]);
            if ($e) { $lead = row('SELECT * FROM leads WHERE id = ? AND tenant_id = ?', [$e['lead_id'], tid()]); break; }
        }
    }
    if (!$lead) $lead = row('SELECT * FROM leads WHERE tenant_id = ? AND email = ? AND last_sent_at IS NOT NULL ORDER BY id DESC LIMIT 1', [tid(), $from]);
    if (!$lead) {
        $dom = substr(strrchr($from, '@') ?: '', 1);
        if ($dom) $lead = row('SELECT * FROM leads WHERE tenant_id = ? AND domain = ? AND last_sent_at IS NOT NULL ORDER BY id DESC LIMIT 1', [tid(), $dom]);
    }
    if (!$lead) return 'ignored'; // not related to outreach

    $auto = (isset($h['auto-submitted']) && strtolower($h['auto-submitted']) !== 'no')
        || preg_match('/(out of (the )?office|automatic reply|auto(-|\s)?reply|autoreply|away from (the )?office|vacation)/i', $subject);
    $reply = strip_quoted($text);
    $row = ['lead_id' => $lead['id'], 'mailbox_id' => $mb['id'], 'message_id' => mb_substr($msgId, 0, 255), 'from_email' => mb_substr($from, 0, 190),
        'from_name' => mb_substr($fromName, 0, 190), 'subject' => mb_substr($subject, 0, 255), 'body' => mb_substr($reply, 0, 8000), 'received_at' => $date];
    if ($auto) {
        insert('replies', $row + ['kind' => 'auto', 'category' => 'out_of_office', 'handled' => 1]);
        log_event((int) $lead['id'], 'auto_reply', 'Auto-reply received; sequence continues');
        return 'auto';
    }

    // ---- real reply: stop sequence, classify with AI
    cancel_sequence((int) $lead['id'], 'lead replied');
    $last = row("SELECT body FROM emails WHERE lead_id = ? AND status = 'sent' ORDER BY step DESC LIMIT 1", [$lead['id']]);
    $cat = 'other'; $summary = ''; $suggest = '';
    try {
        [$sys, $user] = prompt_classify_reply($lead, $reply, (string) ($last['body'] ?? ''));
        $d = ai_json($sys, $user, 800, 'classify');
        $cat = in_array($d['category'] ?? '', ['interested', 'question', 'not_interested', 'unsubscribe', 'out_of_office', 'referral', 'other'], true) ? $d['category'] : 'other';
        $summary = mb_substr((string) ($d['summary'] ?? ''), 0, 500);
        $suggest = (string) ($d['suggested_reply'] ?? '');
    } catch (Throwable $ex) {
        log_event((int) $lead['id'], 'classify_error', $ex->getMessage(), 'warn');
        if (preg_match('/\b(unsubscribe|remove me|stop emailing|take me off)\b/i', $reply)) $cat = 'unsubscribe';
    }
    insert('replies', $row + ['kind' => 'reply', 'category' => $cat, 'summary' => $summary, 'suggested_reply' => $suggest,
        'handled' => in_array($cat, ['not_interested', 'unsubscribe'], true) ? 1 : 0]);
    $status = match ($cat) {
        'interested', 'question', 'referral' => 'interested',
        'not_interested' => 'not_interested',
        'unsubscribe' => 'unsubscribed',
        default => 'replied',
    };
    if ($lead['status'] === 'booked') $status = 'booked';
    update('leads', ['status' => $status, 'replied_at' => $lead['replied_at'] ?: now(), 'reply_category' => $cat,
        'unsubscribed_at' => $cat === 'unsubscribe' ? now() : $lead['unsubscribed_at']], 'id = ?', [$lead['id']]);
    if ($cat === 'unsubscribe') suppress($lead['email'], 'unsubscribe_reply');
    log_event((int) $lead['id'], 'reply', "Reply from $from classified as $cat");
    if (in_array($cat, ['interested', 'question', 'referral'], true)) notify_owner($lead, $cat, $reply);
    return 'reply';
}

function notify_owner(array $lead, string $cat, string $reply): void {
    $to = trim((string) setting('notify_email'));
    $mb = row('SELECT * FROM mailboxes WHERE active = 1 AND tenant_id = ? ORDER BY id LIMIT 1', [tid()]);
    if ($to === '' || !$mb) return;
    $body = strtoupper($cat) . " reply from {$lead['business_name']} ({$lead['email']})\n\n" . mb_substr($reply, 0, 1500)
        . "\n\nOpen in dashboard: " . base_url('lead.php?id=' . $lead['id']) . "\n";
    try { send_mail($mb, $to, '', "[Outreach] $cat reply: {$lead['business_name']}", $body); }
    catch (Throwable $ex) { log_event((int) $lead['id'], 'notify_error', $ex->getMessage(), 'warn'); }
}

/** Send a manual (or AI-suggested) reply in the same thread. */
function send_manual_reply(int $replyId, string $text): void {
    $rp = row('SELECT * FROM replies WHERE id = ? AND tenant_id = ?', [$replyId, tid()]);
    if (!$rp || !$rp['lead_id']) throw new RuntimeException('Reply not found');
    $lead = row('SELECT * FROM leads WHERE id = ? AND tenant_id = ?', [$rp['lead_id'], tid()]);
    $mb = row('SELECT * FROM mailboxes WHERE id = ? AND tenant_id = ?', [$rp['mailbox_id'], tid()]);
    if (!$mb) throw new RuntimeException('Mailbox not found');
    $prev = array_column(all("SELECT message_id FROM emails WHERE lead_id = ? AND status = 'sent' AND message_id IS NOT NULL ORDER BY step", [$lead['id']]), 'message_id');
    $prev[] = $rp['message_id'];
    $subject = preg_match('/^re:/i', (string) $rp['subject']) ? $rp['subject'] : 'Re: ' . $rp['subject'];
    $to = $rp['from_email'];
    $leadForBody = $lead; $leadForBody['email'] = $to;
    $body = compose_body($text, $leadForBody, $mb);
    send_mail($mb, $to, (string) $rp['from_name'], $subject, $body, ['in_reply_to' => $rp['message_id'], 'references' => implode(' ', $prev)], (int) $lead['id']);
    update('replies', ['handled' => 1, 'sent_reply' => $text], 'id = ?', [$replyId]);
    log_event((int) $lead['id'], 'manual_reply', "Reply sent to $to from {$mb['email']}");
}

/* ======================= 7. BOOKINGS (cal webhook) ======================= */

function handle_booking(array $payload): string {
    $event = (string) ($payload['triggerEvent'] ?? 'UNKNOWN');
    $p = $payload['payload'] ?? [];
    $att = $p['attendees'][0] ?? [];
    $email = strtolower(trim((string) ($att['email'] ?? $p['responses']['email']['value'] ?? '')));
    $name = (string) ($att['name'] ?? '');
    $lead = null;
    if ($email) {
        $lead = row('SELECT * FROM leads WHERE tenant_id = ? AND email = ? ORDER BY id DESC LIMIT 1', [tid(), $email]);
        if (!$lead) {
            $dom = substr(strrchr($email, '@') ?: '', 1);
            $free = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com', 'aol.com'];
            if ($dom && !in_array($dom, $free, true)) $lead = row('SELECT * FROM leads WHERE tenant_id = ? AND domain = ? ORDER BY id DESC LIMIT 1', [tid(), $dom]);
        }
        if (!$lead) $lead = row('SELECT l.* FROM replies r JOIN leads l ON l.id = r.lead_id WHERE r.tenant_id = ? AND r.from_email = ? ORDER BY r.id DESC LIMIT 1', [tid(), $email]);
    }
    $start = isset($p['startTime']) && strtotime($p['startTime']) ? gmdate('Y-m-d H:i:s', strtotime($p['startTime'])) : null;
    insert('bookings', ['lead_id' => $lead['id'] ?? null, 'uid' => mb_substr((string) ($p['uid'] ?? ''), 0, 190), 'trigger_event' => mb_substr($event, 0, 60),
        'attendee_email' => mb_substr($email, 0, 190), 'attendee_name' => mb_substr($name, 0, 190), 'start_time' => $start,
        'title' => mb_substr((string) ($p['title'] ?? ''), 0, 255), 'raw' => mb_substr(json_encode($payload), 0, 60000)]);
    if (!$lead) { log_event(null, 'booking', "$event from $email (no matching lead)"); return 'unmatched'; }
    if (in_array($event, ['BOOKING_CREATED', 'BOOKING_RESCHEDULED', 'BOOKING_CONFIRMED', 'BOOKING_REQUESTED'], true)) {
        update('leads', ['status' => 'booked', 'booked_at' => $lead['booked_at'] ?: now()], 'id = ?', [$lead['id']]);
        cancel_sequence((int) $lead['id'], 'meeting booked');
        q('UPDATE replies SET handled = 1 WHERE lead_id = ?', [$lead['id']]);
    } elseif ($event === 'BOOKING_CANCELLED') {
        update('leads', ['status' => 'interested', 'booked_at' => null], 'id = ?', [$lead['id']]);
    }
    log_event((int) $lead['id'], 'booking', "$event — {$name} <{$email}>" . ($start ? " at $start UTC" : ''));
    return 'matched';
}

/* ======================= Runner ======================= */

/**
 * Runs the pipeline for every active workspace, least-recently-run first, splitting the time budget fairly.
 * $onlyTenant limits the run to one workspace (the "Run a step now" buttons).
 */
function run_jobs(array $jobs, int $budgetSeconds = 50, ?int $onlyTenant = null): array {
    $lockFile = APP_ROOT . '/storage/cron.lock';
    $fp = fopen($lockFile, 'c+');
    if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
        // A run still holds the lock. The OS frees it if PHP dies, so a lock older than 15 minutes means a run is stuck (e.g. a hung mail server).
        $held = (int) @file_get_contents($lockFile);
        if ($held && $held < time() - 900) report_error('A cron run has held the lock for ' . round((time() - $held) / 60) . ' minutes; new runs are being skipped.',
            ['level' => 'critical', 'source' => 'cron', 'fingerprint' => 'cron-stuck', 'title' => 'Cron run is stuck']);
        return ['locked' => 'another run is in progress'];
    }
    ftruncate($fp, 0); fwrite($fp, (string) time()); fflush($fp);
    @set_time_limit($budgetSeconds + 30);
    $deadline = microtime(true) + $budgetSeconds;
    $previous = tid();
    $tenants = $onlyTenant !== null ? [$onlyTenant] : array_map('intval', array_column(all(
        "SELECT t.id FROM tenants t LEFT JOIN tenant_settings s ON s.tenant_id = t.id AND s.k = 'last_run_at'
         WHERE t.status = 'active' ORDER BY (s.v IS NOT NULL), s.v, t.id"), 'id'));
    $out = [];
    foreach ($tenants as $i => $t) {
        $left = $deadline - microtime(true);
        if ($left < 3) break;
        $slice = $onlyTenant !== null ? $left : max(6.0, $left / (count($tenants) - $i));
        $tDeadline = min($deadline, microtime(true) + $slice);
        set_tenant($t);
        foreach ($jobs as $job) {
            if (!deadline_left($tDeadline)) break;
            try {
                $out[$t][$job] = match ($job) {
                    'send' => job_send($tDeadline),
                    'inbox' => job_inbox($tDeadline),
                    'inbox_now' => job_inbox($tDeadline, true),
                    'find' => job_find($tDeadline),
                    'enrich' => job_enrich($tDeadline),
                    'draft' => job_draft($tDeadline),
                    'linkedin' => job_linkedin($tDeadline),
                    default => ['error' => 'unknown job'],
                };
            } catch (Throwable $ex) {
                $out[$t][$job] = ['error' => $ex->getMessage()];
                log_event(null, "job_$job", $ex->getMessage(), 'error');
                report_error($ex, ['level' => 'error', 'source' => 'job', 'context' => ['job' => $job, 'workspace' => $t]]);
            }
        }
        set_tenant_setting('last_run_at', now());
    }
    set_tenant($previous ?: null);
    if ($onlyTenant === null) set_setting('last_cron_at', now());
    ftruncate($fp, 0); flock($fp, LOCK_UN); fclose($fp);
    return $out;
}
