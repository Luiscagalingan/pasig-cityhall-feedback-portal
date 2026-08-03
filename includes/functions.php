<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_url(string $path = ''): string
{
    $base = rtrim(APP_BASE_URL, '/');
    return $base . ($path !== '' ? '/' . ltrim($path, '/') : '/');
}

function redirect(string $path): never
{
    header('Location: ' . app_url($path));
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Invalid or expired form token. Please go back and try again.');
    }
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function consume_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function audit(?int $userId, string $action, string $details = ''): void
{
    try {
        $stmt = db()->prepare('INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $action, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable) {
        // Audit failure must not interrupt the requested operation.
    }
}

function request_int(string $key, int $default = 0): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? $default : (int)$value;
}

function post_int(string $key, int $default = 0): int
{
    $value = filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? $default : (int)$value;
}

function pagination(int $total, int $perPage = 25): array
{
    $perPage = max(10, min(100, $perPage));
    $pages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($pages, request_int('page', 1)));
    return ['page' => $page, 'pages' => $pages, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage, 'total' => $total];
}

function pagination_links(array $p): string
{
    if (($p['pages'] ?? 1) <= 1) return '';
    $query = $_GET;
    unset($query['page']);
    $html = '<nav class="pagination" aria-label="Page navigation">';
    if ((int)$p['page'] > 1) {
        $query['page'] = (int)$p['page'] - 1;
        $html .= '<a class="page-direction" href="?' . e(http_build_query($query)) . '">&larr; Previous</a>';
    }
    for ($i = 1; $i <= (int)$p['pages']; $i++) {
        if ($i > 2 && $i < (int)$p['pages'] - 1 && abs($i - (int)$p['page']) > 2) {
            if ($i === 3 || $i === (int)$p['pages'] - 2) $html .= '<span>…</span>';
            continue;
        }
        $query['page'] = $i;
        $html .= '<a class="' . ($i === (int)$p['page'] ? 'active' : '') . '" href="?' . e(http_build_query($query)) . '">' . $i . '</a>';
    }
    if ((int)$p['page'] < (int)$p['pages']) {
        $query['page'] = (int)$p['page'] + 1;
        $html .= '<a class="page-direction" href="?' . e(http_build_query($query)) . '">Next &rarr;</a>';
    }
    return $html . '</nav>';
}

function rating_average(array $ratings): float
{
    $values = array_map('intval', $ratings);
    return round(array_sum($values) / max(1, count($values)), 2);
}

function sentiment_numeric_score(string $sentiment): float
{
    return match ($sentiment) {
        'positive' => 100.0,
        'negative' => 0.0,
        default => 50.0,
    };
}

function compute_feedback_scores(array $ratings, string $sentiment): array
{
    $average = rating_average($ratings);
    // Chapter 2's -1..+1 index expressed on an equivalent 0..100 scale.
    $normalizedRating = round((($average - 1) / 3) * 100, 2);
    $commentScore = sentiment_numeric_score($sentiment);
    return [
        'average_rating' => $average,
        // Kept under the legacy database column name for migration compatibility.
        'rating_percent' => $normalizedRating,
        'comment_score' => $commentScore,
        'final_score' => round(($normalizedRating * RATING_WEIGHT) + ($commentScore * COMMENT_WEIGHT), 2),
    ];
}

function final_interpretation(float $score): string
{
    if ($score >= 66.5) return 'Positive';
    if ($score >= 33.5) return 'Neutral';
    return 'Negative';
}

function active_offices(): array
{
    return db()->query("SELECT * FROM offices WHERE status='active' ORDER BY name")->fetchAll();
}

function fetch_office_by_code(string $code): ?array
{
    $stmt = db()->prepare("SELECT * FROM offices WHERE code=? AND status='active' LIMIT 1");
    $stmt->execute([strtoupper(trim($code))]);
    return $stmt->fetch() ?: null;
}

function valid_rating(mixed $value): bool
{
    return in_array((int)$value, [1, 2, 3, 4], true);
}

function normalize_header(string $header): string
{
    $header = strtolower(trim($header));
    $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?? $header;
    return trim($header, '_');
}

function feedback_fingerprint(int $officeId, array $record): string
{
    $payload = [
        $officeId,
        strtolower(trim((string)($record['visit_date'] ?? ''))),
        strtolower(trim((string)($record['sex'] ?? ''))),
        (int)($record['age'] ?? 0),
        strtolower(trim((string)($record['client_type'] ?? ''))),
        preg_replace('/\s+/u', ' ', mb_strtolower(trim((string)($record['service'] ?? $record['service_received'] ?? '')))),
        array_map('intval', $record['ratings'] ?? []),
        preg_replace('/\s+/u', ' ', mb_strtolower(trim((string)($record['comment'] ?? '')))),
    ];
    return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));
}

