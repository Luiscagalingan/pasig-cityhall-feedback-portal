<?php
declare(strict_types=1);

function request_is_https(?array $server = null, ?array $environment = null): bool
{
    $server ??= $_SERVER;
    $environment ??= $_ENV + getenv();
    if (($environment['PASIG_FORCE_HTTPS'] ?? '') === '1') return true;
    if (!empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off') return true;

    if (($environment['PASIG_TRUST_PROXY_HEADERS'] ?? '') !== '1') return false;
    $trusted = array_filter(array_map('trim', explode(',', (string)($environment['PASIG_TRUSTED_PROXY_IPS'] ?? ''))));
    $remote = (string)($server['REMOTE_ADDR'] ?? '');
    if ($remote === '' || !in_array($remote, $trusted, true)) return false;
    return strtolower(trim(explode(',', (string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https';
}

function trusted_proxy_request(?array $server = null, ?array $environment = null): bool
{
    $server ??= $_SERVER;
    $environment ??= $_ENV + getenv();
    if (($environment['PASIG_TRUST_PROXY_HEADERS'] ?? '') !== '1') return false;
    $trusted = array_filter(array_map('trim', explode(',', (string)($environment['PASIG_TRUSTED_PROXY_IPS'] ?? ''))));
    return in_array((string)($server['REMOTE_ADDR'] ?? ''), $trusted, true);
}

function request_client_ip(?array $server = null, ?array $environment = null): string
{
    $server ??= $_SERVER;
    $environment ??= $_ENV + getenv();
    $remote = (string)($server['REMOTE_ADDR'] ?? 'unknown');
    if (($environment['PASIG_TRUST_RAILWAY_HEADERS'] ?? '') === '1'
        && trim((string)($environment['RAILWAY_ENVIRONMENT_ID'] ?? '')) !== '') {
        $railwayIp = trim((string)($server['HTTP_X_REAL_IP'] ?? ''));
        if (filter_var($railwayIp, FILTER_VALIDATE_IP)) return $railwayIp;
    }
    if (!trusted_proxy_request($server, $environment)) return $remote;
    $forwarded = trim(explode(',', (string)($server['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
    return filter_var($forwarded, FILTER_VALIDATE_IP) ? $forwarded : $remote;
}
