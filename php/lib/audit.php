<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';

const INVENTORY_AUDIT_FIELDS = [
    'service_no', 'batch_no', 'quantity', 'status', 'status_date', 'status_timestamp',
    'config_desc', 'cost_rmb', 'freight', 'receiver', 'remarks', 'collected_amount',
];

function inventory_audit_snapshot(array $item): array
{
    $snapshot = [];
    foreach (INVENTORY_AUDIT_FIELDS as $field) {
        $snapshot[$field] = $item[$field] ?? null;
    }
    $snapshot['quantity'] = (int) $snapshot['quantity'];
    return $snapshot;
}

function account_audit_snapshot(array $user): array
{
    $snapshot = [];
    foreach ($user as $field => $value) {
        if ($field === 'username' || $field === 'role' || str_starts_with((string) $field, 'perm_')) {
            $snapshot[(string) $field] = str_starts_with((string) $field, 'perm_') ? (int) $value : (string) $value;
        }
    }
    return $snapshot;
}

function audit_entity_snapshot(string $entityType, ?array $value): ?array
{
    if ($value === null) return null;
    return match ($entityType) {
        'inventory' => inventory_audit_snapshot($value),
        'account' => account_audit_snapshot($value),
        default => throw new InvalidArgumentException('审计对象类型无效。'),
    };
}

function record_audit_event(
    PDO $pdo,
    int $tenantId,
    array $actor,
    string $actionType,
    string $entityType,
    ?int $entityId,
    ?array $before = null,
    ?array $after = null,
    ?int $sourceEventId = null
): int {
    if (!$pdo->inTransaction()) throw new LogicException('审计记录必须与业务写入使用同一事务。');
    if ($tenantId < 1 || (int) ($actor['id'] ?? 0) < 1) throw new InvalidArgumentException('审计归属信息无效。');
    $beforeSnapshot = audit_entity_snapshot($entityType, $before);
    $afterSnapshot = audit_entity_snapshot($entityType, $after);
    $serviceNo = $afterSnapshot['service_no'] ?? $beforeSnapshot['service_no'] ?? null;
    $statement = $pdo->prepare('INSERT INTO audit_events
        (tenant_id, actor_user_id, actor_username, action_type, entity_type, entity_id, service_no, before_json, after_json, source_event_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $statement->execute([
        $tenantId,
        (int) $actor['id'],
        (string) ($actor['username'] ?? ''),
        $actionType,
        $entityType,
        $entityId,
        $serviceNo,
        $beforeSnapshot === null ? null : json_encode($beforeSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $afterSnapshot === null ? null : json_encode($afterSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $sourceEventId,
    ]);
    return (int) $pdo->lastInsertId();
}

/** @return array{events:list<array<string,mixed>>,page:int,limit:int,has_more:bool} */
function list_tenant_audit_events(PDO $pdo, int $tenantId, int $page, int $limit): array
{
    if ($tenantId < 1 || $page < 1 || $limit < 1 || $limit > 100) {
        throw new InvalidArgumentException('审计分页参数无效。');
    }
    $offset = ($page - 1) * $limit;
    $queryLimit = $limit + 1;
    $statement = $pdo->prepare("SELECT event.*,
        CASE WHEN event.action_type = 'inventory.delete'
            AND NOT EXISTS (SELECT 1 FROM audit_events restored WHERE restored.source_event_id = event.id)
            THEN 1 ELSE 0 END AS can_restore
        FROM audit_events event
        WHERE event.tenant_id = ?
        ORDER BY event.id DESC LIMIT {$queryLimit} OFFSET {$offset}");
    $statement->execute([$tenantId]);
    $events = $statement->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($events) > $limit;
    if ($hasMore) array_pop($events);
    foreach ($events as &$event) {
        foreach (['before_json', 'after_json'] as $field) {
            $event[$field] = is_string($event[$field])
                ? json_decode($event[$field], true, 32, JSON_THROW_ON_ERROR)
                : null;
        }
        $event['can_restore'] = (int) $event['can_restore'] === 1;
    }
    unset($event);
    return ['events' => $events, 'page' => $page, 'limit' => $limit, 'has_more' => $hasMore];
}

function restore_deleted_inventory(PDO $pdo, int $tenantId, int $eventId, array $actor, callable $authorize): int
{
    if (!$pdo->inTransaction()) throw new LogicException('恢复库存必须在事务中执行。');
    $statement = $pdo->prepare("SELECT * FROM audit_events
        WHERE id = ? AND tenant_id = ? AND action_type = 'inventory.delete' AND entity_type = 'inventory'
        FOR UPDATE");
    $statement->execute([$eventId, $tenantId]);
    $event = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$event || !is_string($event['before_json'])) {
        throw new HttpException('删除记录不存在或不属于当前账号。', 404);
    }
    $used = $pdo->prepare('SELECT id FROM audit_events WHERE source_event_id = ? LIMIT 1');
    $used->execute([$eventId]);
    if ($used->fetchColumn()) throw new HttpException('这条删除记录已经恢复过。', 409);

    $snapshot = json_decode($event['before_json'], true, 32, JSON_THROW_ON_ERROR);
    $snapshot = inventory_audit_snapshot(is_array($snapshot) ? $snapshot : []);
    $serviceNo = trim((string) $snapshot['service_no']);
    $status = (string) $snapshot['status'];
    if ($serviceNo === '' || !in_array($status, ['US', 'TRANSIT', 'CN_WH', 'SOLD', 'REPAIR', 'REPAIR_DONE', 'PARTS', 'PARTS_SOLD'], true)) {
        throw new HttpException('删除快照已损坏，无法恢复。', 409);
    }
    $authorize($snapshot);
    if (!in_array($status, ['REPAIR', 'REPAIR_DONE', 'PARTS', 'PARTS_SOLD'], true)) {
        $conflict = $pdo->prepare("SELECT id FROM inventory_items
            WHERE user_id = ? AND service_no = ?
              AND status NOT IN ('REPAIR', 'REPAIR_DONE', 'PARTS', 'PARTS_SOLD') LIMIT 1");
        $conflict->execute([$tenantId, $serviceNo]);
        if ($conflict->fetchColumn()) throw new HttpException('相同服务编号已存在，无法恢复。', 409);
    }

    $insert = $pdo->prepare('INSERT INTO inventory_items
        (user_id, service_no, batch_no, quantity, status, status_date, status_timestamp, config_desc, cost_rmb, freight, receiver, remarks, collected_amount)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $insert->execute([
        $tenantId, $serviceNo, $snapshot['batch_no'], $snapshot['quantity'], $status,
        $snapshot['status_date'], $snapshot['status_timestamp'], $snapshot['config_desc'],
        $snapshot['cost_rmb'], $snapshot['freight'], $snapshot['receiver'], $snapshot['remarks'],
        $snapshot['collected_amount'],
    ]);
    $newId = (int) $pdo->lastInsertId();
    $restored = $snapshot;
    record_audit_event($pdo, $tenantId, $actor, 'inventory.restore', 'inventory', $newId, null, $restored, $eventId);
    return $newId;
}
