$root=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$psi=[Diagnostics.ProcessStartInfo]::new('git','show HEAD:office/data.php')
$psi.WorkingDirectory=$root;$psi.UseShellExecute=$false;$psi.RedirectStandardOutput=$true;$psi.StandardOutputEncoding=[Text.UTF8Encoding]::new($false)
$p=[Diagnostics.Process]::Start($psi);$text=$p.StandardOutput.ReadToEnd();$p.WaitForExit()
if($p.ExitCode -ne 0 -or -not $text.Contains('Dataset & Historical Data')){throw 'Unable to read clean office/data.php from repository.'}
[IO.File]::WriteAllText((Join-Path $root 'office\data.php'),$text,[Text.UTF8Encoding]::new($false))
