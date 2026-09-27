<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';

function registration_mode_is_valid(string $mode): bool
{
    return in_array($mode, ['closed', 'invite', 'open'], true);
}

function registration_is_available(string $mode): bool
{
    return in_array($mode, ['invite', 'open'], true);
}

function initial_admin_setup_available(PDO $pdo): bool
{
    return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
}

function register_initial_admin(PDO $pdo, array $input): int
{
    if ($pdo->inTransaction()) {
        throw new LogicException('管理员初始化必须在独立事务中执行。');
    }

    $data = validate_primary_registration($input);
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'registration_mode' FOR UPDATE");
        $lock->execute();
        if ($lock->fetchColumn() === false) {
            throw new RuntimeException('注册配置不存在，请先运行数据库迁移。');
        }
        if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
            throw new HttpException('系统已经完成初始化，请直接登录。', 409);
        }

        $statement = $pdo->prepare("INSERT INTO users (
            username, password, role, parent_id, perm_finance, perm_edit,
            sec_q1, sec_a1, sec_q2, sec_a2, sec_q3, sec_a3
        ) VALUES (?, ?, 'admin', 0, 1, 1, ?, ?, ?, ?, ?, ?)");
        $statement->execute([
            $data['username'],
            $data['password_hash'],
            $data['questions'][0],
            $data['answer_hashes'][0],
            $data['questions'][1],
            $data['answer_hashes'][1],
            $data['questions'][2],
            $data['answer_hashes'][2],
        ]);
        $userId = (int) $pdo->lastInsertId();
        $pdo->commit();
        return $userId;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function registration_code_hash(string $plainCode): string
{
    return hash('sha256', strtolower(trim($plainCode)));
}

function generate_invitation_code(): string
{
    return 'INV-' . strtoupper(bin2hex(random_bytes(16)));
}

function registration_mode(PDO $pdo): string
{
    $statement = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'registration_mode'");
    $statement->execute();
    $mode = strtolower((string) ($statement->fetchColumn() ?: 'invite'));
    return registration_mode_is_valid($mode) ? $mode : 'invite';
}

function require_system_admin(PDO $pdo, int $actorId): void
{
    $statement = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND role = 'admin' AND parent_id = 0");
    $statement->execute([$actorId]);
    if ((int) $statement->fetchColumn() !== 1) {
        throw new HttpException('只有系统管理员可以执行此操作。', 403);
    }
}

function set_registration_mode(PDO $pdo, int $actorId, string $mode): void
{
    $mode = strtolower(trim($mode));
    if (!registration_mode_is_valid($mode)) {
        throw new HttpException('不支持的注册模式。', 400);
    }
    require_system_admin($pdo, $actorId);

    $statement = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value, updated_by)
        VALUES ('registration_mode', ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)");
    $statement->execute([$mode, $actorId]);
}

function create_invitation(PDO $pdo, int $actorId, ?DateTimeImmutable $expiresAt): array
{
    require_system_admin($pdo, $actorId);
    if ($expiresAt !== null && $expiresAt <= new DateTimeImmutable('now')) {
        throw new HttpException('邀请码有效期必须晚于当前时间。', 400);
    }

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $code = generate_invitation_code();
        $hash = registration_code_hash($code);
        try {
            $statement = $pdo->prepare('INSERT INTO registration_invites (code_hash, created_by, expires_at) VALUES (?, ?, ?)');
            $statement->execute([$hash, $actorId, $expiresAt?->format('Y-m-d H:i:s')]);
            return [
                'id' => (int) $pdo->lastInsertId(),
                'code' => $code,
                'expires_at' => $expiresAt?->format(DATE_ATOM),
            ];
        } catch (PDOException $error) {
            if ((string) $error->getCode() !== '23000' || $attempt === 2) {
                throw $error;
            }
        }
    }

    throw new RuntimeException('无法生成邀请码，请重试。');
}

