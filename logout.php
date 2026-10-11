<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
$cacheHeadersAvailable = !headers_sent();
send_private_no_store_headers();
if ($cacheHeadersAvailable) header('Clear-Site-Data: "cache"');
$user = current_user();
if ($user) audit((int)$user['id'], 'logout', 'User logged out');
logout_user();
session_start();
set_flash('success', 'You have been logged out.');
redirect('login.php');
