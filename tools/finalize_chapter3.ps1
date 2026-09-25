param(
    [string]$InputPath = ".\CHAPTER_3_RESULTS_AND_DISCUSSIONS_FINAL.docx",
    [string]$OutputPath = ".\CHAPTER_3_RESULTS_AND_DISCUSSIONS_CORRECTED.docx"
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem
Add-Type -AssemblyName System.IO.Compression

$source = (Resolve-Path -LiteralPath $InputPath).Path
$destination = [System.IO.Path]::GetFullPath((Join-Path (Get-Location) $OutputPath))
Copy-Item -LiteralPath $source -Destination $destination -Force

$archive = [System.IO.Compression.ZipFile]::Open($destination, [System.IO.Compression.ZipArchiveMode]::Update)
try {
    $entry = $archive.GetEntry('word/document.xml')
    if ($null -eq $entry) { throw 'word/document.xml was not found in the DOCX package.' }

    $reader = [System.IO.StreamReader]::new($entry.Open())
    try { $xml = $reader.ReadToEnd() } finally { $reader.Dispose() }

    $replacements = [ordered]@{
        'Office Heads have monitoring access for their assigned office but cannot process annual count requests or perform restricted administrative writes. Staff pages restrict records and monthly client output to the authenticated account and assigned office, while providing access to assisted surveys, annual count requests, announcements, and account settings.' = 'Office Heads can monitor feedback, manage Office Staff accounts, review sentiment, administer action workflows, and generate reports for their assigned office, but they cannot process annual client-count requests or access Administrator-only controls. Office Staff can view feedback records within their assigned office, while their monthly Client Output is further restricted to records owned by the authenticated staff account. Staff also have access to assisted surveys, annual client-count requests, announcements, and account settings.'
        'Historical datasets can be uploaded through staged validation in which invalid rows are listed before confirmation and rejected rows remain downloadable by authorized users.' = 'The Administrator can upload CSV or XLSX historical datasets through staged validation, in which invalid rows are listed before confirmation and rejected rows remain downloadable to authorized users.'
        'Needs Action, In Progress, and Completed statuses remained correct when records were opened or notes were saved' = 'Needs Action, In Progress, Pending Approval, and Completed statuses remained correct when records were opened, completion was requested, approval was processed, or notes were saved'
        'Python 3.13.15' = 'Python 3.14.7'
    }

    foreach ($item in $replacements.GetEnumerator()) {
        if (-not $xml.Contains($item.Key)) { throw "Expected text was not found: $($item.Key)" }
        $xml = $xml.Replace($item.Key, $item.Value)
    }

    $entry.Delete()
    $newEntry = $archive.CreateEntry('word/document.xml', [System.IO.Compression.CompressionLevel]::Optimal)
    $utf8 = [System.Text.UTF8Encoding]::new($false)
    $writer = [System.IO.StreamWriter]::new($newEntry.Open(), $utf8)
    try { $writer.Write($xml) } finally { $writer.Dispose() }
}
finally {
    $archive.Dispose()
}

Get-Item -LiteralPath $destination | Select-Object FullName, Length, LastWriteTime
