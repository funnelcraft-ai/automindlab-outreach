<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
start_session();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
verify_csrf();
$backToConsole = app_area() === 'app' && !empty($_SESSION['via_admin']);   // an admin leaving a client workspace goes back to the console
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => $p['samesite'] ?: 'Lax']);
}
session_destroy();
redirect($backToConsole ? 'admin/' : 'login.php');
