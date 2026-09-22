<?php
declare(strict_types=1);
// Reuse the isolated database guard/connection without touching production data.
ob_start();
require __DIR__.'/client_count_fixture.php';
ob_end_clean();
if ($argv[1] === 'seed_actions') {
    $insert = $pdo->prepare('INSERT INTO actions(office_id,title,status,resolution_notes) VALUES(1,?,?,?)');
    foreach (['needs_action','in_progress','completed','pending_approval'] as $status) {
        $insert->execute(['Fixture '.$status, $status, 'Original notes']);
    }
}
echo json_encode($pdo->query('SELECT * FROM actions ORDER BY id')->fetchAll());
