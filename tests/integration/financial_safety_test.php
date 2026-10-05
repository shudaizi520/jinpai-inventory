<?php
declare(strict_types=1);
require_once __DIR__ . '/api_endpoint_test.php';

function finance_post(string $url, array &$session, array $data): array {
    return endpoint_form_request($url.'/api_inventory.php',$data,$session['cookies'],$session['csrf']);
}
function finance_read(string $url, array &$session, string $action='list&status=US&unlocked=1'): array {
    return endpoint_json(endpoint_http_request($url.'/api_inventory.php?action='.$action,'GET','',[],$session['cookies']));
}
function finance_seed(PDO $pdo): void {
    $pdo->prepare('UPDATE users SET lock_tab_us=1, lock_password=? WHERE id=10')->execute([password_hash('VaultOriginal12!',PASSWORD_DEFAULT)]);
}

test('financial password attempts are bounded across sessions without blocking other tenants or login', function (): void {
    endpoint_database(function(PDO $pdo,string $url): void {
        finance_seed($pdo);
        $a=endpoint_login($url,'endpoint-owner-a','EndpointOwner12!');
        $b=endpoint_login($url,'endpoint-owner-a','EndpointOwner12!');
        for($i=1;$i<=5;$i++) {
            $r=finance_post($url,$a,['action'=>'verify_lock','pwd'=>'wrong']);
            assert_same($i===5?429:403,$r['status']);
        }
        assert_same(429,finance_post($url,$b,['action'=>'verify_lock','pwd'=>'VaultOriginal12!'])['status']);
        $other=endpoint_login($url,'endpoint-owner-b','EndpointOwner12!');
        assert_same('success',endpoint_json(finance_post($url,$other,['action'=>'verify_lock','pwd'=>'']))['status']);
        endpoint_login($url,'endpoint-owner-a','EndpointOwner12!');
        $pdo->prepare('UPDATE login_blocks SET lock_until=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE identifier=?')->execute([financial_rate_key(10)]);
        assert_same('success',endpoint_json(finance_post($url,$b,['action'=>'verify_lock','pwd'=>'VaultOriginal12!']))['status']);
        $q=$pdo->prepare('SELECT COUNT(*) FROM login_blocks WHERE identifier=?'); $q->execute([financial_rate_key(10)]);
        assert_same(0,(int)$q->fetchColumn());
    });
});

test('unrelated finance-prefixed usernames cannot clear another tenant financial attempts', function (): void {
    endpoint_database(function(PDO $pdo,string $url): void {
        finance_seed($pdo);
        $pdo->prepare('INSERT INTO users (username,password,parent_id,role) VALUES (?,?,0,?)')
            ->execute(['finance-account:10',password_hash('EndpointOwner12!',PASSWORD_DEFAULT),'user']);
        $s=endpoint_login($url,'endpoint-owner-a','EndpointOwner12!');
        for($i=0;$i<4;$i++) assert_same(403,finance_post($url,$s,['action'=>'verify_lock','pwd'=>'wrong'])['status']);
        endpoint_login($url,'finance-account:10','EndpointOwner12!');
        assert_same(429,finance_post($url,$s,['action'=>'verify_lock','pwd'=>'wrong'])['status']);
    });
});

test('all financial password entry points share the same attempt limit', function (): void {
    endpoint_database(function(PDO $pdo,string $url): void {
        finance_seed($pdo);
        $s=endpoint_login($url,'endpoint-owner-a','EndpointOwner12!');
        $requests=[['action'=>'verify_lock','pwd'=>'wrong'],['action'=>'set_lock','old_lock'=>'wrong','new_pwd'=>'Changed12!'],
            ['action'=>'update_master_tabs','lock_pwd'=>'wrong'],
            ['action'=>'save_sub_account','sub_user'=>'endpoint-worker-a','sub_id'=>11,'p_fin'=>1,'lock_pwd'=>'wrong']];
        foreach($requests as $data) assert_same(403,finance_post($url,$s,$data)['status']);
        assert_same(429,finance_post($url,$s,$requests[0])['status']);
        foreach($requests as $data) assert_same(429,finance_post($url,$s,$data)['status']);
    });
});

test('owner password change revokes other device grants while normal login remains valid', function (): void {
    endpoint_database(function(PDO $pdo,string $url): void {
        finance_seed($pdo);
        $a=endpoint_login($url,'endpoint-owner-a','EndpointOwner12!');
        $b=endpoint_login($url,'endpoint-owner-a','EndpointOwner12!');
        assert_same('success',endpoint_json(finance_post($url,$a,['action'=>'verify_lock','pwd'=>'VaultOriginal12!']))['status']);
        assert_same('10.00',finance_read($url,$a)['data'][0]['cost_rmb']);
        assert_same('success',endpoint_json(finance_post($url,$b,['action'=>'set_lock','old_lock'=>'VaultOriginal12!','new_pwd'=>'VaultChanged12!']))['status']);
        assert_same(false,finance_read($url,$a,'check_update')['finance_unlocked']??null);
        assert_same('***',finance_read($url,$a)['data'][0]['cost_rmb']);
        $item=$pdo->query("SELECT * FROM inventory_items WHERE service_no='OWNER-A-ITEM'")->fetch();
        assert_same('error',endpoint_json(finance_post($url,$a,['action'=>'delete','id'=>$item['id'],'row_version'=>$item['row_version']]))['status']);
        assert_same('success',finance_read($url,$a,'heartbeat')['status']);
        assert_same('success',endpoint_json(finance_post($url,$a,['action'=>'verify_lock','pwd'=>'VaultChanged12!']))['status']);
        assert_same('10.00',finance_read($url,$a)['data'][0]['cost_rmb']);
    });
});

