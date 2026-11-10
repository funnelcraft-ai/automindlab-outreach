<?php
declare(strict_types=1);

/** Current sending mode shown in the top bar. */
function send_mode(): array {
    if (setting('sending_paused') === '1') return ['paused', 'Sending paused', 'pause_circle'];
    if (setting('dry_run') === '1') return ['dry', 'Dry run', 'science'];
    if (trim((string) setting('test_redirect')) !== '') return ['test', 'Test mode', 'experiment'];
    return ['live', 'Live', 'radio_button_checked'];
}

function icon(string $name, string $extra = ''): string {
    return '<span class="ms ' . e($extra) . '" aria-hidden="true">' . e($name) . '</span>';
}

function page_head(string $title, ?string $brand = null): void { ?><!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#f1effb">
<title><?= e($title) ?> · <?= e($brand ?? setting('company_name')) ?> Outreach</title>
<link rel="icon" type="image/png" href="<?= rel('assets/favicon.png') ?>">
<link rel="preload" href="<?= rel('assets/google-sans-flex.woff2') ?>" as="font" type="font/woff2" crossorigin>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400..500,0..1,0&display=block">
<link rel="stylesheet" href="<?= rel('assets/app.css') ?>?v=<?= APP_VERSION ?>">
</head><?php }

/** Pages that belong to the Admin console (platform admins only); every other page is a workspace page. */
const ADMIN_PAGES = ['admin', 'workspaces', 'platform', 'health', 'admins'];   // kept for reference; the area is set by APP_AREA

