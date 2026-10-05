<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/php/lib/auth.php';
require_once dirname(__DIR__, 2) . '/php/lib/authorization.php';

test('financial attempt keys cannot collide with valid login usernames or recovery keys', function (): void {
    assert_true(function_exists('financial_rate_key'), 'financial_rate_key is missing');
    assert_true(strlen(financial_rate_key(1)) > 50);
    assert_true(strlen(financial_rate_key(PHP_INT_MAX)) <= 100);
    assert_false(str_starts_with(financial_rate_key(1), 'recovery-account:'));
});

test('partial money allocation conserves cents including large and odd totals', function (): void {
    assert_true(function_exists('split_inventory_money'), 'split_inventory_money is missing');
    foreach ([['100.01',2,1,'50.01','50.00'], ['1.01',2,1,'0.51','0.50'],
        ['0.01',3,1,'0.00','0.01'], ['0',3,2,'0.00','0.00'],
        ['9999999999.99',100000,50000,'5000000000.00','4999999999.99'],
        ['10.01',3,3,'10.01','0.00']] as [$total,$qty,$dispatch,$sold,$remaining]) {
        assert_same(['dispatched'=>$sold,'remaining'=>$remaining], split_inventory_money($total,$qty,$dispatch));
    }
    assert_throws(fn () => split_inventory_money('1',0,1), RuntimeException::class);
    assert_throws(fn () => split_inventory_money('1',2,3), RuntimeException::class);
});

test('financial grants bind both employee and owner credentials and reject legacy flags', function (): void {
    assert_true(function_exists('grant_financial_unlock'), 'grant_financial_unlock is missing');
    $_SESSION=[];
    $owner=['id'=>10,'lock_password'=>'owner-hash'];
    $worker=['id'=>11,'parent_id'=>10,'lock_password'=>'worker-hash'];
    grant_financial_unlock($worker,$owner);
    assert_true(financial_session_is_unlocked($worker,$owner));
    assert_false(financial_session_is_unlocked($worker,array_replace($owner,['lock_password'=>'new-owner-hash'])));
    grant_financial_unlock($worker,$owner);
    assert_false(financial_session_is_unlocked(array_replace($worker,['lock_password'=>'new-worker-hash']),$owner));
    $_SESSION['finance_unlocked_11']=true;
    assert_false(financial_session_is_unlocked($worker,$owner));
    grant_financial_unlock($owner,$owner);
    clear_financial_unlock(10);
    assert_false(financial_session_is_unlocked($owner,$owner));
});
