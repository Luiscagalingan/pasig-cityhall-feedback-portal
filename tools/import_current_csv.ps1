$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.Net.Http
$base='http://localhost/pasig-cityhall-feedback-portal'
$cookie=Join-Path $env:TEMP 'pasig_import_cookie.txt'
$login=Invoke-WebRequest "$base/login.php" -SessionVariable session -UseBasicParsing
$csrf=[regex]::Match($login.Content,'name="csrf_token" value="([^"]+)"').Groups[1].Value
$response=Invoke-WebRequest "$base/login.php" -WebSession $session -Method Post -Body @{csrf_token=$csrf;identity='admin';password='Admin123!'} -UseBasicParsing
if($response.Content -notmatch 'Successfully logged in'){throw 'Administrator login failed.'}
$page=Invoke-WebRequest "$base/office/data.php?office_id=1" -WebSession $session -UseBasicParsing
$csrf=[regex]::Match($page.Content,'name="csrf_token" value="([^"]+)"').Groups[1].Value
$file=(Resolve-Path '.\CSS-COPY-WITH-DUMMY-NAMES-FIXED.csv').Path
$handler=[Net.Http.HttpClientHandler]::new();$handler.CookieContainer=[Net.CookieContainer]::new()
foreach($c in $session.Cookies.GetCookies([Uri]$base)){$handler.CookieContainer.Add([Uri]$base,[Net.Cookie]::new($c.Name,$c.Value,$c.Path,$c.Domain))}
$client=[Net.Http.HttpClient]::new($handler)
$client.Timeout=[TimeSpan]::FromMinutes(10)
$form=[Net.Http.MultipartFormDataContent]::new()
$form.Add([Net.Http.StringContent]::new($csrf),'csrf_token');$form.Add([Net.Http.StringContent]::new('preview'),'action');$form.Add([Net.Http.StringContent]::new('1'),'office_id')
$stream=[IO.File]::OpenRead($file);$content=[Net.Http.StreamContent]::new($stream);$content.Headers.ContentType=[Net.Http.Headers.MediaTypeHeaderValue]::new('text/csv');$form.Add($content,'csv_file',[IO.Path]::GetFileName($file))
$previewResponse=$client.PostAsync("$base/office/data.php",$form).Result;$stream.Dispose()
$location=$previewResponse.RequestMessage.RequestUri.AbsoluteUri
$previewPage=$client.GetStringAsync($location).Result
$token=[regex]::Match($location,'preview=([a-f0-9]{32})').Groups[1].Value
if(-not $token){throw "Preview failed: $location"}
$csrf=[regex]::Match($previewPage,'name="csrf_token" value="([^"]+)"').Groups[1].Value
$confirm=Invoke-WebRequest "$base/office/data.php" -WebSession $session -Method Post -Body @{csrf_token=$csrf;action='confirm';preview_token=$token;office_id='1'} -UseBasicParsing -TimeoutSec 600
$confirmText=$confirm.Content
"Preview=$location"
"ConfirmStatus=$([int]$confirm.StatusCode)"
if($confirmText -match 'Upload could not be completed</h2><p>(.*?)</p>'){"Error=$([Net.WebUtility]::HtmlDecode($matches[1]))"}
