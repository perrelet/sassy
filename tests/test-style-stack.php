<?php

/**
 * The phase 1 spine: Asset models one enqueued thing, Style_Stack models all of them.
 *
 * 2.x saw only registered styles whose src ended in .scss -- 1 of 329 on the reference install
 * -- and discarded WordPress's own handle dependencies entirely.
 */

require __DIR__ . '/bootstrap.php';

use Sassy\Asset;
use Sassy\Style_Stack;

$BASE = 'http://test.local/wp-content/themes/t/scss/';

section('Asset resolves a local source');

$asset = new Asset('local', $BASE . 'frontend.scss', ['jquery']);

check('handle is kept',        $asset->handle === 'local');
check('type defaults to style', $asset->type === 'style');
check('extension is read',      $asset->extension === 'scss', var_export($asset->extension, true));
check('WP deps are kept',       $asset->deps === ['jquery']);
check('path resolves under ABSPATH', $asset->get_source_path() === ABSPATH . 'wp-content/themes/t/scss/frontend.scss', (string) $asset->get_source_path());
check('is local',               $asset->is_local());
check('is compilable',          $asset->is_compilable());

section('Extensions');

// Indented syntax entry files are closed as won't-do; partials of it still resolve. Plan §6.
check('.sass is not compilable', !(new Asset('s', $BASE . 'a.sass'))->is_compilable());
check('.sass still resolves',    (new Asset('s', $BASE . 'a.sass'))->is_local());
check('.SCSS is normalized',  (new Asset('s', $BASE . 'a.SCSS'))->extension === 'scss');
check('.css is not compilable', !(new Asset('s', $BASE . 'a.css'))->is_compilable());
check('.css is still local',   (new Asset('s', $BASE . 'a.css'))->is_local());
check('a query string is ignored', (new Asset('s', $BASE . 'a.scss?ver=1'))->extension === 'scss');

section('Root-relative sources are local, not remote');

// 65 of the reference install's 329 handles register this way -- every wp-admin stylesheet.
$core = new Asset('common', '/wp-admin/css/common.min.css');

check('resolves under ABSPATH', $core->get_source_path() === ABSPATH . 'wp-admin/css/common.min.css', (string) $core->get_source_path());
check('is local',               $core->is_local());
check('extension is read',      $core->extension === 'css');

section('Sources Sassy cannot map');

$remote = new Asset('fonts', 'https://fonts.googleapis.com/css?family=Open+Sans');
check('remote host does not resolve', $remote->get_source_path() === null, (string) $remote->get_source_path());
check('remote is not local',          !$remote->is_local());

$remote_scss = new Asset('cdn', 'https://cdn.example.com/x.scss');
check('remote .scss is not compilable', !$remote_scss->is_compilable());
check('remote .scss keeps its extension', $remote_scss->extension === 'scss');

// Dependency-only handles register with src === false.
$virtual = new Asset('bundle', false, ['a', 'b']);
check('src === false does not resolve', $virtual->get_source_path() === null);
check('src === false has no extension', $virtual->extension === null);
check('src === false is not compilable', !$virtual->is_compilable());
check('src === false keeps its deps',    $virtual->deps === ['a', 'b']);

$relative = new Asset('odd', 'themes/t/a.scss');
check('a hostless relative src does not resolve', $relative->get_source_path() === null);

section('Host comparison ignores the scheme');

// WP-CLI makes no HTTPS request, so our own URLs can arrive http:// against an https:// site.
check('https src on an http site is local', (new Asset('s', 'https://test.local/wp-content/a.scss'))->is_local());

section('sassy-src-path places what Sassy cannot');

$GLOBALS['filter_overrides']['sassy-src-path'] = function ($path, $src, $handle, $asset) {
    $GLOBALS['seen'] = ['path' => $path, 'handle' => $handle, 'asset' => $asset];
    return '/somewhere/else.scss';
};

$filtered = new Asset('cdn', 'https://cdn.example.com/x.scss');

check('the filter overrides the resolved path', $filtered->get_source_path() === '/somewhere/else.scss');
check('it runs over an unresolvable URL',       $GLOBALS['seen']['path'] === null);
check('it receives the handle',                 $GLOBALS['seen']['handle'] === 'cdn');
check('it receives the Asset',                  $GLOBALS['seen']['asset'] instanceof Asset);
check('a placed asset is local',                $filtered->is_local());
check('a placed .scss is compilable',           $filtered->is_compilable());

unset($GLOBALS['filter_overrides']['sassy-src-path']);

section('Discovery reads the queue');

wp_styles()->add('theme', $BASE . 'frontend.scss', ['base']);
wp_styles()->add('base',  '/wp-admin/css/common.min.css');
wp_styles()->add('bundle', false, ['theme']);

