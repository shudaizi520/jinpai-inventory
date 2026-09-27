<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo '退出登录只接受 POST 请求。';
    exit;
}

try {
    require_csrf();
    perform_logout();
    header('Location: login.php');
} catch (HttpException $exception) {
    http_response_code($exception->statusCode());
    echo e($exception->getMessage());
}
exit;
