<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/security.php';

date_default_timezone_set((string) app_config('APP_TIMEZONE', 'Asia/Shanghai'));
start_secure_session();
send_security_headers();

try {
    $pdo = app_pdo();
    $databaseOffset = (new DateTimeImmutable('now'))->format('P');
    $pdo->exec('SET time_zone = ' . $pdo->quote($databaseOffset));
} catch (Throwable $error) {
    $requestId = safe_log($error);
    if (PHP_SAPI === 'cli') {
        throw $error;
    }
    http_response_code(503);
    echo '系统暂时无法连接数据库，请稍后重试。请求编号：' . e($requestId);
    exit;
}
