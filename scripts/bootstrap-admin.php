<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/php/lib/config.php';
require_once dirname(__DIR__) . '/php/lib/migrations.php';

$username = (string) app_config('BOOTSTRAP_ADMIN_USERNAME', 'admin');
$password = app_config('BOOTSTRAP_ADMIN_PASSWORD');
if (!is_string($password) || $password === '') {
    throw new RuntimeException('BOOTSTRAP_ADMIN_PASSWORD is required for a fresh installation');
}

$pdo = app_pdo();
run_migrations($pdo);
$created = bootstrap_admin($pdo, $username, $password);
fwrite(STDOUT, $created ? "Initial administrator created.\n" : "Users already exist; administrator bootstrap skipped.\n");
