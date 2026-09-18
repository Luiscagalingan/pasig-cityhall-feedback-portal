<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
$user=require_login(['admin']);
if(strtolower((string)$user['username'])!=='uno'){http_response_code(403);exit('Client Counts is available only to Sir Uno.');}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $staffId=post_int('staff_id');
    $stmt=db()->prepare("SELECT u.id,u.full_name,u.username,u.office_id,o.code office_code FROM users u JOIN offices o ON o.id=u.office_id WHERE u.id=? AND u.role='office_staff' AND u.status='active'");
    $stmt->execute([$staffId]); $staff=$stmt->fetch();
    if(!$staff){set_flash('error','Select an active staff account.');}
    else{
        $count=db()->prepare("SELECT COUNT(*) FROM feedback WHERE office_id=? AND is_void=0 AND LOWER(TRIM(assisted_by)) IN (?,?)");
        $count->execute([(int)$staff['office_id'],mb_strtolower((string)$staff['full_name']),mb_strtolower((string)$staff['username'])]);
        $total=(int)$count->fetchColumn();
        $message='Your current client count is '.$total.'. Based on active feedback records matching your name or username.';
        db()->prepare("INSERT INTO notifications(user_id,office_id,sender_user_id,type,title,message) VALUES(?,?,?,'client_count',?,?)")
            ->execute([(int)$staff['id'],(int)$staff['office_id'],(int)$user['id'],'Client count from Administrator',$message]);
        audit((int)$user['id'],'admin_client_count_sent','Sent count '.$total.' to staff #'.(int)$staff['id']);
        set_flash('success','Client count sent to '.$staff['full_name'].'.');
    }
    redirect('admin/client-counts.php');
}
$staff=db()->query("SELECT u.id,u.full_name,u.username,o.code office_code,o.name office_name FROM users u JOIN offices o ON o.id=u.office_id WHERE u.role='office_staff' AND u.status='active' AND o.status='active' ORDER BY o.name,u.full_name")->fetchAll();
$count=db()->prepare("SELECT COUNT(*) FROM feedback WHERE office_id=? AND is_void=0 AND LOWER(TRIM(assisted_by)) IN (?,?)");
$counts=[];
foreach($staff as $member){$count->execute([(int)$member['office_id'],mb_strtolower((string)$member['full_name']),mb_strtolower((string)$member['username'])]);$counts[(int)$member['id']]=(int)$count->fetchColumn();}
render_dashboard_start('Client Counts','client_counts');
page_header('Staff Client Counts','Send the current client count directly to any staff member who requests it.');
?>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Staff</th><th>Office</th><th>Current Count</th><th>Action</th></tr></thead><tbody>
<?php foreach($staff as $member): ?><tr><td><strong><?= e($member['full_name']) ?></strong><br><span class="muted">@<?= e($member['username']) ?></span></td><td><?= e($member['office_code']) ?> — <?= e($member['office_name']) ?></td><td><?= (int)$counts[(int)$member['id']] ?></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="staff_id" value="<?= (int)$member['id'] ?>"><button class="btn small">Send Count</button></form></td></tr><?php endforeach; ?>
<?php if(!$staff): ?><tr><td colspan="4" class="empty-state">No active staff accounts.</td></tr><?php endif; ?></tbody></table></div></section>
<?php render_dashboard_end(); ?>
