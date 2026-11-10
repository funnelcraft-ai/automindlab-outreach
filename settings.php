<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_login('admin');

$plain = ['company_name', 'legal_name', 'postal_address', 'website', 'notify_email', 'sender_role', 'send_start', 'send_end', 'min_gap_min', 'max_gap_min', 'li_daily_cap'];
$checks = ['canada_strict', 'include_unsub_link'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_role('admin');
    $a = (string) ($_POST['action'] ?? 'save');
    try {
        if ($a === 'save') {
            if (isset($_POST['li_daily_cap'])) $_POST['li_daily_cap'] = (string) max(1, min(40, (int) $_POST['li_daily_cap']));
            foreach ($plain as $k) if (isset($_POST[$k])) set_setting($k, trim((string) $_POST[$k]));
            foreach ($checks as $k) set_setting($k, isset($_POST[$k]) ? '1' : '0');
            set_setting('send_days', implode(',', array_map('intval', (array) ($_POST['send_days'] ?? []))));
            flash('Settings saved.');
        }
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); }
    redirect('settings.php');
}

$s = fn($k) => e((string) setting($k));
$days = array_map('intval', explode(',', (string) setting('send_days')));
page_header('Settings', 'settings', 'Business details, compliance rules and sending schedule for ' . (tenant()['name'] ?? 'this workspace') . '.');
?>
<a class="panel link-panel" href="integrations.php"><span class="api-logo l-gemini"><?= icon('hub', 'fill') ?></span>
  <span><b>Sending mode and bookings are in Connections</b><small>Switch between Test and Live, and connect cal.id.</small></span><?= icon('chevron_right') ?></a>

<form method="post" class="form"><?= csrf_field() ?>
<section class="panel"><div class="panel-head"><div><h2>Business and compliance</h2><p class="muted">Shown in every email’s signature and footer.</p></div></div>
  <div class="cols3">
    <label class="field">Brand name<input name="company_name" value="<?= $s('company_name') ?>"></label>
    <label class="field">Legal entity<input name="legal_name" value="<?= $s('legal_name') ?>"></label>
    <label class="field">Your role<input name="sender_role" value="<?= $s('sender_role') ?>"></label>
  </div>
  <label class="field">Postal address <small>required by US and Canadian law in every cold email</small><textarea name="postal_address" rows="2"><?= $s('postal_address') ?></textarea></label>
  <div class="cols2">
    <label class="field">Website<input name="website" value="<?= $s('website') ?>"></label>
    <label class="field">Email me about hot replies at<input name="notify_email" value="<?= $s('notify_email') ?>"></label>
  </div>
  <label class="switch"><input type="checkbox" name="include_unsub_link" <?= setting('include_unsub_link') === '1' ? 'checked' : '' ?>><span class="track"><span class="thumb"></span></span>Add an opt-out link to every email (required for Canada)</label>
  <label class="switch"><input type="checkbox" name="canada_strict" <?= setting('canada_strict') === '1' ? 'checked' : '' ?>><span class="track"><span class="thumb"></span></span>Canada mode: only email addresses the business publishes on its own website, keep the source as proof</label>
</section>

<section class="panel"><div class="panel-head"><div><h2>Sending schedule</h2><p class="muted">Times are in each lead’s own time zone.</p></div></div>
  <div class="cols4">
    <label class="field">Start<input type="time" name="send_start" value="<?= $s('send_start') ?>"></label>
    <label class="field">End<input type="time" name="send_end" value="<?= $s('send_end') ?>"></label>
    <label class="field">Shortest gap (min)<input type="number" name="min_gap_min" value="<?= $s('min_gap_min') ?>"></label>
    <label class="field">Longest gap (min)<input type="number" name="max_gap_min" value="<?= $s('max_gap_min') ?>"></label>
  </div>
  <div class="chip-set" role="group" aria-label="Sending days">
    <?php foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $n => $d): ?>
      <label class="filter-chip"><input type="checkbox" name="send_days[]" value="<?= $n ?>" <?= in_array($n, $days, true) ? 'checked' : '' ?>><?= icon('check') ?><?= $d ?></label>
    <?php endforeach; ?>
  </div>
</section>

<section class="panel"><div class="panel-head"><div><h2>LinkedIn</h2><p class="muted">For campaigns with LinkedIn steps switched on. Your team sends these by hand.</p></div></div>
  <div class="cols3"><label class="field">Connection requests per person per day <small>keep it at 20 or less (max 40)</small><input type="number" min="1" max="40" name="li_daily_cap" value="<?= $s('li_daily_cap') ?: 20 ?>"></label></div>
</section>

<div class="actions sticky"><button name="action" value="save" class="btn filled" data-press><?= icon('save') ?>Save settings</button>
</div>
</form>
<?php page_footer();
