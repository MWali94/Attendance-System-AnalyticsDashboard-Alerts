<?php
require_once 'includes/config.php';
requireLogin();
$db = db();

$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: students.php'); exit; }

$student = $db->prepare('SELECT * FROM students WHERE id=?');
$student->execute([$id]);
$student = $student->fetch();
if (!$student) { header('Location: students.php'); exit; }

// All courses student is enrolled in
$courses = $db->prepare("
    SELECT c.id, c.name, c.level,
           SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) AS present,
           SUM(CASE WHEN a.status='absent'  THEN 1 ELSE 0 END) AS absent,
           SUM(CASE WHEN a.status='late'    THEN 1 ELSE 0 END) AS late,
           SUM(CASE WHEN a.status='excused' THEN 1 ELSE 0 END) AS excused,
           COUNT(a.id) AS total
    FROM enrollments e
    JOIN classes c ON c.id = e.class_id
    LEFT JOIN attendance a ON a.student_id = e.student_id AND a.class_id = c.id
    WHERE e.student_id = ?
    GROUP BY c.id
    ORDER BY c.id
");
$courses->execute([$id]);
$courseList = $courses->fetchAll();

// Overall totals across all courses
$totals = ['present'=>0,'absent'=>0,'late'=>0,'excused'=>0,'total'=>0];
foreach ($courseList as $c) {
    $totals['present'] += $c['present'];
    $totals['absent']  += $c['absent'];
    $totals['late']    += $c['late'];
    $totals['excused'] += $c['excused'];
    $totals['total']   += $c['total'];
}
$overallPct = $totals['total'] > 0 ? round(($totals['present'] / $totals['total']) * 100) : 0;

