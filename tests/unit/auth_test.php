<?php
declare(strict_types=1);

$authPath = dirname(__DIR__, 2) . '/php/lib/auth.php';
if (is_file($authPath)) {
    require_once $authPath;
}

test('api method policy rejects GET mutations and allows read actions', function (): void {
    assert_true(function_exists('enforce_api_action_method'), 'enforce_api_action_method() is missing');
    enforce_api_action_method('list', 'GET');
    enforce_api_action_method('save', 'POST');
    assert_throws(fn () => enforce_api_action_method('save', 'GET'), RuntimeException::class);
    assert_throws(fn () => enforce_api_action_method('unknown', 'GET'), RuntimeException::class);
});

test('authenticated session setup rotates the identifier and stores the current account', function (): void {
    assert_true(function_exists('establish_authenticated_session'), 'establish_authenticated_session() is missing');
    $_SESSION = ['old' => 'value'];
    $rotated = false;
    establish_authenticated_session([
        'id' => 9,
        'username' => 'worker',
        'role' => 'user',
        'parent_id' => 4,
    ], function (bool $deleteOld) use (&$rotated): bool {
        $rotated = $deleteOld;
        return true;
    });
    assert_true($rotated);
    assert_false(isset($_SESSION['old']));
    assert_true($_SESSION['is_logged_in']);
    assert_same(9, (int) $_SESSION['user_id']);
    assert_same(4, (int) $_SESSION['parent_id']);
});

test('logout clears all server-side session values', function (): void {
    assert_true(function_exists('perform_logout'), 'perform_logout() is missing');
    $_SESSION = ['user_id' => 9, '_csrf_token' => 'token'];
    perform_logout(false);
    assert_same([], $_SESSION);
});

test('legacy plaintext secrets verify once and request a hash upgrade', function (): void {
    assert_true(function_exists('verify_stored_secret'), 'verify_stored_secret() is missing');
    $legacy = verify_stored_secret('old-secret', 'old-secret');
    assert_true($legacy['valid']);
    assert_true(is_string($legacy['upgrade_hash']));
    assert_true(password_verify('old-secret', $legacy['upgrade_hash']));

    $hash = password_hash('StrongLogin12!', PASSWORD_DEFAULT);
    $modern = verify_stored_secret('StrongLogin12!', $hash);
    assert_true($modern['valid']);
    assert_same(null, $modern['upgrade_hash']);
    assert_false(verify_stored_secret('wrong', $hash)['valid']);
});
