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

function admin_settings_test_database(callable $test): void
{
    assert_true(function_exists('admin_registration_snapshot'), 'admin_registration_snapshot() is missing');
    $dsn = getenv('TEST_DB_DSN');
    if (!is_string($dsn) || $dsn === '') {
        throw new TestFailure('TEST_DB_DSN is required for admin settings integration tests');
    }
    $admin = new PDO($dsn, getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $name = 'inventory_admin_' . getmypid() . '_' . bin2hex(random_bytes(4));
    $admin->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    try {
        $databaseDsn = preg_replace('/;dbname=[^;]*/', '', $dsn) . ';dbname=' . $name;
        $pdo = new PDO($databaseDsn, getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        run_migrations($pdo);
        $insert = $pdo->prepare("INSERT INTO users (username, password, role, parent_id) VALUES (?, ?, ?, 0)");
        $insert->execute(['sysadmin', password_hash('StrongAdmin12!', PASSWORD_DEFAULT), 'admin']);
        $adminId = (int) $pdo->lastInsertId();
        $insert->execute(['ordinary', password_hash('StrongUser12!', PASSWORD_DEFAULT), 'user']);
        $userId = (int) $pdo->lastInsertId();
        $test($pdo, $adminId, $userId);
    } finally {
        $admin->exec("DROP DATABASE IF EXISTS `{$name}`");
    }
}

test('only the system administrator can view registration administration', function (): void {
    admin_settings_test_database(function (PDO $pdo, int $adminId, int $userId): void {
        $snapshot = admin_registration_snapshot($pdo, $adminId);
        assert_same('invite', $snapshot['mode']);
        assert_same([], $snapshot['invitations']);
        assert_throws(fn () => admin_registration_snapshot($pdo, $userId), RuntimeException::class);
    });
});

test('only the system administrator can revoke invitation codes', function (): void {
    admin_settings_test_database(function (PDO $pdo, int $adminId, int $userId): void {
        $invite = create_invitation($pdo, $adminId, new DateTimeImmutable('+1 day'));
        assert_throws(fn () => revoke_invitation_as_admin($pdo, $userId, $invite['id']), RuntimeException::class);
        revoke_invitation_as_admin($pdo, $adminId, $invite['id']);
        assert_same('revoked', admin_registration_snapshot($pdo, $adminId)['invitations'][0]['status']);
    });
});
