<?php
declare(strict_types=1);
define('APP_AREA', 'admin');
require __DIR__ . '/../lib/bootstrap.php';
require_login();
require_platform_admin();

const PLAN_PRESETS = [
    'Starter' => ['quota_leads' => 600,  'quota_ai_usd' => 8,  'quota_mailboxes' => 3],
    'Growth'  => ['quota_leads' => 1500, 'quota_ai_usd' => 18, 'quota_mailboxes' => 6],
    'Scale'   => ['quota_leads' => 4000, 'quota_ai_usd' => 45, 'quota_mailboxes' => 12],
];
$me = $GLOBALS['CURRENT_USER'];

function ws_fields(array $in): array {
    $name = trim(mb_substr((string) ($in['name'] ?? ''), 0, 190));
    if ($name === '') throw new RuntimeException('Give the workspace a name.');
    return [
        'name' => $name,
        'plan' => trim(mb_substr((string) ($in['plan'] ?? 'Starter'), 0, 60)) ?: 'Starter',
        'quota_leads' => max(0, (int) ($in['quota_leads'] ?? 0)),
        'quota_ai_usd' => round(max(0, (float) ($in['quota_ai_usd'] ?? 0)), 2),
        'quota_mailboxes' => max(0, (int) ($in['quota_mailboxes'] ?? 0)),
        'notes' => trim(mb_substr((string) ($in['notes'] ?? ''), 0, 2000)) ?: null,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    try {
        if ($a === 'create') {
            $d = ws_fields($_POST);
            $id = insert('tenants', $d + ['status' => 'active']);
            set_tenant_setting('company_name', $d['name'], $id);
            set_tenant_setting('webhook_key', bin2hex(random_bytes(16)), $id);
            set_tenant_setting('sending_paused', '1', $id);
            $owner = trim((string) ($_POST['owner_email'] ?? ''));
            if ($owner !== '') $_SESSION['new_invite'] = ['email' => strtolower($owner), 'role' => 'owner', 'ws' => $d['name'], 'link' => create_invite($id, $owner, 'owner', (int) $me['id'])];
            flash("Workspace “{$d['name']}” created. Sending starts paused until its owner connects an inbox and switches it on.");
            redirect('workspaces.php?edit=' . $id);
        }
        $t = row('SELECT * FROM tenants WHERE id = ?', [$id]) ?? throw new RuntimeException('Workspace not found.');
        if ($a === 'save') {
            update('tenants', ws_fields($_POST), 'id = ?', [$id]);
            flash('Workspace saved.');
        } elseif ($a === 'suspend' || $a === 'resume') {
            if ($a === 'suspend' && $id === 1) throw new RuntimeException('Your own agency workspace (#1) can’t be suspended.');
            update('tenants', ['status' => $a === 'suspend' ? 'suspended' : 'active'], 'id = ?', [$id]);
            $prev = tid(); set_tenant($id);
            log_event(null, 'workspace', 'Workspace ' . ($a === 'suspend' ? 'suspended' : 'resumed') . ' by ' . $me['email'], $a === 'suspend' ? 'warn' : 'info');
            set_tenant($prev);
            flash($a === 'suspend' ? "“{$t['name']}” suspended — nothing is searched or sent, and its team can only look." : "“{$t['name']}” is active again.");
        } elseif ($a === 'invite_owner') {
            $email = strtolower(trim((string) ($_POST['owner_email'] ?? '')));
            $_SESSION['new_invite'] = ['email' => $email, 'role' => 'owner', 'ws' => $t['name'], 'link' => create_invite($id, $email, 'owner', (int) $me['id'])];
            flash("Owner invitation created for $email.");
        }
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); }
    redirect('workspaces.php' . ($id ? '?edit=' . $id : ''));
}

