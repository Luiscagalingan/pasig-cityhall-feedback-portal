<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
$user=require_login(['admin','office_head']);$batchId=request_int('batch_id');
$where=$user['role']==='admin'?'id=?':'id=? AND office_id=?';
$params=$user['role']==='admin'?[$batchId]:[$batchId,(int)$user['office_id']];
$stmt=db()->prepare('SELECT * FROM import_batches WHERE '.$where);$stmt->execute($params);$batch=$stmt->fetch();if(!$batch){http_response_code(404);exit('Import batch not found.');}$stmt=db()->prepare('SELECT row_number,raw_row,error_message FROM import_rejected_rows WHERE import_batch_id=? ORDER BY row_number');$stmt->execute([$batchId]);$rows=[];foreach($stmt->fetchAll() as $r)$rows[]=[(int)$r['row_number'],$r['error_message'],$r['raw_row']];audit((int)$user['id'],'rejected_rows_export','Downloaded rejected rows for batch #'.$batchId);csv_download('rejected-rows-batch-'.$batchId.'.csv',['row_number','error_message','raw_row_json'],$rows);
