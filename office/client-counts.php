<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_login(['supervisor']);
$officeId = (int)$user['office_id'];
$staffStmt = db()->prepare("SELECT id,full_name,username FROM users WHERE office_id=? AND role='office_staff' AND status='active' ORDER BY full_name");
$staffStmt->execute([$officeId]);
$staff = $staffStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $staffId = post_int('staff_id');
    $target = db()->prepare("SELECT id,full_name,username FROM users WHERE id=? AND office_id=? AND role='office_staff' AND status='active'");
    $target->execute([$staffId, $officeId]);
    $recipient = $target->fetch();
    if (!$recipient) {
        set_flash('error', 'Select an active staff account from this office.');
    } else {
        $count = db()->prepare("SELECT COUNT(*) FROM feedback WHERE office_id=? AND is_void=0 AND LOWER(TRIM(assisted_by)) IN (?,?)");
        $count->execute([$officeId, mb_strtolower((string)$recipient['full_name']), mb_strtolower((string)$recipient['username'])]);
        $total = (int)$count->fetchColumn();
        $message = 'Your current catered-client count is ' . $total . '. This is based on active feedback records with Assisted By matching your name or username.';
        db()->prepare("INSERT INTO notifications(user_id,office_id,sender_user_id,type,title,message) VALUES(?,?,?,'client_count',?,?)")
            ->execute([(int)$recipient['id'], $officeId, (int)$user['id'], 'Catered-client count', $message]);
        audit((int)$user['id'], 'client_count_sent', 'Sent count '.$total.' to staff #'.(int)$recipient['id']);
        set_flash('success', 'Client count sent to ' . $recipient['full_name'] . '.');
    }
    redirect('office/client-counts.php');
}

$counts = [];
$countStmt = db()->prepare("SELECT COUNT(*) FROM feedback WHERE office_id=? AND is_void=0 AND LOWER(TRIM(assisted_by)) IN (?,?)");
foreach ($staff as $member) {
    $countStmt->execute([$officeId, mb_strtolower((string)$member['full_name']), mb_strtolower((string)$member['username'])]);
    $counts[(int)$member['id']] = (int)$countStmt->fetchColumn();
}
render_dashboard_start('Client Counts', 'client_counts');
page_header('Staff Catered-Client Counts', 'Send an up-to-date count directly to a staff member’s Notifications.');
?>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Staff</th><th>Current Count</th><th>Send</th></tr></thead><tbody><?php foreach($staff as $member): ?><tr><td><strong><?= e($member['full_name']) ?></strong><br><span class="muted">@<?= e($member['username']) ?></span></td><td><?= (int)$counts[(int)$member['id']] ?></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="staff_id" value="<?= (int)$member['id'] ?>"><button class="btn small">Send Count</button></form></td></tr><?php endforeach; ?><?php if(!$staff): ?><tr><td colspan="3" class="empty-state">No active staff accounts.</td></tr><?php endif; ?></tbody></table></div></section>
<?php render_dashboard_end(); ?>
