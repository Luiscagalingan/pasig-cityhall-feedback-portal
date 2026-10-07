<?php
declare(strict_types=1);
require_once __DIR__ . '/output_periods.php';

function client_count_actor(PDO $pdo, int $userId, string $role): array
{
    $stmt = $pdo->prepare("SELECT u.*,o.status office_status FROM users u LEFT JOIN offices o ON o.id=u.office_id WHERE u.id=? AND u.role=? AND u.status='active'");
    $stmt->execute([$userId, $role]);
    $user = $stmt->fetch();
    if (!$user || ($role !== 'admin' && $user['office_status'] !== 'active')) {
        throw new DomainException('This account cannot perform this operation.');
    }
    return $user;
}

function client_count_requester(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("SELECT u.*,o.status office_status FROM users u LEFT JOIN offices o ON o.id=u.office_id
        WHERE u.id=? AND u.role IN ('office_head','office_staff') AND u.status='active'");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || $user['office_status'] !== 'active') {
        throw new DomainException('This account cannot perform this operation.');
    }
    return $user;
}

function assisted_client_count(PDO $pdo, int $staffId, int $officeId, int $year): int
{
    client_count_year($year);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM feedback f JOIN offices o ON o.id=f.office_id
        WHERE f.office_id=? AND o.status='active' AND f.is_void=0
        AND ((f.source='assisted_survey' AND f.imported_by_user_id=?) OR (f.source='csv_import' AND f.assisted_by_user_id=?))
        AND NULLIF(TRIM(f.assisted_by),'') IS NOT NULL
        AND f.visit_date>=? AND f.visit_date<?");
    $stmt->execute([$officeId, $staffId, $staffId, $year . '-01-01', ($year + 1) . '-01-01']);
    return (int)$stmt->fetchColumn();
}

