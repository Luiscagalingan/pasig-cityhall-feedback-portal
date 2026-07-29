<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_login(['admin']);
if ($_SERVER['REQUEST_METHOD']==='POST') {
  verify_csrf();
  $action=(string)($_POST['action']??'create');
  try {
    if ($action==='create') {
      $name=trim((string)$_POST['name']); $code=strtoupper(trim((string)$_POST['code'])); $description=trim((string)($_POST['description']??''));
      $headName=trim((string)$_POST['head_name']); $username=trim((string)$_POST['username']); $email=trim((string)$_POST['email']); $password=(string)$_POST['password'];
      if ($name===''||$code===''||$headName===''||$username===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<8) throw new RuntimeException('Complete all office and head fields. Password must contain at least 8 characters.');
      db()->beginTransaction();
      $stmt=db()->prepare('INSERT INTO offices(name,code,description,status) VALUES(?,?,?,\'active\')'); $stmt->execute([$name,$code,$description]); $officeId=(int)db()->lastInsertId();
      $stmt=db()->prepare('INSERT INTO users(office_id,full_name,username,email,password_hash,role,status,created_by_user_id) VALUES(?,?,?,?,?,\'office_head\',\'active\',?)');
      $stmt->execute([$officeId,$headName,$username,$email,password_hash($password,PASSWORD_DEFAULT),(int)$user['id']]);
      db()->commit(); audit((int)$user['id'],'office_create',"Created {$code} with office head {$username}");
      set_flash('success','Office and Office Head created. Their dashboard is available immediately.');
    } elseif ($action==='toggle') {
      $id=post_int('office_id'); $status=(string)$_POST['status']; if(!in_array($status,['active','archived'],true)) throw new RuntimeException('Invalid status.');
      $stmt=db()->prepare('UPDATE offices SET status=? WHERE id=?'); $stmt->execute([$status,$id]); audit((int)$user['id'],'office_status',"Office #{$id} -> {$status}"); set_flash('success','Office status updated.');
    } elseif ($action==='edit') {
      $id=post_int('office_id'); $name=trim((string)$_POST['name']); $code=strtoupper(trim((string)$_POST['code'])); $description=trim((string)$_POST['description']);
      $stmt=db()->prepare('UPDATE offices SET name=?,code=?,description=? WHERE id=?'); $stmt->execute([$name,$code,$description,$id]); set_flash('success','Office details updated.');
    }
  } catch(Throwable $e){ if(db()->inTransaction())db()->rollBack(); set_flash('error',$e->getMessage()); }
  redirect('admin/offices.php');
}
$offices=db()->query("SELECT o.*, COUNT(DISTINCT CASE WHEN u.role='office_head' AND u.status='active' THEN u.id END) active_heads, COUNT(DISTINCT CASE WHEN u.role='office_staff' AND u.status='active' THEN u.id END) active_staff, COUNT(DISTINCT f.id) feedback_count FROM offices o LEFT JOIN users u ON u.office_id=o.id LEFT JOIN feedback f ON f.office_id=o.id GROUP BY o.id ORDER BY o.created_at DESC")->fetchAll();
render_dashboard_start('Office Management','offices');
page_header('Office Management','Create an office together with its first Office Head. The role-based dashboard works automatically.');
?>
<div class="split-grid">
<section class="panel-form"><h2>Add Office & Office Head</h2><p>This single action creates the office, survey option, office scope, and head dashboard.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create"><div class="form-group"><label>Office Name</label><input name="name" required placeholder="Example: Public Information Office"></div><div class="form-group"><label>Office Code</label><input name="code" maxlength="20" required placeholder="PIO"></div><div class="form-group"><label>Description</label><textarea name="description"></textarea></div><h3>Initial Office Head</h3><div class="form-group"><label>Full Name</label><input name="head_name" required></div><div class="form-row"><div class="form-group"><label>Username</label><input name="username" required></div><div class="form-group"><label>Email</label><input type="email" name="email" required></div></div><div class="form-group"><label>Temporary Password</label><input type="password" name="password" minlength="8" required><div class="help">At least 8 characters. Give this securely to the Office Head.</div></div><button class="btn" type="submit">Create Office & Head</button></form></section>
<section class="card"><div class="card-head"><div><h2>Registered Offices</h2><p>Archived offices disappear from public surveys and block office logins.</p></div></div><div class="table-wrap"><table><thead><tr><th>Office</th><th>Status</th><th>Heads</th><th>Staff</th><th>Feedback</th><th>Actions</th></tr></thead><tbody><?php foreach($offices as $o): ?><tr><td><strong><?= e($o['name']) ?></strong><br><span class="muted"><?= e($o['code']) ?> · <?= e($o['description']) ?></span></td><td><?= badge(status_label($o['status']),$o['status']) ?></td><td><?= (int)$o['active_heads'] ?></td><td><?= (int)$o['active_staff'] ?></td><td><?= (int)$o['feedback_count'] ?></td><td class="actions-cell"><details><summary class="btn secondary small">Edit</summary><form method="post" style="min-width:280px;padding-top:10px"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="edit"><input type="hidden" name="office_id" value="<?= (int)$o['id'] ?>"><div class="form-group"><input name="name" value="<?= e($o['name']) ?>" required></div><div class="form-group"><input name="code" value="<?= e($o['code']) ?>" required></div><div class="form-group"><textarea name="description"><?= e($o['description']) ?></textarea></div><button class="btn small">Save</button></form></details><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="office_id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="status" value="<?= $o['status']==='active'?'archived':'active' ?>"><button class="btn <?= $o['status']==='active'?'danger':'success' ?> small"><?= $o['status']==='active'?'Archive':'Reactivate' ?></button></form></td></tr><?php endforeach; ?></tbody></table></div></section>
</div>
<?php render_dashboard_end(); ?>
