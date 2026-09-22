<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/dataset_import.php';
function expect_dataset(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
foreach (['4'=>4, '3. Agree'=>3, '2. DisAgree'=>2, '1. Strong Disagree'=>1, '3. Agree, 2. DisAgree'=>0, '3.5'=>0, '5'=>0] as $value=>$expected) {
    expect_dataset(dataset_rating((string)$value)===$expected, 'Rating validation failed');
}
expect_dataset(dataset_age('29')===29 && dataset_age('29.5')===0, 'Age validation failed');
$csv=dataset_import_stream(__DIR__.'/../sample_feedback_import.csv','csv');
expect_dataset(count(fgetcsv($csv))>5,'CSV regression');fclose($csv);
$path=tempnam(sys_get_temp_dir(),'dataset-test-');
try {
    foreach ([false,true] as $date1904) {
        $zip=new ZipArchive();$zip->open($path,ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml','<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><workbookPr date1904="'.($date1904?'1':'0').'"/><sheets><sheet name="Hidden" state="hidden" r:id="r1"/><sheet name="Data" r:id="r2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<Relationships><Relationship Id="r2" Target="worksheets/custom.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml','<sst><si><t>Timestamp</t></si><si><r><t>Hello </t></r><r><t>world</t></r></si></sst>');
        $zip->addFromString('xl/worksheets/custom.xml','<worksheet><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1" t="inlineStr"><is><t>Comment</t></is></c></row><row r="3"><c r="A3"><v>45000.5</v></c><c r="C3" t="s"><v>1</v></c></row></sheetData></worksheet>');$zip->close();
        $name=null;$stream=dataset_import_stream($path,'xlsx',$name);
        expect_dataset($name==='Data','Wrong visible sheet');
        expect_dataset(fgetcsv($stream)===['Timestamp','','Comment'],'Sparse header failed');
        fgetcsv($stream);$row=fgetcsv($stream);fclose($stream);
        $expected=(new DateTimeImmutable($date1904?'1904-01-01':'1899-12-30'))->modify('+45000 days')->format('Y-m-d');
        expect_dataset($row===[$expected,'','Hello world'],'Date, shared string or sparse row failed');
    }
    $zip=new ZipArchive();$zip->open($path);$zip->addFromString('xl/workbook.xml','<!DOCTYPE workbook [<!ENTITY x SYSTEM "file:///private">]><workbook/>');$zip->close();
    $rejected=false;try{dataset_import_stream($path,'xlsx');}catch(RuntimeException $e){$rejected=true;}
    expect_dataset($rejected,'DTD must be rejected');
} finally {unlink($path);}
echo "PASS: CSV, XLSX sheets, sparse cells, dates, strings, malformed XML and value validation.\n";
