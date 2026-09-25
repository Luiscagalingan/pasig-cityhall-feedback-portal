<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
$token = $argv[1] ?? '';
$path = __DIR__ . '/../storage/import_previews/preview_' . $token . '.json';
if (!preg_match('/^[a-f0-9]{32}$/', $token) || !is_file($path)) throw new RuntimeException('Preview not found.');
$preview = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$pdo=db();$officeId=(int)$preview['office_id'];$userId=(int)$preview['user_id'];$valid=$preview['valid'];$rejected=$preview['rejected'];
$staffMap=[];$s=$pdo->prepare("SELECT id,username,full_name FROM users WHERE office_id=? AND role='office_staff' AND status='active'");$s->execute([$officeId]);
foreach($s->fetchAll() as $u){$staffMap[mb_strtolower(trim($u['username']))]=(int)$u['id'];$staffMap[mb_strtolower(trim($u['full_name']))]=(int)$u['id'];}
$comments=[];$commentIndexes=[];
foreach($valid as $i=>$r){if(trim((string)$r['comment'])!==''){$commentIndexes[]=$i;$comments[]=$r['comment'];}}
$predictions=[];
if($comments){$cmd=[__DIR__.'/../.venv/Scripts/python.exe',__DIR__.'/../ml/predict_batch.py'];$pipes=[];$proc=proc_open($cmd,[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,__DIR__.'/..');if(!is_resource($proc))throw new RuntimeException('Batch predictor unavailable.');fwrite($pipes[0],json_encode($comments,JSON_UNESCAPED_UNICODE));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($proc)!==0)throw new RuntimeException('Batch predictor failed: '.$err);$batch=json_decode($out,true,512,JSON_THROW_ON_ERROR);foreach($commentIndexes as $j=>$index)$predictions[$index]=['label'=>$batch[$j]['label'],'confidence'=>$batch[$j]['confidence'],'source'=>'svm'];}
$pdo->beginTransaction();
try{
 $b=$pdo->prepare('INSERT INTO import_batches(office_id,uploaded_by_user_id,original_filename,total_rows,imported_rows,rejected_rows,duplicate_rows,preview_token,error_summary) VALUES(?,?,?,?,0,?,?,?,?)');
 $b->execute([$officeId,$userId,$preview['filename'],count($valid)+count($rejected),count($rejected),(int)$preview['duplicates'],$token,implode("\n",array_map(fn($r)=>'Row '.$r['row_number'].': '.$r['error'],array_slice($rejected,0,100)))]);$batchId=(int)$pdo->lastInsertId();
 $sql="INSERT INTO feedback(office_id,visit_date,source_timestamp,sex,age,client_type,service_received,timeliness_rating,client_handling_rating,quality_rating,overall_rating,comment,assisted_by,assisted_by_user_id,client_number,surname,given_name,middle_initial,sentiment,original_sentiment,sentiment_confidence,sentiment_source,review_status,model_version,average_rating,rating_percent,comment_score,final_score,source,record_fingerprint,import_batch_id,imported_by_user_id,submitted_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'csv_import',?,?,?,NOW())";
 $ins=$pdo->prepare($sql);$imported=0;
 foreach($valid as $i=>$r){$pred=$predictions[$i]??feedback_sentiment_prediction($r['ratings'],'');$scores=compute_feedback_scores($r['ratings'],$pred['label'],$r['comment']);$review=prediction_review_status($pred);$owner=$staffMap[mb_strtolower(trim((string)$r['assisted_by']))]??null;
  $ins->execute([$officeId,$r['visit_date'],$r['source_timestamp'],$r['sex'],$r['age'],$r['client_type'],$r['service'],$r['ratings'][0],$r['ratings'][1],$r['ratings'][2],$r['ratings'][3],$r['comment'],$r['assisted_by'],$owner,$r['client_number'],$r['surname'],$r['given_name'],$r['middle_initial'],$pred['label'],$pred['label'],$pred['confidence'],$pred['source'],$review,model_version(),$scores['average_rating'],$scores['rating_percent'],$scores['comment_score'],$scores['final_score'],$r['fingerprint'],$batchId,$userId]);$imported++;}
 $rej=$pdo->prepare('INSERT INTO import_rejected_rows(import_batch_id,row_number,raw_row,error_message) VALUES(?,?,?,?)');foreach($rejected as $r)$rej->execute([$batchId,(int)$r['row_number'],'[]',(string)$r['error']]);
 $pdo->prepare('UPDATE import_batches SET imported_rows=? WHERE id=?')->execute([$imported,$batchId]);$pdo->commit();echo json_encode(['batch_id'=>$batchId,'imported'=>$imported,'rejected'=>count($rejected)]).PHP_EOL;
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
