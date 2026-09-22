<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/client_counts.php';
$user = require_login(['office_staff']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        create_client_count_request(db(), (int)$user['id'], client_count_year($_POST['requested_year'] ?? date('Y')));
        set_flash('success', 'Your request was sent to an Administrator. The answer will appear here and in Notifications.');
    } catch (DomainException $error) {
        set_flash('error', $error->getMessage());
    } catch (Throwable $error) {
        error_log('Client-count request failed: ' . $error->getMessage());
        set_flash('error', 'Unable to send the request. Please contact the system administrator.');
    }
    redirect('office/client-count-request.php');
}
$requests = client_count_requests(db(), $user);
render_dashboard_start('Request Annual Client Count', 'client_request');
page_header('Request Annual Client Count', 'Ask an Administrator to verify your assisted-client total for a selected calendar year.');
?>
<section class="card"><p class="muted">The verified annual count includes only eligible assisted-survey records owned by your account in your assigned office, from January 1 through December 31.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Calendar Year<input type="number" name="requested_year" min="1900" max="9998" value="<?= e(date('Y')) ?>" required></label><button class="btn">Request Verified Annual Count</button></form></section>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Request</th><th>Year</th><th>Requested</th><th>Status</th><th>Verified Annual Count</th><th>Answered</th><th>Administrator</th></tr></thead><tbody>
<?php foreach ($requests as $request): ?><tr><td>#<?= (int)$request['id'] ?></td><td><?= e($request['requested_year'] ?? 'Legacy - no year') ?></td><td><?= e($request['requested_at']) ?></td><td><?= e(ucfirst($request['status'])) ?></td><td><?= $request['answered_count'] === null ? '?' : (int)$request['answered_count'] ?></td><td><?= e($request['answered_at'] ?? '?') ?></td><td><?= e($request['administrator_name'] ?? '?') ?></td></tr><?php endforeach; ?>
<?php if (!$requests): ?><tr><td colspan="7" class="empty-state">No tracked requests yet.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php render_dashboard_end(); ?>