// Recent attendance records (all courses)
$history = $db->prepare("
    SELECT a.date, a.status, a.remarks, c.name AS course
    FROM attendance a
    JOIN classes c ON c.id = a.class_id
    WHERE a.student_id = ?
    ORDER BY a.date DESC, c.id
    LIMIT 60
");
$history->execute([$id]);
$records = $history->fetchAll();

// Selected course for filtering history
$filterCourse = intval($_GET['course_id'] ?? 0);
if ($filterCourse) {
    $records = array_filter($records, fn($r) => true); // re-fetch below
    $history = $db->prepare("
        SELECT a.date, a.status, a.remarks, c.name AS course
        FROM attendance a
        JOIN classes c ON c.id = a.class_id
        WHERE a.student_id=? AND a.class_id=?
        ORDER BY a.date DESC
    ");
    $history->execute([$id, $filterCourse]);
    $records = $history->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= sanitize($student['name']) ?> — AttendIQ</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>

<main class="main-content">
  <div class="page-header">
    <div>
      <a href="students.php" class="back-link">← Back to Students</a>
      <h1 class="page-title"><?= sanitize($student['name']) ?></h1>
      <p class="page-sub">
        <span class="lv-tag lv<?= $student['level'] ?>-tag">Level <?= $student['level'] ?></span>
        &nbsp;Roll No: <strong><?= sanitize($student['roll_no']) ?></strong>
        &nbsp;·&nbsp;Enrolled in <?= count($courseList) ?> courses
      </p>
    </div>
  </div>

  <div class="two-col">

    <!-- Student Info -->
    <div class="card">
      <div class="card-header"><h2 class="card-title">Student Information</h2></div>
      <div class="info-grid">
        <div class="info-row"><span>Roll No</span><strong><?= sanitize($student['roll_no']) ?></strong></div>
        <div class="info-row"><span>Level</span><strong><span class="lv-tag lv<?= $student['level'] ?>-tag">Level <?= $student['level'] ?></span></strong></div>
        <div class="info-row"><span>Date of Birth</span><strong><?= $student['dob'] ? date('d M Y',strtotime($student['dob'])) : '—' ?></strong></div>
        <div class="info-row"><span>Parent / Guardian</span><strong><?= sanitize($student['parent_name'] ?? '—') ?></strong></div>
        <div class="info-row"><span>Phone</span><strong><?= sanitize($student['parent_phone'] ?? '—') ?></strong></div>
        <div class="info-row"><span>Email</span><strong><?= sanitize($student['parent_email'] ?? '—') ?></strong></div>
        <?php if ($student['comments']): ?>
        <div class="info-row"><span>Comments</span><strong style="color:var(--warn)"><?= sanitize($student['comments']) ?></strong></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Overall Attendance -->
    <div class="card">
      <div class="card-header"><h2 class="card-title">Overall Attendance (All Courses)</h2></div>
      <div class="big-pct <?= $overallPct>=85?'pct-green':($overallPct>=70?'pct-amber':'pct-red') ?>"><?= $overallPct ?>%</div>
      <div class="stats-row">
        <div class="stat-box"><div class="stat-val ok"><?= $totals['present'] ?></div><div class="stat-lbl">Present</div></div>
        <div class="stat-box"><div class="stat-val danger"><?= $totals['absent'] ?></div><div class="stat-lbl">Absent</div></div>
        <div class="stat-box"><div class="stat-val warn"><?= $totals['late'] ?></div><div class="stat-lbl">Late</div></div>
        <div class="stat-box"><div class="stat-val"><?= $totals['excused'] ?></div><div class="stat-lbl">Excused</div></div>
      </div>
      <p style="text-align:center;font-size:12px;color:var(--text-3);margin-top:10px">Total <?= $totals['total'] ?> sessions across <?= count($courseList) ?> courses</p>
    </div>

  </div>

  <!-- Per-course attendance breakdown -->
  <div class="card">
    <div class="card-header"><h2 class="card-title">Attendance by Course</h2></div>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr><th>Course</th><th>Present</th><th>Absent</th><th>Late</th><th>Excused</th><th>Total</th><th>%</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($courseList as $c):
            $pct = $c['total'] > 0 ? round(($c['present'] / $c['total']) * 100) : 0;
          ?>
          <tr>
            <td><?= sanitize($c['name']) ?></td>
            <td class="ok"><?= $c['present'] ?></td>
            <td class="danger"><?= $c['absent'] ?></td>
            <td class="warn"><?= $c['late'] ?></td>
            <td><?= $c['excused'] ?></td>
            <td><?= $c['total'] ?></td>
            <td><span class="badge badge-<?= $pct>=85?'green':($pct>=70?'amber':'red') ?>"><?= $pct ?>%</span></td>
            <td><a href="?id=<?= $id ?>&course_id=<?= $c['id'] ?>" class="btn-xs">History</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Attendance history -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">
        Attendance History
        <?php if ($filterCourse):
          foreach ($courseList as $c) { if ($c['id']==$filterCourse) echo '— '.sanitize($c['name']); }
        endif; ?>
      </h2>
      <?php if ($filterCourse): ?>
      <a href="?id=<?= $id ?>" class="btn-xs">Show All Courses</a>
      <?php endif; ?>
    </div>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Date</th><th>Day</th><th>Course</th><th>Status</th><th>Remarks</th></tr></thead>
        <tbody>
          <?php foreach ($records as $r): ?>
          <tr>
            <td><?= date('d M Y',strtotime($r['date'])) ?></td>
            <td><?= date('l',strtotime($r['date'])) ?></td>
            <td style="font-size:13px"><?= sanitize($r['course']) ?></td>
            <td>
              <span class="badge badge-<?= match($r['status']){'present'=>'green','absent'=>'red','late'=>'amber',default=>'blue'} ?>">
                <?= ucfirst($r['status']) ?>
              </span>
            </td>
            <td><?= sanitize($r['remarks'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($records)): ?>
          <tr><td colspan="5" class="empty-msg">No attendance records yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</main>
</body>
</html>