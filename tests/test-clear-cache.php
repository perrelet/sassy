<?php

/**
 * Clear Cache has to work where the transients actually are.
 *
 * forget_all() pattern-matched the options table, which under an external object cache holds
 * none of them, so the admin bar's Clear Cache removed nothing on the reference install.
 */

require __DIR__ . '/bootstrap.php';

use Sassy\Compile_Cache;
use Sassy\Diagnostic;
use Sassy\Printer;

$GLOBALS['ext_object_cache'] = true;
function wp_using_ext_object_cache () { return $GLOBALS['ext_object_cache']; }

$SCSS = ABSPATH . 'wp-content/themes/t';
$BASE = 'http://test.local/wp-content/themes/t/';

fixture("$SCSS/a.scss", ".a { color: red; }\n");
fixture("$SCSS/b.scss", ".b { color: blue; }\n");

compile($BASE . 'a.scss', 'a');
compile($BASE . 'b.scss', 'b');

section('Recording a compile indexes the handle');

check('both graphs are recorded',    Compile_Cache::get_graph('a') && Compile_Cache::get_graph('b'));
check('the index lists both',        get_transient('sassy-handles') === ['a', 'b']);

$GLOBALS['filter_overrides']['sassy-force-compile'] = true;
compile($BASE . 'a.scss', 'a');
unset($GLOBALS['filter_overrides']['sassy-force-compile']);

check('recompiling does not duplicate', get_transient('sassy-handles') === ['a', 'b']);

section('forget_all() clears by handle under an external object cache');

$cleared = Compile_Cache::forget_all();

check('reports the handles cleared',  $cleared === 2, var_export($cleared, true));
check('the graphs are gone',          !Compile_Cache::get_graph('a') && !Compile_Cache::get_graph('b'));
check('the signatures are gone',      get_transient('sassy-vars-sig-a') === false && get_transient('sassy-vars-sig-b') === false);
check('the index is gone',            get_transient('sassy-handles') === false);
check('a fresh compile is needed',    (new Printer())->prepare($BASE . 'a.scss', 'a')->get_cache()->needs_compile());

section('Diagnostics are recorded after every compile that ran');

fixture("$SCSS/warned.scss", "@warn 'careful';\n.c { color: red; }\n");
fixture("$SCSS/broken.scss", ".c { color: }\n");

compile($BASE . 'warned.scss', 'warned');
$recorded = Compile_Cache::get_diagnostics('warned');

check('a success records its diagnostics', $recorded && count($recorded['diagnostics']) === 1, var_export($recorded, true));
check('as Diagnostic objects',             ($recorded['diagnostics'][0] ?? null) instanceof Diagnostic);
check('with the severity intact',          ($recorded['diagnostics'][0]->severity ?? null) === 'warning');
check('and when',                          ($recorded['time'] ?? 0) > 0);

compile($BASE . 'broken.scss', 'broken');
$failed = Compile_Cache::get_diagnostics('broken');

check('a failure records its error',          $failed && ($failed['diagnostics'][0]->severity ?? null) === 'error');
check('without being recorded as current',    !Compile_Cache::get_graph('broken'));
check('but indexed, so forget_all() finds it', in_array('broken', get_transient('sassy-handles') ?: [], true));

compile($BASE . 'warned.scss', 'warned');
check('a cache hit leaves the record standing', count(Compile_Cache::get_diagnostics('warned')['diagnostics'] ?? []) === 1);

fixture("$SCSS/warned.scss", ".c { color: red; }\n", 10);
compile($BASE . 'warned.scss', 'warned');
check('a clean recompile empties it', (Compile_Cache::get_diagnostics('warned')['diagnostics'] ?? null) === []);

Compile_Cache::forget_handle('warned');
check('forget_handle() drops it', Compile_Cache::get_diagnostics('warned') === null);

Compile_Cache::forget_all();
check('forget_all() drops the failure\'s too', Compile_Cache::get_diagnostics('broken') === null);

section('A recorded diagnostic round-trips');

$original = new Diagnostic('deprecation', "Global built-in functions are deprecated.\nUse math.div instead.", [
    'file' => '_x.scss', 'line' => 3, 'column' => 7, 'frame' => "  ╷\n3 │ a\n  ╵", 'trace' => '  _x.scss 3:7  root stylesheet', 'code' => 'global-builtin', 'url' => 'https://sass-lang.com/d/import',
]);
$copy = Diagnostic::from_array($original->to_array());

check('every field survives', $copy->to_array() === $original->to_array());
check('and it renders the same', $copy->render() === $original->render());

finish();
