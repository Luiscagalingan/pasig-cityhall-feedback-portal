<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
$user = require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'password');
    $current = (string)($_POST['current_password'] ?? '');
    if ($action === 'profile') {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 160) set_flash('error', 'Enter a valid full name.');
        elseif (!preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username)) set_flash('error', 'Username must be 3-80 characters using letters, numbers, dots, underscores, or hyphens.');
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160) set_flash('error', 'Enter a valid email address.');
        elseif (!password_verify($current, (string)$user['password_hash'])) set_flash('error', 'Current password is incorrect.');
        else {
            $duplicate = db()->prepare('SELECT id FROM users WHERE id<>? AND (username=? OR email=?) LIMIT 1');
            $duplicate->execute([(int)$user['id'], $username, $email]);
            if ($duplicate->fetchColumn()) set_flash('error', 'That username or email address is already in use.');
            else {
                db()->prepare('UPDATE users SET full_name=?,username=?,email=? WHERE id=?')->execute([$fullName,$username,$email,(int)$user['id']]);
                audit((int)$user['id'], 'profile_update', 'User updated own name, username, or email');
                set_flash('success', 'Account information updated successfully.');
            }
        }
    } else {
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if (!password_verify($current, (string)$user['password_hash'])) set_flash('error', 'Current password is incorrect.');
        elseif (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) set_flash('error', 'New password must have at least 8 characters, including a letter and a number.');
        elseif ($new !== $confirm) set_flash('error', 'New password and confirmation do not match.');
        elseif (password_verify($new, (string)$user['password_hash'])) set_flash('error', 'Choose a password different from the current password.');
        else {
            db()->prepare('UPDATE users SET password_hash=?,must_change_password=0,failed_login_attempts=0,locked_until=NULL WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT),(int)$user['id']]);
            audit((int)$user['id'], 'password_change', 'User changed password and cleared temporary-password requirement');
            set_flash('success', 'Password changed successfully.');
        }
    }
    redirect('account.php');
}
render_dashboard_start('My Account', 'account');
page_header('My Account', 'Update your account information and maintain a secure password.');
?>
<div class="account-grid"><section class="panel-form"><h2>Account Information</h2><p>Review your profile and access assignment. Select Edit Information only when you need to make a correction.</p><div class="account-summary"><div><small>Full Name</small><strong><?= e($user['full_name']) ?></strong></div><div><small>Username</small><strong><?= e($user['username']) ?></strong></div><div><small>Email</small><strong><?= e($user['email']) ?></strong></div></div><div class="account-scope"><div><small>Role</small><strong><?= e(status_label($user['role'])) ?></strong></div><div><small>Office Scope</small><strong><?= e($user['role']==='admin'?'All Active Offices':$user['office_name'].' ('.$user['office_code'].')') ?></strong></div><div><small>Status</small><?= badge(status_label($user['status']),$user['status']) ?></div></div><details class="account-editor"><summary class="btn">Edit Information</summary><form method="post" class="account-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="profile"><div class="form-group"><label>Full Name</label><input name="full_name" maxlength="160" value="<?= e($user['full_name']) ?>" required></div><div class="form-group"><label>Username</label><input name="username" maxlength="80" pattern="[A-Za-z0-9._-]{3,80}" value="<?= e($user['username']) ?>" required></div><div class="form-group"><label>Email</label><input type="email" name="email" maxlength="160" value="<?= e($user['email']) ?>" required></div><div class="form-group compact-password"><label for="profile-current-password">Current Password to Confirm Changes</label><div class="password-field"><input id="profile-current-password" type="password" name="current_password" required autocomplete="current-password"><button type="button" class="password-eye" data-password-toggle="profile-current-password" aria-label="Show password" aria-pressed="false"><span class="eye-hidden"><?= icon('eye_off') ?></span><span class="eye-visible"><?= icon('eye') ?></span></button></div></div><div class="actions-cell"><button class="btn">Save Information</button><button type="button" class="btn secondary" data-account-edit-cancel>Cancel</button></div></form></details></section>
<section class="panel-form password-panel"><h2>Change Password</h2><p>Use at least 8 characters with a letter and a number.</p><form method="post" class="account-form compact-password-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="password"><div class="form-group"><label for="account-current-password">Current Password</label><div class="password-field"><input id="account-current-password" type="password" name="current_password" required autocomplete="current-password"><button type="button" class="password-eye" data-password-toggle="account-current-password" aria-label="Show password" aria-pressed="false"><span class="eye-hidden"><?= icon('eye_off') ?></span><span class="eye-visible"><?= icon('eye') ?></span></button></div></div><div class="form-group"><label for="account-new-password">New Password</label><div class="password-field"><input id="account-new-password" type="password" name="new_password" minlength="8" required autocomplete="new-password"><button type="button" class="password-eye" data-password-toggle="account-new-password" aria-label="Show password" aria-pressed="false"><span class="eye-hidden"><?= icon('eye_off') ?></span><span class="eye-visible"><?= icon('eye') ?></span></button></div></div><div class="form-group"><label for="account-confirm-password">Confirm New Password</label><div class="password-field"><input id="account-confirm-password" type="password" name="confirm_password" minlength="8" required autocomplete="new-password"><button type="button" class="password-eye" data-password-toggle="account-confirm-password" aria-label="Show password" aria-pressed="false"><span class="eye-hidden"><?= icon('eye_off') ?></span><span class="eye-visible"><?= icon('eye') ?></span></button></div></div><button class="btn">Update Password</button></form><?php if(!empty($user['must_change_password'])): ?><div class="error-box">This account is still using a temporary password.</div><?php endif; ?></section></div>
<?php render_dashboard_end(); ?>
