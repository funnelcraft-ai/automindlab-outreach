<?php
declare(strict_types=1);
/**
 * Admin console — master dashboard for platform admins.
 * Everything here is across ALL workspaces; per-workspace work happens on the workspace dashboard (index.php).
 */
define('APP_AREA', 'admin');
require __DIR__ . '/../lib/bootstrap.php';
require_login();
require_platform_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'refresh') { @unlink(APP_ROOT . '/storage/cache/g-admin_overview.cache'); @unlink(APP_ROOT . '/storage/cache/g-admin_chart.cache'); }
    redirect('index.php' . (isset($_GET['sort']) ? '?sort=' . urlencode((string) $_GET['sort']) : ''));
}

/** One grouped query per figure across every workspace (no per-workspace loops), cached for 60 seconds. */
function admin_overview(): array {
    $since30 = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
    $month = gmdate('Y-m-01 00:00:00');
    $by = fn(string $sql, array $p = []) => array_column(all($sql, $p), null, 'tenant_id');
    $d = [
        'tenants'   => all('SELECT t.*, (SELECT COUNT(*) FROM memberships m WHERE m.tenant_id = t.id) members FROM tenants t ORDER BY t.id'),
        'leadsMon'  => $by('SELECT tenant_id, COUNT(*) n FROM leads WHERE created_at >= ? GROUP BY tenant_id', [$month]),
        'leadStats' => $by("SELECT tenant_id, SUM(status = 'drafted') review, SUM(booked_at >= ?) booked30, SUM(last_sent_at >= ?) contacted30 FROM leads GROUP BY tenant_id", [$since30, $since30]),
        'sent30'    => $by("SELECT tenant_id, COUNT(*) n FROM emails WHERE status = 'sent' AND sent_at >= ? GROUP BY tenant_id", [$since30]),
        'replies30' => $by("SELECT tenant_id, SUM(kind = 'reply') replies, SUM(kind = 'bounce') bounces FROM replies WHERE received_at >= ? GROUP BY tenant_id", [$since30]),
        'waiting'   => $by("SELECT tenant_id, COUNT(*) n FROM replies WHERE kind = 'reply' AND handled = 0 GROUP BY tenant_id"),
        'aiMon'     => $by("SELECT tenant_id, SUM(cost_usd) usd FROM api_usage WHERE day >= ? AND service LIKE 'ai:%' GROUP BY tenant_id", [gmdate('Y-m-01')]),
        'inboxes'   => $by("SELECT tenant_id, COUNT(*) n, SUM(active) active, SUM(last_error IS NOT NULL AND last_error <> '') failing FROM mailboxes GROUP BY tenant_id"),
        'campaigns' => $by("SELECT tenant_id, COUNT(*) n FROM campaigns WHERE status = 'active' GROUP BY tenant_id"),
        'liOverdue' => $by("SELECT tenant_id, COUNT(*) n FROM li_tasks WHERE status = 'pending' AND due_at < ? GROUP BY tenant_id", [gmdate('Y-m-d H:i:s', time() - 3 * 86400)]),
        'liReplies' => $by("SELECT tenant_id, COUNT(*) n FROM replies WHERE channel = 'linkedin' AND received_at >= ? GROUP BY tenant_id", [$since30]),
        'lastEvent' => $by('SELECT e.tenant_id, e.created_at FROM events e JOIN (SELECT tenant_id, MAX(id) m FROM events GROUP BY tenant_id) x ON x.m = e.id'),
        'settings'  => [],
        'problems'  => [],
    ];
    foreach (all("SELECT tenant_id, k, v FROM tenant_settings WHERE k IN ('sending_paused','dry_run','test_redirect','postal_address','last_run_at')") as $r) $d['settings'][(int) $r['tenant_id']][$r['k']] = $r['v'];
    try {
        foreach (all("SELECT tenants, level FROM error_groups WHERE status = 'open' AND level IN ('error','critical')") as $g) {
            foreach (json_decode((string) $g['tenants'], true) ?: [] as $t) $d['problems'][(int) $t] = ($d['problems'][(int) $t] ?? 0) + 1;
        }
    } catch (Throwable $e) {}
    $d['at'] = now();
    return $d;
}

