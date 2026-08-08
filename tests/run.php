<?php

/**
 * Runs every tests/test-*.php in its own process and aggregates the result.
 *
 *   php tests/run.php                    # this checkout
 *   php tests/run.php /path/to/checkout  # another one, to confirm a fix fails before it
 */

$plugin = $argv[1] ?? dirname(__DIR__);
$tests  = glob(__DIR__ . '/test-*.php');

sort($tests);

$failed = [];

foreach ($tests as $test) {

    $name = basename($test, '.php');
    echo "\n\033[1m{$name}\033[0m";

    $output = [];
    $code   = 0;

    exec(sprintf('%s %s %s 2>&1', PHP_BINARY, escapeshellarg($test), escapeshellarg($plugin)), $output, $code);

    echo "\n" . implode("\n", $output) . "\n";

    if ($code !== 0) $failed[] = $name;

}

echo "\n" . str_repeat('-', 60) . "\n";

if ($failed) {
    echo count($failed) . " of " . count($tests) . " FAILED: " . implode(', ', $failed) . "\n";
    exit(1);
}

echo count($tests) . " test files passed\n";
exit(0);
