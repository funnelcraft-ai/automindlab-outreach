<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_login('admin');

$me = $GLOBALS['CURRENT_USER'];
$myRank = ROLE_RANK[current_role() ?? 'viewer'];
/** Admins manage members and viewers; owners (and platform admins) manage everyone. */
$canManage = fn(string $role): bool => $myRank >= ROLE_RANK['owner'] || ROLE_RANK[$role] < ROLE_RANK['admin'];
$assignable = array_values(array_filter(array_keys(ROLE_RANK), $canManage));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_role('admin');
    $a = (string) ($_POST['action'] ?? '');
    try {
        if ($a === 'invite' || $a === 'relink') {
            if ($a === 'relink') {
                $inv = row('SELECT * FROM invitations WHERE id = ? AND tenant_id = ? AND accepted_at IS NULL', [(int) $_POST['id'], tid()]) ?? throw new RuntimeException('Invitation not found.');
                $email = $inv['email']; $role = $inv['role'];
            } else {
                $email = strtolower(trim((string) ($_POST['email'] ?? ''))); $role = (string) ($_POST['role'] ?? 'member');
            }
            if (!isset(ROLE_RANK[$role]) || !$canManage($role)) throw new RuntimeException('You can’t invite people as ' . (ROLE_LABELS[$role] ?? $role) . '.');
            if (val('SELECT 1 FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.tenant_id = ? AND u.email = ?', [tid(), $email])) throw new RuntimeException("$email is already on this team.");
            $_SESSION['new_invite'] = ['email' => $email, 'role' => $role, 'link' => create_invite(tid(), $email, $role, (int) $me['id'])];
            log_event(null, 'team', "Invited $email as " . ROLE_LABELS[$role]);
            flash($a === 'relink' ? 'New link created — the old one no longer works.' : "Invitation created for $email.");
        } elseif ($a === 'revoke') {
            $n = q('DELETE FROM invitations WHERE id = ? AND tenant_id = ? AND accepted_at IS NULL', [(int) $_POST['id'], tid()])->rowCount();
            flash($n ? 'Invitation cancelled.' : 'Invitation not found.');
        } elseif ($a === 'role' || $a === 'remove') {
            $uid = (int) ($_POST['user_id'] ?? 0);
            $m = row('SELECT m.*, u.email FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.tenant_id = ? AND m.user_id = ?', [tid(), $uid]) ?? throw new RuntimeException('That person isn’t on this team.');
            if (!$canManage($m['role'])) throw new RuntimeException('Only owners can change owners and admins.');
            $new = $a === 'role' ? (string) ($_POST['role'] ?? '') : null;
            if ($new !== null && (!isset(ROLE_RANK[$new]) || !$canManage($new))) throw new RuntimeException('You can’t give that role.');
            if ($m['role'] === 'owner' && $new !== 'owner' && owner_count(tid()) <= 1) throw new RuntimeException('Every workspace needs at least one owner. Make someone else an owner first.');
            if ($a === 'remove') {
                q('DELETE FROM memberships WHERE tenant_id = ? AND user_id = ?', [tid(), $uid]);
                log_event(null, 'team', "Removed {$m['email']}", 'warn');
                flash("{$m['email']} removed from the team.");
                if ($uid === (int) $me['id']) { unset($_SESSION['tid']); redirect('index.php'); }
            } else {
                q('UPDATE memberships SET role = ? WHERE tenant_id = ? AND user_id = ?', [$new, tid(), $uid]);
                log_event(null, 'team', "{$m['email']} is now " . ROLE_LABELS[$new]);
                flash("{$m['email']} is now " . ROLE_LABELS[$new] . '.');
            }
        }
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); }
    redirect('team.php');
}

