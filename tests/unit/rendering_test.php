<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/php/lib/security.php';

test('server rendered text escapes html and javascript payloads', function (): void {
    $payload = '<img src=x onerror=alert(1)>"\'';
    $escaped = e($payload);
    assert_false(str_contains($escaped, '<img'));
    assert_true(str_contains($escaped, '&lt;img'));
    assert_true(str_contains($escaped, '&quot;'));
    assert_true(str_contains($escaped, '&#039;'));
    assert_same('库', first_character('库存'));
});

test('dynamic api errors and usernames are not interpolated into html sinks', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2) . '/php/index.php');
    assert_true(is_string($source));
    assert_false(str_contains($source, '${j.message}</td>'), 'API error is inserted into innerHTML');
    assert_false(str_contains($source, '${u.username}</td>'), 'Employee username is inserted into innerHTML');
    assert_false(str_contains($source, '${msg}`'), 'Toast message is inserted into innerHTML');
});

test('authentication pages do not load a remote background image', function (): void {
    $login = file_get_contents(dirname(__DIR__, 2) . '/php/login.php');
    $register = file_get_contents(dirname(__DIR__, 2) . '/php/register.php');
    assert_false(str_contains((string) $login, 'images.unsplash.com'));
    assert_false(str_contains((string) $register, 'images.unsplash.com'));
});
