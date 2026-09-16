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
        $error = login_error_message();
    }
}
render_public_start('Authorized Login', 'login-body');
?>
<div class="login-wrap">
  <section class="login-info">
    <div class="login-orb login-orb-one"></div><div class="login-orb login-orb-two"></div>
    <div class="login-brand">
      <img class="login-seal" src="<?= e(app_url('assets/images/241304413_194220316131017_8817860418863376271_n.jpg')) ?>" alt="Pasig Public Information Office logo">
      <span>CITY GOVERNMENT OF PASIG</span>
      <h1>Pasig City Hall</h1>
      <h2>Service Feedback System</h2>
      <i aria-hidden="true"></i>
      <p>Your feedback helps us build a better, more responsive Pasig City.</p>
    </div>
    <div class="city-silhouette" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span></div>
  </section>
  <section class="login-form"><span class="eyebrow">SECURE OFFICE ACCESS</span><h2>Authorized Login</h2><p class="muted">Administrator, Office Head, Supervisor, and Office Staff</p>
    <?php if ($error): ?><div class="error-box"><?= e($error) ?></div><?php endif; ?>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <div class="form-group"><label>Username or Email</label><input name="identity" autocomplete="username" required></div>
      <div class="form-group">
        <label for="login-password">Password</label>
        <input id="login-password" type="password" name="password" autocomplete="current-password" required>
        <label class="password-toggle"><input type="checkbox" data-show-password="login-password"> <span>Show password</span></label>
      </div>
      <button class="btn" type="submit" style="width:100%;justify-content:center">Sign In</button>
    </form>
    <p class="help">Archived users cannot sign in. Staff and heads can access only their assigned active office.</p>
  </section>
</div>
<?php render_public_end(); ?>
