<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
$head = require_login(['office_head']);
$stmt = db()->prepare("SELECT full_name,username,email,status,created_at FROM users WHERE office_id=? AND role='office_staff' ORDER BY full_name");
$stmt->execute([(int)$head['office_id']]);
$staff = $stmt->fetchAll();
render_dashboard_start('View Team', 'staff');
page_header($head['office_code'] . ' Office Team', 'View only. Administrators manage staff accounts.');
?>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Staff</th><th>Username</th><th>Email</th><th>Status</th><th>Created</th></tr></thead><tbody>
<?php foreach ($staff as $member): ?><tr><td><?= e($member['full_name']) ?></td><td><?= e($member['username']) ?></td><td><?= e($member['email']) ?></td><td><?= e($member['status']) ?></td><td><?= e($member['created_at']) ?></td></tr><?php endforeach; ?>
<?php if (!$staff): ?><tr><td colspan="5" class="empty-state">No staff accounts in this office.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php render_dashboard_end(); ?>
