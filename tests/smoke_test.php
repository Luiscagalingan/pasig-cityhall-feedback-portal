<?php
declare(strict_types=1);
$testSessionPath = __DIR__ . '/../storage/test_sessions';
if (!is_dir($testSessionPath)) mkdir($testSessionPath, 0775, true);
ini_set('session.save_path', $testSessionPath);
require_once __DIR__ . '/../includes/bootstrap.php';

$results=[];
function check_result(array &$results,string $name,bool $ok,string $detail=''): void {$results[]=['name'=>$name,'ok'=>$ok,'detail'=>$detail];}

check_result($results,'PHP version',version_compare(PHP_VERSION,'8.1.0','>='),PHP_VERSION);
check_result($results,'Python executable',is_file(PYTHON_BIN),PYTHON_BIN);
check_result($results,'SVM model file',is_file(__DIR__.'/../ml/models/svm_sentiment.joblib'));
$chapter2Example=compute_feedback_scores([4,3,4,4],'negative');
check_result(
    $results,
    'Chapter 2 weighted formula',
    abs((float)$chapter2Example['final_score'] - 55.0) < 0.01,
    json_encode($chapter2Example)
);
$prediction=predict_sentiment('Mabilis at maayos ang serbisyo.');
check_result($results,'PHP-to-SVM bridge',($prediction['source']??'')==='svm',json_encode($prediction));

try{
 $pdo=db();check_result($results,'Database connection',true);
 $requiredTables=['offices','users','feedback','actions','import_batches','import_rejected_rows','notifications','login_attempts','public_submission_log','training_candidates','audit_logs'];
 $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);foreach($requiredTables as $table)check_result($results,'Table: '.$table,in_array($table,$tables,true));
 $requiredColumns=['review_status','is_void','record_fingerprint','import_batch_id','model_version'];$cols=$pdo->query('SHOW COLUMNS FROM feedback')->fetchAll(PDO::FETCH_COLUMN);foreach($requiredColumns as $col)check_result($results,'feedback.'.$col,in_array($col,$cols,true));
 $actionCols=$pdo->query('SHOW COLUMNS FROM actions')->fetchAll(PDO::FETCH_COLUMN);check_result($results,'Head approval columns',in_array('approved_by_user_id',$actionCols,true)&&in_array('completion_requested_by_user_id',$actionCols,true));
}catch(Throwable $e){check_result($results,'Database connection',false,$e->getMessage());}

$failed=0;echo "PASIG FEEDBACK PORTAL SMOKE TEST\n".str_repeat('=',42)."\n";foreach($results as $r){echo ($r['ok']?'[PASS] ':'[FAIL] ').$r['name'].($r['detail']!==''?' — '.$r['detail']:'')."\n";if(!$r['ok'])$failed++;}echo str_repeat('-',42)."\n";echo $failed===0?"All smoke tests passed.\n":"{$failed} smoke test(s) failed. Fix them before deployment.\n";exit($failed===0?0:1);
