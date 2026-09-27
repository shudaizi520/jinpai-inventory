<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';

const API_READ_ACTIONS = [
    'heartbeat',
    'check_update',
    'list',
    'list_sub_accounts',
    'get_sec_questions',
    'get_registration_settings',
];

const API_KNOWN_ACTIONS = [
    'heartbeat',
    'check_update',
    'list',
    'batch_update_status',
    'batch_edit',
    'batch_delete',
    'dispatch_part',
    'save',
    'update_status',
    'delete',
    'import',
    'verify_lock',
    'relock',
    'get_sec_questions',
    'reset_lock_with_sec',
    'reset_sub_lock',
    'update_sec_questions',
    'change_my_password',
    'set_lock',
    'set_timeout',
    'update_master_tabs',
    'delete_my_account',
    'list_sub_accounts',
    'save_sub_account',
    'verify_login',
    'delete_sub_account',
    'get_registration_settings',
    'set_registration_mode',
    'create_invitation',
    'revoke_invitation',
];

function enforce_api_action_method(string $action, string $method): void
{
    if ($action === '' || !in_array($action, API_KNOWN_ACTIONS, true)) {
        throw new HttpException('未知操作。', 400);
    }

    $method = strtoupper($method);
    if (in_array($action, API_READ_ACTIONS, true)) {
        if (!in_array($method, ['GET', 'POST'], true)) {
            throw new HttpException('请求方法不受支持。', 405);
        }
        return;
    }

    if ($method !== 'POST') {
        throw new HttpException('此操作只接受 POST 请求。', 405);
    }
}

function establish_authenticated_session(array $user, ?callable $regenerate = null): void
{
    $_SESSION = [];
    $regenerate ??= static fn (bool $deleteOld): bool => session_regenerate_id($deleteOld);
    if (!$regenerate(true)) {
        throw new RuntimeException('无法建立安全会话。');
    }

    $_SESSION['is_logged_in'] = true;
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['role'] = (string) $user['role'];
    $_SESSION['parent_id'] = (int) ($user['parent_id'] ?? 0);
    $_SESSION['last_active_time'] = time();
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}

function perform_logout(bool $destroySession = true): void
{
    $_SESSION = [];

    if (!$destroySession || session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

/**
 * @return array{valid: bool, upgrade_hash: ?string}
 */
function verify_stored_secret(string $input, string $stored): array
{
    $info = password_get_info($stored);
    $isPasswordHash = ($info['algo'] ?? null) !== null;

    if ($isPasswordHash) {
        if (!password_verify($input, $stored)) {
            return ['valid' => false, 'upgrade_hash' => null];
        }
        return [
            'valid' => true,
            'upgrade_hash' => password_needs_rehash($stored, PASSWORD_DEFAULT)
                ? password_hash($input, PASSWORD_DEFAULT)
                : null,
        ];
    }

    if ($stored === '' || !hash_equals($stored, $input)) {
        return ['valid' => false, 'upgrade_hash' => null];
    }

    return ['valid' => true, 'upgrade_hash' => password_hash($input, PASSWORD_DEFAULT)];
}

function auth_lock_until(PDO $pdo, string $type, string $identifier): ?DateTimeImmutable
{
    assert_auth_rate_key($type, $identifier);
    $statement = $pdo->prepare('SELECT lock_until FROM login_blocks WHERE type = ? AND identifier = ?');
    $statement->execute([$type, $identifier]);
    $lockUntil = $statement->fetchColumn();

    if (!is_string($lockUntil) || $lockUntil === '') {
        return null;
    }

    $lock = new DateTimeImmutable($lockUntil);
    return $lock > new DateTimeImmutable('now') ? $lock : null;
}

function record_auth_failure(
    PDO $pdo,
    string $type,
    string $identifier,
    int $threshold = 5,
    int $lockMinutes = 15
): int {
    assert_auth_rate_key($type, $identifier);
    if ($threshold < 1 || $lockMinutes < 1) {
        throw new InvalidArgumentException('认证限制参数无效。');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $statement = $pdo->prepare(
            'SELECT failed_count, lock_until FROM login_blocks WHERE type = ? AND identifier = ? FOR UPDATE'
        );
        $statement->execute([$type, $identifier]);
        $row = $statement->fetch();

        $now = new DateTimeImmutable('now');
        $existingLock = $row && is_string($row['lock_until']) && $row['lock_until'] !== ''
            ? new DateTimeImmutable($row['lock_until'])
            : null;
        $count = (!$row || ($existingLock !== null && $existingLock <= $now))
            ? 1
            : (int) $row['failed_count'] + 1;
        $lockUntil = $count >= $threshold
            ? $now->modify("+{$lockMinutes} minutes")->format('Y-m-d H:i:s')
            : null;

        $upsert = $pdo->prepare(
            'INSERT INTO login_blocks (type, identifier, failed_count, lock_until) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE failed_count = VALUES(failed_count), lock_until = VALUES(lock_until)'
        );
        $upsert->execute([$type, $identifier, $count, $lockUntil]);

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $count;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function clear_auth_failures(PDO $pdo, string $type, string $identifier): void
{
    assert_auth_rate_key($type, $identifier);
    $statement = $pdo->prepare('DELETE FROM login_blocks WHERE type = ? AND identifier = ?');
    $statement->execute([$type, $identifier]);
}

function assert_auth_rate_key(string $type, string $identifier): void
{
    if (!in_array($type, ['ip', 'username'], true)) {
        throw new InvalidArgumentException('认证限制类型无效。');
    }
    if ($identifier === '' || strlen($identifier) > 100) {
        throw new InvalidArgumentException('认证限制标识无效。');
    }
}
