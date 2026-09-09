<?php

/**
 * The browser half, as far as node can reach it.
 *
 * assets/js/sassy.js has the worst record in the repository: three bugs in phase 6 alone, each
 * invisible to PHP and to a careful read. tests/js/harness.cjs boots the shipped file under a
 * minimal DOM and exercises it through the surface it exposes.
 *
 * Skips when node is absent, exactly as the Dart tests skip without the sass binary.
 */

require __DIR__ . '/bootstrap.php';

section('JavaScript');

if (!node_available()) {

    skip('sassy.js', 'node not installed');
    finish();

}

$harness = __DIR__ . '/js/harness.cjs';
$output  = [];
$code    = 0;

exec(sprintf('node %s %s 2>&1', escapeshellarg($harness), escapeshellarg($GLOBALS['SASSY_PLUGIN'])), $output, $code);

$raw     = trim(implode("\n", $output));
$results = json_decode($raw, true);

if (!is_array($results)) {

    check('the harness ran', false, $raw !== '' ? substr($raw, 0, 400) : 'no output');
    finish();

}

foreach ($results as $label => $passed) check($label, $passed);

finish();