$D = cache_remember('admin_overview', 60, 'admin_overview', true);
$num = fn(array $map, int $t, string $col = 'n') => (float) ($map[$t][$col] ?? 0);

/* ---------- one row per workspace ---------- */
$rows = [];
$tot = ['leads' => 0, 'sent' => 0, 'replies' => 0, 'booked' => 0, 'ai' => 0.0, 'review' => 0, 'waiting' => 0, 'bounces' => 0];
$attention = [];
$weekAgo = time() - 7 * 86400;
foreach ($D['tenants'] as $t) {
    $id = (int) $t['id'];
    $s = $D['settings'][$id] ?? [];
    $mode = ($s['sending_paused'] ?? '1') === '1' ? ['paused', 'Paused'] : (($s['dry_run'] ?? '0') === '1' ? ['dry', 'Dry run'] : (trim((string) ($s['test_redirect'] ?? '')) !== '' ? ['test', 'Test'] : ['live', 'Live']));
    $r = [
        'id' => $id, 'name' => $t['name'], 'plan' => $t['plan'], 'status' => $t['status'], 'mode' => $mode, 'members' => (int) $t['members'],
        'leads' => (int) $num($D['leadsMon'], $id), 'leadsQ' => (int) $t['quota_leads'],
        'ai' => $num($D['aiMon'], $id, 'usd'), 'aiQ' => (float) $t['quota_ai_usd'],
        'sent' => (int) $num($D['sent30'], $id), 'replies' => (int) $num($D['replies30'], $id, 'replies'), 'bounces' => (int) $num($D['replies30'], $id, 'bounces'),
        'contacted' => (int) $num($D['leadStats'], $id, 'contacted30'), 'booked' => (int) $num($D['leadStats'], $id, 'booked30'),
        'review' => (int) $num($D['leadStats'], $id, 'review'), 'waiting' => (int) $num($D['waiting'], $id),
        'inboxes' => (int) $num($D['inboxes'], $id), 'failing' => (int) $num($D['inboxes'], $id, 'failing'),
        'campaigns' => (int) $num($D['campaigns'], $id), 'problems' => (int) ($D['problems'][$id] ?? 0),
        'liOverdue' => (int) $num($D['liOverdue'] ?? [], $id), 'liReplies' => (int) $num($D['liReplies'] ?? [], $id),
        'last' => $D['lastEvent'][$id]['created_at'] ?? null, 'address' => trim((string) ($s['postal_address'] ?? '')) !== '',
    ];
    $r['replyRate'] = $r['contacted'] ? round(100 * $r['replies'] / $r['contacted'], 1) : null;
    $r['bounceRate'] = $r['sent'] ? round(100 * $r['bounces'] / $r['sent'], 1) : null;

    // What needs someone's attention, worst first
    $flags = [];
    if ($t['status'] === 'active') {
        if ($r['problems']) $flags[] = ['bad', 'bug_report', $r['problems'] . ' open error' . ($r['problems'] > 1 ? 's' : ''), 'health.php?ws=' . $id . '#problems', null];
        if ($r['failing']) $flags[] = ['bad', 'mail_lock', $r['failing'] . ' inbox' . ($r['failing'] > 1 ? 'es' : '') . ' failing', null, 'mailboxes.php'];
        if ($r['bounceRate'] !== null && $r['sent'] >= 30 && $r['bounceRate'] >= 3) $flags[] = ['bad', 'trending_up', "Bounce rate {$r['bounceRate']}% (keep under 3%)", null, 'mailboxes.php'];
        if (!$r['address']) $flags[] = ['warn', 'home_pin', 'No postal address — can’t send legally', null, 'settings.php'];
        if (!$r['inboxes']) $flags[] = ['warn', 'mail', 'No sending inbox connected', null, 'mailboxes.php'];
        if ($r['leadsQ'] && $r['leads'] >= 0.9 * $r['leadsQ']) $flags[] = ['warn', 'speed', "Lead allowance {$r['leads']}/{$r['leadsQ']}", 'workspaces.php?edit=' . $id . '#edit', null];
        if ($r['aiQ'] > 0 && $r['ai'] >= 0.9 * $r['aiQ']) $flags[] = ['warn', 'psychology', 'AI allowance $' . number_format($r['ai'], 2) . '/$' . number_format($r['aiQ'], 0), 'workspaces.php?edit=' . $id . '#edit', null];
        if ($r['last'] && strtotime($r['last'] . ' UTC') < $weekAgo) $flags[] = ['warn', 'bedtime', 'No activity for ' . floor((time() - strtotime($r['last'] . ' UTC')) / 86400) . ' days', null, 'index.php'];
        if (!$r['campaigns'] && $r['inboxes']) $flags[] = ['info', 'campaign', 'No active campaign', null, 'campaigns.php'];
        if ($r['review'] >= 25) $flags[] = ['info', 'rate_review', $r['review'] . ' drafts waiting for review', null, 'leads.php?status=drafted'];
        if ($r['liOverdue']) $flags[] = ['info', 'handshake', $r['liOverdue'] . ' LinkedIn task' . ($r['liOverdue'] > 1 ? 's' : '') . ' overdue 3+ days', null, 'linkedin.php'];
        if ($r['waiting']) $flags[] = ['info', 'forum', $r['waiting'] . ' repl' . ($r['waiting'] > 1 ? 'ies' : 'y') . ' not answered', null, 'replies.php'];
    }
    foreach ($flags as $f) $attention[] = ['tone' => $f[0], 'icon' => $f[1], 'text' => $f[2], 'href' => $f[3], 'open' => $f[4], 'ws' => $id, 'name' => $t['name']];
    $r['flags'] = $flags;
    $rows[] = $r;
    if ($t['status'] === 'active') foreach (['leads', 'sent', 'replies', 'booked', 'ai', 'review', 'waiting', 'bounces'] as $k) $tot[$k] += $r[$k];
}
$toneRank = ['bad' => 0, 'warn' => 1, 'info' => 2];
// The same issue in 3+ workspaces becomes one line, so a platform-wide problem doesn't bury everything else.
$generic = ['bug_report' => 'have open errors', 'mail_lock' => 'have a failing inbox', 'trending_up' => 'have a bounce rate over 3%', 'home_pin' => 'have no postal address',
    'mail' => 'have no sending inbox', 'speed' => 'are near their lead allowance', 'psychology' => 'are near their AI allowance', 'bedtime' => 'have had no activity for a week',
    'campaign' => 'have no active campaign', 'handshake' => 'have LinkedIn tasks overdue 3+ days', 'rate_review' => 'have 25+ drafts waiting for review', 'forum' => 'have unanswered replies'];
