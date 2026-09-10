<?php

/**
 * The error panel reports what has failed by the time it is asked, not by the time it was first
 * asked. print_errors() runs at wp_footer 10 and print_late_styles() at 20, so a footer-enqueued
 * style that failed was rendered into an already-empty panel.
 */

require __DIR__ . '/bootstrap.php';

require $SASSY_PLUGIN . 'include/sassy.class.php';
require $SASSY_PLUGIN . 'include/view/ui.class.php';

$SCSS = ABSPATH . 'wp-content/themes/t';
$BASE = 'http://test.local/wp-content/themes/t/';

fixture("$SCSS/good.scss", ".a { color: red; }\n");
fixture("$SCSS/bad.scss",  ".a { color: }\n");

$sassy = new Sassy\Sassy();

section('A style that is not SCSS passes through');

check('untouched', $sassy->style_loader_src('http://test.local/x.css', 'x') === 'http://test.local/x.css');
check('and gets no printer', $sassy->get_printers() === []);

section('Errors are read when asked, not remembered from the first ask');

$sassy->style_loader_src($BASE . 'good.scss', 'good');

check('nothing wrong in the head', $sassy->get_errors() === []);
check('has_error() agrees',        !$sassy->has_error());

// A footer-enqueued style, compiled after print_errors() has already asked once.
$sassy->style_loader_src($BASE . 'bad.scss', 'bad');
$errors = $sassy->get_errors();

check('the later failure is reported',  isset($errors['sassy-bad']));
check('keyed by the admin bar node id', array_keys($errors) === ['sassy-bad']);
check('rendered through Diagnostic',    str_starts_with($errors['sassy-bad'] ?? '', 'ERROR  '));
check('has_error() agrees',             $sassy->has_error());

section('The panel carries the list of sheets the JS may paint');

$GLOBALS['capabilities'] = ['edit_theme_options'];
ob_start(); $sassy->print_errors(); $panel = ob_get_clean();

preg_match("/data-sassy-sheets='([^']*)'/", $panel, $m);
$sheets = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES, 'UTF-8'), true);

check('the panel prints for a dev',                str_starts_with($panel, "<div id='sassy-errors' class='show'"));
check('with every printer listed',                array_keys($sheets ?? []) === ['good', 'bad']);
check('by build URL',                             ($sheets['good']['href'] ?? '') === 'http://test.local/wp-content/scss/good.css');
check('map URL',                                  ($sheets['good']['map'] ?? '') === 'http://test.local/wp-content/scss/good.css.map');
check('and source path',                          ($sheets['good']['source'] ?? '') === "$SCSS/good.scss");
check('a failed handle has no map',               array_key_exists('map', $sheets['bad'] ?? []) && $sheets['bad']['map'] === null);
check('the error is escaped, not raw',            !str_contains($panel, '<pre class=\'sassy-error\'>ERROR  <'));

$GLOBALS['capabilities'] = [];
ob_start(); $sassy->print_errors(); $closed = ob_get_clean();

check('and nothing prints when the gate is closed', $closed === '');

section('The write endpoint: two gates, the nonce, the method');

/** Call the endpoint as admin-ajax would, and return what it sent. */
function write_source ($sassy, array $request = []) {
    $_SERVER['REQUEST_METHOD'] = $request['method'] ?? 'POST';
    $_REQUEST['nonce'] = $request['nonce'] ?? 'nonce:sassy_write';
    $_POST['changes']  = isset($request['changes']) ? json_encode($request['changes']) : '';
    $GLOBALS['json']   = null;
    try { $sassy->write_source(); } catch (Sassy_Test_Exit $e) {}
    return $GLOBALS['json'];
}

$change = ['handle' => 'good', 'source' => 'good.scss', 'line' => 1, 'prop' => 'color', 'from' => 'red', 'to' => 'blue'];

$GLOBALS['capabilities'] = [];
$r = write_source($sassy, ['changes' => [$change]]);
check('the dev gate first: 403',                 ($r['code'] ?? 0) === 403 && !$r['success']);

$GLOBALS['capabilities'] = ['edit_theme_options'];
$r = write_source($sassy, ['changes' => [$change]]);
check('then the write gate, off by default: 403', ($r['code'] ?? 0) === 403 && str_contains((string) $r['data'], 'sassy-write-source'), var_export($r, true));

$GLOBALS['filter_overrides']['sassy-write-source'] = true;
$r = write_source($sassy, ['changes' => [$change], 'nonce' => 'wrong']);
check('then the nonce: 401',                     ($r['code'] ?? 0) === 401);

$r = write_source($sassy, ['changes' => [$change], 'method' => 'GET']);
check('then the method: 405',                    ($r['code'] ?? 0) === 405);

$r = write_source($sassy, []);
check('a body that is not a list: 400',          ($r['code'] ?? 0) === 400);

check('the source is as compiled',                file_get_contents("$SCSS/good.scss") === ".a { color: red; }\n");

$r = write_source($sassy, ['changes' => [$change]]);
check('with all three passing, it writes',       $r['success'] && ($r['data']['results'][0]['written'] ?? false), var_export($r, true));
check('and the file changed',                     file_get_contents("$SCSS/good.scss") === ".a { color: blue; }\n");

$r = write_source($sassy, ['changes' => [array_merge($change, ['from' => 'blue', 'to' => 'green'])]]);
check('a second push before a compile refuses',  !($r['data']['results'][0]['written'] ?? true) && str_contains($r['data']['results'][0]['reason'] ?? '', 'changed since the last compile'));

$r = write_source($sassy, ['changes' => [array_merge($change, ['handle' => 'never-compiled'])]]);
check('a handle with no graph refuses',          str_contains($r['data']['results'][0]['reason'] ?? '', 'no import graph'));

$r = write_source($sassy, ['changes' => ['not a change']]);
check('a malformed change refuses, in place',    ($r['data']['results'][0]['reason'] ?? '') === 'no handle');

unset($GLOBALS['filter_overrides']['sassy-write-source']);
$GLOBALS['capabilities'] = [];

finish();
