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

section('url() rewriting moves the map with it');

/** The original line a generated position maps to, walking every segment before it. */
function map_lookup (array $map, $line, $column) {
    $src = 0; $oline = 0; $ocol = 0; $best = null;
    foreach (explode(';', $map['mappings']) as $l => $encoded) {
        $col = 0;
        foreach (array_filter(explode(',', $encoded), 'strlen') as $segment) {
            $col   += vlq_field($segment, 0);
            $src   += vlq_field($segment, 1) ?? 0;
            $oline += vlq_field($segment, 2) ?? 0;
            $ocol  += vlq_field($segment, 3) ?? 0;
            if ($l === $line && $col <= $column) $best = ['src' => $src, 'line' => $oline, 'column' => $ocol];
        }
    }
    return $best;
}

// Compressed, so the whole sheet is one line and every insertion shifts every later column.
$GLOBALS['filter_overrides']['sassy-style'] = 'compressed';

// Declarations on their own lines, as written in practice: Dart emits a segment only where the
// source position changes, so a declaration beside its selector gets none of its own.
fixture("$SCSS/urls.scss",
    ".a {\n  background: url(img.png);\n}\n"      // lines 1-3: rewritten
  . ".b {\n  background: url('two.png');\n}\n"    // lines 4-6: rewritten again
  . ".c {\n  color: red;\n}\n"                     // lines 7-9: after both insertions
);

compile('http://test.local/wp-content/themes/t/scss/urls.scss', 'urls');

$ucss = file_get_contents("$BUILD/urls.css");
$umap = json_decode(file_get_contents("$BUILD/urls.css.map"), true);

check('both urls were rewritten', substr_count($ucss, 'url(/wp-content/themes/t/scss/img.png)') === 1 && substr_count($ucss, '/wp-content/themes/t/scss/two.png') === 1, $ucss);

$rule = map_lookup($umap, 0, strpos($ucss, '.c{'));
$decl = map_lookup($umap, 0, strpos($ucss, 'color:red'));

// Column as well as line: before the fix the shifted lookup landed on the rule's own
// declaration, which is on the same line, and the line alone could not tell them apart.
check('.c maps to source line 7 after two insertions', $rule && $rule['line'] === 6 && $rule['column'] === 0, var_export($rule, true));
check('and its declaration to line 8',                 $decl && $decl['line'] === 7 && $decl['column'] === 2, var_export($decl, true));
check('in the entry file',                             $rule && str_ends_with($umap['sources'][$rule['src']] ?? '', 'urls.scss'));
check('the map names the built file, not the temp output', ($umap['file'] ?? '') === 'urls.css', (string) ($umap['file'] ?? ''));

unset($GLOBALS['filter_overrides']['sassy-style']);

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
