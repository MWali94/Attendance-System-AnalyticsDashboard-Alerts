<?php
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$user  = currentUser();
$flash = flashGet();
?>
<nav class="navbar">
  <div class="nav-brand">
    <span class="brand-icon">◈</span>
    <span class="brand-name">AttendIQ</span>
  </div>
  <ul class="nav-links">
    <li><a href="dashboard.php"  class="<?= $currentPage==='dashboard'  ?'active':'' ?>">Dashboard</a></li>
    <li><a href="attendance.php" class="<?= $currentPage==='attendance' ?'active':'' ?>">Attendance</a></li>
    <li><a href="students.php"   class="<?= $currentPage==='students'   ?'active':'' ?>">Students</a></li>
    <li><a href="classes.php"    class="<?= $currentPage==='classes'    ?'active':'' ?>">Courses</a></li>
    <li><a href="alerts.php"     class="<?= $currentPage==='alerts'     ?'active':'' ?>">Alerts</a></li>
    <li><a href="reports.php"    class="<?= $currentPage==='reports'    ?'active':'' ?>">Reports</a></li>
    <?php if ($user['role']==='admin'): ?>
    <li><a href="users.php"      class="<?= $currentPage==='users'      ?'active':'' ?>">Users</a></li>
    <?php endif; ?>
  </ul>
  <div class="nav-user">
    <span class="user-badge"><?= strtoupper(substr($user['name'],0,2)) ?></span>
    <span class="user-name-text"><?= sanitize($user['name']) ?></span>
    <a href="logout.php" class="nav-logout">Sign out</a>
  </div>
</nav>

<?php if ($flash): ?>
<div class="flash-bar flash-<?= $flash['type'] ?>">
  <?= sanitize($flash['msg']) ?>
  <button onclick="this.parentElement.remove()" class="flash-close">✕</button>
</div>
<?php endif; ?>