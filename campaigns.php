<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_login();

$fields = ['name', 'vertical', 'keywords', 'locations', 'icp', 'offer', 'proof', 'min_reviews', 'max_reviews', 'min_rating',
    'daily_new_leads', 'fit_threshold', 'approval_mode', 'auto_min_score', 'followups', 'step_delays', 'status',
    'li_connect_after_days', 'li_followup_days'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_role('admin');
    $id = (int) ($_POST['id'] ?? 0);
    if ($id && !owned_row('campaigns', $id)) deny();
    $a = (string) ($_POST['action'] ?? 'save');
    if ($a === 'toggle') {
        q("UPDATE campaigns SET status = IF(status='active','paused','active') WHERE id = ? AND tenant_id = ?", [$id, tid()]);
        redirect('campaigns.php');
    }
    if ($a === 'reset_queries') {
        q("UPDATE search_queries SET status='pending', pages_done=0, next_page_token=NULL, error=NULL WHERE campaign_id = ? AND tenant_id = ?", [$id, tid()]);
        flash('Searches reset — they will run again (already-found businesses are skipped).');
        redirect('campaigns.php?edit=' . $id);
    }
    $d = [];
    foreach ($fields as $f) $d[$f] = trim((string) ($_POST[$f] ?? ''));
    $d['followups'] = max(0, min(5, (int) $d['followups']));
    $d['step_delays'] = implode(',', array_map(fn($x) => max(1, (int) $x), array_filter(explode(',', $d['step_delays']), 'strlen'))) ?: '3,4,7';
    foreach (['min_reviews', 'max_reviews', 'daily_new_leads', 'fit_threshold', 'auto_min_score'] as $n) $d[$n] = (int) $d[$n];
    $d['min_rating'] = (float) $d['min_rating'];
    $d['approval_mode'] = $d['approval_mode'] === 'auto' ? 'auto' : 'manual';
    $d['status'] = $d['status'] === 'active' ? 'active' : 'paused';
    $d['linkedin_enabled'] = isset($_POST['linkedin_enabled']) ? 1 : 0;
    $d['li_connect_after_days'] = $d['li_connect_after_days'] === '' ? 1 : max(0, min(30, (int) $d['li_connect_after_days']));
    $d['li_followup_days'] = $d['li_followup_days'] === '' ? 4 : max(1, min(30, (int) $d['li_followup_days']));
    if ($d['name'] === '' || $d['keywords'] === '' || $d['locations'] === '') { flash('Name, keywords and locations are required.', 'err'); redirect('campaigns.php?' . ($id ? "edit=$id" : 'new=1')); }
    if ($id) update('campaigns', $d, 'id = ? AND tenant_id = ?', [$id, tid()]); else $id = insert('campaigns', $d);
    sync_campaign_queries($id);
    flash('Campaign saved.');
    redirect('campaigns.php?edit=' . $id);
}

if ((isset($_GET['edit']) || isset($_GET['new'])) && !can('admin')) deny('Only workspace admins can create or edit campaigns.');
$edit = isset($_GET['edit']) ? owned_row('campaigns', (int) $_GET['edit']) : null;
if (isset($_GET['edit']) && !$edit) deny('That campaign isn’t in this workspace.');
$isNew = isset($_GET['new']);
$presets = campaign_presets();