function page_header(string $title, string $active = '', string $subtitle = ''): void {
    $console = app_area() === 'admin';
    $user = current_user();
    $initial = strtoupper(substr((string) (($user['name'] ?? '') ?: ($user['email'] ?? '?')), 0, 1));
    $role = current_role() ?? 'viewer';
    $t = tenant();
    $flags = [];
    if ($console) {
        $problems = 0;
        try { $problems = (int) val("SELECT COUNT(*) FROM error_groups WHERE status = 'open' AND level IN ('error','critical')"); } catch (Throwable $e) {}
        $nav = [
            ['admin', 'Overview', 'admin.php', 'space_dashboard', 0],
            ['workspaces', 'Workspaces', 'workspaces.php', 'domain', 0],
            ['platform', 'Platform connections', 'platform.php', 'hub', 0],
            ['health', 'System health', 'health.php', 'monitor_heart', $problems],
            ['admins', 'Platform admins', 'admins.php', 'admin_panel_settings', 0],
        ];
        $last = setting('last_cron_at');
        if (!$last || strtotime($last . ' UTC') < time() - 1800) $flags[] = ['bad', 'schedule', 'The pipeline hasn’t run in 30 minutes. Check the cron job in hPanel.', 'platform.php#cron'];
        try { if ($crit = (int) val("SELECT COUNT(*) FROM error_groups WHERE status = 'open' AND level = 'critical'")) $flags[] = ['bad', 'emergency_home', $crit . ' critical problem' . ($crit > 1 ? 's need' : ' needs') . ' attention.', 'health.php']; } catch (Throwable $e) {}
        $foot = 'Cron ' . (($l = setting('last_cron_at')) ? 'ran ' . ago($l) : 'has never run');
    } else {
        $review = (int) val("SELECT COUNT(*) FROM leads WHERE tenant_id = ? AND status = 'drafted'", [tid()]);
        $unhandled = (int) val("SELECT COUNT(*) FROM replies WHERE tenant_id = ? AND kind = 'reply' AND handled = 0", [tid()]);
        $nav = [
            ['index', 'Dashboard', 'index.php', 'space_dashboard', 0, 'viewer'],
            ['review', 'Review queue', 'leads.php?status=drafted', 'rate_review', $review, 'viewer'],
            ['leads', 'Leads', 'leads.php', 'storefront', 0, 'viewer'],
            ['replies', 'Replies', 'replies.php', 'forum', $unhandled, 'viewer'],
            ...(li_enabled_anywhere() ? [['linkedin', 'LinkedIn tasks', 'linkedin.php', 'handshake', li_due_count(), 'viewer']] : []),
            ['campaigns', 'Campaigns', 'campaigns.php', 'campaign', 0, 'viewer'],
            ['mailboxes', 'Mailboxes', 'mailboxes.php', 'mail', 0, 'admin'],
            ['apis', 'Connections', 'integrations.php', 'hub', 0, 'admin'],
            ['settings', 'Settings', 'settings.php', 'tune', 0, 'admin'],
            ['team', 'Team', 'team.php', 'group', 0, 'admin'],
            ['activity', 'Activity', 'activity.php', 'history', 0, 'viewer'],
        ];
        $nav = array_values(array_filter($nav, fn($n) => ROLE_RANK[$role] >= ROLE_RANK[$n[5]]));
        if ($t && $t['status'] === 'suspended') $flags[] = ['bad', 'block', 'This workspace is suspended: nothing is sent or searched, and changes are disabled.', is_platform_admin() ? admin_href('workspaces.php?edit=' . tid() . '#edit') : 'index.php'];
        if (is_platform_admin() && !empty($_SESSION['via_admin'])) $flags[] = ['info', 'admin_panel_settings', 'You’re in ' . ($t['name'] ?? 'this workspace') . ' as a platform admin. Anything you change here changes it for this client.', admin_href()];
        if (can('admin') && trim((string) setting('postal_address')) === '') $flags[] = ['bad', 'home_pin', 'Add this workspace’s postal address — every cold email must include it.', 'settings.php'];
        $foot = 'Last run ' . (($lr = setting('last_run_at')) ? local_time($lr) : 'never');
        [$modeKey, $modeLabel, $modeIcon] = send_mode();
    }
    $workspaces = $user ? user_workspaces($user) : [];
    $brand = $console ? (string) (val("SELECT v FROM tenant_settings WHERE tenant_id = 1 AND k = 'company_name'") ?: 'Agency') . ' admin' : null;
    page_head($title, $brand);
    ?>
<body class="shell<?= $console ? ' console' : '' ?>" data-active="<?= e($active) ?>">
<a class="skip" href="#main">Skip to content</a>
<header class="topbar">
  <button class="icon-btn menu-btn" type="button" aria-label="Open menu" data-drawer-toggle><?= icon('menu') ?></button>
  <a class="logo" href="index.php" aria-label="<?= $console ? 'Admin console overview' : 'Dashboard' ?>"><img src="<?= rel('assets/logo.png') ?>" alt="<?= e($brand ?? setting('company_name')) ?>" width="168" height="30"></a>
  <?php if ($console): ?>
  <span class="console-badge"><?= icon('admin_panel_settings', 'fill') ?><span>Admin console</span></span>
  <?php else: ?>
  <form class="search" action="leads.php" role="search">
    <?= icon('search') ?><input name="q" placeholder="Search leads, emails, cities" aria-label="Search leads" value="<?= e($_GET['q'] ?? '') ?>">
  </form>
  <?php endif; ?>
  <?php if ($console || count($workspaces) > 1): ?>
  <details class="ws-switch"><summary title="<?= $console ? 'Open a workspace' : 'Switch workspace' ?>"><?= icon('domain') ?><span><?= $console ? 'Open a workspace' : e($t['name'] ?? '') ?></span><?= icon('unfold_more') ?></summary>
    <form method="post" action="<?= $console ? 'open.php' : 'switch.php' ?>" class="menu-pop ws-menu"><?= csrf_field() ?><div class="muted small"><?= $console ? 'Open a client workspace' : 'Switch workspace' ?></div>
      <?php foreach ($workspaces as $w): $here = !$console && (int) $w['id'] === tid(); ?><button class="menu-item <?= $here ? 'on' : '' ?>" name="tenant" value="<?= (int) $w['id'] ?>">
        <span class="ws-dot"><?= e(strtoupper(substr($w['name'], 0, 1))) ?></span><span><b><?= e($w['name']) ?></b><small><?= is_platform_admin() ? e($w['plan'] ?? '') : e(ROLE_LABELS[$w['role']] ?? '') ?><?= $w['status'] === 'suspended' ? ' · suspended' : '' ?></small></span>
        <?= $here ? icon('check') : '' ?></button><?php endforeach; ?>
      <?php if ($console): ?><a href="workspaces.php#new"><?= icon('add') ?>New workspace</a><?php endif; ?>
    </form></details>
  <?php elseif ($t): ?><span class="ws-name"><?= icon('domain') ?><?= e($t['name']) ?></span><?php endif; ?>
  <?php if (!$console): ?>
    <?php if (is_platform_admin()): ?><a class="console-link" href="<?= admin_href() ?>" title="Back to the Admin console"><?= icon('admin_panel_settings') ?><span>Admin console</span></a><?php endif; ?>
    <a class="mode mode-<?= e($modeKey) ?>" href="<?= can('admin') ? 'integrations.php#mode' : 'index.php' ?>" title="Sending mode"><?= icon($modeIcon, 'fill') ?><span><?= e($modeLabel) ?></span></a>
  <?php endif; ?>
  <details class="account"><summary aria-label="Account"><span class="avatar"><?= e($initial) ?></span></summary>
    <div class="menu-pop"><div class="muted small"><?= e($user['email'] ?? '') ?><br><?= is_platform_admin() ? 'Platform admin' : e(ROLE_LABELS[$role] ?? '') ?></div>
      <?php if (!$console && is_platform_admin()): ?><a href="<?= admin_href() ?>"><?= icon('admin_panel_settings') ?>Admin console</a><?php endif; ?>
      <a href="account.php"><?= icon('shield_person') ?>Account and security</a>
      <form method="post" action="logout.php"><?= csrf_field() ?><button class="menu-item"><?= icon('logout') ?>Sign out</button></form></div></details>
</header>
<nav class="drawer" aria-label="<?= $console ? 'Admin console' : 'Main' ?>">
  <?php if ($console): ?><div class="drawer-label">Admin console</div><?php elseif ($t && count($workspaces) > 1): ?><div class="drawer-label"><?= e($t['name']) ?></div><?php endif; ?>
  <div class="nav-list">
    <span class="nav-ind" aria-hidden="true"></span>
    <?php foreach ($nav as $n): [$k, $label, $href, $ic, $count] = $n; ?>
      <a href="<?= e($href) ?>" data-nav="<?= e($k) ?>" class="<?= $k === $active ? 'on' : '' ?>" <?= $k === $active ? 'aria-current="page"' : '' ?>>
        <?= icon($ic) ?><span class="lbl"><?= e($label) ?></span><?php if ($count): ?><b class="count"><?= $count ?></b><?php endif; ?></a>
    <?php endforeach; ?>
  </div>
  <div class="drawer-foot"><?= icon('autorenew') ?><?= e($foot) ?></div>
</nav>
<div class="scrim" data-drawer-toggle></div>
<main id="main" class="sheet">
  <?php foreach ($flags as [$ft, $ic, $m, $href]): ?><a class="banner <?= $ft ?>" href="<?= e($href) ?>"><?= icon($ic) ?><span><?= e($m) ?></span><?= icon('chevron_right') ?></a><?php endforeach; ?>
  <?php foreach (flash() ?? [] as [$ft, $m]): ?><div class="toast <?= e($ft) ?>" role="status"><?= icon($ft === 'err' ? 'error' : 'check_circle', 'fill') ?><span><?= e($m) ?></span><button type="button" class="icon-btn" aria-label="Dismiss" data-dismiss><?= icon('close') ?></button></div><?php endforeach; ?>
  <div class="page-title"><h1><?= e($title) ?></h1><?php if ($subtitle !== ''): ?><p><?= e($subtitle) ?></p><?php endif; ?></div>
<?php }

