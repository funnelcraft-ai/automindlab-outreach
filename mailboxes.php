<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_login('admin');

$providers = mail_providers();

function test_mailbox(int $id): string {
    $mb = owned_row('mailboxes', $id);
    if (!$mb) throw new RuntimeException('Mailbox not found.');
    $msg = [test_smtp($mb)];
    $imap = new MiniImap($mb['imap_host'], (int) $mb['imap_port'], (int) $mb['imap_port'] === 993);
    $imap->login($mb['username'], mailbox_password($mb)); $imap->select('INBOX'); $imap->logout();
    $msg[] = 'IMAP login OK';
    $to = trim((string) setting('notify_email'));
    if ($to && setting('dry_run') !== '1') {
        send_mail($mb, $to, '', 'Outreach test from ' . $mb['email'], "This is a test from your outreach dashboard.\n\nIf this landed in Spam, check the SPF, DKIM and DMARC records for " . substr(strrchr($mb['email'], '@'), 1) . ".\n");
        $msg[] = "test email sent to $to";
    }
    update('mailboxes', ['last_error' => null, 'last_imap_check' => now()], 'id = ? AND tenant_id = ?', [$id, tid()]);
    return implode(' · ', $msg);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_role('admin');
    $id = (int) ($_POST['id'] ?? 0);
    if ($id && !owned_row('mailboxes', $id)) deny();
    $a = (string) ($_POST['action'] ?? 'save');
    $provider = (string) ($_POST['provider'] ?? 'custom');
    if (!isset($providers[$provider])) $provider = 'custom';
    try {
        if ($a === 'save' || $a === 'save_test') {
            $d = ['provider' => $provider];
            foreach (['label', 'from_name', 'email', 'smtp_host', 'smtp_secure', 'imap_host', 'username', 'signature', 'warmup_started'] as $f) $d[$f] = trim((string) ($_POST[$f] ?? ''));
            foreach (['smtp_port', 'imap_port', 'daily_limit', 'ramp_start', 'ramp_step'] as $f) $d[$f] = (int) ($_POST[$f] ?? 0);
            $p = $providers[$provider];
            if ($provider !== 'custom') {   // presets always win, so a stale field can't break the login
                $d['smtp_host'] = $p['smtp_host']; $d['smtp_port'] = $p['smtp_port']; $d['smtp_secure'] = $p['smtp_secure'];
                $d['imap_host'] = $p['imap_host']; $d['imap_port'] = $p['imap_port'];
            }
            if ($d['smtp_host'] === '' || $d['imap_host'] === '') throw new DomainException('Enter the SMTP and IMAP server names from your email provider.');
            $d['email'] = strtolower($d['email']);
            $d['username'] = $d['username'] ?: $d['email'];
            $d['active'] = isset($_POST['active']) ? 1 : 0;
            $d['warmup_started'] = $d['warmup_started'] ?: null;
            $d['daily_limit'] = max(1, min(60, $d['daily_limit']));
            if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) throw new DomainException('Enter a valid email address.');
            $pw = (string) ($_POST['password'] ?? '');
            if ($pw !== '') $d['password_enc'] = encrypt_str(preg_replace('/\s+/', '', $pw)); // app passwords are often shown with spaces
            if (val('SELECT 1 FROM mailboxes WHERE email = ? AND id <> ?', [$d['email'], $id])) throw new DomainException('That inbox is already connected' . (val('SELECT 1 FROM mailboxes WHERE email = ? AND tenant_id = ?', [$d['email'], tid()]) ? ' in this workspace.' : ' to another workspace.'));
            if ($id) update('mailboxes', $d, 'id = ? AND tenant_id = ?', [$id, tid()]);
            else {
                if ($pw === '') throw new DomainException('Paste the inbox’s app password.');
                $lim = (int) (tenant()['quota_mailboxes'] ?? 0);
                if ($lim > 0 && (int) val('SELECT COUNT(*) FROM mailboxes WHERE tenant_id = ?', [tid()]) >= $lim) throw new DomainException("This workspace can connect up to $lim " . ($lim === 1 ? 'inbox' : 'inboxes') . '. Ask your agency to raise the limit.');
                $id = insert('mailboxes', $d);
            }
            flash($a === 'save_test' ? 'Mailbox saved. ' . test_mailbox($id) : 'Mailbox saved.');
        } elseif ($a === 'test') {
            flash(test_mailbox($id));
        } elseif ($a === 'delete') {
            if ((int) val("SELECT COUNT(*) FROM leads WHERE tenant_id = ? AND mailbox_id = ? AND status IN ('queued','contacted')", [tid(), $id])) throw new DomainException('This inbox has active sequences — switch it off instead of deleting it.');
            q('DELETE FROM mailboxes WHERE id = ? AND tenant_id = ?', [$id, tid()]);
            flash('Mailbox deleted.');
        }
    } catch (DomainException $ex) {   // form problems: show as-is, don't mark the inbox as failing
        flash($ex->getMessage(), 'err');
    } catch (Throwable $ex) {
        $err = mb_substr($ex->getMessage(), 0, 255);
        $mbRow = $id ? owned_row('mailboxes', $id) : null;
        if ($mbRow) update('mailboxes', ['last_error' => $err], 'id = ? AND tenant_id = ?', [$id, tid()]);
        $hint = mailbox_error_hint($err, $mbRow ? guess_provider($mbRow) : $provider);
        flash(($hint !== '' ? $hint . ' ' : '') . '(Details: ' . $err . ')', 'err');
    }
    redirect('mailboxes.php' . ($id && ($a === 'save' || $a === 'save_test') && !empty($err) ? '?edit=' . $id : ''));
}

