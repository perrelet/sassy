<?php

/**
 * The reverse dependency query and the audit behind wp sassy check.
 *
 * The import graph has recorded its edges since 2.x and never been read backwards.
 */

require __DIR__ . '/bootstrap.php';

use Sassy\Diagnostic;
use Sassy\Style_Stack;

$SCSS  = WP_CONTENT_DIR . '/themes/t/scss';
$BUILD = WP_CONTENT_DIR . '/scss';
$BASE  = 'http://test.local/wp-content/themes/t/scss/';

@mkdir($SCSS, 0777, true);
fixture("$SCSS/_shared.scss", "\$pad: 4px;\n");
fixture("$SCSS/a.scss", "@import 'shared';\n.a { padding: \$pad; }\n");
fixture("$SCSS/b.scss", "@import 'shared';\n.b { padding: \$pad; }\n");
fixture("$SCSS/lonely.scss", ".c { color: red; }\n");

wp_styles()->add('a', $BASE . 'a.scss');
wp_styles()->add('b', $BASE . 'b.scss');
wp_styles()->add('lonely', $BASE . 'lonely.scss');

foreach (['a', 'b', 'lonely'] as $handle) compile($BASE . "$handle.scss", $handle);

$stack = Style_Stack::discover();

section('dependents_of');

$dependents = $stack->dependents_of("$SCSS/_shared.scss");

check('names the importers',   array_keys($dependents) === ['a', 'b'], implode(',', array_keys($dependents)));
check('and not the innocent',  !isset($dependents['lonely']));
check('returns Assets',        $dependents['a'] instanceof Sassy\Asset);

check('a non-canonical path still matches',
    array_keys($stack->dependents_of("$SCSS/../scss/_shared.scss")) === ['a', 'b']);

check('an entry file is its own dependent', array_keys($stack->dependents_of("$SCSS/a.scss")) === ['a']);
check('an unknown file names nobody',       $stack->dependents_of('/tmp/nothing.scss') === []);

section('A clean stack audits clean');

check('nothing to report', $stack->audit() === [], count($stack->audit()) . ' findings');

section('A stale handle is an error');

fixture("$SCSS/_shared.scss", "\$pad: 12px;\n", 10);
clearstatcache();

$stale = $stack->audit();

check('two handles stale',  count($stale) === 2, (string) count($stale));
check('as errors',          $stale[0]->severity === 'error');
check('fatal without strict', $stale[0]->fatal);
check('naming the handle',  in_array($stale[0]->code, ['a', 'b'], true));
check('attributed to Sassy', $stale[0]->source === 'sassy');

foreach (['a', 'b'] as $handle) compile($BASE . "$handle.scss", $handle);
check('recompiling clears it', $stack->audit() === []);

section('A missing build file is an error');

unlink("$BUILD/a.css");
$missing = $stack->audit();

check('reported',          count($missing) === 1);
check('as never built',    str_contains($missing[0]->message, 'Never built'));
check('and fatal',         $missing[0]->fatal);

compile($BASE . 'a.scss', 'a');

section('Orphaned outputs');

file_put_contents("$BUILD/abandoned.css", '.x{}');
file_put_contents("$BUILD/abandoned.css.map", '{}');
@mkdir("$BUILD/.sassy-tmp", 0777, true);
file_put_contents("$BUILD/.hidden.css", '.y{}');

$orphans = $stack->orphaned_outputs();

check('the abandoned css is found', in_array("$BUILD/abandoned.css", $orphans, true));
check('and its map',                in_array("$BUILD/abandoned.css.map", $orphans, true));
check('the temp directory is not',  !in_array("$BUILD/.sassy-tmp", $orphans, true));
check('nor a dotfile',              !in_array("$BUILD/.hidden.css", $orphans, true));
check('nor anything claimed',       !in_array("$BUILD/a.css", $orphans, true));

$audit   = $stack->audit();
$flagged = array_values(array_filter($audit, function ($d) { return str_contains($d->message, 'Orphaned'); }));

check('reported as warnings',   count($flagged) === 2, (string) count($flagged));
check('not fatal',              !$flagged[0]->fatal);

@unlink("$BUILD/abandoned.css");
@unlink("$BUILD/abandoned.css.map");
@unlink("$BUILD/.hidden.css");

section('Truncation is a warning that fails anyway');

$recorded = get_transient('sassy-filemtimes-a');
$recorded['truncated'] = true;
set_transient('sassy-filemtimes-a', $recorded);

$truncated = array_values(array_filter($stack->audit(), function ($d) { return str_contains($d->message, 'truncated'); }));

check('reported',            count($truncated) === 1);
check('as a warning',        $truncated[0]->severity === 'warning');
check('but fatal regardless', $truncated[0]->fatal, 'a truncated graph makes the answer unknowable');

$recorded['truncated'] = false;
set_transient('sassy-filemtimes-a', $recorded);

section('The last compile\'s severities are remembered');

$tally = Sassy\Compile_Cache::get_tally('a');

check('a tally is recorded', is_array($tally));
check('a clean compile counts nothing', ($tally['error'] ?? 0) === 0);

set_transient('sassy-filemtimes-a', array_merge(get_transient('sassy-filemtimes-a'), ['__diagnostics__' => ['deprecation' => 3]]));

$deprecated = array_values(array_filter($stack->audit(), function ($d) { return $d->severity === 'deprecation'; }));

check('deprecations surface',  count($deprecated) === 1);
check('with the count',        str_contains($deprecated[0]->message, '3 deprecations'));
check('and are not fatal',     !$deprecated[0]->fatal);

finish();
