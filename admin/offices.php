<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_login(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'create');
    $returnStatus = in_array(($_POST['return_status'] ?? 'active'), ['active','archived'], true)
        ? (string)$_POST['return_status'] : 'active';
    try {
        if ($action === 'create') {
            $name = trim((string)$_POST['name']);
            $code = strtoupper(trim((string)$_POST['code']));
            $description = trim((string)($_POST['description'] ?? ''));
            $headName = trim((string)$_POST['head_name']);
            $username = trim((string)$_POST['username']);
            $email = trim((string)$_POST['email']);
            $password = (string)$_POST['password'];
            if ($name === '' || $code === '' || $headName === '' || $username === '' ||
                !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 ||
                !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
                throw new RuntimeException('Complete all office and head fields. Password must contain at least 8 characters with a letter and a number.');
            }
            db()->beginTransaction();
            $stmt = db()->prepare("INSERT INTO offices(name,code,description,status) VALUES(?,?,?,'active')");
            $stmt->execute([$name,$code,$description]);
            $officeId = (int)db()->lastInsertId();
            $stmt = db()->prepare("INSERT INTO users(office_id,full_name,username,email,password_hash,role,status,created_by_user_id,must_change_password) VALUES(?,?,?,?,?,'office_head','active',?,1)");
            $stmt->execute([$officeId,$headName,$username,$email,password_hash($password,PASSWORD_DEFAULT),(int)$user['id']]);
            db()->commit();
            audit((int)$user['id'],'office_create',"Created {$code} with office head {$username}");
            set_flash('success','Office and Office Head created. Their dashboard is available immediately.');
        } elseif ($action === 'toggle') {
            $id = post_int('office_id');
            $newStatus = (string)$_POST['status'];
            if (!in_array($newStatus,['active','archived'],true)) throw new RuntimeException('Invalid status.');
            db()->prepare('UPDATE offices SET status=? WHERE id=?')->execute([$newStatus,$id]);
            audit((int)$user['id'],'office_status',"Office #{$id} -> {$newStatus}");
            set_flash('success',$newStatus === 'archived' ? 'Office archived and removed from public surveys.' : 'Office reactivated and restored to public surveys.');
        } elseif ($action === 'edit') {
            $id = post_int('office_id');
            $name = trim((string)$_POST['name']);
            $code = strtoupper(trim((string)$_POST['code']));
            $description = trim((string)$_POST['description']);
            if ($name === '' || $code === '') throw new RuntimeException('Office name and code are required.');
            db()->prepare('UPDATE offices SET name=?,code=?,description=? WHERE id=?')->execute([$name,$code,$description,$id]);
            audit((int)$user['id'],'office_edit',"Office #{$id} updated to {$code} / {$name}");
            set_flash('success','Office details updated.');
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        set_flash('error',$e->getMessage());
    }
    redirect('admin/offices.php?status=' . urlencode($returnStatus));
}

$status = in_array(($_GET['status'] ?? 'active'), ['active','archived'], true)
    ? (string)$_GET['status'] : 'active';
