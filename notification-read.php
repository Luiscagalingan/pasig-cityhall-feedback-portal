<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$user = require_login();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

verify_csrf();
$stmt = db()->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE user_id=? AND read_at IS NULL AND (expires_at IS NULL OR expires_at>NOW())');
$stmt->execute([(int)$user['id']]);
$markedRead=$stmt->rowCount();
if($markedRead)audit((int)$user['id'],'notifications_preview_read','Binasa mula sa notification bell ang '.$markedRead.' notification(s)');

echo json_encode(['ok' => true, 'marked_read' => $markedRead]);
