<?php

/**
 * The extension registry: four points, named providers, and a post-processor that can report.
 *
 * 2.x had four filters and no way to describe them. A sassy-css callback could rewrite the CSS
 * and say nothing about it, which is how Lightning CSS could fail silently on every surface.
 */

require __DIR__ . '/bootstrap.php';

use Sassy\Asset;
use Sassy\Diagnostic;
use Sassy\Extensions;
use Sassy\Post_Process_Context;

$SCSS = WP_CONTENT_DIR . '/themes/t/scss';
$BASE = 'http://test.local/wp-content/themes/t/scss/';

@mkdir($SCSS, 0777, true);
fixture("$SCSS/entry.scss", ".a { color: red; }\n");

$asset = new Asset('entry', $BASE . 'entry.scss');

section('Nothing is registered by default');

foreach (Extensions::KINDS as $kind) {
    check("$kind starts empty", Extensions::providers($kind) === []);
}

section('Providers are named');

Extensions::register_variables('demo', function ($variables, $asset) {
    $variables['demo'] = '4px';
    return $variables;
});

check('listed by slug',            Extensions::providers('variables') === ['demo']);
check('listed in the full report', Extensions::providers()['variables'] === ['demo']);
check('other kinds unaffected',    Extensions::providers('load_paths') === []);
check('and it applies',            Extensions::apply_variables([], $asset)['demo'] === '4px');

Extensions::register_variables('demo', function ($variables, $asset) {
    $variables['demo'] = '8px';
    return $variables;
});

check('re-registering a slug replaces it', Extensions::apply_variables([], $asset)['demo'] === '8px');
check('without listing it twice',          Extensions::providers('variables') === ['demo']);

section('The asset reaches the provider');

Extensions::register_variables('sees-asset', function ($variables, $asset) {
    $variables['handle'] = $asset->handle;
    return $variables;
});

check('by handle', Extensions::apply_variables([], $asset)['handle'] === 'entry');

Extensions::unregister('variables', 'sees-asset');
Extensions::unregister('variables', 'demo');

section('Load paths');

Extensions::register_load_paths('extra', function ($paths, $asset) {
    $paths[] = '/somewhere/else';
    return $paths;
});

check('appended', Extensions::apply_load_paths(['/first'], $asset) === ['/first', '/somewhere/else']);
Extensions::unregister('load_paths', 'extra');

section('Engines');

Extensions::register_engine('force-scssphp', function ($engine, $asset) {
    return new Sassy\Scssphp_Engine();
});

check('resolved', Extensions::resolve_engine(null, $asset) instanceof Sassy\Scssphp_Engine);
check('a provider sees what came before', Extensions::resolve_engine(null, $asset) !== null);
Extensions::unregister('engines', 'force-scssphp');

section('A post-processor can change the CSS and report');

Extensions::register_post_processor('shouty', function ($css, Post_Process_Context $context) {
    $context->notice('shouted about ' . $context->get_asset()->handle);
    return strtoupper($css);
});

$context = new Post_Process_Context($asset);
$out     = Extensions::post_process('.a{color:red}', $context);

check('css transformed',      $out === '.A{COLOR:RED}');
check('one diagnostic',       count($context->get_diagnostics()) === 1);
check('a notice',             $context->get_diagnostics()[0]->severity === 'notice');
check('attributed to Sassy',  $context->get_diagnostics()[0]->source === 'sassy');
check('naming the asset',     str_contains($context->get_diagnostics()[0]->message, 'entry'));

section('And its reports reach the Printer');

$printer = new Sassy\Printer();
$printer->compile($BASE . 'entry.scss', 'entry');

$reported = array_filter($printer->get_warnings(), function ($d) {
    return str_contains($d->message, 'shouted about');
});

check('the notice arrived',    count($reported) === 1, (string) count($printer->get_warnings()));
check('the compile succeeded', !$printer->has_error(), (string) Diagnostic::render_all($printer->get_errors()));

Extensions::unregister('post_processors', 'shouty');

section('Registration timing is per asset, not global');

$first = new Sassy\Printer();
$first->compile($BASE . 'entry.scss', 'first-handle');

Extensions::register_variables('late', function ($variables, $asset) {
    $variables['late'] = '1px';
    return $variables;
});

$second = new Sassy\Printer();
$second->compile($BASE . 'entry.scss', 'second-handle');

check('the earlier asset never saw it', !isset($first->get_variables()['late']));
check('the later one did',               isset($second->get_variables()['late']));

Extensions::unregister('variables', 'late');

section('Raw filters still bind, and stay unlisted');

$GLOBALS['filter_overrides']['sassy-variables'] = ['from-filter' => '2px'];

check('the filter applies',   (new Sassy\Variable_Resolver($asset))->get_variables()['from-filter'] === '2px');
check('but is not a provider', Extensions::providers('variables') === []);

unset($GLOBALS['filter_overrides']['sassy-variables']);

finish();
