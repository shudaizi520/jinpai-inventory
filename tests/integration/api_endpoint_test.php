<?php
declare(strict_types=1);

$migrationPath = dirname(__DIR__, 2) . '/php/lib/migrations.php';
if (is_file($migrationPath)) {
    require_once $migrationPath;
}

/** @return array{status:int,body:string,headers:list<string>} */
function endpoint_http_request(string $url, string $method, ?string $body, array $headers, array &$cookies): array
{
    if ($cookies !== []) {
        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 5,
        ],
    ]);
    $responseBody = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    if (isset($responseHeaders[0]) && preg_match('/\s([0-9]{3})\s/', $responseHeaders[0], $match) === 1) {
        $status = (int) $match[1];
    }
    foreach ($responseHeaders as $header) {
        if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $header, $match) === 1) {
            $cookies[$match[1]] = $match[2];
        }
    }
    return ['status' => $status, 'body' => is_string($responseBody) ? $responseBody : '', 'headers' => $responseHeaders];
}

function endpoint_form_request(string $url, array $data, array &$cookies, ?string $csrf = null): array
{
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    if ($csrf !== null) {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }
    return endpoint_http_request($url, 'POST', http_build_query($data), $headers, $cookies);
}

function endpoint_json(array $response): array
{
    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded)) {
        throw new TestFailure('Expected JSON response, got HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 200));
    }
    return $decoded;
}

/** @return array{cookies:array<string,string>,csrf:string} */
function endpoint_login(string $baseUrl, string $username, string $password): array
{
    $cookies = [];
    $loginPage = endpoint_http_request($baseUrl . '/login.php', 'GET', null, [], $cookies);
    assert_same(200, $loginPage['status']);
    assert_true(preg_match('/name="_csrf_token" value="([a-f0-9]+)"/', $loginPage['body'], $csrfMatch) === 1);
    $login = endpoint_form_request($baseUrl . '/login.php', [
        '_csrf_token' => $csrfMatch[1],
        'username' => $username,
        'password' => $password,
    ], $cookies);
    assert_same(302, $login['status']);
    $index = endpoint_http_request($baseUrl . '/index.php', 'GET', null, [], $cookies);
    assert_same(200, $index['status']);
    assert_true(preg_match('/const CSRF_TOKEN = "([a-f0-9]+)";/', $index['body'], $sessionCsrf) === 1);
    return ['cookies' => $cookies, 'csrf' => $sessionCsrf[1]];
}

