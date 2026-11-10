<?php
declare(strict_types=1);
define('APP_AREA', 'admin');
require __DIR__ . '/../lib/bootstrap.php';
require_login();
require_platform_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $svc = (string) ($_POST['service'] ?? '');
    try {
        if ($svc === 'ai_provider') {
            $p = (string) ($_POST['ai_provider'] ?? 'anthropic');
            if (in_array($p, ['anthropic', 'openai', 'gemini', 'mock'], true)) set_setting('ai_provider', $p);
            if (isset($_POST['ai_monthly_budget'])) set_setting('ai_monthly_budget', (string) max(0, (float) $_POST['ai_monthly_budget']));
            flash('AI settings saved.');
            redirect('platform.php#ai');
        }
        if ($svc === 'new_cron_key') {
            set_setting('cron_key', bin2hex(random_bytes(16)));
            flash('New cron key created. Update the cron URL if you use the web fallback.');
            redirect('platform.php#cron');
        }
        if ((connection_services()[$svc]['scope'] ?? '') !== 'platform') throw new RuntimeException('Unknown service');
        handle_service_post($svc, (string) ($_POST['op'] ?? 'save'));
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); }
    redirect('platform.php#' . $svc);
}

$provider = (string) setting('ai_provider');
$placesUsed = (int) month_usage('places'); $placesCap = max(1, (int) setting('places_monthly_cap'));
$detailsUsed = (int) month_usage('places_details'); $detailsCap = max(1, (int) setting('places_details_cap'));
$aiSpend = month_usage('ai:', 'cost_usd'); $aiBudget = (float) setting('ai_monthly_budget');
$cronCmd = '/usr/bin/php ' . APP_ROOT . '/cron.php';

page_header('Platform connections', 'platform', 'Shared by every workspace: AI, Google Places and Hunter keys, spend limits, and the cron job. Keys are stored encrypted.');
?>
<h2 class="group-title" id="ai">AI for research and writing</h2>
<form method="post" class="provider-bar"><?= csrf_field() ?><input type="hidden" name="service" value="ai_provider">
  <span>Use</span>
  <div class="segmented compact">
    <?php foreach (['anthropic' => 'Claude', 'openai' => 'OpenAI', 'gemini' => 'Gemini', 'mock' => 'Offline mock'] as $k => $lb): ?>
      <label class="seg <?= $provider === $k ? 'on' : '' ?>"><input type="radio" name="ai_provider" value="<?= $k ?>" <?= $provider === $k ? 'checked' : '' ?> data-autosubmit><span><b><?= $lb ?></b></span></label>
    <?php endforeach; ?>
  </div>
  <label class="inline-field">Monthly budget $<input type="number" step="0.5" min="0" name="ai_monthly_budget" value="<?= e(setting('ai_monthly_budget')) ?>"></label>
  <div class="meter" title="AI spend this month"><i style="--v:<?= min(100, round(100 * $aiSpend / max(0.01, $aiBudget))) ?>%"></i></div>
  <span class="muted small">$<?= number_format($aiSpend, 2) ?> used</span>
  <button class="btn tonal small" data-press>Save</button>
</form>

<div class="api-grid">
<?php foreach ([
    'anthropic' => ['Claude', 'anthropic_key', 'anthropic_model', 'price_anthropic', 'https://console.anthropic.com/settings/keys', 'sk-ant-…'],
    'openai' => ['OpenAI', 'openai_key', 'openai_model', 'price_openai', 'https://platform.openai.com/api-keys', 'sk-…'],
    'gemini' => ['Gemini', 'gemini_key', 'gemini_model', 'price_gemini', 'https://aistudio.google.com/app/apikey', 'AIza…'],
] as $svc => [$name, $keyK, $modelK, $priceK, $url, $ph]): $has = (string) setting($keyK) !== ''; ?>
  <form method="post" class="api-card <?= $provider === $svc ? 'active' : '' ?>" id="<?= $svc ?>"><?= csrf_field() ?><input type="hidden" name="service" value="<?= $svc ?>">
    <div class="api-head"><span class="api-logo l-<?= $svc ?>"><?= icon(['anthropic' => 'auto_awesome', 'openai' => 'psychology', 'gemini' => 'diamond'][$svc], 'fill') ?></span>
      <div><h3><?= $name ?></h3><?= $provider === $svc ? '<span class="small in-use">Drafting with this one</span>' : '<span class="small muted">Standby</span>' ?></div>
      <?= conn_chip($svc, $has) ?></div>
    <?= conn_secret_field($keyK, 'API key', $ph, $url) ?>
    <div class="two"><label class="field">Model<input name="<?= $modelK ?>" value="<?= e(setting($modelK)) ?>" spellcheck="false"></label>
      <label class="field">$ per 1M tokens in/out<input name="<?= $priceK ?>" value="<?= e(setting($priceK)) ?>"></label></div>
    <?= conn_last_msg($svc) ?>
    <div class="actions"><button name="op" value="test" class="btn filled" data-press><?= icon('bolt') ?>Save and test</button>
      <button name="op" value="save" class="btn text" data-press>Save</button>
      <?php if ($has): ?><button name="op" value="remove" class="btn text danger" data-confirm="Remove this key?" data-press>Remove</button><?php endif; ?></div>
  </form>
