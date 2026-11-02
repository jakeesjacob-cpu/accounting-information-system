<?php
require 'config.php'; require 'layout.php'; require_login();
$role = $_SESSION['user']['role'];
$uid  = (int)$_SESSION['user']['user_id'];
$canPost = in_array($role, ['Admin','Faculty'], true);
$audiences = ['All Students','All Faculty','All BSAIS students','All BSEN students'];

// Admin can change any notification; Faculty can change only the ones they posted.
function can_manage($createdBy) {
    global $role, $uid;
    return $role === 'Admin' || ($role === 'Faculty' && (int)$createdBy === $uid);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf(); require_role(['Admin','Faculty']);
    $action = $_POST['action'] ?? 'add';
    try {
        if ($action === 'add') {
            $aud = $_POST['audience'] ?? ''; $msg = trim($_POST['message'] ?? '');
            if (!in_array($aud, $audiences, true) || $msg === '') flash('warn', 'Choose an audience and write a message.');
            else {
                $pdo->prepare("INSERT INTO notifications(audience,message,created_by) VALUES(?,?,?)")->execute([$aud, $msg, $uid]);
                audit($pdo, "Sent notification to $aud"); flash('ok', 'Notification sent.');
            }
        } elseif ($action === 'update' || $action === 'delete') {
            $id = (int)($_POST['notification_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT created_by FROM notifications WHERE notification_id=?");
            $stmt->execute([$id]); $row = $stmt->fetch();
            if (!$row) flash('warn', 'Notification not found.');
            elseif (!can_manage($row['created_by'])) flash('warn', 'You can only change notifications you posted.');
            elseif ($action === 'update') {
                $aud = $_POST['audience'] ?? ''; $msg = trim($_POST['message'] ?? '');
                if (!in_array($aud, $audiences, true) || $msg === '') flash('warn', 'Choose an audience and write a message.');
                else {
                    $pdo->prepare("UPDATE notifications SET audience=?, message=? WHERE notification_id=?")->execute([$aud, $msg, $id]);
                    audit($pdo, "Updated notification #$id"); flash('ok', 'Notification updated.');
                }
            } else {
                $pdo->prepare("DELETE FROM notifications WHERE notification_id=?")->execute([$id]);
                audit($pdo, "Deleted notification #$id"); flash('ok', 'Notification deleted.');
            }
        }
    } catch (PDOException $e) {
        flash('warn', 'Database error. Did you run the notifications migration in database.sql?');
    }
    header('Location: notifications.php'); exit;
}

try {
    $notes = $pdo->query("SELECT n.*, u.name FROM notifications n LEFT JOIN users u ON u.user_id=n.created_by ORDER BY n.notification_id DESC")->fetchAll();
} catch (PDOException $e) {
    $notes = [];
    flash('warn', 'The notifications table is missing. Run the notifications migration in database.sql.');
}
// Results sent to one student (recipient_id) are private to that student and to whoever sent them.
$notes = array_filter($notes, function ($n) use ($uid) {
    $r = (int)($n['recipient_id'] ?? 0);
    return $r === 0 || $r === $uid || (int)$n['created_by'] === $uid;
});
if ($role === 'Student') $notes = array_filter($notes, function ($n) { return $n['audience'] !== 'All Faculty'; });

// Remember what this user had already seen (to tag new cards), then mark everything as seen.
$lastSeen = 0;
try {
    $s = $pdo->prepare("SELECT COALESCE(last_seen_notification,0) FROM users WHERE user_id=?");
    $s->execute([$uid]); $lastSeen = (int)$s->fetchColumn();
    $pdo->prepare("UPDATE users SET last_seen_notification=(SELECT COALESCE(MAX(notification_id),0) FROM notifications) WHERE user_id=?")->execute([$uid]);
} catch (Throwable $e) { /* column not added yet: no badge, page still works */ }

layout_start('notifications', 'Notifications', 'System and course announcements.');
if ($canPost): ?><div style="margin-bottom:12px"><button class="btn brass" onclick="document.getElementById('noteDlg').showModal()">+ New notification</button></div><?php endif; ?>
<div class="grid cols-2">
<?php foreach ($notes as $n): ?>
  <div class="card">
    <div class="small muted"><?php if ((int)$n['notification_id'] > $lastSeen && (int)$n['created_by'] !== $uid): ?><span class="pill ok">New</span> <?php endif; ?><?=h(date('M d, Y h:i A', strtotime($n['created_at'])))?> · To: <?=!empty($n['recipient_id']) ? ((int)$n['recipient_id'] === $uid ? 'You' : 'One student') : h($n['audience'])?><?=$n['name'] ? ' · ' . h($n['name']) : ''?></div>
    <div style="margin-top:6px"><?=nl2br(h($n['message']))?></div>
    <?php if ($canPost && can_manage($n['created_by'])): ?>
    <div style="margin-top:10px;display:flex;gap:6px">
      <button type="button" class="btn ghost small"
        data-id="<?=(int)$n['notification_id']?>" data-audience="<?=h($n['audience'])?>" data-message="<?=h($n['message'])?>"
        onclick="editNote(this)">Edit</button>
      <form method="post" style="display:inline" onsubmit="return confirm('Delete this notification?')">
        <input type="hidden" name="csrf" value="<?=h(csrf())?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="notification_id" value="<?=(int)$n['notification_id']?>">
        <button class="btn ghost small">Delete</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<?php if (!$notes): ?><div class="empty">No notifications.</div><?php endif; ?>

<?php if ($canPost): ?>
<dialog id="noteDlg" class="modal"><h3>New notification</h3>
<form method="post" class="form-grid cols-1">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>">
  <input type="hidden" name="action" value="add">
  <div><label class="field-label">Audience</label><select name="audience"><?php foreach ($audiences as $a): ?><option><?=h($a)?></option><?php endforeach; ?></select></div>
  <div><label class="field-label">Message</label><textarea name="message" required placeholder="Type your announcement..."></textarea></div>
  <div class="actions"><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn brass">Send</button></div>
</form></dialog>

<dialog id="editDlg" class="modal"><h3>Edit notification</h3>
<form method="post" class="form-grid cols-1">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>">
  <input type="hidden" name="action" value="update">
  <input type="hidden" name="notification_id" id="editId">
  <div><label class="field-label">Audience</label><select name="audience" id="editAud"><?php foreach ($audiences as $a): ?><option><?=h($a)?></option><?php endforeach; ?></select></div>
  <div><label class="field-label">Message</label><textarea name="message" id="editMsg" required></textarea></div>
  <div class="actions"><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn brass">Save changes</button></div>
</form></dialog>
<script>
function editNote(btn){
  document.getElementById('editId').value  = btn.dataset.id;
  document.getElementById('editAud').value = btn.dataset.audience;
  document.getElementById('editMsg').value = btn.dataset.message;
  document.getElementById('editDlg').showModal();
}
</script>
<?php endif; layout_end(); ?>