<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
$user = require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'read_all') {
        db()->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE user_id=?')->execute([(int)$user['id']]);
        audit((int)$user['id'], 'notifications_read_all', 'Marked all notifications as read');
        set_flash('success', 'All notifications marked as read.');
    } elseif ($action === 'read') {
        $id = post_int('notification_id');
        db()->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=? AND user_id=?')->execute([$id, (int)$user['id']]);
    }
    redirect('notifications.php');
}
$totalStmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=?');
$totalStmt->execute([(int)$user['id']]);
$p = pagination((int)$totalStmt->fetchColumn(), 25);
$stmt = db()->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY read_at IS NULL DESC,created_at DESC LIMIT ' . $p['per_page'] . ' OFFSET ' . $p['offset']);
$stmt->execute([(int)$user['id']]);
$rows = $stmt->fetchAll();
render_dashboard_start('Notifications', 'notifications');
page_header('Notifications', 'New concerns, review requests, action updates, and approvals.', '<form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="read_all"><button class="btn secondary">Mark All Read</button></form>');
?>
<section class="card"><div class="notification-list"><?php foreach($rows as $n): ?>
<article class="notification-item <?= $n['read_at'] ? '' : 'unread' ?>"><div class="notification-dot"></div><div><div class="notification-meta"><strong><?= e($n['title']) ?></strong><span><?= e(date('M d, Y h:i A', strtotime($n['created_at']))) ?></span></div><p><?= e($n['message']) ?></p><div class="actions-cell"><?php if($n['link_url']): ?><a class="btn small" href="<?= e(app_url($n['link_url'])) ?>"><?= e($n['read_at'] ? 'Open' : 'Review') ?></a><?php endif; ?><?php if(!$n['read_at']): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="read"><input type="hidden" name="notification_id" value="<?= (int)$n['id'] ?>"><button class="btn secondary small">Mark Read</button></form><?php endif; ?></div></div></article>
<?php endforeach; ?><?php if(!$rows): ?><div class="empty-state">No notifications yet.</div><?php endif; ?></div><?= pagination_links($p) ?></section>
<?php render_dashboard_end(); ?>