if ($edit || $isNew) {
    $c = $edit ?: ['id' => 0, 'name' => '', 'vertical' => 'custom', 'keywords' => '', 'locations' => '', 'icp' => '', 'offer' => '', 'proof' => '',
        'min_reviews' => 5, 'max_reviews' => 400, 'min_rating' => 0, 'daily_new_leads' => 40, 'fit_threshold' => 60, 'approval_mode' => 'manual',
        'auto_min_score' => 80, 'followups' => 3, 'step_delays' => '3,4,7', 'status' => 'paused',
        'linkedin_enabled' => 0, 'li_connect_after_days' => 1, 'li_followup_days' => 4];
    $pk = (string) ($_GET['preset'] ?? '');
    if ($isNew && ($p = preset_by_key($pk))) {
        [$label, $kws, $icp, $pain, $proof] = $p;
        $c['name'] = "$label — US"; $c['vertical'] = $pk; $c['keywords'] = implode("\n", $kws);
        $c['icp'] = $icp . "\nTypical pain: " . $pain;
        $c['offer'] = preset_offer();
        $c['proof'] = $proof;
        $c['locations'] = "Dallas, TX\nHouston, TX\nPhoenix, AZ\nAtlanta, GA\nCharlotte, NC";
    }
    $queries = $edit ? all('SELECT * FROM search_queries WHERE campaign_id = ? AND tenant_id = ? ORDER BY status, id', [$edit['id'], tid()]) : [];
    page_header($edit ? 'Edit campaign' : 'New campaign', 'campaigns');
    if ($isNew && !$pk): ?>
      <section class="card"><h2>Start from a preset</h2><p class="muted">Pick a niche to pre-fill search keywords, ICP and offer — you can edit everything.</p>
      <?php foreach ($presets as $group => $items): ?><h3><?= e($group) ?></h3><div class="chips">
        <?php foreach ($items as $k => $p): ?><a href="?new=1&preset=<?= e($k) ?>"><?= e($p[0]) ?></a><?php endforeach; ?></div><?php endforeach; ?>
        <p><a href="?new=1&preset=none">or start blank →</a></p></section>
    <?php page_footer(); exit; endif; ?>
    <form method="post" class="card form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
      <input type="hidden" name="vertical" value="<?= e($c['vertical']) ?>">
      <div class="cols2">
        <label>Campaign name<input name="name" value="<?= e($c['name']) ?>" required></label>
        <label>Status<select name="status"><option value="paused" <?= $c['status'] === 'paused' ? 'selected' : '' ?>>Paused</option><option value="active" <?= $c['status'] === 'active' ? 'selected' : '' ?>>Active</option></select></label>
      </div>
      <div class="cols2">
        <label>Search keywords <small>one per line — searched on Google Maps</small><textarea name="keywords" rows="4" required><?= e($c['keywords']) ?></textarea></label>
        <label>Locations <small>one per line, “City, ST” (US) or “City, ON” (Canada)</small><textarea name="locations" rows="4" required><?= e($c['locations']) ?></textarea></label>
      </div>
      <label>Ideal customer profile <small>the AI scores every lead against this</small><textarea name="icp" rows="4"><?= e($c['icp']) ?></textarea></label>
      <label>Offer <small>what you sell to this niche</small><textarea name="offer" rows="6"><?= e($c['offer']) ?></textarea></label>
      <label>Proof the AI may mention <small>demos, real results only — leave empty if none; the AI never invents results</small><textarea name="proof" rows="2"><?= e($c['proof']) ?></textarea></label>
      <h3>Filters &amp; volume</h3>
      <div class="cols4">
        <label>Min Google reviews<input type="number" name="min_reviews" value="<?= (int) $c['min_reviews'] ?>"></label>
        <label>Max Google reviews<input type="number" name="max_reviews" value="<?= (int) $c['max_reviews'] ?>"></label>
        <label>Min rating<input type="number" step="0.1" name="min_rating" value="<?= e($c['min_rating']) ?>"></label>
        <label>New leads / day<input type="number" name="daily_new_leads" value="<?= (int) $c['daily_new_leads'] ?>"></label>
      </div>
      <h3>AI qualification &amp; sequence</h3>
      <div class="cols4">
        <label>Min fit score to draft<input type="number" name="fit_threshold" value="<?= (int) $c['fit_threshold'] ?>"></label>
        <label>Approval<select name="approval_mode"><option value="manual" <?= $c['approval_mode'] === 'manual' ? 'selected' : '' ?>>I review every lead</option><option value="auto" <?= $c['approval_mode'] === 'auto' ? 'selected' : '' ?>>Auto-approve high scores</option></select></label>
        <label>Auto-approve at score ≥<input type="number" name="auto_min_score" value="<?= (int) $c['auto_min_score'] ?>"></label>
        <label>Follow-ups (0–5)<input type="number" min="0" max="5" name="followups" value="<?= (int) $c['followups'] ?>"></label>
      </div>
      <label>Days between emails <small>comma separated, one per follow-up</small><input name="step_delays" value="<?= e($c['step_delays']) ?>"></label>
      <h3>LinkedIn (assisted)</h3>
      <label class="switch"><input type="checkbox" name="linkedin_enabled" value="1" <?= !empty($c['linkedin_enabled']) ? 'checked' : '' ?>><span></span>Add LinkedIn steps for leads whose website links to LinkedIn</label>
      <p class="small muted">The AI writes a connection note and two messages with each sequence. Your team sends them by hand from the <b>LinkedIn tasks</b> page — LinkedIn doesn’t allow software to send for you. A reply on either channel stops both.</p>
      <div class="cols4">
        <label>Connect after first email (days)<input type="number" min="0" max="30" name="li_connect_after_days" value="<?= (int) ($c['li_connect_after_days'] ?? 1) ?>"></label>
        <label>LinkedIn follow-up after (days)<input type="number" min="1" max="30" name="li_followup_days" value="<?= (int) ($c['li_followup_days'] ?? 4) ?>"></label>
      </div>
      <div class="actions"><button class="btn primary">Save campaign</button><a class="btn ghost" href="campaigns.php">Cancel</a></div>
    </form>
    <?php if ($queries): $done = count(array_filter($queries, fn($q) => $q['status'] === 'done')); ?>
      <section class="card"><h2>Google Maps searches <span class="muted small"><?= $done ?>/<?= count($queries) ?> done</span></h2>
        <table class="t"><tr><th>Keyword</th><th>Location</th><th>Pages</th><th>Results</th><th>Status</th></tr>
        <?php foreach ($queries as $q): ?><tr><td><?= e($q['keyword']) ?></td><td><?= e($q['location']) ?></td><td><?= (int) $q['pages_done'] ?></td><td><?= (int) $q['results'] ?></td>
          <td><?= e($q['status']) ?><?= $q['error'] ? ' <span class="err small">' . e($q['error']) . '</span>' : '' ?></td></tr><?php endforeach; ?></table>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button name="action" value="reset_queries" class="btn ghost">Re-run all searches</button></form>
      </section>
    <?php endif;
    page_footer(); exit;
}

