<?php
declare(strict_types=1);

function app_storage_root(): string
{
    $configured = trim((string)getenv('PASIG_STORAGE_PATH'));
    return rtrim($configured !== '' ? $configured : dirname(__DIR__) . '/storage', '/\\');
}

function app_storage_path(string $relative = ''): string
{
    $relative = ltrim(str_replace('\\', '/', $relative), '/');
    if ($relative === '' || str_contains($relative, '..')) {
        if ($relative !== '') throw new InvalidArgumentException('Invalid storage path.');
        return app_storage_root();
    }
    return app_storage_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
}

function ensure_runtime_directory(string $relative): string
{
    $path = app_storage_path($relative);
    if (!is_dir($path) && !@mkdir($path, 0770, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to initialize runtime storage.');
    }
    return $path;
}