$byKind = [];
foreach ($attention as $a) $byKind[$a['icon']][] = $a;
$attention = [];
foreach ($byKind as $icon => $items) {
    if (count($items) >= 3) {
        $names = array_column($items, 'name');
        $attention[] = ['tone' => $items[0]['tone'], 'icon' => $icon, 'text' => $generic[$icon] ?? 'need attention', 'href' => '?sort=attention#workspaces', 'open' => null,
            'ws' => 0, 'name' => count($items) . ' workspaces', 'more' => implode(', ', array_slice($names, 0, 4)) . (count($names) > 4 ? ' +' . (count($names) - 4) : '')];
    } else foreach ($items as $a) $attention[] = $a;
}
usort($attention, fn($a, $b) => $toneRank[$a['tone']] <=> $toneRank[$b['tone']]);

/* ---------- platform-wide checks ---------- */
$platformFlags = [];
$aiSpend = month_usage('ai:', 'cost_usd'); $aiBudget = (float) setting('ai_monthly_budget');
$placesUsed = (int) month_usage('places'); $placesCap = max(1, (int) setting('places_monthly_cap'));
$detailsUsed = (int) month_usage('places_details'); $detailsCap = max(1, (int) setting('places_details_cap'));
$provider = (string) setting('ai_provider');
if ($provider === 'mock') $platformFlags[] = ['bad', 'smart_toy', 'AI is set to the offline mock — no real research or drafts', 'platform.php#ai'];
elseif (!(api_status($provider)['ok'] ?? false)) $platformFlags[] = ['warn', 'psychology', 'The active AI provider hasn’t passed a test', 'platform.php#ai'];
if (!(api_status('places')['ok'] ?? false)) $platformFlags[] = ['warn', 'location_on', 'Google Places hasn’t passed a test', 'platform.php#places'];
if ($aiBudget > 0 && $aiSpend >= 0.8 * $aiBudget) $platformFlags[] = ['warn', 'payments', 'Platform AI spend at ' . round(100 * $aiSpend / $aiBudget) . '% of the monthly budget', 'platform.php#ai'];
if ($placesUsed >= 0.8 * $placesCap) $platformFlags[] = ['warn', 'travel_explore', "Google Places searches at $placesUsed/$placesCap this month", 'platform.php#places'];
if ($detailsUsed >= 0.8 * $detailsCap) $platformFlags[] = ['warn', 'travel_explore', "Google Places lookups at $detailsUsed/$detailsCap this month", 'platform.php#places'];
$no2fa = (int) val('SELECT COUNT(*) FROM users WHERE is_platform_admin = 1 AND totp_enabled = 0');
if ($no2fa) $platformFlags[] = ['warn', 'shield', "$no2fa platform admin" . ($no2fa > 1 ? 's have' : ' has') . ' no two-step verification', 'admins.php'];
$alertTargets = alert_targets();
if (!$alertTargets['hooks'] && !$alertTargets['emails']) $platformFlags[] = ['warn', 'notifications_off', 'Error alerts don’t go to anyone yet', 'health.php#alerts'];

