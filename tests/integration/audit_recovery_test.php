<?php
declare(strict_types=1);

require_once __DIR__ . '/api_endpoint_test.php';

test('tenant audit logs are isolated attributable and support one-time delete recovery', function (): void {
    endpoint_database(function (PDO $pdo, string $baseUrl): void {
        $owner = endpoint_login($baseUrl, 'endpoint-owner-a', 'EndpointOwner12!');
        $worker = endpoint_login($baseUrl, 'endpoint-worker-a', 'EndpointOwner12!');
        $foreign = endpoint_login($baseUrl, 'endpoint-owner-b', 'EndpointOwner12!');
        $item = $pdo->query("SELECT * FROM inventory_items WHERE user_id=10 AND service_no='OWNER-A-ITEM'")->fetch();

        $deleted = endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action' => 'delete', 'id' => $item['id'], 'row_version' => $item['row_version'],
        ], $owner['cookies'], $owner['csrf']);
        assert_same(200, $deleted['status']);

        $ownerLog = endpoint_json(endpoint_http_request($baseUrl . '/api_inventory.php?action=list_audit_events&page=1&limit=50', 'GET', null, [], $owner['cookies']));
        assert_same('success', $ownerLog['status']);
        $deleteEvent = array_values(array_filter($ownerLog['data']['events'], static fn (array $event): bool => $event['action_type'] === 'inventory.delete'))[0] ?? null;
        assert_true(is_array($deleteEvent));
        assert_same('endpoint-owner-a', $deleteEvent['actor_username']);
        assert_true($deleteEvent['can_restore']);
        assert_same('OWNER-A-ITEM', $deleteEvent['before_json']['service_no']);
        assert_false(str_contains(json_encode($deleteEvent, JSON_THROW_ON_ERROR), 'EndpointOwner12!'));

        $workerLog = endpoint_http_request($baseUrl . '/api_inventory.php?action=list_audit_events&page=1&limit=50', 'GET', null, [], $worker['cookies']);
        assert_same(403, $workerLog['status']);
        $foreignLog = endpoint_json(endpoint_http_request($baseUrl . '/api_inventory.php?action=list_audit_events&page=1&limit=50', 'GET', null, [], $foreign['cookies']));
        assert_same(0, count($foreignLog['data']['events']));

        $restored = endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action' => 'restore_deleted_inventory', 'event_id' => $deleteEvent['id'],
        ], $owner['cookies'], $owner['csrf']);
        assert_same(200, $restored['status']);
        assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE user_id=10 AND service_no='OWNER-A-ITEM'")->fetchColumn());
        $repeated = endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action' => 'restore_deleted_inventory', 'event_id' => $deleteEvent['id'],
        ], $owner['cookies'], $owner['csrf']);
        assert_same(409, $repeated['status']);

        $workerItem = $pdo->query("SELECT * FROM inventory_items WHERE user_id=10 AND service_no='OWNER-A-ITEM'")->fetch();
        $changed = endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action' => 'update_status', 'id' => $workerItem['id'], 'row_version' => $workerItem['row_version'], 'status' => 'TRANSIT',
        ], $worker['cookies'], $worker['csrf']);
        assert_same(200, $changed['status']);
        $ownerLog = endpoint_json(endpoint_http_request($baseUrl . '/api_inventory.php?action=list_audit_events&page=1&limit=50', 'GET', null, [], $owner['cookies']));
        $workerEvent = array_values(array_filter($ownerLog['data']['events'], static fn (array $event): bool => $event['actor_username'] === 'endpoint-worker-a'))[0] ?? null;
        assert_true(is_array($workerEvent));

        $pdo->exec("INSERT INTO inventory_items (user_id, service_no, quantity, status) VALUES (10, 'BATCH-AUDIT-1', 1, 'US'), (10, 'BATCH-AUDIT-2', 1, 'US')");
        $batchRows = $pdo->query("SELECT id, row_version FROM inventory_items WHERE service_no IN ('BATCH-AUDIT-1','BATCH-AUDIT-2') ORDER BY id")->fetchAll();
        $batchIds = array_map(static fn (array $row): int => (int) $row['id'], $batchRows);
        $batchVersions = [];
        foreach ($batchRows as $row) $batchVersions[(int) $row['id']] = (int) $row['row_version'];
        $batchResponse = endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action' => 'batch_update_status', 'ids' => implode(',', $batchIds),
            'versions' => json_encode($batchVersions, JSON_THROW_ON_ERROR), 'status' => 'TRANSIT',
        ], $owner['cookies'], $owner['csrf']);
        assert_same(200, $batchResponse['status']);
        $batchAuditCount = (int) $pdo->query("SELECT COUNT(*) FROM audit_events WHERE tenant_id=10 AND action_type='inventory.status' AND entity_id IN (" . implode(',', $batchIds) . ')')->fetchColumn();
        assert_same(2, $batchAuditCount);

        $accountChange = endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action' => 'save_sub_account', 'sub_id' => 11, 'sub_user' => 'endpoint-worker-a',
            'p_hist' => 999, 'p_edt' => 1, 'p_del' => 1, 'p_dl' => 1, 'p_imp' => 1,
            'p_add' => 1, 'p_exp' => 1, 'p_us' => 1, 'p_transit' => 1, 'p_cn' => 1,
            'p_sold' => 1, 'p_parts' => 1, 'p_parts_sold' => 1, 'p_repair' => 1, 'p_repair_done' => 1,
        ], $owner['cookies'], $owner['csrf']);
        assert_same(200, $accountChange['status']);
        $ownerLog = endpoint_json(endpoint_http_request($baseUrl . '/api_inventory.php?action=list_audit_events&page=1&limit=50', 'GET', null, [], $owner['cookies']));
        assert_true(count(array_filter($ownerLog['data']['events'], static fn (array $event): bool => $event['entity_type'] === 'account')) >= 1);
    });
});

