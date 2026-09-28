<?php
declare(strict_types=1);

require_once __DIR__ . '/api_endpoint_test.php';
require_once dirname(__DIR__, 2) . '/php/lib/inventory_consistency.php';
require_once dirname(__DIR__, 2) . '/php/lib/audit.php';

test('http inventory writes reject stale single and mixed batch versions atomically', function (): void {
    endpoint_database(function (PDO $pdo, string $baseUrl): void {
        $session = endpoint_login($baseUrl, 'endpoint-owner-a', 'EndpointOwner12!');
        $list = endpoint_json(endpoint_http_request(
            $baseUrl . '/api_inventory.php?action=list&status=US&page=1&limit=200',
            'GET', null, [], $session['cookies']
        ));
        $first = $list['data'][0];
        $pdo->exec("INSERT INTO inventory_items (user_id, service_no, quantity, status) VALUES (10, 'OWNER-A-SECOND', 1, 'US')");
        $secondId = (int) $pdo->lastInsertId();

        $save = [
            'action' => 'save', 'id' => $first['id'], 'row_version' => $first['row_version'],
            'service_no' => $first['service_no'], 'quantity' => 1, 'status' => 'US',
            'batch_no' => '', 'config_desc' => 'first edit', 'cost_rmb' => 10,
            'freight' => 1, 'receiver' => '', 'remarks' => '', 'collected_amount' => 100,
        ];
        assert_same(200, endpoint_form_request($baseUrl . '/api_inventory.php', $save, $session['cookies'], $session['csrf'])['status']);
        $stale = endpoint_form_request($baseUrl . '/api_inventory.php', $save, $session['cookies'], $session['csrf']);
        assert_same(409, $stale['status']);

        $currentFirstVersion = (int) $pdo->query('SELECT row_version FROM inventory_items WHERE id=' . (int) $first['id'])->fetchColumn();
        $secondVersion = (int) $pdo->query('SELECT row_version FROM inventory_items WHERE id=' . $secondId)->fetchColumn();
        $pdo->exec('UPDATE inventory_items SET remarks="outside edit", row_version=row_version+1 WHERE id=' . $secondId);
        $batch = endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action' => 'batch_update_status',
            'ids' => $first['id'] . ',' . $secondId,
            'versions' => json_encode([$first['id'] => $currentFirstVersion, $secondId => $secondVersion]),
            'status' => 'TRANSIT',
        ], $session['cookies'], $session['csrf']);
        assert_same(409, $batch['status']);
        assert_same('US', (string) $pdo->query('SELECT status FROM inventory_items WHERE id=' . (int) $first['id'])->fetchColumn());
        assert_same('US', (string) $pdo->query('SELECT status FROM inventory_items WHERE id=' . $secondId)->fetchColumn());
    });
});

test('tenant revision advances only for committed inventory mutations', function (): void {
    endpoint_database(function (PDO $pdo, string $baseUrl): void {
        $session = endpoint_login($baseUrl, 'endpoint-owner-a', 'EndpointOwner12!');
        $probe = endpoint_json(endpoint_http_request($baseUrl . '/api_inventory.php?action=check_update', 'GET', null, [], $session['cookies']));
        assert_same('0', (string) $probe['last_update']);
        $create = endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action' => 'save', 'service_no' => 'REVISION-ITEM', 'quantity' => 1, 'status' => 'US',
            'batch_no' => '', 'config_desc' => '', 'cost_rmb' => 0, 'freight' => 0,
            'receiver' => '', 'remarks' => '', 'collected_amount' => 0,
        ], $session['cookies'], $session['csrf']);
        assert_same(200, $create['status']);
        $probe = endpoint_json(endpoint_http_request($baseUrl . '/api_inventory.php?action=check_update', 'GET', null, [], $session['cookies']));
        assert_same('1', (string) $probe['last_update']);

        $duplicate = endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action' => 'save', 'service_no' => 'REVISION-ITEM', 'quantity' => 1, 'status' => 'US',
            'batch_no' => '', 'config_desc' => '', 'cost_rmb' => 0, 'freight' => 0,
            'receiver' => '', 'remarks' => '', 'collected_amount' => 0,
        ], $session['cookies'], $session['csrf']);
        assert_same(400, $duplicate['status']);
        $probe = endpoint_json(endpoint_http_request($baseUrl . '/api_inventory.php?action=check_update', 'GET', null, [], $session['cookies']));
        assert_same('1', (string) $probe['last_update']);
        assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE user_id=10 AND service_no='REVISION-ITEM'")->fetchColumn());
    });
});

test('competing tenant lock is rejected and released mutations remain atomic', function (): void {
    endpoint_database(function (PDO $pdo, string $baseUrl): void {
        $session = endpoint_login($baseUrl, 'endpoint-owner-a', 'EndpointOwner12!');
        assert_same(1, (int) $pdo->query("SELECT GET_LOCK('inventory_tenant_10', 0)")->fetchColumn());
        try {
            $blocked = endpoint_form_request($baseUrl . '/api_inventory.php', [
                'action' => 'save', 'service_no' => 'LOCK-COMPETITOR', 'quantity' => 1, 'status' => 'US',
                'batch_no' => '', 'config_desc' => '', 'cost_rmb' => 0, 'freight' => 0,
                'receiver' => '', 'remarks' => '', 'collected_amount' => 0,
            ], $session['cookies'], $session['csrf']);
            assert_same(409, $blocked['status']);
            assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE service_no='LOCK-COMPETITOR'")->fetchColumn());
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('inventory_tenant_10')")->fetchColumn();
        }

        $created = endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action' => 'save', 'service_no' => 'LOCK-COMPETITOR', 'quantity' => 1, 'status' => 'US',
            'batch_no' => '', 'config_desc' => '', 'cost_rmb' => 0, 'freight' => 0,
            'receiver' => '', 'remarks' => '', 'collected_amount' => 0,
        ], $session['cookies'], $session['csrf']);
        assert_same(200, $created['status']);

        $revisionBefore = tenant_inventory_revision($pdo, 10);
        try {
            with_tenant_inventory_mutation($pdo, 10, function () use ($pdo): void {
                $pdo->exec("INSERT INTO inventory_items (user_id, service_no, quantity, status) VALUES (10, 'ROLLBACK-ITEM', 1, 'US')");
                $itemId = (int) $pdo->lastInsertId();
                record_audit_event($pdo, 10, ['id' => 10, 'username' => 'endpoint-owner-a'], 'inventory.create', 'inventory', $itemId, null, [
                    'service_no' => 'ROLLBACK-ITEM', 'quantity' => 1, 'status' => 'US',
                ]);
                throw new RuntimeException('forced transaction failure');
            });
            throw new TestFailure('Expected forced transaction failure');
        } catch (RuntimeException $exception) {
            assert_same('forced transaction failure', $exception->getMessage());
        }
        assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE service_no='ROLLBACK-ITEM'")->fetchColumn());
        assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM audit_events WHERE service_no='ROLLBACK-ITEM'")->fetchColumn());
        assert_same($revisionBefore, tenant_inventory_revision($pdo, 10));
        assert_same(1, (int) $pdo->query("SELECT IS_FREE_LOCK('inventory_tenant_10')")->fetchColumn());
    });
});
