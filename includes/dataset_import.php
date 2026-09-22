<?php
declare(strict_types=1);

/** Read CSV or the first visible XLSX worksheet into the existing CSV pipeline. */
function dataset_import_stream(string $path, string $extension, ?string &$sheetName = null)
{
    $sheetName = null;
    if ($extension === 'csv') {
        $stream = fopen($path, 'rb');
        if (!$stream) throw new RuntimeException('Unable to read the uploaded CSV.');
        return $stream;
    }
    if ($extension !== 'xlsx') throw new RuntimeException('Only .csv and .xlsx files are accepted.');
    if (!class_exists('ZipArchive')) throw new RuntimeException('Enable the PHP zip extension to upload Excel workbooks.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Unable to open this XLSX workbook. Save it as an unencrypted Excel Workbook (.xlsx).');
    $stream = null;
    try {
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $total += $stat['size'];
            if ($total > 32 * 1024 * 1024) throw new RuntimeException('The expanded Excel workbook exceeds 32 MB. Export the data sheet as CSV.');
        }
        $readXml = static function (string $entry) use ($zip): DOMXPath {
            $xml = $zip->getFromName($entry);
            if ($xml === false || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
                throw new RuntimeException('Invalid or unsupported XLSX workbook structure.');
            }
            $doc = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            try { $ok = $doc->loadXML($xml, LIBXML_NONET); }
            finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
            if (!$ok) throw new RuntimeException('The XLSX workbook contains invalid XML.');
            return new DOMXPath($doc);
        };
        $workbook = $readXml('xl/workbook.xml');
        $sheet = $workbook->query('/*[local-name()="workbook"]/*[local-name()="sheets"]/*[local-name()="sheet"][not(@state) or @state="visible"]')->item(0);
        if (!$sheet) throw new RuntimeException('The workbook has no visible worksheet.');
        $sheetName = $sheet->getAttribute('name');
        $relationshipId = '';
        foreach ($sheet->attributes as $attribute) if ($attribute->localName === 'id') $relationshipId = $attribute->value;
        $relationships = $readXml('xl/_rels/workbook.xml.rels');
        $target = null;
        foreach ($relationships->query('/*/*[local-name()="Relationship"]') as $relationship) {
            if ($relationship->getAttribute('Id') === $relationshipId && $relationship->getAttribute('TargetMode') !== 'External') $target = $relationship->getAttribute('Target');
        }
        if (!$target) throw new RuntimeException('The worksheet could not be found in the workbook.');
        $parts = [];
        foreach (explode('/', str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target) as $part) {
            if ($part === '..') array_pop($parts);
            elseif ($part !== '' && $part !== '.') $parts[] = $part;
        }
        $shared = [];
        if ($zip->locateName('xl/sharedStrings.xml') !== false) {
            $strings = $readXml('xl/sharedStrings.xml');
            foreach ($strings->query('/*/*[local-name()="si"]') as $item) {
                $text = '';
                foreach ($strings->query('.//*[local-name()="t"]', $item) as $node) $text .= $node->textContent;
                $shared[] = $text;
            }
        }
        $xml = $readXml(implode('/', $parts));
        $date1904 = $workbook->evaluate('string(/*/*[local-name()="workbookPr"]/@date1904)');
        $epoch = in_array($date1904, ['1', 'true'], true) ? '1904-01-01' : '1899-12-30';
        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        if (!$stream) throw new RuntimeException('Unable to allocate workbook preview storage.');
        $dateColumns = [];
        $previousRow = 0;
        foreach ($xml->query('/*/*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
            $rowNumber = (int)$row->getAttribute('r');
            if ($rowNumber < 1) $rowNumber = $previousRow + 1;
            $values = [];
            foreach ($xml->query('./*[local-name()="c"]', $row) as $cell) {
                preg_match('/^([A-Z]+)[0-9]+$/', $cell->getAttribute('r'), $match);
                if (!$match) throw new RuntimeException('Invalid Excel cell reference.');
                $column = 0;
                foreach (str_split($match[1]) as $letter) $column = $column * 26 + ord($letter) - 64;
                if ($column > 256) throw new RuntimeException('The data worksheet must contain at most 256 columns.');
                $value = $xml->evaluate('string(./*[local-name()="v"])', $cell);
                if ($cell->getAttribute('t') === 's') $value = $shared[(int)$value] ?? '';
                elseif ($cell->getAttribute('t') === 'inlineStr') {
                    $value = '';
                    foreach ($xml->query('./*[local-name()="is"]//*[local-name()="t"]', $cell) as $node) $value .= $node->textContent;
                }
                if ($rowNumber === 1 && in_array(strtolower(trim($value)), ['timestamp', 'date', 'visit_date'], true)) $dateColumns[$column - 1] = true;
                if ($rowNumber > 1 && isset($dateColumns[$column - 1]) && is_numeric($value) && (float)$value >= 0 && (float)$value <= 2958465) {
                    $value = (new DateTimeImmutable($epoch))->modify('+' . (int)floor((float)$value) . ' days')->format('Y-m-d');
                }
                $values[$column - 1] = $value;
            }
            if (!array_filter($values, static fn($value) => trim($value) !== '')) continue;
            if ($rowNumber > 10001) throw new RuntimeException('The data worksheet exceeds 10,000 data rows. Split it into smaller files.');
            while (++$previousRow < $rowNumber) fputcsv($stream, []);
            $dense = array_fill(0, max(array_keys($values)) + 1, '');
            foreach ($values as $column => $value) $dense[$column] = $value;
            if (fputcsv($stream, $dense) === false) throw new RuntimeException('Unable to prepare workbook preview.');
        }
        rewind($stream);
        return $stream;
    } catch (Throwable $error) {
        if (is_resource($stream)) fclose($stream);
        throw $error;
    } finally { $zip->close(); }
}

/** Preserve a single selected rating; reject decimals and multiple selections. */
function dataset_rating(string $value): int
{
    return preg_match('/^([1-4])(?:\.\s*[A-Za-z][^,;]*)?$/u', trim($value), $match) ? (int)$match[1] : 0;
}

function dataset_age(string $value): int
{
    return preg_match('/^[0-9]{1,3}$/', trim($value)) ? (int)$value : 0;
}
