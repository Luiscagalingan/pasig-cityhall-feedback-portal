<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
$user = current_user();
if ($user) audit((int)$user['id'], 'logout', 'User logged out');
logout_user();
session_name(SESSION_NAME);
session_start();
set_flash('success', 'You have been logged out.');
redirect('login.php');