$edit = isset($_GET['edit']) ? owned_row('mailboxes', (int) $_GET['edit']) : null;
$rows = all('SELECT * FROM mailboxes WHERE tenant_id = ? ORDER BY id', [tid()]);
$default = $providers['zoho_in'];
$m = $edit ?: ['id' => 0, 'label' => '', 'provider' => 'zoho_in', 'from_name' => '', 'email' => '', 'smtp_host' => $default['smtp_host'], 'smtp_port' => $default['smtp_port'],
    'smtp_secure' => $default['smtp_secure'], 'imap_host' => $default['imap_host'], 'imap_port' => $default['imap_port'], 'username' => '', 'signature' => '',
    'daily_limit' => 30, 'ramp_start' => 5, 'ramp_step' => 2, 'warmup_started' => gmdate('Y-m-d'), 'active' => 1];
$cur = $edit ? guess_provider($edit) : 'zoho_in';

page_header('Mailboxes', 'mailboxes', 'The inboxes that send your emails and pick up replies — Zoho, Google, or any provider with SMTP and IMAP.');
?>
<p class="hint">Use inboxes on a <strong>separate look-alike domain</strong>, never automindlab.io itself. Each inbox starts at the ramp start per day, adds the ramp step each day, and stops at its daily limit. Keep the limit at 30–40.</p>
<table class="t">
  <tr><th>Inbox</th><th>Provider</th><th>Today / limit</th><th>Sent</th><th>Warm-up since</th><th>Status</th><th></th></tr>
  <?php foreach ($rows as $r): $tot = (int) val("SELECT COUNT(*) FROM emails WHERE mailbox_id=? AND status='sent'", [$r['id']]); $gp = guess_provider($r); ?>
  <tr><td><strong><?= e($r['email']) ?></strong><br><span class="muted small"><?= e($r['from_name']) ?></span></td>
    <td class="small"><?= e(explode(' — ', $providers[$gp]['label'])[0]) ?></td>
    <td><?= mailbox_sent_today($r) ?> / <?= mailbox_limit_today($r) ?> <span class="muted small">(max <?= (int) $r['daily_limit'] ?>)</span></td>
    <td><?= $tot ?></td><td class="small"><?= e($r['warmup_started'] ?? '—') ?></td>
    <td><?= !$r['active'] ? '<span class="badge muted">off</span>' : ($r['last_error'] ? '<span class="badge bad">error</span><br><span class="err small">' . e(mailbox_error_hint((string) $r['last_error'], $gp) ?: $r['last_error']) . '</span>' : '<span class="badge good">ok</span>') ?></td>
    <td class="nowrap"><a class="btn small" href="?edit=<?= $r['id'] ?>">Edit</a>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
        <button name="action" value="test" class="btn small" data-press>Test</button>
        <button name="action" value="delete" class="btn small danger" data-confirm="Delete this mailbox?" data-press>Delete</button></form></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="muted">No inboxes yet. Add your first one below.</td></tr><?php endif; ?>
</table>

