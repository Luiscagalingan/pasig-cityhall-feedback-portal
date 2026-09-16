<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
$offices = active_offices();
render_public_start('Public Feedback Portal');
?>
<nav class="public-nav">
  <a class="public-brand" href="<?= e(app_url()) ?>"><img class="seal" src="<?= e(app_url('assets/images/241304413_194220316131017_8817860418863376271_n.jpg')) ?>" alt="Pasig Public Information Office logo"><div><strong>City Government of Pasig</strong><small>Public Service Feedback Portal</small></div></a>
</nav>
<section class="hero">
  <div>
    <span class="eyebrow">UGNAYAN SA PASIG OFFICE FEEDBACK</span>
    <h1>Your feedback helps improve public service.</h1>
    <p>Share your experience after receiving a service. The survey records demographic information, transaction details, and four service ratings. A written comment is optional. No account is required.</p>
    <div class="public-actions"><a class="btn" href="#offices">Choose an Office</a><a class="btn secondary" href="<?= e(app_url('privacy.php')) ?>">Privacy Policy</a></div>
  </div>
  <div class="hero-card" id="offices">
    <h2>Select the office you visited</h2>
    <p class="muted">Only active offices created by the administrator appear here.</p>
    <div class="office-grid">
      <?php foreach ($offices as $office): ?>
      <a class="office-card" href="<?= e(app_url('survey.php?office=' . urlencode($office['code']))) ?>">
        <div><b><?= e($office['name']) ?></b><small><?= e($office['code']) ?> · Public survey</small></div><span>→</span>
      </a>
      <?php endforeach; ?>
      <?php if (!$offices): ?><div class="empty-state">No active office survey is available.</div><?php endif; ?>
    </div>
  </div>
</section>
<?php render_public_end(); ?>
