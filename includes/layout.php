<?php
declare(strict_types=1);

function icon(string $name): string
{
    $icons = [
        'grid'=>'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'building'=>'<path d="M3 21h18M6 21V4h9v17M15 8h3v13M9 8h2M9 12h2M9 16h2"/>',
        'users'=>'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'database'=>'<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v6c0 1.66 4.03 3 9 3s9-1.34 9-3V5M3 11v6c0 1.66 4.03 3 9 3s9-1.34 9-3v-6"/>',
        'message'=>'<path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/>',
        'check'=>'<path d="M20 6 9 17l-5-5"/>',
        'chart'=>'<path d="M3 3v18h18"/><path d="m7 16 4-5 4 3 5-7"/>',
        'report'=>'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8M8 9h2"/>',
        'logout'=>'<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>',
        'sun'=>'<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41"/>',
        'moon'=>'<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
        'upload'=>'<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
        'bell'=>'<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0"/>',
        'shield'=>'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="m9 12 2 2 4-4"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($icons[$name] ?? $icons['grid']) . '</svg>';
}

function admin_nav(): array
{
    return [
        ['overview','Overview','admin/dashboard.php','grid'],['feedback','Feedback Records','admin/feedback.php','message'],
        ['review','Sentiment Review','review.php','shield'],['actions','Action Management','admin/actions.php','check'],
        ['offices','Offices','admin/offices.php','building'],['heads','Manage Heads','admin/heads.php','users'],
        ['staff','Manage Staff','admin/staff.php','users'],['reports','Reports & Export','admin/reports.php','report'],
        ['client_output','Client Output','admin/client-output.php','chart'],['feedback_insights','Feedback Insights','admin/feedback-insights.php','message'],
        ['rating_distribution','Rating Distribution','rating-distribution.php','chart'],
        ['notifications','Announcements & Updates','notifications.php','bell'],['account','My Account','account.php','users'],
        ['system','System & Audit','admin/system.php','database'],
    ];
}

function office_nav(array $user): array
{
    $nav = [
        ['overview','Overview','office/dashboard.php','grid'],['feedback','Feedback Records','office/feedback.php','message'],
        ['actions','Action Management','office/actions.php','check'],
    ];
    if (($user['role'] ?? '') === 'office_head') {
        $nav[] = ['review','Sentiment Review','review.php','shield'];
        $nav[] = ['data','Dataset & CSV Upload','office/data.php','database'];
        $nav[] = ['staff','Manage Team','office/staff.php','users'];
    }
    if (($user['role'] ?? '') === 'supervisor') $nav[] = ['client_counts','Client Counts','office/client-counts.php','users'];
    if (in_array(($user['role'] ?? ''), ['office_head','supervisor','office_staff'], true)) {
        $nav[] = ['client_output','Client Output','office/client-output.php','chart'];
        $nav[] = ['feedback_insights','Feedback Insights','office/feedback-insights.php','message'];
        $nav[] = ['rating_distribution','Rating Distribution','rating-distribution.php','chart'];
    }
    if (($user['role'] ?? '') === 'office_staff') $nav[] = ['client_request','Request Client Count','office/client-count-request.php','message'];
    if (($user['role'] ?? '') === 'office_staff') $nav[] = ['assisted_survey','Assisted Client Survey','office/assisted-survey.php','message'];
    $nav[] = ['reports','Reports & Export','office/reports.php','report'];
    $nav[] = ['notifications','Announcements & Updates','notifications.php','bell'];
    $nav[] = ['account','My Account','account.php','users'];
    return $nav;
}

function render_public_start(string $title, string $bodyClass = 'public-body'): void
{
    $flash = consume_flash(); ?>
<!doctype html><html lang="en" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><script>try{document.documentElement.dataset.theme=localStorage.getItem('pasig-theme')||((matchMedia('(prefers-color-scheme:dark)').matches)?'dark':'light')}catch(e){}</script><title><?= e($title) ?> | <?= e(APP_SHORT_NAME) ?></title><link rel="stylesheet" href="<?= e(app_url('assets/css/app.css')) ?>?v=29"></head><body class="<?= e($bodyClass) ?>">
<button class="theme-toggle public-theme-toggle" type="button" data-theme-toggle aria-label="Switch color theme"><?= icon('moon') ?><span>Dark mode</span></button>
<?php if ($flash): ?><div class="toast <?= e($flash['type']) ?>" data-toast><?= e($flash['message']) ?></div><?php endif;
}

function render_public_end(): void
{ ?>
<script src="<?= e(app_url('assets/js/app.js')) ?>?v=21"></script></body></html>
<?php }

function render_dashboard_start(string $title, string $active): array
{
    $user = require_login();
    $nav = $user['role'] === 'admin' ? admin_nav() : office_nav($user);
    $flash = consume_flash();
    $officeId = $user['role'] === 'admin' ? null : (int)$user['office_id'];
    $metrics = dashboard_metrics($officeId);
    $unread = unread_notification_count((int)$user['id']);
    $notificationPreview = [];
    try {
        $previewStmt = db()->prepare('SELECT title,message,type,read_at,created_at FROM notifications WHERE user_id=? AND (expires_at IS NULL OR expires_at>NOW()) ORDER BY read_at IS NULL DESC,created_at DESC LIMIT 5');
        $previewStmt->execute([(int)$user['id']]);
        $notificationPreview = $previewStmt->fetchAll();
    } catch (Throwable) {}
    $review = in_array($user['role'], ['admin','office_head'], true) ? review_count($user) : 0;
    $workAlerts = (int)$metrics['needs_action'] + (int)$metrics['in_progress'] + (int)$metrics['pending_approval'] + $review;
    $scopeLabel = $user['role'] === 'admin' ? 'SYSTEM ADMINISTRATOR' : strtoupper(status_label((string)$user['role']));
    ?>
<!doctype html><html lang="en" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><script>try{document.documentElement.dataset.theme=localStorage.getItem('pasig-theme')||((matchMedia('(prefers-color-scheme:dark)').matches)?'dark':'light')}catch(e){}</script><title><?= e($title) ?> | <?= e(APP_SHORT_NAME) ?></title><link rel="stylesheet" href="<?= e(app_url('assets/css/app.css')) ?>?v=29"></head><body class="dashboard-body">
<div class="sidebar-overlay" data-sidebar-close></div><aside class="sidebar"><a class="brand" href="<?= e(app_url(dashboard_path($user))) ?>"><img class="seal" src="<?= e(app_url('assets/images/241304413_194220316131017_8817860418863376271_n.jpg')) ?>" alt="Pasig Public Information Office logo"><div><strong>Pasig City Hall</strong><small>Service Feedback</small></div></a><div class="role-card"><span><?= e(initials($user['full_name'])) ?></span><div><strong><?= e($user['full_name']) ?></strong><small><?= e(status_label($user['role'])) ?></small></div></div><nav>
<?php foreach ($nav as [$key,$label,$path,$iconName]): ?><a class="nav-link <?= $active === $key ? 'active' : '' ?>" href="<?= e(app_url($path)) ?>"><?= icon($iconName) ?><span><?= e($label) ?></span><?php if ($key === 'actions' && $metrics['needs_action'] > 0): ?><b><?= (int)$metrics['needs_action'] ?></b><?php elseif ($key === 'notifications' && $workAlerts > 0): ?><b title="Unresolved work items"><?= $workAlerts ?></b><?php elseif ($key === 'review' && $review > 0): ?><b><?= $review ?></b><?php endif; ?></a><?php endforeach; ?>
</nav><a class="nav-link logout-link" href="<?= e(app_url('logout.php')) ?>" data-confirm-logout><?= icon('logout') ?><span>Logout</span></a></aside>
<div class="app-shell"><header class="topbar"><button class="icon-btn mobile-only" data-sidebar-open aria-label="Open menu"><?= icon('menu') ?></button><div><small><?= $user['role'] === 'admin' ? 'SYSTEM ADMINISTRATION' : 'OFFICE WORKSPACE' ?></small><strong><?= e($user['role'] === 'admin' ? 'Administrator Console' : $user['office_name']) ?></strong></div><div class="topbar-actions"><div class="notification-menu"><button type="button" class="icon-btn notification-link" data-notification-toggle aria-label="Open notifications" aria-expanded="false"><?= icon('bell') ?><?php if($unread): ?><b><?= $unread ?></b><?php endif; ?></button><section class="notification-preview" data-notification-preview aria-hidden="true"><div class="notification-preview-head"><div><strong>Notifications</strong><small><?= $unread ?> unread</small></div><a href="<?= e(app_url('notifications.php')) ?>">View all</a></div><div class="notification-preview-list"><?php foreach($notificationPreview as $item): ?><a class="notification-preview-item <?= $item['read_at']?'':'unread' ?>" href="<?= e(app_url('notifications.php')) ?>"><span><?= e($item['title']) ?></span><p><?= e(mb_strimwidth($item['message'],0,90,'…')) ?></p><small><?= e(date('M d, h:i A',strtotime($item['created_at']))) ?></small></a><?php endforeach; ?><?php if(!$notificationPreview): ?><div class="empty-state">No notifications yet.</div><?php endif; ?></div></section></div><span class="scope-pill"><?= e($scopeLabel) ?></span><button class="theme-toggle" data-theme-toggle><?= icon('sun') ?><span>Light</span></button></div></header><main class="page-content">
<div hidden data-notification-read-config data-url="<?= e(app_url('notification-read.php')) ?>" data-csrf="<?= e(csrf_token()) ?>"></div>
<?php if ($flash): ?><div class="toast <?= e($flash['type']) ?>" data-toast><?= e($flash['message']) ?></div><?php endif;
    return $user;
}

function render_dashboard_end(): void
{ ?>
</main></div><script src="<?= e(app_url('assets/js/app.js')) ?>?v=21"></script></body></html>
<?php }

function page_header(string $title, string $description, string $actions = ''): void
{
    echo '<div class="page-header"><div><h1>' . e($title) . '</h1><p>' . e($description) . '</p></div><div class="header-actions">' . $actions . '</div></div>';
}

function badge(string $text, string $type = 'neutral'): string
{
    return '<span class="badge ' . e($type) . '">' . e($text) . '</span>';
}