/* ---------- sorting ---------- */
$sort = in_array($_GET['sort'] ?? '', ['attention', 'booked', 'sent', 'leads', 'name', 'recent'], true) ? $_GET['sort'] : 'attention';
$score = fn($r) => array_sum(array_map(fn($f) => [0 => 100, 1 => 10, 2 => 1][$toneRank[$f[0]]], $r['flags']));
usort($rows, match ($sort) {
    'booked' => fn($a, $b) => [$b['booked'], $b['sent']] <=> [$a['booked'], $a['sent']],
    'sent' => fn($a, $b) => $b['sent'] <=> $a['sent'],
    'leads' => fn($a, $b) => $b['leads'] <=> $a['leads'],
    'name' => fn($a, $b) => strcasecmp($a['name'], $b['name']),
    'recent' => fn($a, $b) => strcmp((string) $b['last'], (string) $a['last']),
    default => fn($a, $b) => [$a['status'] !== 'active', -$score($a), strcasecmp($a['name'], $b['name'])] <=> [$b['status'] !== 'active', -$score($b), strcasecmp($b['name'], $a['name'])],
});

/* ---------- 30-day trend across all workspaces ---------- */
$chart = cache_remember('admin_chart', 300, fn() => [
    all("SELECT DATE(sent_at) d, COUNT(*) n FROM emails WHERE status = 'sent' AND sent_at >= ? GROUP BY DATE(sent_at)", [gmdate('Y-m-d', time() - 29 * 86400)]),
    all("SELECT DATE(received_at) d, COUNT(*) n FROM replies WHERE kind = 'reply' AND received_at >= ? GROUP BY DATE(received_at)", [gmdate('Y-m-d', time() - 29 * 86400)]),
], true);
$days = [];
for ($i = 29; $i >= 0; $i--) $days[gmdate('Y-m-d', time() - $i * 86400)] = ['sent' => 0, 'replies' => 0];
foreach ($chart[0] as $r) if (isset($days[$r['d']])) $days[$r['d']]['sent'] = (int) $r['n'];
foreach ($chart[1] as $r) if (isset($days[$r['d']])) $days[$r['d']]['replies'] = (int) $r['n'];
$maxDay = max(1, max(array_column($days, 'sent')));

