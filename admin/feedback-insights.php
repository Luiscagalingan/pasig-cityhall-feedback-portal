<?php
declare(strict_types=1);
require_once __DIR__ . "/../includes/bootstrap.php";
require_once __DIR__ . "/../includes/feedback_insights_view.php";
$user = require_login(["admin"]);
$month = trim((string) ($_GET["month"] ?? ""));
if ($month !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = '';
$years = array_map('intval', array_column(db()->query("SELECT DISTINCT YEAR(f.visit_date) AS year FROM feedback f JOIN offices o ON o.id=f.office_id WHERE o.status='active' ORDER BY year DESC")->fetchAll(), 'year'));
$currentYear = (int)date('Y');
if (!in_array($currentYear, $years, true)) array_unshift($years, $currentYear);
$highlights = feedback_highlights(null, $month ?: null);
render_dashboard_start("Feedback Insights", "feedback_insights");
page_header("Feedback Insights", "Positive feedback and areas for improvement across active offices.");
?>
<form class="filters month-filter" method="get"><select name="month" aria-label="Filter by month"><option value="">All Months</option><?php foreach($years as $year): ?><?php for($monthNumber=1;$monthNumber<=12;$monthNumber++): $value=sprintf('%04d-%02d',$year,$monthNumber); ?><option value="<?= e($value) ?>" <?= $month===$value?'selected':'' ?>><?= e(date('F Y',strtotime($value.'-01'))) ?></option><?php endfor; ?><?php endforeach; ?></select><button class="btn">Apply</button></form>
<div class="content-grid"><section class="card"><div class="card-head"><div><h2>Top 5 Good Comments</h2><p>Most recent positive feedback<?= $month
    ? " for " . e(date("F Y", strtotime($month . "-01")))
    : "" ?>.</p></div></div><div class="notification-list"><?php
foreach (
    $highlights["good"]
    as $item
): ?><?php render_feedback_insight_list([$item], 'good', true); ?><?php endforeach;
if (!$highlights["good"]): ?><div class="empty-state">No positive comments for this period.</div><?php endif;
?></div></section><section class="card"><div class="card-head"><div><h2>Top 5 Critical Feedback</h2><p>Recent negative feedback requiring attention.</p></div></div><div class="notification-list"><?php
foreach (
    $highlights["critical"]
    as $item
): ?><?php render_feedback_insight_list([$item], 'critical', true); ?><?php endforeach;
if (!$highlights["critical"]): ?><div class="empty-state">No critical feedback for this period.</div><?php endif;
?></div></section></div>
<?php render_dashboard_end(); ?>
