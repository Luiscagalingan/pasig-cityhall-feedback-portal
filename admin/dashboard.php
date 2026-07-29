<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_login(['admin']);
render_dashboard_start('Administrator Overview','overview');
$m = dashboard_metrics();
$trend = monthly_trend();
$indicators = indicator_averages();
$comparison = office_comparison();
$recent = recent_feedback();
page_header('Administrator Overview','Consolidated service-satisfaction results across all active offices.','<a class="btn" href="'.e(app_url('admin/offices.php')).'">Add Office & Head</a>');
?>
<div class="stats-grid">
  <div class="stat-card highlight"><small>Total Responses</small><strong><?= $m['total'] ?></strong><span>All active offices</span></div>
  <div class="stat-card"><small>Final Satisfaction</small><strong><?= number_format($m['avg_final'],1) ?>%</strong><span><?= e(final_interpretation($m['avg_final'])) ?></span></div>
  <div class="stat-card"><small>Average Rating</small><strong><?= number_format($m['avg_rating'],2) ?>/4</strong><span>Four service indicators</span></div>
  <div class="stat-card"><small>Positive Sentiment</small><strong><?= number_format($m['positive_rate'],1) ?>%</strong><span><?= $m['positive'] ?> positive comments</span></div>
  <div class="stat-card"><small>Action Completion</small><strong><?= number_format($m['completion_rate'],1) ?>%</strong><span><?= $m['completed_actions'] ?>/<?= $m['total_actions'] ?> completed</span></div>
</div>
<div class="content-grid">
  <section class="card"><div class="card-head"><div><h2>Six-Month Satisfaction Trend</h2><p>Average final weighted score by service month</p></div></div><div class="chart-box"><canvas data-chart='<?= e(json_encode($trend)) ?>' data-type="line"></canvas></div></section>
  <section class="card"><div class="card-head"><div><h2>Sentiment Distribution</h2><p>SVM output for required written comments</p></div></div><div class="sentiment-stack"><div class="sentiment-box positive"><strong><?= $m['positive'] ?></strong><span>Positive</span></div><div class="sentiment-box neutral"><strong><?= $m['neutral'] ?></strong><span>Neutral</span></div><div class="sentiment-box negative"><strong><?= $m['negative'] ?></strong><span>Negative</span></div></div><h2 style="margin-top:24px">Indicator Averages</h2><div class="bar-list"><?php foreach($indicators as $label=>$value): ?><div class="bar-row"><span><?= e($label) ?></span><div class="bar-track"><div class="bar-fill" style="width:<?= min(100,($value/4)*100) ?>%"></div></div><b><?= number_format($value,2) ?></b></div><?php endforeach; ?></div></section>
</div>
<section class="card" style="margin-bottom:16px"><div class="card-head"><div><h2>Office Performance Comparison</h2><p>Automatically includes every active office created by the administrator</p></div></div><div class="table-wrap"><table><thead><tr><th>Office</th><th>Responses</th><th>Average Rating</th><th>Final Score</th><th>Negative</th><th>Interpretation</th></tr></thead><tbody><?php foreach($comparison as $row): ?><tr><td><strong><?= e($row['name']) ?></strong><br><span class="muted"><?= e($row['code']) ?></span></td><td><?= (int)$row['responses'] ?></td><td><?= number_format((float)$row['avg_rating'],2) ?>/4</td><td><?= number_format((float)$row['avg_score'],2) ?>%</td><td><?= (int)$row['negative_count'] ?></td><td><?= badge(final_interpretation((float)$row['avg_score']), (float)$row['avg_score']>=70?'positive':'negative') ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="card"><div class="card-head"><div><h2>Recent Feedback</h2><p>Latest public and imported responses</p></div><a class="btn secondary small" href="<?= e(app_url('admin/feedback.php')) ?>">View all</a></div><div class="table-wrap"><table><thead><tr><th>Date</th><th>Office</th><th>Service</th><th>Sentiment</th><th>Final Score</th><th>Comment</th></tr></thead><tbody><?php foreach($recent as $row): ?><tr><td><?= e(date('M d, Y',strtotime($row['visit_date']))) ?></td><td><?= e($row['office_code']) ?></td><td><?= e($row['service_received']) ?></td><td><?= badge(status_label($row['sentiment']),$row['sentiment']) ?></td><td><?= number_format((float)$row['final_score'],2) ?>%</td><td><?= e(mb_strimwidth($row['comment'],0,100,'…')) ?></td></tr><?php endforeach; ?><?php if(!$recent): ?><tr><td colspan="6" class="empty-state">No feedback yet.</td></tr><?php endif; ?></tbody></table></div></section>
<?php render_dashboard_end(); ?>
