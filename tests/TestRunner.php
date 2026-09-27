<?php
declare(strict_types=1);

final class TestFailure extends RuntimeException {}

$GLOBALS['test_cases'] = [];

function test(string $name, callable $test): void
{
    $GLOBALS['test_cases'][] = [$name, $test];
}

function assert_true(bool $condition, string $message = 'Expected condition to be true'): void
{
    if (!$condition) {
        throw new TestFailure($message);
    }
}

function assert_false(bool $condition, string $message = 'Expected condition to be false'): void
{
    assert_true(!$condition, $message);
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $detail = sprintf('Expected %s, got %s', var_export($expected, true), var_export($actual, true));
        throw new TestFailure($message === '' ? $detail : $message . ': ' . $detail);
    }
}

function assert_matches(string $pattern, string $actual, string $message = ''): void
{
    if (preg_match($pattern, $actual) !== 1) {
        throw new TestFailure($message === '' ? "Value did not match {$pattern}" : $message);
    }
}

function assert_throws(callable $operation, string $exceptionClass, string $message = ''): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        if ($error instanceof $exceptionClass) {
            return;
        }
        throw new TestFailure(sprintf('Expected %s, got %s', $exceptionClass, $error::class));
    }

    throw new TestFailure($message === '' ? "Expected {$exceptionClass} to be thrown" : $message);
}

function run_tests(): int
{
    $passed = 0;
    $failed = 0;

    foreach ($GLOBALS['test_cases'] as [$name, $test]) {
        try {
            $test();
            $passed++;
            fwrite(STDOUT, "PASS {$name}\n");
        } catch (Throwable $error) {
            $failed++;
            fwrite(STDOUT, "FAIL {$name}: {$error->getMessage()}\n");
        }
    }

    fwrite(STDOUT, sprintf("\n%d passed, %d failed\n", $passed, $failed));
    return $failed === 0 ? 0 : 1;
}