<form method="post" class="panel form mailbox-form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
  <div class="panel-head"><div><h2><?= $edit ? 'Edit ' . e($m['email']) : 'Add an inbox' ?></h2><p class="muted">Pick your email provider and the server settings fill in for you.</p></div></div>

  <label class="field">Email provider
    <select name="provider" data-provider-select>
      <?php foreach ($providers as $k => $p): ?>
        <option value="<?= e($k) ?>" <?= $cur === $k ? 'selected' : '' ?> data-smtp-host="<?= e($p['smtp_host']) ?>" data-smtp-port="<?= (int) $p['smtp_port'] ?>"
          data-smtp-secure="<?= e($p['smtp_secure']) ?>" data-imap-host="<?= e($p['imap_host']) ?>" data-imap-port="<?= (int) $p['imap_port'] ?>"><?= e($p['label']) ?></option>
      <?php endforeach; ?>
    </select></label>

  <?php foreach ($providers as $k => $p): ?>
    <div class="provider-help" data-provider-help="<?= e($k) ?>" <?= $cur === $k ? '' : 'hidden' ?>>
      <?php if ($p['note']): ?><p class="small muted"><?= e($p['note']) ?></p><?php endif; ?>
      <ol><?php foreach ($p['help'] as $line) echo '<li>' . $line . '</li>'; ?></ol>
    </div>
  <?php endforeach; ?>

  <div class="cols3">
    <label>Email address<input name="email" type="email" value="<?= e($m['email']) ?>" required placeholder="joy@yoursendingdomain.com"></label>
    <label>From name<input name="from_name" value="<?= e($m['from_name']) ?>" placeholder="Joy Das" required></label>
    <label>App password <small><?= $edit ? 'leave blank to keep the saved one' : '' ?></small><input name="password" type="password" autocomplete="new-password" <?= $edit ? '' : 'required' ?>></label>
  </div>

  <details class="server-settings" <?= $cur === 'custom' ? 'open' : '' ?> data-server-settings>
    <summary>Server settings</summary>
    <div class="cols4">
      <label>SMTP server<input name="smtp_host" value="<?= e($m['smtp_host']) ?>" spellcheck="false"></label>
      <label>SMTP port<input name="smtp_port" type="number" value="<?= (int) $m['smtp_port'] ?>"></label>
      <label>Security<select name="smtp_secure"><?php foreach (['ssl' => 'SSL (port 465)', 'tls' => 'STARTTLS (port 587)', 'none' => 'None'] as $k => $v): ?><option value="<?= $k ?>" <?= $m['smtp_secure'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></label>
      <label>IMAP server / port<span class="pair"><input name="imap_host" value="<?= e($m['imap_host']) ?>" spellcheck="false"><input name="imap_port" type="number" value="<?= (int) $m['imap_port'] ?>"></span></label>
    </div>
    <div class="cols3">
      <label>Login username <small>if not the email address</small><input name="username" value="<?= e($m['username'] !== $m['email'] ? $m['username'] : '') ?>"></label>
      <label>Label <small>for your reference</small><input name="label" value="<?= e($m['label']) ?>" placeholder="Inbox 1"></label>
    </div>
  </details>

  <h3>Sending pace</h3>
  <div class="cols4">
    <label>Daily limit<input name="daily_limit" type="number" min="1" max="60" value="<?= (int) $m['daily_limit'] ?>"></label>
    <label>Start at (per day)<input name="ramp_start" type="number" value="<?= (int) $m['ramp_start'] ?>"></label>
    <label>Add per day<input name="ramp_step" type="number" value="<?= (int) $m['ramp_step'] ?>"></label>
    <label>Warm-up started<input name="warmup_started" type="date" value="<?= e($m['warmup_started']) ?>"></label>
  </div>
  <label>Signature <small>optional — default is your name, role, company and website</small><textarea name="signature" rows="3"><?= e($m['signature']) ?></textarea></label>
  <label class="switch"><input type="checkbox" name="active" <?= $m['active'] ? 'checked' : '' ?>><span class="track"><span class="thumb"></span></span>Use this inbox for sending</label>
  <div class="actions"><button name="action" value="save_test" class="btn filled" data-press><?= icon('bolt') ?>Save and test</button>
    <button name="action" value="save" class="btn text" data-press>Save</button>
    <?php if ($edit): ?><a class="btn text" href="mailboxes.php">Cancel</a><?php endif; ?></div>
</form>
<?php page_footer();
