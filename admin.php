<?php
// Moved to the Admin console in v2.3. This stub only redirects old bookmarks.
header('Location: admin/' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
exit;
