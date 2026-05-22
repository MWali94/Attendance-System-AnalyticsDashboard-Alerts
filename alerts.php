<?php
require_once 'includes/config.php';
requireLogin();
$db = db();

if (isset($_GET['mark_sent'])) {
    $db->prepare('UPDATE alerts SET sent=1 WHERE id=?')->execute([intval($_GET['mark_sent'])]);
    flashSet('success','Alert marked as sent.');
    header('Location: alerts.php'); exit;
}
if (isset($_GET['mark_unsent']) && isAdmin()) {
    $db->prepare('UPDATE alerts SET sent=0 WHERE id=?')->execute([intval($_GET['mark_unsent'])]);
    flashSet('success','Alert reopened.');
    header('Location: alerts.php'); exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['edit_alert']) && isAdmin()) {
    $db->prepare('UPDATE alerts SET message=?,type=? WHERE id=?')
       ->execute([trim($_POST['message']),$_POST['type'],intval($_POST['alert_id'])]);
    flashSet('success','Alert updated.');
    header('Location: alerts.php'); exit;
}
if (isset($_GET['delete']) && isAdmin()) {
    $db->prepare('DELETE FROM alerts WHERE id=?')->execute([intval($_GET['delete'])]);
    flashSet('success','Alert deleted.');
    header('Location: alerts.php'); exit;
}

$alerts = $db->query("
    SELECT al.*, s.name AS student_name, s.roll_no, s.level, s.parent_phone,
           c.name AS course
    FROM alerts al
    JOIN students s ON s.id = al.student_id
    LEFT JOIN classes c ON c.id = al.class_id
    ORDER BY al.created_at DESC
")->fetchAll();

$unsent = array_filter($alerts, fn($a) => !$a['sent']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Alerts — AttendIQ</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<main class="main-content">
  <div class="page-header">
    <div>
      <h1 class="page-title">Alerts</h1>
      <p class="page-sub"><?= count($unsent) ?> pending · <?= count($alerts) ?> total</p>
    </div>
  </div>

  <div class="card">
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr><th>Student</th><th>Level</th><th>Course</th><th>Type</th><th>Message</th><th>Parent Phone</th><th>Date</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($alerts as $a): ?>
          <tr class="<?= !$a['sent']?'row-highlight':'' ?>">
            <td>
              <div class="inline-student">
                <div class="avatar-sm"><?= strtoupper(substr($a['student_name'],0,2)) ?></div>
                <div>
                  <div><?= sanitize($a['student_name']) ?></div>
                  <div style="font-size:11px;color:var(--text-3)"><?= sanitize($a['roll_no']) ?></div>
                </div>
              </div>
            </td>
            <td><span class="lv-tag lv<?= $a['level'] ?>-tag">L<?= $a['level'] ?></span></td>
            <td style="font-size:12px;max-width:160px"><?= sanitize($a['course'] ?? '—') ?></td>
            <td><span class="badge badge-<?= $a['type']==='consecutive_absent'?'red':'amber' ?>"><?= str_replace('_',' ',ucfirst($a['type'])) ?></span></td>
            <td style="font-size:12px;max-width:200px"><?= sanitize($a['message']) ?></td>
            <td class="td-mono"><?= sanitize($a['parent_phone'] ?? '—') ?></td>
            <td style="font-size:12px"><?= date('d M Y',strtotime($a['created_at'])) ?></td>
            <td><?= $a['sent'] ? '<span class="badge badge-green">Sent</span>' : '<span class="badge badge-amber">Pending</span>' ?></td>
            <td>
              <div class="action-group">
                <?php if (!$a['sent']): ?>
                <a href="alerts.php?mark_sent=<?= $a['id'] ?>" class="btn-xs btn-xs-success">Mark Sent</a>
                <?php else: ?>
                <?php if (isAdmin()): ?>
                <a href="alerts.php?mark_unsent=<?= $a['id'] ?>" class="btn-xs">Reopen</a>
                <?php endif; ?>
                <?php endif; ?>
                <?php if (isAdmin()): ?>
                <button class="btn-xs btn-xs-edit"
                  onclick="openEditModal(<?= htmlspecialchars(json_encode(['id'=>$a['id'],'message'=>$a['message'],'type'=>$a['type']]),ENT_QUOTES) ?>)">Edit</button>
                <a href="alerts.php?delete=<?= $a['id'] ?>" class="btn-xs btn-xs-danger"
                   onclick="return confirm('Delete this alert?')">Delete</a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($alerts)): ?>
          <tr><td colspan="9" class="empty-msg">No alerts yet. Alerts are auto-generated when marking attendance.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<?php if (isAdmin()): ?>
<div id="edit-modal" class="modal-overlay" style="display:none">
  <div class="modal-box">
    <div class="modal-header"><h2>Edit Alert</h2><button onclick="closeModal('edit-modal')" class="modal-close">✕</button></div>
    <form method="POST">
      <input type="hidden" name="edit_alert" value="1">
      <input type="hidden" name="alert_id"   id="edit-id">
      <div class="field-group" style="margin-bottom:14px"><label>Type</label>
        <select name="type" id="edit-type">
          <option value="consecutive_absent">Consecutive Absent</option>
          <option value="threshold">Threshold</option>
          <option value="pattern">Pattern</option>
        </select>
      </div>
      <div class="field-group"><label>Message</label>
        <textarea name="message" id="edit-message" rows="4"
          style="width:100%;padding:8px 12px;border:1px solid var(--border-md);border-radius:var(--radius);resize:vertical"></textarea>
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
function openEditModal(a) {
  document.getElementById('edit-id').value      = a.id;
  document.getElementById('edit-type').value    = a.type;
  document.getElementById('edit-message').value = a.message;
  openModal('edit-modal');
}
document.querySelectorAll('.modal-overlay').forEach(function(el){
  el.addEventListener('click',function(e){ if(e.target===el) el.style.display='none'; });
});
</script>
</body>
</html>