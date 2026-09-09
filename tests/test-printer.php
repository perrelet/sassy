<?php

/**
 * The phase 2 split: Build_Target, Variable_Resolver and Compile_Cache.
 *
 * SCSS_Compiler held all three plus the orchestration, and the surfaces reached past it for
 * whichever part they wanted.
 */

require __DIR__ . '/bootstrap.php';

use Sassy\Asset;
use Sassy\Build_Target;
use Sassy\Compile_Cache;
use Sassy\Import_Scanner;
use Sassy\Printer;
use Sassy\Variable_Resolver;

$SCSS = WP_CONTENT_DIR . '/themes/t/scss';
$BASE = 'http://test.local/wp-content/themes/t/scss/';

@mkdir($SCSS, 0777, true);
fixture("$SCSS/_shared.scss", "\$pad: 4px;\n");
fixture("$SCSS/entry.scss", "@import 'shared';\n.a { padding: \$pad; }\n");

$asset  = new Asset('entry', $BASE . 'entry.scss');
$target = new Build_Target($asset);

section('Build_Target');

check('directory',  $target->get_directory() === '/scss/', $target->get_directory());
check('path',       $target->get_path() === WP_CONTENT_DIR . '/scss/', $target->get_path());
check('name drops the source extension', $target->get_name() === 'entry.css', $target->get_name());
check('file is path plus name',          $target->get_file() === WP_CONTENT_DIR . '/scss/entry.css');
check('url',        $target->get_url() === 'http://test.local/wp-content/scss/entry.css', $target->get_url());
check('map path',   $target->get_map_path() === WP_CONTENT_DIR . '/scss/entry.css.map');
check('map url',    $target->get_map_url() === 'http://test.local/wp-content/scss/entry.css.map');

$queried = new Build_Target(new Asset('q', $BASE . 'entry.scss?ver=9'));
check('a query string does not reach the filename', $queried->get_name() === 'entry.css', $queried->get_name());

section('Build_Target filters');

foreach ([
    'sassy-build-directory' => ['/built/',      'get_directory'],
    'sassy-build-name'      => ['custom.css',   'get_name'],
] as $tag => [$value, $method]) {
    $GLOBALS['filter_overrides'][$tag] = $value;
    check("$tag is honoured", (new Build_Target($asset))->$method() === $value);
    unset($GLOBALS['filter_overrides'][$tag]);
}

$GLOBALS['filter_overrides']['sassy-build-path'] = '/tmp/elsewhere';
check('sassy-build-path is honoured', (new Build_Target($asset))->get_path() === '/tmp/elsewhere/scss/');
unset($GLOBALS['filter_overrides']['sassy-build-path']);

section('Variable_Resolver');

$resolver  = new Variable_Resolver($asset);
$variables = $resolver->get_variables();

check('seeds wp-content-url',           isset($variables['wp-content-url']));
check('seeds template-directory-url',   isset($variables['template-directory-url']));
check('seeds stylesheet-directory-url', isset($variables['stylesheet-directory-url']));
check('values are quoted',              $variables['wp-content-url'] === '"http://test.local/wp-content"', $variables['wp-content-url']);

$GLOBALS['filter_overrides']['sassy-variables'] = ['breakpoints' => ['sm' => '600px', 'lg' => '900px']];
$mapped = (new Variable_Resolver($asset))->get_variables();
check('arrays become Sass maps', $mapped['breakpoints'] === "('sm': 600px, 'lg': 900px)", $mapped['breakpoints']);

$GLOBALS['filter_overrides']['sassy-variables'] = ['url' => '"https://test.local/x.png"'];
$scheme = (new Variable_Resolver($asset))->get_variables();
check('site URLs are forced onto the home scheme', $scheme['url'] === '"http://test.local/x.png"', $scheme['url']);

$GLOBALS['filter_overrides']['sassy-variables'] = ['url' => '"https://other.test/x.png"'];
$foreign = (new Variable_Resolver($asset))->get_variables();
check('other hosts are left alone', $foreign['url'] === '"https://other.test/x.png"', $foreign['url']);
unset($GLOBALS['filter_overrides']['sassy-variables']);

