<?php
declare(strict_types=1);

$migrationPath = dirname(__DIR__, 2) . '/php/lib/migrations.php';
$authorizationPath = dirname(__DIR__, 2) . '/php/lib/authorization.php';
if (is_file($migrationPath)) {
    require_once $migrationPath;
}
if (is_file($authorizationPath)) {
    require_once $authorizationPath;
}

function tenant_test_database(callable $test): void
{
    assert_true(function_exists('load_tenant_items'), 'load_tenant_items() is missing');
    $dsn = getenv('TEST_DB_DSN');
    if (!is_string($dsn) || $dsn === '') {
        throw new TestFailure('TEST_DB_DSN is required for tenant integration tests');
    }
    $admin = new PDO($dsn, getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $name = 'inventory_tenant_' . getmypid() . '_' . bin2hex(random_bytes(4));
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

test('tenant item lookup rejects foreign and partially foreign batches', function (): void {
    tenant_test_database(function (PDO $pdo): void {
        $insert = $pdo->prepare("INSERT INTO inventory_items (user_id, service_no, quantity, status) VALUES (?, ?, 1, 'US')");
        $insert->execute([10, 'TENANT-A']);
        $ownId = (int) $pdo->lastInsertId();
        $insert->execute([20, 'TENANT-B']);
        $foreignId = (int) $pdo->lastInsertId();

        $items = load_tenant_items($pdo, [$ownId], 10);
        assert_same($ownId, (int) $items[0]['id']);
        assert_throws(fn () => load_tenant_items($pdo, [$foreignId], 10), RuntimeException::class);
        assert_throws(fn () => load_tenant_items($pdo, [$ownId, $foreignId], 10), RuntimeException::class);
    });
});

test('tenant item lookup can lock a verified owner row inside a transaction', function (): void {
    tenant_test_database(function (PDO $pdo): void {
        $pdo->exec("INSERT INTO inventory_items (user_id, service_no, quantity, status) VALUES (10, 'LOCK-ME', 1, 'PARTS')");
        $id = (int) $pdo->lastInsertId();
        $pdo->beginTransaction();
        try {
            $item = require_tenant_item($pdo, $id, 10, true);
            assert_same('PARTS', $item['status']);
        } finally {
            $pdo->rollBack();
        }
    });
});
