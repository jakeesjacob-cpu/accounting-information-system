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
$users = $pdo->query("SELECT user_id,name,email,role,status FROM users ORDER BY user_id")->fetchAll();
layout_start('users', 'User Management', 'Administrator, Instructor, and Student accounts.'); ?>
<div class="flex-between" style="margin-bottom:12px">
  <input type="text" id="userSearch" placeholder="Search users..." style="max-width:240px" onkeyup="filterUsers()">
  <button class="btn brass" onclick="document.getElementById('userDlg').showModal()">+ New user</button>
</div>
<div id="userWrap"><?php table_open(['Name','Email','Role','Status',''], [0,0,0,0,0]);
foreach ($users as $u): ?>
  <tr><td><?=h($u['name'])?></td><td><?=h($u['email'])?></td><td><?=h(role_label($u['role']))?></td><td><?=pill($u['status'])?></td>
  <td><?php if ((int)$u['user_id'] !== $selfId): ?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="toggle_user"><input type="hidden" name="user_id" value="<?=(int)$u['user_id']?>"><button class="btn ghost small"><?=$u['status'] === 'Active' ? 'Disable' : 'Enable'?></button></form>
    <form method="post" style="display:inline" onsubmit="return confirm('Delete this user permanently?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="user_id" value="<?=(int)$u['user_id']?>"><button class="btn ghost small">Delete</button></form><?php endif; ?></td></tr>
<?php endforeach; table_close(5); ?></div>
<dialog id="userDlg" class="modal"><h3>New user</h3>
<form method="post" class="form-grid cols-1">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="add_user">
  <div><label class="field-label">Full name</label><input name="name" required></div>
  <div><label class="field-label">Email</label><input name="email" type="email" required placeholder="name@aclc.edu.ph"></div>
  <div><label class="field-label">Role</label><select name="role"><option value="Student">Student</option><option value="Faculty">Faculty</option><option value="Admin">Administrator</option></select></div>
  <div><label class="field-label">Temporary password (min. 8 characters)</label><input name="password" type="password" minlength="8" required></div>
  <div class="actions"><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn brass">Add user</button></div>
</form></dialog>
<script>
function filterUsers(){
  var q=document.getElementById('userSearch').value.toLowerCase();
  document.querySelectorAll('#userWrap tbody tr').forEach(function(r){ r.style.display=r.innerText.toLowerCase().includes(q)?'':'none'; });
}
</script>
<?php layout_end(); ?>