$active = count(array_filter($rows, fn($r) => $r['status'] === 'active'));
$suspended = count($rows) - $active;
$live = count(array_filter($rows, fn($r) => $r['status'] === 'active' && $r['mode'][0] === 'live'));
$plans = array_count_values(array_map(fn($r) => $r['plan'] ?: '—', array_filter($rows, fn($r) => $r['status'] === 'active')));
arsort($plans);
$recent = all("SELECT e.*, t.name AS tname FROM events e LEFT JOIN tenants t ON t.id = e.tenant_id WHERE e.type IN ('team','workspace','mode','migrate') ORDER BY e.id DESC LIMIT 8");
$errors24 = 0;
try { $errors24 = (int) val("SELECT COUNT(*) FROM error_samples s JOIN error_groups g ON g.id = s.group_id WHERE s.at >= ? AND g.level IN ('error','critical')", [gmdate('Y-m-d H:i:s', time() - 86400)]); } catch (Throwable $e) {}
$lastCron = setting('last_cron_at');
$pct = fn($a, $b) => $b > 0 ? min(100, round(100 * $a / $b)) : 0;
$openBtn = fn(int $ws, string $next, string $label, string $cls = 'btn small') => '<form method="post" action="open.php" class="inline">' . csrf_field()
    . '<input type="hidden" name="next" value="' . e($next) . '"><button class="' . $cls . '" name="tenant" value="' . $ws . '">' . $label . '</button></form>';

page_header('Overview', 'admin', 'Every client workspace at a glance — updated ' . ago($D['at']) . '.');
?>
<div class="kpis">
  <div class="kpi accent"><span>Meetings booked · 30 days</span><strong><?= number_format($tot['booked']) ?></strong><em>across <?= $active ?> active workspace<?= $active === 1 ? '' : 's' ?></em></div>
  <div class="kpi"><span>Workspaces</span><strong><?= $active ?></strong><em><?= $live ?> live · <?= $active - $live ?> testing or paused<?= $suspended ? ' · ' . $suspended . ' suspended' : '' ?></em></div>
  <div class="kpi"><span>New leads this month</span><strong><?= number_format($tot['leads']) ?></strong><em>found by Google Places search</em></div>
  <div class="kpi"><span>Emails sent · 30 days</span><strong><?= number_format($tot['sent']) ?></strong><em><?= number_format($tot['replies']) ?> replies<?= ($lr = array_sum(array_column($rows, 'liReplies'))) ? ' · ' . $lr . ' on LinkedIn' : '' ?></em></div>
  <div class="kpi <?= $tot['sent'] >= 30 && $tot['bounces'] / max(1, $tot['sent']) >= 0.03 ? 'alarm' : '' ?>"><span>Bounce rate · 30 days</span><strong><?= $tot['sent'] ? round(100 * $tot['bounces'] / $tot['sent'], 1) : 0 ?>%</strong><em>keep under 3% on every domain</em></div>
  <div class="kpi <?= $aiBudget > 0 && $aiSpend >= 0.8 * $aiBudget ? 'alarm' : '' ?>"><span>AI spend this month</span><strong>$<?= number_format($aiSpend, 2) ?></strong><em>of $<?= number_format($aiBudget, 0) ?> platform budget</em></div>
</div>

