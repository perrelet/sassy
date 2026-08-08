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

section('Cleanup');
$strays = array_merge(
    glob("$SCSS/_sassy-*") ?: [],
    array_filter(glob("$BUILD/.sassy-*") ?: [], 'is_file'),
    glob("$BUILD/.sassy-tmp/*") ?: []
);
check('no temp files left behind', !$strays, implode(', ', $strays));

$built = array_values(array_diff(scandir($BUILD), ['.', '..', '.sassy-tmp']));
check('build dir holds only the css and map', count($built) === 2, implode(', ', $built));

finish();
