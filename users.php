<?php
require 'config.php'; require 'layout.php'; require_role(['Admin']);
$selfId = (int)$_SESSION['user']['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_user') {
            $name = trim($_POST['name'] ?? ''); $email = trim($_POST['email'] ?? '');
            $role = $_POST['role'] ?? ''; $pass = $_POST['password'] ?? '';
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('warn', 'Enter a name and a valid email.');
            elseif (!in_array($role, ['Admin','Faculty','Student'], true)) flash('warn', 'Invalid role.');
            elseif (strlen($pass) < 8) flash('warn', 'Temporary password must be at least 8 characters.');
            else {
                $pdo->prepare("INSERT INTO users(name,email,password,role,two_factor_secret,status) VALUES(?,?,?,?,?,'Active')")
                    ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role, base64_encode(random_bytes(16))]);
                audit($pdo, "Created user $email ($role)"); flash('ok', "$name was added as " . role_label($role) . '.');
            }
        } elseif ($action === 'approve_user') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT email FROM users WHERE user_id=? AND status='Pending' AND role='Student'");
            $stmt->execute([$uid]); $u = $stmt->fetch();
            if (!$u) flash('warn', 'That registration is no longer pending.');
            else {
                $pdo->prepare("UPDATE users SET status='Active' WHERE user_id=?")->execute([$uid]);
                audit($pdo, 'Approved student registration ' . $u['email']);
                flash('ok', $u['email'] . ' was approved and can now log in.');
            }
        } elseif ($action === 'reject_user') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT email FROM users WHERE user_id=? AND status='Pending' AND role='Student'");
            $stmt->execute([$uid]); $u = $stmt->fetch();
            if (!$u) flash('warn', 'That registration is no longer pending.');
            else {
                $pdo->prepare("DELETE FROM users WHERE user_id=?")->execute([$uid]);
                audit($pdo, 'Rejected student registration ' . $u['email']);
                flash('ok', 'Registration of ' . $u['email'] . ' was rejected and removed.');
            }
        } elseif ($action === 'delete_user') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT email,role FROM users WHERE user_id=?"); $stmt->execute([$uid]); $u = $stmt->fetch();
            if (!$u) flash('warn', 'User not found.');
            elseif ($uid === $selfId) flash('warn', 'You cannot delete your own account.');
            else {
                $admins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='Admin'")->fetchColumn();
                if ($u['role'] === 'Admin' && $admins <= 1) flash('warn', 'You cannot delete the last administrator.');
                else {
                    try {
                        $pdo->prepare("DELETE FROM users WHERE user_id=?")->execute([$uid]);
                        audit($pdo, 'Deleted user ' . $u['email']); flash('ok', $u['email'] . ' was deleted.');
                    } catch (PDOException $e) {
                        flash('warn', 'Cannot delete — this user has journal entries, grades or other records. Disable the account instead.');
                    }
                }
            }
        } elseif ($action === 'toggle_user') {
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid === $selfId) flash('warn', 'You cannot disable your own account.');
            else {
                $stmt = $pdo->prepare("SELECT email,status FROM users WHERE user_id=?"); $stmt->execute([$uid]); $u = $stmt->fetch();
                if ($u) {
                    $new = $u['status'] === 'Active' ? 'Disabled' : 'Active';
                    $pdo->prepare("UPDATE users SET status=? WHERE user_id=?")->execute([$new, $uid]);
                    audit($pdo, ($new === 'Active' ? 'Enabled' : 'Disabled') . ' account ' . $u['email']);
                    flash('ok', $u['email'] . " is now $new.");
                }
            }
        }
    } catch (PDOException $e) {
        flash('warn', $e->getCode() === '23000' ? 'That email is already registered.' : 'Database error. Did you run the latest database.sql migration?');
    }
    header('Location: users.php'); exit;
}
$users = $pdo->query("SELECT user_id,name,email,role,status FROM users ORDER BY (status='Pending') DESC, user_id")->fetchAll();
$pendingCount = count(array_filter($users, function ($x) { return $x['status'] === 'Pending'; }));
layout_start('users', 'User Management', 'Administrator, Instructor, and Student accounts.'); ?>
<?php if ($pendingCount): ?><div class="banner warn" style="margin-bottom:12px"><?=$pendingCount?> student registration<?=$pendingCount > 1 ? 's are' : ' is'?> waiting for your approval (shown at the top of the list).</div><?php endif; ?>
<div class="flex-between" style="margin-bottom:12px">
  <input type="text" id="userSearch" placeholder="Search users..." style="max-width:240px" onkeyup="filterUsers()">
  <button class="btn brass" onclick="openUserDlg()">+ New user</button>
