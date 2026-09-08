# Upgrading to Sassy 3.0

What changes for a site running Sassy, and what to do about it.

**In progress.** 3.0 ships phases 1-6 of [style-stack-plan.md](style-stack-plan.md), and each phase adds its breaks here as it lands. Anything not listed below has not changed yet. Landed so far: **phases 1 to 5**.

Nothing on 1.x is auto-updated to 3.0. The digitalis.ca update JSON is version-fenced before 3.0 publishes, so a wild 1.x install is never offered a breaking upgrade.

---

## Phase 1: the style stack

Sassy now models every registered stylesheet, not only the ones whose URL ends in `.scss`. On the reference install that is 337 handles instead of 1.

### `$digitalis_styles` is no longer read

**Who this affects:** sites where the Digitalis framework (or anything else) registered styles into a second `WP_Styles` registry held in the `$digitalis_styles` global. Sassy used to merge that registry with `wp_styles()->registered` before looking for SCSS.

**What happens if you do nothing:** styles registered *only* in that second registry stop being discovered. They will not compile and will not appear in `wp sassy list`.

**What to do:** register the queue through the new filter.

```php
add_filter('sassy-style-queues', function ($queues) {

    global $digitalis_styles;
    if ($digitalis_styles) $queues[] = $digitalis_styles;

    return $queues;

});
```

Queues are read in order and a later one wins on a duplicate handle, matching the old hard-coded `array_merge`.

**If your site uses Lattice's `Theme::enqueue_style_last()`, this is you.** That method populates `$digitalis_styles`. It is unchanged and still enqueues your styles (WordPress prints them either way); it is Sassy that stops looking in there, so anything registered through it needs the filter above.

The intended fix is for Lattice to register the filter once on behalf of every site that calls it, rather than each site adding the snippet. Until it does, the snippet is the workaround.

### `Sassy::get_scss_styles()` is removed

**Who this affects:** any code calling it directly.

**What to do:** use `Style_Stack`.

```php
$stack = Sassy\Style_Stack::discover(['frontend']);   // [] reads the queues as they stand

$stack->all();          // every registered style, as Assets keyed by handle
$stack->compilable();   // just the ones Sassy builds
$stack->handle('my-theme');
```

An `Asset` carries `handle`, `type`, `src`, `deps` (the WordPress handle dependencies, which Sassy previously discarded), `extension` and `get_source_path()`.

### `sassy-src-path` runs in more cases, and its last argument changed

**Who this affects:** anyone filtering `sassy-src-path`.

- **It now runs even when Sassy could not resolve the URL**, receiving `null` as the value. In 2.x it ran only after resolution had already succeeded, so the one filter documented as "override the source path" could not rescue the case that most needed it: a CDN-hosted or otherwise unresolvable URL. Returning a path from `null` places the asset; returning `null` leaves it unresolved.
- **Its fourth argument is now the `Asset`**, not the `SCSS_Compiler`. Callbacks that ignore the argument (most do) need no change.

### Root-relative sources now resolve

**Who this affects:** nobody who has to act, but the behaviour is visibly different.

A style registered as `/wp-admin/css/common.min.css` (no scheme, no host) used to be treated as remote and unresolvable. It now resolves against `ABSPATH`. This is why 65 of the reference install's core stylesheets went from invisible to modeled.

Relatedly, `source_path` is `null` only when the URL **maps nowhere**: a remote host, a hostless src that is not root-relative, or `src === false`. A missing file still resolves, so an error can name the path it looked at instead of echoing the URL back.

### `wp sassy list` reports everything

**Who this affects:** anything parsing its output.

- It lists **every** discovered handle, not only the buildable ones. `--compilable` restores the old narrowing.
- New columns `type` and `imports`.
- **`deps` changed meaning.** It was the count of files in the recorded import graph; it is now the WordPress handle dependency list, as an array under `--format=json` and `--format=yaml`, comma-joined for `table` and `csv`. The old meaning is now `imports`.
- Rows for handles Sassy does not build carry identity and `source` only. `state`, `engine`, `time`, `imports` and `built` are empty for them.

Asking to compile a handle that cannot be now says why: unregistered, registers no source of its own, not a local file, or not compilable with the extension named.

---

## Phase 2: one service, thin surfaces

`SCSS_Compiler` held five responsibilities and every surface reached into it for a different one. It is now four classes.

### `SCSS_Compiler` is renamed `Printer`

