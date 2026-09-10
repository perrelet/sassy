<?php

/**
 * Phase 6b: the admin page, and the stylesheet Sassy compiles for herself.
 *
 * The stylesheet is authored for scssphp, the default engine, so a default install styles the
 * page. Dart is checked too when the binary is present, because that is what staging runs.
 */

require __DIR__ . '/bootstrap.php';

use Sassy\Admin_Page;
use Sassy\Compile_Cache;
use Sassy\Compile_Request;
use Sassy\Dart_Sass_Engine;
use Sassy\Diagnostic;
use Sassy\Import_Graph;
use Sassy\Scssphp_Engine;
use Sassy\Style_Stack;

require $SASSY_PLUGIN . 'include/view/ui.class.php';
require $SASSY_PLUGIN . 'include/view/admin-page.class.php';

section('Sassy compiles her own stylesheet');

$scss = $GLOBALS['SASSY_PLUGIN'] . 'assets/scss/sassy.scss';

check('the source exists', file_exists($scss), $scss);

$request = function ($style) use ($scss) {
    return new Compile_Request([
        'source'      => (string) @file_get_contents($scss),
        'source_path' => $scss,
        'load_paths'  => [dirname($scss)],
        'variables'   => [],
        'style'       => $style,
        'source_map'  => false,
    ]);
};

$result = (new Scssphp_Engine())->compile($request('expanded'));

check('on scssphp, the default engine', $result->ok(), (string) $result->error);
check('with nothing to report',         $result->diagnostics === []);
check('and it styles the notice',       $result->css && str_contains($result->css, '#sassy-notice'));

if (!dart_available()) {

    skip('on Dart Sass, compressed as staging runs it', 'sass binary not installed');

} else {

    $dart = (new Dart_Sass_Engine('sass'))->compile($request('compressed'));

    check('on Dart Sass, compressed as staging runs it', $dart->ok(), (string) $dart->error);
    check('with nothing to report there either',        $dart->diagnostics === []);

}

section('Cited files resolve against the recorded graph by path suffix');

$graph = new Import_Graph([
    '/srv/site/scss/_x.scss'            => [1, 1],
    '/srv/site/scss/parts/_x.scss'      => [1, 1],
    '/srv/site/scss/_y.scss'            => [1, 1],
    '/srv/site/lattice/lib/_break.scss' => [1, 1],
]);

check('a bare basename with one match resolves',     Admin_Page::resolve_file('_y.scss', $graph) === '/srv/site/scss/_y.scss');
check('a colliding basename stays unresolved',       Admin_Page::resolve_file('_x.scss', $graph) === null);
check('a longer suffix disambiguates it',            Admin_Page::resolve_file('parts/_x.scss', $graph) === '/srv/site/scss/parts/_x.scss');
check('a cwd-relative path resolves',                Admin_Page::resolve_file('lattice/lib/_break.scss', $graph) === '/srv/site/lattice/lib/_break.scss');
check('a recorded absolute path is itself',          Admin_Page::resolve_file('/srv/site/scss/_y.scss', $graph) === '/srv/site/scss/_y.scss');
check('a name nothing recorded is unresolved',       Admin_Page::resolve_file('_nope.scss', $graph) === null);
check('no graph resolves nothing',                   Admin_Page::resolve_file('_y.scss', null) === null);
check('a prefix is not a suffix',                    Admin_Page::resolve_file('y.scss', $graph) === null);

// scssphp cites URLs, Dart cites paths; the rule lives on the graph and takes both.
check('a URL source reduces to its path',            $graph->find('http://test.local/srv/site/scss/_y.scss') === '/srv/site/scss/_y.scss');
check('an absolute path not in the graph is null',   $graph->find('/srv/elsewhere/_y.scss') === null);

$REAL = ABSPATH . 'wp-content/themes/t/real';
fixture("$REAL/parts/_z.scss", ".z { color: red; }\n");
$loose = new Import_Graph(["$REAL/parts/../parts/_z.scss" => [1, 1]]);

