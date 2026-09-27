<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/config.php';

try {
    $pdo = app_pdo();
} catch (Throwable $error) {
    require_once __DIR__ . '/lib/security.php';
    $requestId = safe_log($error);
    if (PHP_SAPI === 'cli') {
        throw $error;
    }
    http_response_code(503);
    die('系统暂时无法连接数据库，请稍后重试。请求编号：' . e($requestId));
}