test('delete recovery rejects a conflicting active service number', function (): void {
    endpoint_database(function (PDO $pdo, string $baseUrl): void {
        $owner = endpoint_login($baseUrl, 'endpoint-owner-a', 'EndpointOwner12!');
        $item = $pdo->query("SELECT * FROM inventory_items WHERE user_id=10 AND service_no='OWNER-A-ITEM'")->fetch();
        endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action' => 'delete', 'id' => $item['id'], 'row_version' => $item['row_version'],
        ], $owner['cookies'], $owner['csrf']);
        $eventId = (int) $pdo->query("SELECT id FROM audit_events WHERE tenant_id=10 AND action_type='inventory.delete' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $pdo->exec("INSERT INTO inventory_items (user_id, service_no, quantity, status) VALUES (10, 'OWNER-A-ITEM', 1, 'US')");
        $response = endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action' => 'restore_deleted_inventory', 'event_id' => $eventId,
        ], $owner['cookies'], $owner['csrf']);
        assert_same(409, $response['status']);
        assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE user_id=10 AND service_no='OWNER-A-ITEM'")->fetchColumn());
    });
});

test('audit financial values follow the current warehouse lock', function (): void {
    endpoint_database(function (PDO $pdo, string $baseUrl): void {
        $owner = endpoint_login($baseUrl, 'endpoint-owner-a', 'EndpointOwner12!');
        $item = $pdo->query("SELECT * FROM inventory_items WHERE user_id=10 AND service_no='OWNER-A-ITEM'")->fetch();
        $response = endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action' => 'save', 'id' => $item['id'], 'row_version' => $item['row_version'],
            'service_no' => $item['service_no'], 'quantity' => 1, 'status' => 'US',
            'batch_no' => '', 'config_desc' => 'audited', 'cost_rmb' => 123.45,
            'freight' => 6.78, 'receiver' => '', 'remarks' => '', 'collected_amount' => 0,
        ], $owner['cookies'], $owner['csrf']);
        assert_same(200, $response['status']);

        $pdo->exec('UPDATE users SET lock_tab_us=1 WHERE id=10');
        $log = endpoint_json(endpoint_http_request($baseUrl . '/api_inventory.php?action=list_audit_events&page=1&limit=20', 'GET', null, [], $owner['cookies']));
        $event = array_values(array_filter($log['data']['events'], static fn (array $row): bool => $row['action_type'] === 'inventory.update'))[0] ?? null;
        assert_true(is_array($event));
        assert_same('***', $event['before_json']['cost_rmb']);
        assert_same('***', $event['after_json']['cost_rmb']);
        assert_same('***', $event['after_json']['freight']);

        $pdo->exec('UPDATE users SET lock_tab_us=0 WHERE id=10');
        $log = endpoint_json(endpoint_http_request($baseUrl . '/api_inventory.php?action=list_audit_events&page=1&limit=20', 'GET', null, [], $owner['cookies']));
        $event = array_values(array_filter($log['data']['events'], static fn (array $row): bool => $row['action_type'] === 'inventory.update'))[0] ?? null;
        assert_same('123.45', (string) $event['after_json']['cost_rmb']);
    });
});

test('delete recovery enforces current warehouse permission and financial lock', function (): void {
    endpoint_database(function (PDO $pdo, string $baseUrl): void {
        $owner = endpoint_login($baseUrl, 'endpoint-owner-a', 'EndpointOwner12!');
        $item = $pdo->query("SELECT * FROM inventory_items WHERE user_id=10 AND service_no='OWNER-A-ITEM'")->fetch();
        assert_same(200, endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action' => 'delete', 'id' => $item['id'], 'row_version' => $item['row_version'],
        ], $owner['cookies'], $owner['csrf'])['status']);
        $eventId = (int) $pdo->query("SELECT id FROM audit_events WHERE tenant_id=10 AND action_type='inventory.delete' ORDER BY id DESC LIMIT 1")->fetchColumn();

        $pdo->exec('UPDATE users SET perm_tab_us=0 WHERE id=10');
        $disabled = endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action' => 'restore_deleted_inventory', 'event_id' => $eventId,
        ], $owner['cookies'], $owner['csrf']);
        assert_same(403, $disabled['status']);
        assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE service_no='OWNER-A-ITEM'")->fetchColumn());

        $pdo->exec('UPDATE users SET perm_tab_us=1, lock_tab_us=1 WHERE id=10');
        $locked = endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action' => 'restore_deleted_inventory', 'event_id' => $eventId,
        ], $owner['cookies'], $owner['csrf']);
        assert_same(403, $locked['status']);
        assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE service_no='OWNER-A-ITEM'")->fetchColumn());
    });
});
