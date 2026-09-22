<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
$user=require_login(['admin']);
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();
 $id=post_int('action_id');$requested=(string)($_POST['status']??'');$notes=trim((string)($_POST['resolution_notes']??''));
 if(!in_array($requested,['needs_action','in_progress','completed'],true)||mb_strlen($notes)<5){set_flash('error','Choose a valid status and enter at least 5 characters of notes.');redirect('admin/actions.php');}
 $stmt=db()->prepare('UPDATE actions SET status=?,resolution_notes=?,approved_by_user_id=?,approved_at=CASE WHEN ?="completed" THEN NOW() ELSE NULL END,completed_at=CASE WHEN ?="completed" THEN NOW() ELSE NULL END WHERE id=?');
 $stmt->execute([$requested,$notes,(int)$user['id'],$requested,$requested,$id]);
 audit((int)$user['id'],'admin_action_update','Action #'.$id.' -> '.$requested);
 set_flash('success','Action updated successfully.');
 redirect('admin/actions.php');
}
$officeId=request_int('office_id');$status=trim((string)($_GET['status']??''));$q=trim((string)($_GET['q']??''));$where=["o.status='active'"];$params=[];if($officeId){$where[]='a.office_id=?';$params[]=$officeId;}if(in_array($status,['needs_action','in_progress','pending_approval','completed'],true)){$where[]='a.status=?';$params[]=$status;}if($q!==''){$where[]='(a.title LIKE ? OR a.details LIKE ? OR a.resolution_notes LIKE ? OR CAST(a.id AS CHAR)=?)';$params[]="%{$q}%";$params[]="%{$q}%";$params[]="%{$q}%";$params[]=$q;}
$count=db()->prepare('SELECT COUNT(*) FROM actions a JOIN offices o ON o.id=a.office_id WHERE '.implode(' AND ',$where));$count->execute($params);$p=pagination((int)$count->fetchColumn(),20);$stmt=db()->prepare("SELECT a.*,o.name office_name,o.code office_code,f.sentiment,f.final_score FROM actions a JOIN offices o ON o.id=a.office_id LEFT JOIN feedback f ON f.id=a.feedback_id WHERE ".implode(' AND ',$where)." ORDER BY FIELD(a.status,'pending_approval','needs_action','in_progress','completed'),a.updated_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}");$stmt->execute($params);$rows=$stmt->fetchAll();$offices=active_offices();
$statusCounts=['needs_action'=>0,'in_progress'=>0,'pending_approval'=>0,'completed'=>0];$summaryWhere=["o.status='active'"];$summaryParams=[];if($officeId){$summaryWhere[]='a.office_id=?';$summaryParams[]=$officeId;}$summaryStmt=db()->prepare('SELECT a.status,COUNT(*) total FROM actions a JOIN offices o ON o.id=a.office_id WHERE '.implode(' AND ',$summaryWhere).' GROUP BY a.status');$summaryStmt->execute($summaryParams);foreach($summaryStmt->fetchAll() as $summaryRow)$statusCounts[$summaryRow['status']]=(int)$summaryRow['total'];
render_dashboard_start('Action Management','actions');page_header('Action Management','Full administrator access across all active offices. Update and approve action items here.');
?>
<div class="action-summary"><?php foreach(['needs_action','in_progress','pending_approval','completed'] as $v):?><a class="action-summary-card <?= $status===$v?'active':'' ?>" href="?status=<?= e($v) ?><?= $officeId?'&office_id='.$officeId:'' ?>"><span><?= e(status_label($v)) ?></span><strong><?= $statusCounts[$v] ?></strong></a><?php endforeach;?></div>
<form class="action-filters" method="get"><div><label>Office</label><select name="office_id"><option value="0">All active offices</option><?php foreach($offices as $o):?><option value="<?= (int)$o['id'] ?>" <?= $officeId===(int)$o['id']?'selected':'' ?>><?= e($o['code']) ?> — <?= e($o['name']) ?></option><?php endforeach;?></select></div><div><label>Status</label><select name="status"><option value="">All statuses</option><?php foreach(['needs_action','in_progress','pending_approval','completed'] as $v):?><option value="<?= $v ?>" <?= $status===$v?'selected':'' ?>><?= e(status_label($v)) ?></option><?php endforeach;?></select></div><div class="action-search"><label>Search</label><input name="q" value="<?= e($q) ?>" placeholder="Action ID, concern, or notes"></div><button class="btn">Apply Filters</button><a class="btn secondary" href="<?= e(app_url('admin/actions.php')) ?>">Reset</a></form>
<section class="action-list"><?php foreach($rows as $a):?><article class="action-card"><div class="action-card-main"><div class="action-card-title"><span class="action-id">Action #<?= (int)$a['id'] ?></span><?= badge(status_label($a['status']),$a['status']) ?></div><h2><?= e($a['title']) ?></h2><p><?= e($a['details']?:'No concern details provided.') ?></p><div class="action-meta"><span><b>Office</b><?= e($a['office_code']) ?> · <?= e($a['office_name']) ?></span><span><b>Source</b><?= $a['feedback_id']?'Feedback #'.(int)$a['feedback_id']:'Manual entry' ?></span><?php if($a['sentiment']):?><span><b>Result</b><?= badge(status_label($a['sentiment']),$a['sentiment']) ?> <?= number_format((float)$a['final_score'],2) ?>%</span><?php endif;?><span><b>Last updated</b><?= e(date('M d, Y · h:i A',strtotime($a['updated_at']))) ?></span></div><?php if($a['resolution_notes']):?><div class="resolution-preview"><b>Latest resolution notes</b><p><?= nl2br(e($a['resolution_notes'])) ?></p></div><?php endif;?></div><div class="formula-box"><strong>Administrator Update</strong>
<form method="post" style="margin-top:10px">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<input type="hidden" name="action_id" value="<?= (int)$a['id'] ?>">
<select name="status" required aria-label="Action status">
<?php if($a['status']==='pending_approval'):?><option value="" selected disabled>Pending Approval - choose an update</option><?php endif;?>
<?php foreach(['needs_action','in_progress','completed'] as $updateStatus):?><option value="<?= e($updateStatus) ?>" <?= $a['status']===$updateStatus?'selected':'' ?>><?= e(status_label($updateStatus)) ?></option><?php endforeach;?>
</select>
<textarea name="resolution_notes" minlength="5" required placeholder="Resolution notes"></textarea>
<button class="btn small">Save Update</button>
</form></div></article><?php endforeach;?><?php if(!$rows):?><div class="card empty-state">No action items match the selected filters.</div><?php endif;?><?= pagination_links($p) ?></section>

<?php render_dashboard_end(); ?>
