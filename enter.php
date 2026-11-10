<?php
declare(strict_types=1);
/** Client app side of the Admin console hand-off: starts a client-app session for a platform admin in one workspace. */
require __DIR__ . '/lib/bootstrap.php';
start_session();
const ENTER_TARGETS = ['index.php', 'leads.php?status=drafted', 'replies.php', 'mailboxes.php', 'settings.php', 'campaigns.php', 'integrations.php', 'team.php', 'linkedin.php'];
$h = use_handoff((string) ($_GET['t'] ?? ''));
$u = $h ? row('SELECT * FROM users WHERE id = ? AND is_platform_admin = 1', [$h['user_id']]) : null;
if (!$h || !$u) {
    http_response_code(403);
    page_head('Link expired');
    echo '<body class="login"><main class="login-card"><h1>This link has expired</h1><p>Workspace links from the Admin console work once, for 60 seconds. Go back to the console and press Open again.</p>'
       . '<a class="btn filled wide" href="admin/">Back to the Admin console</a></main></body></html>';
    exit;
}
finish_login($u);
$_SESSION['via_admin'] = 1;
$_SESSION['tid'] = (int) $h['tenant_id'];
update('users', ['last_tenant_id' => (int) $h['tenant_id']], 'id = ?', [$u['id']]);
set_tenant((int) $h['tenant_id']);
log_event(null, 'security', "Platform admin {$u['email']} opened this workspace from the Admin console");
flash('Opened ' . (tenant()['name'] ?? 'workspace') . ' as a platform admin.');
redirect(in_array($h['next'], ENTER_TARGETS, true) ? $h['next'] : 'index.php');
