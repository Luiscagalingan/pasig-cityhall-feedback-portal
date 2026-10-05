<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/feedback_insights_view.php';
$user=require_login(['office_head','office_staff']);
$officeId=(int)$user['office_id'];
$staffUserId=$user['role']==='office_staff'?(int)$user['id']:null;
$month=trim((string)($_GET['month']??''));
if ($month !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month='';
$yearSql='SELECT DISTINCT YEAR(visit_date) AS year FROM feedback WHERE office_id=?';
$yearParams=[$officeId];
if($staffUserId){$yearSql.=" AND ((source='assisted_survey' AND imported_by_user_id=?) OR (source='csv_import' AND assisted_by_user_id=?))";$yearParams[]=$staffUserId;$yearParams[]=$staffUserId;}
$yearStmt=db()->prepare($yearSql.' ORDER BY year DESC');
$yearStmt->execute($yearParams);
$years=array_map('intval',array_column($yearStmt->fetchAll(),'year'));
$currentYear=(int)date('Y');
if (!in_array($currentYear,$years,true)) array_unshift($years,$currentYear);
$highlights=feedback_highlights($officeId,$month?:null,5,$staffUserId);
render_dashboard_start('Feedback Insights','feedback_insights');page_header('Feedback Insights',$user['role']==='office_staff'?'Positive feedback and areas for improvement from clients you assisted.':'Positive feedback and areas for improvement for '.$user['office_name'].'.');
?>
<form class="filters month-filter" method="get"><select name="month" aria-label="Filter by month"><option value="">All Months</option><?php foreach($years as $year): ?><?php for($monthNumber=1;$monthNumber<=12;$monthNumber++): $value=sprintf('%04d-%02d',$year,$monthNumber); ?><option value="<?= e($value) ?>" <?= $month===$value?'selected':'' ?>><?= e(date('F Y',strtotime($value.'-01'))) ?></option><?php endfor; ?><?php endforeach; ?></select><button class="btn">Apply</button></form>
<div class="content-grid"><section class="card"><div class="card-head"><div><h2>Top 5 Good Comments</h2><p>Most recent positive feedback<?= $month?' for '.e(date('F Y',strtotime($month.'-01'))):'' ?>.</p></div></div><div class="notification-list"><?php render_feedback_insight_list($highlights['good'],'good',false); ?><?php if(!$highlights['good']): ?><div class="empty-state">No positive comments for this period.</div><?php endif; ?></div></section><section class="card"><div class="card-head"><div><h2>Top 5 Critical Feedback</h2><p>Recent negative feedback requiring attention.</p></div></div><div class="notification-list"><?php render_feedback_insight_list($highlights['critical'],'critical',false); ?><?php if(!$highlights['critical']): ?><div class="empty-state">No critical feedback for this period.</div><?php endif; ?></div></section></div>
<?php render_dashboard_end(); ?>