$rows = all("SELECT t.*, (SELECT COUNT(*) FROM memberships m WHERE m.tenant_id = t.id) members,
  (SELECT v FROM tenant_settings s WHERE s.tenant_id = t.id AND s.k = 'last_run_at') last_run,
  (SELECT v FROM tenant_settings s WHERE s.tenant_id = t.id AND s.k = 'sending_paused') paused
  FROM tenants t ORDER BY t.status = 'active' DESC, t.name");
$edit = isset($_GET['edit']) ? row('SELECT * FROM tenants WHERE id = ?', [(int) $_GET['edit']]) : null;
$new = $_SESSION['new_invite'] ?? null; unset($_SESSION['new_invite']);
$tot = ['leads' => 0, 'ai' => 0.0];

page_header('Workspaces', 'workspaces', 'Every client workspace on this platform: plans, monthly allowances and status. Billing is handled outside the app.');
?>
<?php if ($new): ?>
<section class="panel invite-ready">
  <div class="panel-head"><div><h2>Send this link to <?= e($new['email']) ?></h2>
    <p class="muted">They’ll become an owner of <?= e($new['ws']) ?>. The link works once and expires in <?= INVITE_DAYS ?> days, and is only shown now.</p></div></div>
  <span class="copy"><input readonly value="<?= e($new['link']) ?>"><button type="button" class="icon-btn" data-copy aria-label="Copy link"><?= icon('content_copy') ?></button></span>
</section>
<?php endif; ?>

<table class="t wide">
  <tr><th>Workspace</th><th>Plan</th><th>Leads this month</th><th>AI this month</th><th>Inboxes</th><th>Team</th><th>Last run</th><th>Status</th><th></th></tr>
  <?php foreach ($rows as $r): $q = quota_status((int) $r['id']); $tot['leads'] += $q['leads_used']; $tot['ai'] += $q['ai_used']; ?>
  <tr><td><strong><?= e($r['name']) ?></strong><br><span class="muted small">#<?= (int) $r['id'] ?><?= (int) $r['id'] === 1 ? ' · your agency' : '' ?></span></td>
    <td><?= e($r['plan']) ?></td>
    <td><?= $q['leads_used'] ?> <span class="muted small">/ <?= $q['leads_limit'] ?: '∞' ?></span>
      <div class="meter"><i style="--v:<?= $q['leads_limit'] ? min(100, round(100 * $q['leads_used'] / $q['leads_limit'])) : 0 ?>%"></i></div></td>
    <td>$<?= number_format($q['ai_used'], 2) ?> <span class="muted small">/ <?= $q['ai_limit'] > 0 ? '$' . number_format($q['ai_limit'], 0) : '∞' ?></span>
      <div class="meter"><i style="--v:<?= $q['ai_limit'] > 0 ? min(100, round(100 * $q['ai_used'] / $q['ai_limit'])) : 0 ?>%"></i></div></td>
    <td><?= $q['mailboxes_used'] ?> <span class="muted small">/ <?= $q['mailboxes_limit'] ?: '∞' ?></span></td>
    <td><?= (int) $r['members'] ?></td>
    <td class="small"><?= e(ago($r['last_run'] ?: null)) ?></td>
    <td><span class="status-pill <?= e($r['status']) ?>"><?= $r['status'] === 'active' ? ($r['paused'] === '0' ? 'Active' : 'Active · paused') : 'Suspended' ?></span></td>
    <td class="nowrap"><form method="post" action="open.php" class="inline"><?= csrf_field() ?><button class="btn small" name="tenant" value="<?= (int) $r['id'] ?>">Open</button></form>
      <a class="btn small" href="?edit=<?= (int) $r['id'] ?>#edit">Edit</a></td></tr>
  <?php endforeach; ?>
  <tr class="total"><td><strong>All workspaces</strong></td><td></td><td><strong><?= $tot['leads'] ?></strong></td><td><strong>$<?= number_format($tot['ai'], 2) ?></strong></td><td colspan="5" class="muted small">Google Places searches this month: <?= (int) month_usage('places') ?> · detail lookups: <?= (int) month_usage('places_details') ?></td></tr>
</table>

<?php if ($edit): $q = quota_status((int) $edit['id']); ?>
<form method="post" class="panel form" id="edit"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
  <div class="panel-head"><div><h2><?= e($edit['name']) ?></h2><p class="muted">Allowances reset on the 1st of each month (UTC). 0 means unlimited.</p></div>
    <span class="status-pill <?= e($edit['status']) ?>"><?= $edit['status'] === 'active' ? 'Active' : 'Suspended' ?></span></div>
  <div class="two"><label class="field">Name<input name="name" value="<?= e($edit['name']) ?>" required></label>
    <label class="field">Plan<input name="plan" value="<?= e($edit['plan']) ?>" list="plans"></label></div>
  <div class="three"><label class="field">New leads per month<input type="number" min="0" name="quota_leads" value="<?= (int) $edit['quota_leads'] ?>"></label>
    <label class="field">AI spend per month ($)<input type="number" min="0" step="0.5" name="quota_ai_usd" value="<?= e($edit['quota_ai_usd']) ?>"></label>
    <label class="field">Sending inboxes<input type="number" min="0" name="quota_mailboxes" value="<?= (int) $edit['quota_mailboxes'] ?>"></label></div>
  <label class="field">Notes <small>only platform admins see this — billing, contacts, anything</small><textarea name="notes" rows="3"><?= e($edit['notes'] ?? '') ?></textarea></label>
  <div class="actions"><button name="action" value="save" class="btn filled" data-press>Save</button>
    <?php if ($edit['status'] === 'active'): if ((int) $edit['id'] !== 1): ?><button name="action" value="suspend" class="btn text danger" data-confirm="Suspend <?= e($edit['name']) ?>? Searching and sending stop, and its team can only view." data-press>Suspend</button><?php endif; ?>
    <?php else: ?><button name="action" value="resume" class="btn tonal" data-press>Resume</button><?php endif; ?></div>
</form>
<form method="post" class="panel form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><input type="hidden" name="action" value="invite_owner">
  <div class="panel-head"><div><h2>Invite an owner</h2><p class="muted">The client’s owner can then invite their own team from the Team page.</p></div></div>
  <div class="two"><label class="field">Email<input type="email" name="owner_email" required></label><div class="actions" style="align-self:end"><button class="btn tonal" data-press><?= icon('person_add') ?>Create invite link</button></div></div>
</form>
<?php endif; ?>

<form method="post" class="panel form" id="new"><?= csrf_field() ?><input type="hidden" name="action" value="create">
  <div class="panel-head"><div><h2>New workspace</h2><p class="muted">One per client. Each gets its own leads, campaigns, inboxes, settings and team; lead search and AI come from your platform keys within the allowance below.</p></div></div>
  <div class="two"><label class="field">Client or workspace name<input name="name" required></label>
    <label class="field">Plan<select name="plan" data-plan-select><?php foreach (PLAN_PRESETS as $k => $p): ?><option value="<?= $k ?>" data-leads="<?= $p['quota_leads'] ?>" data-ai="<?= $p['quota_ai_usd'] ?>" data-mbx="<?= $p['quota_mailboxes'] ?>"><?= $k ?></option><?php endforeach; ?></select></label></div>
  <div class="three"><label class="field">New leads per month<input type="number" min="0" name="quota_leads" value="600"></label>
    <label class="field">AI spend per month ($)<input type="number" min="0" step="0.5" name="quota_ai_usd" value="8"></label>
    <label class="field">Sending inboxes<input type="number" min="0" name="quota_mailboxes" value="3"></label></div>
  <label class="field">Owner’s email <small>optional — creates an invite link for them</small><input type="email" name="owner_email"></label>
  <div class="actions"><button class="btn filled" data-press><?= icon('add') ?>Create workspace</button></div>
</form>
<datalist id="plans"><?php foreach (array_keys(PLAN_PRESETS) as $k) echo '<option value="' . $k . '">'; ?><option value="Internal"><option value="Custom"></datalist>
<?php page_footer();
