<?php

/**
 * Editing a partial must invalidate the cache without touching the entry file.
 */

require __DIR__ . '/bootstrap.php';

use Sassy\Import_Scanner;

$SCSS   = WP_CONTENT_DIR . '/themes/t/scss';
$SHARED = WP_CONTENT_DIR . '/shared';
$URL    = 'http://test.local/wp-content/themes/t/scss/entry.scss';

@mkdir($SCSS . '/lib', 0777, true);
@mkdir($SHARED, 0777, true);

section('Parsing');

fixture("$SCSS/_mix.scss",       "\$pad: 4px;\n");
fixture("$SCSS/lib/_deep.scss",  "\$deep: 1px;\n");
fixture("$SCSS/lib/_index.scss", "@use 'deep';\n");
fixture("$SHARED/_ext.scss",     "\$ext: 2px;\n");
fixture("$SCSS/entry.scss", <<<'SCSS'
@use "sass:math";
@use "mix";
@use "lib";
@use "ext";
@use "ghost" with ($msg: "not-an-import");
@import url("http://cdn.test/x.css");
@import "plain.css";
// @use "commented-out";
/* @use "block-commented"; */
.a { padding: mix.$pad; }
SCSS
);

$parse = new ReflectionMethod(Import_Scanner::class, 'parse_rules');
$parse->setAccessible(true);
$rules = $parse->invoke(null, file_get_contents("$SCSS/entry.scss"));

check('parses exactly the real targets', $rules === ['mix', 'lib', 'ext', 'ghost'], implode(', ', $rules));

section('Resolution');

$graph = Import_Scanner::scan("$SCSS/entry.scss", [$SCSS, $SHARED]);
$deps  = array_keys($graph->deps);
$has   = fn($suffix) => (bool) count(array_filter($deps, fn($d) => str_ends_with($d, $suffix)));

check('tracks the entry file',            $has('/entry.scss'));
check('resolves a sibling partial',       $has('/_mix.scss'));
check('resolves a directory _index.scss', $has('/lib/_index.scss'));
check('follows transitively',             $has('/lib/_deep.scss'));
check('resolves via a load path',         $has('/shared/_ext.scss'));
check('watches directories for shadowing', (bool) $graph->dirs);
check('not truncated',                    !$graph->truncated);

fixture("$SCSS/_a.scss", "@use 'b';\n");
fixture("$SCSS/_b.scss", "@use 'a';\n");
check('a cycle terminates', count(Import_Scanner::scan("$SCSS/_a.scss", [$SCSS])->deps) === 2);

if (!dart_available()) {
    skip('invalidation', 'sass binary not installed');
    finish();
}

use_dart_engine();

section('Invalidation');

fixture("$SCSS/entry.scss", "@use 'mix';\n.a { padding: mix.\$pad; }\n", 300);

check('first compile runs',           compile($URL, 'inv')->has_compiled());
check('unchanged: serves from cache', !compile($URL, 'inv')->has_compiled());

fixture("$SCSS/_mix.scss", "\$pad: 99px;\n", 500);   // the partial only; entry untouched
check('editing a partial recompiles', compile($URL, 'inv')->has_compiled());
check('output reflects the edit',     str_contains(file_get_contents(WP_CONTENT_DIR . '/scss/entry.css'), '99px'));
check('then settles back to cached',  !compile($URL, 'inv')->has_compiled());

section('Shadowing');

fixture("$SCSS/entry.scss", "@use 'ext' as *;\n.b { margin: \$ext; }\n", 600);
$GLOBALS['filter_overrides']['sassy-import-paths'] = [$SCSS, $SHARED];

check('compiles against the load-path copy', compile($URL, 'shadow')->has_compiled());
check('then caches',                         !compile($URL, 'shadow')->has_compiled());

// Nothing already tracked changes when this appears; only the directory's mtime moves.
fixture("$SCSS/_ext.scss", "\$ext: 42px;\n", 700);
check('a newly shadowing file invalidates', compile($URL, 'shadow')->has_compiled());
check('output uses the shadowing file',     str_contains(file_get_contents(WP_CONTENT_DIR . '/scss/entry.css'), '42px'));

unset($GLOBALS['filter_overrides']['sassy-import-paths']);

section('A failed compile is not remembered as current');

$GLOBALS['transients'] = [];
fixture("$SCSS/entry.scss", "@use 'mix';\n.a { padding: mix.\$pad; }\n", 800);
fixture("$SCSS/_mix.scss", "\$pad: 4px;\n", 810);
check('baseline compiles', compile($URL, 'fail')->has_compiled());

// Trigger the next run purely via the variable signature, and make that run fail.
$GLOBALS['filter_overrides']['sassy-variables']        = ['injected' => '1px'];
$GLOBALS['filter_overrides']['sassy-dart-sass-binary'] = '/nonexistent/sass';

check('signature change triggers a run, which fails', compile($URL, 'fail')->has_error());
check('the error persists on the next request',       compile($URL, 'fail')->has_error());

$GLOBALS['filter_overrides']['sassy-dart-sass-binary'] = 'sass';
check('recovers once the failure is resolved', compile($URL, 'fail')->has_compiled());

finish();
