<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/client_counts.php';
$user = require_login(['admin']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $count = answer_client_count_request(db(), (int)$user['id'], post_int('request_id'));
        set_flash('success', 'Request answered. Verified annual count sent: ' . $count . '.');
    } catch (DomainException $error) {
        set_flash('error', $error->getMessage());
    } catch (Throwable $error) {
        error_log('Client-count response failed: ' . $error->getMessage());
        set_flash('error', 'Unable to answer the request. Please contact the system administrator.');
    }
    redirect('admin/client-counts.php');
}
$requests = client_count_requests(db());
render_dashboard_start('Annual Client Counts', 'client_counts');
page_header('Annual Client-count Requests', 'Any active Administrator can verify eligible account-owned records for the requested calendar year.');
?>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Request / Staff</th><th>Assigned Office</th><th>Year</th><th>Requested</th><th>Status</th><th>Annual Count</th><th>Answered</th><th>Administrator</th><th>Action</th></tr></thead><tbody>
<?php foreach ($requests as $request):
    $pending = $request['status'] === 'pending';
    $eligible = $request['requested_year'] !== null && $request['staff_role'] === 'office_staff' && $request['staff_status'] === 'active' && $request['office_status'] === 'active' && (int)$request['current_office_id'] === (int)$request['office_id'];
    $count = $pending ? ($request['requested_year'] === null ? 'Legacy - no year' : assisted_client_count(db(), (int)$request['staff_user_id'], (int)$request['office_id'], (int)$request['requested_year'])) : (int)$request['answered_count'];
?>
<tr id="request-<?= (int)$request['id'] ?>"><td>#<?= (int)$request['id'] ?> <strong><?= e($request['full_name']) ?></strong><br>@<?= e($request['username']) ?></td><td><?= e($request['office_code']) ?> - <?= e($request['office_name']) ?></td><td><?= e($request['requested_year'] ?? 'Legacy - no year') ?></td><td><?= e($request['requested_at']) ?></td><td><?= e(ucfirst($request['status'])) ?></td><td><?= $count ?></td><td><?= e($request['answered_at'] ?? '?') ?></td><td><?= e($request['administrator_name'] ?? '?') ?></td><td>
<?php if ($pending && $eligible): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><button class="btn small">Send Verified Annual Count</button></form><?php elseif ($pending): ?>Annual year, staff account, or assigned office unavailable; submit a new annual request<?php else: ?>Answered<?php endif; ?>
</td></tr><?php endforeach; ?>
<?php if (!$requests): ?><tr><td colspan="9" class="empty-state">No tracked requests. For notifications sent before request tracking was installed, ask the staff member to send a new request.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php render_dashboard_end(); ?>