**Who this affects:** anything type-hinting it, instantiating it, or reading it off `SASSY()`.

| Before | After |
|---|---|
| `Sassy\SCSS_Compiler` | `Sassy\Printer` |
| `SASSY()->get_compilers()` | `SASSY()->get_printers()`, keyed by a 1-based index |
| `$compiler->get_index()` | the array key in `get_printers()` |
| `SCSS_Compiler::prepend_variables()` | `Variable_Resolver::prepend()` |
| `SCSS_Compiler::temp_file()` | gone; each backend owns its own |

Path math moved to `Build_Target`, currency to `Compile_Cache`, values to `Variable_Resolver`. `Printer` still exposes `get_build_file()`, `get_build_url()`, `get_variables()`, `is_current()` and the rest, so code that only reads those is unaffected.

### The fourth filter argument is now the `Asset`

**Who this affects:** any callback that uses the fourth argument of a per-compile filter. Callbacks that ignore it, which is most, need no change.

Every per-compile filter (`sassy-build-*`, `sassy-variables`, `sassy-style`, `sassy-import-paths`, `sassy-src-map`, `sassy-css`, `sassy-force-compile`, `sassy-check-dependencies` and the rest) now receives `($value, $src, $handle, $asset)` instead of the compiler. `sassy-engine` receives `($engine, $asset)`.

Two exceptions to the shape, unchanged from 2.x: `sassy-import-paths` passes the resolved *source path* as its second argument rather than the source URL, and the `sassy-compiler` action is engine-specific, receiving scssphp's own compiler object plus the `Compile_Request`.

The `Asset` is available before a compile starts and carries no build state, so a filter can no longer reach through it into paths or the cache. Ask `Build_Target` or `Compile_Cache` for those.

### Cache transients are owned by `Compile_Cache`

**Who this affects:** anything reading `sassy-filemtimes-{handle}` or `sassy-vars-sig-{handle}` directly.

The keys are unchanged, but treat them as private. `Compile_Cache::get_graph($handle)`, `::get_last_compile_time($handle)`, `::forget_handle($handle)` and `::forget_all()` are the supported way in.

---

## Phase 3: engine contract and diagnostics

### Warnings and errors are `Diagnostic` objects

**Who this affects:** anything reading `get_warnings()` or `get_error()` off a printer, or `Compile_Result::$info`.

`get_warnings()` returned an array of strings and now returns `Sassy\Diagnostic` objects; `get_error()` returned a string and now returns the first error `Diagnostic`, or `null`. Interpolating either into a string fatals.

```php
// before
implode("\n", $compiler->get_warnings());
echo $compiler->get_error();

// after
Sassy\Diagnostic::render_all($compiler->get_warnings());
Sassy\Diagnostic::render_all($compiler->get_errors());
```

`Diagnostic` carries `severity` (`error`, `warning`, `deprecation`, `notice`), `message`, `file`, `line`, `column`, `frame`, `trace`, `code`, `url` and `source`. `render()` produces the canonical text every surface uses; `to_array()` is the JSON shape. `Compile_Result::$info` is removed, replaced by `$diagnostics` with `errors()`, `warnings()`, `deprecations()` and `has_errors()`.

Counts change meaning. A single Dart diagnostic was previously reported as one warning per line of stderr, so the numbers drop sharply: one real compile went from 97 to 10.

### `sassy-src-map-options` is removed

**Who this affects:** anyone filtering it.

The filter's entire value surface was scssphp's own option names, so it leaked one engine's API into the contract. `Compile_Request` carries `map_path` and `map_url` instead, and `Scssphp_Engine` derives `sourceMapBasepath` and `sourceMapRootpath` internally. To relocate a map, filter `sassy-build-path` or `sassy-build-directory`.

`Printer::get_src_map_options()` is gone. Use `get_map_path()` and `get_map_url()`.

### Engines take a `Compile_Request` and declare capabilities

**Who this affects:** anyone with a custom `Compiler_Engine`.

```php
public function compile (Compile_Request $request) : Compile_Result;
public function capabilities () : array;   // e.g. ['modules', 'source_maps', 'compressed']
public function supports (string $capability) : bool;
```

The untyped `$args` array is gone. `$request` carries `source`, `source_path`, `load_paths`, `variables`, `style`, `source_map`, `map_path` and `map_url`. The `sassy-compiler` action now receives the request as its second argument rather than the args array.