function endpoint_database(callable $test, bool $seedFixtures = true): void
{
    $dsn = getenv('TEST_DB_DSN');
    if (!is_string($dsn) || $dsn === '') {
        throw new TestFailure('TEST_DB_DSN is required for endpoint integration tests');
    }
    preg_match('/host=([^;]+)/', $dsn, $hostMatch);
    preg_match('/port=([^;]+)/', $dsn, $portMatch);
    $host = $hostMatch[1] ?? '127.0.0.1';
    $port = $portMatch[1] ?? '3306';
    $adminUser = getenv('TEST_DB_USER') ?: 'root';
    $adminPassword = getenv('TEST_DB_PASSWORD') ?: '';
    $admin = new PDO($dsn, $adminUser, $adminPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $suffix = getmypid() . '_' . bin2hex(random_bytes(4));
    $database = 'inventory_endpoint_' . $suffix;
    $databaseUser = 'endpoint_' . substr(hash('sha256', $suffix), 0, 12);
    $databasePassword = 'EndpointDatabase12!';
    $admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $admin->exec("CREATE USER '{$databaseUser}'@'%' IDENTIFIED BY '{$databasePassword}'");
    $admin->exec("GRANT ALL PRIVILEGES ON `{$database}`.* TO '{$databaseUser}'@'%'");

    $databaseDsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
    $pdo = new PDO($databaseDsn, $databaseUser, $databasePassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    run_migrations($pdo);

    $foreignId = 0;
    $foreignPartId = 0;
    if ($seedFixtures) {
        $passwordHash = password_hash('EndpointOwner12!', PASSWORD_DEFAULT);
        $insertUser = $pdo->prepare("INSERT INTO users (id, username, password, role, parent_id, lock_tab_sold, lock_tab_parts_sold) VALUES (?, ?, ?, 'user', ?, 0, 0)");
        $insertUser->execute([10, 'endpoint-owner-a', $passwordHash, 0]);
        $insertUser->execute([11, 'endpoint-worker-a', $passwordHash, 10]);
        $insertUser->execute([20, 'endpoint-owner-b', $passwordHash, 0]);
        $insertUser->execute([21, 'endpoint-worker-b', $passwordHash, 20]);
        $pdo->exec('UPDATE users SET perm_finance = 0 WHERE id = 11');
        $insertItem = $pdo->prepare("INSERT INTO inventory_items (user_id, service_no, quantity, status, cost_rmb, freight, collected_amount) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insertItem->execute([10, 'OWNER-A-ITEM', 1, 'US', 10, 1, 100]);
        $insertItem->execute([10, 'OWNER-A-PART', 3, 'PARTS', 300, 30, 0]);
        $insertItem->execute([10, 'OWNER-A-SOLD', 1, 'SOLD', 400, 40, 500]);
        $ownerSoldId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE inventory_items SET receiver = ? WHERE id = ?')->execute(['existing-customer', $ownerSoldId]);
        $insertItem->execute([20, 'OWNER-B-ITEM', 1, 'US', 900, 90, 1200]);
        $foreignId = (int) $pdo->lastInsertId();
        $insertItem->execute([20, 'OWNER-B-PART', 3, 'PARTS', 300, 30, 0]);
        $foreignPartId = (int) $pdo->lastInsertId();
    }

    $serverCommand = trim((string) (getenv('TEST_PHP_SERVER_COMMAND') ?: PHP_BINARY));
    $command = preg_split('/\s+/', $serverCommand) ?: [PHP_BINARY];
    $serverPort = random_int(20000, 45000);
    $sessionDirectory = sys_get_temp_dir() . '/inventory-endpoint-sessions-' . $suffix;
    mkdir($sessionDirectory, 0700, true);
    array_push($command, '-d', 'session.save_path=' . $sessionDirectory, '-S', '127.0.0.1:' . $serverPort, '-t', dirname(__DIR__, 2) . '/php');
    $logFile = tempnam(sys_get_temp_dir(), 'inventory-endpoint-server-');
    $environment = getenv();
    $environment['DB_HOST'] = $host;
    $environment['DB_PORT'] = $port;
    $environment['DB_NAME'] = $database;
    $environment['DB_USER'] = $databaseUser;
    $environment['DB_PASSWORD'] = $databasePassword;
    $environment['APP_TIMEZONE'] = 'UTC';
    $environment['SESSION_COOKIE_SECURE'] = 'false';
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['file', $logFile, 'a'],
        2 => ['file', $logFile, 'a'],
    ], $pipes, dirname(__DIR__, 2), $environment);

    try {
        if (!is_resource($process)) {
            throw new TestFailure('Could not start PHP endpoint server');
        }
        fclose($pipes[0]);
        $baseUrl = 'http://127.0.0.1:' . $serverPort;
        $ready = false;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $probeCookies = [];
            $probe = endpoint_http_request($baseUrl . '/health.php', 'GET', null, [], $probeCookies);
            if ($probe['status'] === 200 && trim($probe['body']) === 'ok') {
                $ready = true;
                break;
            }
            usleep(100000);
        }
        if (!$ready) {
            throw new TestFailure('Endpoint server did not become healthy; last response was HTTP '
                . ($probe['status'] ?? 0) . ' with body ' . var_export($probe['body'] ?? null, true)
                . ': ' . (string) file_get_contents($logFile));
        }
        $test($pdo, $baseUrl, $foreignId, $foreignPartId);
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        if (is_file($logFile)) {
            unlink($logFile);
        }
        foreach (glob($sessionDirectory . '/*') ?: [] as $sessionFile) {
            unlink($sessionFile);
        }
        rmdir($sessionDirectory);
        $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
        $admin->exec("DROP USER IF EXISTS '{$databaseUser}'@'%'");
    }
}