test('owner changes revoke employee own-vault grants and employee recovery revokes other devices', function (): void {
    endpoint_database(function(PDO $pdo,string $url): void {
        finance_seed($pdo);
        $pdo->prepare('UPDATE users SET perm_finance=1, lock_password=? WHERE id=11')->execute([password_hash('WorkerVault12!',PASSWORD_DEFAULT)]);
        $owner=endpoint_login($url,'endpoint-owner-a','EndpointOwner12!');
        $a=endpoint_login($url,'endpoint-worker-a','EndpointOwner12!');
        $b=endpoint_login($url,'endpoint-worker-a','EndpointOwner12!');
        assert_same('success',endpoint_json(finance_post($url,$a,['action'=>'verify_lock','pwd'=>'WorkerVault12!']))['status']);
        assert_same('10.00',finance_read($url,$a)['data'][0]['cost_rmb']);
        assert_same('success',endpoint_json(finance_post($url,$owner,['action'=>'set_lock','old_lock'=>'VaultOriginal12!','new_pwd'=>'NewOwner12!']))['status']);
        assert_same('***',finance_read($url,$a)['data'][0]['cost_rmb']);
        finance_post($url,$a,['action'=>'verify_lock','pwd'=>'WorkerVault12!']);
        assert_same('success',endpoint_json(finance_post($url,$b,['action'=>'reset_sub_lock','login_pwd'=>'EndpointOwner12!','new_lock'=>'NewWorker12!']))['status']);
        assert_same('***',finance_read($url,$a)['data'][0]['cost_rmb']);
        assert_same('success',endpoint_json(finance_post($url,$a,['action'=>'verify_lock','pwd'=>'NewWorker12!']))['status']);
    });
});

test('owner recovery revokes grants and legacy plaintext unlock upgrades remain valid', function (): void {
    endpoint_database(function(PDO $pdo,string $url): void {
        $pdo->exec("UPDATE users SET lock_tab_us=1,lock_password='LegacyVault12!',sec_a1='a',sec_a2='b',sec_a3='c' WHERE id=10");
        $a=endpoint_login($url,'endpoint-owner-a','EndpointOwner12!');
        $b=endpoint_login($url,'endpoint-owner-a','EndpointOwner12!');
        assert_same('success',endpoint_json(finance_post($url,$a,['action'=>'verify_lock','pwd'=>'LegacyVault12!']))['status']);
        assert_true(password_verify('LegacyVault12!',$pdo->query('SELECT lock_password FROM users WHERE id=10')->fetchColumn()));
        assert_same('10.00',finance_read($url,$a)['data'][0]['cost_rmb']);
        assert_same('success',endpoint_json(finance_post($url,$b,['action'=>'reset_lock_with_sec','login_pwd'=>'EndpointOwner12!','a1'=>'a','a2'=>'b','a3'=>'c','new_lock'=>'Recovered12!']))['status']);
        assert_same('***',finance_read($url,$a)['data'][0]['cost_rmb']);
    });
});

test('partial parts dispatch conserves cost and freight cents in database and audit snapshots', function (): void {
    endpoint_database(function(PDO $pdo,string $url): void {
        $pdo->exec("UPDATE inventory_items SET quantity=2,cost_rmb=100.01,freight=1.01,collected_amount=2.01 WHERE service_no='OWNER-A-PART'");
        $s=endpoint_login($url,'endpoint-owner-a','EndpointOwner12!');
        $item=$pdo->query("SELECT * FROM inventory_items WHERE service_no='OWNER-A-PART'")->fetch();
        assert_same('success',endpoint_json(finance_post($url,$s,['action'=>'dispatch_part','id'=>$item['id'],'row_version'=>1,'dispatch_qty'=>1,'receiver'=>'test-customer','unit_collected'=>'20.01']))['status']);
        $sum=$pdo->query("SELECT SUM(cost_rmb) cost,SUM(freight) freight,SUM(quantity) qty FROM inventory_items WHERE service_no='OWNER-A-PART'")->fetch();
        assert_same('100.01',$sum['cost']); assert_same('1.01',$sum['freight']); assert_same(2,(int)$sum['qty']);
        $remaining=$pdo->query('SELECT * FROM inventory_items WHERE id='.(int)$item['id'])->fetch();
        assert_same('50.00',$remaining['cost_rmb']); assert_same('0.50',$remaining['freight']); assert_same('1.00',$remaining['collected_amount']);
        assert_same(2,(int)$remaining['row_version']);
        $events=$pdo->query("SELECT * FROM audit_events WHERE tenant_id=10 AND entity_type='inventory' ORDER BY id")->fetchAll();
        assert_same(2,count($events));
        assert_same('50.00',json_decode($events[0]['after_json'],true)['cost_rmb']);
        assert_same('50.01',json_decode($events[1]['after_json'],true)['cost_rmb']);
        assert_same('success',endpoint_json(finance_post($url,$s,['action'=>'dispatch_part','id'=>$item['id'],'row_version'=>2,'dispatch_qty'=>1,'receiver'=>'test-customer','unit_collected'=>'20.01']))['status']);
        assert_same('100.01',$pdo->query("SELECT SUM(cost_rmb) FROM inventory_items WHERE service_no='OWNER-A-PART'")->fetchColumn());
    });
});
