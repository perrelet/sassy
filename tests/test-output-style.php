<?php

/**
 * sassy-style must work whether the filter returns an OutputStyle case or a string, on both
 * engines. An enum never equals a string, so the Dart Sass engine used to silently expand.
 */

require __DIR__ . '/bootstrap.php';

use Sassy\Dart_Sass_Engine;
use Sassy\Scssphp_Engine;
use ScssPhp\ScssPhp\OutputStyle;

$SCSS = WP_CONTENT_DIR . '/themes/t/scss';
$URL  = 'http://test.local/wp-content/themes/t/scss/entry.scss';

@mkdir($SCSS, 0777, true);

// @import rather than @use: scssphp v2 does not implement Sass modules.
fixture("$SCSS/_mix.scss", "\$pad: 4px;\n");
fixture("$SCSS/entry.scss", "@import 'mix';\n.a { padding: \$pad; }\n.b { margin: \$pad; }\n");

$GLOBALS['filter_overrides']['sassy-dart-sass-binary'] = 'sass';

function build ($engine, $style, $handle) {
    global $URL;
    $GLOBALS['transients'] = [];
    $GLOBALS['filter_overrides']['sassy-engine'] = $engine;

    if ($style === null) unset($GLOBALS['filter_overrides']['sassy-style']);
    else                 $GLOBALS['filter_overrides']['sassy-style'] = $style;

    $compiler = compile($URL, $handle);
    return $compiler->has_error() ? '!' . $compiler->get_error() : file_get_contents(WP_CONTENT_DIR . '/scss/entry.css');
}

$compressed = fn($css) => is_string($css) && !str_contains($css, "\n.b") && !str_contains($css, "{\n");

$engines = ['scssphp' => fn() => new Scssphp_Engine()];
if (dart_available()) $engines = ['Dart Sass' => fn() => new Dart_Sass_Engine()] + $engines;
else                  skip('Dart Sass', 'sass binary not installed');

foreach ($engines as $name => $make) {

    section($name);

    check('default is expanded',            !$compressed(build($make(), null, "d-$name")));
    check("string 'compressed' compresses",  $compressed(build($make(), 'compressed', "s-$name")));
    check('OutputStyle::COMPRESSED compresses', $compressed(build($make(), OutputStyle::COMPRESSED, "e-$name")));
    check('OutputStyle::EXPANDED expands',   !$compressed(build($make(), OutputStyle::EXPANDED, "x-$name")));

}

finish();
