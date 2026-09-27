<?php
declare(strict_types=1);

$authorizationPath = dirname(__DIR__, 2) . '/php/lib/authorization.php';
if (is_file($authorizationPath)) {
    require_once $authorizationPath;
}

test('tenant id resolves primary and employee accounts to one owner', function (): void {
    assert_true(function_exists('tenant_id'), 'tenant_id() is missing');
    assert_same(7, tenant_id(['id' => 7, 'parent_id' => 0]));
    assert_same(7, tenant_id(['id' => 12, 'parent_id' => 7]));
});

test('warehouse policy intersects employee and owner permissions', function (): void {
    assert_true(function_exists('allowed_statuses'), 'allowed_statuses() is missing');
    $owner = array_fill_keys(array_values(INVENTORY_STATUS_PERMISSIONS), 0);
    $employee = array_fill_keys(array_values(INVENTORY_STATUS_PERMISSIONS), 0);
    $owner['perm_tab_us'] = 1;
    $owner['perm_tab_cn'] = 1;
    $employee['parent_id'] = 7;
    $employee['perm_tab_us'] = 1;
    $employee['perm_tab_sold'] = 1;
    assert_same(['US'], allowed_statuses($employee, $owner));
    assert_throws(fn () => require_status_access('CN_WH', $employee, $owner), RuntimeException::class);
    assert_throws(fn () => require_status_access('NOT_REAL', $employee, $owner), RuntimeException::class);
});

test('batch ids are positive unique and bounded', function (): void {
    assert_true(function_exists('parse_ids'), 'parse_ids() is missing');
    assert_same([3, 4], parse_ids('3,4,3'));
    assert_throws(fn () => parse_ids('3,wrong'), RuntimeException::class);
    assert_throws(fn () => parse_ids('1,2,3', 2), RuntimeException::class);
});

test('individual identifiers and pages require bounded positive integers', function (): void {
    assert_true(function_exists('positive_int_input'), 'positive_int_input() is missing');
    assert_same(12, positive_int_input('12', '编号', 100));
    assert_throws(fn () => positive_int_input('12oops', '编号', 100), RuntimeException::class);
    assert_throws(fn () => positive_int_input('-1', '编号', 100), RuntimeException::class);
    assert_throws(fn () => positive_int_input('101', '编号', 100), RuntimeException::class);
});

test('inventory input rejects invalid status and oversized or invalid values', function (): void {
    assert_true(function_exists('validate_inventory_input'), 'validate_inventory_input() is missing');
    $valid = validate_inventory_input([
        'service_no' => '  SV-100  ',
        'status' => 'US',
        'quantity' => '2',
        'cost_rmb' => '12.50',
        'freight' => '2',
        'collected_amount' => '0',
    ]);
    assert_same('SV-100', $valid['service_no']);
    assert_same(2, $valid['quantity']);
    assert_throws(fn () => validate_inventory_input(['service_no' => 'x', 'status' => 'ROOT', 'quantity' => 1]), RuntimeException::class);
    assert_throws(fn () => validate_inventory_input(['service_no' => str_repeat('x', 101), 'status' => 'US', 'quantity' => 1]), RuntimeException::class);
    assert_throws(fn () => validate_inventory_input(['service_no' => 'x', 'status' => 'US', 'quantity' => 0]), RuntimeException::class);
    assert_throws(fn () => validate_inventory_input(['service_no' => 'x', 'status' => 'US', 'quantity' => 1, 'cost_rmb' => '-1']), RuntimeException::class);
});

test('financial masking removes every monetary value', function (): void {
    assert_true(function_exists('mask_financial_fields'), 'mask_financial_fields() is missing');
    $masked = mask_financial_fields([
        'cost_rmb' => '100',
        'freight' => '20',
        'collected_amount' => '180',
        'profit' => '60',
        'service_no' => 'SV-1',
    ]);
    assert_same('***', $masked['cost_rmb']);
    assert_same('***', $masked['freight']);
    assert_same('***', $masked['collected_amount']);
    assert_same('***', $masked['profit']);
    assert_same('SV-1', $masked['service_no']);
});

test('restricted financial writes preserve every hidden monetary value', function (): void {
    assert_true(function_exists('financial_values_for_write'), 'financial_values_for_write() is missing');
    $posted = ['cost_rmb' => 0.0, 'freight' => 0.0, 'collected_amount' => 0.0, 'quantity' => 4];
    $existing = ['cost_rmb' => '100.00', 'freight' => '20.00', 'collected_amount' => '180.00', 'quantity' => 2];
    assert_same(
        ['cost_rmb' => 200.0, 'freight' => 40.0, 'collected_amount' => 360.0],
        financial_values_for_write($posted, $existing, false)
    );
    assert_same(
        ['cost_rmb' => 0.0, 'freight' => 0.0, 'collected_amount' => 0.0],
        financial_values_for_write($posted, null, false)
    );
});

test('financial write access requires permission and every locked warehouse to be unlocked', function (): void {
    assert_true(function_exists('can_manage_financial_values'), 'can_manage_financial_values() is missing');
    $owner = ['lock_tab_us' => 0, 'lock_tab_sold' => 1];
    assert_false(can_manage_financial_values(0, $owner, ['US'], true));
    assert_true(can_manage_financial_values(1, $owner, ['US'], false));
    assert_false(can_manage_financial_values(1, $owner, ['US', 'SOLD'], false));
    assert_true(can_manage_financial_values(1, $owner, ['US', 'SOLD'], true));
});

test('system administrator cannot self delete through the application', function (): void {
    assert_true(function_exists('require_self_delete_allowed'), 'require_self_delete_allowed() is missing');
    require_self_delete_allowed(['role' => 'user', 'parent_id' => 0]);
    assert_throws(fn () => require_self_delete_allowed(['role' => 'admin', 'parent_id' => 0]), RuntimeException::class);
    assert_throws(fn () => require_self_delete_allowed(['role' => 'user', 'parent_id' => 7]), RuntimeException::class);
});
