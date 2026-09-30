<?php
declare(strict_types=1);

require_once __DIR__ . '/api_endpoint_test.php';

function safety_import(string $baseUrl, array $rows, array &$cookies, string $csrf): array
{
    return endpoint_json(endpoint_http_request($baseUrl . '/api_inventory.php?action=import', 'POST',
        json_encode($rows, JSON_THROW_ON_ERROR), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $cookies));
}

test('parts import updates remaining stock without overwriting a partial sale', function (): void {
    endpoint_database(function (PDO $pdo, string $baseUrl): void {
        $session = endpoint_login($baseUrl, 'endpoint-owner-a', 'EndpointOwner12!');
        $cookies = $session['cookies'];
        $item = $pdo->query("SELECT * FROM inventory_items WHERE user_id=10 AND service_no='OWNER-A-PART'")->fetch();
        $result = endpoint_json(endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action'=>'dispatch_part', 'id'=>$item['id'], 'row_version'=>$item['row_version'],
            'dispatch_qty'=>1, 'receiver'=>'test-customer', 'unit_collected'=>150,
        ], $cookies, $session['csrf']));
        assert_same('success', $result['status']);
        $sale = $pdo->query("SELECT * FROM inventory_items WHERE user_id=10 AND status='PARTS_SOLD'")->fetch();
        $result = safety_import($baseUrl, [['服务编号'=>'OWNER-A-PART', '状态'=>'零配件仓', '数量'=>5]], $cookies, $session['csrf']);
        assert_same('success', $result['status']);
        assert_same($sale, $pdo->query("SELECT * FROM inventory_items WHERE id=" . (int)$sale['id'])->fetch(), 'Sale history changed');
        assert_same(5, (int)$pdo->query("SELECT quantity FROM inventory_items WHERE id=" . (int)$item['id'])->fetchColumn());
        assert_same(2, (int)$pdo->query("SELECT COUNT(*) FROM inventory_items WHERE user_id=10 AND service_no='OWNER-A-PART'")->fetchColumn());
    });
});

test('restocking a sold-out part creates stock without changing the sale', function (): void {
    endpoint_database(function (PDO $pdo, string $baseUrl): void {
        $pdo->exec("UPDATE inventory_items SET status='PARTS_SOLD', receiver='test-customer', collected_amount=450 WHERE user_id=10 AND service_no='OWNER-A-PART'");
        $sale = $pdo->query("SELECT * FROM inventory_items WHERE user_id=10 AND service_no='OWNER-A-PART'")->fetch();
        $session = endpoint_login($baseUrl, 'endpoint-owner-a', 'EndpointOwner12!');
        $cookies = $session['cookies'];
        $result = safety_import($baseUrl, [['服务编号'=>'OWNER-A-PART', '状态'=>'零配件仓', '数量'=>5]], $cookies, $session['csrf']);
        assert_same('success', $result['status']);
        assert_same($sale, $pdo->query("SELECT * FROM inventory_items WHERE id=" . (int)$sale['id'])->fetch());
        assert_same(5, (int)$pdo->query("SELECT quantity FROM inventory_items WHERE user_id=10 AND service_no='OWNER-A-PART' AND status='PARTS'")->fetchColumn());
    });
});

test('ambiguous parts import rejects the entire file without guessing a record', function (): void {
    endpoint_database(function (PDO $pdo, string $baseUrl): void {
        $pdo->exec("INSERT INTO inventory_items (user_id,service_no,quantity,status) VALUES (10,'OWNER-A-PART',4,'PARTS')");
        $before = $pdo->query('SELECT * FROM inventory_items ORDER BY id')->fetchAll();
        $session = endpoint_login($baseUrl, 'endpoint-owner-a', 'EndpointOwner12!');
        $cookies = $session['cookies'];
        $result = safety_import($baseUrl, [
            ['服务编号'=>'SHOULD-ROLL-BACK', '状态'=>'美国', '数量'=>1],
            ['服务编号'=>'OWNER-A-PART', '状态'=>'零配件仓', '数量'=>5],
        ], $cookies, $session['csrf']);
        assert_same('error', $result['status']);
        assert_same($before, $pdo->query('SELECT * FROM inventory_items ORDER BY id')->fetchAll());
    });
});

