<?php
// Moved to the Admin console in v2.3. This stub only redirects old bookmarks.
header('Location: admin/platform.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
exit;