$stack = Style_Stack::discover();

check('every handle is modeled', count($stack->all()) === 3, (string) count($stack->all()));
check('compilable narrows',      array_keys($stack->compilable()) === ['theme']);
check('handle() finds one',      $stack->handle('base') instanceof Asset);
check('handle() misses cleanly', $stack->handle('nope') === null);
check('WP deps survive discovery', $stack->handle('theme')->deps === ['base']);
check('dependents_of names nobody for an unknown file', $stack->dependents_of('/any.scss') === []);

section('Discovery fires the contexts it is asked for');

on_action('wp_enqueue_scripts',        function () { wp_styles()->add('front', 'http://test.local/f.scss'); });
on_action('admin_enqueue_scripts',     function () { wp_styles()->add('admin', 'http://test.local/a.scss'); });
on_action('enqueue_block_editor_assets', function () { wp_styles()->add('editor', 'http://test.local/e.scss'); });

$GLOBALS['actions'] = [];
$admin = Style_Stack::discover(['admin']);

check('the admin hook fired',      in_array('admin_enqueue_scripts', $GLOBALS['actions'], true));
check('the frontend hook did not', !in_array('wp_enqueue_scripts', $GLOBALS['actions'], true));
check('what it registered is seen', $admin->handle('admin') instanceof Asset);
check('the frontend handle is not', $admin->handle('front') === null);

$all = Style_Stack::discover(Style_Stack::CONTEXTS);

check('all three contexts register', $all->handle('front') && $all->handle('admin') && $all->handle('editor'));
check('no context raised',           $all->context_errors() === []);

section('A context that raises is recorded, not fatal');

on_action('enqueue_block_editor_assets', function () { throw new \RuntimeException('screen missing'); });

$guarded = Style_Stack::discover(Style_Stack::CONTEXTS);

check('the raise is recorded',       isset($guarded->context_errors()['editor']));
check('it carries the message',      ($guarded->context_errors()['editor'] ?? '') === 'screen missing');
check('earlier contexts still ran',  $guarded->handle('front') instanceof Asset);
check('discovery still returned',    count($guarded->all()) > 0);

section('sassy-style-queues');

$second = new Sassy_Test_Queue();
$second->add('extra', $BASE . 'extra.scss');
$second->add('theme', $BASE . 'override.scss');

$GLOBALS['filter_overrides']['sassy-style-queues'] = [wp_styles(), $second];

$merged = Style_Stack::discover();

check('the second queue is read',  $merged->handle('extra') instanceof Asset);
check('the first queue is kept',   $merged->handle('base') instanceof Asset);
check('later queues win',          $merged->handle('theme')->src === $BASE . 'override.scss');

unset($GLOBALS['filter_overrides']['sassy-style-queues']);

section('Printer delegates rather than duplicating');

$SCSS = WP_CONTENT_DIR . '/themes/t/scss';
@mkdir($SCSS, 0777, true);
fixture("$SCSS/entry.scss", ".a { color: red; }\n");

$compiler = (new Sassy\Printer())->prepare($BASE . 'entry.scss', 'entry');

check('the printer and the Asset agree',
    $compiler->get_src_path() === (new Asset('entry', $BASE . 'entry.scss'))->get_source_path(),
    $compiler->get_src_path());

check('and it is the real file', file_exists($compiler->get_src_path()));

// The one place the two deliberately differ: callers file_exists() this value and print it.
$unmappable = 'https://cdn.example.com/x.scss';
$remote_compiler = (new Sassy\Printer())->prepare($unmappable, 'cdn');

check('an unmappable URL is null on the Asset',   (new Asset('cdn', $unmappable))->get_source_path() === null);
check('and comes back as the URL on the printer', $remote_compiler->get_src_path() === $unmappable);

section('sassy-src-path reaches both call sites');

$GLOBALS['filter_overrides']['sassy-src-path'] = function ($path, $src) use ($SCSS) {
    return "$SCSS/entry.scss";
};

check('the printer honours it',
    (new Sassy\Printer())->prepare($unmappable, 'cdn')->get_src_path() === "$SCSS/entry.scss");

check('the Asset honours it',
    (new Asset('cdn', $unmappable))->get_source_path() === "$SCSS/entry.scss");

unset($GLOBALS['filter_overrides']['sassy-src-path']);

section('Sassy reports its own failures as diagnostics');

$missing = (new Sassy\Printer())->compile($BASE . 'absent.scss', 'absent');
$m = (new Sassy\Printer());
$m->compile($BASE . 'absent.scss', 'absent');
$d = $m->get_error();

