<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_role('member');
    // keep only ids that belong to this workspace
    $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
    $ids = $ids ? array_map('intval', array_column(all('SELECT id FROM leads WHERE tenant_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_merge([tid()], $ids)), 'id')) : [];
    $act = (string) ($_POST['bulk'] ?? '');
    $n = 0;
    try {
        foreach ($ids as $id) {
            if ($act === 'approve') $n += approve_lead($id) ? 1 : 0;
            elseif ($act === 'disqualify') { cancel_sequence($id, 'disqualified manually'); $n += update('leads', ['status' => 'disqualified'], 'id = ? AND tenant_id = ?', [$id, tid()]); }
            elseif ($act === 'redraft') $n += update('leads', ['status' => 'enriched', 'attempts' => 0, 'error' => null], "id = ? AND status IN ('drafted','disqualified','error') AND email IS NOT NULL AND tenant_id = ?", [$id, tid()]);
            elseif ($act === 'retry') $n += update('leads', ['status' => 'new', 'attempts' => 0, 'error' => null], "id = ? AND status IN ('no_email','error','skipped') AND tenant_id = ?", [$id, tid()]);
            elseif ($act === 'delete') {
                if (!can('admin')) continue;
                // Contacted leads are kept (send history and opt-outs are compliance records); only never-contacted ones are removed, with their drafts.
                if (!val('SELECT 1 FROM leads WHERE id = ? AND tenant_id = ? AND last_sent_at IS NULL', [$id, tid()])) continue;
                q('DELETE FROM emails WHERE lead_id = ? AND tenant_id = ?', [$id, tid()]);
                q('DELETE FROM li_tasks WHERE lead_id = ? AND tenant_id = ?', [$id, tid()]);
                $n += q('DELETE FROM leads WHERE id = ? AND tenant_id = ? AND last_sent_at IS NULL', [$id, tid()])->rowCount();
            }
        }
        flash("$act: $n lead(s) updated");
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); }
    redirect('leads.php?' . http_build_query(array_intersect_key($_GET, array_flip(['status', 'campaign', 'q', 'p']))));
}

$status = (string) ($_GET['status'] ?? '');
$campaign = (int) ($_GET['campaign'] ?? 0);
$search = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['p'] ?? 1));
$per = 50;
$where = ['l.tenant_id = ?']; $p = [tid()];
if ($status !== '') { $where[] = 'l.status = ?'; $p[] = $status; } else { $where[] = "l.status <> 'no_website'"; }
if ($campaign) { $where[] = 'l.campaign_id = ?'; $p[] = $campaign; }
if ($search !== '') { $where[] = '(l.business_name LIKE ? OR l.email LIKE ? OR l.city LIKE ? OR l.domain LIKE ?)'; array_push($p, "%$search%", "%$search%", "%$search%", "%$search%"); }
$w = implode(' AND ', $where);

if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="leads-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    $csv = fn(array $row) => array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v, $row); // block spreadsheet formulas
    fputcsv($out, ['id', 'business', 'searched_as', 'city', 'region', 'country', 'phone', 'website', 'email', 'email_source', 'contact', 'status', 'fit_score', 'last_sent', 'replied', 'booked', 'linkedin', 'linkedin_status']);
    // Stream rows straight to the download instead of loading the whole workspace into memory.
    db()->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    $st = q("SELECT l.id, l.business_name, l.category, l.city, l.region, l.country, l.phone, l.website, l.email, l.email_source, l.contact_name, l.status, l.fit_score,
                    l.last_sent_at, l.replied_at, l.booked_at, l.linkedin_url, l.li_state FROM leads l WHERE $w ORDER BY l.id DESC", $p);
    while ($r = $st->fetch()) {
        fputcsv($out, $csv([$r['id'], $r['business_name'], $r['category'], $r['city'], $r['region'], $r['country'], $r['phone'], $r['website'], $r['email'], $r['email_source'], $r['contact_name'], $r['status'], $r['fit_score'], $r['last_sent_at'], $r['replied_at'], $r['booked_at'], $r['linkedin_url'], LI_STATES[$r['li_state']] ?? $r['li_state']]));
    }
    $st->closeCursor();
    exit;
}

