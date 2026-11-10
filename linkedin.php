<?php
declare(strict_types=1);
/**
 * LinkedIn tasks: today's connection requests and messages, done by hand in the person's own LinkedIn.
 * The app never signs in to LinkedIn or sends anything there.
 */
require __DIR__ . '/lib/bootstrap.php';
require_login();
$me = (int) current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_role('member');
    $leadId = (int) ($_POST['lead_id'] ?? 0);
    try {
        if (!owned_row('leads', $leadId)) throw new RuntimeException('That lead isn’t in this workspace.');
        flash(li_record($leadId, (string) ($_POST['action'] ?? ''), $me));
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); }
    redirect('linkedin.php' . (isset($_POST['tab']) && $_POST['tab'] === 'waiting' ? '#waiting' : ''));
}

$cap = li_daily_cap();
$doneToday = li_requests_today($me);
$left = max(0, $cap - $doneToday);
$cols = 't.id task_id, t.kind, t.body, t.due_at, l.id, l.business_name, l.city, l.region, l.contact_name, l.email, l.linkedin_url, l.linkedin_kind, l.category, l.campaign_id';
$connectDue = (int) val("SELECT COUNT(*) FROM li_tasks WHERE tenant_id = ? AND kind = 'connect' AND status = 'pending' AND due_at <= ?", [tid(), now()]);
$connect = $left ? all("SELECT $cols FROM li_tasks t JOIN leads l ON l.id = t.lead_id WHERE t.tenant_id = ? AND t.kind = 'connect' AND t.status = 'pending' AND t.due_at <= ? ORDER BY l.fit_score DESC, t.due_at LIMIT " . min($left, 50), [tid(), now()]) : [];
$messages = all("SELECT $cols FROM li_tasks t JOIN leads l ON l.id = t.lead_id WHERE t.tenant_id = ? AND t.kind IN ('message','followup') AND t.status = 'pending' AND t.due_at <= ? ORDER BY t.due_at LIMIT 50", [tid(), now()]);
$waiting = all("SELECT l.id, l.business_name, l.city, l.region, l.linkedin_url, l.li_requested_at FROM leads l WHERE l.tenant_id = ? AND l.li_state = 'requested' ORDER BY l.li_requested_at LIMIT 100", [tid()]);
$upcoming = (int) val("SELECT COUNT(*) FROM li_tasks WHERE tenant_id = ? AND status = 'pending' AND due_at > ?", [tid(), now()]);
$st = li_stats();
$member = can('member');

$card = function (array $t) use ($member): void {
    $lead = $t;
    $text = li_render((string) $t['body'], $lead);
    $isNote = $t['kind'] === 'connect';
    $label = ['connect' => 'Connection note', 'message' => 'Message after they accepted', 'followup' => 'Follow-up message'][$t['kind']];
    $cid = 'cc' . $t['task_id'];
    ?>
  <article class="card li-task">
    <div class="li-head">
      <div><a class="strong" href="lead.php?id=<?= (int) $t['id'] ?>"><?= e($t['business_name']) ?></a>
        <div class="muted small"><?= e(trim($t['city'] . ', ' . $t['region'], ', ')) ?><?= $t['contact_name'] ? ' · ' . e($t['contact_name']) : '' ?> · due <?= e(ago($t['due_at'])) ?></div></div>
      <a class="btn tonal li-open" href="<?= e($t['linkedin_url']) ?>" target="_blank" rel="noopener noreferrer"><?= icon('open_in_new') ?>Open LinkedIn</a>
    </div>
    <?php if ($t['linkedin_kind'] === 'company' && $isNote): ?><p class="li-hint"><?= icon('info') ?>This is the company page. Open <b>People</b> and connect with the owner or manager<?= $t['contact_name'] ? ' (' . e($t['contact_name']) . ')' : '' ?>.</p><?php endif; ?>
    <label class="field"><span class="lbl-row"><span><?= e($label) ?></span><?php if ($isNote): ?><small id="<?= $cid ?>" class="char-count"></small><?php endif; ?></span>
      <span class="copy tall li-copy"><textarea readonly rows="<?= $isNote ? 3 : 5 ?>" <?= $isNote ? 'data-maxchars="' . LI_NOTE_MAX . '" data-counter="' . $cid . '"' : '' ?>><?= e($text) ?></textarea><button type="button" class="icon-btn" data-copy data-copied="1" aria-label="Copy text"><?= icon('content_copy') ?></button></span></label>
    <?php if ($member): ?>
    <form method="post" class="actions li-actions"><?= csrf_field() ?><input type="hidden" name="lead_id" value="<?= (int) $t['id'] ?>">
      <?php if ($isNote): ?>
        <button class="btn filled" name="action" value="sent_request" data-press><?= icon('check') ?>Sent request</button>
        <button class="btn tonal" name="action" value="sent_request_no_note" data-press title="Free accounts can only add a note a few times a month">Sent without note</button>
        <button class="btn text" name="action" value="already_connected" data-press>Already connected</button>
        <button class="btn text" name="action" value="not_found" data-press>Can’t find them</button>
      <?php else: ?>
        <button class="btn filled" name="action" value="<?= $t['kind'] === 'message' ? 'sent_message' : 'sent_followup' ?>" data-press><?= icon('check') ?>Sent</button>
        <button class="btn tonal" name="action" value="replied" data-press>They replied</button>
        <button class="btn text" name="action" value="skip_message" data-press>Skip</button>
      <?php endif; ?>
    </form>
    <?php endif; ?>
  </article>
<?php };