</div>
<div id="userWrap"><?php table_open(['Name','Email','Role','Status',''], [0,0,0,0,0]);
foreach ($users as $u): ?>
  <tr><td><?=h($u['name'])?></td><td><?=h($u['email'])?></td><td><?=h(role_label($u['role']))?></td><td><?=pill($u['status'])?></td>
  <td><?php if ($u['status'] === 'Pending'): ?>
    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="approve_user"><input type="hidden" name="user_id" value="<?=(int)$u['user_id']?>"><button class="btn brass small">Approve</button></form>
    <form method="post" style="display:inline" onsubmit="return confirm('Reject and remove this registration?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="reject_user"><input type="hidden" name="user_id" value="<?=(int)$u['user_id']?>"><button class="btn ghost small">Reject</button></form>
  <?php elseif ((int)$u['user_id'] !== $selfId): ?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="toggle_user"><input type="hidden" name="user_id" value="<?=(int)$u['user_id']?>"><button class="btn ghost small"><?=$u['status'] === 'Active' ? 'Disable' : 'Enable'?></button></form>
    <form method="post" style="display:inline" onsubmit="return confirm('Delete this user permanently?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="user_id" value="<?=(int)$u['user_id']?>"><button class="btn ghost small">Delete</button></form><?php endif; ?></td></tr>
<?php endforeach; table_close(5); ?></div>
<dialog id="userDlg" class="modal"><h3>New user</h3>
<form method="post" class="form-grid cols-1">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="add_user">
  <div><label class="field-label">Full name</label><input name="name" required></div>
  <div><label class="field-label">Email</label><input name="email" type="email" required placeholder="name@aclc.edu.ph"></div>
  <div><label class="field-label">Role</label><select name="role"><option value="Student">Student</option><option value="Faculty">Faculty</option><option value="Admin">Administrator</option></select></div>
  <div><label class="field-label">Temporary password (min. 8 characters)</label>
    <div class="pw-wrap">
      <input name="password" id="newPass" type="password" minlength="8" required autocomplete="new-password">
      <button type="button" class="pw-eye" id="passEye" aria-label="Show password" aria-pressed="false" title="Show password">
        <svg id="eyeOn" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        <svg id="eyeOff" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
      </button>
    </div></div>
  <div class="actions"><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn brass">Add user</button></div>
</form></dialog>
<style>
.pw-wrap{position:relative;display:block}
.pw-wrap input{width:100%;padding-right:42px}
.pw-wrap .pw-eye{position:absolute;right:4px;top:50%;transform:translateY(-50%);background:none;border:0;color:var(--ink-soft);padding:6px 8px;line-height:0;cursor:pointer}
.pw-wrap .pw-eye:hover{color:var(--ink)}
</style>
<script>
function filterUsers(){
  var q=document.getElementById('userSearch').value.toLowerCase();
  document.querySelectorAll('#userWrap tbody tr').forEach(function(r){ r.style.display=r.innerText.toLowerCase().includes(q)?'':'none'; });
}
function setPassVisible(show){
  var i=document.getElementById('newPass'), b=document.getElementById('passEye');
  i.type = show ? 'text' : 'password';
  document.getElementById('eyeOn').style.display  = show ? 'none' : '';
  document.getElementById('eyeOff').style.display = show ? '' : 'none';
  b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  b.setAttribute('title', show ? 'Hide password' : 'Show password');
  b.setAttribute('aria-pressed', show ? 'true' : 'false');
}
document.getElementById('passEye').addEventListener('click', function(){
  setPassVisible(document.getElementById('newPass').type === 'password');
});
function openUserDlg(){
  setPassVisible(false);   // always start hidden
  document.getElementById('userDlg').showModal();
}
</script>
<?php layout_end(); ?>