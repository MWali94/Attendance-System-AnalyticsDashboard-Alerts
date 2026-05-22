<?php
require_once 'includes/config.php';
startSession();
if (!empty($_SESSION['user_id'])) { header('Location: dashboard.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = trim($_POST['password'] ?? '');
    if ($email && $pass) {
        $stmt = db()->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if ($u && password_verify($pass, $u['password'])) {
            $_SESSION['user_id']   = $u['id'];
            $_SESSION['user_name'] = $u['name'];
            $_SESSION['user_role'] = $u['role'];
            header('Location: dashboard.php'); exit;
        } else {
            $error = 'Invalid email or password.';
        }
    } else {
        $error = 'Please fill in all fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>AttendIQ — Login</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<style>
  .login-body  { background:var(--text); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:2rem; }
  .login-card  { background:var(--surface); border-radius:var(--radius-lg); padding:2.5rem 2rem; width:100%; max-width:420px; box-shadow:0 24px 64px rgba(0,0,0,.4); }
  .login-brand { display:flex; align-items:center; gap:8px; margin-bottom:1.5rem; }
  .login-brand .brand-icon { font-size:24px;  }
  .login-brand .brand-name { font-family:'DM Serif Display',serif; font-size:22px; }
  .login-headline { font-family:'DM Serif Display',serif; font-size:26px; font-weight:400; line-height:1.25; margin-bottom:.5rem; }
  .login-sub   { font-size:14px; color:var(--text-2); margin-bottom:1.5rem; }
  .login-form  { display:flex; flex-direction:column; gap:14px; margin-bottom:1.25rem; }
  .login-divider { text-align:center; font-size:12.5px; color:var(--text-3); margin:.75rem 0; position:relative; }
  .login-divider::before,
  .login-divider::after { content:''; position:absolute; top:50%; width:42%; height:1px; background:var(--border); }
  .login-divider::before { left:0; }
  .login-divider::after  { right:0; }
  .student-btn {
    display:flex; align-items:center; justify-content:center; gap:8px;
    width:100%; padding:10px 20px; border-radius:var(--radius);
    border:1.5px solid var(--border-md); background:transparent;
    color:var(--text); font-size:14px; font-weight:500; cursor:pointer;
    transition:all .15s; text-decoration:none;
  }
  .student-btn:hover { border-color:var(--blue); color:var(--blue); background:var(--blue-lt); }
  .login-hint  { margin-top:1.5rem; font-size:12.5px; color:var(--text-3); text-align:center; }
  .login-hint code { background:var(--bg); padding:2px 6px; border-radius:4px; }
  .login-levels { display:flex; gap:8px; justify-content:center; flex-wrap:wrap; margin-top:1rem; }
</style>
</head>
<body class="login-body">

<div class="login-card">
  <div class="login-brand">
    <span class="brand-icon">◈</span>
    <span class="brand-name">AttendIQ</span>
  </div>

  <h1 class="login-headline">Attendance System</h1>
  <p class="login-sub">Manage attendance across IT Level 3, 4 &amp; 5 courses.<br>Developed by ISLB-IT department.</p> 

  <?php if ($error): ?>
  <div class="alert alert-danger"><?= sanitize($error) ?></div>
  <?php endif; ?>

  <!-- Staff login form -->
  <form method="POST" class="login-form">
    <div class="field-group">
      <label>Email address</label>
      <input type="email" name="email" placeholder="admin@school.com" required
             value="<?= sanitize($_POST['email'] ?? '') ?>">
    </div>
    <div class="field-group">
      <label>Password</label>
      <input type="password" name="password" placeholder="••••••••" required>
    </div>
    <button type="submit" class="btn-primary btn-full">Sign in →</button>
  </form>

  <!-- Divider -->
  <div class="login-divider">or</div>

  <!-- Student / Parent button -->
  <a href="public_reports.php" class="student-btn">
    🎓 Student / Parent — View My Attendance
  </a>


  <div class="login-levels">
    <span class="lv-pill lv3">Level 3 · 7 Courses</span>
    <span class="lv-pill lv4">Level 4 · 11 Courses</span>
    <span class="lv-pill lv5">Level 5 · 8 Courses</span>
  </div>
</div>
    
</body>
</html>