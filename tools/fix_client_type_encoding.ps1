$path=Join-Path $PSScriptRoot '..\office\data.php'
$text=[IO.File]::ReadAllText((Resolve-Path $path),[Text.UTF8Encoding]::new($false))
$enye=[char]0x00F1
$text=[regex]::Replace($text,"Non-Pasigue[^']*o","Non-Pasigue${enye}o")
$text=[regex]::Replace($text,"Pasigue[^']*o","Pasigue${enye}o")
[IO.File]::WriteAllText((Resolve-Path $path),$text,[Text.UTF8Encoding]::new($false))
