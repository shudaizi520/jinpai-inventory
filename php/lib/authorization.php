<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';

const INVENTORY_STATUS_PERMISSIONS = [
    'US' => 'perm_tab_us',
    'TRANSIT' => 'perm_tab_transit',
    'CN_WH' => 'perm_tab_cn',
    'SOLD' => 'perm_tab_sold',
    'PARTS' => 'perm_tab_parts',
    'PARTS_SOLD' => 'perm_tab_parts_sold',
    'REPAIR' => 'perm_tab_repair',
    'REPAIR_DONE' => 'perm_tab_repair_done',
];

const INVENTORY_STATUS_LOCKS = [
    'US' => 'lock_tab_us',
    'TRANSIT' => 'lock_tab_transit',
    'CN_WH' => 'lock_tab_cn',
    'SOLD' => 'lock_tab_sold',
    'PARTS' => 'lock_tab_parts',
    'PARTS_SOLD' => 'lock_tab_parts_sold',
    'REPAIR' => 'lock_tab_repair',
    'REPAIR_DONE' => 'lock_tab_repair_done',
];

function tenant_id(array $user): int
{
    $id = (int) ($user['id'] ?? 0);
    $parentId = (int) ($user['parent_id'] ?? 0);
    $tenantId = $parentId > 0 ? $parentId : $id;
    if ($tenantId < 1) {
        throw new InvalidArgumentException('账号租户信息无效。');
    }
    return $tenantId;
}

/** @return list<string> */
function allowed_statuses(array $user, array $owner): array
{
    $isEmployee = (int) ($user['parent_id'] ?? 0) > 0;
    $allowed = [];
    foreach (INVENTORY_STATUS_PERMISSIONS as $status => $permission) {
        $ownerAllows = (int) ($owner[$permission] ?? 1) === 1;
        $userAllows = !$isEmployee || (int) ($user[$permission] ?? 1) === 1;
        if ($ownerAllows && $userAllows) {
            $allowed[] = $status;
        }
    }
    return $allowed;
}

function require_status_access(string $status, array $user, array $owner): void
{
    if (!array_key_exists($status, INVENTORY_STATUS_PERMISSIONS)) {
        throw new HttpException('仓库状态无效。', 400);
    }
    if (!in_array($status, allowed_statuses($user, $owner), true)) {
        throw new HttpException('您没有访问该仓库的权限。', 403);
    }
}

/** @return list<int> */
function parse_ids(string $value, int $maximum = 200): array
{
    if ($maximum < 1) {
        throw new InvalidArgumentException('批量数量上限无效。');
    }
    $value = trim($value);
    if ($value === '') {
        throw new HttpException('未选择任何记录。', 400);
    }

    $ids = [];
    foreach (explode(',', $value) as $raw) {
        $raw = trim($raw);
        if ($raw === '' || preg_match('/^[1-9][0-9]*$/', $raw) !== 1) {
            throw new HttpException('记录编号格式无效。', 400);
        }
        $id = (int) $raw;
        if ($id < 1) {
            throw new HttpException('记录编号格式无效。', 400);
        }
        $ids[$id] = $id;
        if (count($ids) > $maximum) {
            throw new HttpException("单次最多操作 {$maximum} 条记录。", 400);
        }
    }
    return array_values($ids);
}

function positive_int_input(mixed $value, string $label, int $maximum = PHP_INT_MAX): int
{
    $stringValue = is_int($value) ? (string) $value : trim((string) $value);
    if (preg_match('/^[1-9][0-9]*$/', $stringValue) !== 1) {
        throw new HttpException("{$label}格式无效。", 400);
    }
    $integer = filter_var($stringValue, FILTER_VALIDATE_INT);
    if ($integer === false || $integer < 1 || $integer > $maximum) {
        throw new HttpException("{$label}超出允许范围。", 400);
    }
    return (int) $integer;
}

/**
 * @return array{service_no:string,batch_no:string,quantity:int,status:string,config_desc:string,cost_rmb:float,freight:float,receiver:string,remarks:string,collected_amount:float}
 */
function validate_inventory_input(array $input): array
{
    $status = trim((string) ($input['status'] ?? 'US'));
    if (!array_key_exists($status, INVENTORY_STATUS_PERMISSIONS)) {
        throw new HttpException('仓库状态无效。', 400);
    }

    $serviceNo = bounded_text($input['service_no'] ?? '', '服务编号', 100, true);
    $quantityRaw = $input['quantity'] ?? 1;
    if (filter_var($quantityRaw, FILTER_VALIDATE_INT) === false) {
        throw new HttpException('数量必须是整数。', 400);
    }
    $quantity = (int) $quantityRaw;
    if ($quantity < 1 || $quantity > 100000) {
        throw new HttpException('数量必须在 1 到 100000 之间。', 400);
    }

    $validated = [
        'service_no' => $serviceNo,
        'batch_no' => bounded_text($input['batch_no'] ?? '', '批次', 100),
        'quantity' => $quantity,
        'status' => $status,
        'config_desc' => bounded_text($input['config_desc'] ?? '', '配置', 255),
        'cost_rmb' => bounded_money($input['cost_rmb'] ?? 0, '成本'),
        'freight' => bounded_money($input['freight'] ?? 0, '运费'),
        'receiver' => bounded_text($input['receiver'] ?? '', '收货人', 100),
        'remarks' => bounded_text($input['remarks'] ?? '', '备注', 255),
        'collected_amount' => bounded_money($input['collected_amount'] ?? 0, '收款金额'),
    ];

    return $validated;
}