$counts = ['active'=>0,'archived'=>0];
foreach (db()->query('SELECT status,COUNT(*) total FROM offices GROUP BY status')->fetchAll() as $countRow) {
    $counts[$countRow['status']] = (int)$countRow['total'];
}
$officeStmt = db()->prepare("SELECT o.*,
    COUNT(DISTINCT CASE WHEN u.role='office_head' AND u.status='active' THEN u.id END) active_heads,
    COUNT(DISTINCT CASE WHEN u.role='office_staff' AND u.status='active' THEN u.id END) active_staff,
    COUNT(DISTINCT f.id) feedback_count
    FROM offices o
    LEFT JOIN users u ON u.office_id=o.id
    LEFT JOIN feedback f ON f.office_id=o.id
    WHERE o.status=?
    GROUP BY o.id
    ORDER BY o.created_at DESC");
$officeStmt->execute([$status]);
$offices = $officeStmt->fetchAll();

render_dashboard_start('Office Management','offices');
page_header('Office Management','Create offices, view active registrations, and restore archived offices.',
    '<button type="button" class="btn" data-modal-open="add-office-modal">Add Office</button>');
?>
<div class="app-modal" id="add-office-modal" aria-hidden="true">
  <section class="app-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="add-office-title">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <h2 id="add-office-title">Add Office & Office Head</h2>
    <p class="muted">Create the office, public survey scope, and initial Office Head account in one step.</p>
    <form method="post" class="modal-form">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="return_status" value="active">
      <div class="form-group"><label>Office Name</label><input name="name" required placeholder="Example: Public Information Office"></div>
      <div class="form-group"><label>Office Code</label><input name="code" maxlength="20" required placeholder="PIO"></div>
      <div class="form-group"><label>Description</label><textarea name="description"></textarea></div>
      <h3>Initial Office Head</h3>
      <div class="form-group"><label>Full Name</label><input name="head_name" required></div>
      <div class="form-row">
        <div class="form-group"><label>Username</label><input name="username" required></div>
        <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
      </div>
      <div class="form-group"><label>Temporary Password</label><input type="password" name="password" minlength="8" required><div class="help">At least 8 characters with a letter and number. Share it securely.</div></div>
      <div class="confirm-actions"><button type="button" class="btn secondary" data-modal-close>Cancel</button><button class="btn" type="submit">Create Office & Head</button></div>
    </form>
  </section>
</div>

<div class="tabs">
  <a class="tab <?= $status==='active'?'active':'' ?>" href="?status=active">Active Offices <b><?= $counts['active'] ?></b></a>
  <a class="tab <?= $status==='archived'?'active':'' ?>" href="?status=archived">Archived Offices <b><?= $counts['archived'] ?></b></a>
</div>

<section class="card">
  <div class="card-head">
    <div>
      <h2><?= $status==='active'?'Registered Offices':'Archived Offices' ?></h2>
      <p><?= $status==='active'
          ? 'Active offices appear in the public survey and allow assigned users to sign in.'
          : 'Archived offices are hidden from public surveys and their assigned users cannot sign in.' ?></p>
    </div>
  </div>
  <div class="table-wrap"><table>
    <thead><tr><th>Office</th><th>Status</th><th>Heads</th><th>Staff</th><th>Feedback</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach($offices as $o): ?>
      <tr>
        <td><strong><?= e($o['name']) ?></strong><br><span class="muted"><?= e($o['code']) ?> · <?= e($o['description']) ?></span></td>
        <td><?= badge(status_label($o['status']),$o['status']) ?></td>
        <td><?= (int)$o['active_heads'] ?></td>
        <td><?= (int)$o['active_staff'] ?></td>
        <td><?= (int)$o['feedback_count'] ?></td>
        <td class="actions-cell">
          <details><summary class="btn secondary small">Edit</summary>
            <form method="post" style="min-width:280px;padding-top:10px">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="edit">
              <input type="hidden" name="return_status" value="<?= e($status) ?>">
              <input type="hidden" name="office_id" value="<?= (int)$o['id'] ?>">
              <div class="form-group"><input name="name" value="<?= e($o['name']) ?>" required></div>
              <div class="form-group"><input name="code" value="<?= e($o['code']) ?>" required></div>
              <div class="form-group"><textarea name="description"><?= e($o['description']) ?></textarea></div>
              <button class="btn small">Save</button>
            </form>
          </details>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="return_status" value="<?= e($status) ?>">
            <input type="hidden" name="office_id" value="<?= (int)$o['id'] ?>">
            <input type="hidden" name="status" value="<?= $o['status']==='active'?'archived':'active' ?>">
            <button class="btn <?= $o['status']==='active'?'danger':'success' ?> small"><?= $o['status']==='active'?'Archive':'Reactivate' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if(!$offices): ?><tr><td colspan="6" class="empty-state">No <?= e($status) ?> offices found.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>
<?php render_dashboard_end(); ?>
