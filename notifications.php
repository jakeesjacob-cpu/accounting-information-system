<?php
require 'config.php'; require 'layout.php'; require_login();
$role = $_SESSION['user']['role'];
$canPost = in_array($role, ['Admin','Faculty'], true);
$audiences = ['All Students','All Faculty','All BSAIS students','All BSEN students'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf(); require_role(['Admin','Faculty']);
    $aud = $_POST['audience'] ?? ''; $msg = trim($_POST['message'] ?? '');
    if (!in_array($aud, $audiences, true) || $msg === '') flash('warn', 'Choose an audience and write a message.');
    else {
        try {
            $pdo->prepare("INSERT INTO notifications(audience,message,created_by) VALUES(?,?,?)")->execute([$aud, $msg, $_SESSION['user']['user_id']]);
            audit($pdo, "Sent notification to $aud"); flash('ok', 'Notification sent.');
        } catch (PDOException $e) { flash('warn', 'Database error. Did you run the notifications migration in database.sql?'); }
    }
    header('Location: notifications.php'); exit;
}
$notes = $pdo->query("SELECT n.*, u.name FROM notifications n LEFT JOIN users u ON u.user_id=n.created_by ORDER BY n.notification_id DESC")->fetchAll();
if ($role === 'Student') $notes = array_filter($notes, function ($n) { return $n['audience'] !== 'All Faculty'; });

layout_start('notifications', 'Notifications', 'System and course announcements.');
if ($canPost): ?><div style="margin-bottom:12px"><button class="btn brass" onclick="document.getElementById('noteDlg').showModal()">+ New notification</button></div><?php endif; ?>
<div class="grid cols-2">
<?php foreach ($notes as $n): ?>
  <div class="card"><div class="small muted"><?=h(date('M d, Y h:i A', strtotime($n['created_at'])))?> · To: <?=h($n['audience'])?><?=$n['name'] ? ' · ' . h($n['name']) : ''?></div><div style="margin-top:6px"><?=nl2br(h($n['message']))?></div></div>
<?php endforeach; ?>
</div>
<?php if (!$notes): ?><div class="empty">No notifications.</div><?php endif; ?>
<?php if ($canPost): ?>
<dialog id="noteDlg" class="modal"><h3>New notification</h3>
<form method="post" class="form-grid cols-1">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>">
  <div><label class="field-label">Audience</label><select name="audience"><?php foreach ($audiences as $a): ?><option><?=h($a)?></option><?php endforeach; ?></select></div>
  <div><label class="field-label">Message</label><textarea name="message" required placeholder="Type your announcement..."></textarea></div>
  <div class="actions"><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn brass">Send</button></div>
</form></dialog>
<?php endif; layout_end(); ?>
