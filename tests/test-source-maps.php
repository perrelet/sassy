<?php

/**
 * Source maps must be usable, not merely present: every source has to resolve from where the
 * map is served, and line numbers have to point at the right source lines.
 */

require __DIR__ . '/bootstrap.php';

if (!dart_available()) {
    skip('source maps', 'sass binary not installed');
    finish();
}

use_dart_engine();

$SCSS  = WP_CONTENT_DIR . '/themes/t/scss';
$BUILD = WP_CONTENT_DIR . '/scss';
$URL   = 'http://test.local/wp-content/themes/t/scss/entry.scss';

@mkdir($SCSS, 0777, true);

// Several variables, so a multi-line prelude would shift lines noticeably.
$GLOBALS['filter_overrides']['sassy-variables'] = [
    'a' => '1px', 'b' => '2px', 'c' => '3px', 'd' => '4px', 'e' => '5px',
];

fixture("$SCSS/_mix.scss", "@mixin box { padding: 4px; }\n");
fixture("$SCSS/entry.scss",
    "@use 'mix' as *;\n"            // line 1
  . "\n"                            // line 2
  . ".line3 { color: red; }\n"      // line 3
  . ".line4 { @include box; }\n"    // line 4
);

$compiler = compile($URL, 'maps');

section('Compile');
check('no error',   !$compiler->has_error(), (string) $compiler->get_error());
check('css written', file_exists("$BUILD/entry.css"));
check('map written', file_exists("$BUILD/entry.css.map"));

$css = file_get_contents("$BUILD/entry.css");
$map = json_decode(file_get_contents("$BUILD/entry.css.map"), true);

section('sourceMappingURL');
preg_match('~sourceMappingURL=(\S+?)\s*\*/~', $css, $m);
check('points at the built map, not the temp output',
    ($m[1] ?? '') === 'http://test.local/wp-content/scss/entry.css.map', $m[1] ?? '(none)');

section('Sources resolve from where the map is served');
foreach (($map['sources'] ?? []) as $source) {
    check("resolves: $source", (bool) realpath("$BUILD/" . $source));
}
check('entry is the real file, not the temp copy',
    in_array('../themes/t/scss/entry.scss', $map['sources'] ?? [], true),
    implode(', ', $map['sources'] ?? []));

section('Line numbers are unshifted');

/** Decode the nth field of a source-map VLQ segment. */
function vlq_field ($segment, $index) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
    $i = 0; $fields = [];
    while ($i < strlen($segment) && count($fields) <= $index) {
        $value = 0; $shift = 0;
        while ($i < strlen($segment)) {
            $digit = strpos($alphabet, $segment[$i]); $i++;
            $value |= ($digit & 31) << $shift; $shift += 5;
            if (!($digit & 32)) break;
        }
        $fields[] = ($value & 1) ? -($value >> 1) : ($value >> 1);
    }
    return $fields[$index] ?? null;
}

$first = explode(',', explode(';', $map['mappings'])[0])[0];
check('first mapping points at source line 3', vlq_field($first, 2) === 2, '0-indexed ' . vlq_field($first, 2));

section('Diagnostics name the file the author wrote');

// Dart Sass cites whatever file it was handed, which is the temp copy, path and all.
fixture("$SCSS/warns.scss", ".a { content: unquote(\"x\"); }\n");
$warned = compile('http://test.local/wp-content/themes/t/scss/warns.scss', 'warns');
$text   = Sassy\Diagnostic::render_all($warned->get_warnings());

check('a warning was produced', $text !== '', 'nothing to check against');
check('no temp basename leaks',  !str_contains($text, '.tmp.scss'), $text);
check('no temp directory leaks', !str_contains($text, '.sassy-tmp'), $text);
check('the real filename is cited', str_contains($text, 'warns.scss'), $text);

// Same for hard errors, which take a different path out of the engine.
fixture("$SCSS/broken.scss", ".a { padding: ; }\n");
$broken = compile('http://test.local/wp-content/themes/t/scss/broken.scss', 'broken');

check('the compile failed',        $broken->has_error());
$error = Sassy\Diagnostic::render_all($broken->get_errors());
check('no temp path in the error', !str_contains($error, '.sassy-tmp'), $error);
check('the error is a Diagnostic',  $broken->get_error() instanceof Sassy\Diagnostic);

section('Cleanup');
$strays = array_merge(
    glob("$SCSS/_sassy-*") ?: [],
    array_filter(glob("$BUILD/.sassy-*") ?: [], 'is_file'),
    glob("$BUILD/.sassy-tmp/*") ?: []
);
check('no temp files left behind', !$strays, implode(', ', $strays));

$unexpected = array_filter(
    array_diff(scandir($BUILD), ['.', '..', '.sassy-tmp']),
    fn($file) => !preg_match('/\.css(\.map)?$/', $file)
);
check('build dir holds nothing but css and maps', !$unexpected, implode(', ', $unexpected));

finish();