function client_count_audit(PDO $pdo, int $userId, string $action, string $details): void
{
    // Required audit entry shares the request transaction; failure rolls it back.
    $pdo->prepare('INSERT INTO audit_logs(user_id,action,details,ip_address) VALUES(?,?,?,?)')
        ->execute([$userId, $action, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
}

function create_client_count_request(PDO $pdo, int $staffId, int $year): int
{
    client_count_year($year);
    $pdo->beginTransaction();
    try {
        $staff = client_count_requester($pdo, $staffId);
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username=? AND role='admin' AND status='active' LIMIT 1");
        $stmt->execute(['uno']);
        $requestAdminId = $stmt->fetchColumn();
        if (!$requestAdminId) throw new DomainException('Sir Uno is not available to receive this request.');
        $pdo->prepare('INSERT INTO client_count_requests(staff_user_id,office_id,requested_year) VALUES(?,?,?)')
            ->execute([$staffId, (int)$staff['office_id'], $year]);
        $id = (int)$pdo->lastInsertId();
        $notify = $pdo->prepare("INSERT INTO notifications(user_id,office_id,sender_user_id,type,title,message,link_url) VALUES(?,?,?,'client_count_request',?,?,?)");
        $notify->execute([(int)$requestAdminId, (int)$staff['office_id'], $staffId, 'Annual client count requested',
            $staff['full_name'] . ' (@' . $staff['username'] . ') requested Sir Uno\'s review of annual client-count request #' . $id . ' for ' . $year . '.',
            'admin/client-counts.php?request_id=' . $id]);
        client_count_audit($pdo, $staffId, 'client_count_request', 'Created annual request #' . $id . ' for ' . $year . ' for Administrator review');
        $pdo->commit();
        return $id;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PDOException && (int)($error->errorInfo[1] ?? 0) === 1062) {
            throw new DomainException('A pending annual request already exists for this year.');
        }
        throw $error;
    }
}

function answer_client_count_request(PDO $pdo, int $adminId, int $requestId): int
{
    $pdo->beginTransaction();
    try {
        $actorStmt = $pdo->prepare("SELECT u.*,o.status office_status FROM users u LEFT JOIN offices o ON o.id=u.office_id WHERE u.id=? AND u.status='active'");
        $actorStmt->execute([$adminId]);
        $admin = $actorStmt->fetch();
        if (!$admin || !in_array($admin['role'], ['admin','office_head'], true)
            || ($admin['role'] === 'office_head' && $admin['office_status'] !== 'active')
            || ($admin['role'] === 'admin' && strcasecmp((string)$admin['username'], 'uno') !== 0)) {
            throw new DomainException('Only Sir Uno or the assigned Office Head can answer client-count requests.');
        }
        // Serialize competing administrators before calculating or notifying.
        $stmt = $pdo->prepare('SELECT * FROM client_count_requests WHERE id=? FOR UPDATE');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        if (!$request) throw new DomainException('Client-count request not found.');
        if ($request['status'] !== 'pending') throw new DomainException('This request has already been answered.');
        if ($request['requested_year'] === null) throw new DomainException('This legacy request has no year. Ask the staff member to submit an annual request.');
        $staff = client_count_requester($pdo, (int)$request['staff_user_id']);
        if ($admin['role'] === 'office_head'
            && ((int)$admin['office_id'] !== (int)$request['office_id'] || $staff['role'] !== 'office_staff')) {
            throw new DomainException('Office Heads can answer only Staff requests from their assigned office.');
        }
        if ((int)$staff['office_id'] !== (int)$request['office_id']) {
            throw new DomainException('The staff office has changed. Ask the staff member to send a new request.');
        }
        $year = client_count_year($request['requested_year']);
        $count = assisted_client_count($pdo, (int)$staff['id'], (int)$request['office_id'], $year);
        $update = $pdo->prepare("UPDATE client_count_requests SET status='answered',answered_count=?,answered_at=NOW(),answered_by_user_id=? WHERE id=? AND status='pending'");
        $update->execute([$count, $adminId, $requestId]);
        if ($update->rowCount() !== 1) throw new DomainException('This request has already been answered.');
        $responderLabel = $admin['role'] === 'office_head' ? 'Your Office Head' : 'An Administrator';
        $pdo->prepare("INSERT INTO notifications(user_id,office_id,sender_user_id,type,title,message,link_url) VALUES(?,?,?,'client_count',?,?,?)")
            ->execute([(int)$staff['id'], (int)$request['office_id'], $adminId, 'Annual client-count request answered',
                $responderLabel . ' answered request #' . $requestId . '. Your verified annual count for ' . $year . ' is ' . $count . '. Status: Answered.',
                'office/client-count-request.php']);
        $auditAction = $admin['role'] === 'office_head' ? 'office_head_client_count_sent' : 'admin_client_count_sent';
        client_count_audit($pdo, $adminId, $auditAction, 'Answered annual request #' . $requestId . ' for staff #' . $staff['id'] . '; year=' . $year . '; count=' . $count);
        $pdo->commit();
        return $count;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function client_count_requests(PDO $pdo, ?array $staff = null): array
{
    // Own request snapshots remain readable after an office transfer.
    $where = '';
    $params = [];
    if ($staff !== null) {
        if (($staff['role'] ?? '') === 'office_head') {
            $where = ' WHERE r.office_id=?';
            $params[] = (int)$staff['office_id'];
        } else {
            $where = ' WHERE r.staff_user_id=?';
            $params[] = (int)$staff['id'];
        }
    }
    $stmt = $pdo->prepare("SELECT r.*,u.full_name,u.username,u.role staff_role,u.status staff_status,u.office_id current_office_id,o.name office_name,o.code office_code,o.status office_status,a.full_name administrator_name
        FROM client_count_requests r JOIN users u ON u.id=r.staff_user_id JOIN offices o ON o.id=r.office_id
        LEFT JOIN users a ON a.id=r.answered_by_user_id" . $where . " ORDER BY r.status='pending' DESC,r.requested_at DESC,r.id DESC");
    $stmt->execute($params);
    return $stmt->fetchAll();
}
