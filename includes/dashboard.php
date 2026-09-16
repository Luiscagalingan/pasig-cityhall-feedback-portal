<?php
declare(strict_types=1);

function feedback_where(?int $officeId = null, ?string $dateFrom = null, ?string $dateTo = null, bool $activeOnly = true, bool $includeVoid = false, string $alias = 'f'): array
{
    $where = $includeVoid ? ['1=1'] : ["{$alias}.is_void=0"];
    $params = [];
    if ($officeId) { $where[] = "{$alias}.office_id=?"; $params[] = $officeId; }
    if ($dateFrom) { $where[] = "{$alias}.visit_date>=?"; $params[] = $dateFrom; }
    if ($dateTo) { $where[] = "{$alias}.visit_date<=?"; $params[] = $dateTo; }
    if ($activeOnly) $where[] = "o.status='active'";
    return [$where, $params];
}

function dashboard_metrics(?int $officeId = null, ?string $dateFrom = null, ?string $dateTo = null, bool $activeOnly = true, bool $includeVoid = false): array
{
    [$where, $params] = feedback_where($officeId, $dateFrom, $dateTo, $activeOnly, $includeVoid);
    $stmt = db()->prepare(
        "SELECT COUNT(*) total, COALESCE(AVG(f.average_rating),0) avg_rating,
                COALESCE(AVG(f.final_score),0) avg_final,
                SUM(f.sentiment='positive') positive_count,
                SUM(f.sentiment='neutral') neutral_count,
                SUM(f.sentiment='negative') negative_count,
                SUM(f.review_status='needs_review') review_count,
                SUM(f.sentiment_source='fallback') fallback_count
         FROM feedback f JOIN offices o ON o.id=f.office_id
         WHERE " . implode(' AND ', $where)
    );
    $stmt->execute($params);
    $m = $stmt->fetch() ?: [];

    $actionWhere = ['(a.feedback_id IS NULL OR af.is_void=0)'];
    $actionParams = [];
    if ($officeId) { $actionWhere[] = 'a.office_id=?'; $actionParams[] = $officeId; }
    if ($dateFrom) { $actionWhere[] = 'COALESCE(af.visit_date,DATE(a.created_at))>=?'; $actionParams[] = $dateFrom; }
    if ($dateTo) { $actionWhere[] = 'COALESCE(af.visit_date,DATE(a.created_at))<=?'; $actionParams[] = $dateTo; }
    if ($activeOnly) $actionWhere[] = "o.status='active'";
    $actionSql = 'WHERE ' . implode(' AND ', $actionWhere);
    $stmt = db()->prepare(
        "SELECT COUNT(*) total_actions,
                SUM(a.status='completed') completed_actions,
                SUM(a.status='needs_action') needs_action,
                SUM(a.status='in_progress') in_progress,
                SUM(a.status='pending_approval') pending_approval,
                COALESCE(AVG(CASE WHEN a.completed_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR,a.created_at,a.completed_at) END),0) avg_resolution_hours,
                COALESCE(AVG(CASE WHEN a.status<>'completed' THEN TIMESTAMPDIFF(HOUR,a.created_at,NOW()) END),0) avg_open_hours
         FROM actions a JOIN offices o ON o.id=a.office_id LEFT JOIN feedback af ON af.id=a.feedback_id {$actionSql}"
    );
    $stmt->execute($actionParams);
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
        'review_count' => (int)($m['review_count'] ?? 0),
        'fallback_count' => (int)($m['fallback_count'] ?? 0),
        'positive_rate' => $total ? round(((int)$m['positive_count'] / $total) * 100, 1) : 0,
        'total_actions' => $totalActions,
        'completed_actions' => (int)($a['completed_actions'] ?? 0),
        'needs_action' => (int)($a['needs_action'] ?? 0),
        'in_progress' => (int)($a['in_progress'] ?? 0),
        'pending_approval' => (int)($a['pending_approval'] ?? 0),
        'completion_rate' => $totalActions ? round(((int)$a['completed_actions'] / $totalActions) * 100, 1) : 0,
        'avg_resolution_hours' => round((float)($a['avg_resolution_hours'] ?? 0), 1),
        'avg_open_hours' => round((float)($a['avg_open_hours'] ?? 0), 1),
    ];
}

function monthly_trend(?int $officeId = null, int $months = 6): array
{
    $where = ["f.is_void=0", "o.status='active'", 'f.visit_date>=?'];
    $params = [date('Y-m-d', strtotime('-' . ($months - 1) . ' months first day of this month'))];
    if ($officeId) { $where[] = 'f.office_id=?'; $params[] = $officeId; }
    $stmt = db()->prepare(
        "SELECT DATE_FORMAT(f.visit_date, '%Y-%m') month_key, COUNT(*) responses, ROUND(AVG(f.final_score),2) avg_score
         FROM feedback f JOIN offices o ON o.id=f.office_id WHERE " . implode(' AND ', $where) . "
         GROUP BY DATE_FORMAT(f.visit_date, '%Y-%m') ORDER BY month_key"
    );
    $stmt->execute($params);
    $found = [];
    foreach ($stmt->fetchAll() as $row) $found[$row['month_key']] = $row;
    $result = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $key = date('Y-m', strtotime("-{$i} months"));
        $result[] = ['label' => date('M Y', strtotime($key . '-01')), 'responses' => (int)($found[$key]['responses'] ?? 0), 'score' => (float)($found[$key]['avg_score'] ?? 0)];
    }
    return $result;
}