`Scssphp_Engine` declares `source_maps` and `compressed`; `Dart_Sass_Engine` adds `modules`. A `@use` or `@forward` under scssphp is refused with the file, the line and the remedy rather than a bare "Sass modules are not implemented yet".

**`--strict=all` will differ per engine** when phase 5 lands, because scssphp implements a fraction of Dart's deprecations. That is intended.

### Dart Sass runs with `--verbose`

Dart withholds repeated deprecations by default and reports only that it did. Sassy now asks for all of them, so expect more diagnostics from the same source than 2.x reported, and all of them were always there.

---

## Phase 4: extension API

### The built-in builder integrations are removed

**Who this affects:** anyone running Bricks Builder, Oxygen Builder or the Digitalis Framework and relying on Sassy to turn their settings into SCSS variables. This is the most visible break in 3.0.

Gone with them: `$c-*` colours, `$f-*` fonts, `$b-*` breakpoints, the `$breakpoints` map, `$sec-px`, `$sec-py`, `$col-px`, `$col-py`, `$digitalis_path` and `$digitalis_uri`. SCSS referencing any of those stops compiling with an undefined variable error, which is at least loud.

**What to do:** copy the fixture. `tests/fixtures/bricks.php`, `oxygen.php` and `digitalis.php` in the plugin are each integration rebuilt on the extension API, roughly forty lines, and they produce exactly what the built-in versions produced. Drop one into your theme or plugin and register it.

**Why:** neither builder is installed on the machine Sassy is developed on, so the code shipped with no test surface. Two real bugs got in that way. As fixtures they are tested against stubbed builder APIs on every run, and as your code they are visible and yours to change.

### There is now a registry alongside the filters

**Who this affects:** nobody who has to act. Every filter works exactly as before.

Four points can be extended: load paths, variables, post-processors and engines. Registering gives a provider a name, a typed signature and a line in `wp sassy status`.

```php
add_action('sassy-register', function () {

    Sassy\Extensions::register_variables('my-theme', function (array $variables, Sassy\Asset $asset) {
        $variables['brand'] = '#ff0000';
        return $variables;
    });

});
```

Registering later than `sassy-register` works, as long as it happens before the asset in question compiles. Timing is per asset: each `Printer` resolves variables lazily, so a provider registered between two compiles applies to the second and not the first.

### A post-processor can report; a `sassy-css` filter still cannot

**Who this affects:** anyone whose `sassy-css` callback can fail.

A filter takes a string and returns a string, so it has no way to say it did not run. Lightning CSS is the case in point: a missing binary or a failed process returned the CSS untouched and wrote to the PHP error log, which looked identical to success on every Sassy surface. It now reports, and so can yours if you register instead of filtering:

```php
Sassy\Extensions::register_post_processor('my-minifier', function ($css, $context) {
    $context->warn('my-minifier did not run; the CSS is unprocessed.');
    return $css;
});
```

`Lightning_CSS_Postprocessor::filter()` is replaced by `::process($css, $context)`.

### A new notice when the source map link disappears

If source maps are on and a map is written but the CSS coming out of post-processing has no `sourceMappingURL`, Sassy reports a notice naming the map. Any post-processor can cause this; Lightning CSS is the common one.

---

## Phase 5: reverse dependencies and `check`

Almost entirely additive. Two new commands, `wp sassy check` and `wp sassy deps --file=<path>`, and one changed signature.

### `Compile_Cache::record()` takes a third argument

**Who this affects:** anything calling it directly, which is `Printer` and nothing else in the plugin.

```php
record(Import_Graph $graph, $compile_time, array $diagnostics = [])
```

The diagnostics are stored as a severity tally under `__diagnostics__` in the existing `sassy-filemtimes-{handle}` record, so `check --strict` can gate on them without compiling. Counts only: that record is read on every request and a compile's frames run to kilobytes. `Compile_Cache::get_tally($handle)` reads them back. Existing records without the key simply report nothing until the next compile.

### `wp sassy deps` no longer requires a handle

`deps <handle>` is unchanged. `deps --file=<path>` reports the reverse direction, and the handle argument is now optional so one or the other can be given.

### `Diagnostic` gains a `fatal` flag

Set by `Style_Stack::audit()` for findings that fail a check regardless of severity, which today means a truncated import graph. Defaults to false, so nothing that constructs a `Diagnostic` needs changing.
