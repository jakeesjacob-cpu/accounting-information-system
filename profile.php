<?php
require 'config.php'; require 'layout.php'; require_login();
$uid = (int)$_SESSION['user']['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'profile') {
            $name = trim($_POST['name'] ?? ''); $email = trim($_POST['email'] ?? '');
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('warn', 'Enter your name and a valid email.');
            else {
                $pdo->prepare("UPDATE users SET name=?, email=? WHERE user_id=?")->execute([$name, $email, $uid]);
                $_SESSION['user']['name'] = $name; $_SESSION['user']['email'] = $email;
                audit($pdo, 'Updated own profile'); flash('ok', 'Profile updated.');
            }
        } elseif ($action === 'password') {
            $cur = $_POST['current'] ?? ''; $new = $_POST['new'] ?? ''; $conf = $_POST['confirm'] ?? '';
            $stmt = $pdo->prepare("SELECT password FROM users WHERE user_id=?"); $stmt->execute([$uid]);
            $hash = $stmt->fetchColumn();
            if (!$hash || !password_verify($cur, $hash)) flash('warn', 'Current password is incorrect.');
            elseif (strlen($new) < 8) flash('warn', 'New password must be at least 8 characters.');
            elseif ($new !== $conf) flash('warn', 'New password and confirmation do not match.');
            else {
                $pdo->prepare("UPDATE users SET password=? WHERE user_id=?")->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
                audit($pdo, 'Changed own password'); flash('ok', 'Password changed.');
            }
        }
    } catch (PDOException $e) {
        flash('warn', $e->getCode() === '23000' ? 'That email is already registered.' : 'Database error.');
    }
    header('Location: profile.php'); exit;
}

$stmt = $pdo->prepare("SELECT name,email,role FROM users WHERE user_id=?"); $stmt->execute([$uid]);
$me = $stmt->fetch();
layout_start('profile', 'Profile Settings', 'Update your name, email and password.'); ?>
<section class="block"><h2>Profile</h2><div class="card">
<form method="post" class="form-grid cols-1">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="profile">
  <div><label class="field-label">Full name</label><input name="name" value="<?=h($me['name'])?>" required></div>
  <div><label class="field-label">Email</label><input name="email" type="email" value="<?=h($me['email'])?>" required></div>
  <div><label class="field-label">Role</label><input value="<?=h(role_label($me['role']))?>" readonly></div>
  <div class="actions"><button class="btn brass">Save profile</button></div>
</form></div></section>

<section class="block"><h2>Change password</h2><div class="card">
<form method="post" class="form-grid cols-1">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="password">
  <div><label class="field-label">Current password</label><input name="current" type="password" required autocomplete="current-password"></div>
  <div><label class="field-label">New password (min. 8 characters)</label><input name="new" type="password" minlength="8" required autocomplete="new-password"></div>
  <div><label class="field-label">Confirm new password</label><input name="confirm" type="password" minlength="8" required autocomplete="new-password"></div>
  <div class="actions"><button class="btn brass">Change password</button></div>
</form></div></section>
<?php layout_end(); ?>
