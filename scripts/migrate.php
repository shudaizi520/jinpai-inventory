<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/php/lib/config.php';
require_once dirname(__DIR__) . '/php/lib/migrations.php';

$pdo = app_pdo();
run_migrations($pdo);
fwrite(STDOUT, "Database migrations completed.\n");