$cstats = campaign_stats();
$rows = all("SELECT c.* FROM campaigns c WHERE c.tenant_id = ? ORDER BY c.id DESC", [tid()]);
foreach ($rows as &$r) $r += ($cstats[(int) $r['id']] ?? []) + ['leads' => 0, 'review' => 0, 'contacted' => 0, 'booked' => 0];
unset($r);
page_header('Campaigns', 'campaigns', 'Each campaign is a niche, a set of cities and an offer.');
?>
<?php if (can('admin')): ?><p><a class="btn primary" href="?new=1">+ New campaign</a></p><?php endif; ?>
<table class="t">
  <tr><th>Campaign</th><th>Status</th><th>Leads</th><th>To review</th><th>Contacted</th><th>Booked</th><th>Approval</th><th></th></tr>
  <?php foreach ($rows as $r): ?>
  <tr><td><?= can('admin') ? '<a href="?edit=' . $r['id'] . '"><strong>' . e($r['name']) . '</strong></a>' : '<strong>' . e($r['name']) . '</strong>' ?></td>
    <td><?= $r['status'] === 'active' ? '<span class="badge good">active</span>' : '<span class="badge muted">paused</span>' ?></td>
    <td><?= $r['leads'] ?></td><td><?= $r['review'] ? '<a href="leads.php?status=drafted&campaign=' . $r['id'] . '">' . $r['review'] . '</a>' : 0 ?></td>
    <td><?= $r['contacted'] ?></td><td><?= $r['booked'] ?></td><td class="small"><?= $r['approval_mode'] === 'auto' ? 'auto ≥ ' . (int) $r['auto_min_score'] : 'manual' ?></td>
    <td><?php if (can('admin')): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><button name="action" value="toggle" class="btn small"><?= $r['status'] === 'active' ? 'Pause' : 'Activate' ?></button></form><?php endif; ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="muted">No campaigns yet.</td></tr><?php endif; ?>
</table>
<?php page_footer();
