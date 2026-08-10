<?php
declare(strict_types=1);

// Development-only router for exposing this project at the tunnel root while
// preserving the application's normal /pasig-cityhall-feedback-portal URLs.
$uriPath = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

if ($uriPath === '/' || $uriPath === '') {
    require dirname(__DIR__) . '/index.php';
    return true;
}

// The application's normal prefixed URLs are already under the htdocs root.
if (str_starts_with($uriPath, '/pasig-cityhall-feedback-portal/')) {
    return false;
}

// Also allow convenient tunnel-root URLs such as /login.php and /assets/...
$projectRoot = realpath(dirname(__DIR__));
$requestedFile = realpath($projectRoot . DIRECTORY_SEPARATOR . ltrim($uriPath, '/'));

if ($requestedFile === false
    || !str_starts_with($requestedFile, $projectRoot . DIRECTORY_SEPARATOR)
    || !is_file($requestedFile)) {
    http_response_code(404);
    echo 'Not Found';
    return true;
}

if (strtolower(pathinfo($requestedFile, PATHINFO_EXTENSION)) === 'php') {
    $_SERVER['SCRIPT_FILENAME'] = $requestedFile;
    $_SERVER['SCRIPT_NAME'] = $uriPath;
    require $requestedFile;
    return true;
}

$mimeType = function_exists('mime_content_type')
    ? (mime_content_type($requestedFile) ?: 'application/octet-stream')
    : 'application/octet-stream';
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($requestedFile));
readfile($requestedFile);
return true;
