<?php
require_once 'includes/config.php';
requireLogin();
$db = db();

$classes     = $db->query('SELECT * FROM classes ORDER BY level,name')->fetchAll();
$reportType  = $_GET['report']   ?? '';
$filterLevel = intval($_GET['level']    ?? 0);
$classId     = intval($_GET['class_id'] ?? 0);
$from        = $_GET['from'] ?? date('Y-m-01');
$to          = $_GET['to']   ?? date('Y-m-d');
$results     = [];

// Student attendance summary across all their courses
if ($reportType === 'student' && $filterLevel) {
    $stmt = $db->prepare("
        SELECT s.roll_no, s.name, s.level,
               SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) AS present,
               SUM(CASE WHEN a.status='absent'  THEN 1 ELSE 0 END) AS absent,
               SUM(CASE WHEN a.status='late'    THEN 1 ELSE 0 END) AS late,
               COUNT(a.id) AS total
        FROM students s
        LEFT JOIN attendance a ON a.student_id=s.id AND a.date BETWEEN ? AND ?
        WHERE s.level=?
        GROUP BY s.id ORDER BY s.roll_no
    ");
    $stmt->execute([$from,$to,$filterLevel]);
    $results = $stmt->fetchAll();
}

// Per-course attendance summary
if ($reportType === 'course' && $classId) {
    $stmt = $db->prepare("
        SELECT s.roll_no, s.name,
               SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) AS present,
               SUM(CASE WHEN a.status='absent'  THEN 1 ELSE 0 END) AS absent,
               SUM(CASE WHEN a.status='late'    THEN 1 ELSE 0 END) AS late,
               COUNT(a.id) AS total
        FROM enrollments e
        JOIN students s ON s.id=e.student_id
        LEFT JOIN attendance a ON a.student_id=s.id AND a.class_id=e.class_id AND a.date BETWEEN ? AND ?
        WHERE e.class_id=?
        GROUP BY s.id ORDER BY s.roll_no
    ");
    $stmt->execute([$from,$to,$classId]);
    $results = $stmt->fetchAll();
}

// Level summary
if ($reportType === 'summary') {
    $stmt = $db->prepare("
        SELECT s.level,
               COUNT(DISTINCT s.id) AS students,
               SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) AS present,
               SUM(CASE WHEN a.status='absent'  THEN 1 ELSE 0 END) AS absent,
               COUNT(a.id) AS total
        FROM students s
        LEFT JOIN attendance a ON a.student_id=s.id AND a.date BETWEEN ? AND ?
        GROUP BY s.level ORDER BY s.level
    ");
    $stmt->execute([$from,$to]);
    $results = $stmt->fetchAll();
}