test('every status-changing entry point enforces source and destination financial locks', function (): void {
    endpoint_database(function (PDO $pdo, string $baseUrl): void {
        $pdo->prepare('UPDATE users SET lock_tab_sold=1, lock_password=? WHERE id=10')->execute([password_hash('FinanceVault12!', PASSWORD_DEFAULT)]);
        $pdo->exec('UPDATE users SET perm_finance=1 WHERE id=11');
        $pdo->exec("UPDATE inventory_items SET status_date=CURRENT_DATE() WHERE user_id=10 AND status='SOLD'");
        $pdo->exec("UPDATE inventory_items SET receiver='test-customer' WHERE user_id=10 AND service_no='OWNER-A-ITEM'");
        $session = endpoint_login($baseUrl, 'endpoint-worker-a', 'EndpointOwner12!');
        $cookies = $session['cookies'];
        $sold = $pdo->query("SELECT * FROM inventory_items WHERE user_id=10 AND service_no='OWNER-A-SOLD'")->fetch();
        $before = $pdo->query('SELECT * FROM inventory_items ORDER BY id')->fetchAll();
        $save = ['action'=>'save', 'id'=>$sold['id'], 'row_version'=>$sold['row_version'],
            'service_no'=>$sold['service_no'], 'quantity'=>1, 'status'=>'CN_WH', 'receiver'=>'test-customer'];
        foreach ([
            ['action'=>'update_status', 'id'=>$sold['id'], 'row_version'=>$sold['row_version'], 'status'=>'CN_WH'],
            ['action'=>'batch_update_status', 'ids'=>(string)$sold['id'], 'versions'=>json_encode([$sold['id']=>1]), 'status'=>'CN_WH'],
            $save,
        ] as $request) {
            $response = endpoint_form_request($baseUrl . '/api_inventory.php', $request, $cookies, $session['csrf']);
            $result = endpoint_json($response);
            assert_same('error', $result['status'], $request['action'] . ' bypassed the financial lock');
            assert_same(403, $response['status']);
            assert_same('FINANCIAL_LOCKED', $result['code'] ?? null);
        }
        $result = safety_import($baseUrl, [['服务编号'=>'OWNER-A-SOLD', '状态'=>'国内仓', '数量'=>1]], $cookies, $session['csrf']);
        assert_same('error', $result['status'], 'Import bypassed the source lock');
        $stock = $pdo->query("SELECT * FROM inventory_items WHERE user_id=10 AND service_no='OWNER-A-ITEM'")->fetch();
        $response = endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action'=>'update_status', 'id'=>$stock['id'], 'row_version'=>1, 'status'=>'SOLD',
        ], $cookies, $session['csrf']);
        $result = endpoint_json($response);
        assert_same('error', $result['status'], 'Destination lock was not enforced');
        assert_same(403, $response['status']);
        assert_same('FINANCIAL_LOCKED', $result['code'] ?? null);
        assert_same($before, $pdo->query('SELECT * FROM inventory_items ORDER BY id')->fetchAll());

        $unlock = endpoint_json(endpoint_form_request($baseUrl . '/api_inventory.php', ['action'=>'verify_lock', 'pwd'=>'FinanceVault12!'], $cookies, $session['csrf']));
        assert_same('success', $unlock['status']);
        $result = endpoint_json(endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action'=>'update_status', 'id'=>$sold['id'], 'row_version'=>1, 'status'=>'CN_WH',
        ], $cookies, $session['csrf']));
        assert_same('success', $result['status'], 'A properly unlocked transition must remain available');
    });
});

test('single and batch transitions reject collisions with existing main-flow service numbers', function (): void {
    endpoint_database(function (PDO $pdo, string $baseUrl): void {
        $pdo->exec("INSERT INTO inventory_items (user_id,service_no,quantity,status) VALUES (10,'owner-a-item',1,'REPAIR')");
        $repairId = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO inventory_items (user_id,service_no,quantity,status) VALUES (10,'UNIQUE-REPAIR',1,'REPAIR')");
        $uniqueId = (int)$pdo->lastInsertId();
        $before = $pdo->query('SELECT * FROM inventory_items ORDER BY id')->fetchAll();
        $session = endpoint_login($baseUrl, 'endpoint-owner-a', 'EndpointOwner12!');
        $cookies = $session['cookies'];
        foreach ([
            ['action'=>'update_status', 'id'=>$repairId, 'row_version'=>1, 'status'=>'CN_WH'],
            ['action'=>'batch_update_status', 'ids'=>"$uniqueId,$repairId", 'versions'=>json_encode([$uniqueId=>1,$repairId=>1]), 'status'=>'CN_WH'],
        ] as $request) {
            $result = endpoint_json(endpoint_form_request($baseUrl . '/api_inventory.php', $request, $cookies, $session['csrf']));
            assert_same('error', $result['status']);
            assert_same($before, $pdo->query('SELECT * FROM inventory_items ORDER BY id')->fetchAll(), 'Conflicting batch was not atomic');
        }
    });
});