function indicator_averages(?int $officeId = null): array
{
    $where = ["f.is_void=0", "o.status='active'"];
    $params = [];
    if ($officeId) { $where[] = 'f.office_id=?'; $params[] = $officeId; }
    $stmt = db()->prepare(
        "SELECT COALESCE(AVG(f.timeliness_rating),0) timeliness,
                COALESCE(AVG(f.client_handling_rating),0) handling,
                COALESCE(AVG(f.quality_rating),0) quality,
                COALESCE(AVG(f.overall_rating),0) overall
         FROM feedback f JOIN offices o ON o.id=f.office_id WHERE " . implode(' AND ', $where)
    );
    $stmt->execute($params);
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
        "SELECT o.id,o.name,o.code,COUNT(f.id) responses,
                COALESCE(ROUND(AVG(f.final_score),2),0) avg_score,
                COALESCE(ROUND(AVG(f.average_rating),2),0) avg_rating,
                SUM(f.sentiment='negative') negative_count,
                SUM(f.review_status='needs_review') review_count
         FROM offices o LEFT JOIN feedback f ON f.office_id=o.id AND f.is_void=0
         WHERE o.status='active' GROUP BY o.id ORDER BY avg_score DESC,o.name"
    )->fetchAll();
}

function recent_feedback(?int $officeId = null, int $limit = 8): array
{
    $where = ["f.is_void=0", "o.status='active'"];
    $params = [];
    if ($officeId) { $where[] = 'f.office_id=?'; $params[] = $officeId; }
    $stmt = db()->prepare(
        "SELECT f.*,o.name office_name,o.code office_code FROM feedback f
         JOIN offices o ON o.id=f.office_id WHERE " . implode(' AND ', $where) .
        " ORDER BY f.submitted_at DESC LIMIT " . (int)$limit
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function demographic_counts(?int $officeId, string $field): array
{
    if (!in_array($field, ['sex', 'client_type'], true)) return [];
    $where = ["f.is_void=0", "o.status='active'"];
    $params = [];
    if ($officeId) { $where[] = 'f.office_id=?'; $params[] = $officeId; }
    $stmt = db()->prepare("SELECT COALESCE(NULLIF(f.{$field},''),'Not specified') label,COUNT(*) count FROM feedback f JOIN offices o ON o.id=f.office_id WHERE " . implode(' AND ', $where) . " GROUP BY f.{$field} ORDER BY count DESC");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function age_group_counts(?int $officeId = null, ?string $dateFrom = null, ?string $dateTo = null, bool $activeOnly = true, bool $includeVoid = false): array
{
    $where = $includeVoid ? ['1=1'] : ["f.is_void=0"];
    $params = [];
    if ($activeOnly) $where[] = "o.status='active'";
    if ($officeId) { $where[] = 'f.office_id=?'; $params[] = $officeId; }
    if ($dateFrom) { $where[] = 'f.visit_date>=?'; $params[] = $dateFrom; }
    if ($dateTo) { $where[] = 'f.visit_date<=?'; $params[] = $dateTo; }
    $stmt = db()->prepare(
        "SELECT CASE WHEN f.age<18 THEN 'Below 18' WHEN f.age<=24 THEN '18-24' WHEN f.age<=34 THEN '25-34'
                     WHEN f.age<=44 THEN '35-44' WHEN f.age<=59 THEN '45-59' ELSE '60+' END label,
                COUNT(*) count
         FROM feedback f JOIN offices o ON o.id=f.office_id WHERE " . implode(' AND ', $where) . " GROUP BY label ORDER BY MIN(f.age)"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function service_summary(?int $officeId = null, int $limit = 8, ?string $dateFrom = null, ?string $dateTo = null, bool $activeOnly = true, bool $includeVoid = false): array
{
    $where = $includeVoid ? ['1=1'] : ["f.is_void=0"];
    $params = [];
    if ($activeOnly) $where[] = "o.status='active'";
    if ($officeId) { $where[] = 'f.office_id=?'; $params[] = $officeId; }
    if ($dateFrom) { $where[] = 'f.visit_date>=?'; $params[] = $dateFrom; }
    if ($dateTo) { $where[] = 'f.visit_date<=?'; $params[] = $dateTo; }
    $stmt = db()->prepare(
        "SELECT f.service_received label,COUNT(*) responses,ROUND(AVG(f.final_score),2) avg_score,
                SUM(f.sentiment='negative') negative_count
         FROM feedback f JOIN offices o ON o.id=f.office_id WHERE " . implode(' AND ', $where) .
        " GROUP BY f.service_received ORDER BY responses DESC,avg_score ASC LIMIT " . (int)$limit
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function frequent_concern_terms(?int $officeId = null, int $limit = 18): array
{
    $where = ["f.is_void=0", "o.status='active'", "f.sentiment='negative'"];
    $params = [];
    if ($officeId) { $where[]='f.office_id=?'; $params[]=$officeId; }
    $stmt = db()->prepare("SELECT f.comment FROM feedback f JOIN offices o ON o.id=f.office_id WHERE ".implode(' AND ',$where)." ORDER BY f.submitted_at DESC LIMIT 1000");
    $stmt->execute($params);
    $stop = array_flip(['ang','ng','mga','sa','na','at','ay','ako','ko','po','ito','yung','yong','yon','naman','lang','din','rin','dahil','pero','para','kasi','hindi','di','hndi','nmn','dba','the','and','was','were','is','are','to','of','for','it','this','that','very','with','service','staff','office']);
    $counts=[];
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $comment){
        $text=mb_strtolower((string)$comment);
        preg_match_all('/[\p{L}\p{N}]{3,}/u',$text,$matches);
        foreach($matches[0] as $word){
            $word=trim($word);
            if(isset($stop[$word])||mb_strlen($word)<3)continue;
            $counts[$word]=($counts[$word]??0)+1;
        }
    }
    arsort($counts);
    $out=[];
    foreach(array_slice($counts,0,$limit,true) as $word=>$count)$out[]=['term'=>$word,'count'=>$count];
    return $out;
}

function client_output_rows(?int $officeId, ?string $dateFrom = null, ?string $dateTo = null, string $assistedBy = ''): array
{
    $where = ["f.is_void=0", "o.status='active'"];
    $params = [];
    if ($officeId) { $where[] = 'f.office_id=?'; $params[] = $officeId; }
    if ($dateFrom) { $where[] = 'f.visit_date>=?'; $params[] = $dateFrom; }
    if ($dateTo) { $where[] = 'f.visit_date<=?'; $params[] = $dateTo; }
    if ($assistedBy !== '') { $where[] = 'f.assisted_by=?'; $params[] = $assistedBy; }
    $sql = "SELECT f.visit_date,COUNT(*) client_count,ROUND(AVG(f.average_rating),2) avg_rating,
                   COALESCE(NULLIF(MAX(f.assisted_by),''),'Unassigned') top_assisting_staff
            FROM feedback f JOIN offices o ON o.id=f.office_id WHERE " . implode(' AND ', $where) . "
            GROUP BY f.visit_date ORDER BY f.visit_date DESC";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function assisting_staff_options(?int $officeId, ?string $dateFrom = null, ?string $dateTo = null): array
{
    $where = ["f.is_void=0", "o.status='active'", "NULLIF(f.assisted_by,'') IS NOT NULL"];
    $params = [];
    if ($officeId) { $where[] = 'f.office_id=?'; $params[] = $officeId; }
    if ($dateFrom) { $where[] = 'f.visit_date>=?'; $params[] = $dateFrom; }
    if ($dateTo) { $where[] = 'f.visit_date<=?'; $params[] = $dateTo; }
    $stmt = db()->prepare("SELECT f.assisted_by label,COUNT(*) clients FROM feedback f JOIN offices o ON o.id=f.office_id WHERE " . implode(' AND ', $where) . " GROUP BY f.assisted_by ORDER BY clients DESC,label");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function feedback_highlights(?int $officeId, ?string $month = null, int $limit = 5): array
{
    $where = ["f.is_void=0", "o.status='active'", "NULLIF(f.comment,'') IS NOT NULL"];
    $params = [];
    if ($officeId) { $where[] = 'f.office_id=?'; $params[] = $officeId; }
    if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) { $where[] = "DATE_FORMAT(f.visit_date,'%Y-%m')=?"; $params[] = $month; }
    $base = " FROM feedback f JOIN offices o ON o.id=f.office_id WHERE " . implode(' AND ', $where);
    $good = db()->prepare("SELECT f.visit_date,f.comment,f.sentiment,f.assisted_by" . $base . " AND f.sentiment='positive' ORDER BY f.visit_date DESC,f.id DESC LIMIT " . (int)$limit);
    $good->execute($params);
    $critical = db()->prepare("SELECT f.visit_date,f.comment,f.sentiment,f.assisted_by" . $base . " AND f.sentiment='negative' ORDER BY f.visit_date DESC,f.id DESC LIMIT " . (int)$limit);
    $critical->execute($params);
    return ['good'=>$good->fetchAll(), 'critical'=>$critical->fetchAll()];
}
