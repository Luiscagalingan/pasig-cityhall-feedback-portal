<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$user=require_login(['office_head']);
$officeId=(int)$user['office_id'];
$q=trim((string)($_GET['q']??''));
$actionFilter=trim((string)($_GET['action_filter']??''));
$roleFilter=trim((string)($_GET['role_filter']??''));
$validRoles=['office_head','supervisor','office_staff'];
$where=['u.office_id=?'];
$params=[$officeId];

if($q!==''){
    $where[]='(u.full_name LIKE ? OR u.username LIKE ? OR a.action LIKE ? OR a.details LIKE ? OR a.ip_address LIKE ?)';
    array_push($params,"%{$q}%","%{$q}%","%{$q}%","%{$q}%","%{$q}%");
}
if($actionFilter!==''){$where[]='a.action=?';$params[]=$actionFilter;}
if(in_array($roleFilter,$validRoles,true)){$where[]='u.role=?';$params[]=$roleFilter;}

$whereSql=implode(' AND ',$where);
$count=db()->prepare('SELECT COUNT(*) FROM audit_logs a JOIN users u ON u.id=a.user_id WHERE '.$whereSql);
$count->execute($params);
$p=pagination((int)$count->fetchColumn(),30);
$stmt=db()->prepare("SELECT a.*,u.full_name,u.username,u.role FROM audit_logs a JOIN users u ON u.id=a.user_id WHERE {$whereSql} ORDER BY a.created_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}");
$stmt->execute($params);
$logs=$stmt->fetchAll();
$actionStmt=db()->prepare('SELECT DISTINCT a.action FROM audit_logs a JOIN users u ON u.id=a.user_id WHERE u.office_id=? ORDER BY a.action');
$actionStmt->execute([$officeId]);
$actions=$actionStmt->fetchAll(PDO::FETCH_COLUMN);

render_dashboard_start('System Audit','office_audit');
page_header($user['office_code'].' System Audit','Makikita rito ang lahat ng naka-log na gawain ng Head, Supervisor, at Staff sa iyong opisina.');
?>
<div class="tabs"><a class="tab <?= $roleFilter===''?'active':'' ?>" href="?<?= e(http_build_query(array_filter(['q'=>$q,'action_filter'=>$actionFilter]))) ?>">Lahat</a><?php foreach(['office_head'=>'Office Head','supervisor'=>'Supervisor','office_staff'=>'Office Staff'] as $roleKey=>$roleLabel):?><a class="tab <?= $roleFilter===$roleKey?'active':'' ?>" href="?<?= e(http_build_query(array_filter(['q'=>$q,'action_filter'=>$actionFilter,'role_filter'=>$roleKey]))) ?>"><?= e($roleLabel) ?></a><?php endforeach;?></div>
<section class="card"><div class="card-head"><div><h2>Activity Log</h2><p><?= (int)$p['total'] ?> naitalang aktibidad sa <?= e($user['office_name']) ?></p></div></div>
<form class="filters wide" method="get"><input name="q" value="<?= e($q) ?>" placeholder="Hanapin ang user, action, detalye, o IP"><select name="role_filter"><option value="">Lahat ng role</option><?php foreach(['office_head'=>'Office Head','supervisor'=>'Supervisor','office_staff'=>'Office Staff'] as $roleKey=>$roleLabel):?><option value="<?= $roleKey ?>" <?= $roleFilter===$roleKey?'selected':'' ?>><?= e($roleLabel) ?></option><?php endforeach;?></select><select name="action_filter"><option value="">Lahat ng action</option><?php foreach($actions as $action):?><option value="<?= e($action) ?>" <?= $actionFilter===$action?'selected':'' ?>><?= e(status_label($action)) ?></option><?php endforeach;?></select><button class="btn">I-filter</button></form>
<div class="table-wrap"><table><thead><tr><th>Petsa at Oras</th><th>User</th><th>Role</th><th>Action</th><th>Detalye</th><th>IP Address</th></tr></thead><tbody><?php foreach($logs as $log):?><tr><td><?= e(date('M d, Y h:i A',strtotime($log['created_at']))) ?></td><td><strong><?= e($log['full_name']) ?></strong><br><span class="muted"><?= e($log['username']) ?></span></td><td><?= badge(status_label($log['role']),$log['role']==='office_head'?'neutral':'positive') ?></td><td><?= e(status_label($log['action'])) ?></td><td><?= e($log['details']) ?></td><td><?= e($log['ip_address']) ?></td></tr><?php endforeach;?><?php if(!$logs):?><tr><td colspan="6" class="empty-state">Walang audit record na tumutugma sa filter.</td></tr><?php endif;?></tbody></table></div><?= pagination_links($p) ?></section>
<?php render_dashboard_end(); ?>
