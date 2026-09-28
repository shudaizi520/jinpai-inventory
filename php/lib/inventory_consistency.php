<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';

/** @return array<int,int> */
function parse_version_map(mixed $value, array $ids): array
{
    if (!is_string($value) || trim($value) === '') {
        throw new HttpException('缺少记录版本，请刷新后重试。', 400);
    }
    try {
        $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    } catch (JsonException) {
        throw new HttpException('记录版本格式无效，请刷新后重试。', 400);
    }
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new HttpException('记录版本格式无效，请刷新后重试。', 400);
    }

    preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"\s*:/', $value, $keyMatches);
    if (count($keyMatches[1] ?? []) !== count(array_unique($keyMatches[1] ?? []))) {
        throw new HttpException('记录版本包含重复编号。', 400);
    }

    $expectedIds = array_values(array_unique(array_map('intval', $ids)));
    sort($expectedIds);
    $versions = [];
    foreach ($decoded as $rawId => $rawVersion) {
        $idText = (string) $rawId;
        $versionText = is_int($rawVersion) ? (string) $rawVersion : '';
        if (preg_match('/^[1-9][0-9]*$/', $idText) !== 1
            || preg_match('/^[1-9][0-9]*$/', $versionText) !== 1) {
            throw new HttpException('记录版本格式无效，请刷新后重试。', 400);
        }
        $id = filter_var($idText, FILTER_VALIDATE_INT);
        $version = filter_var($versionText, FILTER_VALIDATE_INT);
        if ($id === false || $version === false) {
            throw new HttpException('记录版本超出允许范围。', 400);
        }
        $versions[(int) $id] = (int) $version;
    }
    $actualIds = array_keys($versions);
    sort($actualIds);
    if ($actualIds !== $expectedIds) {
        throw new HttpException('记录版本与所选记录不一致，请刷新后重试。', 400);
    }
    return $versions;
}

function require_expected_version(array $item, int $expected): void
{
    if ($expected < 1 || (int) ($item['row_version'] ?? 0) !== $expected) {
        throw new HttpException('记录已被其他成员修改，请刷新后重试。', 409);
    }
}

function tenant_inventory_revision(PDO $pdo, int $tenantId): int
{
    if ($tenantId < 1) throw new InvalidArgumentException('租户编号无效。');
    $pdo->prepare('INSERT IGNORE INTO tenant_inventory_state (tenant_id, revision) VALUES (?, 0)')
        ->execute([$tenantId]);
    $statement = $pdo->prepare('SELECT revision FROM tenant_inventory_state WHERE tenant_id = ?');
    $statement->execute([$tenantId]);
    return (int) $statement->fetchColumn();
}

function bump_tenant_inventory_revision(PDO $pdo, int $tenantId): int
{
    if (!$pdo->inTransaction()) throw new LogicException('库存版本只能在事务中更新。');
    $statement = $pdo->prepare('INSERT INTO tenant_inventory_state (tenant_id, revision) VALUES (?, 1)
        ON DUPLICATE KEY UPDATE revision = revision + 1');
    $statement->execute([$tenantId]);
    $read = $pdo->prepare('SELECT revision FROM tenant_inventory_state WHERE tenant_id = ?');
    $read->execute([$tenantId]);
    return (int) $read->fetchColumn();
}

function with_tenant_inventory_mutation(PDO $pdo, int $tenantId, callable $operation): mixed
{
    if ($tenantId < 1) throw new InvalidArgumentException('租户编号无效。');
    if ($pdo->inTransaction()) throw new LogicException('库存写入不能嵌套事务。');
    $lockName = 'inventory_tenant_' . $tenantId;
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $lock->execute([$lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new HttpException('系统繁忙，其他成员正在更新库存，请稍后重试。', 409);
    }
    try {
        $pdo->beginTransaction();
        $result = $operation();
        bump_tenant_inventory_revision($pdo, $tenantId);
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    } finally {
        try {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        } catch (Throwable $releaseError) {
            safe_log($releaseError);
        }
    }
}
