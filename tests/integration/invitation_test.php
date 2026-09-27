<?php
declare(strict_types=1);

$registrationPath = dirname(__DIR__, 2) . '/php/lib/registration.php';
$migrationPath = dirname(__DIR__, 2) . '/php/lib/migrations.php';
if (is_file($migrationPath)) {
    require_once $migrationPath;
}
if (is_file($registrationPath)) {
    require_once $registrationPath;
}

function invitation_test_database(callable $test): void
{
    assert_true(function_exists('create_invitation'), 'create_invitation() is missing');
    $dsn = getenv('TEST_DB_DSN');
    if (!is_string($dsn) || $dsn === '') {
        throw new TestFailure('TEST_DB_DSN is required for invitation integration tests');
    }
    $admin = new PDO($dsn, getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $name = 'inventory_invite_' . getmypid() . '_' . bin2hex(random_bytes(4));
    $admin->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    try {
        $databaseDsn = preg_replace('/;dbname=[^;]*/', '', $dsn) . ';dbname=' . $name;
        $pdo = new PDO($databaseDsn, getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        run_migrations($pdo);
        $pdo->prepare("INSERT INTO users (id, username, password, role, parent_id) VALUES (1, 'system-admin', 'hash', 'admin', 0), (2, 'normal-owner', 'hash', 'user', 0)")->execute();
        $test($pdo);
    } finally {
        $admin->exec("DROP DATABASE IF EXISTS `{$name}`");
    }
}

test('system administrator can switch all registration modes', function (): void {
    invitation_test_database(function (PDO $pdo): void {
        foreach (['closed', 'invite', 'open'] as $mode) {
            set_registration_mode($pdo, 1, $mode);
            assert_same($mode, registration_mode($pdo));
        }
        assert_throws(fn () => set_registration_mode($pdo, 2, 'open'), RuntimeException::class);
        assert_throws(fn () => set_registration_mode($pdo, 1, 'unsupported'), RuntimeException::class);
    });
});

test('invitation is stored as a hash and consumed exactly once', function (): void {
    invitation_test_database(function (PDO $pdo): void {
        $invite = create_invitation($pdo, 1, new DateTimeImmutable('+1 day'));
        assert_matches('/^INV-[A-F0-9]{32}$/', $invite['code']);
        $storedHash = $pdo->query('SELECT code_hash FROM registration_invites')->fetchColumn();
        assert_false(hash_equals($invite['code'], (string) $storedHash));

        $createdId = consume_invitation($pdo, $invite['code'], function (PDO $connection): int {
            $statement = $connection->prepare("INSERT INTO users (username, password, role, parent_id) VALUES ('invited-owner', 'hash', 'user', 0)");
            $statement->execute();
            return (int) $connection->lastInsertId();
        });
        assert_true($createdId > 2);
        assert_throws(fn () => consume_invitation($pdo, $invite['code'], fn (): int => 99), RuntimeException::class);

        $listed = list_invitations($pdo);
        assert_same('used', $listed[0]['status']);
        assert_false(array_key_exists('code', $listed[0]));
        assert_same($createdId, (int) $listed[0]['used_by']);
    });
});

test('expired revoked and invalid invitation codes are rejected', function (): void {
    invitation_test_database(function (PDO $pdo): void {
        $expired = create_invitation($pdo, 1, new DateTimeImmutable('+1 day'));
        $pdo->exec("UPDATE registration_invites SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = " . (int) $expired['id']);
        assert_throws(fn () => consume_invitation($pdo, $expired['code'], fn (): int => 10), RuntimeException::class);

        $revoked = create_invitation($pdo, 1, null);
        revoke_invitation($pdo, (int) $revoked['id']);
        assert_throws(fn () => consume_invitation($pdo, $revoked['code'], fn (): int => 11), RuntimeException::class);
        assert_throws(fn () => consume_invitation($pdo, 'INV-DOES-NOT-EXIST', fn (): int => 12), RuntimeException::class);
    });
});

test('primary account registration obeys closed invite and open modes', function (): void {
    invitation_test_database(function (PDO $pdo): void {
        assert_true(function_exists('register_primary_account'), 'register_primary_account() is missing');
        $base = [
            'password' => 'StrongFriend12!',
            'password_confirm' => 'StrongFriend12!',
            'q1' => '问题一', 'a1' => '答案一',
            'q2' => '问题二', 'a2' => '答案二',
            'q3' => '问题三', 'a3' => '答案三',
        ];

        set_registration_mode($pdo, 1, 'closed');
        assert_throws(fn () => register_primary_account($pdo, ['username' => 'closed-user'] + $base, '203.0.113.10'), RuntimeException::class);

        set_registration_mode($pdo, 1, 'invite');
        assert_throws(fn () => register_primary_account($pdo, ['username' => 'no-invite'] + $base, '203.0.113.11'), RuntimeException::class);
        $invite = create_invitation($pdo, 1, new DateTimeImmutable('+1 day'));
        $inviteId = register_primary_account($pdo, ['username' => 'invited-user', 'invite_code' => $invite['code']] + $base, '203.0.113.11');
        assert_true($inviteId > 2);

        set_registration_mode($pdo, 1, 'open');
        $openId = register_primary_account($pdo, ['username' => 'open-user'] + $base, '203.0.113.12');
        assert_true($openId > $inviteId);
        assert_same(2, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='user' AND parent_id=0 AND username IN ('invited-user','open-user')")->fetchColumn());
    });
});

test('primary registration validates lengths passwords and open-mode rate limits', function (): void {
    invitation_test_database(function (PDO $pdo): void {
        assert_true(function_exists('register_primary_account'), 'register_primary_account() is missing');
        set_registration_mode($pdo, 1, 'open');
        $base = [
            'password' => 'StrongFriend12!',
            'password_confirm' => 'StrongFriend12!',
            'q1' => '问题一', 'a1' => '答案一',
            'q2' => '问题二', 'a2' => '答案二',
            'q3' => '问题三', 'a3' => '答案三',
        ];
        assert_throws(fn () => register_primary_account($pdo, ['username' => 'ab'] + $base, '203.0.113.20'), RuntimeException::class);
        assert_throws(fn () => register_primary_account($pdo, ['username' => 'weak', 'password' => 'short', 'password_confirm' => 'short'] + $base, '203.0.113.20'), RuntimeException::class);

        for ($index = 1; $index <= 5; $index++) {
            register_primary_account($pdo, ['username' => 'rate-user-' . $index] + $base, '203.0.113.21');
        }
        assert_throws(fn () => register_primary_account($pdo, ['username' => 'rate-user-6'] + $base, '203.0.113.21'), RuntimeException::class);
    });
});
