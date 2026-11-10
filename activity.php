<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_login();

$level = (string) ($_GET['level'] ?? '');
$page = max(1, (int) ($_GET['p'] ?? 1));
$per = 100;
$w = 'e.tenant_id = ?' . ($level !== '' ? ' AND e.level = ?' : '');
$p = $level !== '' ? [tid(), $level] : [tid()];
$total = (int) val("SELECT COUNT(*) FROM events e WHERE $w", $p);
$rows = all("SELECT e.*, l.business_name FROM events e LEFT JOIN leads l ON l.id = e.lead_id WHERE $w ORDER BY e.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p);
$supp = all('SELECT * FROM suppression WHERE tenant_id IN (0, ?) ORDER BY created_at DESC LIMIT 50', [tid()]);
page_header('Activity', 'activity', 'Everything the system did, newest first.');
?>
<div class="chips"><a href="activity.php" class="<?= $level === '' ? 'on' : '' ?>">All</a><a href="?level=error" class="<?= $level === 'error' ? 'on' : '' ?>">Errors</a><a href="?level=warn" class="<?= $level === 'warn' ? 'on' : '' ?>">Warnings</a></div>
<section class="card">
<?php foreach ($rows as $a): ?>
  <div class="act <?= e($a['level']) ?>"><time><?= e(local_time($a['created_at'])) ?></time><code class="small"><?= e($a['type']) ?></code>
    <span><?= $a['lead_id'] ? '<a href="lead.php?id=' . $a['lead_id'] . '">' . e($a['business_name']) . '</a> · ' : '' ?><?= e($a['detail']) ?></span></div>
<?php endforeach; ?>
</section>
<?= pager($total, $page, $per, array_filter(['level' => $level])) ?>
<section class="card"><h2>Do-not-contact list <span class="muted small">latest 50</span></h2>
  <table class="t"><tr><th>Value</th><th>Type</th><th>Reason</th><th>Added</th></tr>
  <?php foreach ($supp as $s): ?><tr><td><?= e($s['value']) ?></td><td><?= e($s['type']) ?></td><td><?= e($s['reason']) ?></td><td class="small"><?= e(local_time($s['created_at'])) ?></td></tr><?php endforeach; ?>
  </table></section>
<?php page_footer();
