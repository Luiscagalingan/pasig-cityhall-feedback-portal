<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login(['admin']);
// Compatibility route for old notification links; processing is administrator-only.
redirect('admin/client-counts.php');