test('fresh http installation guides the owner through one-time administrator setup', function (): void {
    endpoint_database(function (PDO $pdo, string $baseUrl): void {
        $cookies = [];
        $login = endpoint_http_request($baseUrl . '/login.php', 'GET', null, [], $cookies);
        assert_same(302, $login['status']);
        assert_true(in_array('Location: register.php', $login['headers'], true));

        $setup = endpoint_http_request($baseUrl . '/register.php', 'GET', null, [], $cookies);
        assert_same(200, $setup['status']);
        assert_true(str_contains($setup['body'], '初始化系统管理员'));
        assert_true(str_contains($setup['body'], 'name="form_action" value="initial_admin_setup"'));
        assert_true(preg_match('/name="_csrf_token" value="([a-f0-9]+)"/', $setup['body'], $csrfMatch) === 1);

        $setupInput = [
            'form_action' => 'initial_admin_setup',
            'username' => 'browser-admin',
            'password' => 'BrowserAdminPassword12!',
            'password_confirm' => 'BrowserAdminPassword12!',
            'q1' => '问题一', 'a1' => '答案一',
            'q2' => '问题二', 'a2' => '答案二',
            'q3' => '问题三', 'a3' => '答案三',
        ];

        $missingCsrf = endpoint_form_request($baseUrl . '/register.php', $setupInput, $cookies);
        assert_same(403, $missingCsrf['status']);
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());

        $wrongCsrf = endpoint_form_request($baseUrl . '/register.php', $setupInput, $cookies, str_repeat('0', 64));
        assert_same(403, $wrongCsrf['status']);
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());

        $created = endpoint_form_request($baseUrl . '/register.php', $setupInput, $cookies, $csrfMatch[1]);
        assert_same(200, $created['status']);
        assert_true(str_contains($created['body'], '管理员创建成功'));
        assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'browser-admin' AND role = 'admin' AND parent_id = 0")->fetchColumn());

        $staleSetup = endpoint_form_request($baseUrl . '/register.php', $setupInput, $cookies, $csrfMatch[1]);
        assert_same(302, $staleSetup['status']);
        assert_true(in_array('Location: login.php', $staleSetup['headers'], true));
        assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());

        $registration = endpoint_http_request($baseUrl . '/register.php', 'GET', null, [], $cookies);
        assert_same(200, $registration['status']);
        assert_false(str_contains($registration['body'], '初始化系统管理员'));
        assert_true(str_contains($registration['body'], '邀请码'));

        endpoint_login($baseUrl, 'browser-admin', 'BrowserAdminPassword12!');
    }, false);
});

test('http inventory and employee mutations cannot cross tenant boundaries', function (): void {
    endpoint_database(function (PDO $pdo, string $baseUrl, int $foreignId, int $foreignPartId): void {
        $session = endpoint_login($baseUrl, 'endpoint-owner-a', 'EndpointOwner12!');
        $cookies = $session['cookies'];
        $csrf = $session['csrf'];

        $list = endpoint_json(endpoint_http_request($baseUrl . '/api_inventory.php?action=list&status=US&page=1&limit=200', 'GET', null, [], $cookies));
        assert_same('success', $list['status']);
        assert_same(1, count($list['data']));
        assert_same('OWNER-A-ITEM', $list['data'][0]['service_no']);

        $mutations = [
            ['action' => 'save', 'id' => $foreignId, 'service_no' => 'OWNER-B-ITEM', 'quantity' => 1, 'status' => 'US', 'cost_rmb' => 1, 'freight' => 1, 'collected_amount' => 1],
            ['action' => 'update_status', 'id' => $foreignId, 'status' => 'CN_WH'],
            ['action' => 'delete', 'id' => $foreignId],
            ['action' => 'batch_update_status', 'ids' => (string) $foreignId, 'status' => 'CN_WH'],
            ['action' => 'batch_edit', 'ids' => (string) $foreignId, 'update_remarks' => '1', 'remarks' => 'tampered'],
            ['action' => 'batch_delete', 'ids' => (string) $foreignId],
            ['action' => 'dispatch_part', 'id' => $foreignPartId, 'dispatch_qty' => 1, 'receiver' => 'attacker', 'unit_collected' => 1],
            ['action' => 'save_sub_account', 'sub_id' => 21, 'sub_user' => 'tampered-worker', 'p_hist' => 999],
            ['action' => 'delete_sub_account', 'sub_id' => 21],
        ];
        foreach ($mutations as $mutation) {
            $response = endpoint_form_request($baseUrl . '/api_inventory.php', $mutation, $cookies, $csrf);
            $payload = endpoint_json($response);
            assert_same('error', $payload['status'], 'Cross-tenant action unexpectedly succeeded: ' . $mutation['action']);
        }

        $soldId = (int) $pdo->query("SELECT id FROM inventory_items WHERE user_id = 10 AND service_no = 'OWNER-A-SOLD'")->fetchColumn();
        $partId = (int) $pdo->query("SELECT id FROM inventory_items WHERE user_id = 10 AND service_no = 'OWNER-A-PART'")->fetchColumn();
        $invalidSoldEdits = [
            ['action' => 'batch_edit', 'ids' => (string) $soldId, 'update_receiver' => '1', 'receiver' => ''],
            ['action' => 'batch_edit', 'ids' => (string) $soldId, 'update_collected_amount' => '1', 'unit_collected' => 0],
        ];
        foreach ($invalidSoldEdits as $mutation) {
            $payload = endpoint_json(endpoint_form_request($baseUrl . '/api_inventory.php', $mutation, $cookies, $csrf));
            assert_same('error', $payload['status'], 'Invalid sold-record edit unexpectedly succeeded');
        }
        $zeroValueDispatch = endpoint_json(endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action' => 'dispatch_part',
            'id' => $partId,
            'dispatch_qty' => 1,
            'receiver' => 'customer',
            'unit_collected' => 0,
        ], $cookies, $csrf));
        assert_same('error', $zeroValueDispatch['status']);

        $importRows = json_encode([[
            '服务编号' => 'OWNER-B-ITEM',
            '数量' => 1,
            '状态' => '美国',
            '成本' => 1,
        ]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $import = endpoint_json(endpoint_http_request(
            $baseUrl . '/api_inventory.php?action=import',
            'POST',
            $importRows,
            ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf],
            $cookies
        ));
        assert_same('success', $import['status']);

        $foreign = $pdo->query("SELECT service_no, status, cost_rmb, freight, collected_amount FROM inventory_items WHERE id = {$foreignId}")->fetch();
        assert_same('OWNER-B-ITEM', $foreign['service_no']);
        assert_same('US', $foreign['status']);
        assert_same('900.00', $foreign['cost_rmb']);
        assert_same('90.00', $foreign['freight']);
        assert_same('1200.00', $foreign['collected_amount']);
        $sold = $pdo->query("SELECT receiver, collected_amount FROM inventory_items WHERE id = {$soldId}")->fetch();
        assert_same('existing-customer', $sold['receiver']);
        assert_same('500.00', $sold['collected_amount']);
        assert_same(3, (int) $pdo->query("SELECT quantity FROM inventory_items WHERE id = {$partId}")->fetchColumn());
        assert_same('endpoint-worker-b', $pdo->query('SELECT username FROM users WHERE id = 21')->fetchColumn());
        assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE user_id = 10 AND service_no = 'OWNER-B-ITEM'")->fetchColumn());
    });
});

