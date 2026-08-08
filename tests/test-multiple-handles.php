<?php

/**
 * Handles sharing a source directory must not invalidate each other.
 *
 * Regression: the Dart Sass engine wrote its temp input beside the real source, which is a
 * directory dependency tracking watches. Creating and removing that file moved the directory's
 * mtime, so compiling one handle marked every sibling stale and the handles recompiled each
 * other on every request. Single-handle tests could not see it.
 */

require __DIR__ . '/bootstrap.php';

if (!dart_available()) {
    skip('sibling handles', 'sass binary not installed');
    finish();
}

use_dart_engine();

$SCSS = WP_CONTENT_DIR . '/themes/t/scss';
$BASE = 'http://test.local/wp-content/themes/t/scss/';

@mkdir($SCSS, 0777, true);

fixture("$SCSS/_shared.scss", "\$pad: 4px;\n");

foreach (['frontend', 'admin', 'editor'] as $name) {
    fixture("$SCSS/$name.scss", "@use 'shared' as *;\n.$name { padding: \$pad; }\n");
}

section('Three handles compiled from one directory');

foreach (['frontend', 'admin', 'editor'] as $name) {
    check("$name compiles", compile($BASE . "$name.scss", $name)->has_compiled());
}

section('None of them invalidated the others');

foreach (['frontend', 'admin', 'editor'] as $name) {
    check("$name is still cached", !compile($BASE . "$name.scss", $name)->has_compiled());
}

section('And stays that way across repeats');

for ($round = 1; $round <= 2; $round++) {
    $recompiled = [];
    foreach (['frontend', 'admin', 'editor'] as $name) {
        if (compile($BASE . "$name.scss", $name)->has_compiled()) $recompiled[] = $name;
    }
    check("round $round recompiled nothing", !$recompiled, implode(', ', $recompiled));
}

section('The shared partial still invalidates all three');

fixture("$SCSS/_shared.scss", "\$pad: 99px;\n", 500);

foreach (['frontend', 'admin', 'editor'] as $name) {
    check("$name picks up the shared edit", compile($BASE . "$name.scss", $name)->has_compiled());
}

section('Compiling leaves the source directory untouched');

clearstatcache();
$before = filemtime($SCSS);
compile($BASE . 'frontend.scss', 'frontend-again');
clearstatcache();

check('source directory mtime unchanged', filemtime($SCSS) === $before, sprintf('%d -> %d', $before, filemtime($SCSS)));
check('no temp files in the source directory', !glob("$SCSS/_sassy-*"));

finish();
