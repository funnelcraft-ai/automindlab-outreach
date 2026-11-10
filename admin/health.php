<?php
declare(strict_types=1);
define('APP_AREA', 'admin');
require __DIR__ . '/../lib/bootstrap.php';
require_login();
require_platform_admin();

$now = now();
$since24 = gmdate('Y-m-d H:i:s', time() - 86400);

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    try {
        if ($a === 'resolve' || $a === 'reopen') {
            q('UPDATE error_groups SET status = ?, resolved_at = ?, regressed = 0, muted_until = NULL WHERE id = ?', [$a === 'resolve' ? 'resolved' : 'open', $a === 'resolve' ? $now : null, $id]);
            flash($a === 'resolve' ? 'Marked as fixed. If it happens again it reopens and the team is alerted.' : 'Reopened.');
        } elseif ($a === 'mute') {
            $h = (int) ($_POST['hours'] ?? 24);
            q("UPDATE error_groups SET status = 'muted', muted_until = ? WHERE id = ?", [$h > 0 ? gmdate('Y-m-d H:i:s', time() + $h * 3600) : null, $id]);
            flash($h > 0 ? "Muted for " . ($h >= 24 ? ($h / 24) . ' day' . ($h > 24 ? 's' : '') : "$h hours") . '. It is still recorded, just not alerted.' : 'Muted until you unmute it.');
        } elseif ($a === 'note') {
            q('UPDATE error_groups SET note = ? WHERE id = ?', [mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500) ?: null, $id]);
            flash('Note saved.');
        } elseif ($a === 'bulk_resolve') {
            $n = q("UPDATE error_groups SET status = 'resolved', resolved_at = ?, regressed = 0 WHERE status = 'open' AND level IN ('notice','warning') AND last_seen < ?", [$now, $since24])->rowCount();
            flash("Closed $n warnings that haven’t happened in 24 hours.");
        } elseif ($a === 'alerts') {
            set_setting('alert_emails', implode(', ', array_filter(array_map('trim', explode(',', (string) ($_POST['alert_emails'] ?? ''))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
            $hooks = trim((string) ($_POST['alert_webhooks'] ?? ''));
            if (!empty($_POST['clear_webhooks'])) set_setting('alert_webhooks', '');
            elseif ($hooks !== '') {
                foreach (preg_split('/\R/', $hooks) as $h) if (trim($h) !== '' && !preg_match('#^https://#i', trim($h)) && !local_testing()) throw new RuntimeException('Each webhook URL must start with https://');
                set_setting('alert_webhooks', $hooks);
            }
            set_setting('alert_min_level', in_array($_POST['alert_min_level'] ?? '', ['warning', 'error', 'critical'], true) ? $_POST['alert_min_level'] : 'error');
            set_setting('alert_digest', !empty($_POST['alert_digest']) ? '1' : '0');
            set_setting('alert_mailbox_id', (string) (int) ($_POST['alert_mailbox_id'] ?? 0));
            flash('Alert settings saved.');
        } elseif ($a === 'test_alert') {
            $t = alert_targets();
            if (!$t['hooks'] && !$t['emails']) throw new RuntimeException('Add an email address or a webhook first.');
            $msg = "Test alert from the Outreach app (" . base_url() . ").\nIf you can read this, error alerts will reach you here.\nSent by " . current_user()['email'] . ' at ' . $now . ' UTC';
            $res = [];
            foreach ($t['hooks'] as $h) { $e = alert_post($h, $msg); $res[] = parse_url($h, PHP_URL_HOST) . ': ' . ($e ?? 'delivered'); }
            if ($t['emails']) { $e = alert_email($t['emails'], '[Outreach] Test alert', $msg); $res[] = 'email: ' . ($e ?? 'sent'); }
            flash(implode(' · ', $res), str_contains(implode('', $res), 'delivered') || str_contains(implode('', $res), 'sent') ? 'ok' : 'err');
        } elseif ($a === 'new_health_key') {
            set_setting('health_key', bin2hex(random_bytes(12)));
            flash('New monitoring key created. Update the URL in your uptime monitor.');
        } elseif ($a === 'clear_fallback') {
            @unlink(APP_ROOT . '/storage/errors-fallback.log');
            flash('Fallback log cleared.');
        } elseif ($a === 'digest_now') {
            flash('Daily summary: ' . send_digest());
        }
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); }
    redirect('health.php' . ($id && $a !== 'bulk_resolve' ? '?id=' . $id : (str_starts_with($a, 'alert') || $a === 'test_alert' ? '#alerts' : '')));
}

/* ---------- lookup by reference ---------- */
if (($ref = strtoupper(trim((string) ($_GET['ref'] ?? '')))) !== '') {
    $gid = val('SELECT group_id FROM error_samples WHERE request_id = ? ORDER BY id DESC LIMIT 1', [$ref]);
    if ($gid) redirect('health.php?id=' . $gid . '&ref=' . urlencode($ref));
    flash("No error was recorded for reference $ref. It may have been older than 30 days, or the problem wasn’t an error.", 'err');
    redirect('health.php');
}

$tenantNames = array_column(all('SELECT id, name FROM tenants'), 'name', 'id');
$wsNames = function (?string $json) use ($tenantNames): string {
    $ids = json_decode((string) $json, true) ?: [];
    if (!$ids) return 'Platform';
    $n = array_map(fn($i) => $tenantNames[$i] ?? "#$i", array_slice($ids, 0, 3));
    return implode(', ', $n) . (count($ids) > 3 ? ' +' . (count($ids) - 3) : '');
};
$levelPill = fn(string $l): string => '<span class="lvl ' . e($l) . '">' . e(ucfirst($l)) . '</span>';

/* ---------- one problem ---------- */
if (isset($_GET['id'])) {
    $g = row('SELECT * FROM error_groups WHERE id = ?', [(int) $_GET['id']]);
    if (!$g) { http_response_code(404); deny('That problem no longer exists — resolved problems are cleared after 90 days.'); }
    $samples = all('SELECT s.*, u.email AS user_email FROM error_samples s LEFT JOIN users u ON u.id = s.user_id WHERE s.group_id = ? ORDER BY s.id DESC LIMIT 25', [$g['id']]);
    $last24 = (int) val('SELECT COUNT(*) FROM error_samples WHERE group_id = ? AND at >= ?', [$g['id'], $since24]);
    $hl = strtoupper((string) ($_GET['ref'] ?? ''));
    $report = "### [{$g['level']}] {$g['title']}\n"
        . "- Where: " . (ERROR_SOURCES[$g['source']] ?? $g['source']) . ($g['file'] ? " — `{$g['file']}:{$g['line']}`" : '') . "\n"
        . "- Count: {$g['count']} (last 24h: $last24) · first {$g['first_seen']} UTC · last {$g['last_seen']} UTC\n"
        . "- Workspaces: " . $wsNames($g['tenants']) . "\n- App: v" . APP_VERSION . ' · PHP ' . PHP_VERSION . "\n"
        . ($samples ? "- Last request: {$samples[0]['url']} (ref {$samples[0]['request_id']})\n" : '')
        . "\nMessage:\n```\n" . mb_substr((string) $g['message'], 0, 2000) . "\n```\n"
        . ($g['trace'] ? "\nTrace:\n```\n" . mb_substr((string) $g['trace'], 0, 6000) . "\n```\n" : '');
    page_header('Problem #' . $g['id'], 'health', ERROR_SOURCES[$g['source']] ?? $g['source']);
    ?>
<p><a href="health.php" class="back"><?= icon('arrow_back') ?>All problems</a></p>
<section class="panel">
  <div class="panel-head"><div><h2 class="break"><?= e($g['title']) ?></h2>
    <p class="muted"><?= $levelPill($g['level']) ?> <span class="status-pill <?= $g['status'] === 'open' ? 'suspended' : 'active' ?>"><?= e(ucfirst($g['status'])) ?><?= $g['status'] === 'muted' ? ($g['muted_until'] ? ' until ' . e(local_time($g['muted_until'])) : ' indefinitely') : '' ?></span>
    <?= $g['regressed'] ? '<span class="lvl critical">Came back after a fix</span>' : '' ?></p></div></div>
  <form method="post" class="actions"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
    <?php if ($g['status'] !== 'resolved'): ?><button name="action" value="resolve" class="btn filled" data-press><?= icon('check') ?>Mark as fixed</button><?php else: ?><button name="action" value="reopen" class="btn tonal" data-press>Reopen</button><?php endif; ?>
    <?php if ($g['status'] !== 'muted'): ?>
      <select name="hours" aria-label="Mute for"><option value="24">24 hours</option><option value="168">7 days</option><option value="720">30 days</option><option value="0">Until unmuted</option></select>
      <button name="action" value="mute" class="btn tonal" data-press><?= icon('notifications_off') ?>Mute alerts</button>
    <?php else: ?><button name="action" value="reopen" class="btn tonal" data-press>Unmute</button><?php endif; ?>
  </form>
</section>

<table class="t facts">
  <tr><th>Where</th><th>How often</th><th>When</th><th>Workspaces</th><th>Alerts</th></tr>
  <tr><td><?= e(ERROR_SOURCES[$g['source']] ?? $g['source']) ?><?php if ($g['file']): ?><br><code><?= e($g['file']) ?>:<?= (int) $g['line'] ?></code><?php endif; ?></td>
    <td><strong><?= number_format((int) $g['count']) ?></strong> in total<br><span class="muted small"><?= $last24 ?> in the last 24 h</span></td>
    <td class="small">First <?= e(local_time($g['first_seen'])) ?><br>Last <?= e(local_time($g['last_seen'])) ?> (<?= e(ago($g['last_seen'])) ?>)</td>
    <td><?= e($wsNames($g['tenants'])) ?></td>
    <td class="small"><?= $g['last_notified_at'] ? 'Sent ' . (int) $g['notified_count'] . '×, last ' . e(ago($g['last_notified_at'])) : 'None sent' ?></td></tr>
</table>

<section class="panel"><h2>Message</h2><pre class="code-block"><?= e((string) $g['message']) ?></pre>
<?php if ($g['trace']): ?><details class="trace"><summary>Stack trace</summary><pre class="code-block"><?= e((string) $g['trace']) ?></pre></details><?php endif; ?></section>

<h2 class="group-title">Recent occurrences <span class="tag">last <?= count($samples) ?></span></h2>
<table class="t">
  <tr><th>When</th><th>Reference</th><th>Workspace</th><th>User</th><th>Request</th><th>Details</th></tr>
  <?php foreach ($samples as $s): ?>
  <tr class="<?= $hl && $hl === $s['request_id'] ? 'hl' : '' ?>"><td class="small nowrap"><?= e(local_time($s['at'])) ?></td><td><code><?= e($s['request_id']) ?></code></td>
    <td><?= $s['tenant_id'] ? e($tenantNames[$s['tenant_id']] ?? '#' . $s['tenant_id']) : '<span class="muted">—</span>' ?></td>
    <td class="small"><?= e($s['user_email'] ?? '—') ?></td><td class="small break"><?= e((string) $s['url']) ?></td>
    <td class="small break"><?= $s['context'] ? '<code>' . e(mb_substr((string) $s['context'], 0, 300)) . '</code>' : '' ?></td></tr>
  <?php endforeach; ?>
</table>

<section class="panel form">
  <div class="panel-head"><div><h2>Report for the developer</h2><p class="muted">Copy this into a ticket or chat. It contains no passwords or keys.</p></div></div>
  <span class="copy tall"><textarea readonly rows="8"><?= e($report) ?></textarea><button type="button" class="icon-btn" data-copy aria-label="Copy report"><?= icon('content_copy') ?></button></span>
  <form method="post" class="note-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $g['id'] ?>"><input type="hidden" name="action" value="note">
    <label class="field">Team note <small>e.g. who is on it, ticket link</small><input name="note" maxlength="500" value="<?= e($g['note'] ?? '') ?>"></label>
    <div class="actions"><button class="btn tonal" data-press>Save note</button></div></form>
</section>
<?php
    page_footer();
    exit;
}

/* ---------- overview ---------- */
$f = [
    'status' => in_array($_GET['status'] ?? 'open', ['open', 'resolved', 'muted', 'all'], true) ? ($_GET['status'] ?? 'open') : 'open',
    'level' => isset(ERROR_LEVELS[$_GET['level'] ?? '']) ? $_GET['level'] : '',
    'source' => isset(ERROR_SOURCES[$_GET['source'] ?? '']) ? $_GET['source'] : '',
    'ws' => (int) ($_GET['ws'] ?? 0),
    'q' => trim((string) ($_GET['q'] ?? '')),
];
$w = ['1=1']; $p = [];
if ($f['status'] !== 'all') { $w[] = 'status = ?'; $p[] = $f['status']; }
if ($f['level']) { $w[] = 'level = ?'; $p[] = $f['level']; }
if ($f['source']) { $w[] = 'source = ?'; $p[] = $f['source']; }
if ($f['ws']) { $w[] = "JSON_CONTAINS(COALESCE(tenants, '[]'), ?)"; $p[] = (string) $f['ws']; }
if ($f['q'] !== '') { $w[] = '(title LIKE ? OR message LIKE ? OR file LIKE ?)'; array_push($p, "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%"); }
$where = implode(' AND ', $w);
$per = 50; $page = max(1, (int) ($_GET['page'] ?? 1));
$total = (int) val("SELECT COUNT(*) FROM error_groups WHERE $where", $p);
$groups = all("SELECT g.*, (SELECT COUNT(*) FROM error_samples s WHERE s.group_id = g.id AND s.at >= ?) last24 FROM error_groups g WHERE $where
               ORDER BY (g.status = 'open') DESC, g.level DESC, g.last_seen DESC LIMIT $per OFFSET " . (($page - 1) * $per), array_merge([$since24], $p));
$openBy = array_column(all("SELECT level, COUNT(*) n FROM error_groups WHERE status = 'open' GROUP BY level"), 'n', 'level');
$hits24 = (int) val("SELECT COUNT(*) FROM error_samples s JOIN error_groups g ON g.id = s.group_id WHERE s.at >= ? AND g.level IN ('error','critical')", [$since24]);

$lastCron = (string) setting('last_cron_at');
$cron = row('SELECT COUNT(*) n, ROUND(AVG(duration_ms)) avg_ms, MAX(duration_ms) max_ms, SUM(skipped) skipped, SUM(errors) errs FROM cron_runs WHERE started_at >= ?', [$since24]);
$runs = all('SELECT * FROM cron_runs ORDER BY id DESC LIMIT 12');
$speed = all('SELECT path, SUM(hits) hits, ROUND(SUM(total_ms) / SUM(hits)) avg_ms, MAX(max_ms) max_ms, SUM(slow_hits) slow, ROUND(SUM(queries) / SUM(hits), 1) q
              FROM perf_daily WHERE day >= ? GROUP BY path ORDER BY avg_ms DESC LIMIT 15', [gmdate('Y-m-d', time() - 86400)]);
$overall = row('SELECT SUM(hits) hits, ROUND(SUM(total_ms) / NULLIF(SUM(hits), 0)) avg_ms FROM perf_daily WHERE day = ?', [gmdate('Y-m-d')]);
$dbMb = (float) val('SELECT ROUND(SUM(data_length + index_length) / 1048576, 1) FROM information_schema.TABLES WHERE table_schema = DATABASE()');
$opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
$targets = alert_targets();
$hookCount = count($targets['hooks']);
$mailboxes = all('SELECT m.id, m.email, t.name tname FROM mailboxes m JOIN tenants t ON t.id = m.tenant_id ORDER BY m.tenant_id, m.id');
$fallback = is_file(APP_ROOT . '/storage/errors-fallback.log') ? array_slice(file(APP_ROOT . '/storage/errors-fallback.log', FILE_IGNORE_NEW_LINES) ?: [], -15) : [];
$cronAge = $lastCron ? (int) floor((time() - strtotime($lastCron . ' UTC')) / 60) : null;
$params = array_filter(['status' => $f['status'] !== 'open' ? $f['status'] : null, 'level' => $f['level'] ?: null, 'source' => $f['source'] ?: null, 'ws' => $f['ws'] ?: null, 'q' => $f['q'] ?: null]);

page_header('System health', 'health', 'Errors, speed and background jobs across every workspace. Only platform admins see this page.');
?>
<div class="kpis health-kpis">
  <div class="kpi <?= ($cronAge === null || $cronAge > 15) ? 'alarm' : '' ?>"><span>Cron</span><strong><?= $cronAge === null ? 'Never' : ($cronAge < 1 ? 'Now' : $cronAge . ' min') ?></strong><em><?= (int) $cron['n'] ?> runs in 24 h · avg <?= number_format((int) $cron['avg_ms'] / 1000, 1) ?> s<?= (int) $cron['skipped'] ? ' · ' . (int) $cron['skipped'] . ' skipped' : '' ?></em></div>
  <div class="kpi <?= (int) ($openBy['critical'] ?? 0) ? 'alarm' : '' ?>"><span>Open problems</span><strong><?= (int) ($openBy['critical'] ?? 0) + (int) ($openBy['error'] ?? 0) ?></strong><em><?= (int) ($openBy['critical'] ?? 0) ?> critical · <?= (int) ($openBy['error'] ?? 0) ?> errors · <?= (int) ($openBy['warning'] ?? 0) ?> warnings</em></div>
  <div class="kpi"><span>Errors in 24 h</span><strong><?= number_format($hits24) ?></strong><em>occurrences of errors and critical problems</em></div>
  <div class="kpi <?= (int) ($overall['avg_ms'] ?? 0) > 1500 ? 'alarm' : '' ?>"><span>Page speed today</span><strong><?= $overall && $overall['hits'] ? number_format((int) $overall['avg_ms']) . ' ms' : '—' ?></strong><em>average over <?= number_format((int) ($overall['hits'] ?? 0)) ?> page views</em></div>
  <div class="kpi <?= !$hookCount && !$targets['emails'] ? 'alarm' : '' ?>"><span>Alerts go to</span><strong><?= $hookCount + count($targets['emails']) ?: 'Nobody' ?></strong><em><?= count($targets['emails']) ?> email · <?= $hookCount ?> chat webhook<?= $hookCount === 1 ? '' : 's' ?></em></div>
</div>

<div class="chips">
  <a href="#problems" class="on">Problems</a><a href="#cron">Background jobs</a><a href="#speed">Page speed</a><a href="#alerts">Alerts</a><a href="#server">Server</a>
</div>

<?php if ($fallback): ?>
<section class="panel warn-panel"><div class="panel-head"><div><h2>Recorded while the database was unreachable</h2><p class="muted">These couldn’t be saved normally. Check them, then clear the list.</p></div>
  <form method="post"><?= csrf_field() ?><button name="action" value="clear_fallback" class="btn text" data-press>Clear</button></form></div>
  <pre class="code-block"><?= e(implode("\n", $fallback)) ?></pre></section>
<?php endif; ?>

<h2 class="group-title" id="problems">Problems</h2>
<div class="chips">
  <?php foreach (['open' => 'Open', 'muted' => 'Muted', 'resolved' => 'Fixed', 'all' => 'All'] as $k => $label): ?>
    <a href="?<?= e(http_build_query(['status' => $k] + array_diff_key($params, ['status' => 1]))) ?>#problems" class="<?= $f['status'] === $k ? 'on' : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>
<form class="filters" method="get" action="health.php#problems">
  <input type="hidden" name="status" value="<?= e($f['status']) ?>">
  <select name="level"><option value="">Any level</option><?php foreach (array_reverse(array_keys(ERROR_LEVELS)) as $l): ?><option value="<?= $l ?>" <?= $f['level'] === $l ? 'selected' : '' ?>><?= ucfirst($l) ?></option><?php endforeach; ?></select>
  <select name="source"><option value="">Any source</option><?php foreach (ERROR_SOURCES as $k => $l): ?><option value="<?= $k ?>" <?= $f['source'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <select name="ws"><option value="0">All workspaces</option><?php foreach ($tenantNames as $tid => $n): ?><option value="<?= $tid ?>" <?= $f['ws'] === (int) $tid ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select>
  <input name="q" value="<?= e($f['q']) ?>" placeholder="Search message or file…">
  <button class="btn">Filter</button>
</form>
<form class="filters ref-form" method="get" action="health.php"><input name="ref" placeholder="Reference from an error page, e.g. 3FA9C21B" maxlength="8" pattern="[A-Fa-f0-9]{8}" aria-label="Error reference"><button class="btn tonal"><?= icon('search') ?>Find</button></form>

<table class="t problems">
  <tr><th>Problem</th><th>Level</th><th>Workspaces</th><th>24 h</th><th>Total</th><th>Last seen</th></tr>
  <?php foreach ($groups as $g): ?>
  <tr><td><a href="?id=<?= (int) $g['id'] ?>" class="strong break"><?= e(mb_substr($g['title'], 0, 140)) ?></a>
      <br><span class="muted small"><?= e(ERROR_SOURCES[$g['source']] ?? $g['source']) ?><?= $g['file'] ? ' · ' . e($g['file']) . ':' . (int) $g['line'] : '' ?><?= $g['status'] !== 'open' ? ' · ' . e($g['status'] === 'resolved' ? 'fixed' : 'muted') : '' ?><?= $g['regressed'] ? ' · came back' : '' ?><?= $g['note'] ? ' · ' . e(mb_substr($g['note'], 0, 60)) : '' ?></span></td>
    <td><?= $levelPill($g['level']) ?></td>
    <td class="small"><?= e($wsNames($g['tenants'])) ?></td>
    <td><?= (int) $g['last24'] ?: '<span class="muted">0</span>' ?></td>
    <td><?= number_format((int) $g['count']) ?></td>
    <td class="small nowrap"><?= e(ago($g['last_seen'])) ?></td></tr>
  <?php endforeach; if (!$groups): ?><tr><td colspan="6" class="empty-row"><?= icon('verified', 'fill') ?> <?= $f['status'] === 'open' && !$f['level'] && !$f['source'] && !$f['q'] && !$f['ws'] ? 'No open problems. Everything recorded so far has been fixed or muted.' : 'Nothing matches these filters.' ?></td></tr><?php endif; ?>
</table>
<?= pager($total, $page, $per, $params) ?>
<?php if ($f['status'] === 'open' && $total): ?><form method="post" class="actions"><?= csrf_field() ?><button name="action" value="bulk_resolve" class="btn text" data-confirm="Close every open warning that hasn’t happened in the last 24 hours?" data-press>Close quiet warnings</button></form><?php endif; ?>

<h2 class="group-title" id="cron">Background jobs <span class="tag">last 12 runs</span></h2>
<table class="t">
  <tr><th>Started</th><th>Took</th><th>Workspaces</th><th>Job errors</th><th>Memory</th><th>Result</th></tr>
  <?php foreach ($runs as $r): $sec = $r['duration_ms'] / 1000; ?>
  <tr><td class="small nowrap"><?= e(local_time($r['started_at'], 'M j, H:i:s')) ?></td>
    <td class="<?= $sec > 55 ? 'bad-text' : '' ?>"><?= number_format($sec, 1) ?> s</td>
    <td><?= (int) $r['tenants'] ?></td><td class="<?= $r['errors'] ? 'bad-text' : '' ?>"><?= (int) $r['errors'] ?></td>
    <td class="small"><?= e($r['peak_mb']) ?> MB</td>
    <td class="small"><?= $r['skipped'] ? '<span class="lvl warning">Skipped — previous run still going</span>' : e(mb_substr((string) $r['jobs'], 0, 60)) ?></td></tr>
  <?php endforeach; if (!$runs): ?><tr><td colspan="6" class="muted">No runs recorded yet. Check the cron job in hPanel.</td></tr><?php endif; ?>
</table>
<p class="small muted">Each run has a 50-second budget shared fairly across active workspaces. Runs that regularly take more than 55 s, or show as skipped, mean the server needs more room: fewer workspaces per installation, or a VPS.</p>

<h2 class="group-title" id="speed">Page speed <span class="tag">today and yesterday</span></h2>
<table class="t">
  <tr><th>Page</th><th>Views</th><th>Average</th><th>Slowest</th><th>Slow views (&gt; <?= SLOW_PAGE_MS / 1000 ?> s)</th><th>Queries per view</th></tr>
  <?php foreach ($speed as $r): ?>
  <tr><td><code><?= e($r['path']) ?></code></td><td><?= number_format((int) $r['hits']) ?></td>
    <td class="<?= (int) $r['avg_ms'] > 1500 ? 'bad-text' : '' ?>"><?= number_format((int) $r['avg_ms']) ?> ms</td><td><?= number_format((int) $r['max_ms']) ?> ms</td>
    <td><?= (int) $r['slow'] ?></td><td><?= e($r['q']) ?></td></tr>
  <?php endforeach; if (!$speed): ?><tr><td colspan="6" class="muted">No page views recorded yet.</td></tr><?php endif; ?>
</table>

<h2 class="group-title" id="alerts">Alerts for the development team</h2>
<form method="post" class="panel form"><?= csrf_field() ?><input type="hidden" name="action" value="alerts">
  <p class="muted">You’re alerted when a problem is new, comes back after being marked fixed, reaches 10, 100 or 1,000 occurrences, or — for critical ones — is still happening two hours later. At most <?= ALERTS_PER_HOUR ?> alerts an hour.</p>
  <label class="field">Email addresses <small>comma-separated</small><input name="alert_emails" value="<?= e(implode(', ', $targets['emails'])) ?>" placeholder="dev@yourteam.com, joy@automindlab.io"></label>
  <label class="field">Send alert emails from <small>pick your agency’s own inbox — ideally an alerts@ inbox with sending to prospects switched off</small>
    <select name="alert_mailbox_id"><option value="0">— choose an inbox —</option><?php foreach ($mailboxes as $m): ?><option value="<?= (int) $m['id'] ?>" <?= (int) setting('alert_mailbox_id', 0) === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['email']) ?> (<?= e($m['tname']) ?>)</option><?php endforeach; ?></select></label>
  <label class="field">Chat webhooks <small>one per line — Slack, Discord, Google Chat, Telegram (<code>https://api.telegram.org/bot&lt;token&gt;/sendMessage?chat_id=&lt;id&gt;</code>), or n8n / Make / Zapier</small>
    <textarea name="alert_webhooks" rows="3" placeholder="<?= $hookCount ? $hookCount . ' saved (stored encrypted). Leave empty to keep them, or paste new ones to replace them.' : 'https://hooks.slack.com/services/…' ?>"></textarea></label>
  <?php if ($hookCount): ?><ul class="small muted hook-list"><?php foreach ($targets['hooks'] as $h): ?><li><?= e(parse_url($h, PHP_URL_HOST)) ?> …<?= e(substr($h, -6)) ?></li><?php endforeach; ?></ul>
    <label class="switch"><input type="checkbox" name="clear_webhooks" value="1"><span></span>Remove all saved webhooks</label><?php endif; ?>
  <div class="two"><label class="field">Alert me about<select name="alert_min_level">
      <?php foreach (['critical' => 'Critical only', 'error' => 'Errors and critical (recommended)', 'warning' => 'Warnings too (noisy)'] as $k => $l): ?><option value="<?= $k ?>" <?= setting('alert_min_level', 'error') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
    <label class="switch" style="align-self:end"><input type="checkbox" name="alert_digest" value="1" <?= setting('alert_digest', '1') === '1' ? 'checked' : '' ?>><span></span>Daily health summary</label></div>
  <?php if ($lf = setting('alert_last_failure')): ?><p class="test-msg err">Last delivery problem: <?= e($lf) ?></p><?php endif; ?>
  <div class="actions"><button class="btn filled" data-press>Save</button>
    <button class="btn tonal" name="action" value="test_alert" data-press><?= icon('send') ?>Send test alert</button>
    <button class="btn text" name="action" value="digest_now" data-press>Send today’s summary now</button></div>
</form>

<h2 class="group-title" id="server">Server</h2>
<section class="panel">
  <dl class="facts-list">
    <dt>App</dt><dd>v<?= e(APP_VERSION) ?> · schema <?= (int) setting('schema_version') ?></dd>
    <dt>PHP</dt><dd><?= e(PHP_VERSION) ?> · memory limit <?= e(ini_get('memory_limit')) ?> · max run <?= e(ini_get('max_execution_time')) ?> s</dd>
    <dt>OPcache</dt><dd><?= $opcache && !empty($opcache['opcache_enabled']) ? '<span class="lvl ok">On</span> · ' . round(($opcache['memory_usage']['used_memory'] ?? 0) / 1048576) . ' MB used · hit rate ' . round($opcache['opcache_statistics']['opcache_hit_rate'] ?? 0) . '%' : '<span class="lvl warning">Off or not visible</span> — turn on in hPanel → Advanced → PHP Configuration → PHP options' ?></dd>
    <dt>Database</dt><dd><?= e(number_format($dbMb, 1)) ?> MB · <?= (int) val('SELECT COUNT(*) FROM leads') ?> leads · <?= (int) val('SELECT COUNT(*) FROM tenants') ?> workspaces</dd>
    <dt>Installer</dt><dd><?= is_file(APP_ROOT . '/install.php') ? '<span class="lvl ok">Locked</span> install.php answers “not found” now that an admin exists' : 'Removed' ?></dd>
    <dt>Storage folder</dt><dd><?= is_writable(APP_ROOT . '/storage') ? '<span class="lvl ok">Writable</span>' : '<span class="lvl critical">Not writable</span> — locks and logs will fail' ?><?php $free = @disk_free_space(APP_ROOT); if ($free !== false): ?> · <?= number_format($free / 1073741824, 1) ?> GB free<?php endif; ?></dd>
    <dt>Uptime monitor</dt><dd><span class="copy"><input readonly value="<?= e(base_url('healthz.php?key=' . setting('health_key'))) ?>"><button type="button" class="icon-btn" data-copy aria-label="Copy"><?= icon('content_copy') ?></button></span>
      <span class="small muted">Add this URL to a free monitor such as UptimeRobot (check every 5 minutes). It answers 200 when the database works and cron ran in the last 20 minutes, 503 otherwise.</span>
      <form method="post" class="inline"><?= csrf_field() ?><button name="action" value="new_health_key" class="btn text small" data-confirm="The old monitoring URL will stop working." data-press>New key</button></form></dd>
  </dl>
</section>
<?php page_footer();
