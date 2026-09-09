<?php

/**
 * Phase 6b: the admin page, and the stylesheet Sassy compiles for herself.
 *
 * The stylesheet is authored for scssphp, the default engine, so a default install styles the
 * page. Dart is checked too when the binary is present, because that is what staging runs.
 */

require __DIR__ . '/bootstrap.php';

use Sassy\Compile_Request;
use Sassy\Dart_Sass_Engine;
use Sassy\Scssphp_Engine;

section('Sassy compiles her own stylesheet');

$scss = $GLOBALS['SASSY_PLUGIN'] . 'assets/scss/sassy.scss';

check('the source exists', file_exists($scss), $scss);

$request = function ($style) use ($scss) {
    return new Compile_Request([
        'source'      => (string) @file_get_contents($scss),
        'source_path' => $scss,
        'load_paths'  => [dirname($scss)],
        'variables'   => [],
        'style'       => $style,
        'source_map'  => false,
    ]);
};

$result = (new Scssphp_Engine())->compile($request('expanded'));

check('on scssphp, the default engine', $result->ok(), (string) $result->error);
check('with nothing to report',         $result->diagnostics === []);
check('and it styles the notice',       $result->css && str_contains($result->css, '#sassy-notice'));

if (!dart_available()) {

    skip('on Dart Sass, compressed as staging runs it', 'sass binary not installed');

} else {

    $dart = (new Dart_Sass_Engine('sass'))->compile($request('compressed'));

    check('on Dart Sass, compressed as staging runs it', $dart->ok(), (string) $dart->error);
    check('with nothing to report there either',        $dart->diagnostics === []);

}

finish();
