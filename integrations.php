<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_login('admin');
$plat = is_platform_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_role('admin');
    $svc = (string) ($_POST['service'] ?? '');
    if (!in_array($svc, ['mode', 'calid'], true)) deny('Shared API keys are managed in the Admin console.');
    $op = (string) ($_POST['op'] ?? 'save');
    try {
        if ($svc === 'mode') {
            $mode = (string) ($_POST['mode'] ?? 'test');
            $testTo = trim((string) ($_POST['test_redirect'] ?? ''));
            if ($mode === 'test' && !filter_var($testTo, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter the email address that should receive test sends.');
            set_setting('sending_paused', $mode === 'paused' ? '1' : '0');
            set_setting('dry_run', $mode === 'dry' ? '1' : '0');
            set_setting('test_redirect', $mode === 'test' ? $testTo : ($mode === 'live' ? '' : (string) setting('test_redirect')));
            log_event(null, 'mode', 'Sending mode set to ' . $mode, $mode === 'live' ? 'warn' : 'info');
            flash(['live' => 'You’re live — approved emails now go to real prospects.', 'test' => "Test mode on — every email goes to $testTo.",
                'dry' => 'Dry run on — emails are logged, not sent.', 'paused' => 'Sending paused.'][$mode] ?? 'Mode saved.');
            redirect('integrations.php#mode');
        }
        handle_service_post($svc, $op);
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); }
    redirect('integrations.php#' . $svc);
}

[$mode] = send_mode();
$provider = (string) setting('ai_provider');
$mbx = all('SELECT * FROM mailboxes WHERE tenant_id = ? ORDER BY id', [tid()]);
$quota = quota_status();
$mbOk = count(array_filter($mbx, fn($m) => $m['active'] && !$m['last_error'] && $m['last_imap_check']));
$lastCron = setting('last_cron_at');
$checks = [
    ['Postal address added', trim((string) setting('postal_address')) !== '', 'settings.php', true],
    ['A sending inbox is connected', $mbOk > 0, 'mailboxes.php', true],
    ['Platform AI ready (' . ['anthropic' => 'Claude', 'openai' => 'OpenAI', 'gemini' => 'Gemini', 'mock' => 'offline mock'][$provider] . ')', $provider !== 'mock' && (api_status($provider)['ok'] ?? false), $plat ? admin_href('platform.php#ai') : '', true],
    ['Platform lead search ready (Google Places)', api_status('places')['ok'] ?? false, $plat ? admin_href('platform.php#places') : '', true],
    ['Pipeline running every 5 minutes', $lastCron && strtotime($lastCron . ' UTC') > time() - 1800, $plat ? admin_href('platform.php#cron') : '', true],
    ['A campaign is active', (bool) val("SELECT 1 FROM campaigns WHERE status='active' AND tenant_id = ? LIMIT 1", [tid()]), 'campaigns.php', true],
    ['cal.id webhook received', api_status('calid')['ok'] ?? false, '#calid', false],
];
$ready = !in_array(false, array_map(fn($c) => $c[1] || !$c[3], $checks), true);

page_header('Connections', 'apis', 'Connect bookings and inboxes, then switch this workspace to Live. Lead search and AI are provided by the platform.');
?>
<section class="panel mode-panel" id="mode">
  <div class="panel-head"><div><h2>Sending mode</h2><p class="muted">Controls what happens to approved emails.</p></div></div>
  <form method="post" class="mode-form"><?= csrf_field() ?><input type="hidden" name="service" value="mode">
    <div class="segmented" role="radiogroup" aria-label="Sending mode">
      <?php foreach (['live' => ['radio_button_checked', 'Live', 'Emails go to prospects'], 'test' => ['experiment', 'Test', 'Everything goes to you'],
          'dry' => ['science', 'Dry run', 'Log only, nothing sent'], 'paused' => ['pause_circle', 'Paused', 'Hold all sending']] as $k => [$ic, $lb, $desc]): ?>
        <label class="seg <?= $mode === $k ? 'on' : '' ?>"><input type="radio" name="mode" value="<?= $k ?>" <?= $mode === $k ? 'checked' : '' ?>>
          <?= icon($ic, $mode === $k ? 'fill' : '') ?><span><b><?= $lb ?></b><small><?= $desc ?></small></span></label>
      <?php endforeach; ?>
    </div>
    <div class="mode-extra" data-show-for="test"><label class="field">Send test emails to<input type="email" name="test_redirect" value="<?= e(setting('test_redirect') ?: setting('notify_email')) ?>"></label></div>
    <div class="actions"><button class="btn filled" data-press><?= icon('check') ?>Apply mode</button>
      <?php if (!$ready): ?><span class="muted small">Complete the required checks before going live.</span><?php endif; ?></div>
  </form>
  <ul class="checklist">
    <?php foreach ($checks as [$label, $ok, $href, $req]): ?>
      <li class="<?= $ok ? 'done' : ($req ? 'todo' : 'opt') ?>"><?= icon($ok ? 'check_circle' : 'radio_button_unchecked', $ok ? 'fill' : '') ?>
        <?php if ($href): ?><a href="<?= e($href) ?>"><?= e($label) ?></a><?php else: ?><span><?= e($label) ?></span><?php endif; ?><?php if (!$req): ?><span class="muted small">optional</span><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