$total = (int) val("SELECT COUNT(*) FROM leads l WHERE $w", $p);
$rows = all("SELECT l.*, c.name AS campaign_name FROM leads l LEFT JOIN campaigns c ON c.id = l.campaign_id WHERE $w
             ORDER BY " . ($status === 'drafted' ? 'l.fit_score DESC, l.id' : 'l.updated_at DESC') . " LIMIT $per OFFSET " . (($page - 1) * $per), $p);
$counts = [];
foreach (all('SELECT status, COUNT(*) n FROM leads WHERE tenant_id = ? GROUP BY status', [tid()]) as $r) $counts[$r['status']] = (int) $r['n'];
$campaigns = all('SELECT id, name FROM campaigns WHERE tenant_id = ? ORDER BY name', [tid()]);
$params = array_filter(['status' => $status, 'campaign' => $campaign ?: null, 'q' => $search ?: null]);

page_header($status === 'drafted' ? 'Review queue' : 'Leads', $status === 'drafted' ? 'review' : 'leads');
?>
<div class="chips">
  <a href="leads.php" class="<?= $status === '' ? 'on' : '' ?>">All</a>
  <?php foreach (STATUS_LABELS as $s => $label): if (empty($counts[$s])) continue; ?>
    <a href="?<?= e(http_build_query(['status' => $s] + array_diff_key($params, ['status' => 1]))) ?>" class="<?= $status === $s ? 'on' : '' ?>"><?= e($label) ?> <b><?= $counts[$s] ?></b></a>
  <?php endforeach; ?>
</div>
<form class="filters" method="get">
  <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
  <select name="campaign"><option value="">All campaigns</option><?php foreach ($campaigns as $c): ?><option value="<?= $c['id'] ?>" <?= $campaign === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
  <input name="q" value="<?= e($search) ?>" placeholder="Search business, email, city…">
  <button class="btn">Filter</button>
  <a class="btn ghost" href="?<?= e(http_build_query($params + ['export' => 1])) ?>">Export CSV</a>
</form>
<?php if ($status === 'drafted'): ?><p class="hint">Open a lead to read and edit its sequence, or select several and approve. Approved leads start sending inside each lead's local business hours.</p><?php endif; ?>
<form method="post" id="bulkform"><?= csrf_field() ?>
<?php if (can('member')): ?><div class="bulk">
  <label><input type="checkbox" data-checkall> Select all</label>
  <button name="bulk" value="approve" class="btn primary">Approve &amp; queue</button>
  <button name="bulk" value="redraft" class="btn">Re-draft with AI</button>
  <button name="bulk" value="retry" class="btn">Retry research</button>
  <button name="bulk" value="disqualify" class="btn">Not a fit</button>
  <?php if (can('admin')): ?><button name="bulk" value="delete" class="btn danger" data-confirm="Delete selected leads that were never emailed?">Delete</button><?php endif; ?>
</div><?php endif; ?>
<table class="t leads">
  <tr><th></th><th>Business</th><th>Fit</th><th>Status</th><th>Email</th><th>Location</th><th>Updated</th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><input type="checkbox" name="ids[]" value="<?= $r['id'] ?>"></td>
    <td><a href="lead.php?id=<?= $r['id'] ?>"><strong><?= e($r['business_name']) ?></strong></a><br><span class="muted small"><?= e($r['category'] ?: $r['campaign_name']) ?></span></td>
    <td><?= score_pill($r['fit_score']) ?></td>
    <td><?= status_badge($r['status']) ?><?php if ($r['error'] && in_array($r['status'], ['no_email', 'skipped', 'error'], true)): ?><br><span class="muted small"><?= e($r['error']) ?></span><?php endif; ?></td>
    <td class="small"><?= e($r['email'] ?? '—') ?></td>
    <td class="small"><?= e(trim($r['city'] . ', ' . $r['region'], ', ')) ?></td>
    <td class="small muted"><?= e(local_time($r['updated_at'])) ?></td>
  </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="muted">Nothing here yet.</td></tr><?php endif; ?>
</table>
</form>
<?= pager($total, $page, $per, $params) ?>
<?php page_footer();
