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

section('The watch set skips directories holding no Sass');

// A load-path root with no SCSS in it churns for unrelated reasons, and watching it would
// invalidate every handle on the site whenever anything was added to it.
$BARREN = WP_CONTENT_DIR . '/barren';
@mkdir($BARREN, 0777, true);
file_put_contents("$BARREN/readme.txt", "not sass\n");

$graph = Import_Scanner::scan("$SCSS/entry.scss", [$SCSS, $SHARED, $BARREN]);

check('a Sass-free load path is not watched', !isset($graph->dirs[$BARREN]), $BARREN);
check('the entry file directory is watched',  isset($graph->dirs[$SCSS]));
check('a load path holding Sass is watched',  isset($graph->dirs[$SHARED]));
check('no watched directory is missing',      !count(array_filter(array_keys($graph->dirs), fn($d) => !is_dir($d))));

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

section('A same-second edit is still detected');

// mtime is second-resolution, so an edit inside the same second as the scan leaves it
// unchanged — and would be missed permanently, not just late. Size is compared too.
fixture("$SCSS/entry.scss", "@use 'mix';\n.a { padding: mix.\$pad; }\n", 200);
fixture("$SCSS/_mix.scss", "\$pad: 4px;\n", 210);
check('baseline compiles', compile($URL, 'sub-second')->has_compiled());

$partial = "$SCSS/_mix.scss";
$mtime   = filemtime($partial);
file_put_contents($partial, "\$pad: 4px; // edited within the same second\n");
touch($partial, $mtime);   // exactly what a fast second save looks like

check('mtime really is unchanged', filemtime($partial) === $mtime);
check('the edit is still caught',  compile($URL, 'sub-second')->has_compiled());

section('is_current() agrees with what a compile actually does');

fixture("$SCSS/entry.scss", "@use 'mix';\n.a { padding: mix.\$pad; }\n", 250);
fixture("$SCSS/_mix.scss", "\$pad: 4px;\n", 260);
compile($URL, 'current');

$state = fn() => (new Sassy\Printer())->prepare($URL, 'current')->is_current();

check('current right after compiling', $state());

// Reporting used to consult the import graph alone, so a variable change read as current
// while the very next request rebuilt.
delete_transient('sassy-vars-sig-current');
check('a variable-signature change reads as stale', !$state());
check('and a compile confirms it', compile($URL, 'current')->has_compiled());

fixture("$SCSS/_mix.scss", "\$pad: 5px;\n", 270);
check('a partial edit reads as stale', !$state());
compile($URL, 'current');

$GLOBALS['filter_overrides']['sassy-force-compile'] = true;
check('force reads as stale too', !$state());
unset($GLOBALS['filter_overrides']['sassy-force-compile']);

section('sassy-check-dependencies can switch the stat cost off');

fixture("$SCSS/entry.scss", "@use 'mix';\n.a { padding: mix.\$pad; }\n", 320);
fixture("$SCSS/_mix.scss", "\$pad: 4px;\n", 330);
check('baseline compiles', compile($URL, 'nocheck')->has_compiled());

$GLOBALS['filter_overrides']['sassy-check-dependencies'] = false;

fixture("$SCSS/_mix.scss", "\$pad: 7px;\n", 340);
check('a partial edit is ignored when off', !compile($URL, 'nocheck')->has_compiled());

// Explicit invalidation still works, so a deploy hook can drive compilation.
$GLOBALS['filter_overrides']['sassy-force-compile'] = true;
check('force still compiles', compile($URL, 'nocheck')->has_compiled());
unset($GLOBALS['filter_overrides']['sassy-force-compile']);

$GLOBALS['filter_overrides']['sassy-check-dependencies'] = true;
fixture("$SCSS/_mix.scss", "\$pad: 8px;\n", 350);
check('and edits land again once back on', compile($URL, 'nocheck')->has_compiled());
unset($GLOBALS['filter_overrides']['sassy-check-dependencies']);

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
