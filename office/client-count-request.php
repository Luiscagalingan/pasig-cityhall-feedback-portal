<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/client_counts.php';
$user = require_login(['office_head','office_staff']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if ($user['role'] === 'office_head' && isset($_POST['request_id'])) {
            $count = answer_client_count_request(db(), (int)$user['id'], post_int('request_id'));
            set_flash('success', 'Request answered. Verified annual count sent: ' . $count . '.');
        } else {
            create_client_count_request(db(), (int)$user['id'], client_count_year($_POST['requested_year'] ?? date('Y')));
            set_flash('success', 'Your request was sent to Sir Uno. The answer will appear here and in Notifications.');
        }
    } catch (DomainException $error) {
        set_flash('error', $error->getMessage());
    } catch (Throwable $error) {
        error_log('Client-count request failed: ' . $error->getMessage());
        set_flash('error', 'Unable to send the request. Please contact the system administrator.');
    }
    redirect('office/client-count-request.php');
}
$requests = client_count_requests(db(), $user);
$headView = $user['role'] === 'office_head';
render_dashboard_start('Request Client Count from Sir Uno', 'client_request');
page_header('Request Client Count from Sir Uno', $headView
    ? 'Send your own request and monitor client-count requests submitted within your assigned office.'
    : 'Send a request to Sir Uno to verify your assisted-client total for a selected calendar year.');
?>
<div class="client-request-layout">
<section class="card client-request-form-card"><p class="muted">Sir Uno will verify eligible assisted-survey records owned by your account in your assigned office, from January 1 through December 31. Accounts without matching records will receive a verified count of 0.</p><form method="post" class="client-request-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Calendar Year<input type="number" name="requested_year" min="1900" max="9998" value="<?= e(date('Y')) ?>" required></label><button class="btn">Send Request to Sir Uno</button></form></section>
<section class="card client-request-history-card"><div class="table-wrap"><table><thead><tr><th>Request</th><?php if ($headView): ?><th>Requester</th><?php endif; ?><th>Year</th><th>Requested</th><th>Status</th><th>Verified Annual Count</th><th>Answered</th><th>Responder</th><?php if ($headView): ?><th>Action</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($requests as $request): $headCanAnswer=$headView && $request['status']==='pending' && $request['requested_year']!==null && $request['staff_role']==='office_staff' && $request['staff_status']==='active' && $request['office_status']==='active' && (int)$request['current_office_id']===(int)$request['office_id']; ?><tr><td>#<?= (int)$request['id'] ?></td><?php if ($headView): ?><td><strong><?= e($request['full_name']) ?></strong><br><span class="muted">@<?= e($request['username']) ?> · <?= e(status_label($request['staff_role'])) ?></span></td><?php endif; ?><td><?= e($request['requested_year'] ?? 'Legacy - no year') ?></td><td><?= e($request['requested_at']) ?></td><td><?= e(ucfirst($request['status'])) ?></td><td><?= $request['answered_count'] === null ? '?' : (int)$request['answered_count'] ?></td><td><?= e($request['answered_at'] ?? '?') ?></td><td><?= e($request['administrator_name'] ?? '?') ?></td><?php if ($headView): ?><td><?php if ($headCanAnswer): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><button class="btn small">Send Count</button></form><?php elseif ($request['status']==='answered'): ?>Answered<?php else: ?>Sir Uno only<?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?>
<?php if (!$requests): ?><tr><td colspan="<?= $headView ? 9 : 7 ?>" class="empty-state">No tracked requests yet.</td></tr><?php endif; ?>
</tbody></table></div></section>
</div>
<?php render_dashboard_end(); ?>
