<?php
declare(strict_types=1);
/** Admin console → open a client workspace in the client app, via a one-time hand-off link (sessions stay separate). */
define('APP_AREA', 'admin');
require __DIR__ . '/../lib/bootstrap.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
verify_csrf();
$tenant = (int) ($_POST['tenant'] ?? 0);
if (!row('SELECT id FROM tenants WHERE id = ?', [$tenant])) { flash('That workspace doesn’t exist.', 'err'); redirect('index.php'); }
$token = create_handoff((int) current_user()['id'], $tenant, (string) ($_POST['next'] ?? 'index.php'));
redirect('../enter.php?t=' . $token);
