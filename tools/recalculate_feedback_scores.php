<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Recalculate derived scores only; preserve raw answers, labels, reviews and actions.
$pdo = db();
$pdo->beginTransaction();
try {
    $rows = $pdo->query('SELECT id,timeliness_rating,client_handling_rating,quality_rating,overall_rating,sentiment,comment FROM feedback FOR UPDATE');
    $update = $pdo->prepare('UPDATE feedback SET average_rating=?,rating_percent=?,comment_score=?,final_score=? WHERE id=?');
    $count = 0;
    foreach ($rows as $row) {
        $scores = compute_feedback_scores(
            [$row['timeliness_rating'],$row['client_handling_rating'],$row['quality_rating'],$row['overall_rating']],
            $row['sentiment'], (string)$row['comment']
        );
        $update->execute([$scores['average_rating'],$scores['rating_percent'],$scores['comment_score'],$scores['final_score'],$row['id']]);
        $count++;
    }
    $pdo->commit();
    echo "Recalculated {$count} feedback records using 90/10 with comments and 100% ratings without comments.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
