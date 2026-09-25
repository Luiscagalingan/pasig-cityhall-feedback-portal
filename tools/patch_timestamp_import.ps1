$path = Join-Path $PSScriptRoot '..\office\data.php'
$text = [IO.File]::ReadAllText((Resolve-Path $path), [Text.UTF8Encoding]::new($false))
$dateFunction = "function csv_date_value(array `$row,array `$map,array `$aliases): string { `$value=csv_value(`$row,`$map,`$aliases);`$time=strtotime(`$value);return `$time===false?`$value:date('Y-m-d',`$time); }"
$timestampFunction = $dateFunction + "`nfunction csv_timestamp_value(array `$row,array `$map,array `$aliases): string { `$value=csv_value(`$row,`$map,`$aliases);`$time=strtotime(`$value);return `$time===false?`$value:date('Y-m-d H:i:s',`$time); }"
if (-not $text.Contains($dateFunction)) { throw 'CSV date function was not found.' }
$text = $text.Replace($dateFunction, $timestampFunction)
$old = "`$record=['visit_date'=>csv_date_value(`$row,`$map,['visit_date','date','timestamp']),'sex'=>"
$new = "`$record=['visit_date'=>csv_date_value(`$row,`$map,['visit_date','date','timestamp']),'source_timestamp'=>csv_timestamp_value(`$row,`$map,['visit_date','date','timestamp']),'sex'=>"
if (-not $text.Contains($old)) { throw 'CSV record constructor was not found.' }
$text = $text.Replace($old, $new)

$text = $text.Replace("VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'csv_import'", "VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'csv_import'")

$old = 'comment,assisted_by,client_number,surname,given_name,middle_initial,sentiment'
$new = 'comment,assisted_by,assisted_by_user_id,source_timestamp,client_number,surname,given_name,middle_initial,sentiment'
if (-not $text.Contains($old)) { throw 'CSV INSERT columns were not found.' }
$text = $text.Replace($old, $new)

$old = "`$actionCount=0;`$insert=db()->prepare("
$new = @'
$staffMap=[];$staffStmt=db()->prepare("SELECT id,username,full_name FROM users WHERE office_id=? AND role='office_staff' AND status='active'");$staffStmt->execute([$officeId]);foreach($staffStmt->fetchAll() as $staffRow){$staffMap[mb_strtolower(trim((string)$staffRow['username']))]=(int)$staffRow['id'];$staffMap[mb_strtolower(trim((string)$staffRow['full_name']))]=(int)$staffRow['id'];}$actionCount=0;$insert=db()->prepare(
'@
if (-not $text.Contains($old)) { throw 'CSV confirm insert preparation was not found.' }
$text = $text.Replace($old, $new)

$old = "`$record['comment'],`$record['assisted_by'],`$record['client_number']"
$new = "`$record['comment'],`$record['assisted_by'],(`$staffMap[mb_strtolower(trim((string)`$record['assisted_by']))]??null),`$record['source_timestamp'],`$record['client_number']"
if (-not $text.Contains($old)) { throw 'CSV INSERT values were not found.' }
$text = $text.Replace($old, $new)

[IO.File]::WriteAllText((Resolve-Path $path), $text, [Text.UTF8Encoding]::new($false))