section('Variable_Resolver signature');

$GLOBALS['filter_overrides']['sassy-variables'] = ['a' => '1px'];
$first = (new Variable_Resolver($asset))->get_signature();
$GLOBALS['filter_overrides']['sassy-variables'] = ['a' => '2px'];
$second = (new Variable_Resolver($asset))->get_signature();
unset($GLOBALS['filter_overrides']['sassy-variables']);

check('is stable for the same values', $first === (function () use ($asset) {
    $GLOBALS['filter_overrides']['sassy-variables'] = ['a' => '1px'];
    $sig = (new Variable_Resolver($asset))->get_signature();
    unset($GLOBALS['filter_overrides']['sassy-variables']);
    return $sig;
})());
check('changes when a value changes', $first !== $second);

section('Variable_Resolver::prepend');

check('empty variables leave the source alone', Variable_Resolver::prepend('.a{}', []) === '.a{}');
check('declarations join the first line',       Variable_Resolver::prepend(".a{}", ['p' => '4px']) === '$p: 4px; .a{}');
check('no newline is introduced',               substr_count(Variable_Resolver::prepend(".a{}\n.b{}\n", ['p' => '4px', 'q' => '8px']), "\n") === 2);

section('Compile_Cache');

$cache = new Compile_Cache($asset, $target, new Variable_Resolver($asset));

check('an unbuilt handle needs compiling', $cache->needs_compile());
check('and is not current',                !$cache->is_current());

compile($BASE . 'entry.scss', 'entry');

check('after a compile it is current',     $cache->is_current());
check('and needs no compile',              !$cache->needs_compile());
check('the graph is recorded',             Compile_Cache::get_graph('entry') !== null);
check('the compile time is recorded',      Compile_Cache::get_last_compile_time('entry') > 0);

fixture("$SCSS/_shared.scss", "\$pad: 12px;\n", 10);
clearstatcache();
check('editing a partial invalidates', $cache->needs_compile());

compile($BASE . 'entry.scss', 'entry');
check('recompiling restores it', $cache->is_current());

$GLOBALS['filter_overrides']['sassy-variables'] = ['extra' => '1px'];
check('changing variables invalidates', (new Compile_Cache($asset, $target, new Variable_Resolver($asset)))->needs_compile());
unset($GLOBALS['filter_overrides']['sassy-variables']);

unlink($target->get_file());
check('a missing build file invalidates', $cache->needs_compile());

compile($BASE . 'entry.scss', 'entry');

$GLOBALS['filter_overrides']['sassy-force-compile'] = true;
check('sassy-force-compile invalidates', $cache->needs_compile());
unset($GLOBALS['filter_overrides']['sassy-force-compile']);

$GLOBALS['filter_overrides']['sassy-check-dependencies'] = false;
fixture("$SCSS/_shared.scss", "\$pad: 20px;\n", 20);
clearstatcache();
check('sassy-check-dependencies off ignores the graph', !$cache->needs_compile());
unset($GLOBALS['filter_overrides']['sassy-check-dependencies']);

section('One asset-state vocabulary');

$state_asset  = new Asset('stateful', $BASE . 'entry.scss');
$state_target = new Build_Target($state_asset);
$state_cache  = new Compile_Cache($state_asset, $state_target, new Variable_Resolver($state_asset));

@unlink($state_target->get_file());
check('an unbuilt handle',   $state_cache->get_state() === 'not built', $state_cache->get_state());

compile($BASE . 'entry.scss', 'stateful');
check('a fresh build',       $state_cache->get_state() === 'current', $state_cache->get_state());

fixture("$SCSS/_shared.scss", "\$pad: 24px;\n", 40);
clearstatcache();
check('after editing a partial', $state_cache->get_state() === 'stale', $state_cache->get_state());

compile($BASE . 'entry.scss', 'stateful');

$recorded = get_transient('sassy-filemtimes-stateful');
$recorded['__diagnostics__'] = ['deprecation' => 2];
set_transient('sassy-filemtimes-stateful', $recorded);

check('deprecations show as warning', $state_cache->get_state() === 'warning', $state_cache->get_state());

