<?php
require_once 'includes/config.php';
requireLogin();
$user  = currentUser();
$db    = db();
$today = date('Y-m-d');
$month = date('Y-m');

// ── Headline stats ───────────────────────────────────────────
$totalStudents = $db->query('SELECT COUNT(*) FROM students')->fetchColumn();
$totalCourses  = $db->query('SELECT COUNT(*) FROM classes')->fetchColumn();

$s = $db->prepare('SELECT COUNT(DISTINCT student_id) FROM attendance WHERE date=? AND status="present"');
$s->execute([$today]); $todayPresent = $s->fetchColumn();

$s = $db->prepare('SELECT COUNT(DISTINCT student_id) FROM attendance WHERE date=? AND status="absent"');
$s->execute([$today]); $todayAbsent = $s->fetchColumn();

// ── Students per level ───────────────────────────────────────
$lvRows = $db->query('SELECT level, COUNT(*) AS cnt FROM students GROUP BY level ORDER BY level')->fetchAll();
$lvCounts = [3=>0, 4=>0, 5=>0];
foreach ($lvRows as $r) $lvCounts[$r['level']] = $r['cnt'];

// ── At-risk: students with 3+ absences this month (any course)
$atRisk = $db->prepare("
    SELECT s.id, s.roll_no, s.name, s.level,
           COUNT(DISTINCT a.class_id, a.date) AS absences
    FROM attendance a
    JOIN students s ON s.id = a.student_id
    WHERE a.status='absent' AND DATE_FORMAT(a.date,'%Y-%m')=?
    GROUP BY s.id
    HAVING absences >= 3
    ORDER BY absences DESC
    LIMIT 10
");
$atRisk->execute([$month]);
$atRiskStudents = $atRisk->fetchAll();

// ── Course attendance this week ──────────────────────────────
$weekStart = date('Y-m-d', strtotime('monday this week'));
$classStat = $db->prepare("
    SELECT c.id, c.name, c.level,
           COUNT(DISTINCT e.student_id)                                          AS total_enrolled,
           SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END)                  AS present_count,
           COUNT(a.id)                                                           AS marked
    FROM classes c
    JOIN enrollments e ON e.class_id = c.id
    LEFT JOIN attendance a ON a.student_id = e.student_id
                           AND a.class_id  = c.id
                           AND a.date >= ?
    GROUP BY c.id
    ORDER BY c.level, c.name
");
$classStat->execute([$weekStart]);
$classData = $classStat->fetchAll();

// ── Recent alerts ────────────────────────────────────────────
$recentAlerts = $db->query("
    SELECT al.*, s.name AS student_name, s.roll_no, s.level,
           c.name AS course
    FROM alerts al
    JOIN students s ON s.id = al.student_id
    LEFT JOIN classes c ON c.id = al.class_id
    ORDER BY al.created_at DESC LIMIT 6
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard — AttendIQ</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<main class="main-content">

  <div class="page-header">
    <div>
      <h1 class="page-title">Dashboard</h1>
      <p class="page-sub">Welcome back, <?= sanitize($user['name']) ?> · <?= date('l, d M Y') ?></p>
    </div>
    <a href="attendance.php" class="btn-primary">+ Mark Attendance</a>
  </div>

  <!-- Headline metrics -->
  <div class="metrics-grid">
    <div class="metric-card">
      <div class="metric-icon icon-slate">◉</div>
      <div class="metric-val"><?= $totalStudents ?></div>
      <div class="metric-label">Total Students</div>
    </div>
    <div class="metric-card">
      <div class="metric-icon icon-slate">◈</div>
      <div class="metric-val"><?= $totalCourses ?></div>
      <div class="metric-label">Total Courses</div>
    </div>
    <div class="metric-card">
      <div class="metric-icon icon-green">◉</div>
      <div class="metric-val"><?= $todayPresent ?></div>
      <div class="metric-label">Present Today</div>
    </div>
    <div class="metric-card">
      <div class="metric-icon icon-red">◉</div>
      <div class="metric-val"><?= $todayAbsent ?></div>
      <div class="metric-label">Absent Today</div>
    </div>
    <div class="metric-card">
      <div class="metric-icon icon-amber">◉</div>
      <div class="metric-val"><?= count($atRiskStudents) ?></div>
      <div class="metric-label">At-Risk This Month</div>
    </div>
  </div>

  <!-- Students by Level -->
  <div class="metrics-grid three-col">
    <?php foreach ([3,4,5] as $lv): ?>
    <div class="metric-card lv-card lv<?= $lv ?>-card">
      <div class="lv-card-label">Level <?= $lv ?></div>
      <div class="lv-card-title"><?= $lv===3?'':'' ?></div>
      <div class="lv-card-count"><?= $lvCounts[$lv] ?> <span>students</span></div>
      <div class="lv-card-sub"><?= lvCourseCount($lv) ?> courses · each student enrolled in all</div>
      <a href="students.php?level=<?= $lv ?>" class="lv-card-link">View students →</a>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="two-col">

    <!-- Course attendance this week -->
    <div class="card">
      <div class="card-header"><h2 class="card-title">Course Attendance — This Week</h2></div>
      <?php
      $prevLv = null;
      foreach ($classData as $cls):
        $pct = $cls['marked'] > 0 ? round(($cls['present_count'] / $cls['marked']) * 100) : 0;
        $color = $pct>=85 ? 'bar-green' : ($pct>=70 ? 'bar-amber' : 'bar-red');
        if ($cls['level'] !== $prevLv):
          $prevLv = $cls['level'];
      ?>
      <div class="lv-mini-header lv<?= $prevLv ?>-mini">Level <?= $prevLv ?></div>
      <?php endif; ?>
      <div class="bar-row">
        <span class="bar-label" title="<?= sanitize($cls['name']) ?>"><?= mb_strimwidth(sanitize($cls['name']),0,24,'…') ?></span>
        <div class="bar-track"><div class="bar-fill <?= $color ?>" style="width:<?= $pct ?>%"></div></div>
        <span class="bar-pct <?= str_replace('bar-','pct-',$color) ?>"><?= $pct ?>%</span>
      </div>
      <?php endforeach; ?>
      <?php if (empty($classData)): ?><p class="empty-msg">No attendance data yet.</p><?php endif; ?>
    </div>

    <!-- At-risk students -->
    <div class="card">
      <div class="card-header">
        <h2 class="card-title">At-Risk Students</h2>
        <a href="students.php" class="card-link">View all →</a>
      </div>
      <?php if (empty($atRiskStudents)): ?>
        <p class="empty-msg">No at-risk students this month 🎉</p>
      <?php else: foreach ($atRiskStudents as $s): ?>
        <div class="student-row">
          <div class="avatar-circle"><?= strtoupper(substr($s['name'],0,2)) ?></div>
          <div class="student-info">
            <div class="student-name"><?= sanitize($s['name']) ?></div>
            <div class="student-meta">
              <span class="lv-tag lv<?= $s['level'] ?>-tag">L<?= $s['level'] ?></span>
              <?= sanitize($s['roll_no']) ?>
            </div>
          </div>
          <span class="badge badge-<?= $s['absences']>=7?'red':($s['absences']>=5?'amber':'blue') ?>"><?= $s['absences'] ?> absent</span>
        </div>
      <?php endforeach; endif; ?>
    </div>

  </div>

  <!-- Recent alerts -->
  <?php if (!empty($recentAlerts)): ?>
  <div class="card mt-1">
    <div class="card-header">
      <h2 class="card-title">Recent Alerts</h2>
      <a href="alerts.php" class="card-link">View all →</a>
    </div>
    <?php foreach ($recentAlerts as $al): ?>
    <div class="alert-row">
      <span class="alert-dot alert-dot-<?= $al['type']==='consecutive_absent'?'red':'amber' ?>"></span>
      <div class="alert-body">
        <strong><?= sanitize($al['student_name']) ?></strong>
        <span class="lv-tag lv<?= $al['level'] ?>-tag" style="margin:0 4px">L<?= $al['level'] ?></span>
        <?= sanitize($al['course'] ?? '') ?> — <?= sanitize($al['message']) ?>
      </div>
      <span class="alert-time"><?= date('d M', strtotime($al['created_at'])) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</main>
</body>
</html>