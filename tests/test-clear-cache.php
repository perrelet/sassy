<?php

/**
 * Clear Cache has to work where the transients actually are.
 *
 * forget_all() pattern-matched the options table, which under an external object cache holds
 * none of them, so the admin bar's Clear Cache removed nothing on the reference install.
 */

require __DIR__ . '/bootstrap.php';

use Sassy\Compile_Cache;
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

finish();
