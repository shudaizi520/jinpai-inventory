<?php
declare(strict_types=1);

require_once __DIR__ . '/TestRunner.php';

$files = array_slice($argv, 1);
if ($files === []) {
    $files = glob(__DIR__ . '/unit/*_test.php') ?: [];
}

foreach ($files as $file) {
    $path = str_starts_with($file, '/') ? $file : dirname(__DIR__) . '/' . ltrim($file, '/');
    if (!is_file($path)) {
        fwrite(STDERR, "Test file not found: {$path}\n");
        exit(2);
    }
    require $path;
}

exit(run_tests());
