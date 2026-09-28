<?php
declare(strict_types=1);

require_once __DIR__ . '/api_endpoint_test.php';

function session_test_heartbeat(string $baseUrl, array &$session): array
{
    return endpoint_http_request($baseUrl . '/api_inventory.php?action=heartbeat', 'GET', null, [], $session['cookies']);
}

test('credential and employee permission changes revoke only obsolete sessions', function (): void {
    endpoint_database(function (PDO $pdo, string $baseUrl): void {
        $ownerA = endpoint_login($baseUrl, 'endpoint-owner-a', 'EndpointOwner12!');
        $ownerB = endpoint_login($baseUrl, 'endpoint-owner-a', 'EndpointOwner12!');
        $workerA = endpoint_login($baseUrl, 'endpoint-worker-a', 'EndpointOwner12!');
        $workerB = endpoint_login($baseUrl, 'endpoint-worker-a', 'EndpointOwner12!');

        $permissionChange = endpoint_json(endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action' => 'save_sub_account', 'sub_id' => 11, 'sub_user' => 'endpoint-worker-a',
            'p_hist' => 999, 'p_edt' => 1, 'p_del' => 1, 'p_dl' => 1, 'p_imp' => 1,
            'p_add' => 1, 'p_exp' => 1, 'p_us' => 1, 'p_transit' => 1, 'p_cn' => 1,
            'p_sold' => 1, 'p_parts' => 1, 'p_parts_sold' => 1, 'p_repair' => 1,
            'p_repair_done' => 1,
        ], $ownerA['cookies'], $ownerA['csrf']));
        assert_same('success', $permissionChange['status']);
        assert_same(401, session_test_heartbeat($baseUrl, $workerA)['status']);
        assert_same(401, session_test_heartbeat($baseUrl, $workerB)['status']);
        assert_same(200, session_test_heartbeat($baseUrl, $ownerB)['status']);

        $workerA = endpoint_login($baseUrl, 'endpoint-worker-a', 'EndpointOwner12!');
        $workerB = endpoint_login($baseUrl, 'endpoint-worker-a', 'EndpointOwner12!');
        $passwordChange = endpoint_json(endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action' => 'save_sub_account', 'sub_id' => 11, 'sub_user' => 'endpoint-worker-a',
            'sub_pass' => 'WorkerReplacement12!', 'p_hist' => 999, 'p_edt' => 1,
            'p_del' => 1, 'p_dl' => 1, 'p_imp' => 1, 'p_add' => 1, 'p_exp' => 1,
            'p_us' => 1, 'p_transit' => 1, 'p_cn' => 1, 'p_sold' => 1, 'p_parts' => 1,
            'p_parts_sold' => 1, 'p_repair' => 1, 'p_repair_done' => 1,
        ], $ownerA['cookies'], $ownerA['csrf']));
        assert_same('success', $passwordChange['status']);
        assert_same(401, session_test_heartbeat($baseUrl, $workerA)['status']);
        assert_same(401, session_test_heartbeat($baseUrl, $workerB)['status']);
        assert_same(200, session_test_heartbeat($baseUrl, $ownerB)['status']);

        $selfChange = endpoint_json(endpoint_form_request($baseUrl . '/api_inventory.php', [
            'action' => 'change_my_password', 'old_pwd' => 'EndpointOwner12!',
            'new_pwd' => 'OwnerReplacement12!',
        ], $ownerA['cookies'], $ownerA['csrf']));
        assert_same('success', $selfChange['status']);
        assert_same(200, session_test_heartbeat($baseUrl, $ownerA)['status']);
        assert_same(401, session_test_heartbeat($baseUrl, $ownerB)['status']);

        $ownerB = endpoint_login($baseUrl, 'endpoint-owner-a', 'OwnerReplacement12!');
        $pdo->prepare('UPDATE users SET sec_q1=?, sec_a1=?, sec_q2=?, sec_a2=?, sec_q3=?, sec_a3=? WHERE id=10')
            ->execute(['q1', password_hash('answer1', PASSWORD_DEFAULT), 'q2', password_hash('answer2', PASSWORD_DEFAULT), 'q3', password_hash('answer3', PASSWORD_DEFAULT)]);
        $recoveryCookies = [];
        $loginPage = endpoint_http_request($baseUrl . '/login.php', 'GET', null, [], $recoveryCookies);
        assert_true(preg_match('/name="_csrf_token" value="([a-f0-9]+)"/', $loginPage['body'], $csrfMatch) === 1);
        $recovery = endpoint_json(endpoint_form_request($baseUrl . '/login.php', [
            'api_action' => 'reset_pwd', '_csrf_token' => $csrfMatch[1],
            'username' => 'endpoint-owner-a', 'a1' => 'answer1', 'a2' => 'answer2',
            'a3' => 'answer3', 'new_pwd' => 'RecoveredOwner12!',
        ], $recoveryCookies));
        assert_same('success', $recovery['status']);
        assert_same(401, session_test_heartbeat($baseUrl, $ownerA)['status']);
        assert_same(401, session_test_heartbeat($baseUrl, $ownerB)['status']);
    });
});
