<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$lead = owned_row('leads', $id);
if (!$lead) { http_response_code(404); deny('That lead doesn’t exist in this workspace.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_role('member');
    $a = (string) ($_POST['action'] ?? '');
    try {
        if ($a === 'save' || $a === 'save_approve') {
            foreach ((array) ($_POST['subject'] ?? []) as $eid => $subj) {
                update('emails', ['subject' => mb_substr(trim((string) $subj), 0, 255), 'body' => trim((string) ($_POST['body'][$eid] ?? ''))],
                    "id = ? AND lead_id = ? AND status IN ('draft','approved','scheduled')", [(int) $eid, $id]);
            }
            $newEmail = strtolower(trim((string) ($_POST['email'] ?? '')));
            $data = ['contact_name' => trim((string) ($_POST['contact_name'] ?? '')) ?: null];
            if ($newEmail !== '' && $newEmail !== $lead['email'] && filter_var($newEmail, FILTER_VALIDATE_EMAIL) && !$lead['last_sent_at']) {
                $data['email'] = $newEmail; $data['email_source'] = 'manual';
            }
            update('leads', $data, 'id = ?', [$id]);
            if ($a === 'save_approve') {
                if ($lead['status'] === 'disqualified') update('leads', ['status' => 'drafted'], 'id = ?', [$id]);
                flash(approve_lead($id) ? 'Approved — first email will go out in the lead\'s business hours.' : 'Could not approve (check status/email).', 'ok');
            } else flash('Saved.');
        } elseif ($a === 'redraft') {
            $l = owned_row('leads', $id);
            if ($l['last_sent_at']) throw new RuntimeException('Already contacted — cannot re-draft.');
            $s = draft_lead($l);
            flash("Re-drafted with AI ($s).");
        } elseif ($a === 'research') {
            update('leads', ['status' => 'new', 'error' => null, 'attempts' => 0], 'id = ?', [$id]);
            $ok = enrich_lead(owned_row('leads', $id));
            flash($ok ? 'Website re-researched — email found; the AI will draft it next.' : 'Re-researched, but no usable email (see status).', $ok ? 'ok' : 'err');
        } elseif ($a === 'booked') {
            update('leads', ['status' => 'booked', 'booked_at' => now()], 'id = ?', [$id]);
            cancel_sequence($id, 'marked booked manually');
            log_event($id, 'booking', 'Marked as booked manually');
            flash('Marked as booked.');
        } elseif ($a === 'stop') {
            cancel_sequence($id, 'stopped manually');
            update('leads', ['status' => $lead['last_sent_at'] ? 'completed' : 'disqualified'], 'id = ?', [$id]);
            flash('Sequence stopped.');
        } elseif ($a === 'save_li') {
            $url = trim((string) ($_POST['linkedin_url'] ?? ''));
            $norm = $url === '' ? null : li_normalize($url);
            if ($url !== '' && !$norm) throw new RuntimeException('That isn’t a LinkedIn profile or company link (linkedin.com/in/… or linkedin.com/company/…).');
            $note = trim((string) ($_POST['li_note'] ?? ''));
            if (mb_strlen($note) > LI_NOTE_MAX) throw new RuntimeException('The connection note is over ' . LI_NOTE_MAX . ' characters.');
            update('leads', ['linkedin_url' => $norm[0] ?? null, 'linkedin_kind' => $norm[1] ?? null, 'li_note' => $note ?: null,
                'li_msg1' => trim((string) ($_POST['li_msg1'] ?? '')) ?: null, 'li_msg2' => trim((string) ($_POST['li_msg2'] ?? '')) ?: null], 'id = ?', [$id]);
            // keep open tasks in step with the edited text
            foreach (['connect' => 'li_note', 'message' => 'li_msg1', 'followup' => 'li_msg2'] as $k => $col)
                q("UPDATE li_tasks SET body = ? WHERE lead_id = ? AND kind = ? AND status = 'pending'", [trim((string) ($_POST[$col] ?? '')), $id, $k]);
            if (!$norm && in_array($lead['li_state'], ['none', 'queued'], true)) li_cancel($id, 'LinkedIn link removed');
            flash('LinkedIn details saved.');
        } elseif ($a === 'li_draft') {
            $camp = owned_row('campaigns', (int) $lead['campaign_id']);
            if (!$camp) throw new RuntimeException('Campaign missing');
            li_draft_copy(owned_row('leads', $id), $camp);
            flash('LinkedIn note and messages written.');
        } elseif (str_starts_with($a, 'li_')) {
            flash(li_record($id, substr($a, 3), (int) current_user()['id']));
        } elseif ($a === 'suppress') {
            if ($lead['email']) suppress($lead['email'], 'manual');
            if (!empty($_POST['domain']) && $lead['domain']) suppress($lead['domain'], 'manual', 'domain');
            cancel_sequence($id, 'suppressed manually');
            update('leads', ['status' => 'unsubscribed', 'unsubscribed_at' => now()], 'id = ?', [$id]);
            flash('Added to do-not-contact list.');
        }
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); }
    redirect('lead.php?id=' . $id);
}