function backfill_office_feedback_fingerprints(int $officeId): int
{
    $stmt = db()->prepare(
        "SELECT id, visit_date, sex, age, client_type, service_received,
                timeliness_rating, client_handling_rating, quality_rating, overall_rating, comment
         FROM feedback
         WHERE office_id=? AND is_void=0 AND (record_fingerprint IS NULL OR record_fingerprint='')"
    );
    $stmt->execute([$officeId]);
    $update = db()->prepare("UPDATE feedback SET record_fingerprint=? WHERE id=? AND (record_fingerprint IS NULL OR record_fingerprint='')");
    $count = 0;
    foreach ($stmt->fetchAll() as $row) {
        $fingerprint = feedback_fingerprint($officeId, [
            'visit_date' => $row['visit_date'],
            'sex' => $row['sex'],
            'age' => (int)$row['age'],
            'client_type' => $row['client_type'],
            'service' => $row['service_received'],
            'ratings' => [
                (int)$row['timeliness_rating'],
                (int)$row['client_handling_rating'],
                (int)$row['quality_rating'],
                (int)$row['overall_rating'],
            ],
            'comment' => $row['comment'],
        ]);
        $update->execute([$fingerprint, (int)$row['id']]);
        $count += $update->rowCount();
    }
    return $count;
}

function model_version(): string
{
    $metrics = __DIR__ . '/../ml/models/model_metrics.json';
    if (!is_file($metrics)) return 'unavailable';
    $data = json_decode((string)file_get_contents($metrics), true);
    $samples = (int)($data['sample_count'] ?? 0);
    return 'svm-' . $samples . '-' . date('YmdHis', (int)filemtime($metrics));
}

function prediction_review_status(array $prediction): string
{
    return (($prediction['source'] ?? '') !== 'svm' || (float)($prediction['confidence'] ?? 0) < LOW_CONFIDENCE_THRESHOLD)
        ? 'needs_review'
        : 'not_required';
}

function send_notification(?int $userId, ?int $officeId, string $type, string $title, string $message, ?string $link = null): void
{
    try {
        $stmt = db()->prepare('INSERT INTO notifications (user_id, office_id, type, title, message, link_url) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $officeId, $type, $title, $message, $link]);
    } catch (Throwable) {
        // Keep the main operation usable before/while migrations are applied.
    }
}

function notify_admins(string $type, string $title, string $message, ?string $link = null): void
{
    try {
        $ids = db()->query("SELECT id FROM users WHERE role='admin' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) send_notification((int)$id, null, $type, $title, $message, $link);
    } catch (Throwable) {}
}

function notify_office_heads(int $officeId, string $type, string $title, string $message, ?string $link = null): void
{
    try {
        $stmt = db()->prepare("SELECT id FROM users WHERE office_id=? AND role='office_head' AND status='active'");
        $stmt->execute([$officeId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) send_notification((int)$id, $officeId, $type, $title, $message, $link);
    } catch (Throwable) {}
}

function notify_office_users(int $officeId, string $type, string $title, string $message, ?string $link = null): void
{
    try {
        $stmt = db()->prepare("SELECT id FROM users WHERE office_id=? AND role IN ('office_head','office_staff') AND status='active'");
        $stmt->execute([$officeId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) send_notification((int)$id, $officeId, $type, $title, $message, $link);
    } catch (Throwable) {}
}

function unread_notification_count(int $userId): int
{
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL AND (expires_at IS NULL OR expires_at>NOW())');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function review_count(array $user): int
{
    try {
        $where = ["f.is_void=0", "f.review_status='needs_review'"];
        $params = [];
        if (($user['role'] ?? '') !== 'admin') {
            $where[] = 'f.office_id=?';
            $params[] = (int)$user['office_id'];
        }
        $stmt = db()->prepare('SELECT COUNT(*) FROM feedback f WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function create_action_if_needed(int $feedbackId, int $officeId, string $sentiment, float $finalScore, string $comment, bool $notify = true): ?int
{
    if ($sentiment !== 'negative' && $finalScore >= ACTION_SCORE_THRESHOLD) return null;
    $existing = db()->prepare('SELECT id,status FROM actions WHERE feedback_id=? LIMIT 1');
    $existing->execute([$feedbackId]);
    if ($row = $existing->fetch()) {
        if ($row['status'] === 'completed') {
            db()->prepare("UPDATE actions SET status='needs_action',completed_at=NULL,approved_by_user_id=NULL,approved_at=NULL,resolution_notes=CONCAT(COALESCE(resolution_notes,''),'\nReopened after feedback review changed the required action status.') WHERE id=?")
                ->execute([(int)$row['id']]);
        }
        return (int)$row['id'];
    }

    $stmt = db()->prepare('INSERT INTO actions (feedback_id, office_id, title, details, status) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$feedbackId, $officeId, 'Review client feedback #' . $feedbackId, mb_substr($comment, 0, 1000), 'needs_action']);
    $id = (int)db()->lastInsertId();
    if ($notify) {
        notify_office_users($officeId, 'action', 'New feedback requires action', 'Feedback #' . $feedbackId . ' generated a new action item.', 'office/actions.php');
        notify_admins('action', 'New action item', 'Feedback #' . $feedbackId . ' requires office action.', 'admin/actions.php');
    }
    return $id;
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) $letters .= mb_strtoupper(mb_substr($part, 0, 1));
    return $letters ?: 'U';
}

function status_label(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

function csv_safe_cell(mixed $value): string
{
    $text = (string)$value;
    if ($text !== '' && preg_match('/^[=+\-@]/', ltrim($text))) return "'" . $text;
    return $text;
}

function csv_download(string $filename, array $headers, array $rows): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map('csv_safe_cell', $headers));
    foreach ($rows as $row) fputcsv($out, array_map('csv_safe_cell', $row));
    fclose($out);
    exit;
}
