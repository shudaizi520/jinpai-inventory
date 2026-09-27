<?php
declare(strict_types=1);

$migrationPath = dirname(__DIR__, 2) . '/php/lib/migrations.php';
$registrationPath = dirname(__DIR__, 2) . '/php/lib/registration.php';
if (is_file($migrationPath)) {
    require_once $migrationPath;
}
if (is_file($registrationPath)) {
    require_once $registrationPath;
}

function initial_setup_test_database(callable $test): void
{
    $dsn = getenv('TEST_DB_DSN');
    if (!is_string($dsn) || $dsn === '') {
        throw new TestFailure('TEST_DB_DSN is required for initial setup integration tests');
    }
    $admin = new PDO($dsn, getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $name = 'inventory_setup_' . getmypid() . '_' . bin2hex(random_bytes(4));
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

function initial_setup_valid_input(string $username = 'first-admin'): array
{
    return [
        'username' => $username,
        'password' => 'FirstAdminPassword12!',
        'password_confirm' => 'FirstAdminPassword12!',
        'q1' => '问题一',
        'a1' => '答案一',
        'q2' => '问题二',
        'a2' => '答案二',
        'q3' => '问题三',
        'a3' => '答案三',
    ];
}

test('fresh installation creates exactly one administrator with recovery details', function (): void {
    initial_setup_test_database(function (PDO $pdo): void {
        assert_true(initial_admin_setup_available($pdo));

        $userId = register_initial_admin($pdo, initial_setup_valid_input());
        assert_true($userId > 0);
        assert_false(initial_admin_setup_available($pdo));

        $row = $pdo->query("SELECT username, password, role, parent_id, sec_q1, sec_a1, sec_q2, sec_a2, sec_q3, sec_a3 FROM users WHERE id = {$userId}")->fetch();
        assert_same('first-admin', $row['username']);
        assert_same('admin', $row['role']);
        assert_same(0, (int) $row['parent_id']);
        assert_true(password_verify('FirstAdminPassword12!', $row['password']));
        assert_same('问题一', $row['sec_q1']);
        assert_same('问题二', $row['sec_q2']);
        assert_same('问题三', $row['sec_q3']);
        assert_true(password_verify('答案一', $row['sec_a1']));
        assert_true(password_verify('答案二', $row['sec_a2']));
        assert_true(password_verify('答案三', $row['sec_a3']));

        assert_throws(
            fn () => register_initial_admin($pdo, initial_setup_valid_input('second-admin')),
            HttpException::class
        );
        assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn());
    });
});

test('existing account permanently disables browser administrator setup', function (): void {
    initial_setup_test_database(function (PDO $pdo): void {
        $pdo->exec("INSERT INTO users (username, password, role, parent_id) VALUES ('existing-owner', 'hash', 'user', 0)");

        assert_false(initial_admin_setup_available($pdo));
        assert_throws(
            fn () => register_initial_admin($pdo, initial_setup_valid_input()),
            HttpException::class
        );
        assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    });
});

test('initial administrator uses the normal account validation policy', function (): void {
    initial_setup_test_database(function (PDO $pdo): void {
        $input = initial_setup_valid_input();
        $input['password'] = 'short';
        $input['password_confirm'] = 'short';

        assert_throws(fn () => register_initial_admin($pdo, $input), HttpException::class);
        assert_true(initial_admin_setup_available($pdo));
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    });
});

test('initial administrator creation is serialized across database connections', function (): void {
    initial_setup_test_database(function (PDO $pdo): void {
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $dsn = (string) getenv('TEST_DB_DSN');
        $databaseDsn = preg_replace('/;dbname=[^;]*/', '', $dsn) . ';dbname=' . $database;
        $second = new PDO($databaseDsn, getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $second->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $pdo->beginTransaction();
        try {
            $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'registration_mode' FOR UPDATE")->fetchColumn();
            assert_throws(
                fn () => register_initial_admin($second, initial_setup_valid_input()),
                PDOException::class
            );
            assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        } finally {
            if ($second->inTransaction()) {
                $second->rollBack();
            }
            $pdo->rollBack();
        }
    });
});
