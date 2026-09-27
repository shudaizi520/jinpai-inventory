<?php
declare(strict_types=1);

function app_config(string $key, mixed $default = null): mixed
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    if (preg_match('/(?:PASSWORD|SECRET|TOKEN|KEY)$/', $key) === 1) {
        $normalized = strtolower(trim((string) $value));
        $placeholders = [
            'change-me',
            'changeme',
            'change_this',
            'replace-me',
            'replace_this',
            'password',
            'example',
        ];
        if (in_array($normalized, $placeholders, true)) {
            throw new RuntimeException("{$key} still uses a placeholder value");
        }
    }

    return $value;
}

function app_config_bool(string $key, bool $default = false): bool
{
    $value = app_config($key, null);
    if ($value === null) {
        return $default;
    }

    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($parsed === null) {
        throw new RuntimeException("{$key} must be true or false");
    }

    return $parsed;
}

function app_config_list(string $key): array
{
    $value = trim((string) app_config($key, ''));
    if ($value === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
}

function app_pdo(): PDO
{
    $host = (string) app_config('DB_HOST', 'db');
    $port = (int) app_config('DB_PORT', 3306);
    $database = (string) app_config('DB_NAME', 'inventory');
    $username = (string) app_config('DB_USER', 'inventory');
    $password = app_config('DB_PASSWORD');

    if (!is_string($password) || $password === '') {
        throw new RuntimeException('DB_PASSWORD is required');
    }
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('DB_PORT is invalid');
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
}
