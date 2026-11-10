<?php
declare(strict_types=1);
define('APP_AREA', 'admin');
require __DIR__ . '/../lib/bootstrap.php';
require_login();
require_platform_admin();
$me = $GLOBALS['CURRENT_USER'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = (string) ($_POST['action'] ?? '');
    try {
        if ($a === 'grant') {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $u = row('SELECT id, email, is_platform_admin FROM users WHERE email = ?', [$email]);
            if (!$u) throw new RuntimeException("There’s no account for $email yet. Invite them to your agency workspace from its Team page first, then come back here.");
            if ($u['is_platform_admin']) throw new RuntimeException("$email is already a platform admin.");
            q('UPDATE users SET is_platform_admin = 1 WHERE id = ?', [$u['id']]);
            $prev = tid(); set_tenant(1); log_event(null, 'team', "$email made a platform admin by {$me['email']}", 'warn'); set_tenant($prev);
            flash("$email is now a platform admin. Ask them to turn on two-step verification.");
        } elseif ($a === 'revoke') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id === (int) $me['id']) throw new RuntimeException('You can’t remove your own platform access. Ask another platform admin.');
            if ((int) val('SELECT COUNT(*) FROM users WHERE is_platform_admin = 1') <= 1) throw new RuntimeException('There must always be at least one platform admin.');
            $u = row('SELECT email FROM users WHERE id = ? AND is_platform_admin = 1', [$id]) ?? throw new RuntimeException('Not a platform admin.');
            q('UPDATE users SET is_platform_admin = 0, session_version = session_version + 1 WHERE id = ?', [$id]);   // signs them out everywhere
            $prev = tid(); set_tenant(1); log_event(null, 'team', "{$u['email']} is no longer a platform admin (by {$me['email']})", 'warn'); set_tenant($prev);
            flash("{$u['email']} is no longer a platform admin. They keep any workspace memberships they had.");
        }
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); }
    redirect('admins.php');
}

$lastLogin = "(SELECT MAX(created_at) FROM login_attempts la WHERE la.email = u.email AND la.success = 1)";
$admins = all("SELECT u.*, $lastLogin last_login FROM users u WHERE u.is_platform_admin = 1 ORDER BY u.email");
$q = trim((string) ($_GET['q'] ?? ''));
$per = 50; $page = max(1, (int) ($_GET['page'] ?? 1));
$w = $q !== '' ? 'WHERE u.email LIKE ? OR u.name LIKE ?' : '';
$p = $q !== '' ? ["%$q%", "%$q%"] : [];
$total = (int) val("SELECT COUNT(*) FROM users u $w", $p);
$people = all("SELECT u.id, u.email, u.name, u.totp_enabled, u.is_platform_admin, u.created_at, $lastLogin last_login,
               (SELECT GROUP_CONCAT(CONCAT(t.name, ' (', m.role, ')') ORDER BY t.name SEPARATOR ', ') FROM memberships m JOIN tenants t ON t.id = m.tenant_id WHERE m.user_id = u.id) spaces
               FROM users u $w ORDER BY u.is_platform_admin DESC, u.email LIMIT $per OFFSET " . (($page - 1) * $per), $p);

page_header('Platform admins', 'admins', 'People who can open every workspace, see all client data, and change the shared API keys. Keep this list short.');
?>
<table class="t">
  <tr><th>Admin</th><th>2-step</th><th>Last sign-in</th><th></th></tr>
  <?php foreach ($admins as $u): $mine = (int) $u['id'] === (int) $me['id']; ?>
  <tr><td><strong><?= e($u['name'] ?: $u['email']) ?></strong><?= $mine ? ' <span class="muted small">(you)</span>' : '' ?><br><span class="muted small"><?= e($u['email']) ?></span></td>
    <td><?= $u['totp_enabled'] ? '<span class="lvl ok">On</span>' : '<span class="lvl error">Off</span>' ?></td>
    <td class="small"><?= e($u['last_login'] ? ago($u['last_login']) : 'never') ?></td>
    <td class="nowrap"><?php if (!$mine): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
      <button name="action" value="revoke" class="btn small danger" data-confirm="Remove platform access for <?= e($u['email']) ?>? They’ll be signed out everywhere." data-press>Remove access</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
</table>

<form method="post" class="panel form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="grant">
  <div class="panel-head"><div><h2>Add a platform admin</h2><p class="muted">Only for people who run the whole platform with you (e.g. your developer during setup). Client teams never need this — invite them to their workspace instead.</p></div></div>
  <div class="two"><label class="field">Email of an existing account<input type="email" name="email" required></label>
    <div class="actions" style="align-self:end"><button class="btn tonal" data-confirm="This person will see every client’s data and the shared API keys. Continue?" data-press><?= icon('admin_panel_settings') ?>Make platform admin</button></div></div>
</form>

<h2 class="group-title" id="people">Everyone with an account <span class="tag"><?= number_format($total) ?></span></h2>
<form class="filters" method="get" action="admins.php#people"><input name="q" value="<?= e($q) ?>" placeholder="Search name or email…"><button class="btn">Search</button></form>
<table class="t">
  <tr><th>Person</th><th>Workspaces</th><th>2-step</th><th>Last sign-in</th></tr>
  <?php foreach ($people as $u): ?>
  <tr><td><strong><?= e($u['name'] ?: $u['email']) ?></strong><?= $u['is_platform_admin'] ? ' <span class="role-pill owner">Platform admin</span>' : '' ?><br><span class="muted small"><?= e($u['email']) ?></span></td>
    <td class="small break"><?= e($u['spaces'] ?: '—') ?></td>
    <td><?= $u['totp_enabled'] ? '<span class="lvl ok">On</span>' : '<span class="lvl notice">Off</span>' ?></td>
    <td class="small"><?= e($u['last_login'] ? ago($u['last_login']) : 'never') ?></td></tr>
  <?php endforeach; ?>
</table>
<?= pager($total, $page, $per, array_filter(['q' => $q ?: null])) ?>
<?php page_footer();
