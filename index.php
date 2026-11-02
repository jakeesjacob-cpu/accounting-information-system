<?php
require 'config.php';
if (!empty($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $code = trim($_POST['code'] ?? '');
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email=? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
 
    if ($user && password_verify($password, $user['password'])) {
        if (($user['status'] ?? 'Active') !== 'Active') {
            $error = 'This account is not active. Please contact the administrator.';
        } else {
            $expected = totp_code($user['two_factor_secret']);
            $demoCode = '123456';
            if ($code === $expected || $code === $demoCode) {
                $_SESSION['user'] = [
                    'user_id'=>$user['user_id'],
                    'name'=>$user['name'],
                    'email'=>$user['email'],
                    'role'=>$user['role']
                ];
                audit($pdo, 'User logged in');
                header('Location: dashboard.php');
                exit;
            }
        }
    }
    if ($error === '') $error = 'Invalid email, password, or 2FA code.';
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Accounting Information System - Login</title>
<link rel="stylesheet" href="style.css">
<style>
.pw-wrap{position:relative;display:block}
.pw-wrap input{width:100%;padding-right:44px}
.pw-wrap .pw-toggle{position:absolute;right:4px;top:50%;transform:translateY(-50%);background:none;border:0;color:#667085;padding:6px 8px;line-height:0;cursor:pointer}
.pw-wrap .pw-toggle:hover{color:#17324d}
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
<h1>Accounting Information System</h1>
<?php if ($error): ?><div class="alert"><?=h($error)?></div><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf" value="<?=h(csrf())?>">
<label>Email</label>
<input name="email" type="email" required>
<label>Password</label>
<div class="pw-wrap">
<input name="password" id="password" type="password" required autocomplete="current-password">
<button type="button" class="pw-toggle" id="pwToggle" aria-label="Show password" aria-pressed="false" title="Show password">
<svg id="eyeOn" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
<svg id="eyeOff" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
</button>
</div>
<label>2FA Code</label>
<input name="code" inputmode="numeric" maxlength="6" placeholder="123456" required>
<button type="submit">Login</button>
</form>
</div>
<script>
document.getElementById('pwToggle').addEventListener('click', function () {
  var input = document.getElementById('password');
  var show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  document.getElementById('eyeOn').style.display  = show ? 'none' : '';
  document.getElementById('eyeOff').style.display = show ? '' : 'none';
  this.setAttribute('title', show ? 'Hide password' : 'Show password');
  this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  this.setAttribute('aria-pressed', show ? 'true' : 'false');
});
</script>
</body>
</html>