$c = owned_row('campaigns', (int) $lead['campaign_id']);
$emails = all('SELECT * FROM emails WHERE lead_id = ? ORDER BY step', [$id]);
$replies = all('SELECT * FROM replies WHERE lead_id = ? ORDER BY received_at', [$id]);
$events = all('SELECT * FROM events WHERE lead_id = ? AND tenant_id = ? ORDER BY id DESC LIMIT 40', [$id, tid()]);
$mb = $lead['mailbox_id'] ? owned_row('mailboxes', (int) $lead['mailbox_id']) : null;
$research = json_decode((string) $lead['research'], true) ?: [];
$signals = json_decode((string) $lead['signals'], true) ?: [];
$editable = can('member') && (!$lead['last_sent_at'] || in_array($lead['status'], ['contacted', 'queued'], true));

page_header($lead['business_name'], 'leads');
?>
<div class="lead-head"><?= status_badge($lead['status']) ?> <?= score_pill($lead['fit_score']) ?>
  <span class="muted"><?= e($lead['category']) ?> · <?= e($c['name'] ?? '') ?></span></div>

<div class="grid-lead">
<div>
  <section class="card">
    <h2>Sequence</h2>
    <?php if (!empty($research['flags']) && in_array($lead['status'], ['drafted', 'disqualified'], true)): ?><div class="flag-box"><strong>Check before approving:</strong> <?= e(implode('; ', $research['flags'])) ?>. Auto-approve skipped this lead.</div><?php endif; ?>
    <?php if (!$emails): ?><p class="muted">No drafts yet. <?= $lead['status'] === 'enriched' ? 'The AI will draft this lead on the next run.' : '' ?></p><?php endif; ?>
    <form method="post"><?= csrf_field() ?>
    <div class="contact-row">
      <label>Send to<input name="email" value="<?= e($lead['email']) ?>" <?= $lead['last_sent_at'] ? 'readonly' : '' ?>></label>
      <label>First name (optional)<input name="contact_name" value="<?= e($lead['contact_name']) ?>" placeholder="used for {{first_name}}"></label>
    </div>
    <?php foreach ($emails as $em): $locked = !can('member') || !in_array($em['status'], ['draft', 'approved', 'scheduled'], true); ?>
      <div class="email-step <?= e($em['status']) ?>">
        <div class="step-head"><b>Email <?= $em['step'] ?></b>
          <span class="badge <?= $em['status'] === 'sent' ? 'good' : ($em['status'] === 'failed' ? 'bad' : 'muted') ?>"><?= e($em['status']) ?></span>
          <span class="muted small"><?= $em['sent_at'] ? 'sent ' . e(local_time($em['sent_at'])) : ($em['scheduled_for'] ? 'due ' . e(local_time($em['scheduled_for'])) : ($em['step'] > 1 ? 'after ' . e(explode(',', $c['step_delays'] ?? '')[$em['step'] - 2] ?? '?') . ' days' : '')) ?></span>
          <?php if ($em['error']): ?><span class="err small"><?= e($em['error']) ?></span><?php endif; ?>
        </div>
        <?php if ($locked): ?>
          <div class="subj"><?= e($em['subject']) ?></div><pre class="body"><?= e($em['body']) ?></pre>
        <?php else: ?>
          <input class="subj-in" name="subject[<?= $em['id'] ?>]" value="<?= e($em['subject']) ?>" <?= $em['step'] > 1 ? 'readonly title="Follow-ups reply in the same thread"' : '' ?>>
          <textarea name="body[<?= $em['id'] ?>]" rows="<?= max(5, substr_count($em['body'], "\n") + 3) ?>"><?= e($em['body']) ?></textarea>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <p class="hint">Placeholders: {{cal_link}}, {{first_name}}, {{business}}, {{city}}. Signature, postal address and opt-out line are added automatically<?= $mb ? ' (from ' . e($mb['email']) . ')' : '' ?>.</p>
    <?php if (can('member')): ?><div class="actions">
      <?php if ($emails && $editable): ?><button name="action" value="save" class="btn">Save</button><?php endif; ?>
      <?php if (in_array($lead['status'], ['drafted', 'disqualified'], true) && $emails): ?><button name="action" value="save_approve" class="btn primary">Save &amp; approve</button><?php endif; ?>
      <?php if (!$lead['last_sent_at'] && $lead['email'] && $lead['site_excerpt']): ?><button name="action" value="redraft" class="btn" data-confirm="Replace the current drafts with a new AI version?">Re-draft with AI</button><?php endif; ?>
      <?php if (!$lead['last_sent_at'] && $lead['website']): ?><button name="action" value="research" class="btn ghost">Re-research site</button><?php endif; ?>
      <?php if (in_array($lead['status'], ['queued', 'contacted'], true)): ?><button name="action" value="stop" class="btn">Stop sequence</button><?php endif; ?>
      <?php if ($lead['status'] !== 'booked'): ?><button name="action" value="booked" class="btn">Mark booked</button><?php endif; ?>
      <button name="action" value="suppress" class="btn danger" data-confirm="Never email this address again?">Do not contact</button>
    </div><?php endif; ?>
    </form>
  </section>

  <?php if ($replies): ?>
  <section class="card"><h2>Replies</h2>
    <?php foreach ($replies as $r): ?>
      <div class="reply <?= e($r['kind']) ?>"><div class="step-head"><b><?= e($r['from_email']) ?></b> <span class="badge info"><?= e($r['category']) ?></span>
        <span class="muted small"><?= e(local_time($r['received_at'])) ?></span></div>
        <pre class="body"><?= e($r['body']) ?></pre>
        <?php if ($r['sent_reply']): ?><div class="muted small">You replied:</div><pre class="body mine"><?= e($r['sent_reply']) ?></pre>
        <?php elseif ($r['kind'] === 'reply' && !$r['handled']): ?><a class="btn primary" href="replies.php?open=<?= $r['id'] ?>">Reply</a><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>