test('http restricted employees cannot overwrite masked financial values', function (): void {
    endpoint_database(function (PDO $pdo, string $baseUrl): void {
        $session = endpoint_login($baseUrl, 'endpoint-worker-a', 'EndpointOwner12!');
        $cookies = $session['cookies'];
        $csrf = $session['csrf'];
        $itemId = (int) $pdo->query("SELECT id FROM inventory_items WHERE user_id = 10 AND service_no = 'OWNER-A-ITEM'")->fetchColumn();
        $partId = (int) $pdo->query("SELECT id FROM inventory_items WHERE user_id = 10 AND service_no = 'OWNER-A-PART'")->fetchColumn();

        $list = endpoint_json(endpoint_http_request($baseUrl . '/api_inventory.php?action=list&status=US&page=1&limit=200', 'GET', null, [], $cookies));
        assert_same('***', $list['data'][0]['cost_rmb']);
        assert_same('***', $list['data'][0]['freight']);
        assert_same('***', $list['data'][0]['collected_amount']);

        $save = endpoint_json(endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action' => 'save',
            'id' => $itemId,
            'service_no' => 'OWNER-A-ITEM',
            'quantity' => 2,
            'status' => 'US',
            'cost_rmb' => 0,
            'freight' => 0,
            'collected_amount' => 0,
        ], $cookies, $csrf));
        assert_same('success', $save['status']);

        $batch = endpoint_json(endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action' => 'batch_edit',
            'ids' => (string) $itemId,
            'update_collected_amount' => '1',
            'unit_collected' => 1,
        ], $cookies, $csrf));
        assert_same('error', $batch['status']);

        $importRows = json_encode([[
            '服务编号' => 'OWNER-A-ITEM',
            '数量' => 2,
            '状态' => '美国',
            '成本' => 1,
            '运费' => 1,
            '收款金额' => 1,
        ]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $import = endpoint_json(endpoint_http_request(
            $baseUrl . '/api_inventory.php?action=import',
            'POST',
            $importRows,
            ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf],
            $cookies
        ));
        assert_same('success', $import['status']);

        $dispatch = endpoint_json(endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action' => 'dispatch_part',
            'id' => $partId,
            'dispatch_qty' => 1,
            'receiver' => 'customer',
            'unit_collected' => 1,
        ], $cookies, $csrf));
        assert_same('error', $dispatch['status']);

        $row = $pdo->query("SELECT quantity, cost_rmb, freight, collected_amount FROM inventory_items WHERE id = {$itemId}")->fetch();
        assert_same(2, (int) $row['quantity']);
        assert_same('20.00', $row['cost_rmb']);
        assert_same('2.00', $row['freight']);
        assert_same('200.00', $row['collected_amount']);
        assert_same(3, (int) $pdo->query("SELECT quantity FROM inventory_items WHERE id = {$partId}")->fetchColumn());
    });
});