<?php endforeach; ?>
</div>

<h2 class="group-title">Lead data</h2>
<div class="api-grid">
  <?php $has = (string) setting('places_key') !== ''; ?>
  <form method="post" class="api-card" id="places"><?= csrf_field() ?><input type="hidden" name="service" value="places">
    <div class="api-head"><span class="api-logo l-places"><?= icon('location_on', 'fill') ?></span><div><h3>Google Places</h3><span class="small muted">Finds local businesses on Google Maps</span></div><?= conn_chip('places', $has) ?></div>
    <?= conn_secret_field('places_key', 'API key', 'AIza… with Places API (New) enabled', 'https://console.cloud.google.com/google/maps-apis/credentials') ?>
    <div class="two"><label class="field">Searches per month<input type="number" name="places_monthly_cap" value="<?= e(setting('places_monthly_cap')) ?>"></label>
      <label class="field">Lookups per month<input type="number" name="places_details_cap" value="<?= e(setting('places_details_cap')) ?>"></label></div>
    <div class="usage"><div class="meter"><i style="--v:<?= min(100, round(100 * $placesUsed / $placesCap)) ?>%"></i></div><span class="small muted"><?= $placesUsed ?>/<?= $placesCap ?> searches</span></div>
    <div class="usage"><div class="meter"><i style="--v:<?= min(100, round(100 * $detailsUsed / $detailsCap)) ?>%"></i></div><span class="small muted"><?= $detailsUsed ?>/<?= $detailsCap ?> detail lookups</span></div>
    <p class="small muted">Each search returns up to 20 businesses; each lead then needs one detail lookup to get its website. Google includes 1,000 free calls a month for each. Only Google’s place ID is stored, as its terms require — names, phones and addresses come from each business’s own website.</p>
    <?= conn_last_msg('places') ?>
    <div class="actions"><button name="op" value="test" class="btn filled" data-press><?= icon('bolt') ?>Save and test</button>
      <button name="op" value="save" class="btn text" data-press>Save</button>
      <?php if ($has): ?><button name="op" value="remove" class="btn text danger" data-confirm="Remove this key?" data-press>Remove</button><?php endif; ?></div>
  </form>

  <?php $has = (string) setting('hunter_key') !== ''; ?>
  <form method="post" class="api-card" id="hunter"><?= csrf_field() ?><input type="hidden" name="service" value="hunter">
    <div class="api-head"><span class="api-logo l-hunter"><?= icon('alternate_email', 'fill') ?></span><div><h3>Hunter.io</h3><span class="small muted">Optional backup email finder for US leads</span></div><?= conn_chip('hunter', $has) ?></div>
    <?= conn_secret_field('hunter_key', 'API key', 'Free plan works', 'https://hunter.io/api-keys') ?>
    <p class="small muted">Only used when a business doesn’t publish an email on its site. Never used for Canadian leads while Canada mode is on.</p>
    <?= conn_last_msg('hunter') ?>
    <div class="actions"><button name="op" value="test" class="btn filled" data-press><?= icon('bolt') ?>Save and test</button>
      <?php if ($has): ?><button name="op" value="remove" class="btn text danger" data-confirm="Remove this key?" data-press>Remove</button><?php endif; ?></div>
  </form>
</div>


<h2 class="group-title" id="cron">Background jobs (cron)</h2>
<section class="panel form">
  <p class="muted">Add this as a cron job in hPanel → Advanced → Cron Jobs, every 5 minutes. One job serves every workspace, taking turns so each gets a fair share of each run.</p>
  <label class="field">Cron command<span class="copy"><input readonly value="<?= e($cronCmd) ?>"><button type="button" class="icon-btn" data-copy aria-label="Copy"><?= icon('content_copy') ?></button></span></label>
  <label class="field">Cron URL <small>only if command-line cron isn’t available</small><span class="copy"><input readonly value="<?= e(base_url('cron.php?key=' . setting('cron_key'))) ?>"><button type="button" class="icon-btn" data-copy aria-label="Copy"><?= icon('content_copy') ?></button></span></label>
  <p class="small muted">Last run <?= e(($l = setting('last_cron_at')) ? ago($l) : 'never') ?> · time budget <?= (int) cfg('cron_budget', 50) ?> s per run (config file). Run history is on <a href="health.php#cron">System health</a>.</p>
  <form method="post" class="actions"><?= csrf_field() ?><button name="service" value="new_cron_key" class="btn text" data-confirm="Create a new cron key? The old cron URL will stop working." data-press>Create new cron key</button></form>
</section>
<?php page_footer();
