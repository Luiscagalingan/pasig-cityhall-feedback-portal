<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$canAnnounce = $user['role'] === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'read_all') {
        db()->prepare("UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE user_id=? AND (type<>'client_count_request' OR user_id=(SELECT uno.id FROM users uno WHERE uno.username='uno' LIMIT 1)) AND (expires_at IS NULL OR expires_at>NOW())")->execute([(int)$user['id']]);
        audit((int)$user['id'], 'notifications_read_all', 'Marked all notifications as read');
        set_flash('success', 'All notifications marked as read.');
    } elseif ($action === 'read') {
        $notificationId=post_int('notification_id');
        db()->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=? AND user_id=?')->execute([$notificationId, (int)$user['id']]);
        audit((int)$user['id'], 'notification_read', 'Binasa ang notification #'.$notificationId);
    } elseif ($action === 'delete') {
        $stmt = db()->prepare('DELETE FROM notifications WHERE id=? AND user_id=? AND created_at<=DATE_SUB(NOW(),INTERVAL 7 DAY)');
        $notificationId=post_int('notification_id');$stmt->execute([$notificationId, (int)$user['id']]);
        if($stmt->rowCount())audit((int)$user['id'], 'notification_delete', 'Binura ang notification #'.$notificationId);
        set_flash($stmt->rowCount() ? 'success' : 'error', $stmt->rowCount() ? 'Notification deleted.' : 'Notifications can only be deleted after seven days.');
    } elseif ($action === 'announce' && $canAnnounce) {
        $audienceType = (string)($_POST['audience'] ?? 'all');
        $title = trim((string)($_POST['title'] ?? ''));
        $message = trim((string)($_POST['message'] ?? ''));
        if (!in_array($audienceType, ['all','staff'], true)) {
            set_flash('error', 'Select a valid announcement audience.');
        } elseif ($title === '' || $message === '' || mb_strlen($title) > 180 || mb_strlen($message) > 2000) {
            set_flash('error', 'Enter a title and message within the allowed length.');
        } else {
            if ($audienceType === 'staff') {
                $recipients = db()->query("SELECT id,office_id FROM users WHERE role='office_staff' AND status='active'")->fetchAll();
                $audience = 'active Office Staff';
            } else {
                $stmt = db()->prepare("SELECT id,office_id FROM users WHERE role IN ('admin','office_head','office_staff') AND status='active' AND id<>?");
                $stmt->execute([(int)$user['id']]);
                $recipients = $stmt->fetchAll();
                $audience = 'active users';
            }
            $insert = db()->prepare("INSERT INTO notifications (user_id,office_id,sender_user_id,type,title,message,expires_at) VALUES (?,?,?,'announcement',?,?,DATE_ADD(NOW(),INTERVAL 7 DAY))");
            foreach ($recipients as $recipient) $insert->execute([(int)$recipient['id'], $recipient['office_id'] ?: null, (int)$user['id'], $title, $message]);
            audit((int)$user['id'], 'announcement_sent', 'Sent announcement to '.count($recipients).' '.$audience);
            set_flash('success', 'Announcement sent to '.count($recipients).' '.$audience.'. It will expire after seven days.');
        }
    }
    redirect('notifications.php');
}

// Expired announcements are removed as part of normal notification access.
db()->exec("DELETE FROM notifications WHERE type='announcement' AND expires_at IS NOT NULL AND expires_at<=NOW()");
$totalStmt = db()->prepare("SELECT COUNT(*) FROM notifications n JOIN users recipient ON recipient.id=n.user_id WHERE n.user_id=? AND (n.type<>'client_count_request' OR recipient.username='uno') AND (n.expires_at IS NULL OR n.expires_at>NOW())");
$totalStmt->execute([(int)$user['id']]);
$p = pagination((int)$totalStmt->fetchColumn(), 25);
$stmt = db()->prepare("SELECT n.*,u.full_name sender_name FROM notifications n JOIN users recipient ON recipient.id=n.user_id LEFT JOIN users u ON u.id=n.sender_user_id WHERE n.user_id=? AND (n.type<>'client_count_request' OR recipient.username='uno') AND (n.expires_at IS NULL OR n.expires_at>NOW()) ORDER BY n.read_at IS NULL DESC,n.created_at DESC LIMIT ".$p['per_page'].' OFFSET '.$p['offset']);
$stmt->execute([(int)$user['id']]);
$rows = $stmt->fetchAll();
$header = '<form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="read_all"><button class="btn secondary">Mark All Read</button></form>';
if ($canAnnounce) $header = '<button type="button" class="btn" data-modal-open="announcement-modal">Create Announcement</button>'.$header;
render_dashboard_start('Announcements & Updates', 'notifications');
page_header('Announcements & Updates', $user['role']==='admin' ? 'Create audience-specific announcements and monitor system events.' : 'View announcements and system updates intended for your account.', $header);
?>
<?php if($canAnnounce): ?><div class="app-modal" id="announcement-modal" aria-hidden="true"><section class="app-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="announcement-title"><button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button><h2 id="announcement-title">Create Announcement</h2><p class="muted">Choose who should receive this announcement. It will expire after seven days.</p><form method="post" class="modal-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="announce"><div><strong class="field-heading">Audience</strong><div class="scope-options announcement-audience"><label class="scope-option"><input type="radio" name="audience" value="all" checked><span><strong>All Users</strong><br><small>All active administrators, office heads, and staff.</small></span></label><label class="scope-option"><input type="radio" name="audience" value="staff"><span><strong>Office Staff Only</strong><br><small>Only active Office Staff accounts.</small></span></label></div></div><label>Title<input name="title" maxlength="180" required></label><label>Message<textarea name="message" maxlength="2000" rows="6" required></textarea></label><div class="confirm-actions"><button type="button" class="btn secondary" data-modal-close>Cancel</button><button class="btn">Send Announcement</button></div></form></section></div><?php endif; ?>
<section class="card"><div class="notification-list"><?php foreach($rows as $n): ?>
<article class="notification-item <?= $n['read_at'] ? '' : 'unread' ?>"><div class="notification-dot"></div><div><div class="notification-meta"><strong><?= e($n['title']) ?><?= $n['type']==='announcement' ? ' '.badge('Announcement','neutral') : '' ?></strong><span><?= e(date('M d, Y h:i A', strtotime($n['created_at']))) ?></span></div><?php if($n['sender_name']): ?><small class="muted">From <?= e($n['sender_name']) ?></small><?php endif; ?><p><?= nl2br(e($n['message'])) ?></p><div class="actions-cell"><?php if($n['link_url']): ?><a class="btn small" href="<?= e(app_url($n['link_url'])) ?>"><?= e($n['read_at'] ? 'Open' : 'Review') ?></a><?php endif; ?><?php if(!$n['read_at']): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="read"><input type="hidden" name="notification_id" value="<?= (int)$n['id'] ?>"><button class="btn secondary small">Mark Read</button></form><?php endif; ?><?php if(strtotime($n['created_at']) <= strtotime('-7 days')): ?><form method="post" data-confirm-delete="Delete this notification permanently?"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="notification_id" value="<?= (int)$n['id'] ?>"><button class="btn danger small">Delete</button></form><?php endif; ?></div></div></article>
<?php endforeach; ?><?php if(!$rows): ?><div class="empty-state">No notifications yet.</div><?php endif; ?></div><?= pagination_links($p) ?></section>
<?php render_dashboard_end(); ?>
