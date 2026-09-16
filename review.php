<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
$user = require_login(['admin','office_head']);
$isAdmin = $user['role'] === 'admin';

if (($_GET['export'] ?? '') === 'training_csv') {
    $where = $isAdmin ? '1=1' : 'f.office_id=?';
    $params = $isAdmin ? [] : [(int)$user['office_id']];
    $stmt = db()->prepare("SELECT tc.id,tc.comment_text,tc.approved_label,o.code office_code,tc.approved_at
                           FROM training_candidates tc JOIN feedback f ON f.id=tc.feedback_id JOIN offices o ON o.id=f.office_id
                           WHERE {$where} ORDER BY tc.approved_at");
    $stmt->execute($params);
    $rows = [];
    $ids = [];
    foreach ($stmt->fetchAll() as $r) { $rows[] = [$r['comment_text'],$r['approved_label'],$r['office_code'],$r['approved_at']]; $ids[] = (int)$r['id']; }
    if ($ids) db()->exec('UPDATE training_candidates SET exported_at=NOW() WHERE id IN (' . implode(',', $ids) . ')');
    audit((int)$user['id'], 'training_dataset_export', 'Exported ' . count($rows) . ' reviewed training candidates');
    csv_download('reviewed-training-candidates-' . date('Y-m-d') . '.csv', ['comment','label','office_code','approved_at'], $rows);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'correct');
    $feedbackId = post_int('feedback_id');
    $scopeSql = $isAdmin ? '' : ' AND office_id=?';
    $scopeParams = $isAdmin ? [$feedbackId] : [$feedbackId, (int)$user['office_id']];
    $stmt = db()->prepare('SELECT * FROM feedback WHERE id=?' . $scopeSql . ' LIMIT 1');
    $stmt->execute($scopeParams);
    $feedback = $stmt->fetch();
    if (!$feedback) { set_flash('error', 'Feedback record not found in your scope.'); redirect('review.php'); }

    try {
        if ($action === 'correct') {
            $label = (string)($_POST['sentiment'] ?? '');
            $notes = trim((string)($_POST['reviewer_notes'] ?? ''));
            if (!in_array($label, ['positive','neutral','negative'], true) || mb_strlen($notes) < 5) throw new RuntimeException('Select the verified sentiment and enter review notes.');
            $ratings = [(int)$feedback['timeliness_rating'],(int)$feedback['client_handling_rating'],(int)$feedback['quality_rating'],(int)$feedback['overall_rating']];
            $scores = compute_feedback_scores($ratings, $label, (string)$feedback['comment']);
            db()->beginTransaction();
            $update = db()->prepare("UPDATE feedback SET sentiment=?,sentiment_source='manual',review_status='reviewed',reviewed_by_user_id=?,reviewed_at=NOW(),reviewer_notes=?,comment_score=?,final_score=? WHERE id=?");
            $update->execute([$label,(int)$user['id'],$notes,$scores['comment_score'],$scores['final_score'],$feedbackId]);
            if (isset($_POST['add_training'])) {
                db()->prepare("INSERT INTO training_candidates(feedback_id,comment_text,approved_label,approved_by_user_id)
                               VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE comment_text=VALUES(comment_text),approved_label=VALUES(approved_label),approved_by_user_id=VALUES(approved_by_user_id),approved_at=CURRENT_TIMESTAMP,exported_at=NULL")
                    ->execute([$feedbackId,$feedback['comment'],$label,(int)$user['id']]);
            }
            $actionStmt = db()->prepare('SELECT id,status FROM actions WHERE feedback_id=? LIMIT 1');
            $actionStmt->execute([$feedbackId]);
            $existingAction = $actionStmt->fetch();
            if ($label === 'negative' || $scores['final_score'] < ACTION_SCORE_THRESHOLD) {
                create_action_if_needed($feedbackId, (int)$feedback['office_id'], $label, $scores['final_score'], (string)$feedback['comment']);
            } elseif ($existingAction && $existingAction['status'] !== 'completed') {
                db()->prepare("UPDATE actions SET status='completed',resolution_notes=CONCAT(COALESCE(resolution_notes,''),'\nClosed after verified sentiment review: ',?),approved_by_user_id=?,approved_at=NOW(),completed_at=NOW() WHERE id=?")
                    ->execute([$notes,(int)$user['id'],(int)$existingAction['id']]);
            }
            db()->commit();
            audit((int)$user['id'], 'sentiment_review', "Feedback #{$feedbackId}: {$feedback['sentiment']} -> {$label}; training=" . (isset($_POST['add_training'])?'yes':'no'));
            set_flash('success', 'Sentiment verified and weighted score recalculated.');
        } elseif ($action === 'void') {
            $reason = trim((string)($_POST['void_reason'] ?? ''));
            if (mb_strlen($reason) < 5) throw new RuntimeException('Enter a clear reason for voiding this feedback.');
            db()->beginTransaction();
            db()->prepare('UPDATE feedback SET is_void=1,void_reason=?,voided_by_user_id=?,voided_at=NOW() WHERE id=?')->execute([$reason,(int)$user['id'],$feedbackId]);
            db()->prepare("UPDATE actions SET status='completed',resolution_notes=CONCAT(COALESCE(resolution_notes,''),'\nSource feedback voided: ',?),approved_by_user_id=?,approved_at=NOW(),completed_at=NOW() WHERE feedback_id=? AND status<>'completed'")
                ->execute([$reason,(int)$user['id'],$feedbackId]);
            db()->commit();
            audit((int)$user['id'], 'feedback_void', "Feedback #{$feedbackId}: {$reason}");
            set_flash('success', 'Feedback marked as void. Original data and audit history were preserved.');
        } elseif ($action === 'unvoid') {
            db()->beginTransaction();
            db()->prepare('UPDATE feedback SET is_void=0,void_reason=NULL,voided_by_user_id=NULL,voided_at=NULL WHERE id=?')->execute([$feedbackId]);
            create_action_if_needed(
                $feedbackId,
                (int)$feedback['office_id'],
                (string)$feedback['sentiment'],
                (float)$feedback['final_score'],
                (string)$feedback['comment']
            );
            db()->commit();
            audit((int)$user['id'], 'feedback_unvoid', 'Restored feedback #' . $feedbackId . ' and re-evaluated its action requirement');
            set_flash('success', 'Feedback restored and its action requirement was re-evaluated.');
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        set_flash('error', $e->getMessage());
    }
    redirect('review.php');
}

$officeId = $isAdmin ? request_int('office_id') : (int)$user['office_id'];
$status = trim((string)($_GET['status'] ?? 'needs_review'));
$q = trim((string)($_GET['q'] ?? ''));
$includeVoid = isset($_GET['include_void']);
$where = ['1=1']; $params = [];
if ($officeId) { $where[]='f.office_id=?'; $params[]=$officeId; }
if (in_array($status,['needs_review','reviewed','not_required'],true)) { $where[]='f.review_status=?'; $params[]=$status; }
if (!$includeVoid) $where[]='f.is_void=0';
if ($q !== '') { $where[]='(f.comment LIKE ? OR f.service_received LIKE ? OR CAST(f.id AS CHAR)=?)'; $params[]="%{$q}%"; $params[]="%{$q}%"; $params[]=$q; }
$countStmt = db()->prepare('SELECT COUNT(*) FROM feedback f WHERE ' . implode(' AND ',$where)); $countStmt->execute($params); $p=pagination((int)$countStmt->fetchColumn(),20);
$stmt = db()->prepare("SELECT f.*,o.code office_code,o.name office_name,u.full_name reviewer_name FROM feedback f JOIN offices o ON o.id=f.office_id LEFT JOIN users u ON u.id=f.reviewed_by_user_id WHERE ".implode(' AND ',$where)." ORDER BY f.review_status='needs_review' DESC,f.submitted_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}");
$stmt->execute($params); $rows=$stmt->fetchAll();
$offices = $isAdmin ? db()->query('SELECT * FROM offices ORDER BY status=\'active\' DESC,name')->fetchAll() : [];
render_dashboard_start('Sentiment Review','review');
page_header('Sentiment Review','Verify low-confidence or fallback classifications, preserve corrections, and approve examples for retraining.','<a class="btn secondary" href="?export=training_csv">Export Reviewed Training CSV</a>');
?>
<form class="filters wide" method="get"><?php if($isAdmin): ?><select name="office_id"><option value="0">All offices</option><?php foreach($offices as $o): ?><option value="<?= (int)$o['id'] ?>" <?= $officeId===(int)$o['id']?'selected':'' ?>><?= e($o['code']) ?> — <?= e($o['name']) ?></option><?php endforeach; ?></select><?php endif; ?><select name="status"><option value="needs_review" <?= $status==='needs_review'?'selected':'' ?>>Needs Review</option><option value="reviewed" <?= $status==='reviewed'?'selected':'' ?>>Reviewed</option><option value="not_required" <?= $status==='not_required'?'selected':'' ?>>No Review Required</option><option value="" <?= $status===''?'selected':'' ?>>All</option></select><input name="q" value="<?= e($q) ?>" placeholder="Search ID, service, or comment"><label class="checkbox-inline"><input type="checkbox" name="include_void" value="1" <?= $includeVoid?'checked':'' ?>> Include void</label><button class="btn">Apply</button></form>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Feedback</th><th>Prediction</th><th>Weighted Result</th><th>Human Review / Correction</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr class="<?= $r['is_void']?'void-row':'' ?>"><td><strong>#<?= (int)$r['id'] ?> · <?= e($r['office_code']) ?></strong><br><span class="muted"><?= e($r['visit_date']) ?> · <?= e($r['service_received']) ?></span><p><?= e($r['comment']) ?></p><?php if($r['is_void']): ?><?= badge('Void','negative') ?><br><small><?= e($r['void_reason']) ?></small><?php endif; ?></td><td><?= badge(status_label($r['sentiment']),$r['sentiment']) ?><br><span class="muted"><?= number_format((float)$r['sentiment_confidence']*100,2) ?>% · <?= e($r['sentiment_source']) ?></span><br><?= badge(status_label($r['review_status']),$r['review_status']==='needs_review'?'negative':'positive') ?><br><small><?= e($r['model_version']) ?></small></td><td><strong><?= number_format((float)$r['final_score'],2) ?>%</strong><br><span class="muted">Rating <?= number_format((float)$r['rating_percent'],2) ?>% × <?= trim((string)$r['comment']) !== '' ? '90%' : '100%' ?><?php if(trim((string)$r['comment']) !== ''): ?><br>Comment <?= number_format((float)$r['comment_score'],2) ?>% × 10%<?php else: ?><br>No comment — ratings only<?php endif; ?></span></td><td><?php if(!$r['is_void']): ?><form method="post" class="review-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="correct"><input type="hidden" name="feedback_id" value="<?= (int)$r['id'] ?>"><select name="sentiment" required><?php foreach(['positive','neutral','negative'] as $v): ?><option value="<?= $v ?>" <?= $r['sentiment']===$v?'selected':'' ?>><?= e(status_label($v)) ?></option><?php endforeach; ?></select><textarea name="reviewer_notes" required minlength="5" placeholder="Explain why this is the verified label."><?= e($r['reviewer_notes']) ?></textarea><label class="checkbox-row"><input type="checkbox" name="add_training" value="1"><span>Add the de-identified comment and verified label to the retraining candidates.</span></label><button class="btn small">Verify & Recalculate</button></form><details><summary class="btn danger small">Void Incorrect Record</summary><form method="post" class="details-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="void"><input type="hidden" name="feedback_id" value="<?= (int)$r['id'] ?>"><textarea name="void_reason" required minlength="5" placeholder="Reason for voiding"></textarea><button class="btn danger small">Confirm Void</button></form></details><?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="unvoid"><input type="hidden" name="feedback_id" value="<?= (int)$r['id'] ?>"><button class="btn success small">Restore Record</button></form><?php endif; ?><?php if($r['reviewer_name']): ?><p class="help">Reviewed by <?= e($r['reviewer_name']) ?> on <?= e(date('M d, Y h:i A',strtotime($r['reviewed_at']))) ?></p><?php endif; ?></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="4" class="empty-state">No feedback matches the review filters.</td></tr><?php endif; ?></tbody></table></div><?= pagination_links($p) ?></section>
<?php render_dashboard_end(); ?>