test('batch transitions reject duplicate incoming service numbers using database collation', function (): void {
    endpoint_database(function (PDO $pdo, string $baseUrl): void {
        $pdo->exec("INSERT INTO inventory_items (user_id,service_no,quantity,status) VALUES (10,'SAME-PART',1,'PARTS'), (10,'same-part',1,'PARTS')");
        $items = $pdo->query("SELECT * FROM inventory_items WHERE user_id=10 AND status='PARTS' AND service_no='SAME-PART'")->fetchAll();
        $ids = array_column($items, 'id');
        $session = endpoint_login($baseUrl, 'endpoint-owner-a', 'EndpointOwner12!');
        $cookies = $session['cookies'];
        $result = endpoint_json(endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action'=>'batch_update_status', 'ids'=>implode(',', $ids), 'versions'=>json_encode(array_fill_keys($ids,1)), 'status'=>'CN_WH',
        ], $cookies, $session['csrf']));
        assert_same('error', $result['status']);
        assert_same($items, $pdo->query("SELECT * FROM inventory_items WHERE user_id=10 AND status='PARTS' AND service_no='SAME-PART'")->fetchAll());
    });
});

test('valid transitions keep their own record and ignore other tenants service numbers', function (): void {
    endpoint_database(function (PDO $pdo, string $baseUrl): void {
        $pdo->exec("INSERT INTO inventory_items (user_id,service_no,quantity,status) VALUES (10,'OWNER-B-ITEM',1,'REPAIR')");
        $id = (int)$pdo->lastInsertId();
        $session = endpoint_login($baseUrl, 'endpoint-owner-a', 'EndpointOwner12!');
        $cookies = $session['cookies'];
        foreach (['CN_WH','TRANSIT'] as $index=>$status) {
            $result = endpoint_json(endpoint_form_request($baseUrl . '/api_inventory.php', [
                'action'=>'update_status', 'id'=>$id, 'row_version'=>$index+1, 'status'=>$status,
            ], $cookies, $session['csrf']));
            assert_same('success', $result['status']);
        }
        assert_same('US', $pdo->query("SELECT status FROM inventory_items WHERE user_id=20 AND service_no='OWNER-B-ITEM'")->fetchColumn());
    });
});

test('retrying a masked save after unlocking preserves unavailable financial fields', function (): void {
    endpoint_database(function (PDO $pdo, string $baseUrl): void {
        $pdo->exec('UPDATE users SET lock_tab_sold=1 WHERE id=10');
        $pdo->exec("UPDATE inventory_items SET status_date=CURRENT_DATE() WHERE user_id=10 AND status='SOLD'");
        $session = endpoint_login($baseUrl, 'endpoint-owner-a', 'EndpointOwner12!');
        $cookies = $session['cookies'];
        $items = endpoint_json(endpoint_http_request($baseUrl . '/api_inventory.php?action=list&status=SOLD', 'GET', null, [], $cookies));
        $item = $items['data'][0];
        assert_same('***', $item['cost_rmb']);
        $request = ['action'=>'save', 'id'=>$item['id'], 'row_version'=>$item['row_version'],
            'service_no'=>$item['service_no'], 'quantity'=>2, 'status'=>'CN_WH', 'receiver'=>'test-customer',
            'cost_rmb'=>0, 'freight'=>0, 'collected_amount'=>0, 'preserve_finance'=>'1'];
        assert_same(403, endpoint_form_request($baseUrl . '/api_inventory.php', $request, $cookies, $session['csrf'])['status']);
        assert_same('success', endpoint_json(endpoint_form_request($baseUrl . '/api_inventory.php', ['action'=>'verify_lock', 'pwd'=>''], $cookies, $session['csrf']))['status']);
        assert_same('success', endpoint_json(endpoint_form_request($baseUrl . '/api_inventory.php', $request, $cookies, $session['csrf']))['status']);
        $after = $pdo->query('SELECT cost_rmb,freight,collected_amount FROM inventory_items WHERE id=' . (int)$item['id'])->fetch();
        assert_same(['cost_rmb'=>'800.00', 'freight'=>'80.00', 'collected_amount'=>'1000.00'], $after);
    });
});