</div>

<div>
  <section class="card">
    <h2>Business</h2>
    <dl class="kv">
      <dt>Website</dt><dd><?= $lead['website'] ? '<a href="' . e($lead['website']) . '" target="_blank" rel="noopener">' . e($lead['domain']) . '</a>' : '—' ?></dd>
      <dt>Phone</dt><dd><?= e($lead['phone'] ?? '—') ?></dd>
      <dt>Address</dt><dd><?= e($lead['address'] ?? '—') ?></dd>
      <dt>Found by</dt><dd><?= e(trim(($lead['category'] ?? '') . ' in ' . ($lead['search_location'] ?: trim($lead['city'] . ', ' . $lead['region'], ', ')), ' in')) ?><?= $lead['place_id'] ? ' · <a href="https://www.google.com/maps/place/?q=place_id:' . rawurlencode((string) $lead['place_id']) . '" target="_blank" rel="noopener">Google Maps</a>' : '' ?></dd>
      <dt>Email found on</dt><dd class="small"><?= $lead['email_source'] && str_starts_with($lead['email_source'], 'http') ? '<a href="' . e($lead['email_source']) . '" target="_blank" rel="noopener">' . e($lead['email_source']) . '</a>' : e($lead['email_source'] ?? '—') ?><?= $lead['email_found_at'] ? '<br><span class="muted">found ' . e(local_time($lead['email_found_at'], 'M j, Y')) . '</span>' : '' ?></dd>
      <dt>Local time zone</dt><dd><?= e($lead['timezone']) ?></dd>
      <dt>Sending inbox</dt><dd><?= e($mb['email'] ?? 'assigned on approval') ?></dd>
    </dl>
  </section>
  <?php if ($lead['linkedin_url'] || !empty($c['linkedin_enabled'])): $liOpen = can('member'); ?>
  <section class="card li-card" id="linkedin">
    <div class="card-head"><h2>LinkedIn</h2><span class="badge <?= in_array($lead['li_state'], ['replied', 'connected', 'messaged'], true) ? 'good' : 'muted' ?>"><?= e(LI_STATES[$lead['li_state']] ?? $lead['li_state']) ?></span></div>
    <?php if (empty($c['linkedin_enabled'])): ?><p class="small muted">LinkedIn steps are off for this campaign. <?= can('admin') ? '<a href="campaigns.php?edit=' . (int) $c['id'] . '">Turn them on</a>.' : '' ?></p><?php endif; ?>
    <form method="post" class="form"><?= csrf_field() ?>
      <label class="field"><span class="lbl-row"><span>Profile or company page</span><?= $lead['linkedin_url'] ? '<a href="' . e($lead['linkedin_url']) . '" target="_blank" rel="noopener noreferrer">Open' . icon('open_in_new') . '</a>' : '' ?></span>
        <input name="linkedin_url" value="<?= e($lead['linkedin_url']) ?>" placeholder="https://www.linkedin.com/in/…" <?= $liOpen ? '' : 'readonly' ?>></label>
      <?php if (!$lead['linkedin_url']): ?><p class="small muted">Their website doesn’t link to LinkedIn. Paste the owner’s profile here if you find it.</p><?php endif; ?>
      <label class="field"><span class="lbl-row"><span>Connection note</span><small id="li-cc" class="char-count"></small></span>
        <textarea name="li_note" rows="3" data-maxchars="<?= LI_NOTE_MAX ?>" data-counter="li-cc" <?= $liOpen ? '' : 'readonly' ?>><?= e($lead['li_note']) ?></textarea></label>
      <label class="field">Message after they accept<textarea name="li_msg1" rows="4" <?= $liOpen ? '' : 'readonly' ?>><?= e($lead['li_msg1']) ?></textarea></label>
      <label class="field">Follow-up message<textarea name="li_msg2" rows="3" <?= $liOpen ? '' : 'readonly' ?>><?= e($lead['li_msg2']) ?></textarea></label>
      <?php if ($liOpen): ?><div class="actions">
        <button name="action" value="save_li" class="btn" data-press>Save</button>
        <?php if (!empty($c['linkedin_enabled']) && $lead['site_excerpt'] && !in_array($lead['li_state'], ['replied', 'closed'], true)): ?><button name="action" value="li_draft" class="btn ghost" data-press <?= $lead['li_note'] ? 'data-confirm="Replace the LinkedIn texts with a new AI version?"' : '' ?>>Write with AI</button><?php endif; ?>
      </div>
      <?php if ($lead['linkedin_url'] && !in_array($lead['li_state'], ['replied'], true)): ?><div class="actions li-actions">
        <?php if (in_array($lead['li_state'], ['none', 'queued'], true)): ?><button name="action" value="li_sent_request" class="btn small" data-press>I sent a request</button><button name="action" value="li_already_connected" class="btn small" data-press>Already connected</button><?php endif; ?>
        <?php if ($lead['li_state'] === 'requested'): ?><button name="action" value="li_accepted" class="btn small filled" data-press>Accepted</button><button name="action" value="li_not_accepted" class="btn small" data-press>Not accepted</button><?php endif; ?>
        <button name="action" value="li_replied" class="btn small" data-press>Replied on LinkedIn</button>
        <button name="action" value="li_not_interested" class="btn small text" data-press>Not interested</button>
        <button name="action" value="li_asked_to_stop" class="btn small text danger" data-confirm="Stop all contact with this lead, on email and LinkedIn?" data-press>Asked to stop</button>
      </div><?php endif; endif; ?>
      <p class="hint">Placeholders: {{first_name}}, {{business}}, {{city}}, {{cal_link}}. Sent by hand from the LinkedIn tasks page.</p>
    </form>
  </section>
  <?php endif; ?>
  <section class="card">
    <h2>AI research</h2>
    <?php if (!empty($research['flags'])): ?><div class="flag-box"><strong>Held for manual review</strong> — the AI copy contains something it shouldn’t. Check it before approving:<ul><?php foreach ($research['flags'] as $f) echo '<li>' . e($f) . '</li>'; ?></ul></div><?php endif; ?>
    <?php if ($lead['fit_reason']): ?><p><strong>Fit:</strong> <?= e($lead['fit_reason']) ?></p><?php endif; ?>
    <?php if (!empty($research['observations'])): ?><h3>Observations</h3><ul><?php foreach ($research['observations'] as $o) echo '<li>' . e($o) . '</li>'; ?></ul><?php endif; ?>
    <?php if (!empty($research['automation_ideas'])): ?><h3>Automation ideas</h3><ul><?php foreach ($research['automation_ideas'] as $o) echo '<li>' . e($o) . '</li>'; ?></ul><?php endif; ?>
    <?php if ($signals): ?><h3>Website signals</h3><div class="sigs">
      <?php foreach ($signals as $k => $v): if (is_bool($v)) echo '<span class="sig ' . ($v ? 'yes' : 'no') . '">' . e(['after_hours_or_24_7' => '24/7 or after-hours', 'meta_pixel' => 'Meta pixel', 'google_analytics' => 'Google Analytics', 'text_us' => 'text us'][$k] ?? str_replace('_', ' ', $k)) . '</span>'; endforeach; ?>
      <?php if (!empty($signals['cms']) && $signals['cms'] !== 'unknown') echo '<span class="sig">' . e($signals['cms']) . '</span>'; ?>
      <?php if (!empty($signals['copyright_year'])) echo '<span class="sig">© ' . (int) $signals['copyright_year'] . '</span>'; ?>
    </div><?php endif; ?>
    <?php if (!$lead['fit_reason'] && !$signals): ?><p class="muted">Not researched yet.</p><?php endif; ?>
  </section>
  <section class="card"><h2>Timeline</h2>
    <?php foreach ($events as $ev): ?><div class="act <?= e($ev['level']) ?>"><time><?= e(local_time($ev['created_at'])) ?></time><span><?= e($ev['detail']) ?></span></div><?php endforeach; ?>
  </section>
</div>
</div>
<?php page_footer();
