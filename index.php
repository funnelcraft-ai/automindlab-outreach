<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_role('admin');
    $job = (string) ($_POST['run'] ?? '');
    $map = ['find' => ['find'], 'enrich' => ['enrich'], 'draft' => ['draft'], 'send' => ['send'], 'inbox' => ['inbox_now'], 'linkedin' => ['linkedin'], 'all' => ['send', 'inbox_now', 'find', 'enrich', 'draft', 'linkedin']];
    if (isset($map[$job])) {
        session_write_close();                 // don't lock the user's other tabs while the jobs run
        $r = run_jobs($map[$job], 45, tid());
        session_start();
        foreach (['dash_kpis', 'campaign_stats', 'dash_chart'] as $ck) cache_forget($ck);   // show the new numbers straight away
        $r = $r[tid()] ?? $r;
        flash('Ran ' . $job . ': ' . json_encode($r));
    }
    redirect('index.php');
}

$since = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
// Headline numbers are cached for 60 seconds per workspace: they scan a month of leads and emails.
[$k, $sentToday, $sent30, $queued] = cache_remember('dash_kpis', 60, function () use ($since) {
    $k = row("SELECT
  COUNT(*) AS found,
  SUM(email IS NOT NULL AND status NOT IN ('no_email','skipped')) AS with_email,
  SUM(fit_score IS NOT NULL AND status <> 'disqualified') AS qualified,
  SUM(last_sent_at IS NOT NULL) AS contacted,
  SUM(replied_at IS NOT NULL) AS replied,
  SUM(status IN ('interested','booked') OR reply_category IN ('interested','question','referral')) AS interested,
  SUM(booked_at IS NOT NULL) AS booked,
  SUM(status = 'bounced') AS bounced,
  SUM(last_sent_at IS NOT NULL OR status = 'bounced') AS attempted,
  SUM(status = 'unsubscribed') AS unsubscribed
  FROM leads WHERE tenant_id = ? AND created_at >= ? AND status <> 'no_website'", [tid(), $since]);
    $k = array_map('intval', $k);
    $sentToday = (int) val("SELECT COUNT(*) FROM emails WHERE tenant_id = ? AND status = 'sent' AND sent_at >= ?", [tid(), gmdate('Y-m-d 00:00:00')]);
    $sent30 = (int) val("SELECT COUNT(*) FROM emails WHERE tenant_id = ? AND status = 'sent' AND sent_at >= ?", [tid(), $since]);
    $queued = (int) val("SELECT COUNT(*) FROM emails WHERE tenant_id = ? AND status = 'scheduled'", [tid()]);
    return [$k, $sentToday, $sent30, $queued];
});
$pct = fn($a, $b) => $b > 0 ? round(100 * $a / $b, 1) : 0;

// 14-day chart
$days = [];
for ($i = 13; $i >= 0; $i--) $days[gmdate('Y-m-d', time() - $i * 86400)] = ['sent' => 0, 'replies' => 0];
$chart = cache_remember('dash_chart', 300, fn() => [
    all("SELECT DATE(sent_at) d, COUNT(*) n FROM emails WHERE tenant_id = ? AND status='sent' AND sent_at >= ? GROUP BY DATE(sent_at)", [tid(), gmdate('Y-m-d', time() - 13 * 86400)]),
    all("SELECT DATE(received_at) d, COUNT(*) n FROM replies WHERE tenant_id = ? AND kind='reply' AND received_at >= ? GROUP BY DATE(received_at)", [tid(), gmdate('Y-m-d', time() - 13 * 86400)]),
]);
foreach ($chart[0] as $r) if (isset($days[$r['d']])) $days[$r['d']]['sent'] = (int) $r['n'];
foreach ($chart[1] as $r) if (isset($days[$r['d']])) $days[$r['d']]['replies'] = (int) $r['n'];
$maxDay = max(1, max(array_column($days, 'sent')));

$cstats = cache_remember('campaign_stats', 60, 'campaign_stats');
$campaigns = all("SELECT c.id, c.name, c.status FROM campaigns c WHERE c.tenant_id = ? ORDER BY c.status='active' DESC, c.id DESC", [tid()]);
foreach ($campaigns as &$c) $c += ($cstats[(int) $c['id']] ?? []) + ['leads' => 0, 'contacted' => 0, 'replied' => 0, 'booked' => 0, 'pending_q' => 0];
unset($c);
$mailboxes = all('SELECT * FROM mailboxes WHERE tenant_id = ? ORDER BY id', [tid()]);
$upcoming = all("SELECT b.*, l.business_name FROM bookings b LEFT JOIN leads l ON l.id=b.lead_id WHERE b.tenant_id = ? AND b.start_time >= ? AND b.trigger_event IN ('BOOKING_CREATED','BOOKING_RESCHEDULED') ORDER BY b.start_time LIMIT 6", [tid(), now()]);
$activity = all('SELECT e.*, l.business_name FROM events e LEFT JOIN leads l ON l.id=e.lead_id WHERE e.tenant_id = ? ORDER BY e.id DESC LIMIT 12', [tid()]);
$quota = quota_status();

$hour = (int) (new DateTime('now', new DateTimeZone((string) cfg('app_timezone', 'UTC'))))->format('G');
$cu = current_user() ?? [];
$name = trim((string) strtok((string) ($cu['name'] ?? ''), ' ')) ?: (ucfirst(strtok((string) ($cu['email'] ?? ''), '@.') ?: 'there'));
$toReview = (int) val("SELECT COUNT(*) FROM leads WHERE tenant_id = ? AND status = 'drafted'", [tid()]);
$toAnswer = (int) val("SELECT COUNT(*) FROM replies WHERE tenant_id = ? AND kind = 'reply' AND handled = 0", [tid()]);
$todo = array_filter([$toReview ? "$toReview " . ($toReview === 1 ? 'lead needs' : 'leads need') . ' review' : '', $toAnswer ? "$toAnswer " . ($toAnswer === 1 ? 'reply is' : 'replies are') . ' waiting' : '']);
page_header(($hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening')) . ", $name", 'index',
    $todo ? ucfirst(implode(' and ', $todo)) . '.' : 'Nothing waiting on you right now.');
?>
<div class="kpis">
  <div class="kpi"><span>Leads found · 30d</span><strong><?= $k['found'] ?></strong><em><?= $k['with_email'] ?> with email</em></div>
  <div class="kpi"><span>Emails sent</span><strong><?= $sentToday ?></strong><em>today · <?= $sent30 ?> in 30d · <?= $queued ?> queued</em></div>
  <div class="kpi"><span>Reply rate</span><strong><?= $pct($k['replied'], $k['contacted']) ?>%</strong><em><?= $k['replied'] ?> of <?= $k['contacted'] ?> contacted</em></div>
  <div class="kpi"><span>Interested</span><strong><?= $k['interested'] ?></strong><em>positive replies</em></div>
  <div class="kpi accent"><span>Meetings booked</span><strong><?= $k['booked'] ?></strong><em><?= $pct($k['booked'], $k['contacted']) ?>% of contacted</em></div>
  <div class="kpi <?= $pct($k['bounced'], $k['attempted']) > 3 ? 'alarm' : '' ?>"><span>Bounce rate</span><strong><?= $pct($k['bounced'], $k['attempted']) ?>%</strong><em>keep under 3% · <?= $k['unsubscribed'] ?> unsubscribed</em></div>
</div>

<div class="grid2">
  <section class="card">
    <h2>Funnel · last 30 days</h2>
    <?php $stages = ['Found' => $k['found'], 'Email found' => $k['with_email'], 'Qualified by AI' => $k['qualified'], 'Contacted' => $k['contacted'], 'Replied' => $k['replied'], 'Interested' => $k['interested'], 'Booked' => $k['booked']];
    $top = max(1, $k['found']);
    foreach ($stages as $label => $n): ?>
      <div class="fun"><span><?= e($label) ?></span><div class="bar"><i style="width:<?= max(1, round(100 * $n / $top)) ?>%"></i></div><b><?= $n ?></b></div>
    <?php endforeach; ?>
  </section>
  <section class="card">
    <h2>Sends &amp; replies · 14 days</h2>
    <svg class="chart" viewBox="0 0 560 170" role="img" aria-label="Emails sent per day">
      <?php $i = 0; foreach ($days as $d => $v): $x = 10 + $i * 39; $h = round(130 * $v['sent'] / $maxDay); $rh = round(130 * $v['replies'] / $maxDay); ?>
        <rect x="<?= $x ?>" y="<?= 140 - $h ?>" width="22" height="<?= $h ?>" rx="3" class="s"><title><?= $d ?>: <?= $v['sent'] ?> sent, <?= $v['replies'] ?> replies</title></rect>
        <?php if ($rh): ?><rect x="<?= $x + 24 ?>" y="<?= 140 - $rh ?>" width="8" height="<?= $rh ?>" rx="2" class="r"/><?php endif; ?>
        <text x="<?= $x + 11 ?>" y="160" text-anchor="middle"><?= date('j', strtotime($d)) ?></text>
      <?php $i++; endforeach; ?>
    </svg>
    <div class="legend"><i class="s"></i>Sent <i class="r"></i>Replies</div>
  </section>
</div>

<div class="grid2">
  <section class="card">
    <h2>Campaigns</h2>
    <table class="t"><tr><th>Campaign</th><th>Leads</th><th>Contacted</th><th>Replied</th><th>Booked</th><th></th></tr>
    <?php foreach ($campaigns as $c): ?>
      <tr><td><a href="campaigns.php?edit=<?= $c['id'] ?>"><?= e($c['name']) ?></a> <?= $c['status'] === 'active' ? '<span class="badge good">active</span>' : '<span class="badge muted">paused</span>' ?></td>
      <td><?= $c['leads'] ?></td><td><?= $c['contacted'] ?></td><td><?= $c['replied'] ?></td><td><?= $c['booked'] ?></td>
      <td class="muted small"><?= $c['pending_q'] ? $c['pending_q'] . ' searches left' : 'searches done' ?></td></tr>
    <?php endforeach; if (!$campaigns): ?><tr><td colspan="6" class="muted">No campaigns yet — <a href="campaigns.php?new=1">create one</a>.</td></tr><?php endif; ?>
    </table>
  </section>
  <section class="card">
    <h2>Mailboxes</h2>
    <table class="t"><tr><th>Inbox</th><th>Today</th><th>Bounces 30d</th><th>Status</th></tr>
    <?php foreach ($mailboxes as $m):
      $b = (int) val("SELECT COUNT(*) FROM replies WHERE mailbox_id=? AND kind='bounce' AND received_at >= ?", [$m['id'], $since]);
      $s = (int) val("SELECT COUNT(*) FROM emails WHERE mailbox_id=? AND status='sent' AND sent_at >= ?", [$m['id'], $since]); ?>
      <tr><td><?= e($m['email']) ?></td><td><?= mailbox_sent_today($m) ?> / <?= mailbox_limit_today($m) ?></td>
      <td><?= $b ?> <span class="muted small">(<?= $pct($b, $s) ?>%)</span></td>
      <td><?= !$m['active'] ? '<span class="badge muted">off</span>' : ($m['last_error'] ? '<span class="badge bad" title="' . e($m['last_error']) . '">error</span>' : '<span class="badge good">ok</span>') ?></td></tr>
    <?php endforeach; if (!$mailboxes): ?><tr><td colspan="4" class="muted">No mailboxes — <a href="mailboxes.php">add one</a>.</td></tr><?php endif; ?>
    </table>
    <h3>This month's allowance</h3>
    <div class="fun"><span>New leads</span><div class="bar"><i style="width:<?= min(100, round(100 * $quota['leads_used'] / max(1, $quota['leads_limit']))) ?>%"></i></div><b><?= $quota['leads_used'] ?>/<?= $quota['leads_limit'] ?></b></div>
    <div class="fun"><span>AI research</span><div class="bar"><i style="width:<?= min(100, round(100 * $quota['ai_used'] / max(0.01, $quota['ai_limit']))) ?>%"></i></div><b>$<?= number_format($quota['ai_used'], 2) ?>/$<?= number_format($quota['ai_limit'], 0) ?></b></div>
  </section>
</div>

<?php if (li_enabled_anywhere()): $li = li_stats(); ?>
<section class="card li-summary">
  <div class="card-head"><h2>LinkedIn · last 30 days</h2><a class="btn small <?= $li['due'] ? 'filled' : '' ?>" href="linkedin.php"><?= $li['due'] ? $li['due'] . ' task' . ($li['due'] > 1 ? 's' : '') . ' due' : 'Open tasks' ?></a></div>
  <div class="li-stats">
    <div><strong><?= $li['requests'] ?></strong><span>requests sent</span></div>
    <div><strong><?= $li['accepted'] ?></strong><span>accepted<?= $li['requests'] ? ' (' . round(100 * $li['accepted'] / $li['requests']) . '%)' : '' ?></span></div>
    <div><strong><?= $li['messages'] ?></strong><span>messages sent</span></div>
    <div><strong><?= $li['replies'] ?></strong><span>replies</span></div>
    <div><strong><?= $li['waiting'] ?></strong><span>waiting for acceptance</span></div>
  </div>
</section>
<?php endif; ?>

<div class="grid2">
  <section class="card">
    <h2>Upcoming meetings</h2>
    <?php foreach ($upcoming as $b): ?>
      <div class="row-item"><strong><?= e(local_time($b['start_time'], 'D M j, g:i a')) ?></strong> — <?= $b['lead_id'] ? '<a href="lead.php?id=' . $b['lead_id'] . '">' . e($b['business_name']) . '</a>' : e($b['attendee_name'] . ' ' . $b['attendee_email']) ?></div>
    <?php endforeach; if (!$upcoming): ?><p class="muted">None yet. Bookings appear here via the cal.id webhook.</p><?php endif; ?>
    <?php if (can('admin')): ?><h3>Run a step now</h3><p class="muted small">The cron job runs these every 5 minutes. Use them to test.</p>
    <form method="post" class="runrow"><?= csrf_field() ?>
      <?php foreach (['find' => 'Find leads', 'enrich' => 'Research sites', 'draft' => 'AI draft', 'send' => 'Send due', 'inbox' => 'Check inboxes'] + (li_enabled_anywhere() ? ['linkedin' => 'LinkedIn tasks'] : []) + ['all' => 'Run all'] as $j => $l): ?>
        <button name="run" value="<?= $j ?>" class="btn <?= $j === 'all' ? 'filled' : 'small' ?>"><?= $l ?></button>
      <?php endforeach; ?>
    </form><?php endif; ?>
  </section>
  <section class="card">
    <h2>Recent activity <a class="small" href="activity.php">See all</a></h2>
    <?php foreach ($activity as $a): ?>
      <div class="act <?= e($a['level']) ?>"><time><?= e(local_time($a['created_at'])) ?></time>
        <span><?= $a['lead_id'] ? '<a href="lead.php?id=' . $a['lead_id'] . '">' . e($a['business_name']) . '</a> · ' : '' ?><?= e($a['detail']) ?></span></div>
    <?php endforeach; ?>
  </section>
</div>
<?php page_footer();
