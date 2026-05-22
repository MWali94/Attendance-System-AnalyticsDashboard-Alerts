<?php
require_once 'includes/config.php';
requireLogin();
$user = currentUser();
$db   = db();

$classes       = $db->query('SELECT * FROM classes ORDER BY level, name')->fetchAll();
$selectedClass = intval($_GET['class_id'] ?? 0);
$selectedDate  = $_GET['date'] ?? date('Y-m-d');
$students      = [];
$existing      = [];

if ($selectedClass) {
    // Only show students ENROLLED in this course
    $stmt = $db->prepare("
        SELECT s.* FROM students s
        JOIN enrollments e ON e.student_id = s.id
        WHERE e.class_id = ?
        ORDER BY s.roll_no
    ");
    $stmt->execute([$selectedClass]);
    $students = $stmt->fetchAll();

    $stmt2 = $db->prepare('SELECT student_id, status FROM attendance WHERE class_id=? AND date=?');
    $stmt2->execute([$selectedClass, $selectedDate]);
    foreach ($stmt2->fetchAll() as $r) $existing[$r['student_id']] = $r['status'];
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_attendance'])) {
    $classId  = intval($_POST['class_id']);
    $date     = $_POST['date'];
    $statuses = $_POST['status'] ?? [];
    $db->beginTransaction();
    try {
        foreach ($statuses as $sid => $status) {
            $sid = intval($sid);
            if (!in_array($status,['present','absent','late','excused'])) continue;
            $chk = $db->prepare('SELECT id FROM attendance WHERE student_id=? AND class_id=? AND date=?');
            $chk->execute([$sid,$classId,$date]);
            if ($chk->fetch()) {
                $db->prepare('UPDATE attendance SET status=?,marked_by=? WHERE student_id=? AND class_id=? AND date=?')
                   ->execute([$status,$user['id'],$sid,$classId,$date]);
            } else {
                $db->prepare('INSERT INTO attendance (student_id,class_id,date,status,marked_by) VALUES(?,?,?,?,?)')
                   ->execute([$sid,$classId,$date,$status,$user['id']]);
            }
        }

        // Auto-alert: 3+ absences in this course in last 5 days
        foreach (array_keys($statuses) as $sid) {
            $sid = intval($sid);
            $c = $db->prepare("SELECT COUNT(*) FROM attendance
                               WHERE student_id=? AND class_id=? AND status='absent'
                               AND date > DATE_SUB(?,INTERVAL 5 DAY) AND date<=?");
            $c->execute([$sid,$classId,$date,$date]);
            if ($c->fetchColumn()>=3) {
                $ex = $db->prepare("SELECT id FROM alerts WHERE student_id=? AND class_id=?
                                    AND type='consecutive_absent' AND DATE(created_at)=?");
                $ex->execute([$sid,$classId,$date]);
                if (!$ex->fetch()) {
                    $db->prepare("INSERT INTO alerts (student_id,class_id,type,message) VALUES(?,?,?,?)")
                       ->execute([$sid,$classId,'consecutive_absent',
                                  '3 or more consecutive absences detected in this course. Parent notification recommended.']);
                }
            }
        }
        $db->commit();
        flashSet('success','Attendance saved for '.date('d M Y',strtotime($date)).'.');
        header("Location: attendance.php?class_id=$classId&date=$date"); exit;
    } catch (Exception $e) {
        $db->rollBack();
        flashSet('danger','Error: '.$e->getMessage());
        header("Location: attendance.php?class_id=$classId&date=$date"); exit;
    }
}

// Group by level for dropdown
$byLevel = [3=>[],4=>[],5=>[]];
foreach ($classes as $c) $byLevel[$c['level']][] = $c;

// Selected class info
$selectedCls = null;
foreach ($classes as $c) { if ($c['id']==$selectedClass) { $selectedCls=$c; break; } }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mark Attendance — AttendIQ</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>

<main class="main-content">
  <div class="page-header">
    <div>
      <h1 class="page-title">Mark Attendance</h1>
      <p class="page-sub">Select a course and date to record attendance.</p>
    </div>
  </div>

  <form method="GET" class="filter-form card">
    <div class="form-row">
      <div class="field-group" style="flex:2">
        <label>Course</label>
        <select name="class_id" required>
          <option value="">— Select course —</option>
          <?php foreach ([3,4,5] as $lv): if (!empty($byLevel[$lv])): ?>
          <optgroup label="Level <?= $lv ?> — <?= $lv===3?'Information & Digital Technologies':'Computing' ?>">
            <?php foreach ($byLevel[$lv] as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $selectedClass==$c['id']?'selected':'' ?>><?= sanitize($c['name']) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endif; endforeach; ?>
        </select>
      </div>
      <div class="field-group">
        <label>Date</label>
        <input type="date" name="date" value="<?= $selectedDate ?>" max="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="field-group field-align-end">
        <button type="submit" class="btn-primary">Load Students</button>
      </div>
    </div>
  </form>

  <?php if ($selectedClass && !empty($students)): ?>
  <form method="POST">
    <input type="hidden" name="class_id" value="<?= $selectedClass ?>">
    <input type="hidden" name="date"     value="<?= $selectedDate ?>">
    <input type="hidden" name="save_attendance" value="1">

    <div class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title"><?= sanitize($selectedCls['name'] ?? '') ?></h2>
          <p style="font-size:13px;color:var(--text-2);margin-top:3px">
            <span class="lv-tag lv<?= $selectedCls['level'] ?? 3 ?>-tag">Level <?= $selectedCls['level'] ?? '' ?></span>
            &nbsp;<?= date('l, d M Y',strtotime($selectedDate)) ?>
            &nbsp;·&nbsp;<?= count($students) ?> students enrolled
          </p>
        </div>
        <div class="bulk-actions">
          <button type="button" onclick="markAll('present')" class="btn-sm btn-sm-green">✓ All Present</button>
          <button type="button" onclick="markAll('absent')"  class="btn-sm btn-sm-red">✗ All Absent</button>
        </div>
      </div>

      <div class="attendance-table-wrap">
        <table class="att-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Roll No</th>
              <th>Student Name</th>
              <th class="status-col">Present</th>
              <th class="status-col">Absent</th>
              <th class="status-col">Late</th>
              <th class="status-col">Excused</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($students as $i => $s):
              $cur = $existing[$s['id']] ?? 'present';
            ?>
            <tr>
              <td class="td-num"><?= $i+1 ?></td>
              <td class="td-mono"><?= sanitize($s['roll_no']) ?></td>
              <td class="td-name">
                <?= sanitize($s['name']) ?>
                <?php if ($s['comments']): ?>
                <span title="<?= sanitize($s['comments']) ?>" style="color:var(--warn);cursor:help"> ⚠</span>
                <?php endif; ?>
              </td>
              <?php foreach (['present','absent','late','excused'] as $st): ?>
              <td class="status-col">
                <label class="radio-label radio-<?= $st ?>">
                  <input type="radio" name="status[<?= $s['id'] ?>]" value="<?= $st ?>" <?= $cur===$st?'checked':'' ?>>
                  <span class="radio-dot"></span>
                </label>
              </td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card-footer">
        <button type="submit" class="btn-primary">💾 Save Attendance</button>
        <span class="footer-hint"><?= count($students) ?> students · <?= date('d M Y',strtotime($selectedDate)) ?></span>
      </div>
    </div>
  </form>

  <?php elseif ($selectedClass): ?>
  <div class="card"><p class="empty-msg">No students enrolled in this course yet. Add students first.</p></div>
  <?php endif; ?>
</main>

<script>
function markAll(s) {
  document.querySelectorAll('input[type=radio][value="'+s+'"]').forEach(r=>r.checked=true);
}
</script>
</body>
</html>