$members = all('SELECT u.id, u.email, u.name, u.totp_enabled, u.is_platform_admin, m.role, m.created_at FROM memberships m JOIN users u ON u.id = m.user_id
                WHERE m.tenant_id = ? ORDER BY m.role, u.email', [tid()]);
$invites = all('SELECT * FROM invitations WHERE tenant_id = ? AND accepted_at IS NULL ORDER BY created_at DESC', [tid()]);
$platform = all('SELECT email FROM users WHERE is_platform_admin = 1 AND id NOT IN (SELECT user_id FROM memberships WHERE tenant_id = ?) ORDER BY email', [tid()]);
$new = $_SESSION['new_invite'] ?? null; unset($_SESSION['new_invite']);

page_header('Team', 'team', 'Who can use ' . (tenant()['name'] ?? 'this workspace') . ', and what they can do.');
?>
<?php if ($new): ?>
<section class="panel invite-ready">
  <div class="panel-head"><div><h2>Send this link to <?= e($new['email']) ?></h2>
    <p class="muted">They’ll join as <?= e(ROLE_LABELS[$new['role']]) ?>. The link works once and expires in <?= INVITE_DAYS ?> days. It’s only shown now — copy it before leaving this page.</p></div></div>
  <span class="copy"><input readonly value="<?= e($new['link']) ?>"><button type="button" class="icon-btn" data-copy aria-label="Copy link"><?= icon('content_copy') ?></button></span>
</section>
<?php endif; ?>

<table class="t">
  <tr><th>Person</th><th>Role</th><th>2-step</th><th>Joined</th><th></th></tr>
  <?php foreach ($members as $m): $mine = (int) $m['id'] === (int) $me['id']; $editable = $canManage($m['role']); ?>
  <tr><td><strong><?= e($m['name'] ?: $m['email']) ?></strong><?= $mine ? ' <span class="muted small">(you)</span>' : '' ?><br><span class="muted small"><?= e($m['email']) ?></span></td>
    <td><?php if ($editable): ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>">
        <select name="role" data-autosubmit aria-label="Role for <?= e($m['email']) ?>"><?php foreach ($assignable as $r): ?><option value="<?= $r ?>" <?= $r === $m['role'] ? 'selected' : '' ?>><?= ROLE_LABELS[$r] ?></option><?php endforeach; ?></select></form>
      <?php else: ?><span class="role-pill <?= e($m['role']) ?>"><?= e(ROLE_LABELS[$m['role']]) ?></span><?php endif; ?></td>
    <td><?= $m['totp_enabled'] ? '<span class="badge good">on</span>' : '<span class="badge muted">off</span>' ?></td>
    <td class="small"><?= e(substr((string) $m['created_at'], 0, 10)) ?></td>
    <td class="nowrap"><?php if ($editable): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>">
      <button name="action" value="remove" class="btn small danger" data-confirm="<?= $mine ? 'Leave this workspace? You’ll lose access to it.' : 'Remove ' . e($m['email']) . ' from this workspace?' ?>" data-press><?= $mine ? 'Leave' : 'Remove' ?></button></form><?php endif; ?></td></tr>
  <?php endforeach; if (!$members): ?><tr><td colspan="5" class="muted">No one has joined yet. Invite the first person below.</td></tr><?php endif; ?>
</table>
<?php if ($platform): ?><p class="small muted">Platform admins can also open this workspace: <?= e(implode(', ', array_column($platform, 'email'))) ?>.</p><?php endif; ?>

<?php if ($invites): ?>
<h2 class="group-title">Waiting to join</h2>
<table class="t">
  <tr><th>Email</th><th>Role</th><th>Expires</th><th></th></tr>
  <?php foreach ($invites as $i): $expired = strtotime($i['expires_at'] . ' UTC') < time(); ?>
  <tr><td><?= e($i['email']) ?></td><td><span class="role-pill <?= e($i['role']) ?>"><?= e(ROLE_LABELS[$i['role']]) ?></span></td>
    <td class="small"><?= $expired ? '<span class="badge bad">expired</span>' : e(substr($i['expires_at'], 0, 10)) ?></td>
    <td class="nowrap"><?php if ($canManage($i['role'])): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
      <button name="action" value="relink" class="btn small" data-press>New link</button>
      <button name="action" value="revoke" class="btn small danger" data-confirm="Cancel this invitation?" data-press>Cancel</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<form method="post" class="panel form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="invite">
  <div class="panel-head"><div><h2>Invite someone</h2><p class="muted">You’ll get a sign-up link to send them yourself — by email, chat or however you like.</p></div></div>
  <div class="two"><label class="field">Email<input type="email" name="email" required></label>
    <label class="field">Role<select name="role"><?php foreach ($assignable as $r): ?><option value="<?= $r ?>" <?= $r === 'member' ? 'selected' : '' ?>><?= ROLE_LABELS[$r] ?></option><?php endforeach; ?></select></label></div>
  <ul class="role-help small muted"><?php foreach ($assignable as $r): ?><li><b><?= ROLE_LABELS[$r] ?></b> — <?= e(ROLE_HELP[$r]) ?></li><?php endforeach; ?></ul>
  <div class="actions"><button class="btn filled" data-press><?= icon('person_add') ?>Create invite link</button></div>
</form>
<?php page_footer();
