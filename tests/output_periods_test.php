<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/output_periods.php';
date_default_timezone_set(APP_TIMEZONE);
$cases = [
    [null, '2024-02-29 23:59:59', '2024-02-01', '2024-02-29'],
    [null, '2024-03-01 00:00:00', '2024-03-01', '2024-03-31'],
    [null, '2024-12-31 23:59:59', '2024-12-01', '2024-12-31'],
    [null, '2025-01-01 00:00:00', '2025-01-01', '2025-01-31'],
    ['2001-02', '2025-01-01', '2001-02-01', '2001-02-28'],
];
foreach ($cases as [$month, $now, $start, $end]) {
    $period = client_output_month($month, new DateTimeImmutable($now));
    if ($period['start'] !== $start || $period['end'] !== $end) throw new RuntimeException('Calendar boundary mismatch');
}
foreach (['2024-00','2024-13','2024-2','2024-02-01',[], '1899-01'] as $invalid) {
    try { client_output_month($invalid); throw new RuntimeException('Invalid month accepted'); }
    catch (DomainException $expected) {}
}
foreach (['2001 OR 1=1', [], 0, 9999] as $invalid) {
    try { client_count_year($invalid); throw new RuntimeException('Invalid year accepted'); }
    catch (DomainException $expected) {}
}
echo "PASS: 5 calendar boundary cases and 10 invalid month/year cases.\n";
