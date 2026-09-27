<?php
declare(strict_types=1);

$configPath = dirname(__DIR__, 2) . '/php/lib/config.php';
$securityPath = dirname(__DIR__, 2) . '/php/lib/security.php';
if (is_file($configPath)) {
    require_once $configPath;
}
if (is_file($securityPath)) {
    require_once $securityPath;
}

test('configuration reads environment values and explicit defaults', function (): void {
    assert_true(function_exists('app_config'), 'app_config() is missing');
    putenv('APP_TEST_SETTING=from-environment');
    assert_same('from-environment', app_config('APP_TEST_SETTING', 'fallback'));
    putenv('APP_TEST_SETTING');
    assert_same('fallback', app_config('APP_TEST_SETTING', 'fallback'));
});

test('configuration rejects documented placeholder secrets', function (): void {
    assert_true(function_exists('app_config'), 'app_config() is missing');
    putenv('DB_PASSWORD=change-me');
    assert_throws(fn () => app_config('DB_PASSWORD'), RuntimeException::class);
    putenv('DB_PASSWORD');
});

test('csrf validation rejects a mismatched request token', function (): void {
    assert_true(function_exists('require_csrf'), 'require_csrf() is missing');
    $_SESSION = ['_csrf_token' => 'known-token'];
    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'wrong-token';
    unset($_POST['_csrf_token']);
    assert_throws(fn () => require_csrf(), RuntimeException::class);
});

test('csrf validation accepts the matching header token', function (): void {
    assert_true(function_exists('require_csrf'), 'require_csrf() is missing');
    $_SESSION = ['_csrf_token' => 'known-token'];
    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'known-token';
    unset($_POST['_csrf_token']);
    require_csrf();
    assert_true(true);
});

test('password policy requires at least twelve characters', function (): void {
    assert_true(function_exists('validate_password'), 'validate_password() is missing');
    assert_false(validate_password('Short1!')['valid']);
    assert_true(validate_password('LongEnough12!')['valid']);
});

test('untrusted clients cannot choose their IP using forwarded headers', function (): void {
    assert_true(function_exists('client_ip'), 'client_ip() is missing');
    $server = [
        'REMOTE_ADDR' => '203.0.113.8',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.25',
    ];
    assert_same('203.0.113.8', client_ip($server, []));
});

test('configured reverse proxies may supply the original client IP', function (): void {
    assert_true(function_exists('client_ip'), 'client_ip() is missing');
    $server = [
        'REMOTE_ADDR' => '10.0.0.2',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.25, 10.0.0.1',
    ];
    assert_same('198.51.100.25', client_ip($server, ['10.0.0.2']));
});

test('safe logging returns an opaque request identifier', function (): void {
    assert_true(function_exists('safe_log'), 'safe_log() is missing');
    $logFile = tempnam(sys_get_temp_dir(), 'inventory-security-test-');
    $previousLog = ini_get('error_log');
    ini_set('error_log', $logFile);
    $requestId = safe_log(new RuntimeException('database-password-must-not-leak'));
    ini_set('error_log', (string) $previousLog);
    assert_matches('/^[a-f0-9]{16}$/', $requestId);
    assert_false(str_contains($requestId, 'password'));
    assert_true(str_contains((string) file_get_contents($logFile), $requestId));
    unlink($logFile);
});
