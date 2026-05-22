<?php
require_once 'includes/config.php';
// NO requireLogin() — public page

$db = db();

$reportType = $_GET['report'] ?? 'student';
$search     = trim($_GET['search']   ?? '');
$classId    = intval($_GET['class_id'] ?? 0);
$from       = $_GET['from'] ?? date('Y-m-01');
$to         = $_GET['to']   ?? date('Y-m-d');

$studentInfo = null;
$courseList  = [];
$results     = [];
$searchError = '';

$classes = $db->query('SELECT * FROM classes ORDER BY level, name')->fetchAll();
$byLevel = [3=>[],4=>[],5=>[]];
foreach ($classes as $c) $byLevel[$c['level']][] = $c;

// ── My Attendance: search by name OR roll number ─────────────
if ($reportType === 'student' && $search) {
    $stmt = $db->prepare('SELECT * FROM students
                          WHERE roll_no = ? OR name LIKE ?
                          LIMIT 1');
    $stmt->execute([strtoupper($search), '%'.$search.'%']);
    $studentInfo = $stmt->fetch();

    if ($studentInfo) {
        $stmt2 = $db->prepare("
            SELECT c.id, c.name, c.level,
                   SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) AS present,
                   SUM(CASE WHEN a.status='absent'  THEN 1 ELSE 0 END) AS absent,
                   SUM(CASE WHEN a.status='late'    THEN 1 ELSE 0 END) AS late,
                   SUM(CASE WHEN a.status='excused' THEN 1 ELSE 0 END) AS excused,
                   COUNT(a.id) AS total
            FROM enrollments e
            JOIN classes c ON c.id = e.class_id
            LEFT JOIN attendance a ON a.student_id = e.student_id
                                   AND a.class_id  = c.id
                                   AND a.date BETWEEN ? AND ?
            WHERE e.student_id = ?
            GROUP BY c.id ORDER BY c.id
        ");
        $stmt2->execute([$from, $to, $studentInfo['id']]);
        $courseList = $stmt2->fetchAll();
    } else {
        $searchError = 'No student found for "<strong>'.sanitize($search).'</strong>". Please check the name or roll number and try again.';
    }
}

// ── Course Report ────────────────────────────────────────────
if ($reportType === 'course' && $classId) {
    $stmt = $db->prepare("
        SELECT s.roll_no, s.name,
               SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) AS present,
               SUM(CASE WHEN a.status='absent'  THEN 1 ELSE 0 END) AS absent,
               SUM(CASE WHEN a.status='late'    THEN 1 ELSE 0 END) AS late,
               SUM(CASE WHEN a.status='excused' THEN 1 ELSE 0 END) AS excused,
               COUNT(a.id) AS total
        FROM enrollments e
        JOIN students s ON s.id = e.student_id
        LEFT JOIN attendance a ON a.student_id = s.id
                               AND a.class_id  = e.class_id
                               AND a.date BETWEEN ? AND ?
        WHERE e.class_id = ?
        GROUP BY s.id ORDER BY s.roll_no
    ");
    $stmt->execute([$from, $to, $classId]);
    $results = $stmt->fetchAll();
}

// Overall totals for student report
$totals = ['present'=>0,'absent'=>0,'late'=>0,'excused'=>0,'total'=>0];
foreach ($courseList as $c) {
    $totals['present'] += $c['present'];
    $totals['absent']  += $c['absent'];
    $totals['late']    += $c['late'];
    $totals['excused'] += $c['excused'];
    $totals['total']   += $c['total'];
}
$overallPct = $totals['total'] > 0 ? round(($totals['present'] / $totals['total']) * 100) : 0;

// Selected course name
$selectedCourseName = '';
foreach ($classes as $c) { if ($c['id'] === $classId) $selectedCourseName = $c['name']; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Attendance Reports — AttendIQ</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<style>
/* ── Public navbar ─────────────────────────────────────────── */
.pub-nav {
  background:var(--text); color:#fff;
  display:flex; align-items:center; justify-content:space-between;
  padding:0 2rem; height:56px; position:sticky; top:0; z-index:100;
}
.pub-nav-brand { display:flex; align-items:center; gap:8px; }
.pub-nav-brand .brand-name { font-family:'DM Serif Display',serif; font-size:18px; }
.pub-nav-back {
  font-size:13px; color:rgba(255,255,255,.8);
  padding:7px 16px; border-radius:6px;
  border:1px solid rgba(255,255,255,.2); transition:all .15s;
}
.pub-nav-back:hover { background:rgba(255,255,255,.1); color:#fff; }

/* ── Page header ───────────────────────────────────────────── */
.pub-header { background:var(--surface); border-bottom:1px solid var(--border); padding:2rem; text-align:center; }
.pub-header h1 { font-family:'DM Serif Display',serif; font-size:30px; font-weight:400; margin-bottom:.4rem; }
.pub-header p  { font-size:14px; color:var(--text-2); }

/* ── Tabs ──────────────────────────────────────────────────── */
.pub-tabs { display:flex; gap:6px; justify-content:center; padding:1.5rem 0 0; }
.pub-tab  {
  padding:9px 24px; border-radius:99px; font-size:14px; font-weight:500;
  border:1.5px solid var(--border-md); background:transparent;
  color:var(--text-2); cursor:pointer; transition:all .15s;
  text-decoration:none; display:inline-block;
}
.pub-tab:hover  { border-color:var(--text); color:var(--text); }
.pub-tab.active { background:var(--text); color:#fff; border-color:var(--text); }

/* ── Content area ──────────────────────────────────────────── */
.pub-content { max-width:800px; margin:0 auto; padding:2rem 1.5rem; }

/* ── Search form ───────────────────────────────────────────── */
.search-hint { font-size:13px; color:var(--text-2); margin-bottom:1.25rem; }
.search-hint span { background:var(--bg); padding:2px 8px; border-radius:4px; font-family:monospace; font-size:12px; }

/* ── Student result card ───────────────────────────────────── */
.student-card {
  display:flex; align-items:center; gap:16px;
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--radius-lg); padding:1.25rem 1.5rem;
  margin-bottom:1.25rem; box-shadow:var(--shadow);
}
.student-avatar {
  width:52px; height:52px; border-radius:50%;
  background:var(--blue); color:#fff;
  display:flex; align-items:center; justify-content:center;
  font-size:20px; font-weight:500; flex-shrink:0;
}
.student-meta-row { font-size:13px; color:var(--text-2); margin-top:4px; display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
.student-comment  { font-size:12px; color:var(--amber); margin-top:5px; }

/* ── Overall stats row ─────────────────────────────────────── */
.overall-stats { display:grid; grid-template-columns:repeat(5,1fr); gap:10px; margin-bottom:1.25rem; }
.stat-pill { text-align:center; padding:14px 10px; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); box-shadow:var(--shadow); }
.stat-pill .val { font-family:'DM Serif Display',serif; font-size:26px; line-height:1; }
.stat-pill .lbl { font-size:11.5px; color:var(--text-3); margin-top:4px; }
@media(max-width:500px){ .overall-stats { grid-template-columns:repeat(3,1fr); } }

/* ── Attendance warning ────────────────────────────────────── */
.att-warning { padding:12px 16px; border-radius:var(--radius); margin-bottom:1.25rem; font-size:13.5px; border-left:4px solid; }
.warn-red    { background:var(--red-lt);   border-color:var(--red);   color:#7A1F1A; }
.warn-amber  { background:var(--amber-lt); border-color:var(--amber); color:#6B3A0A; }

/* ── Progress bar inline ───────────────────────────────────── */
.inline-bar { display:flex; align-items:center; gap:8px; }
.bar-mini-track { width:70px; height:6px; background:var(--border); border-radius:99px; overflow:hidden; flex-shrink:0; }
.bar-mini-fill  { height:100%; border-radius:99px; }

/* ── Error state ───────────────────────────────────────────── */
.not-found { text-align:center; padding:3rem 2rem; color:var(--text-2); }
.not-found .icon { font-size:44px; margin-bottom:1rem; }
.not-found h3 { font-family:'DM Serif Display',serif; font-size:20px; margin-bottom:.5rem; color:var(--text); }

/* ── Footer ────────────────────────────────────────────────── */
.pub-footer { text-align:center; padding:2rem; font-size:12.5px; color:var(--text-3); border-top:1px solid var(--border); margin-top:2rem; }
.pub-footer a { color:var(--green); }

/* ── Print ─────────────────────────────────────────────────── */
@media print {
  .pub-nav, .pub-tabs, .filter-form, .pub-header p, .btn-xs { display:none!important; }
  .pub-content { padding:0; }
}
</style>
</head>
<body>

<!-- Navbar -->
<nav class="pub-nav">
  <div class="pub-nav-brand">
    <span class="brand-icon">◈</span>
    <span class="brand-name">AttendIQ</span>
  </div>
  <a href="index.php" class="pub-nav-back">← Staff Login</a>
</nav>

<!-- Header -->
<div class="pub-header">
  <h1>Attendance Reports</h1>
  <p>Students and parents can view attendance records without an account.</p>

  <!-- Tabs -->
  <div class="pub-tabs">
    <a href="?report=student&from=<?= $from ?>&to=<?= $to ?>"
       class="pub-tab <?= $reportType==='student'?'active':'' ?>">🎓 My Attendance</a>
    <a href="?report=course&from=<?= $from ?>&to=<?= $to ?>"
       class="pub-tab <?= $reportType==='course'?'active':'' ?>">📋 Course Report</a>
  </div>
</div>

<div class="pub-content">

<?php if ($reportType === 'student'): ?>
<!-- ═══════════════════════════════════════════════════════════
     TAB 1 — MY ATTENDANCE
     Search by name or roll number
     ═══════════════════════════════════════════════════════════ -->

  <form method="GET" class="card" style="margin-bottom:1.25rem">
    <input type="hidden" name="report" value="student">
    <p class="search-hint">
      Search by your <strong>name</strong> or <strong>roll number</strong>
      (e.g. <span>L3-001</span> or <span>Muhammad Bilal</span>)
    </p>
    <div class="form-row">
      <div class="field-group" style="flex:3">
        <label>Name or Roll Number</label>
        <input type="text" name="search"
               placeholder="Enter your name or roll no..."
               value="<?= sanitize($search) ?>"
               autofocus>
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
        <button type="submit" class="btn-primary">Search</button>
      </div>
    </div>
  </form>

  <?php if ($search && $searchError): ?>
  <!-- Not found -->
  <div class="not-found">
    <div class="icon">🔍</div>
    <h3>No student found</h3>
    <p><?= $searchError ?></p>
  </div>

  <?php elseif ($studentInfo): ?>
  <!-- Student found — show info card -->
  <div class="student-card">
    <div class="student-avatar"><?= strtoupper(substr($studentInfo['name'],0,2)) ?></div>
    <div style="flex:1;min-width:0">
      <div style="font-size:17px;font-weight:500"><?= sanitize($studentInfo['name']) ?></div>
      <div class="student-meta-row">
        <span class="lv-tag lv<?= $studentInfo['level'] ?>-tag">Level <?= $studentInfo['level'] ?></span>
        <span>Roll No: <strong><?= sanitize($studentInfo['roll_no']) ?></strong></span>
        <span>Enrolled in <strong><?= count($courseList) ?></strong> courses</span>
      </div>
      <?php if ($studentInfo['comments']): ?>
      <div class="student-comment">⚠ <?= sanitize($studentInfo['comments']) ?></div>
      <?php endif; ?>
    </div>
    <button onclick="window.print()" class="btn-xs" style="flex-shrink:0">🖨 Print</button>
  </div>

  <!-- Attendance warning -->
  <?php if ($totals['total'] > 0 && $overallPct < 75): ?>
  <div class="att-warning warn-red">
    ⚠ <strong>Critical:</strong> Your overall attendance is <strong><?= $overallPct ?>%</strong> — below 75%. Please contact your lecturer or academic coordinator immediately.
  </div>
  <?php elseif ($totals['total'] > 0 && $overallPct < 85): ?>
  <div class="att-warning warn-amber">
    ⚠ Your overall attendance is <strong><?= $overallPct ?>%</strong> — below 85%. Regular attendance is required to avoid academic penalties.
  </div>
  <?php endif; ?>

  <!-- Overall stats -->
  <div class="overall-stats">
    <div class="stat-pill">
      <div class="val <?= $overallPct>=85?'ok':($overallPct>=70?'warn':'danger') ?>"><?= $overallPct ?>%</div>
      <div class="lbl">Overall</div>
    </div>
    <div class="stat-pill">
      <div class="val ok"><?= $totals['present'] ?></div>
      <div class="lbl">Present</div>
    </div>
    <div class="stat-pill">
      <div class="val danger"><?= $totals['absent'] ?></div>
      <div class="lbl">Absent</div>
    </div>
    <div class="stat-pill">
      <div class="val warn"><?= $totals['late'] ?></div>
      <div class="lbl">Late</div>
    </div>
    <div class="stat-pill">
      <div class="val"><?= $totals['total'] ?></div>
      <div class="lbl">Sessions</div>
    </div>
  </div>

  <!-- Per-course breakdown -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">Attendance by Course</h2>
      <span style="font-size:12px;color:var(--text-3)"><?= date('d M Y',strtotime($from)) ?> — <?= date('d M Y',strtotime($to)) ?></span>
    </div>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Course</th>
            <th>Present</th>
            <th>Absent</th>
            <th>Late</th>
            <th>Total</th>
            <th>%</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($courseList as $i => $c):
            $pct   = $c['total'] > 0 ? round(($c['present'] / $c['total']) * 100) : 0;
            $color = $pct>=85 ? 'var(--green)' : ($pct>=70 ? 'var(--amber)' : 'var(--red)');
          ?>
          <tr>
            <td class="td-num"><?= $i+1 ?></td>
            <td style="font-size:13px"><?= sanitize($c['name']) ?></td>
            <td class="ok"><?= $c['present'] ?></td>
            <td class="danger"><?= $c['absent'] ?></td>
            <td class="warn"><?= $c['late'] ?></td>
            <td><?= $c['total'] ?></td>
            <td>
              <div class="inline-bar">
                <div class="bar-mini-track">
                  <div class="bar-mini-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
                </div>
                <span style="font-size:13px;font-weight:500;color:<?= $color ?>"><?= $pct ?>%</span>
              </div>
            </td>
            <td>
              <?php if ($c['total'] === 0): ?>
                <span class="badge" style="background:var(--bg);color:var(--text-3)">No data</span>
              <?php elseif ($pct >= 85): ?>
                <span class="badge badge-green">Good</span>
              <?php elseif ($pct >= 70): ?>
                <span class="badge badge-amber">Warning</span>
              <?php else: ?>
                <span class="badge badge-red">At Risk</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($courseList)): ?>
          <tr><td colspan="8" class="empty-msg">No attendance records found for this date range.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php endif; ?>
  <!-- end student tab -->

<?php elseif ($reportType === 'course'): ?>
<!-- ═══════════════════════════════════════════════════════════
     TAB 2 — COURSE REPORT
     ═══════════════════════════════════════════════════════════ -->

  <form method="GET" class="card" style="margin-bottom:1.25rem">
    <input type="hidden" name="report" value="course">
    <div class="form-row">
      <div class="field-group" style="flex:2">
        <label>Select Course</label>
        <select name="class_id" required>
          <option value="">— Select a course —</option>
          <?php foreach ([3,4,5] as $lv): if (!empty($byLevel[$lv])): ?>
          <optgroup label="Level <?= $lv ?> — <?= $lv===3?'Information & Digital Technologies':'Computing' ?>">
            <?php foreach ($byLevel[$lv] as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $classId===$c['id']?'selected':'' ?>>
              <?= sanitize($c['name']) ?>
            </option>
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

  <?php if ($classId && !empty($results)): ?>
  <div class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title"><?= sanitize($selectedCourseName) ?></h2>
        <p style="font-size:12px;color:var(--text-3);margin-top:3px">
          <?= date('d M Y',strtotime($from)) ?> — <?= date('d M Y',strtotime($to)) ?>
          · <?= count($results) ?> students
        </p>
      </div>
      <button onclick="window.print()" class="btn-xs">🖨 Print</button>
    </div>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Roll No</th>
            <th>Name</th>
            <th>Present</th>
            <th>Absent</th>
            <th>Late</th>
            <th>Excused</th>
            <th>Total</th>
            <th>%</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($results as $i => $r):
            $pct   = $r['total'] > 0 ? round(($r['present'] / $r['total']) * 100) : 0;
            $color = $pct>=85 ? 'var(--green)' : ($pct>=70 ? 'var(--amber)' : 'var(--red)');
          ?>
          <tr>
            <td class="td-num"><?= $i+1 ?></td>
            <td class="td-mono"><?= sanitize($r['roll_no']) ?></td>
            <td><?= sanitize($r['name']) ?></td>
            <td class="ok"><?= $r['present'] ?></td>
            <td class="danger"><?= $r['absent'] ?></td>
            <td class="warn"><?= $r['late'] ?></td>
            <td><?= $r['excused'] ?></td>
            <td><?= $r['total'] ?></td>
            <td>
              <div class="inline-bar">
                <div class="bar-mini-track">
                  <div class="bar-mini-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
                </div>
                <span style="font-size:13px;font-weight:500;color:<?= $color ?>"><?= $pct ?>%</span>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php elseif ($classId && empty($results)): ?>
  <div class="not-found">
    <div class="icon">📋</div>
    <h3>No records found</h3>
    <p>No attendance has been recorded for this course in the selected date range.</p>
  </div>
  <?php endif; ?>

<?php endif; ?>

</div><!-- end pub-content -->

<div class="pub-footer">
  AttendIQ · Developed by ISLB-IT department ·
  <a href="index.php">Staff Login</a>
</div>

</body>
</html>
