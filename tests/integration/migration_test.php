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
        foreach (['users', 'inventory_items', 'login_blocks', 'schema_migrations', 'app_settings', 'registration_invites', 'tenant_inventory_state', 'audit_events'] as $table) {
            assert_true(in_array($table, $tables, true), "Missing table {$table}");
        }
        $columns = $pdo->query("SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'users' AND COLUMN_NAME = 'session_version')
                OR (TABLE_NAME = 'inventory_items' AND COLUMN_NAME = 'row_version'))")->fetchAll(PDO::FETCH_COLUMN);
        assert_true(in_array('users.session_version', $columns, true));
        assert_true(in_array('inventory_items.row_version', $columns, true));

        $constraints = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() ORDER BY CONSTRAINT_NAME")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['fk_audit_tenant', 'fk_inventory_owner', 'fk_tenant_state_owner'] as $constraint) {
            assert_true(in_array($constraint, $constraints, true), "Missing constraint {$constraint}");
        }

        $triggers = $pdo->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['trg_inventory_owner_insert', 'trg_inventory_owner_update', 'trg_users_parent_insert', 'trg_users_parent_update', 'trg_users_primary_delete'] as $trigger) {
            assert_true(in_array($trigger, $triggers, true), "Missing trigger {$trigger}");
        }
        assert_same('invite', $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='registration_mode'")->fetchColumn());
        assert_same('pending', $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='initial_admin_setup'")->fetchColumn());
        assert_same(4, (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
    });
});

test('valid legacy ownership upgrades without changing account or inventory values', function (): void {
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
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("INSERT INTO users (id, username, password, role, parent_id) VALUES
            (7, 'legacy-owner', 'owner-hash', 'admin', 0),
            (8, 'legacy-worker', 'worker-hash', 'user', 7)");
        $pdo->exec("INSERT INTO inventory_items (user_id, service_no, status, config_desc, cost_us, freight, receiver, collected_amount)
            VALUES (7, 'SN-KEEP', 'CN_WH', 'original', 123.45, 6.78, 'customer', 200.00)");

        run_migrations($pdo);
        run_migrations($pdo);

        assert_same('owner-hash', $pdo->query("SELECT password FROM users WHERE id=7")->fetchColumn());
        assert_same(7, (int) $pdo->query("SELECT parent_id FROM users WHERE id=8")->fetchColumn());
        assert_same(1, (int) $pdo->query("SELECT session_version FROM users WHERE id=8")->fetchColumn());
        $item = $pdo->query("SELECT user_id, service_no, config_desc, cost_rmb, freight, receiver, collected_amount, row_version
            FROM inventory_items WHERE service_no='SN-KEEP'")->fetch();
        assert_same(7, (int) $item['user_id']);
        assert_same('SN-KEEP', $item['service_no']);
        assert_same('original', $item['config_desc']);
        assert_same('123.45', $item['cost_rmb']);
        assert_same('6.78', $item['freight']);
        assert_same('customer', $item['receiver']);
        assert_same('200.00', $item['collected_amount']);
        assert_same(1, (int) $item['row_version']);
        assert_same(0, (int) $pdo->query('SELECT revision FROM tenant_inventory_state WHERE tenant_id=7')->fetchColumn());
    });
});

test('legacy migration rejects orphan inventory without deleting it', function (): void {
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

        assert_throws(fn () => run_migrations($pdo), RuntimeException::class);

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
        $uniqueServiceIndexes = $pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='inventory_items' AND column_name='service_no' AND non_unique=0")->fetchColumn();
        assert_same(0, (int) $uniqueServiceIndexes);
        $lengths = $pdo->query("SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_items' AND COLUMN_NAME IN ('service_no', 'batch_no')")->fetchAll(PDO::FETCH_KEY_PAIR);
        assert_same(100, (int) $lengths['service_no']);
        assert_same(100, (int) $lengths['batch_no']);
    });
});

test('legacy migration rejects employees whose primary account is missing', function (): void {
    migration_test_database(function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role ENUM('admin','user') DEFAULT 'user',
            parent_id INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("INSERT INTO users (id, username, password, role, parent_id)
            VALUES (8, 'orphan-worker', 'hash', 'user', 777)");

        assert_throws(fn () => run_migrations($pdo), RuntimeException::class);
        assert_same(777, (int) $pdo->query('SELECT parent_id FROM users WHERE id=8')->fetchColumn());
    });
});

test('legacy migration rejects nested employee ownership', function (): void {
    migration_test_database(function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role ENUM('admin','user') DEFAULT 'user',
            parent_id INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("INSERT INTO users (id, username, password, role, parent_id) VALUES
            (7, 'owner', 'hash', 'user', 0),
            (8, 'worker', 'hash', 'user', 7),
            (9, 'nested-worker', 'hash', 'user', 8)");

        assert_throws(fn () => run_migrations($pdo), RuntimeException::class);
        assert_same(8, (int) $pdo->query('SELECT parent_id FROM users WHERE id=9')->fetchColumn());
    });
});

test('legacy migration rejects inventory owned by an employee', function (): void {
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
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("INSERT INTO users (id, username, password, role, parent_id) VALUES
            (7, 'owner', 'hash', 'user', 0),
            (8, 'worker', 'hash', 'user', 7)");
        $pdo->exec("INSERT INTO inventory_items (user_id, service_no, status) VALUES (8, 'EMPLOYEE-OWNED', 'US')");

        assert_throws(fn () => run_migrations($pdo), RuntimeException::class);
        assert_same(8, (int) $pdo->query("SELECT user_id FROM inventory_items WHERE service_no='EMPLOYEE-OWNED'")->fetchColumn());
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
        assert_same(4, (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
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
