<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/functions.php';

$checked = 0;
for ($a=1; $a<=4; $a++) for ($b=1; $b<=4; $b++)
for ($c=1; $c<=4; $c++) for ($d=1; $d<=4; $d++) {
    $ratings = [$a,$b,$c,$d];
    foreach (['positive'=>100, 'neutral'=>50, 'negative'=>0] as $label=>$labelScore) {
        foreach (['', " \t\n", 'Service feedback'] as $comment) {
            $actual = compute_feedback_scores($ratings, $label, $comment);
            $normalized = round((array_sum($ratings)-4)/12*100, 2);
            $hasComment = trim($comment) !== '';
            $expected = $hasComment ? round($normalized*.9+$labelScore*.1,2) : $normalized;
            if (abs($actual['final_score']-$expected)>0.001 ||
                $actual['comment_score'] !== ($hasComment ? (float)$labelScore : 0.0)) {
                throw new RuntimeException('Scoring mismatch: '.json_encode([$ratings,$label,$comment,$actual,$expected]));
            }
            $checked++;
        }
    }
}
foreach ([[33.49,'Negative'],[33.5,'Neutral'],[66.49,'Neutral'],[66.5,'Positive']] as [$score,$expected]) {
    if (final_interpretation($score) !== $expected) throw new RuntimeException('Classification threshold mismatch');
}
echo "PASS: {$checked} scoring cases and classification boundaries.\n";