<div class="grid-lead">
  <section class="card attention">
    <div class="card-head"><h2>Needs attention</h2><span class="muted small"><?= count($attention) + count($platformFlags) ?: 'Nothing' ?> item<?= count($attention) + count($platformFlags) === 1 ? '' : 's' ?></span></div>
    <?php if (!$attention && !$platformFlags): ?><p class="empty-row"><?= icon('verified', 'fill') ?> Every workspace looks healthy.</p><?php endif; ?>
    <ul class="att-list">
      <?php foreach ($platformFlags as [$tone, $ic, $text, $href]): ?>
        <li class="<?= $tone ?>"><?= icon($ic) ?><span><b>Platform</b><?= e($text) ?></span><a class="btn small" href="<?= e($href) ?>">Fix</a></li>
      <?php endforeach; ?>
      <?php foreach (array_slice($attention, 0, 14) as $a): ?>
        <li class="<?= $a['tone'] ?>"><?= icon($a['icon']) ?><span><b><?= e($a['name']) ?></b><?= e($a['text']) ?><?php if (!empty($a['more'])): ?><small class="muted"><?= e($a['more']) ?></small><?php endif; ?></span>
          <?= $a['href'] ? '<a class="btn small" href="' . e($a['href']) . '">View</a>' : $openBtn($a['ws'], $a['open'], 'Open') ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if (count($attention) > 14): ?><p class="small muted"><?= count($attention) - 14 ?> more — sort the table below by “Needs attention”.</p><?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><h2>Platform</h2><a class="small" href="platform.php">Connections</a></div>
    <div class="usage-row"><span>AI (<?= e(['anthropic' => 'Claude', 'openai' => 'OpenAI', 'gemini' => 'Gemini', 'mock' => 'mock'][$provider] ?? $provider) ?>)</span><div class="meter"><i style="--v:<?= $pct($aiSpend, $aiBudget) ?>%"></i></div><b>$<?= number_format($aiSpend, 2) ?> / $<?= number_format($aiBudget, 0) ?></b></div>
    <div class="usage-row"><span>Places searches</span><div class="meter"><i style="--v:<?= $pct($placesUsed, $placesCap) ?>%"></i></div><b><?= $placesUsed ?> / <?= $placesCap ?></b></div>
    <div class="usage-row"><span>Places lookups</span><div class="meter"><i style="--v:<?= $pct($detailsUsed, $detailsCap) ?>%"></i></div><b><?= $detailsUsed ?> / <?= $detailsCap ?></b></div>
    <dl class="facts-list compact">
      <dt>Cron</dt><dd><?= $lastCron && strtotime($lastCron . ' UTC') > time() - 1200 ? '<span class="lvl ok">Running</span>' : '<span class="lvl critical">Stopped</span>' ?> last run <?= e($lastCron ? ago($lastCron) : 'never') ?></dd>
      <dt>Errors · 24 h</dt><dd><a href="health.php"><?= number_format($errors24) ?></a><?= ($p = array_sum($D['problems'])) ? ' · ' . $p . ' open in workspaces' : '' ?></dd>
      <dt>Plans</dt><dd><?= $plans ? e(implode(' · ', array_map(fn($k, $v) => "$v $k", array_keys($plans), $plans))) : '—' ?></dd>
      <dt>People</dt><dd><?= (int) val('SELECT COUNT(*) FROM users') ?> accounts · <?= array_sum(array_column($rows, 'inboxes')) ?> sending inboxes</dd>
    </dl>
  </section>
</div>

<div class="section-head" id="workspaces">
  <h2 class="group-title">Workspaces</h2>
  <div class="chips">
    <?php foreach (['attention' => 'Needs attention', 'booked' => 'Most bookings', 'sent' => 'Most sent', 'leads' => 'Most leads', 'recent' => 'Recently active', 'name' => 'Name'] as $k => $l): ?>
      <a href="?sort=<?= $k ?>#workspaces" class="<?= $sort === $k ? 'on' : '' ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>
