<?php

/**
 * Nothing in the compile path may call an admin-only function.
 *
 * wp_tempnam() lives in wp-admin/includes/file.php, which is not loaded on the frontend, so
 * reaching it there is a fatal error. This file deliberately never defines it.
 */

require __DIR__ . '/bootstrap.php';

use Sassy\Lightning_CSS_Postprocessor;
use Sassy\Dart_Sass_Engine;

assert(!function_exists('wp_tempnam'), 'the harness must simulate a frontend request');

function resolve_bin () {
    return invoke_protected('resolve_bin');
}

function invoke_protected ($name, ...$args) {
    $method = new ReflectionMethod(Lightning_CSS_Postprocessor::class, $name);
    $method->setAccessible(true);
    return $method->invoke(null, ...$args);
}

section('Lightning CSS is off unless configured');

check('resolve_bin() returns null rather than guessing at npx', resolve_bin() === null);

$css     = '.foo { color: red; }';
$asset   = new Sassy\Asset('h', 'http://test.local/x.scss');
$context = new Sassy\Post_Process_Context($asset);

check('a no-op on the frontend when nothing is configured',
    Lightning_CSS_Postprocessor::process($css, $context) === $css);
check('and it says nothing, because off is not a failure',
    $context->get_diagnostics() === []);

$GLOBALS['filter_overrides']['sassy-lightning-css-binary'] = '/nonexistent/lightningcss';
$broken = new Sassy\Post_Process_Context($asset);

check('a configured but broken binary degrades gracefully',
    Lightning_CSS_Postprocessor::process($css, $broken) === $css);
check('and reports rather than failing silently',
    count($broken->get_diagnostics()) === 1);
check('as a warning',
    ($broken->get_diagnostics()[0] ?? null) && $broken->get_diagnostics()[0]->severity === 'warning');

unset($GLOBALS['filter_overrides']['sassy-lightning-css-binary']);

section('Temp files');

$a = invoke_protected('temp_file', 'sassy-in-');
$b = invoke_protected('temp_file', 'sassy-in-');
check('created',                                      $a && file_exists($a));
check('unique, so concurrent requests cannot collide', $a !== $b);
@unlink($a); @unlink($b);

section('Dart Sass engine on a frontend request');

if (!dart_available()) {

    skip('compiles without wp_tempnam', 'sass binary not installed');

} else {

    $src = $GLOBALS['SASSY_ROOT'] . '/entry.scss';
    fixture($src, ".bar { padding: \$pad; }\n");

    $result = (new Dart_Sass_Engine('sass'))->compile(new Sassy\Compile_Request([
        'source'      => file_get_contents($src),
        'source_path' => $src,
        'load_paths'  => [dirname($src)],
        'variables'   => ['pad' => '8px'],
        'style'       => 'expanded',
        'source_map'  => true,
        'map_path'    => $GLOBALS['SASSY_ROOT'] . '/out.css.map',
        'map_url'     => 'http://test.local/out.css.map',
    ]));

    check('compiles without calling wp_tempnam', $result->ok(), (string) $result->error);
    check('injected variable reached the output', $result->css && str_contains($result->css, '8px'));
    check('source map produced',                  !empty($result->map));

}

finish();