$byLevel = [3=>[],4=>[],5=>[]];
foreach ($classes as $c) $byLevel[$c['level']][] = $c;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reports — AttendIQ</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<main class="main-content">
  <div class="page-header">
    <div><h1 class="page-title">Reports</h1><p class="page-sub">Generate attendance reports by student, course or level.</p></div>
    <?php if (!empty($results)): ?>
    <button onclick="window.print()" class="btn-primary">🖨 Print</button>
    <?php endif; ?>
  </div>

  <form method="GET" class="card filter-form">
    <div class="form-row">
      <div class="field-group">
        <label>Report Type</label>
        <select name="report" required onchange="toggleFields(this.value)">
          <option value="">Select type</option>
          <option value="student" <?= $reportType==='student' ?'selected':'' ?>>Student Report (by Level)</option>
          <option value="course"  <?= $reportType==='course'  ?'selected':'' ?>>Course Report</option>
          <option value="summary" <?= $reportType==='summary' ?'selected':'' ?>>Overall Level Summary</option>
        </select>
      </div>

      <div class="field-group" id="field-level" style="<?= $reportType==='student'||$reportType==='summary'?'':'display:none' ?>">
        <label>Level</label>
        <select name="level">
          <option value="">All levels</option>
          <option value="3" <?= $filterLevel===3?'selected':'' ?>>Level 3</option>
          <option value="4" <?= $filterLevel===4?'selected':'' ?>>Level 4</option>
          <option value="5" <?= $filterLevel===5?'selected':'' ?>>Level 5</option>
        </select>
      </div>

      <div class="field-group" id="field-course" style="<?= $reportType==='course'?'':'display:none' ?>">
        <label>Course</label>
        <select name="class_id">
          <option value="">Select course</option>
          <?php foreach ([3,4,5] as $lv): if (!empty($byLevel[$lv])): ?>
          <optgroup label="Level <?= $lv ?>">
            <?php foreach ($byLevel[$lv] as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $classId==$c['id']?'selected':'' ?>><?= sanitize($c['name']) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endif; endforeach; ?>
        </select>
      </div>

      <div class="field-group">
        <label>From</label>
        <input type="date" name="from" value="<?= $from ?>">
      </div>
      <div class="field-group">
        <label>To</label>
        <input type="date" name="to" value="<?= $to ?>">
      </div>
      <div class="field-group field-align-end">
        <button type="submit" class="btn-primary">Generate</button>
      </div>
    </div>
  </form>

  <?php if (!empty($results)): ?>
  <div class="card print-area">
    <div class="card-header">
      <h2 class="card-title">
        <?php
        if ($reportType==='student') echo 'Level '.$filterLevel.' — Student Attendance Report';
        elseif ($reportType==='course') {
            foreach ($classes as $c) { if ($c['id']==$classId) echo sanitize($c['name']); }
        }
        elseif ($reportType==='summary') echo 'Overall Level Summary';
        ?>
        &nbsp;· <?= date('d M Y',strtotime($from)) ?> to <?= date('d M Y',strtotime($to)) ?>
      </h2>
    </div>
    <div class="table-wrap">
      <?php if ($reportType==='summary'): ?>
      <table class="data-table">
        <thead><tr><th>Level</th><th>Students</th><th>Present Sessions</th><th>Absent Sessions</th><th>Total Sessions</th><th>Avg %</th></tr></thead>
        <tbody>
          <?php foreach ($results as $r):
            $p = $r['total']>0 ? round(($r['present']/$r['total'])*100):0;
          ?>
          <tr>
            <td><span class="lv-tag lv<?= $r['level'] ?>-tag">Level <?= $r['level'] ?></span></td>
            <td><?= $r['students'] ?></td>
            <td class="ok"><?= $r['present'] ?></td>
            <td class="danger"><?= $r['absent'] ?></td>
            <td><?= $r['total'] ?></td>
            <td><span class="badge badge-<?= $p>=85?'green':($p>=70?'amber':'red') ?>"><?= $p ?>%</span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <table class="data-table">
        <thead><tr><th>Roll No</th><th>Name</th><?= $reportType==='student'?'<th>Level</th>':'' ?><th>Present</th><th>Absent</th><th>Late</th><th>Total</th><th>Attendance %</th></tr></thead>
        <tbody>
          <?php foreach ($results as $r):
            $p = $r['total']>0 ? round(($r['present']/$r['total'])*100):0;
          ?>
          <tr>
            <td class="td-mono"><?= sanitize($r['roll_no']) ?></td>
            <td><?= sanitize($r['name']) ?></td>
            <?php if ($reportType==='student'): ?>
            <td><span class="lv-tag lv<?= $r['level'] ?>-tag">L<?= $r['level'] ?></span></td>
            <?php endif; ?>
            <td class="ok"><?= $r['present'] ?></td>
            <td class="danger"><?= $r['absent'] ?></td>
            <td class="warn"><?= $r['late'] ?></td>
            <td><?= $r['total'] ?></td>
            <td><span class="badge badge-<?= $p>=85?'green':($p>=70?'amber':'red') ?>"><?= $p ?>%</span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</main>

<script>
function toggleFields(type) {
  document.getElementById('field-level').style.display  = (type==='student'||type==='summary') ? '' : 'none';
  document.getElementById('field-course').style.display = (type==='course') ? '' : 'none';
}
</script>
</body>
</html>