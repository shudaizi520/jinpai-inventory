<?php
declare(strict_types=1);

require_once '/var/www/html/lib/config.php';

try {
    $pdo = app_pdo();
    $result = $pdo->query('SELECT 1')->fetchColumn();
    exit((int) $result === 1 ? 0 : 1);
} catch (Throwable) {
    exit(1);
}
