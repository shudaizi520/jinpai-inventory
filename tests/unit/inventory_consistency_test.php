<?php
declare(strict_types=1);

$consistencyPath = dirname(__DIR__, 2) . '/php/lib/inventory_consistency.php';
if (is_file($consistencyPath)) {
    require_once $consistencyPath;
}

test('inventory version maps require one strict positive version per selected id', function (): void {
    assert_true(function_exists('parse_version_map'), 'parse_version_map() is missing');
    assert_same([4 => 7, 9 => 12], parse_version_map('{"4":7,"9":12}', [4, 9]));

    foreach ([
        ['', [4]], ['not-json', [4]], ['[]', [4]], ['{"4":7}', [4, 9]],
        ['{"4":7,"9":12}', [4]], ['{"4":0}', [4]], ['{"4":-1}', [4]],
        ['{"4":"7"}', [4]], ['{"4":9223372036854775808}', [4]],
        ['{"4":7,"4":8}', [4]],
    ] as [$value, $ids]) {
        assert_throws(fn () => parse_version_map($value, $ids), HttpException::class);
    }
});

test('inventory stale version detection returns an http conflict', function (): void {
    assert_true(function_exists('require_expected_version'), 'require_expected_version() is missing');
    require_expected_version(['row_version' => 7], 7);
    try {
        require_expected_version(['row_version' => 8], 7);
        throw new TestFailure('Expected a stale version conflict');
    } catch (HttpException $exception) {
        assert_same(409, $exception->statusCode());
        assert_true(str_contains($exception->getMessage(), '刷新'));
    }
});
