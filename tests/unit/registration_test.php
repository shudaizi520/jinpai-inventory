<?php
declare(strict_types=1);

$registrationPath = dirname(__DIR__, 2) . '/php/lib/registration.php';
if (is_file($registrationPath)) {
    require_once $registrationPath;
}

test('registration mode validation accepts only supported modes', function (): void {
    assert_true(function_exists('registration_mode_is_valid'), 'registration_mode_is_valid() is missing');
    assert_true(registration_mode_is_valid('closed'));
    assert_true(registration_mode_is_valid('invite'));
    assert_true(registration_mode_is_valid('open'));
    assert_false(registration_mode_is_valid('public'));
    assert_false(registration_mode_is_valid(''));
});

test('invitation codes are normalized before hashing', function (): void {
    assert_true(function_exists('registration_code_hash'), 'registration_code_hash() is missing');
    assert_same(hash('sha256', 'inv-abc234'), registration_code_hash('  INV-ABC234  '));
});

test('generated invitation codes have high entropy and a readable alphabet', function (): void {
    assert_true(function_exists('generate_invitation_code'), 'generate_invitation_code() is missing');
    $first = generate_invitation_code();
    $second = generate_invitation_code();
    assert_matches('/^INV-[A-F0-9]{32}$/', $first);
    assert_false(hash_equals($first, $second));
});

test('registration availability hides the public entry point when closed', function (): void {
    assert_true(function_exists('registration_is_available'), 'registration_is_available() is missing');
    assert_false(registration_is_available('closed'));
    assert_true(registration_is_available('invite'));
    assert_true(registration_is_available('open'));
});