function consume_invitation(PDO $pdo, string $plainCode, callable $createAccount): int
{
    if ($pdo->inTransaction()) {
        throw new RuntimeException('邀请码必须在独立事务中使用。');
    }

    $hash = registration_code_hash($plainCode);
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT id, expires_at, used_at, revoked_at FROM registration_invites WHERE code_hash = ? FOR UPDATE');
        $statement->execute([$hash]);
        $invite = $statement->fetch();

        if (!$invite || $invite['used_at'] !== null || $invite['revoked_at'] !== null) {
            throw new HttpException('邀请码无效或已使用。', 400);
        }
        if ($invite['expires_at'] !== null && new DateTimeImmutable($invite['expires_at']) <= new DateTimeImmutable('now')) {
            throw new HttpException('邀请码已过期。', 400);
        }

        $userId = (int) $createAccount($pdo);
        if ($userId < 1) {
            throw new RuntimeException('账号创建失败。');
        }

        $markUsed = $pdo->prepare('UPDATE registration_invites SET used_at = NOW(), used_by = ? WHERE id = ? AND used_at IS NULL AND revoked_at IS NULL');
        $markUsed->execute([$userId, $invite['id']]);
        if ($markUsed->rowCount() !== 1) {
            throw new HttpException('邀请码已被使用。', 409);
        }

        $pdo->commit();
        return $userId;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function list_invitations(PDO $pdo): array
{
    $rows = $pdo->query('SELECT id, created_by, expires_at, used_at, used_by, revoked_at, created_at FROM registration_invites ORDER BY id DESC')->fetchAll();
    $now = new DateTimeImmutable('now');

    foreach ($rows as &$row) {
        if ($row['used_at'] !== null) {
            $row['status'] = 'used';
        } elseif ($row['revoked_at'] !== null) {
            $row['status'] = 'revoked';
        } elseif ($row['expires_at'] !== null && new DateTimeImmutable($row['expires_at']) <= $now) {
            $row['status'] = 'expired';
        } else {
            $row['status'] = 'available';
        }
    }
    unset($row);

    return $rows;
}

function admin_registration_snapshot(PDO $pdo, int $actorId): array
{
    require_system_admin($pdo, $actorId);
    return [
        'mode' => registration_mode($pdo),
        'invitations' => list_invitations($pdo),
    ];
}

function revoke_invitation(PDO $pdo, int $inviteId): void
{
    if ($inviteId < 1) {
        throw new HttpException('邀请码编号无效。', 400);
    }
    $statement = $pdo->prepare('UPDATE registration_invites SET revoked_at = NOW() WHERE id = ? AND used_at IS NULL AND revoked_at IS NULL');
    $statement->execute([$inviteId]);
    if ($statement->rowCount() !== 1) {
        throw new HttpException('邀请码不存在、已使用或已撤销。', 400);
    }
}

function revoke_invitation_as_admin(PDO $pdo, int $actorId, int $inviteId): void
{
    require_system_admin($pdo, $actorId);
    revoke_invitation($pdo, $inviteId);
}

function register_primary_account(PDO $pdo, array $input, string $ip): int
{
    $mode = registration_mode($pdo);
    if ($mode === 'closed') {
        throw new HttpException('系统当前已关闭新账号注册。', 403);
    }

    $data = validate_primary_registration($input);
    $createAccount = static function (PDO $connection) use ($data): int {
        enforce_primary_account_limit($connection);
        return insert_primary_account($connection, $data);
    };

    if ($mode === 'invite') {
        $code = trim((string) ($input['invite_code'] ?? ''));
        if ($code === '') {
            throw new HttpException('请输入有效的邀请码。', 400);
        }
        return consume_invitation($pdo, $code, $createAccount);
    }

    $pdo->beginTransaction();
    try {
        enforce_open_registration_rate_limit($pdo, $ip);
        $userId = $createAccount($pdo);
        record_open_registration($pdo, $ip);
        $pdo->commit();
        return $userId;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function validate_primary_registration(array $input): array
{
    $username = trim((string) ($input['username'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $confirmation = (string) ($input['password_confirm'] ?? '');

    if (strlen($username) < 3 || strlen($username) > 50) {
        throw new HttpException('用户名长度必须为 3 至 50 个字符。', 400);
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $username) === 1) {
        throw new HttpException('用户名包含不允许的字符。', 400);
    }
    if (!hash_equals($password, $confirmation)) {
        throw new HttpException('两次输入的密码不一致。', 400);
    }
    $passwordResult = validate_password($password);
    if (!$passwordResult['valid']) {
        throw new HttpException(implode(' ', $passwordResult['errors']), 400);
    }

    $questions = [];
    $answers = [];
    for ($index = 1; $index <= 3; $index++) {
        $question = trim((string) ($input['q' . $index] ?? ''));
        $answer = trim((string) ($input['a' . $index] ?? ''));
        if ($question === '' || strlen($question) > 255 || $answer === '' || strlen($answer) > 255) {
            throw new HttpException('请完整填写三个密保问题和答案。', 400);
        }
        $questions[] = $question;
        $answers[] = $answer;
    }
    if (count(array_unique($questions)) !== 3) {
        throw new HttpException('三个密保问题不能重复。', 400);
    }

    return [
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'questions' => $questions,
        'answer_hashes' => array_map(static fn (string $answer): string => password_hash($answer, PASSWORD_DEFAULT), $answers),
    ];
}

function enforce_primary_account_limit(PDO $pdo): void
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('账号数量限制必须在事务中检查。');
    }
    $lock = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'registration_mode' FOR UPDATE");
    $lock->execute();
    if ($lock->fetchColumn() === false) {
        throw new RuntimeException('注册配置不存在，请先运行数据库迁移。');
    }
    $maximum = max(1, min(10000, (int) app_config('MAX_PRIMARY_ACCOUNTS', 50)));
    $count = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE parent_id = 0')->fetchColumn();
    if ($count >= $maximum) {
        throw new HttpException('独立主账号数量已达到服务器设置的上限。', 403);
    }
}

function insert_primary_account(PDO $pdo, array $data): int
{
    try {
        $statement = $pdo->prepare("INSERT INTO users (
            username, password, role, parent_id, perm_finance, perm_edit,
            sec_q1, sec_a1, sec_q2, sec_a2, sec_q3, sec_a3
        ) VALUES (?, ?, 'user', 0, 1, 1, ?, ?, ?, ?, ?, ?)");
        $statement->execute([
            $data['username'],
            $data['password_hash'],
            $data['questions'][0],
            $data['answer_hashes'][0],
            $data['questions'][1],
            $data['answer_hashes'][1],
            $data['questions'][2],
            $data['answer_hashes'][2],
        ]);
    } catch (PDOException $error) {
        if ((string) $error->getCode() === '23000') {
            throw new HttpException('该用户名已被使用。', 409);
        }
        throw $error;
    }

    return (int) $pdo->lastInsertId();
}

function registration_rate_identifier(string $ip): string
{
    $validated = filter_var($ip, FILTER_VALIDATE_IP);
    return 'registration:' . (is_string($validated) ? $validated : '0.0.0.0');
}

function enforce_open_registration_rate_limit(PDO $pdo, string $ip): void
{
    $identifier = registration_rate_identifier($ip);
    $statement = $pdo->prepare("SELECT failed_count, lock_until FROM login_blocks WHERE type = 'ip' AND identifier = ? FOR UPDATE");
    $statement->execute([$identifier]);
    $row = $statement->fetch();
    if ($row && $row['lock_until'] !== null && new DateTimeImmutable($row['lock_until']) > new DateTimeImmutable('now')) {
        throw new HttpException('当前网络注册次数过多，请稍后再试。', 429);
    }
}

function record_open_registration(PDO $pdo, string $ip): void
{
    $identifier = registration_rate_identifier($ip);
    $statement = $pdo->prepare("SELECT failed_count, lock_until FROM login_blocks WHERE type = 'ip' AND identifier = ? FOR UPDATE");
    $statement->execute([$identifier]);
    $row = $statement->fetch();

    if (!$row || ($row['lock_until'] !== null && new DateTimeImmutable($row['lock_until']) <= new DateTimeImmutable('now'))) {
        $upsert = $pdo->prepare("INSERT INTO login_blocks (type, identifier, failed_count, lock_until)
            VALUES ('ip', ?, 1, NULL)
            ON DUPLICATE KEY UPDATE failed_count = 1, lock_until = NULL");
        $upsert->execute([$identifier]);
        return;
    }

    $count = (int) $row['failed_count'] + 1;
    $lockUntil = $count >= 5 ? (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s') : null;
    $update = $pdo->prepare("UPDATE login_blocks SET failed_count = ?, lock_until = ? WHERE type = 'ip' AND identifier = ?");
    $update->execute([$count, $lockUntil, $identifier]);
}