page_header('LinkedIn tasks', 'linkedin', 'Do these in your own LinkedIn: open the profile, paste the text, then record what you did here. The app never signs in to LinkedIn or sends anything for you.');
?>
<div class="kpis">
  <div class="kpi accent"><span>Due now</span><strong><?= count($connect) + count($messages) ?></strong><em><?= $upcoming ?> more scheduled later</em></div>
  <div class="kpi <?= $left ? '' : 'alarm' ?>"><span>Your requests today</span><strong><?= $doneToday ?> / <?= $cap ?></strong><em>safe daily limit per person</em></div>
  <div class="kpi"><span>Waiting for acceptance</span><strong><?= count($waiting) ?></strong><em>closed after <?= LI_REQUEST_EXPIRE_DAYS ?> days</em></div>
  <div class="kpi"><span>LinkedIn replies · 30 days</span><strong><?= $st['replies'] ?></strong><em><?= $st['accepted'] ?> accepted · <?= $st['requests'] ?> request<?= $st['requests'] === 1 ? '' : 's' ?> sent</em></div>
</div>

<h2 class="group-title">Connection requests <span class="tag"><?= $connectDue ?> due</span></h2>
<?php if (!$left && $connectDue): ?>
  <div class="banner warn"><?= icon('front_hand') ?><span>You’ve sent <?= $doneToday ?> requests today — that’s the safe limit. The rest wait until tomorrow, so your LinkedIn account isn’t restricted.</span></div>
<?php elseif (!$connect): ?>
  <p class="empty-row"><?= icon('task_alt', 'fill') ?> No connection requests due. They appear <?= (int) (val('SELECT MIN(li_connect_after_days) FROM campaigns WHERE tenant_id = ? AND linkedin_enabled = 1', [tid()]) ?? 1) ?> day(s) after a lead’s first email goes out.</p>
<?php else: ?>
  <?php if ($connectDue > count($connect)): ?><p class="small muted">Showing your next <?= count($connect) ?> (best fit first). <?= $connectDue - count($connect) ?> more wait for tomorrow’s limit.</p><?php endif; ?>
  <div class="li-grid"><?php foreach ($connect as $t) $card($t); ?></div>
<?php endif; ?>

<h2 class="group-title">Messages to send <span class="tag"><?= count($messages) ?></span></h2>
<?php if ($messages): ?><div class="li-grid"><?php foreach ($messages as $t) $card($t); ?></div>
<?php else: ?><p class="empty-row"><?= icon('task_alt', 'fill') ?> Nothing to send. A message appears as soon as you mark a request accepted.</p><?php endif; ?>

<h2 class="group-title" id="waiting">Waiting for acceptance</h2>
<p class="small muted">Check LinkedIn’s “My Network” for new connections, then record them here.</p>
<table class="t">
  <tr><th>Business</th><th>Requested</th><th></th></tr>
  <?php foreach ($waiting as $w): ?>
  <tr><td><a href="lead.php?id=<?= (int) $w['id'] ?>" class="strong"><?= e($w['business_name']) ?></a><br><span class="muted small"><?= e(trim($w['city'] . ', ' . $w['region'], ', ')) ?> · <a href="<?= e($w['linkedin_url']) ?>" target="_blank" rel="noopener noreferrer">LinkedIn</a></span></td>
    <td class="small nowrap"><?= e(ago($w['li_requested_at'])) ?></td>
    <td class="nowrap"><?php if ($member): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="lead_id" value="<?= (int) $w['id'] ?>"><input type="hidden" name="tab" value="waiting">
      <button class="btn small filled" name="action" value="accepted" data-press>Accepted</button>
      <button class="btn small" name="action" value="replied" data-press>They replied</button>
      <button class="btn small text" name="action" value="not_accepted" data-press>Not accepted</button></form><?php endif; ?></td></tr>
  <?php endforeach; if (!$waiting): ?><tr><td colspan="3" class="muted">No open requests.</td></tr><?php endif; ?>
</table>

<section class="panel">
  <h2>How this keeps your account safe</h2>
  <ul class="small muted plain-list">
    <li>LinkedIn doesn’t allow software to send requests or messages for you, so a person does the sending. That keeps everyone’s account in good standing.</li>
    <li>At most <?= $cap ?> connection requests per person per day (admins can change this in Settings). LinkedIn also limits weekly invitations.</li>
    <li>Free accounts can only add a note to a few requests a month. If LinkedIn won’t let you add one, use <b>Sent without note</b> — the message after they accept does the work.</li>
    <li>A reply, booking or opt-out on email or LinkedIn stops both channels for that lead.</li>
  </ul>
</section>
<?php page_footer();
