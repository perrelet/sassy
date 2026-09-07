<?php

/**
 * The diagnostic schema and its canonical rendering.
 *
 * 2.x flattened everything the engines knew into a string and an array of stderr lines: one
 * live compile reported 54 warnings for 6 actual diagnostics.
 */

require __DIR__ . '/bootstrap.php';

use Sassy\Diagnostic;

section('Fields');

$d = new Diagnostic(Diagnostic::ERROR, 'Undefined variable.', [
    'file'   => '_layout.scss',
    'line'   => 41,
    'column' => 10,
    'code'   => 'global-builtin',
    'url'    => 'https://sass-lang.com/d/import',
]);

check('severity',        $d->severity === 'error');
check('message',         $d->message === 'Undefined variable.');
check('is_error',        $d->is_error());
check('source defaults to engine', $d->source === 'engine');
check('absent fields are null',    $d->frame === null && $d->trace === null);
check('to_array carries all ten',  count($d->to_array()) === 10);

$sassy = new Diagnostic(Diagnostic::NOTICE, 'Lightning CSS stripped the source map link.', ['source' => 'sassy']);
check('a Sassy-source notice',     $sassy->source === 'sassy' && !$sassy->is_error());

section('Canonical rendering');

// The plan's worked example, reproduced from sass 1.92.0.
$frame = "   ╷\n41 │     gap: \$gap;\n   │          ^^^^\n   ╵";
$trace = "  _layout.scss 41:10  layout()\n  frontend.scss 12:1  root stylesheet";

$full = new Diagnostic(Diagnostic::ERROR, 'Undefined variable.', [
    'file' => '_layout.scss', 'line' => 41, 'column' => 10, 'frame' => $frame, 'trace' => $trace,
]);

$expected = "ERROR  _layout.scss:41:10  Undefined variable.\n" . $frame . "\n" . $trace;

check('matches the plan byte for byte', $full->render() === $expected, $full->render());

section('Rendering degrades with what is missing');

check('no frame, trace kept',
    (new Diagnostic(Diagnostic::WARNING, 'careful', ['file' => 'a.scss', 'line' => 1, 'column' => 1, 'trace' => "  a.scss 1:1  root stylesheet"]))->render()
    === "WARNING  a.scss:1:1  careful\n  a.scss 1:1  root stylesheet");

check('no position at all',
    (new Diagnostic(Diagnostic::NOTICE, 'something happened'))->render() === 'NOTICE  something happened');

check('line without column',
    (new Diagnostic(Diagnostic::WARNING, 'w', ['file' => 'a.scss', 'line' => 7]))->render() === 'WARNING  a.scss:7  w');

check('the engine drawing is not redrawn',
    str_contains((new Diagnostic(Diagnostic::ERROR, 'e', ['frame' => "  ,\n1 | .a{}\n  '"]))->render(), "  ,\n1 | .a{}\n  '"));

section('Multi-line messages');

$wrapped = new Diagnostic(Diagnostic::DEPRECATION, "Global built-in functions are deprecated.\nUse math.percentage instead.", ['file' => 'a.scss', 'line' => 2, 'column' => 13]);

check('the header keeps one line',  str_contains($wrapped->render(), "DEPRECATION  a.scss:2:13  Global built-in functions are deprecated.\n"));
check('the remainder is not dropped', str_contains($wrapped->render(), 'Use math.percentage instead.'));

section('render_all');

$two = Diagnostic::render_all([
    new Diagnostic(Diagnostic::WARNING, 'first',  ['file' => 'a.scss', 'line' => 1]),
    new Diagnostic(Diagnostic::WARNING, 'second', ['file' => 'b.scss', 'line' => 2]),
]);

check('blank line between diagnostics', $two === "WARNING  a.scss:1  first\n\nWARNING  b.scss:2  second", $two);
check('empty renders empty',            Diagnostic::render_all([]) === '');

section('Compile_Result carries them');

$result = new Sassy\Compile_Result('.a{}', null, null, null, [
    new Diagnostic(Diagnostic::ERROR, 'boom'),
    new Diagnostic(Diagnostic::WARNING, 'careful'),
    new Diagnostic(Diagnostic::DEPRECATION, 'going away', ['code' => 'import']),
    new Diagnostic(Diagnostic::NOTICE, 'fyi', ['source' => 'sassy']),
]);

check('errors',        count($result->errors()) === 1);
check('warnings exclude deprecations', count($result->warnings()) === 1);
check('deprecations',  count($result->deprecations()) === 1);
check('has_errors',    $result->has_errors());
check('an error makes it not ok', !$result->ok());

$clean = new Sassy\Compile_Result('.a{}', null, null, null, [new Diagnostic(Diagnostic::WARNING, 'careful')]);
check('warnings alone stay ok', $clean->ok());
check('the legacy error field still fails it', !(new Sassy\Compile_Result(null, null, 'broke'))->ok());

section('scssphp produces them');

$engine = new Sassy\Scssphp_Engine();
$src    = '/var/www/site/scss/frontend.scss';

$warned = $engine->compile(['scss' => "@warn \"careful\";\n.a { color: red; }\n", 'src_path' => $src]);
$w      = $warned->warnings()[0] ?? null;

check('a @warn becomes a warning',  $w && $w->severity === 'warning');
check('with the message',           $w && $w->message === 'careful');
check('located from the trace',     $w && $w->file === $src && $w->line === 1, $w ? "{$w->file}:{$w->line}" : 'none');
check('carrying the trace',         $w && str_contains((string) $w->trace, 'root stylesheet'));
check('and no frame',               $w && $w->frame === null);
check('the compile still succeeded', $warned->ok());

$deprecated = (new Sassy\Scssphp_Engine())->compile(['scss' => "@if false {} @elseif true {}\n", 'src_path' => $src]);
$d          = $deprecated->deprecations()[0] ?? null;

check('a deprecation is not a warning', $d && $d->severity === 'deprecation');
check('carrying the engine code',       $d && $d->code === 'elseif', $d ? (string) $d->code : 'none');
check('located from the span',          $d && $d->file === $src && $d->column === 14);

$failed = (new Sassy\Scssphp_Engine())->compile(['scss' => ".a { color: \$nope; }\n", 'src_path' => $src]);
$e      = $failed->errors()[0] ?? null;

check('a SassException becomes an error', $e && $e->severity === 'error');
check('with the original message',        $e && $e->message === 'Undefined variable.');
check('located',                          $e && $e->file === $src && $e->line === 1 && $e->column === 13);
check('and the result is not ok',         !$failed->ok());

section('Modules are still unsupported, and say so with a location');

$modules = (new Sassy\Scssphp_Engine())->compile(['scss' => "@use 'x';\n", 'src_path' => $src]);
$m       = $modules->errors()[0] ?? null;

check('refused',            $m && $m->is_error());
check('naming the file',    $m && $m->file === $src);
check('not a vendor trace', $m && !str_contains($m->message, 'vendor'));

finish();
