<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/migrations.php';

send_security_headers();
header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = app_pdo();
    $statement = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version = ?');
    $statement->execute([INVENTORY_SCHEMA_VERSION]);
    if ((int) $statement->fetchColumn() !== 1) {
        throw new RuntimeException('Expected database migration is missing.');
    }
    echo "ok\n";
} catch (Throwable $error) {
    safe_log($error);
    http_response_code(503);
    echo "unavailable\n";
}
