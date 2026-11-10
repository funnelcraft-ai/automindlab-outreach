<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_role('member');
    $rid = (int) ($_POST['reply_id'] ?? 0);
    try {
        if (($_POST['action'] ?? '') === 'send') {
            $text = trim((string) ($_POST['text'] ?? ''));
            if ($text === '') throw new RuntimeException('Write a reply first.');
            send_manual_reply($rid, $text);
            flash('Reply sent in the same thread.');
        } elseif (($_POST['action'] ?? '') === 'handled') {
            update('replies', ['handled' => 1], 'id = ? AND tenant_id = ?', [$rid, tid()]);
            flash('Marked as handled.');
        }
    } catch (Throwable $ex) { flash('Could not send: ' . $ex->getMessage(), 'err'); redirect('replies.php?open=' . $rid); }
    redirect('replies.php');
}

$show = (string) ($_GET['show'] ?? 'open');
$open = (int) ($_GET['open'] ?? 0);
$where = $show === 'all' ? "r.kind = 'reply'" : ($show === 'bounces' ? "r.kind = 'bounce'" : "r.kind = 'reply' AND r.handled = 0");
$rows = all("SELECT r.*, l.business_name, l.status AS lead_status, m.email AS mailbox_email FROM replies r
             LEFT JOIN leads l ON l.id = r.lead_id LEFT JOIN mailboxes m ON m.id = r.mailbox_id
             WHERE r.tenant_id = ? AND $where ORDER BY r.received_at DESC LIMIT 100", [tid()]);

page_header('Replies', 'replies', 'Prospect replies, sorted by AI. Edit the suggested answer and send it in the same thread.');
?>
<div class="chips">
  <a href="?show=open" class="<?= $show === 'open' ? 'on' : '' ?>">Needs a response</a>
  <a href="?show=all" class="<?= $show === 'all' ? 'on' : '' ?>">All replies</a>
  <a href="?show=bounces" class="<?= $show === 'bounces' ? 'on' : '' ?>">Bounces</a>
</div>
<?php foreach ($rows as $r): ?>
<section class="card reply-card <?= $open === (int) $r['id'] ? 'focus' : '' ?>" id="r<?= $r['id'] ?>">
  <div class="step-head">
    <b><?= $r['lead_id'] ? '<a href="lead.php?id=' . $r['lead_id'] . '">' . e($r['business_name']) . '</a>' : e($r['from_email']) ?></b>
    <span class="badge <?= in_array($r['category'], ['interested', 'question', 'referral'], true) ? 'good' : 'muted' ?>"><?= e($r['category']) ?></span>
    <span class="muted small"><?= e($r['from_name'] ?: $r['from_email']) ?> → <?= e($r['mailbox_email']) ?> · <?= e(local_time($r['received_at'])) ?></span>
  </div>
  <?php if ($r['summary']): ?><p class="muted">AI summary: <?= e($r['summary']) ?></p><?php endif; ?>
  <pre class="body"><?= e($r['body']) ?></pre>
  <?php if ($r['sent_reply']): ?>
    <div class="muted small">You replied:</div><pre class="body mine"><?= e($r['sent_reply']) ?></pre>
  <?php elseif ($r['kind'] === 'reply' && $r['lead_id'] && can('member')): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="reply_id" value="<?= $r['id'] ?>">
      <?php $rf = $r['suggested_reply'] ? draft_safety_issues([['subject' => '', 'body' => $r['suggested_reply']]], ['offer' => '', 'proof' => '']) : []; ?>
      <?php if ($rf): ?><div class="flag-box"><strong>Check the suggested reply:</strong> <?= e(str_replace('Email 1 ', 'it ', implode('; ', $rf))) ?>.</div><?php endif; ?>
      <textarea name="text" rows="7" placeholder="Write your reply… {{cal_link}} inserts your booking link"><?= e($r['suggested_reply']) ?></textarea>
      <div class="actions"><button name="action" value="send" class="btn primary">Send reply</button>
      <button name="action" value="handled" class="btn ghost">Mark handled</button>
      <span class="hint">Draft written by AI — check it before sending. Signature is added automatically.</span></div>
    </form>
  <?php endif; ?>
</section>
<?php endforeach; if (!$rows): ?><p class="muted">Nothing here. Replies are picked up from your inboxes every 10 minutes.</p><?php endif; ?>
<?php page_footer();