check('a missing source is an error',   $d && $d->severity === 'error');
check('attributed to Sassy',            $d && $d->source === 'sassy');
check('naming the path it looked at',   $d && $d->file === ABSPATH . 'wp-content/themes/t/scss/absent.scss', $d ? (string) $d->file : 'none');
check('without repeating it in the message', $d && !str_contains($d->message, ABSPATH));

$remote = new Sassy\Printer();
$remote->compile('https://cdn.example.com/x.scss', 'cdn');
$r = $remote->get_error();

check('one that maps nowhere is an error', $r && $r->severity === 'error');
check('has no file to name',               $r && $r->file === null);
check('so it names the URL',               $r && str_contains($r->message, 'cdn.example.com'));

section('A throwing callback cannot leak an output buffer');

// Not the editor hook: an earlier section already throws on it, which would short-circuit this
// callback and let the test pass without exercising anything.
on_action('admin_enqueue_scripts', function () {
    ob_start();                       // a callback that buffers and then dies
    echo 'partial output';
    throw new \RuntimeException('died mid-buffer');
});

$before = ob_get_level();
$leaky  = Style_Stack::discover(['admin']);
$after  = ob_get_level();

check('the raise is still recorded',   isset($leaky->context_errors()['admin']));
check('and the buffer level is restored', $after === $before, "before $before, after $after");

section('Discovery leaves the live registries and the screen as it found them');

// In a wp-admin page request, everything the hooks enqueue would otherwise print in the footer.
$live = wp_styles();
$live->add('live-only', $BASE . 'live.scss');

$GLOBALS['screen'] = 'tools_page_sassy';
function get_current_screen () { return $GLOBALS['screen']; }
function set_current_screen ($screen) { $GLOBALS['screen'] = $screen; $GLOBALS['screens_seen'][] = $screen; }
$GLOBALS['screens_seen'] = [];

$isolated = Style_Stack::discover(['frontend', 'admin']);

check('the hook\'s handle is in the discovered stack', $isolated->handle('front') instanceof Asset);
check('and so is what was registered before',         $isolated->handle('live-only') instanceof Asset);
check('the live registry did not receive the hook\'s', !isset($live->registered['front']));
check('and is what wp_styles() returns again',        wp_styles() === $live);
check('the admin context set its screen',             in_array('dashboard', $GLOBALS['screens_seen'], true));
check('and the page\'s screen is handed back',        $GLOBALS['screen'] === 'tools_page_sassy', (string) $GLOBALS['screen']);

section('Scripts are discovered into a second store');

$styles_before = array_keys(Style_Stack::discover()->all());

wp_scripts()->add('app',  $BASE . 'app.js', ['jquery']);
wp_scripts()->add('base', '/wp-includes/js/base.js');           // a handle a style has too
wp_scripts()->add('cdn',  'https://ajax.googleapis.com/jquery.js');

$both = Style_Stack::discover();

check('all() with no argument is what it was',        array_keys($both->all()) === $styles_before);
check('scripts() holds the scripts',                  array_keys($both->scripts()) === ['app', 'base', 'cdn']);
check('typed as scripts',                             $both->scripts()['app']->type === 'script' && $both->scripts()['app']->extension === 'js');
check('with their WP deps',                           $both->scripts()['app']->deps === ['jquery']);
check('handle() answers the style by default',        $both->handle('base')->src === '/wp-admin/css/common.min.css');
check('and the script when asked',                    $both->handle('base', 'script')->src === '/wp-includes/js/base.js');
check('all(\'all\') holds both under type:handle',    isset($both->all('all')['style:base']) && isset($both->all('all')['script:base']) && count($both->all('all')) === count($both->styles()) + count($both->scripts()));
check('styles() is all()',                            $both->styles() === $both->all());
check('compilable() never holds a script',            !array_filter($both->compilable(), function ($a) { return $a->type === 'script'; }));
check('a remote script is not local',                 !$both->scripts()['cdn']->is_local());

$extra = new Sassy_Test_Queue();
$extra->add('extra', $BASE . 'extra.js');
$GLOBALS['filter_overrides']['sassy-script-queues'] = [wp_scripts(), $extra];

check('sassy-script-queues adds a queue',             Style_Stack::discover()->handle('extra', 'script') instanceof Asset);

unset($GLOBALS['filter_overrides']['sassy-script-queues']);

section('parse_contexts');

check('all expands',            Style_Stack::parse_contexts('all') === Style_Stack::CONTEXTS);
check('a list is split',        Style_Stack::parse_contexts('admin, editor') === ['admin', 'editor']);
check('an unknown set is null', Style_Stack::parse_contexts('frontend,nope') === null);
check('an empty value is null', Style_Stack::parse_contexts('') === null);

finish();