function page_footer(): void { ?>
</main><script src="<?= rel('assets/app.js') ?>?v=<?= APP_VERSION ?>"></script></body></html>
<?php }

function status_badge(string $s): string {
    $tone = match ($s) {
        'booked', 'interested' => 'good', 'replied', 'contacted', 'queued' => 'info',
        'drafted' => 'warn', 'bounced', 'error', 'unsubscribed' => 'bad', default => 'muted',
    };
    return '<span class="badge ' . $tone . '">' . e(STATUS_LABELS[$s] ?? $s) . '</span>';
}

function score_pill($score): string {
    if ($score === null) return '<span class="muted">—</span>';
    $s = (int) $score;
    $tone = $s >= 75 ? 'good' : ($s >= 55 ? 'warn' : 'bad');
    return '<span class="score ' . $tone . '" title="AI fit score">' . $s . '</span>';
}

function pager(int $total, int $page, int $per, array $params): string {
    $pages = (int) ceil($total / $per);
    if ($pages <= 1) return '';
    $out = '<nav class="pager" aria-label="Pages">';
    for ($i = max(1, $page - 4); $i <= min($pages, $page + 4); $i++) {
        $out .= $i === $page ? "<span aria-current=\"page\">$i</span>" : '<a href="?' . e(http_build_query(['p' => $i] + $params)) . "\">$i</a>";
    }
    return $out . "<em>$total total</em></nav>";
}

/** API connection status stored after each test. */
/** Connection test results. cal.id is per workspace; AI, Places and Hunter belong to the platform. */
function api_status_key(string $service): string { return $service === 'calid' ? 'ws_api_status' : 'api_status'; }
function api_status(string $service): ?array {
    $all = json_decode((string) setting(api_status_key($service), '{}'), true) ?: [];
    return $all[$service] ?? null;
}
function set_api_status(string $service, bool $ok, string $msg): void {
    $k = api_status_key($service);
    $all = json_decode((string) setting($k, '{}'), true) ?: [];
    $all[$service] = ['ok' => $ok, 'msg' => mb_substr($msg, 0, 240), 'at' => now()];
    set_setting($k, json_encode($all));
}
function ago(?string $utc): string {
    if (!$utc) return 'never';
    $s = time() - strtotime($utc . ' UTC');
    if ($s < 90) return 'just now';
    if ($s < 3600) return round($s / 60) . ' min ago';
    if ($s < 86400) return round($s / 3600) . ' h ago';
    return round($s / 86400) . ' d ago';
}
