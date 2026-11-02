<?php
require 'config.php';
if (!empty($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}
$error = '';
$done  = false;
$name = $email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $name    = trim($_POST['name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $pass    = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if ($name === '' || strlen($name) > 100) $error = 'Enter your full name.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Enter a valid email address.';
    elseif (strlen($pass) < 8) $error = 'Password must be at least 8 characters.';
    elseif ($pass !== $confirm) $error = 'Password and confirmation do not match.';
    else {
        try {
            // Always a Student, always Pending: an Admin must approve before the account can log in.
            $pdo->prepare("INSERT INTO users(name,email,password,role,two_factor_secret,status) VALUES(?,?,?,'Student',?,'Pending')")
                ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), base64_encode(random_bytes(16))]);
            $done = true;
        } catch (PDOException $e) {
            $error = $e->getCode() === '23000'
                ? 'That email is already registered.'
                : 'Could not create your registration. Please tell the administrator (did the Pending status migration run?).';
        }
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Student Sign Up - Accounting Information System</title>
<link rel="stylesheet" href="style.css">
<style>
.pw-wrap{position:relative;display:block}
.pw-wrap input{width:100%;padding-right:44px}
.pw-wrap .pw-toggle{position:absolute;right:4px;top:50%;transform:translateY(-50%);background:none;border:0;color:#667085;padding:6px 8px;line-height:0;cursor:pointer}
.pw-wrap .pw-toggle:hover{color:#17324d}
.login-card .ok{background:#e9f7ef;color:#1d6b43;padding:12px;border-radius:7px;margin-bottom:15px}
.login-card .back{display:block;margin-top:14px;text-align:center;font-size:14px;color:#17324d}
@media (max-width:480px){
  .login-page{padding:16px;align-items:start;padding-top:8vh}
  .login-card{padding:22px 18px;box-shadow:0 4px 18px #0001}
  .login-card h1{font-size:1.35rem;line-height:1.25}
  .login-card input,.login-card button{font-size:16px;min-height:44px}
}
</style>
</head>
<body class="login-page">
<div class="login-card">
<h1>Student Sign Up</h1>
<?php if ($done): ?>
  <div class="ok">Registration received. An administrator must approve your account before you can log in.</div>
  <a class="back" href="index.php">Back to login</a>
<?php else: ?>
  <?php if ($error): ?><div class="alert"><?=h($error)?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?=h(csrf())?>">
    <label>Full name</label>
    <input name="name" value="<?=h($name)?>" maxlength="100" required>
    <label>Email</label>
    <input name="email" type="email" value="<?=h($email)?>" placeholder="name@aclc.edu.ph" required>
    <label>Password (min. 8 characters)</label>
    <div class="pw-wrap">
      <input name="password" id="password" type="password" minlength="8" required autocomplete="new-password">
      <button type="button" class="pw-toggle" id="pwToggle" aria-label="Show password" aria-pressed="false" title="Show password">
        <svg id="eyeOn" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        <svg id="eyeOff" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
      </button>
    </div>
    <label>Confirm password</label>
    <input name="confirm" id="confirm" type="password" minlength="8" required autocomplete="new-password">
    <button type="submit">Sign up</button>
  </form>
  <a class="back" href="index.php">Already have an account? Log in</a>
<?php endif; ?>
</div>
<script>
var tg = document.getElementById('pwToggle');
if (tg) tg.addEventListener('click', function () {
  var input = document.getElementById('password');
  var conf  = document.getElementById('confirm');
  var show = input.type === 'password';
  input.type = conf.type = show ? 'text' : 'password';
  document.getElementById('eyeOn').style.display  = show ? 'none' : '';
  document.getElementById('eyeOff').style.display = show ? '' : 'none';
  this.setAttribute('title', show ? 'Hide password' : 'Show password');
  this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  this.setAttribute('aria-pressed', show ? 'true' : 'false');
});
</script>
</body>
</html>