check('a non-canonical recorded path still matches',   $loose->find('_z.scss') === "$REAL/parts/../parts/_z.scss");
check('and a canonical citation finds the recorded key', $loose->find("$REAL/parts/_z.scss") === "$REAL/parts/../parts/_z.scss");

section('The page renders the model');

$SCSS = ABSPATH . 'wp-content/themes/t';
$BASE = 'http://test.local/wp-content/themes/t/';

fixture("$SCSS/_shared.scss", "\$pad: 4px;\n@warn 'careful';\n");
fixture("$SCSS/warned.scss",  "@import 'shared';\n.a { padding: \$pad; }\n");
fixture("$SCSS/never.scss",   ".b { color: red; }\n");
fixture("$SCSS/broken.scss",  ".c { color: }\n");

compile($BASE . 'warned.scss', 'warned');
compile($BASE . 'broken.scss', 'broken');

wp_styles()->add('warned', $BASE . 'warned.scss', ['base']);
wp_styles()->add('never',  $BASE . 'never.scss');
wp_styles()->add('broken', $BASE . 'broken.scss');
wp_styles()->add('base',   '/wp-admin/css/common.min.css');
wp_styles()->add('bundle', false, ['base']);
wp_styles()->add('evil<b>', 'https://cdn.example.com/x.css');

$data = Admin_Page::data(Style_Stack::discover());
$html = Admin_Page::html($data);

$kinds = array_column($data['stack'], 'kind', 'handle');

check('every handle is a row',          count($data['stack']) === 6);
check('a built handle is managed',      ($kinds['warned'] ?? null) === 'managed');
check('an unbuilt one is compilable',   ($kinds['never'] ?? null) === 'compilable');
check('a failed one is compilable',     ($kinds['broken'] ?? null) === 'compilable', $kinds['broken'] ?? 'none');
check('css is third-party',             ($kinds['base'] ?? null) === 'third-party');
check('a per-handle section per compilable handle', array_keys($data['handles']) === ['warned', 'never', 'broken']);
check('WP deps survive',                str_contains($html, '<td>base</td>'));
check('a handle is escaped',            str_contains($html, 'evil&lt;b&gt;') && !str_contains($html, 'evil<b>'));

section('Check findings are on the page');

$messages = array_map(function ($f) { return $f->code . ': ' . $f->message; }, $data['findings']);

check('the unbuilt handle is a finding', in_array('never: Never built. Run wp sassy compile.', $messages, true), implode(' | ', $messages));
check('the failed one is stale',         in_array('broken: Stale. Run wp sassy compile.', $messages, true) || in_array('broken: Never built. Run wp sassy compile.', $messages, true));
check('rendered in the findings table',  str_contains($html, 'sassy-findings') && str_contains($html, 'Never built.'));

section('Diagnostics render as header plus verbatim body, with the canonical text kept');

$recorded  = Compile_Cache::get_diagnostics('warned');
$warning   = $recorded['diagnostics'][0] ?? null;
$canonical = $warning ? $warning->render() : '';

check('the warning was recorded',                     $warning && $warning->severity === 'warning');
check('its canonical text is in the markup verbatim', $canonical !== '' && str_contains($html, esc_html($canonical)));
check('inside a hidden pre for copy',                 preg_match('/<pre id="sassy-warned-handle-0" class="sassy-canonical" hidden>/', $html) === 1);
check('with a copy button pointing at it',            str_contains($html, 'data-sassy-copy-from="sassy-warned-handle-0"'));
check('and a copy-all for the handle',                str_contains($html, 'data-sassy-copy-from="sassy-warned-handle-canonical"'));
check('the location is click-to-copy',                preg_match('/data-sassy-copy="[^"]*_shared\.scss:2(:\d+)?"/', $html) === 1);
check('the header shows the first message line',      str_contains($html, '<span class="sassy-message">careful</span>'));
check('the failure is recorded and shown',            str_contains($html, '<details class="sassy-group" open>'));

section('Actions');

check('Compile all is declared, not wired',  str_contains($html, 'data-sassy-action="compile"'));
check('Clear cache is a nonced POST',        str_contains($html, 'name="action" value="sassy_clear"') && str_contains($html, "value='nonce:sassy-clear'"));

finish();
