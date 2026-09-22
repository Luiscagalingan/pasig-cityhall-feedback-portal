<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/output_periods.php';
$user=require_login(['office_head','office_staff']);$officeId=(int)$user['office_id'];
$ownOutput=$user['role']==='office_staff';
$from=trim((string)($_GET['from']??''));$to=trim((string)($_GET['to']??''));$staff=$ownOutput?'':trim((string)($_GET['assisted_by']??''));
if ($ownOutput) {
    try { $period=client_output_month($_GET['month'] ?? null); }
    catch (DomainException $error) { http_response_code(400); exit(e($error->getMessage())); }
    $from=$period['start']; $to=$period['end'];
}
$options=$ownOutput?[]:assisting_staff_options($officeId,$from?:null,$to?:null);
$rows=$ownOutput?staff_client_output_rows($user,$from?:null,$to?:null):client_output_rows($officeId,$from?:null,$to?:null,$staff);
$total=array_sum(array_column($rows,'client_count'));$days=count(array_unique(array_column($rows,'visit_date')));$peak=$rows?max(array_column($rows,'client_count')):0;$avg=$days?$total/$days:0;
render_dashboard_start('Client Output','client_output');page_header('Client Output',$ownOutput?'Your own assisted-client output in '.$user['office_name'].' for '.$period['label'].'.':'Daily catered-client volume for '.$user['office_name'].'.');
?>
<form class="filters wide" method="get"><?php if ($ownOutput): ?><label class="filter-field"><span>Month and Year</span><input type="month" name="month" min="1900-01" max="9998-12" value="<?= e($period['month']) ?>" required></label><?php else: ?><label class="filter-field"><span>Start date</span><input type="date" name="from" value="<?= e($from) ?>"></label><label class="filter-field"><span>End date</span><input type="date" name="to" value="<?= e($to) ?>"></label><select name="assisted_by"><option value="">All Staff (Assisted By)</option><?php foreach($options as $option): ?><option value="<?= e($option['label']) ?>" <?= $staff===$option['label']?'selected':'' ?>><?= e($option['label']) ?> · <?= (int)$option['clients'] ?> clients</option><?php endforeach; ?></select><?php endif; ?><button class="btn">Apply Filters</button></form>
<div class="stats-grid"><div class="stat-card highlight"><small>Total Output</small><strong><?= (int)$total ?></strong><span>client feedback records</span></div><div class="stat-card"><small>Average Daily Output</small><strong><?= number_format($avg,1) ?></strong><span>clients / active day</span></div><div class="stat-card"><small>Peak Daily Output</small><strong><?= (int)$peak ?></strong><span>clients</span></div><div class="stat-card"><small>Active Days</small><strong><?= $days ?></strong><span>within selected range</span></div></div>
<section class="card"><div class="card-head"><div><h2>Daily Output Breakdown</h2><p>Completed feedback records per service date.</p></div></div><div class="table-wrap"><table><thead><tr><th>Date</th><th>Daily Output</th><th>Volume vs. Peak</th><th>Average Rating</th><?php if (!$ownOutput): ?><th>Assisted By</th><?php endif; ?></tr></thead><tbody><?php foreach($rows as $row): $width=$peak?(int)round(((int)$row['client_count']/$peak)*100):0; ?><tr><td><?= e(date('M d, Y',strtotime($row['visit_date']))) ?></td><td><?= badge((string)$row['client_count'].' clients','positive') ?></td><td><div class="bar-track"><div class="bar-fill" style="width:<?= $width ?>%"></div></div><small class="muted"><?= $width ?>%</small></td><td><?= number_format((float)$row['avg_rating'],2) ?>/4</td><?php if (!$ownOutput): ?><td><?= e($row['top_assisting_staff']) ?></td><?php endif; ?></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="<?= $ownOutput?4:5 ?>" class="empty-state">No client output matches the selected filters.</td></tr><?php endif; ?></tbody></table></div></section>
<?php render_dashboard_end(); ?>
