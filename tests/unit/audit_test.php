<?php
declare(strict_types=1);

$auditPath = dirname(__DIR__, 2) . '/php/lib/audit.php';
if (is_file($auditPath)) {
    require_once $auditPath;
}

test('inventory audit snapshots contain only restorable business fields', function (): void {
    assert_true(function_exists('inventory_audit_snapshot'), 'inventory_audit_snapshot() is missing');
    $snapshot = inventory_audit_snapshot([
        'id' => 8, 'user_id' => 2, 'service_no' => 'S-1', 'batch_no' => 'B-1',
        'quantity' => 2, 'status' => 'US', 'status_date' => '2026-09-28',
        'status_timestamp' => '2026-09-28 01:02:03', 'config_desc' => 'cfg',
        'cost_rmb' => '10.00', 'freight' => '2.00', 'receiver' => 'buyer',
        'remarks' => 'note', 'collected_amount' => '20.00', 'row_version' => 4,
        'password' => 'never-log-this', '_csrf_token' => 'csrf-secret',
    ]);
    assert_same('S-1', $snapshot['service_no']);
    assert_same(2, $snapshot['quantity']);
    $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
    foreach (['user_id', 'row_version', 'password', '_csrf_token', 'never-log-this', 'csrf-secret'] as $forbidden) {
        assert_false(str_contains($json, $forbidden));
    }
});

test('account audit snapshots exclude every authentication and lock secret', function (): void {
    assert_true(function_exists('account_audit_snapshot'), 'account_audit_snapshot() is missing');
    $snapshot = account_audit_snapshot([
        'id' => 9, 'username' => 'worker', 'role' => 'user', 'parent_id' => 2,
        'perm_edit' => 1, 'perm_finance' => 0, 'password' => 'password-secret',
        'lock_password' => 'lock-secret', 'sec_q1' => 'question-secret',
        'sec_a1' => 'answer-secret', 'session_version' => 17,
        'DB_PASSWORD' => 'database-secret', '_csrf_token' => 'csrf-secret',
    ]);
    assert_same(['username' => 'worker', 'role' => 'user', 'perm_edit' => 1, 'perm_finance' => 0], $snapshot);
    $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
    foreach (['password', 'lock', 'sec_', 'session', 'DB_', '_csrf', 'password-secret', 'database-secret'] as $forbidden) {
        assert_false(str_contains($json, $forbidden));
    }
});
