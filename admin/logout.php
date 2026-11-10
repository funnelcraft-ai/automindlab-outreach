<?php
// Admin console sign-out: same code as the client page, but in the 'admin' area
// (its own session cookie, platform admins only, stricter timeouts).
define('APP_AREA', 'admin');
require __DIR__ . '/../logout.php';
