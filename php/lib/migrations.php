<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';

const INVENTORY_SCHEMA_VERSION = '202609270001_secure_self_hosted_base';

function run_migrations(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(100) PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $check = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version = ?');
    $check->execute([INVENTORY_SCHEMA_VERSION]);
    if ((int) $check->fetchColumn() > 0) {
        return;
    }

    create_core_tables($pdo);
    migrate_users_table($pdo);
    migrate_inventory_table($pdo);
    create_application_tables($pdo);
    ensure_inventory_indexes($pdo);
    seed_registration_mode($pdo);
    report_orphaned_inventory($pdo);

    $record = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
    $record->execute([INVENTORY_SCHEMA_VERSION]);
}

function create_core_tables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
        parent_id INT NOT NULL DEFAULT 0,
        perm_finance TINYINT(1) NOT NULL DEFAULT 1,
        perm_edit TINYINT(1) NOT NULL DEFAULT 1,
        perm_delete TINYINT(1) NOT NULL DEFAULT 1,
        perm_download_tpl TINYINT(1) NOT NULL DEFAULT 1,
        perm_import TINYINT(1) NOT NULL DEFAULT 1,
        perm_add TINYINT(1) NOT NULL DEFAULT 1,
        perm_export TINYINT(1) NOT NULL DEFAULT 1,
        perm_history_view INT NOT NULL DEFAULT 999,
        perm_tab_us TINYINT(1) NOT NULL DEFAULT 1,
        perm_tab_transit TINYINT(1) NOT NULL DEFAULT 1,
        perm_tab_cn TINYINT(1) NOT NULL DEFAULT 1,
        perm_tab_sold TINYINT(1) NOT NULL DEFAULT 1,
        perm_tab_parts TINYINT(1) NOT NULL DEFAULT 1,
        perm_tab_parts_sold TINYINT(1) NOT NULL DEFAULT 1,
        perm_tab_repair TINYINT(1) NOT NULL DEFAULT 1,
        perm_tab_repair_done TINYINT(1) NOT NULL DEFAULT 1,
        lock_tab_us TINYINT(1) NOT NULL DEFAULT 0,
        lock_tab_transit TINYINT(1) NOT NULL DEFAULT 0,
        lock_tab_cn TINYINT(1) NOT NULL DEFAULT 0,
        lock_tab_sold TINYINT(1) NOT NULL DEFAULT 1,
        lock_tab_parts TINYINT(1) NOT NULL DEFAULT 0,
        lock_tab_parts_sold TINYINT(1) NOT NULL DEFAULT 1,
        lock_tab_repair TINYINT(1) NOT NULL DEFAULT 0,
        lock_tab_repair_done TINYINT(1) NOT NULL DEFAULT 0,
        timeout_minutes INT NOT NULL DEFAULT 20,
        lock_password VARCHAR(255) NULL,
        sec_q1 VARCHAR(255) NULL,
        sec_a1 VARCHAR(255) NULL,
        sec_q2 VARCHAR(255) NULL,
        sec_a2 VARCHAR(255) NULL,
        sec_q3 VARCHAR(255) NULL,
        sec_a3 VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL DEFAULT 0,
        service_no VARCHAR(50) NOT NULL,
        batch_no VARCHAR(50) NULL,
        quantity INT NOT NULL DEFAULT 1,
        status ENUM('US', 'TRANSIT', 'CN_WH', 'SOLD', 'REPAIR', 'REPAIR_DONE', 'PARTS', 'PARTS_SOLD') NOT NULL DEFAULT 'US',
        status_date DATE NULL,
        status_timestamp DATETIME NULL,
        config_desc VARCHAR(255) NULL,
        cost_rmb DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        freight DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        receiver VARCHAR(100) NULL,
        remarks VARCHAR(255) NULL,
        collected_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_status_date (user_id, status, status_date),
        INDEX idx_service_no (service_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS login_blocks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('ip', 'username') NOT NULL,
        identifier VARCHAR(100) NOT NULL,
        failed_count INT NOT NULL DEFAULT 0,
        lock_until DATETIME NULL,
        UNIQUE KEY uq_login_block (type, identifier)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function migrate_users_table(PDO $pdo): void
{
    $columns = [
        'perm_finance' => "TINYINT(1) NOT NULL DEFAULT 1",
        'perm_edit' => "TINYINT(1) NOT NULL DEFAULT 1",
        'perm_delete' => "TINYINT(1) NOT NULL DEFAULT 1",
        'perm_download_tpl' => "TINYINT(1) NOT NULL DEFAULT 1",
        'perm_import' => "TINYINT(1) NOT NULL DEFAULT 1",
        'perm_add' => "TINYINT(1) NOT NULL DEFAULT 1",
        'perm_export' => "TINYINT(1) NOT NULL DEFAULT 1",
        'perm_history_view' => "INT NOT NULL DEFAULT 999",
        'perm_tab_us' => "TINYINT(1) NOT NULL DEFAULT 1",
        'perm_tab_transit' => "TINYINT(1) NOT NULL DEFAULT 1",
        'perm_tab_cn' => "TINYINT(1) NOT NULL DEFAULT 1",
        'perm_tab_sold' => "TINYINT(1) NOT NULL DEFAULT 1",
        'perm_tab_parts' => "TINYINT(1) NOT NULL DEFAULT 1",
        'perm_tab_parts_sold' => "TINYINT(1) NOT NULL DEFAULT 1",
        'perm_tab_repair' => "TINYINT(1) NOT NULL DEFAULT 1",
        'perm_tab_repair_done' => "TINYINT(1) NOT NULL DEFAULT 1",
        'lock_tab_us' => "TINYINT(1) NOT NULL DEFAULT 0",
        'lock_tab_transit' => "TINYINT(1) NOT NULL DEFAULT 0",
        'lock_tab_cn' => "TINYINT(1) NOT NULL DEFAULT 0",
        'lock_tab_sold' => "TINYINT(1) NOT NULL DEFAULT 1",
        'lock_tab_parts' => "TINYINT(1) NOT NULL DEFAULT 0",
        'lock_tab_parts_sold' => "TINYINT(1) NOT NULL DEFAULT 1",
        'lock_tab_repair' => "TINYINT(1) NOT NULL DEFAULT 0",
        'lock_tab_repair_done' => "TINYINT(1) NOT NULL DEFAULT 0",
        'timeout_minutes' => "INT NOT NULL DEFAULT 20",
        'lock_password' => "VARCHAR(255) NULL",
        'sec_q1' => "VARCHAR(255) NULL",
        'sec_a1' => "VARCHAR(255) NULL",
        'sec_q2' => "VARCHAR(255) NULL",
        'sec_a2' => "VARCHAR(255) NULL",
        'sec_q3' => "VARCHAR(255) NULL",
        'sec_a3' => "VARCHAR(255) NULL",
    ];
    add_missing_columns($pdo, 'users', $columns);
}

function migrate_inventory_table(PDO $pdo): void
{
    $columns = [
        'user_id' => "INT NOT NULL DEFAULT 0",
        'batch_no' => "VARCHAR(50) NULL",
        'quantity' => "INT NOT NULL DEFAULT 1",
        'status_date' => "DATE NULL",
        'status_timestamp' => "DATETIME NULL",
        'cost_rmb' => "DECIMAL(12,2) NOT NULL DEFAULT 0.00",
        'remarks' => "VARCHAR(255) NULL",
        'updated_at' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];
    add_missing_columns($pdo, 'inventory_items', $columns);

    $pdo->exec("ALTER TABLE inventory_items MODIFY COLUMN status ENUM('US', 'TRANSIT', 'CN_WH', 'SOLD', 'REPAIR', 'REPAIR_DONE', 'PARTS', 'PARTS_SOLD') NOT NULL DEFAULT 'US'");
}

function create_application_tables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_by INT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS registration_invites (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        code_hash CHAR(64) NOT NULL UNIQUE,
        created_by INT NOT NULL,
        expires_at DATETIME NULL,
        used_at DATETIME NULL,
        used_by INT NULL,
        revoked_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_invites_available (used_at, revoked_at, expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function add_missing_columns(PDO $pdo, string $table, array $columns): void
{
    $existing = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $existing->execute([$table]);
    $present = array_fill_keys($existing->fetchAll(PDO::FETCH_COLUMN), true);

    foreach ($columns as $name => $definition) {
        if (!isset($present[$name])) {
            $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $name, $definition));
        }
    }
}

function ensure_inventory_indexes(PDO $pdo): void
{
    $indexes = $pdo->query('SHOW INDEX FROM inventory_items')->fetchAll();
    $byName = [];
    foreach ($indexes as $index) {
        $byName[$index['Key_name']][] = $index;
    }

    foreach ($byName as $name => $parts) {
        usort($parts, static fn (array $a, array $b): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']);
        $columns = array_column($parts, 'Column_name');
        $isUnique = (int) $parts[0]['Non_unique'] === 0;
        if ($name !== 'PRIMARY' && $isUnique && $columns === ['service_no']) {
            $pdo->exec(sprintf('ALTER TABLE inventory_items DROP INDEX `%s`', str_replace('`', '``', $name)));
            unset($byName[$name]);
        }
    }

    if (!isset($byName['idx_user_status_date'])) {
        $pdo->exec('CREATE INDEX idx_user_status_date ON inventory_items (user_id, status, status_date)');
    }
    if (!isset($byName['idx_service_no'])) {
        $pdo->exec('CREATE INDEX idx_service_no ON inventory_items (service_no)');
    }
}

function seed_registration_mode(PDO $pdo): void
{
    $mode = strtolower((string) app_config('DEFAULT_REGISTRATION_MODE', 'invite'));
    if (!in_array($mode, ['closed', 'invite', 'open'], true)) {
        throw new RuntimeException('DEFAULT_REGISTRATION_MODE must be closed, invite, or open');
    }
    $statement = $pdo->prepare('INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES (?, ?)');
    $statement->execute(['registration_mode', $mode]);
}

function report_orphaned_inventory(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM inventory_items i LEFT JOIN users u ON u.id = i.user_id WHERE i.user_id <> 0 AND u.id IS NULL')->fetchColumn();
    if ($count > 0) {
        error_log("Inventory migration retained {$count} orphaned inventory row(s) for manual review");
    }
}

function bootstrap_admin(PDO $pdo, string $username, string $password): bool
{
    $username = trim($username);
    if ($username === '' || strlen($username) < 3 || strlen($username) > 50) {
        throw new RuntimeException('Administrator username must contain 3 to 50 characters');
    }
    $passwordResult = validate_password($password);
    if (!$passwordResult['valid']) {
        throw new RuntimeException(implode(' ', $passwordResult['errors']));
    }

    $pdo->beginTransaction();
    try {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM users FOR UPDATE')->fetchColumn();
        if ($count > 0) {
            $pdo->rollBack();
            return false;
        }
        $statement = $pdo->prepare("INSERT INTO users (username, password, role, parent_id) VALUES (?, ?, 'admin', 0)");
        $statement->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
        $pdo->commit();
        return true;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