$recorded['__diagnostics__'] = [];
set_transient('sassy-filemtimes-stateful', $recorded);

$missing = new Asset('gone', $BASE . 'never-existed.scss');
$missing_cache = new Compile_Cache($missing, new Build_Target($missing), new Variable_Resolver($missing));
check('a missing source',    $missing_cache->get_state() === 'no source', $missing_cache->get_state());

// Only a Printer that just ran can know this: a failed compile is never recorded.
$broken_printer = new Sassy\Printer();
$broken_printer->compile($BASE . 'never-existed.scss', 'gone');
check('a Printer reports its own error', $broken_printer->get_state() === 'error', $broken_printer->get_state());

section('One file-state vocabulary');

$graph = Compile_Cache::get_graph('stateful');
$paths = array_keys($graph->deps);

check('an unchanged dependency', Sassy\Import_Graph::state_of($paths[0], $graph->deps[$paths[0]]) === 'current');
check('a missing one',           Sassy\Import_Graph::state_of('/tmp/never.scss', [1, 1]) === 'missing');
check('a changed one',           Sassy\Import_Graph::state_of($paths[0], [1, 1]) === 'changed');

section('Compile_Cache::forget');

$cache->forget();
check('forget drops the graph',        Compile_Cache::get_graph('entry') === null);
check('and the handle needs compiling', $cache->needs_compile());

section('Printer delegates to all three');

$printer = (new Printer())->prepare($BASE . 'entry.scss', 'entry');

check('build file agrees with the target',   $printer->get_build_file() === $target->get_file());
check('build url agrees with the target',    $printer->get_build_url() === $target->get_url());
check('map path agrees with the target',     $printer->get_map_path() === $target->get_map_path());
check('map url agrees with the target',      $printer->get_map_url() === $target->get_map_url());
check('variables agree with the resolver',   $printer->get_variables() === (new Variable_Resolver($asset))->get_variables());
check('currency agrees with the cache',      $printer->is_current() === (new Compile_Cache($asset, $target, new Variable_Resolver($asset)))->is_current());

section('A build that cannot be written is not recorded as current');

// A distinct source, because the build name comes from the filename rather than the handle.
fixture("$SCSS/blocked.scss", ".a { color: red; }\n");

$blocked = new Asset('blocked', $BASE . 'blocked.scss');
$target2 = new Build_Target($blocked);

@mkdir($target2->get_path(), 0777, true);

// file_put_contents fails on a path that is a directory, which is the portable way to force
// the write failure a full disk or a permissions change would cause.
@mkdir($target2->get_file(), 0777, true);

$printer2 = new Sassy\Printer();
$printer2->compile($BASE . 'blocked.scss', 'blocked');

check('the failure is reported',        $printer2->has_error());
check('naming the build file',          str_contains((string) $printer2->get_error()->file, 'blocked.css'));
check('and nothing is recorded',        Compile_Cache::get_graph('blocked') === null, 'a recorded graph would make the missing build read as current');

$cache2 = new Compile_Cache($blocked, $target2, new Variable_Resolver($blocked));
check('so it still needs compiling',    $cache2->needs_compile());

@rmdir($target2->get_file());

section('No map, no missing-link notice');

$engine = new class implements Sassy\Compiler_Engine {
    public function compile (Sassy\Compile_Request $request) : Sassy\Compile_Result {
        return new Sassy\Compile_Result('.a{color:red}', null, null, []);
    }
    public function capabilities () : array { return []; }
    public function supports (string $capability) : bool { return false; }
};

$GLOBALS['filter_overrides']['sassy-engine'] = $engine;
$GLOBALS['filter_overrides']['sassy-force-compile'] = true;

$mapless = new Sassy\Printer();
$mapless->compile($BASE . 'entry.scss', 'mapless');

unset($GLOBALS['filter_overrides']['sassy-engine'], $GLOBALS['filter_overrides']['sassy-force-compile']);

$linknotice = array_filter($mapless->get_warnings(), function ($d) { return str_contains($d->message, 'nothing links to it'); });

check('an engine that wrote no map is not accused of losing one', $linknotice === [], (string) count($linknotice));

finish();
