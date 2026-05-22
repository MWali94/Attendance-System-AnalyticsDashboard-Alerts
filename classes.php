<?php
require_once 'includes/config.php';
requireLogin();
$user = currentUser();
$db   = db();

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_class']) && isAdmin()) {
    try {
        $db->prepare('INSERT INTO classes (name,section,level,teacher_id) VALUES (?,?,?,?)')
           ->execute([trim($_POST['name']), trim($_POST['section']),
                      intval($_POST['level']), intval($_POST['teacher_id']) ?: null]);
        flashSet('success','Course added.');
    } catch (Exception $e) { flashSet('danger',$e->getMessage()); }
    header('Location: classes.php'); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['edit_class']) && isAdmin()) {
    $id = intval($_POST['class_id']);
    try {
        $db->prepare('UPDATE classes SET name=?,section=?,level=?,teacher_id=? WHERE id=?')
           ->execute([trim($_POST['name']), trim($_POST['section']),
                      intval($_POST['level']), intval($_POST['teacher_id']) ?: null, $id]);
        flashSet('success','Course updated.');
    } catch (Exception $e) { flashSet('danger',$e->getMessage()); }
    header('Location: classes.php'); exit;
}

if (isset($_GET['delete']) && isAdmin()) {
    $db->prepare('DELETE FROM classes WHERE id=?')->execute([intval($_GET['delete'])]);
    flashSet('success','Course deleted.');
    header('Location: classes.php'); exit;
}

$filterLevel = intval($_GET['level'] ?? 0);
$teachers    = $db->query("SELECT id,name FROM users WHERE role='teacher' ORDER BY name")->fetchAll();

$sql = "SELECT c.*, u.name AS teacher_name,
               (SELECT COUNT(*) FROM enrollments e WHERE e.class_id=c.id) AS enrolled_count
        FROM classes c LEFT JOIN users u ON u.id=c.teacher_id WHERE 1=1";
$params = [];
if ($filterLevel) { $sql .= ' AND c.level=?'; $params[]=$filterLevel; }
$sql .= ' ORDER BY c.level, c.name';
$stmt = $db->prepare($sql); $stmt->execute($params);
$classes = $stmt->fetchAll();

$byLevel = [3=>[],4=>[],5=>[]];
foreach ($classes as $c) $byLevel[$c['level']][] = $c;

$lvTitles = [3=>'Level 3 — Information &amp; Digital Technologies',
             4=>'Level 4 — Computing (Software Developer)', 5=>'Level 5 — Computing'];
