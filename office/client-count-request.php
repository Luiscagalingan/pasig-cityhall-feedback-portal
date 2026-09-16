<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_login(['office_staff']);
$officeId = (int)$user['office_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $supervisors = db()->prepare("SELECT id FROM users WHERE office_id=? AND role='supervisor' AND status='active'");
    $supervisors->execute([$officeId]);
    $ids = $supervisors->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) {
        set_flash('error', 'No active supervisor is assigned to this office yet.');
    } else {
        $insert = db()->prepare("INSERT INTO notifications(user_id,office_id,sender_user_id,type,title,message,link_url) VALUES(?,?,?,'client_count_request',?,?,?)");
        foreach ($ids as $id) {
            $insert->execute([(int)$id, $officeId, (int)$user['id'], 'Client count requested', $user['full_name'] . ' requested their catered-client count.', 'office/client-counts.php']);
        }
        audit((int)$user['id'], 'client_count_request', 'Requested catered-client count from supervisor');
        set_flash('success', 'Your request was sent to the supervisor. The reply will appear in Notifications.');
    }
    redirect('office/client-count-request.php');
}

render_dashboard_start('Request Client Count', 'client_request');
page_header('Request My Client Count', 'Ask your supervisor for the current number of client feedback records assigned to you.');
?>
<section class="card"><p class="muted">The count uses feedback records whose <strong>Assisted By</strong> value matches your staff name or username.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="btn">Send Request to Supervisor</button></form></section>
<?php render_dashboard_end(); ?>
