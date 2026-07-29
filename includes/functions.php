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
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
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
        // Audit failure must not interrupt the main user action.
    }
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
    $ratingPercent = round(($average / 4) * 100, 2);
    $commentScore = sentiment_numeric_score($sentiment);
    $finalScore = round(($ratingPercent * RATING_WEIGHT) + ($commentScore * COMMENT_WEIGHT), 2);

    return [
        'average_rating' => $average,
        'rating_percent' => $ratingPercent,
        'comment_score' => $commentScore,
        'final_score' => $finalScore,
    ];
}

function final_interpretation(float $score): string
{
    if ($score >= 85) return 'Highly Satisfied';
    if ($score >= 70) return 'Satisfied';
    if ($score >= 55) return 'Needs Improvement';
    return 'Critical Concern';
}

function office_scope_clause(array $user, string $column = 'office_id'): array
{
    if (($user['role'] ?? '') === 'admin') {
        return ['1=1', []];
    }
    return ["{$column} = ?", [(int)$user['office_id']]];
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

function request_int(string $key, int $default = 0): int
{
    return filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT) ?: $default;
}

function post_int(string $key, int $default = 0): int
{
    return filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT) ?: $default;
}

function valid_rating(mixed $value): bool
{
    return in_array((int)$value, [1,2,3,4], true);
}

function normalize_header(string $header): string
{
    $header = strtolower(trim($header));
    $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?? $header;
    return trim($header, '_');
}

function create_action_if_needed(int $feedbackId, int $officeId, string $sentiment, float $finalScore, string $comment): void
{
    if ($sentiment !== 'negative' && $finalScore >= ACTION_SCORE_THRESHOLD) {
        return;
    }

    $stmt = db()->prepare('INSERT INTO actions (feedback_id, office_id, title, details, status) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([
        $feedbackId,
        $officeId,
        'Review client feedback #' . $feedbackId,
        mb_substr($comment, 0, 1000),
        'needs_action',
    ]);
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $letters ?: 'U';
}

function status_label(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

function csv_download(string $filename, array $headers, array $rows): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}
