<?php
declare(strict_types=1);

$authPath = dirname(__DIR__, 2) . '/php/lib/auth.php';
$migrationPath = dirname(__DIR__, 2) . '/php/lib/migrations.php';
if (is_file($migrationPath)) {
    require_once $migrationPath;
}
if (is_file($authPath)) {
    require_once $authPath;
}

function auth_test_database(callable $test): void
{
    assert_true(function_exists('record_auth_failure'), 'record_auth_failure() is missing');
    $dsn = getenv('TEST_DB_DSN');
    if (!is_string($dsn) || $dsn === '') {
        throw new TestFailure('TEST_DB_DSN is required for authentication integration tests');
    }
    $admin = new PDO($dsn, getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $name = 'inventory_auth_' . getmypid() . '_' . bin2hex(random_bytes(4));
    $admin->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    try {
        $databaseDsn = preg_replace('/;dbname=[^;]*/', '', $dsn) . ';dbname=' . $name;
        $pdo = new PDO($databaseDsn, getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        run_migrations($pdo);
        $test($pdo);
    } finally {
        $admin->exec("DROP DATABASE IF EXISTS `{$name}`");
    }
}

test('csrf validation rejects missing and invalid tokens before mutation', function (): void {
    assert_true(function_exists('require_csrf'), 'require_csrf() is missing');
    $_SESSION = ['_csrf_token' => 'expected'];
    unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_POST['_csrf_token']);
    assert_throws(fn () => require_csrf(), RuntimeException::class);
    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'wrong';
    assert_throws(fn () => require_csrf(), RuntimeException::class);
    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'expected';
    require_csrf();
    assert_true(true);
});

test('authentication failures lock at the threshold and clear after success', function (): void {
    auth_test_database(function (PDO $pdo): void {
        assert_same(null, auth_lock_until($pdo, 'ip', '203.0.113.30'));
        assert_same(1, record_auth_failure($pdo, 'ip', '203.0.113.30', 3, 15));
        assert_same(2, record_auth_failure($pdo, 'ip', '203.0.113.30', 3, 15));
        assert_same(null, auth_lock_until($pdo, 'ip', '203.0.113.30'));
        assert_same(3, record_auth_failure($pdo, 'ip', '203.0.113.30', 3, 15));
        assert_true(auth_lock_until($pdo, 'ip', '203.0.113.30') instanceof DateTimeImmutable);
        clear_auth_failures($pdo, 'ip', '203.0.113.30');
        assert_same(null, auth_lock_until($pdo, 'ip', '203.0.113.30'));
    });
});

test('untrusted forwarded addresses cannot evade password recovery throttling', function (): void {
    assert_true(function_exists('client_ip'), 'client_ip() is missing');
    $server = ['REMOTE_ADDR' => '203.0.113.31', 'HTTP_X_FORWARDED_FOR' => '198.51.100.99'];
    assert_same('203.0.113.31', client_ip($server, []));
    assert_same('198.51.100.99', client_ip($server, ['203.0.113.31']));
});

test('password recovery records both source network and target account', function (): void {
    auth_test_database(function (PDO $pdo): void {
        $keys = recovery_rate_keys('203.0.113.44', 'owner');
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            record_recovery_failure($pdo, $keys);
        }
        assert_true(auth_lock_until($pdo, 'username', $keys['username']) instanceof DateTimeImmutable);
        $statement = $pdo->prepare("SELECT failed_count FROM login_blocks WHERE type = 'ip' AND identifier = ?");
        $statement->execute([$keys['ip']]);
        assert_same(5, (int) $statement->fetchColumn());
        clear_recovery_account_failures($pdo, $keys);
        assert_same(null, auth_lock_until($pdo, 'username', $keys['username']));
        assert_same(null, auth_lock_until($pdo, 'ip', $keys['ip']));
    });
});