</div>
<table class="t wide overview">
  <tr><th>Workspace</th><th>Sending</th><th>Leads this month</th><th>AI this month</th><th>Sent · 30 d</th><th>Reply rate</th><th>Booked · 30 d</th><th>Bounce</th><th>Waiting</th><th>Last activity</th><th></th></tr>
  <?php foreach ($rows as $r): $sus = $r['status'] !== 'active'; ?>
  <tr class="<?= $sus ? 'dim' : '' ?>">
    <td><strong><?= e($r['name']) ?></strong> <?= $r['flags'] ? '<span class="dot-flag ' . e($r['flags'][0][0]) . '" title="' . e($r['flags'][0][2]) . '"></span>' : '' ?><br><span class="muted small"><?= e($r['plan'] ?: '—') ?> · <?= $r['members'] ?> member<?= $r['members'] === 1 ? '' : 's' ?></span></td>
    <td><?= $sus ? '<span class="status-pill suspended">Suspended</span>' : '<span class="mode-pill ' . e($r['mode'][0]) . '">' . e($r['mode'][1]) . '</span>' ?></td>
    <td><?= number_format($r['leads']) ?> <span class="muted small">/ <?= $r['leadsQ'] ?: '∞' ?></span><div class="meter"><i style="--v:<?= $pct($r['leads'], $r['leadsQ']) ?>%"></i></div></td>
    <td>$<?= number_format($r['ai'], 2) ?> <span class="muted small">/ <?= $r['aiQ'] > 0 ? '$' . number_format($r['aiQ'], 0) : '∞' ?></span><div class="meter"><i style="--v:<?= $pct($r['ai'], $r['aiQ']) ?>%"></i></div></td>
    <td><?= number_format($r['sent']) ?></td>
    <td><?= $r['replyRate'] === null ? '<span class="muted">—</span>' : $r['replyRate'] . '%' ?></td>
    <td><strong><?= number_format($r['booked']) ?></strong></td>
    <td class="<?= $r['bounceRate'] !== null && $r['sent'] >= 30 && $r['bounceRate'] >= 3 ? 'bad-text' : '' ?>"><?= $r['bounceRate'] === null ? '<span class="muted">—</span>' : $r['bounceRate'] . '%' ?></td>
    <td class="small"><?= $r['review'] ? $r['review'] . ' drafts' : '' ?><?= $r['review'] && $r['waiting'] ? '<br>' : '' ?><?= $r['waiting'] ? $r['waiting'] . ' replies' : '' ?><?= !$r['review'] && !$r['waiting'] ? '<span class="muted">—</span>' : '' ?></td>
    <td class="small nowrap"><?= e($r['last'] ? ago($r['last']) : 'never') ?></td>
    <td class="nowrap"><?= $openBtn($r['id'], 'index.php', 'Open') ?> <a class="btn small text" href="workspaces.php?edit=<?= $r['id'] ?>#edit">Edit</a></td>
  </tr>
  <?php endforeach; ?>
  <tr class="total"><td><strong>All active</strong></td><td></td><td><strong><?= number_format($tot['leads']) ?></strong></td><td><strong>$<?= number_format($tot['ai'], 2) ?></strong></td><td><strong><?= number_format($tot['sent']) ?></strong></td>
    <td><?= $tot['sent'] ? round(100 * $tot['replies'] / max(1, array_sum(array_column($rows, 'contacted'))), 1) . '%' : '—' ?></td><td><strong><?= number_format($tot['booked']) ?></strong></td>
    <td><?= $tot['sent'] ? round(100 * $tot['bounces'] / $tot['sent'], 1) . '%' : '—' ?></td><td class="small"><?= $tot['review'] ?> drafts · <?= $tot['waiting'] ?> replies</td><td></td><td></td></tr>
</table>

<div class="grid2">
  <section class="card">
    <h2>Sends &amp; replies · all workspaces · 30 days</h2>
    <svg class="chart" viewBox="0 0 600 170" role="img" aria-label="Emails sent and replies per day across all workspaces">
      <?php $i = 0; foreach ($days as $d => $v): $x = 6 + $i * 19.8; $h = round(130 * $v['sent'] / $maxDay); $rh = round(130 * $v['replies'] / $maxDay); ?>
        <rect x="<?= $x ?>" y="<?= 140 - $h ?>" width="12" height="<?= $h ?>" rx="2" class="s"><title><?= $d ?>: <?= $v['sent'] ?> sent, <?= $v['replies'] ?> replies</title></rect>
        <?php if ($rh): ?><rect x="<?= $x + 13 ?>" y="<?= 140 - $rh ?>" width="4" height="<?= $rh ?>" rx="1" class="r"/><?php endif; ?>
        <?php if ($i % 3 === 0): ?><text x="<?= $x + 6 ?>" y="160" text-anchor="middle"><?= date('j', strtotime($d)) ?></text><?php endif; ?>
      <?php $i++; endforeach; ?>
    </svg>
    <div class="legend"><i class="s"></i>Sent <i class="r"></i>Replies</div>
  </section>
  <section class="card">
    <div class="card-head"><h2>Recent changes</h2><a class="small" href="workspaces.php">All workspaces</a></div>
    <div class="act">
      <?php foreach ($recent as $ev): ?><span class="muted small"><?= e(ago($ev['created_at'])) ?></span><span><b><?= e($ev['tname'] ?? 'Platform') ?></b> · <?= e(mb_substr((string) $ev['detail'], 0, 110)) ?></span><?php endforeach; ?>
      <?php if (!$recent): ?><span></span><span class="muted">Workspace, team and sending-mode changes will show here.</span><?php endif; ?>
    </div>
  </section>
</div>
<form method="post" class="actions"><?= csrf_field() ?><button name="action" value="refresh" class="btn text small" data-press><?= icon('refresh') ?>Refresh numbers now</button><span class="small muted">Numbers refresh by themselves every minute.</span></form>
<?php page_footer();