</section>

<h2 class="group-title">Lead search and AI</h2>
<section class="panel">
  <p class="muted">Provided by the platform — no keys needed. This workspace’s monthly allowance:</p>
  <div class="usage"><div class="meter"><i style="--v:<?= $quota['leads_limit'] ? min(100, round(100 * $quota['leads_used'] / $quota['leads_limit'])) : 0 ?>%"></i></div><span class="small muted"><?= $quota['leads_used'] ?>/<?= $quota['leads_limit'] ?: '∞' ?> new leads</span></div>
  <div class="usage"><div class="meter"><i style="--v:<?= $quota['ai_limit'] > 0 ? min(100, round(100 * $quota['ai_used'] / $quota['ai_limit'])) : 0 ?>%"></i></div><span class="small muted">$<?= number_format($quota['ai_used'], 2) ?>/<?= $quota['ai_limit'] > 0 ? '$' . number_format($quota['ai_limit'], 2) : '∞' ?> AI research and writing</span></div>
  <p class="small muted"><?= $plat ? 'Keys, models and spend limits: <a href="' . admin_href('platform.php') . '">Admin console → Platform connections</a>. Allowances: <a href="' . admin_href('workspaces.php?edit=' . tid() . '#edit') . '">Workspaces</a>.' : 'Need more? Ask your agency to raise the allowance.' ?></p>
</section>

<h2 class="group-title">Meetings and email</h2>
<div class="api-grid">
  <form method="post" class="api-card" id="calid"><?= csrf_field() ?><input type="hidden" name="service" value="calid">
    <div class="api-head"><span class="api-logo l-cal"><?= icon('event_available', 'fill') ?></span><div><h3>cal.id bookings</h3><span class="small muted">Marks leads as booked and stops their emails</span></div><?= conn_chip('calid', true) ?></div>
    <label class="field">Booking link<input name="cal_link" value="<?= e(setting('cal_link')) ?>"></label>
    <label class="field">Webhook URL <small>paste into cal.id → Settings → Developer → Webhooks</small>
      <span class="copy"><input readonly value="<?= e(base_url('webhook-cal.php?w=' . tid() . '&key=' . setting('webhook_key'))) ?>"><button type="button" class="icon-btn" data-copy aria-label="Copy"><?= icon('content_copy') ?></button></span></label>
    <label class="field">Signing secret <small>optional, must match cal.id</small><input name="cal_webhook_secret" value="<?= e(setting('cal_webhook_secret')) ?>" spellcheck="false"></label>
    <label class="switch"><input type="checkbox" name="cal_prefill" <?= setting('cal_prefill') === '1' ? 'checked' : '' ?>><span class="track"><span class="thumb"></span></span>Pre-fill the prospect’s email on the booking page</label>
    <?= conn_last_msg('calid') ?>
    <div class="actions"><button name="op" value="save" class="btn filled" data-press>Save</button>
      <a class="btn text" href="<?= e(setting('cal_link')) ?>" target="_blank" rel="noopener">Open booking page<?= icon('open_in_new') ?></a></div>
  </form>

  <div class="api-card">
    <div class="api-head"><span class="api-logo l-mail"><?= icon('mail', 'fill') ?></span><div><h3>Sending inboxes</h3><span class="small muted">Zoho, Google or any SMTP + IMAP inbox</span></div>
      <?= $mbOk ? '<span class="chip-status ok">' . icon('check_circle', 'fill') . $mbOk . ' connected</span>' : '<span class="chip-status off">' . icon('radio_button_unchecked') . 'None yet</span>' ?></div>
    <ul class="mini-list">
      <?php foreach ($mbx as $m): ?><li><?= icon($m['active'] && !$m['last_error'] ? 'check_circle' : 'error', 'fill ' . ($m['active'] && !$m['last_error'] ? 'ok' : 'err')) ?>
        <span><?= e($m['email']) ?></span><span class="muted small"><?= mailbox_sent_today($m) ?>/<?= mailbox_limit_today($m) ?> today</span></li><?php endforeach; ?>
      <?php if (!$mbx): ?><li class="muted">Add 2–3 inboxes on a separate look-alike domain.</li><?php endif; ?>
    </ul>
    <div class="actions"><a class="btn tonal" href="mailboxes.php"><?= icon('settings') ?>Manage inboxes</a></div>
  </div>
</div>
<?php page_footer();
