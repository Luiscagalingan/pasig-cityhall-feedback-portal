<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
$user=require_login(['admin']);
$pdo=db();
$tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
audit((int)$user['id'],'database_backup_export','Downloaded full SQL backup containing '.count($tables).' tables');
header('Content-Type: application/sql; charset=UTF-8');
header('Content-Disposition: attachment; filename="pasig-feedback-backup-'.date('Y-m-d-His').'.sql"');
echo "-- Pasig Feedback Portal database backup\n-- Generated: ".date('c')."\nSET FOREIGN_KEY_CHECKS=0;\nUSE pasig_feedback_portal;\n\n";
foreach($tables as $table){
 $safe=str_replace('`','``',(string)$table);$create=$pdo->query("SHOW CREATE TABLE `{$safe}`")->fetch();$createSql=$create['Create Table']??array_values($create)[1]??'';echo "DROP TABLE IF EXISTS `{$safe}`;\n{$createSql};\n\n";
 $stmt=$pdo->query("SELECT * FROM `{$safe}`");while($row=$stmt->fetch(PDO::FETCH_ASSOC)){$cols=array_map(fn($c)=>'`'.str_replace('`','``',(string)$c).'`',array_keys($row));$vals=array_map(fn($v)=>$v===null?'NULL':$pdo->quote((string)$v),array_values($row));echo "INSERT INTO `{$safe}` (".implode(',',$cols).") VALUES (".implode(',',$vals).");\n";}echo "\n";
}
$triggers=$pdo->query('SHOW TRIGGERS')->fetchAll();foreach($triggers as $trigger){$name=(string)$trigger['Trigger'];$show=$pdo->query('SHOW CREATE TRIGGER `'.str_replace('`','``',$name).'`')->fetch();$sql=$show['SQL Original Statement']??$show['Create Trigger']??'';if($sql!=='')echo "DROP TRIGGER IF EXISTS `".str_replace('`','``',$name)."`;\nDELIMITER $$\n{$sql}$$\nDELIMITER ;\n\n";}
echo "SET FOREIGN_KEY_CHECKS=1;\n";
exit;
