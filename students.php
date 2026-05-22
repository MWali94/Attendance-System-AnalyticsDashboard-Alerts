<?php
require_once 'includes/config.php';
requireLogin();
$user = currentUser();
$db   = db();

// ── ADD student ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_student'])) {
    $roll    = trim($_POST['roll_no']);
    $name    = trim($_POST['name']);
    $level   = intval($_POST['level']);
    $pname   = trim($_POST['parent_name']);
    $pphone  = trim($_POST['parent_phone']);
    $pemail  = trim($_POST['parent_email']);
    $dob     = $_POST['dob'] ?? '';
    $comment = trim($_POST['comments']);
    try {
        $db->prepare('INSERT INTO students
                      (roll_no, name, level, parent_name, parent_phone, parent_email, dob, comments)
                      VALUES (?,?,?,?,?,?,?,?)')
           ->execute([$roll, $name, $level, $pname, $pphone, $pemail, $dob ?: null, $comment]);
        $newId = $db->lastInsertId();
        // Auto-enroll into ALL courses of their level
        $db->prepare("INSERT IGNORE INTO enrollments (student_id, class_id)
                      SELECT ?, id FROM classes WHERE level = ?")
           ->execute([$newId, $level]);
        flashSet('success', "Student added and auto-enrolled in all Level $level courses.");
    } catch (Exception $e) {
        flashSet('danger', 'Error: ' . $e->getMessage());
    }
    header('Location: students.php'); exit;
}

// ── EDIT student (admin only) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['edit_student']) && isAdmin()) {
    $id      = intval($_POST['student_id']);
    $level   = intval($_POST['level']);
    try {
        $old = $db->prepare('SELECT level FROM students WHERE id=?');
        $old->execute([$id]);
        $oldLevel = intval($old->fetchColumn());

        $db->prepare('UPDATE students
                      SET roll_no=?, name=?, level=?, parent_name=?,
                          parent_phone=?, parent_email=?, dob=?, comments=?
                      WHERE id=?')
           ->execute([
               trim($_POST['roll_no']),
               trim($_POST['name']),
               $level,
               trim($_POST['parent_name']),
               trim($_POST['parent_phone']),
               trim($_POST['parent_email']),
               $_POST['dob'] ?: null,
               trim($_POST['comments']),
               $id
           ]);

        // If level changed — remove old enrollments and re-enroll in new level courses
        if ($oldLevel !== $level) {
            $db->prepare('DELETE FROM enrollments WHERE student_id=?')->execute([$id]);
            $db->prepare("INSERT IGNORE INTO enrollments (student_id, class_id)
                          SELECT ?, id FROM classes WHERE level = ?")
               ->execute([$id, $level]);
            flashSet('success', 'Student updated. Level changed — re-enrolled in Level ' . $level . ' courses.');
        } else {
            flashSet('success', 'Student updated successfully.');
        }
    } catch (Exception $e) {
        flashSet('danger', 'Error: ' . $e->getMessage());
    }
    header('Location: students.php'); exit;
}

// ── DELETE student (admin only) ──────────────────────────────
if (isset($_GET['delete']) && isAdmin()) {
    $db->prepare('DELETE FROM students WHERE id=?')->execute([intval($_GET['delete'])]);
    flashSet('success', 'Student deleted.');
    header('Location: students.php'); exit;
}

// ── Filters ──────────────────────────────────────────────────
$search      = trim($_GET['search']  ?? '');
$filterLevel = intval($_GET['level'] ?? 0);

// ── Query — matches new DB: students has level, no class_id ──
$sql    = "SELECT s.*,
           (SELECT COUNT(*) FROM enrollments e
            WHERE e.student_id = s.id) AS courses_enrolled,
           (SELECT COUNT(*) FROM attendance a
            WHERE a.student_id = s.id
            AND a.status = 'absent'
            AND DATE_FORMAT(a.date,'%Y-%m') = ?) AS absences_month
           FROM students s
           WHERE 1=1";
$params = [date('Y-m')];

if ($search) {
    $sql    .= ' AND (s.name LIKE ? OR s.roll_no LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filterLevel) {
    $sql    .= ' AND s.level = ?';
    $params[] = $filterLevel;
}
$sql .= ' ORDER BY s.level, s.roll_no';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Count per level for tabs
$lvCount  = [3 => 0, 4 => 0, 5 => 0];
$allCount = $db->query('SELECT level, COUNT(*) AS cnt FROM students GROUP BY level')->fetchAll();
foreach ($allCount as $r) $lvCount[$r['level']] = $r['cnt'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Students — AttendIQ</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>

<main class="main-content">
  <div class="page-header">
    <div>
      <h1 class="page-title">Students</h1>
      <p class="page-sub">
        <?= count($students) ?> students found ·
        each auto-enrolled in all courses of their level
      </p>
    </div>
    <button onclick="openModal('add-modal')" class="btn-primary">+ Add Student</button>
  </div>

  <!-- Level filter tabs -->
  <div class="level-tabs">
    <a href="students.php"
       class="level-tab <?= !$filterLevel ? 'tab-active' : '' ?>">
       All <span class="tab-pill"><?= array_sum($lvCount) ?></span>
    </a>
    <a href="students.php?level=3"
       class="level-tab tab-lv3 <?= $filterLevel===3 ? 'tab-active' : '' ?>">
       Level 3 <span class="tab-pill"><?= $lvCount[3] ?></span>
    </a>
    <a href="students.php?level=4"
       class="level-tab tab-lv4 <?= $filterLevel===4 ? 'tab-active' : '' ?>">
       Level 4 <span class="tab-pill"><?= $lvCount[4] ?></span>
    </a>
    <a href="students.php?level=5"
       class="level-tab tab-lv5 <?= $filterLevel===5 ? 'tab-active' : '' ?>">
       Level 5 <span class="tab-pill"><?= $lvCount[5] ?></span>
    </a>
  </div>

  <!-- Search -->
  <form method="GET" class="filter-form card">
    <div class="form-row">
      <div class="field-group" style="flex:3">
        <label>Search</label>
        <input type="text" name="search"
               placeholder="Search by name or roll number..."
               value="<?= sanitize($search) ?>">
      </div>
      <?php if ($filterLevel): ?>
      <input type="hidden" name="level" value="<?= $filterLevel ?>">
      <?php endif; ?>
      <div class="field-group field-align-end">
        <button type="submit" class="btn-primary">Search</button>
        <a href="students.php" class="btn-outline">Reset</a>
      </div>
    </div>
  </form>

  <!-- Students table -->
  <div class="card">
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Roll No</th>
            <th>Name</th>
            <th>Level</th>
            <th>Enrolled In</th>
            <th>Parent / Guardian</th>
            <th>Phone</th>
            <th>Absent (month)</th>
            <th>Comments</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($students as $s): ?>
          <tr>
            <td class="td-mono"><?= sanitize($s['roll_no']) ?></td>
            <td>
              <div class="inline-student">
                <div class="avatar-sm"><?= strtoupper(substr($s['name'], 0, 2)) ?></div>
                <?= sanitize($s['name']) ?>
              </div>
            </td>
            <td>
              <span class="lv-tag lv<?= $s['level'] ?>-tag">Level <?= $s['level'] ?></span>
            </td>
            <td>
              <span class="badge badge-blue"><?= $s['courses_enrolled'] ?> courses</span>
            </td>
            <td><?= sanitize($s['parent_name'] ?? '—') ?></td>
            <td class="td-mono"><?= sanitize($s['parent_phone'] ?? '—') ?></td>
            <td>
              <?php $abs = intval($s['absences_month']); ?>
              <span class="badge badge-<?= $abs>=7 ? 'red' : ($abs>=4 ? 'amber' : 'green') ?>">
                <?= $abs ?>
              </span>
            </td>
            <td style="font-size:12px;color:var(--text-2);max-width:160px">
              <?= sanitize($s['comments'] ?? '') ?>
            </td>
            <td>
              <div class="action-group">
                <a href="student_detail.php?id=<?= $s['id'] ?>" class="btn-xs">View</a>
                <?php if (isAdmin()): ?>
                <button class="btn-xs btn-xs-edit"
                  onclick="openEditModal(<?= htmlspecialchars(json_encode($s), ENT_QUOTES) ?>)">
                  Edit
                </button>
                <a href="students.php?delete=<?= $s['id'] ?>"
                   class="btn-xs btn-xs-danger"
                   onclick="return confirm('Delete \'<?= addslashes(sanitize($s['name'])) ?>\'?\nThis also removes all their attendance records.')">
                   Delete
                </a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($students)): ?>
          <tr>
            <td colspan="9" class="empty-msg">No students found.</td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<!-- ── ADD MODAL ─────────────────────────────────────────── -->
<div id="add-modal" class="modal-overlay" style="display:none">
  <div class="modal-box">
    <div class="modal-header">
      <h2>Add New Student</h2>
      <button onclick="closeModal('add-modal')" class="modal-close">✕</button>
    </div>
    <p class="modal-note">
      Student will be <strong>automatically enrolled</strong> in all courses of their selected level.
      <br>Level 3 = 7 courses · Level 4 = 11 courses · Level 5 = 8 courses
    </p>
    <form method="POST">
      <input type="hidden" name="add_student" value="1">
      <div class="form-grid-2">
        <div class="field-group">
          <label>Roll No *</label>
          <input name="roll_no" required placeholder="L3-013 / L4-014 / L5-009">
        </div>
        <div class="field-group">
          <label>Full Name *</label>
          <input name="name" required placeholder="Student full name">
        </div>
        <div class="field-group">
          <label>Level *</label>
          <select name="level" required>
            <option value="">Select level</option>
            <option value="3">Level 3 — 7 courses</option>
            <option value="4">Level 4 — 11 courses</option>
            <option value="5">Level 5 — 8 courses</option>
          </select>
        </div>
        <div class="field-group">
          <label>Date of Birth</label>
          <input type="date" name="dob">
        </div>
        <div class="field-group">
          <label>Parent / Guardian Name</label>
          <input name="parent_name" placeholder="Full name">
        </div>
        <div class="field-group">
          <label>Parent Phone</label>
          <input name="parent_phone" placeholder="03001234567">
        </div>
        <div class="field-group" style="grid-column:span 2">
          <label>Parent Email</label>
          <input type="email" name="parent_email" placeholder="parent@email.com">
        </div>
        <div class="field-group" style="grid-column:span 2">
          <label>Comments / Notes</label>
          <input name="comments" placeholder="e.g. Rarely attends classes">
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn-primary">Add Student</button>
        <button type="button" onclick="closeModal('add-modal')" class="btn-outline">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ── EDIT MODAL (admin only) ───────────────────────────── -->
<?php if (isAdmin()): ?>
<div id="edit-modal" class="modal-overlay" style="display:none">
  <div class="modal-box">
    <div class="modal-header">
      <h2>Edit Student</h2>
      <button onclick="closeModal('edit-modal')" class="modal-close">✕</button>
    </div>
    <p class="modal-note">
      Changing the <strong>level</strong> will remove existing enrollments and
      re-enroll the student in the new level's courses.
    </p>
    <form method="POST">
      <input type="hidden" name="edit_student" value="1">
      <input type="hidden" name="student_id"   id="edit-id">
      <div class="form-grid-2">
        <div class="field-group">
          <label>Roll No *</label>
          <input name="roll_no" id="edit-roll" required>
        </div>
        <div class="field-group">
          <label>Full Name *</label>
          <input name="name" id="edit-name" required>
        </div>
        <div class="field-group">
          <label>Level *</label>
          <select name="level" id="edit-level" required>
            <option value="3">Level 3 — 7 courses</option>
            <option value="4">Level 4 — 11 courses</option>
            <option value="5">Level 5 — 8 courses</option>
          </select>
        </div>
        <div class="field-group">
          <label>Date of Birth</label>
          <input type="date" name="dob" id="edit-dob">
        </div>
        <div class="field-group">
          <label>Parent / Guardian Name</label>
          <input name="parent_name" id="edit-pname">
        </div>
        <div class="field-group">
          <label>Parent Phone</label>
          <input name="parent_phone" id="edit-pphone">
        </div>
        <div class="field-group" style="grid-column:span 2">
          <label>Parent Email</label>
          <input type="email" name="parent_email" id="edit-pemail">
        </div>
        <div class="field-group" style="grid-column:span 2">
          <label>Comments / Notes</label>
          <input name="comments" id="edit-comments">
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn-primary">Save Changes</button>
        <button type="button" onclick="closeModal('edit-modal')" class="btn-outline">Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
function openModal(id)  { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }

function openEditModal(s) {
  document.getElementById('edit-id').value       = s.id;
  document.getElementById('edit-roll').value     = s.roll_no;
  document.getElementById('edit-name').value     = s.name;
  document.getElementById('edit-level').value    = s.level;
  document.getElementById('edit-dob').value      = s.dob        || '';
  document.getElementById('edit-pname').value    = s.parent_name  || '';
  document.getElementById('edit-pphone').value   = s.parent_phone || '';
  document.getElementById('edit-pemail').value   = s.parent_email || '';
  document.getElementById('edit-comments').value = s.comments    || '';
  openModal('edit-modal');
}

// Close modal when clicking the dark overlay
document.querySelectorAll('.modal-overlay').forEach(function(el) {
  el.addEventListener('click', function(e) {
    if (e.target === el) el.style.display = 'none';
  });
});
</script>
</body>
</html>
