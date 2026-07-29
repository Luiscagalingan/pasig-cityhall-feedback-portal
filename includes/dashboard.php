<?php
declare(strict_types=1);

function dashboard_metrics(?int $officeId = null): array
{
    $where = $officeId ? 'WHERE office_id=?' : '';
    $params = $officeId ? [$officeId] : [];

    $stmt = db()->prepare(
        "SELECT COUNT(*) total,
                COALESCE(AVG(average_rating),0) avg_rating,
                COALESCE(AVG(final_score),0) avg_final,
                SUM(sentiment='positive') positive_count,
                SUM(sentiment='neutral') neutral_count,
                SUM(sentiment='negative') negative_count
         FROM feedback {$where}"
    );
    $stmt->execute($params);
    $m = $stmt->fetch() ?: [];

    $actionWhere = $officeId ? 'WHERE office_id=?' : '';
    $stmt = db()->prepare(
        "SELECT COUNT(*) total_actions,
                SUM(status='completed') completed_actions,
                SUM(status='needs_action') needs_action,
                SUM(status='in_progress') in_progress
         FROM actions {$actionWhere}"
    );
    $stmt->execute($params);
    $a = $stmt->fetch() ?: [];

    $total = (int)($m['total'] ?? 0);
    $totalActions = (int)($a['total_actions'] ?? 0);
    return [
        'total' => $total,
        'avg_rating' => round((float)($m['avg_rating'] ?? 0), 2),
        'avg_final' => round((float)($m['avg_final'] ?? 0), 2),
        'positive' => (int)($m['positive_count'] ?? 0),
        'neutral' => (int)($m['neutral_count'] ?? 0),
        'negative' => (int)($m['negative_count'] ?? 0),
        'positive_rate' => $total ? round(((int)$m['positive_count'] / $total) * 100, 1) : 0,
        'total_actions' => $totalActions,
        'completed_actions' => (int)($a['completed_actions'] ?? 0),
        'needs_action' => (int)($a['needs_action'] ?? 0),
        'in_progress' => (int)($a['in_progress'] ?? 0),
        'completion_rate' => $totalActions ? round(((int)$a['completed_actions'] / $totalActions) * 100, 1) : 0,
    ];
}

function monthly_trend(?int $officeId = null, int $months = 6): array
{
    $where = $officeId ? 'AND office_id=?' : '';
    $params = [date('Y-m-d', strtotime('-' . ($months - 1) . ' months first day of this month'))];
    if ($officeId) $params[] = $officeId;
    $stmt = db()->prepare(
        "SELECT DATE_FORMAT(visit_date, '%Y-%m') month_key,
                COUNT(*) responses,
                ROUND(AVG(final_score),2) avg_score
         FROM feedback
         WHERE visit_date >= ? {$where}
         GROUP BY DATE_FORMAT(visit_date, '%Y-%m')
         ORDER BY month_key"
    );
    $stmt->execute($params);
    $found = [];
    foreach ($stmt->fetchAll() as $row) $found[$row['month_key']] = $row;

    $result = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $key = date('Y-m', strtotime("-{$i} months"));
        $result[] = [
            'label' => date('M Y', strtotime($key . '-01')),
            'responses' => (int)($found[$key]['responses'] ?? 0),
            'score' => (float)($found[$key]['avg_score'] ?? 0),
        ];
    }
    return $result;
}

function indicator_averages(?int $officeId = null): array
{
    $where = $officeId ? 'WHERE office_id=?' : '';
    $stmt = db()->prepare(
        "SELECT COALESCE(AVG(timeliness_rating),0) timeliness,
                COALESCE(AVG(client_handling_rating),0) handling,
                COALESCE(AVG(quality_rating),0) quality,
                COALESCE(AVG(overall_rating),0) overall
         FROM feedback {$where}"
    );
    $stmt->execute($officeId ? [$officeId] : []);
    $r = $stmt->fetch() ?: [];
    return [
        'Timeliness' => round((float)($r['timeliness'] ?? 0), 2),
        'Client Handling' => round((float)($r['handling'] ?? 0), 2),
        'Quality of Service' => round((float)($r['quality'] ?? 0), 2),
        'Overall Satisfaction' => round((float)($r['overall'] ?? 0), 2),
    ];
}

function office_comparison(): array
{
    return db()->query(
        "SELECT o.id, o.name, o.code,
                COUNT(f.id) responses,
                COALESCE(ROUND(AVG(f.final_score),2),0) avg_score,
                COALESCE(ROUND(AVG(f.average_rating),2),0) avg_rating,
                SUM(f.sentiment='negative') negative_count
         FROM offices o
         LEFT JOIN feedback f ON f.office_id=o.id
         WHERE o.status='active'
         GROUP BY o.id
         ORDER BY avg_score DESC, o.name"
    )->fetchAll();
}

function recent_feedback(?int $officeId = null, int $limit = 8): array
{
    $where = $officeId ? 'WHERE f.office_id=?' : '';
    $sql = "SELECT f.*, o.name office_name, o.code office_code
            FROM feedback f JOIN offices o ON o.id=f.office_id
            {$where}
            ORDER BY f.submitted_at DESC LIMIT " . (int)$limit;
    $stmt = db()->prepare($sql);
    $stmt->execute($officeId ? [$officeId] : []);
    return $stmt->fetchAll();
}

function demographic_counts(?int $officeId, string $field): array
{
    $allowed = ['sex','client_type'];
    if (!in_array($field, $allowed, true)) return [];
    $where = $officeId ? 'WHERE office_id=?' : '';
    $stmt = db()->prepare("SELECT COALESCE(NULLIF({$field},''),'Not specified') label, COUNT(*) count FROM feedback {$where} GROUP BY {$field} ORDER BY count DESC");
    $stmt->execute($officeId ? [$officeId] : []);
    return $stmt->fetchAll();
}