function bounded_text(mixed $value, string $label, int $maximum, bool $required = false): string
{
    $value = trim((string) $value);
    if ($required && $value === '') {
        throw new HttpException("{$label}不能为空。", 400);
    }
    if (strlen($value) > $maximum) {
        throw new HttpException("{$label}不能超过 {$maximum} 个字符。", 400);
    }
    return $value;
}

function bounded_money(mixed $value, string $label): float
{
    if ($value === '' || $value === null) {
        return 0.0;
    }
    if (!is_numeric($value)) {
        throw new HttpException("{$label}必须是数字。", 400);
    }
    $number = (float) $value;
    if (!is_finite($number) || $number < 0 || $number > 9999999999.99) {
        throw new HttpException("{$label}超出允许范围。", 400);
    }
    return round($number, 2);
}

function mask_financial_fields(array $row): array
{
    foreach (['cost_rmb', 'freight', 'collected_amount', 'profit'] as $field) {
        if (array_key_exists($field, $row)) {
            $row[$field] = '***';
        }
    }
    return $row;
}

/** @return array{cost_rmb:float,freight:float,collected_amount:float} */
function financial_values_for_write(array $input, ?array $existing, bool $canEdit): array
{
    if ($canEdit) {
        return [
            'cost_rmb' => bounded_money($input['cost_rmb'] ?? 0, '成本'),
            'freight' => bounded_money($input['freight'] ?? 0, '运费'),
            'collected_amount' => bounded_money($input['collected_amount'] ?? 0, '收款金额'),
        ];
    }
    if ($existing === null) {
        return ['cost_rmb' => 0.0, 'freight' => 0.0, 'collected_amount' => 0.0];
    }

    $oldQuantity = max(1, (int) ($existing['quantity'] ?? 1));
    $newQuantity = positive_int_input($input['quantity'] ?? 1, '数量', 100000);
    return [
        'cost_rmb' => bounded_money(((float) ($existing['cost_rmb'] ?? 0) / $oldQuantity) * $newQuantity, '成本'),
        'freight' => bounded_money(((float) ($existing['freight'] ?? 0) / $oldQuantity) * $newQuantity, '运费'),
        'collected_amount' => bounded_money(((float) ($existing['collected_amount'] ?? 0) / $oldQuantity) * $newQuantity, '收款金额'),
    ];
}

function can_manage_financial_values(int $permission, array $owner, array $statuses, bool $unlocked): bool
{
    if ($permission !== 1) {
        return false;
    }
    foreach (array_unique($statuses) as $status) {
        $lockColumn = INVENTORY_STATUS_LOCKS[$status] ?? null;
        if ($lockColumn === null) {
            throw new HttpException('仓库状态无效。', 400);
        }
        if ((int) ($owner[$lockColumn] ?? 0) === 1 && !$unlocked) {
            return false;
        }
    }
    return true;
}

function require_sold_record_details(string $status, string $receiver, float $collectedAmount): void
{
    if (in_array($status, ['SOLD', 'PARTS_SOLD'], true)
        && ($receiver === '' || $collectedAmount <= 0)) {
        throw new HttpException('已售记录必须填写收货人和有效收款金额。', 400);
    }
}

function require_self_delete_allowed(array $user): void
{
    if ((int) ($user['parent_id'] ?? 0) > 0) {
        throw new HttpException('员工账号不能执行主账号注销。', 403);
    }
    if (($user['role'] ?? '') === 'admin') {
        throw new HttpException('系统管理员不能在应用内注销，以免系统失去管理账号。', 403);
    }
}

/** @return list<array<string, mixed>> */
function load_tenant_items(PDO $pdo, array $ids, int $tenantId, bool $forUpdate = false): array
{
    if ($tenantId < 1 || $ids === []) {
        throw new InvalidArgumentException('租户或记录编号无效。');
    }
    $normalized = parse_ids(implode(',', $ids));
    if ($forUpdate && !$pdo->inTransaction()) {
        throw new LogicException('加锁读取必须在事务中执行。');
    }

    $placeholders = implode(',', array_fill(0, count($normalized), '?'));
    $sql = "SELECT * FROM inventory_items WHERE user_id = ? AND id IN ({$placeholders})";
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $statement = $pdo->prepare($sql);
    $statement->execute(array_merge([$tenantId], $normalized));
    $items = $statement->fetchAll(PDO::FETCH_ASSOC);
    if (count($items) !== count($normalized)) {
        throw new HttpException('部分记录不存在或不属于当前账号。', 404);
    }
    return $items;
}

function require_tenant_item(PDO $pdo, int $id, int $tenantId, bool $forUpdate = false): array
{
    if ($id < 1) {
        throw new HttpException('记录编号无效。', 400);
    }
    return load_tenant_items($pdo, [$id], $tenantId, $forUpdate)[0];
}
