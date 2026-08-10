<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$results = [];
$check = static function(string $name, bool $ok, string $detail = '') use (&$results): void {
    $results[] = compact('name','ok','detail');
};
$source = static fn(string $path): string => (string)file_get_contents($root.'/'.$path);

foreach (glob($root.'/admin/*.php') ?: [] as $file) {
    $name = basename($file);
    $text = (string)file_get_contents($file);
    $check('Admin guard: '.$name, str_contains($text, "require_login(['admin'])"));
}
$officeRoles = [
    'data.php'=>"require_login(['office_head'])",
    'rejected_rows.php'=>"require_login(['office_head'])",
    'staff.php'=>"require_login(['office_head'])",
    'dashboard.php'=>"require_login(['office_head','office_staff'])",
    'feedback.php'=>"require_login(['office_head','office_staff'])",
    'reports.php'=>"require_login(['office_head','office_staff'])",
    'actions.php'=>"require_login(['office_head','office_staff'])",
];
foreach ($officeRoles as $file => $guard) $check('Office guard: '.$file, str_contains($source('office/'.$file), $guard));
$scopeChecks = [
    'office/feedback.php' => ['f.office_id=?'],
    'office/actions.php' => ['a.office_id=?'],
    'office/reports.php' => ['f.office_id=?'],
    'office/rejected_rows.php' => ['id=? AND office_id=?'],
    'office/staff.php' => ['office_id=?', "role='office_staff'"],
    'office/data.php' => ['office_id=?'],
];
foreach ($scopeChecks as $file => $needles) {
    $text = $source($file);
    $ok = true;
    foreach ($needles as $needle) $ok = $ok && str_contains($text, $needle);
    $check('Office ID scope: '.$file, $ok);
}

$mutatingFiles = ['account.php','notifications.php','review.php','admin/actions.php','admin/heads.php','admin/offices.php','admin/staff.php','admin/system.php','office/actions.php','office/data.php','office/staff.php','survey.php'];
foreach ($mutatingFiles as $file) $check('CSRF: '.$file, str_contains($source($file), 'verify_csrf()'));

$officeActions = $source('office/actions.php');
$adminActions = $source('admin/actions.php');
$check('Action scope enforced', str_contains($officeActions, 'WHERE id=? AND office_id=?'));
$check('Staff completion requires approval', str_contains($officeActions, "status='pending_approval'") && str_contains($officeActions, 'completion_requested_by_user_id'));
$check('Head completion approval', str_contains($officeActions, "requested==='completed'") && str_contains($officeActions, 'approved_by_user_id'));
$check('Head can return completion request', str_contains($officeActions, "requested==='reject_completion'") && str_contains($officeActions, "status='in_progress'"));
$check('Admin action oversight guarded', str_contains($adminActions, "require_login(['admin'])") && str_contains($adminActions, 'verify_csrf()'));

$allPhp = '';
foreach (glob($root.'/{admin,office}/*.php', GLOB_BRACE) ?: [] as $file) $allPhp .= file_get_contents($file);
$check('No permanent user deletion', !preg_match('/DELETE\s+FROM\s+users/i', $allPhp));
$check('Adult-only survey', str_contains($source('survey.php'), '$age < 18') && str_contains($source('survey.php'), 'min="18"'));
$check('Exact duplicate protection', str_contains($source('survey.php'), 'record_fingerprint=?'));
$check('Survey rate limit', str_contains($source('survey.php'), 'survey_submission_limit()'));
$check('Encrypted backup only', str_contains($source('admin/backup.php'), "AES-256-GCM") || str_contains(strtolower($source('admin/backup.php')), 'aes-256-gcm'));
$check('Backup requires POST and CSRF', str_contains($source('admin/backup.php'), "REQUEST_METHOD'] !== 'POST'") && str_contains($source('admin/backup.php'), 'verify_csrf()'));
$cryptoOk = false;
if (function_exists('openssl_encrypt')) {
    $plain = 'backup round-trip test';$salt = random_bytes(16);$iv = random_bytes(12);$tag = '';
    $key = hash_pbkdf2('sha256', 'test-passphrase-not-for-use', $salt, 1000, 32, true);
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    $cryptoOk = $cipher !== false && openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag) === $plain;
}
$check('AES-256-GCM round trip', $cryptoOk);
$check('Model limitations documented', is_file($root.'/ml/MODEL_CARD.md') && str_contains($source('ml/MODEL_CARD.md'), 'not a statistical probability'));
foreach (['config','database','includes','ml','storage','uploads','tools'] as $dir) {
    $deny = $root.'/'.$dir.'/.htaccess';
    $check('Web deny: '.$dir, is_file($deny) && str_contains((string)file_get_contents($deny), 'Require all denied'));
}
$config = $source('config/app.php');
foreach (['FEEDBACK_RETENTION_MONTHS','AUDIT_RETENTION_MONTHS','LOGIN_ATTEMPT_RETENTION_DAYS','NOTIFICATION_RETENTION_DAYS','BACKUP_RETENTION_DAYS'] as $constant) {
    $check('Retention config: '.$constant, str_contains($config, 'const '.$constant));
}

$failed = 0;
echo "PASIG SECURITY STATIC TEST\n".str_repeat('=',42)."\n";
foreach ($results as $result) {
    echo ($result['ok'] ? '[PASS] ' : '[FAIL] ').$result['name'].($result['detail'] !== '' ? ' - '.$result['detail'] : '')."\n";
    if (!$result['ok']) $failed++;
}
echo str_repeat('-',42)."\n".($failed ? "{$failed} security test(s) failed.\n" : "All security static tests passed.\n");
exit($failed === 0 ? 0 : 1);