$lvDescs  = [3=>'ATHE Level 3  · 7 mandatory units',
             4=>'ATHE Level 4 Computing  · Diploma (5) + Extended Diploma (6)',
             5=>'ATHE Level 5 Computing  · Diploma (4) + Extended Diploma (4)'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Courses — AttendIQ</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<main class="main-content">

  <div class="page-header">
    <div>
      <h1 class="page-title">Courses</h1>
      <p class="page-sub"><?= count($classes) ?> courses across Level 3, 4 &amp; 5</p>
    </div>
    <?php if (isAdmin()): ?>
    <button onclick="openModal('add-modal')" class="btn-primary">+ Add Course</button>
    <?php endif; ?>
  </div>

  <div class="level-tabs">
    <a href="classes.php"         class="level-tab <?= !$filterLevel?'tab-active':'' ?>">All <span class="tab-pill"><?= count($classes) ?></span></a>
    <a href="classes.php?level=3" class="level-tab tab-lv3 <?= $filterLevel===3?'tab-active':'' ?>">Level 3 <span class="tab-pill"><?= count($byLevel[3]) ?></span></a>
    <a href="classes.php?level=4" class="level-tab tab-lv4 <?= $filterLevel===4?'tab-active':'' ?>">Level 4 <span class="tab-pill"><?= count($byLevel[4]) ?></span></a>
    <a href="classes.php?level=5" class="level-tab tab-lv5 <?= $filterLevel===5?'tab-active':'' ?>">Level 5 <span class="tab-pill"><?= count($byLevel[5]) ?></span></a>
  </div>

  <?php foreach ([3,4,5] as $lv):
    if ($filterLevel && $filterLevel!==$lv) continue;
    if (empty($byLevel[$lv])) continue;
  ?>
  <div class="level-section">
    <div class="level-section-hdr lv<?= $lv ?>-hdr">
      <div>
        <div class="lsh-title"><?= $lvTitles[$lv] ?></div>
        <div class="lsh-desc"><?= $lvDescs[$lv] ?></div>
      </div>
      <span class="lsh-count"><?= count($byLevel[$lv]) ?> courses</span>
    </div>
    <div class="courses-table-wrap">
      <table class="data-table">
        <thead><tr><th>#</th><th>Course Name</th><th>Lecturer</th><th>Enrolled Students</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($byLevel[$lv] as $i => $c): ?>
          <tr>
            <td class="td-num"><?= $i+1 ?></td>
            <td>
              <div class="course-name-cell">
                <span class="lv-tag lv<?= $lv ?>-tag">L<?= $lv ?></span>
                <?= sanitize($c['name']) ?>
              </div>
            </td>
            <td><?= sanitize($c['teacher_name'] ?? '—') ?></td>
            <td><span class="badge badge-blue"><?= $c['enrolled_count'] ?> students</span></td>
            <td>
              <div class="action-group">
                <a href="attendance.php?class_id=<?= $c['id'] ?>" class="btn-xs btn-xs-success">Attendance</a>
                <?php if (isAdmin()): ?>
                <button class="btn-xs btn-xs-edit"
                  onclick="openEditModal(<?= htmlspecialchars(json_encode($c),ENT_QUOTES) ?>)">Edit</button>
                <a href="classes.php?delete=<?= $c['id'] ?>" class="btn-xs btn-xs-danger"
                   onclick="return confirm('Delete course:\n\'<?= addslashes(sanitize($c['name'])) ?>\'?')">Delete</a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; ?>

</main>

<?php if (isAdmin()): ?>
<div id="add-modal" class="modal-overlay" style="display:none">
  <div class="modal-box">
    <div class="modal-header"><h2>Add Course</h2><button onclick="closeModal('add-modal')" class="modal-close">✕</button></div>
    <form method="POST">
      <input type="hidden" name="add_class" value="1">
      <div class="form-grid-2">
        <div class="field-group" style="grid-column:span 2"><label>Course Name *</label><input name="name" required></div>
        <div class="field-group"><label>Level *</label>
          <select name="level" required onchange="this.nextElementSibling.nextElementSibling.value='L'+this.value">
            <option value="">Select</option>
            <option value="3">Level 3</option><option value="4">Level 4</option><option value="5">Level 5</option>
          </select>
        </div>
        <div class="field-group"><label>Section</label><input name="section" id="add-section" placeholder="L3 / L4 / L5"></div>
        <div class="field-group" style="grid-column:span 2"><label>Assign Lecturer</label>
          <select name="teacher_id"><option value="">— None —</option>
            <?php foreach ($teachers as $t): ?><option value="<?= $t['id'] ?>"><?= sanitize($t['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn-primary">Add Course</button>
        <button type="button" onclick="closeModal('add-modal')" class="btn-outline">Cancel</button>
      </div>
    </form>
  </div>
</div>

<div id="edit-modal" class="modal-overlay" style="display:none">
  <div class="modal-box">
    <div class="modal-header"><h2>Edit Course</h2><button onclick="closeModal('edit-modal')" class="modal-close">✕</button></div>
    <form method="POST">
      <input type="hidden" name="edit_class" value="1">
      <input type="hidden" name="class_id"   id="edit-id">
      <div class="form-grid-2">
        <div class="field-group" style="grid-column:span 2"><label>Course Name *</label><input name="name" id="edit-name" required></div>
        <div class="field-group"><label>Level *</label>
          <select name="level" id="edit-level" required>
            <option value="3">Level 3</option><option value="4">Level 4</option><option value="5">Level 5</option>
          </select>
        </div>
        <div class="field-group"><label>Section</label><input name="section" id="edit-section"></div>
        <div class="field-group" style="grid-column:span 2"><label>Assign Lecturer</label>
          <select name="teacher_id" id="edit-teacher"><option value="">— None —</option>
            <?php foreach ($teachers as $t): ?><option value="<?= $t['id'] ?>"><?= sanitize($t['name']) ?></option><?php endforeach; ?>
          </select>
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
function openModal(id)  { document.getElementById(id).style.display='flex'; }
function closeModal(id) { document.getElementById(id).style.display='none'; }
function openEditModal(c) {
  document.getElementById('edit-id').value      = c.id;
  document.getElementById('edit-name').value    = c.name;
  document.getElementById('edit-level').value   = c.level;
  document.getElementById('edit-section').value = c.section;
  document.getElementById('edit-teacher').value = c.teacher_id || '';
  openModal('edit-modal');
}
document.querySelectorAll('.modal-overlay').forEach(function(el){
  el.addEventListener('click',function(e){ if(e.target===el) el.style.display='none'; });
});
</script>
</body>
</html>