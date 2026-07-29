<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
$user = require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    if (!password_verify($current, (string)$user['password_hash'])) set_flash('error', 'Current password is incorrect.');
    elseif (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) set_flash('error', 'New password must have at least 8 characters, including a letter and a number.');
    elseif ($new !== $confirm) set_flash('error', 'New password and confirmation do not match.');
    elseif (password_verify($new, (string)$user['password_hash'])) set_flash('error', 'Choose a password different from the current password.');
    else {
        db()->prepare('UPDATE users SET password_hash=?, must_change_password=0, failed_login_attempts=0, locked_until=NULL WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), (int)$user['id']]);
        audit((int)$user['id'], 'password_change', 'User changed password and cleared temporary-password requirement');
        set_flash('success', 'Password changed successfully.');
    }
    redirect('account.php');
}
render_dashboard_start('My Account', 'account');
page_header('My Account', 'Review your assigned access scope and maintain a secure password.');
?>
<div class="split-grid"><section class="card"><div class="card-head"><div><h2>Account Details</h2><p>Role-based access information</p></div></div><div class="bar-list"><div><strong>Full Name</strong><br><span class="muted"><?= e($user['full_name']) ?></span></div><div><strong>Username</strong><br><span class="muted"><?= e($user['username']) ?></span></div><div><strong>Email</strong><br><span class="muted"><?= e($user['email']) ?></span></div><div><strong>Role</strong><br><span class="muted"><?= e(status_label($user['role'])) ?></span></div><div><strong>Office Scope</strong><br><span class="muted"><?= e($user['role']==='admin'?'All Active Offices':$user['office_name'].' ('.$user['office_code'].')') ?></span></div><div><strong>Status</strong><br><?= badge(status_label($user['status']),$user['status']) ?></div><?php if(!empty($user['must_change_password'])): ?><div class="error-box">This account is still using a temporary password.</div><?php endif; ?></div></section>
<section class="panel-form"><h2>Change Password</h2><p>Use at least 8 characters with a letter and a number.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><div class="form-group"><label>Current Password</label><input type="password" name="current_password" required autocomplete="current-password"></div><div class="form-group"><label>New Password</label><input type="password" name="new_password" minlength="8" required autocomplete="new-password"></div><div class="form-group"><label>Confirm New Password</label><input type="password" name="confirm_password" minlength="8" required autocomplete="new-password"></div><button class="btn">Update Password</button></form></section></div>
<?php render_dashboard_end(); ?>
