<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
if ($existing = current_user()) redirect(dashboard_path($existing));
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $identity = trim((string)($_POST['identity'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($identity === '' || $password === '') {
        $error = 'Enter your username/email and password.';
    } elseif (login_user($identity, $password)) {
        $user = current_user();
        set_flash('success', 'Successfully logged in as ' . ($user['full_name'] ?? 'user') . '.');
        redirect(dashboard_path($user));
    } else {
        $error = 'Invalid credentials, archived account, or inactive office.';
    }
}
render_public_start('Authorized Login', 'login-body');
?>
<div class="login-wrap">
  <section class="login-info"><span class="eyebrow">SECURE OFFICE ACCESS</span><h1>Service Satisfaction Monitoring System</h1><p>Role-based access keeps every office limited to its own feedback. The administrator can view consolidated citywide results.</p><div class="formula-box"><strong>60/40 computation</strong><br>60% structured ratings + 40% SVM-classified required comment.</div></section>
  <section class="login-form"><a class="back-link" href="<?= e(app_url()) ?>">← Return to public survey</a><h2>Authorized Login</h2><p class="muted">Administrator, Office Head, and Office Staff</p>
    <?php if ($error): ?><div class="error-box"><?= e($error) ?></div><?php endif; ?>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <div class="form-group"><label>Username or Email</label><input name="identity" autocomplete="username" required></div>
      <div class="form-group"><label>Password</label><input type="password" name="password" autocomplete="current-password" required></div>
      <button class="btn" type="submit" style="width:100%;justify-content:center">Sign In</button>
    </form>
    <p class="help">Archived users cannot sign in. Staff and heads can access only their assigned active office.</p>
  </section>
</div>
<button class="theme-toggle" data-theme-toggle style="position:fixed;right:20px;top:20px"><?= icon('sun') ?><span>Light</span></button>
<?php render_public_end(); ?>
