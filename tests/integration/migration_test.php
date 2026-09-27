<?php
declare(strict_types=1);

$migrationPath = dirname(__DIR__, 2) . '/php/lib/migrations.php';
if (is_file($migrationPath)) {
    require_once $migrationPath;
}

function migration_test_admin_pdo(): PDO
{
    $dsn = getenv('TEST_DB_DSN');
    if (!is_string($dsn) || $dsn === '') {
        throw new TestFailure('TEST_DB_DSN is required for migration integration tests');
    }
    return new PDO($dsn, getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function migration_test_database(callable $test): void
{
    assert_true(function_exists('run_migrations'), 'run_migrations() is missing');
    $admin = migration_test_admin_pdo();
    $name = 'inventory_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
    $admin->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    try {
        $dsn = (string) getenv('TEST_DB_DSN');
        $databaseDsn = preg_replace('/;dbname=[^;]*/', '', $dsn) . ';dbname=' . $name;
        $pdo = new PDO($databaseDsn, getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $test($pdo);
    } finally {
        $admin->exec("DROP DATABASE IF EXISTS `{$name}`");
    }
}

test('fresh database migration is complete and idempotent', function (): void {
    migration_test_database(function (PDO $pdo): void {
        run_migrations($pdo);
        run_migrations($pdo);

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['users', 'inventory_items', 'login_blocks', 'schema_migrations', 'app_settings', 'registration_invites'] as $table) {
            assert_true(in_array($table, $tables, true), "Missing table {$table}");
        }
        assert_same('invite', $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='registration_mode'")->fetchColumn());
        assert_same('pending', $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='initial_admin_setup'")->fetchColumn());
        assert_same(3, (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
    });
});

test('legacy migration preserves users inventory values and orphan ownership', function (): void {
    migration_test_database(function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role ENUM('admin','user') DEFAULT 'user',
            parent_id INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE inventory_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT 0,
            service_no VARCHAR(50) NOT NULL,
            status ENUM('US','TRANSIT','CN_WH','SOLD') DEFAULT 'US',
            config_desc VARCHAR(255),
            cost_us DECIMAL(10,2) DEFAULT 0.00,
            freight DECIMAL(10,2) DEFAULT 0.00,
            receiver VARCHAR(100),
            collected_amount DECIMAL(10,2) DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY service_no (service_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("INSERT INTO users (id, username, password, role, parent_id) VALUES (7, 'legacy-owner', 'hash', 'admin', 0)");
        $pdo->exec("INSERT INTO inventory_items (user_id, service_no, status, config_desc, cost_us, freight, receiver, collected_amount) VALUES
            (7, 'SN-KEEP', 'CN_WH', 'original', 123.45, 6.78, 'customer', 200.00),
            (999, 'SN-ORPHAN', 'US', 'orphan', 10.00, 1.00, '', 0.00)");

        run_migrations($pdo);
        run_migrations($pdo);

        assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE id=7 AND username='legacy-owner'")->fetchColumn());
        assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM inventory_items')->fetchColumn());
        $kept = $pdo->query("SELECT user_id, service_no, config_desc, cost_us, cost_rmb, freight, receiver, collected_amount FROM inventory_items WHERE service_no='SN-KEEP'")->fetch();
        assert_same(7, (int) $kept['user_id']);
        assert_same('original', $kept['config_desc']);
        assert_same('123.45', $kept['cost_us']);
        assert_same('123.45', $kept['cost_rmb']);
        assert_same('6.78', $kept['freight']);
        assert_same('customer', $kept['receiver']);
        assert_same('200.00', $kept['collected_amount']);
        assert_same(999, (int) $pdo->query("SELECT user_id FROM inventory_items WHERE service_no='SN-ORPHAN'")->fetchColumn());
        assert_same('complete', $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='initial_admin_setup'")->fetchColumn());

        $uniqueServiceIndexes = $pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='inventory_items' AND column_name='service_no' AND non_unique=0")->fetchColumn();
        assert_same(0, (int) $uniqueServiceIndexes);
        $lengths = $pdo->query("SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_items' AND COLUMN_NAME IN ('service_no', 'batch_no')")->fetchAll(PDO::FETCH_KEY_PAIR);
        assert_same(100, (int) $lengths['service_no']);
        assert_same(100, (int) $lengths['batch_no']);
    });
});

test('legacy database with an empty users table does not reopen administrator setup', function (): void {
    migration_test_database(function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role ENUM('admin','user') DEFAULT 'user',
            parent_id INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        run_migrations($pdo);

        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        assert_same('complete', $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='initial_admin_setup'")->fetchColumn());
    });
});

test('interrupted fresh migration retains pending administrator setup on retry', function (): void {
    migration_test_database(function (PDO $pdo): void {
        create_application_tables($pdo);
        seed_initial_admin_setup_state($pdo, true);
        assert_same('pending', $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='initial_admin_setup'")->fetchColumn());

        create_core_tables($pdo);
        run_migrations($pdo);

        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        assert_same('pending', $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='initial_admin_setup'")->fetchColumn());
        assert_same(3, (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
    });
});

test('administrator bootstrap runs once and rejects weak passwords', function (): void {
    migration_test_database(function (PDO $pdo): void {
        run_migrations($pdo);
        assert_true(function_exists('bootstrap_admin'), 'bootstrap_admin() is missing');
        assert_throws(fn () => bootstrap_admin($pdo, 'owner', 'short'), RuntimeException::class);
        assert_true(bootstrap_admin($pdo, 'owner', 'StrongOwner12!'));
        assert_false(bootstrap_admin($pdo, 'second', 'AnotherOwner12!'));
        $row = $pdo->query("SELECT username, role, parent_id, password FROM users WHERE username='owner'")->fetch();
        assert_same('admin', $row['role']);
        assert_same(0, (int) $row['parent_id']);
        assert_true(password_verify('StrongOwner12!', $row['password']));
    });
});
