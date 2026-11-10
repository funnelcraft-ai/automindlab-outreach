<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_login();
verify_csrf();
// Where to land after switching: only these workspace pages (never a full URL), default the workspace dashboard.
const SWITCH_TARGETS = ['index.php', 'leads.php?status=drafted', 'replies.php', 'mailboxes.php', 'settings.php', 'campaigns.php', 'integrations.php', 'team.php', 'linkedin.php'];
$next = in_array($_POST['next'] ?? '', SWITCH_TARGETS, true) ? $_POST['next'] : 'index.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !switch_workspace((int) ($_POST['tenant'] ?? 0))) {
    flash('You don’t have access to that workspace.', 'err');
    redirect('index.php');
}
flash('Opened ' . (tenant()['name'] ?? 'workspace') . '.');
redirect($next);
