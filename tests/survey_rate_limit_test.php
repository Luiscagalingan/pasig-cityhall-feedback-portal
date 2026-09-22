<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/functions.php';

// In-memory database double: never load database.php or connect to MySQL.
$row = [];
$queries = 0;
$databaseFails = false;
function db(): object
{
    global $queries, $databaseFails;
    $queries++;
    if ($databaseFails) throw new RuntimeException('Simulated database failure');
    return new class {
        public function prepare(string $sql): object
        {
            if (!str_starts_with($sql, 'SELECT MAX(submitted_at)')) {
                throw new LogicException('Unexpected query in read-only test');
            }
            return new class {
                public function execute(array $parameters): void {}
                public function fetch(): array { return $GLOBALS['row']; }
            };
        }
    };
}
$checks = 0;
function check_limit(string $name, bool $ok): void
{
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: ' . $name);
    $checks++;
    echo 'PASS: ', $name, PHP_EOL;
}

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'rate-limit-test';
$_SESSION = ['last_public_submission' => time()];
$row = ['last_submit' => date('Y-m-d H:i:s'), 'hourly_count' => 10];
for ($i = 0; $i < 5; $i++) {
    check_limit('disabled allows repeated submission ' . ($i + 1),
        survey_submission_limit(false) === ['allowed' => true, 'wait_seconds' => 0, 'hourly_count' => 0]);
}
check_limit('disabled skips database checks', $queries === 0);
$configured = survey_submission_limit();
check_limit('default uses configuration switch',
    $configured['allowed'] === !PUBLIC_SUBMISSION_RATE_LIMIT_ENABLED);

$_SESSION = [];
$row = ['last_submit' => date('Y-m-d H:i:s', time() - 120), 'hourly_count' => 2];
check_limit('enabled permits third submission after cooldown', survey_submission_limit(true)['allowed']);
$row['hourly_count'] = 3;
$limit = survey_submission_limit(true);
check_limit('enabled rejects fourth submission at existing limit', !$limit['allowed'] && $limit['hourly_count'] === 3);
$row['hourly_count'] = 0;
$_SESSION['last_public_submission'] = time();
$limit = survey_submission_limit(true);
check_limit('enabled retains session cooldown', !$limit['allowed'] && $limit['wait_seconds'] > 0);
$_SESSION = [];
$row['last_submit'] = date('Y-m-d H:i:s');
$limit = survey_submission_limit(true);
check_limit('enabled retains database cooldown', !$limit['allowed'] && $limit['wait_seconds'] > 0);
$databaseFails = true;
$_SESSION['last_public_submission'] = time();
check_limit('enabled retains session protection when database fails', !survey_submission_limit(true)['allowed']);
$_SESSION = [];
check_limit('enabled retains existing database-failure behavior without cooldown', survey_submission_limit(true)['allowed']);
$queriesBefore = $queries;
check_limit('disabled allows submission even when database unavailable', survey_submission_limit(false)['allowed']);
check_limit('disabled never attempts unavailable database', $queries === $queriesBefore);
check_limit('production thresholds unchanged', SURVEY_SUBMISSION_HOURLY_LIMIT === 3 && SURVEY_SUBMISSION_COOLDOWN_SECONDS === 60);
echo "PASS: {$checks} public-survey rate-limit checks; no database connection or writes.\n";
