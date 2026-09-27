<?php
declare(strict_types=1);

$context = stream_context_create([
    'http' => [
        'timeout' => 3,
        'ignore_errors' => true,
    ],
]);
$body = @file_get_contents('http://127.0.0.1/health.php', false, $context);
$statusLine = $http_response_header[0] ?? '';
exit(trim((string) $body) === 'ok' && str_contains($statusLine, ' 200 ') ? 0 : 1);
