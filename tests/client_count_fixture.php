<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$name = $argv[2] ?? '';
if (!preg_match('/^pasig_count_test_[a-f0-9]{16}$/', $name)) throw new RuntimeException('Isolated test database required');
$host = getenv('PASIG_DB_HOST') ?: '127.0.0.1';
$port = getenv('PASIG_DB_PORT') ?: '3306';
$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", getenv('PASIG_DB_USER') ?: 'root', getenv('PASIG_DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
if ($argv[1] === 'cleanup') { $pdo->exec("DROP DATABASE IF EXISTS `$name`"); exit; }
if ($argv[1] === 'legacy_setup') {
    $pdo->exec("USE `$name`");
    $pdo->exec("INSERT INTO client_count_requests(staff_user_id,office_id,status,answered_count,answered_at,answered_by_user_id) VALUES(4,2,'answered',42,NOW(),1),(7,2,'answered',999,NOW(),1)");
    $pdo->exec("INSERT INTO client_count_requests(staff_user_id,office_id) VALUES(4,1)");
    echo 'Legacy fixtures created';
} elseif ($argv[1] === 'output_setup') {
    $pdo->exec("USE `$name`");
    $pdo->prepare("INSERT INTO users(id,office_id,full_name,username,email,password_hash,role,status) VALUES(8,1,'Other Assistant','staff_b','staff_b@test.invalid',?,'office_staff','active')")
        ->execute([password_hash('Workflow123!', PASSWORD_DEFAULT)]);
    $insert = $pdo->prepare("INSERT INTO feedback(office_id,visit_date,sex,age,client_type,service_received,timeliness_rating,client_handling_rating,quality_rating,overall_rating,comment,assisted_by,source,imported_by_user_id,is_void,sentiment,average_rating,rating_percent,comment_score,final_score) VALUES(?,?,'Female',30,'City Government Employee','Output test',3,3,3,3,'Fixture only',?,?,?,?,'neutral',3,66.67,50,65)");
    foreach ([
        [1,'2001-01-01','Test Staff','assisted_survey',4,0],
        [1,'2001-01-01','Test Staff','assisted_survey',4,0],
        [1,'2001-01-02','Former Staff Name','assisted_survey',4,0],
        [1,'2001-02-01','Test Staff','assisted_survey',4,0],
        [1,'2001-12-31','Test Staff','assisted_survey',4,0],
        [1,'2000-12-31','Test Staff','assisted_survey',4,0],
        [1,'2002-01-01','Test Staff','assisted_survey',4,0],
        [1,'2001-01-01','Other Assistant','assisted_survey',8,0],
        [1,'2001-01-02','Other Assistant','assisted_survey',8,0],
        [1,'2001-01-01',null,'public_survey',null,0],
        [1,'2001-01-01','Test Staff','csv_import',4,0],
        [1,'2001-01-01','Test Staff','assisted_survey',4,1],
        [2,'2001-01-01','Test Staff','assisted_survey',4,0],
        [1,'2001-01-01','   ','assisted_survey',4,0],
        [1,'2001-01-01','Test Staff','assisted_survey',null,0],
        [1,'2001-01-01','Test Staff','assisted_survey',8,0],
    ] as $row) $insert->execute($row);
    echo 'Output fixtures created';
} elseif ($argv[1] === 'request') {
    $pdo->exec("USE `$name`");
    require_once __DIR__ . '/../includes/client_counts.php';
    try { create_client_count_request($pdo, 4, 2003); echo 'created'; }
    catch (DomainException $error) { echo 'rejected'; }
} elseif ($argv[1] === 'claim') {
    $pdo->exec("USE `$name`");
    require_once __DIR__ . '/../config/app.php';
    require_once __DIR__ . '/../includes/functions.php';
    $pdo->beginTransaction();
    $claimed = claim_feedback_fingerprint($pdo, 1, str_repeat('a', 64));
    usleep(200000);
    $pdo->commit();
    echo $claimed ? 'claimed' : 'duplicate';
} elseif ($argv[1] === 'expire_claim') {
    $pdo->exec("USE `$name`");
    $pdo->exec("UPDATE feedback_duplicate_claims SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND)");
    echo 'expired';
} elseif ($argv[1] === 'import_inspect') {
    $pdo->exec("USE `$name`");
    echo json_encode([
        'feedback'=>$pdo->query("SELECT id,service_received,source,assisted_by,assisted_by_user_id,import_batch_id,rating_percent,comment_score,final_score FROM feedback ORDER BY id")->fetchAll(),
        'batches'=>$pdo->query("SELECT id,total_rows,imported_rows,rejected_rows,duplicate_rows,rolled_back_at FROM import_batches ORDER BY id")->fetchAll(),
        'rejected'=>(int)$pdo->query('SELECT COUNT(*) FROM import_rejected_rows')->fetchColumn(),
    ]);
} elseif ($argv[1] === 'dashboard_reconcile') {
    putenv('PASIG_DB_NAME=' . $name);
    require_once __DIR__ . '/../config/app.php';
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/functions.php';
    require_once __DIR__ . '/../includes/dashboard.php';
    $direct=(int)$pdo->query("SELECT COUNT(*) FROM `$name`.feedback WHERE office_id=1 AND is_void=0 AND visit_date BETWEEN '2001-01-01' AND '2001-01-31'")->fetchColumn();
    $metrics=dashboard_metrics(1,'2001-01-01','2001-01-31');
    $ages=array_sum(array_map(fn($r)=>(int)$r['count'],age_group_counts(1,'2001-01-01','2001-01-31')));
    $services=array_sum(array_map(fn($r)=>(int)$r['responses'],service_summary(1,100,'2001-01-01','2001-01-31')));
    echo json_encode(['direct'=>$direct,'metrics'=>$metrics['total'],'ages'=>$ages,'services'=>$services]);
} elseif ($argv[1] === 'rate_reserve') {
    $pdo->exec("USE `$name`");
    putenv('PASIG_DB_NAME=' . $name);
    require_once __DIR__ . '/../config/app.php';
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/functions.php';
    $pdo->beginTransaction();
    $result=reserve_public_submission_slot($pdo,(string)$argv[3],new DateTimeImmutable((string)$argv[4],new DateTimeZone(APP_TIMEZONE)));
    usleep(200000);
    $pdo->commit();
    echo json_encode($result);
} elseif ($argv[1] === 'review_setup') {
    $pdo->exec("USE `$name`");
    $pdo->exec("INSERT INTO feedback(office_id,visit_date,sex,age,client_type,service_received,timeliness_rating,client_handling_rating,quality_rating,overall_rating,comment,sentiment,original_sentiment,sentiment_confidence,sentiment_source,review_status,average_rating,rating_percent,comment_score,final_score,source) VALUES(1,CURRENT_DATE,'Female',30,'City Government Employee','Review fixture',3,3,3,3,'Mixed review fixture','neutral','neutral',0.40,'svm','needs_review',3,66.67,50,65,'public_survey')");
    $feedbackId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO actions(feedback_id,office_id,title,status,resolution_notes,completed_at) VALUES(?,1,'Review fixture action','completed','Originally complete',NOW())")->execute([$feedbackId]);
    echo (string)$feedbackId;
} elseif ($argv[1] === 'review_inspect') {
    $pdo->exec("USE `$name`");
    $id=(int)$argv[3];
    $s=$pdo->prepare('SELECT id,sentiment,sentiment_source,review_status,reviewed_by_user_id,reviewed_at,reviewer_notes,comment_score,final_score FROM feedback WHERE id=?');$s->execute([$id]);
    $a=$pdo->prepare('SELECT id,status,resolution_notes,completed_at FROM actions WHERE feedback_id=?');$a->execute([$id]);
    echo json_encode(['feedback'=>$s->fetch(),'action'=>$a->fetch()]);
} elseif ($argv[1] === 'drop_guard_tables') {
    $pdo->exec("USE `$name`");
    $pdo->exec('DROP TABLE public_submission_rate_limits');
    $pdo->exec('DROP TABLE feedback_duplicate_claims');
    echo 'dropped';
} elseif ($argv[1] === 'answer') {
    $pdo->exec("USE `$name`");
    require_once __DIR__ . '/../includes/client_counts.php';
    try {
        answer_client_count_request($pdo, (int)$argv[3], (int)$argv[4]);
        echo 'answered';
    } catch (DomainException $error) {
        echo 'rejected';
    }
} elseif ($argv[1] === 'setup') {
    $pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$name`");
    // Only CREATE TABLE statements; never execute the schema's USE/DROP/seed blocks.
    preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)? .*?\) ENGINE=InnoDB;/s', file_get_contents(__DIR__.'/../database/schema.sql'), $matches);
    foreach ($matches[0] as $sql) $pdo->exec($sql);
    $pdo->exec("INSERT INTO offices(id,name,code,status) VALUES(1,'Test Office','TEST','active'),(2,'Other Office','OTHER','active')");
    $roleType = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch()['Type'];
    if ($roleType !== "enum('admin','office_head','office_staff')") throw new RuntimeException('Fresh schema contains an unsupported role');
    // Emulate an upgraded legacy database solely to test denial of its retired role.
    $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','office_head','office_staff','supervisor') NOT NULL");
    $password = password_hash('Workflow123!', PASSWORD_DEFAULT);
    $insert = $pdo->prepare('INSERT INTO users(id,office_id,full_name,username,email,password_hash,role,status,must_change_password) VALUES(?,?,?,?,?,?,?,?,0)');
    foreach ([[1,null,'Sir Uno','uno','admin','active'],[2,null,'Another Administrator','other_admin','admin','active'],[3,1,'Office Head','head','office_head','active'],[4,1,'Test Staff','staff','office_staff','active'],[5,null,'Archived Administrator','inactive','admin','archived'],[6,1,'Historical Account','legacy','supervisor','active'],[7,2,'Other Staff','other_staff','office_staff','active']] as $u) {
        $insert->execute([$u[0],$u[1],$u[2],$u[3],$u[3].'@test.invalid',$password,$u[4],$u[5]]);
    }
    $feedback = $pdo->prepare("INSERT INTO feedback(office_id,visit_date,sex,age,client_type,service_received,timeliness_rating,client_handling_rating,quality_rating,overall_rating,comment,assisted_by,sentiment,average_rating,rating_percent,comment_score,final_score,is_void) VALUES(?,CURRENT_DATE,'Female',30,'City Government Employee','Test',3,3,3,3,'Fixture only',?,'neutral',3,66.67,50,65,?)");
    foreach ([[1,' Test Staff ',0],[1,'STAFF',0],[1,'staff',1],[2,'staff',0],[1,'Someone Else',0]] as $f) $feedback->execute($f);
    $pdo->exec("UPDATE feedback SET source='assisted_survey',imported_by_user_id=4 WHERE id IN (1,2)");
    $pdo->exec("INSERT INTO import_batches(id,office_id,uploaded_by_user_id,original_filename,rejected_rows) VALUES(1,1,2,'fixture.csv',1),(2,2,2,'fixture-other.csv',1)");
    $pdo->exec("INSERT INTO import_rejected_rows(import_batch_id,row_number,raw_row,error_message) VALUES(1,2,'[]','Fixture rejection'),(2,2,'[]','Other office rejection')");
    echo "Fixture created\n";
} else {
    $pdo->exec("USE `$name`");
    echo json_encode([
        'requests'=>$pdo->query('SELECT * FROM client_count_requests ORDER BY id')->fetchAll(),
        'notifications'=>$pdo->query("SELECT user_id,type,message FROM notifications WHERE type IN ('client_count','client_count_request') ORDER BY id")->fetchAll(),
        'audit'=>$pdo->query("SELECT action FROM audit_logs WHERE action IN ('client_count_request','admin_client_count_sent') ORDER BY id")->fetchAll(),
        'feedback_count'=>(int)$pdo->query('SELECT COUNT(*) FROM feedback')->fetchColumn(),
        'feedback_hash'=>hash('sha256',json_encode($pdo->query('SELECT * FROM feedback ORDER BY id')->fetchAll())),
    ]);
}
