<?php
require_once 'includes/config.php';
requireAdmin();
$user = currentUser();
$db   = db();

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_user'])) {
    $pass = trim($_POST['password']);
    if (strlen($pass)<6) { flashSet('danger','Password must be at least 6 characters.'); header('Location: users.php'); exit; }
    try {
        $db->prepare('INSERT INTO users (name,email,password,role) VALUES (?,?,?,?)')
           ->execute([trim($_POST['name']),trim($_POST['email']),password_hash($pass,PASSWORD_BCRYPT),$_POST['role']]);
        flashSet('success','User added.');
    } catch (Exception $e) { flashSet('danger','Error: '.$e->getMessage()); }
    header('Location: users.php'); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['edit_user'])) {
    $id = intval($_POST['user_id']);
    try {
        $db->prepare('UPDATE users SET name=?,email=?,role=? WHERE id=?')
           ->execute([trim($_POST['name']),trim($_POST['email']),$_POST['role'],$id]);
        $newPass = trim($_POST['new_password']??'');
        if ($newPass!=='') {
            if (strlen($newPass)<6) { flashSet('danger','Password must be at least 6 characters.'); header('Location: users.php'); exit; }
            $db->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($newPass,PASSWORD_BCRYPT),$id]);
        }
        flashSet('success','User updated.');
    } catch (Exception $e) { flashSet('danger','Error: '.$e->getMessage()); }
    header('Location: users.php'); exit;
}

if (isset($_GET['delete'])) {
    $did = intval($_GET['delete']);
    if ($did===$user['id']) { flashSet('danger','You cannot delete your own account.'); }
    else { $db->prepare('DELETE FROM users WHERE id=?')->execute([$did]); flashSet('success','User deleted.'); }
    header('Location: users.php'); exit;
}

$users = $db->query('SELECT * FROM users ORDER BY name')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Users — AttendIQ</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<main class="main-content">
  <div class="page-header">
    <div><h1 class="page-title">Users</h1><p class="page-sub"><?= count($users) ?> accounts</p></div>
    <button onclick="openModal('add-modal')" class="btn-primary">+ Add User</button>
  </div>

  <div class="card">
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Joined</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr>
            <td>
              <div class="inline-student">
                <div class="avatar-sm <?= $u['role']==='admin'?'av-admin':'' ?>"><?= strtoupper(substr($u['name'],0,2)) ?></div>
                <?= sanitize($u['name']) ?>
                <?php if ($u['id']===$user['id']): ?><span class="badge badge-blue" style="margin-left:6px">You</span><?php endif; ?>
              </div>
            </td>
            <td><?= sanitize($u['email']) ?></td>
            <td><span class="badge badge-<?= $u['role']==='admin'?'red':'blue' ?>"><?= ucfirst($u['role']) ?></span></td>
            <td><?= date('d M Y',strtotime($u['created_at'])) ?></td>
            <td>
              <div class="action-group">
                <button class="btn-xs btn-xs-edit"
                  onclick="openEditModal(<?= htmlspecialchars(json_encode(['id'=>$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role']]),ENT_QUOTES) ?>)">Edit</button>
                <?php if ($u['id']!==$user['id']): ?>
                <a href="users.php?delete=<?= $u['id'] ?>" class="btn-xs btn-xs-danger"
                   onclick="return confirm('Delete user <?= sanitize($u['name']) ?>?')">Delete</a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<div id="add-modal" class="modal-overlay" style="display:none">
  <div class="modal-box">
    <div class="modal-header"><h2>Add User</h2><button onclick="closeModal('add-modal')" class="modal-close">✕</button></div>
    <form method="POST">
      <input type="hidden" name="add_user" value="1">
      <div class="form-grid-2">
        <div class="field-group"><label>Full Name *</label><input name="name" required></div>
        <div class="field-group"><label>Email *</label><input type="email" name="email" required></div>
        <div class="field-group"><label>Password * (min 6)</label><input type="password" name="password" required minlength="6"></div>
        <div class="field-group"><label>Role</label>
          <select name="role"><option value="teacher">Teacher</option><option value="admin">Admin</option></select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn-primary">Add User</button>
        <button type="button" onclick="closeModal('add-modal')" class="btn-outline">Cancel</button>
      </div>
    </form>
  </div>
</div>

<div id="edit-modal" class="modal-overlay" style="display:none">
  <div class="modal-box">
    <div class="modal-header"><h2>Edit User</h2><button onclick="closeModal('edit-modal')" class="modal-close">✕</button></div>
    <form method="POST">
      <input type="hidden" name="edit_user" value="1">
      <input type="hidden" name="user_id"   id="edit-id">
      <div class="form-grid-2">
        <div class="field-group"><label>Full Name *</label><input name="name" id="edit-name" required></div>
        <div class="field-group"><label>Email *</label><input type="email" name="email" id="edit-email" required></div>
        <div class="field-group"><label>Role</label>
          <select name="role" id="edit-role"><option value="teacher">Teacher</option><option value="admin">Admin</option></select>
        </div>
        <div class="field-group"><label>New Password <span style="font-weight:400;color:var(--text-3)">(leave blank to keep)</span></label>
          <input type="password" name="new_password" minlength="6" placeholder="Leave blank to keep current">
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn-primary">Save Changes</button>
        <button type="button" onclick="closeModal('edit-modal')" class="btn-outline">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).style.display='flex'; }
function closeModal(id) { document.getElementById(id).style.display='none'; }
function openEditModal(u) {
  document.getElementById('edit-id').value    = u.id;
  document.getElementById('edit-name').value  = u.name;
  document.getElementById('edit-email').value = u.email;
  document.getElementById('edit-role').value  = u.role;
  openModal('edit-modal');
}
document.querySelectorAll('.modal-overlay').forEach(function(el){
  el.addEventListener('click',function(e){ if(e.target===el) el.style.display='none'; });
});
</script>
</body>
</html>