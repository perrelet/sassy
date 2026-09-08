# AGENTS.md — Sassy WordPress Plugin

> **On the `style-stack` branch, [docs/style-stack-plan.md](docs/style-stack-plan.md) is
> authoritative for all new work**, including its §8 execution protocol. This file describes the
> codebase as it currently stands. Read this to understand what exists; read the plan to know
> what to build. Where they disagree about the future, the plan wins; where they disagree about
> the present, this file wins — so **each phase updates this file as part of landing**, or the
> sentence you are reading becomes a trap.
>
> **Phases 1 to 5 have landed**: `Asset`, `Style_Stack`, `Printer`, `Build_Target`, `Compile_Cache`, `Variable_Resolver`, `Diagnostic`, `Compile_Request`, `Extensions`, plus `wp sassy check`. Phases 6 to 9 are still as the plan describes them.

## Overview

**Sassy** is a WordPress plugin (v2.1.0, by Digitalis Web Build Co.) that compiles SCSS files on-demand. The core premise: enqueue `.scss` files exactly as you would `.css` files via `wp_enqueue_style`, and Sassy intercepts the URL, compiles the SCSS to CSS, writes the result to disk, and returns the compiled CSS URL to WordPress instead.

```php
wp_enqueue_style('my-theme', get_template_directory_uri() . '/style.scss');
// Sassy transparently serves wp-content/scss/style.css
```

The plugin is **not** in the WordPress repository — it updates itself by polling `https://digitalis.ca/plugins/update/sassy/info`.

---

## Repository Layout

```
sassy/
├── sassy.php                          # Plugin entry point — constants, global SASSY() helper, WP-CLI registration
├── include/
│   ├── sassy.class.php                # Main plugin class (Sassy\Sassy)
│   ├── model/
│   │   ├── printer.class.php          # Produces one asset's output (was SCSS_Compiler)
│   │   ├── build-target.class.php     # Where output goes: path, URL, filename, source map
│   │   ├── compile-cache.class.php    # Is it current? Sole owner of the cache transients
│   │   ├── variable-resolver.class.php # What values an asset compiles with
│   │   ├── compile-request.class.php  # What an engine is asked to build
│   │   ├── compile-result.class.php   # What it produced, diagnostics included
│   │   ├── diagnostic.class.php       # One reportable event, and the canonical rendering
│   │   ├── extensions.class.php       # The four extension points, and who is extending them
│   │   ├── post-process-context.class.php  # What a post-processor gets besides the CSS
│   │   ├── scss-map.class.php         # PHP array → SCSS map syntax converter
│   │   ├── asset.class.php            # One enqueued thing; owns URL → filesystem resolution
│   │   ├── style-stack.class.php      # Discovery over the enqueue queues, and queries across them
│   │   ├── import-graph.class.php     # Recorded dependency set; answers "has anything changed?"
│   │   ├── import-resolver.class.php  # Sass file-resolution rules (partials, _index, load paths)
│   │   ├── import-scanner.class.php   # Walks the @use/@forward/@import graph into an Import_Graph
│   │   └── lightning-css-postprocessor.class.php  # Optional Lightning CSS post-processing
│   ├── engines/
│   │   ├── compiler-engine.interface.php            # Contract for engine implementations
│   │   ├── scssphp-engine.compiler-engine.php       # Default: PHP-native scssphp v2
│   │   ├── dart-sass-engine.compiler-engine.php     # Alternative: shells out to Dart Sass CLI
│   │   ├── dart-sass-parser.class.php               # Dart stderr into Diagnostics
│   │   └── scssphp-logger.class.php                 # scssphp warnings into Diagnostics
│   ├── admin/
│   │   ├── admin.class.php            # Admin loader (just boots Updater)
│   │   └── updater.class.php          # Custom update checker against digitalis.ca
│   ├── view/
│   │   └── ui.class.php              # Admin bar SCSS menu, clipboard support
│   └── cli/
│       └── sassy-cli-command.class.php  # WP-CLI: status, list, compile, watch, vars, deps, clear
├── assets/
│   └── css/sassy.css                 # Plugin's own admin styles (hand-maintained)
├── tests/                            # `php tests/run.php` — no PHPUnit, no WordPress
├── vendor/                           # Composer dependencies (scssphp/scssphp v2.x)
└── composer.json                     # Requires scssphp/scssphp ^2.1.0
```

---

## Boot Sequence

1. **`sassy.php`** — Defines constants, creates `new Sassy\Sassy()` stored in `$Sassy` global, registers `SASSY()` helper. Registers WP-CLI command if `WP_CLI` is defined.
2. **`plugins_loaded`** → `Sassy::boot()`:
   - Loads vendors (Composer autoload)
   - Loads model classes (require_once in order: `Diagnostic`, `Compile_Request`, `Compile_Result`, `Lightning_CSS_Postprocessor`, `Scss_Map`, `Asset`, `Style_Stack`, `Build_Target`, `Variable_Resolver`, `Compile_Cache`, `Import_Graph`, `Import_Resolver`, `Import_Scanner`, `Compiler_Engine` interface, engine implementations, `Printer`)
   - Loads view (`UI` class, instantiated immediately)
   - Registers `Lightning_CSS_Postprocessor::process` as the `lightning-css` post-processor
   - If `is_admin()`: loads and boots `Admin` → `Updater`
   - Registers `style_loader_src`, `wp_enqueue_scripts`, `wp_footer`, `admin_enqueue_scripts`, `admin_footer` hooks
3. **`after_setup_theme`** → `Extensions::boot()`, which fires the `sassy-register` action so providers have a place to register.

---

## Compilation Pipeline

### Trigger

`style_loader_src` filter intercepts any enqueued style whose URL ends in `.scss`. A fresh `Printer` is created per file and tracked in `Sassy::$printers`, keyed by a 1-based index the admin bar and its JS use for DOM ids.

### `Printer::compile($src, $handle)`

1. **Resolve source path** — delegates to `Asset`, which is the only implementation of URL → filesystem resolution. Handles multisite by normalizing the blog path. Filterable via `sassy-src-path`. See [The style stack](#the-style-stack) for what `null` means, and what it does not.
2. **Cache check** (`Compile_Cache::needs_compile()`) — skips actual compilation if:
   - `sassy-force-compile` filter returns false, AND
   - No file in the recorded import graph has changed (see [Dependency tracking](#dependency-tracking)), AND
   - `sassy-vars-sig-{handle}` transient matches sha1 of current serialized variables, AND
   - The compiled build file already exists on disk.
3. **Build the request** — a `Compile_Request` carrying source, source_path, load_paths (source dir + SASSY_PATH + DIGITALIS_FRAMEWORK_PATH if defined), variables, style, source_map, map_path and map_url. No key is any engine's option name.
4. **Delegate to engine** — calls `Compiler_Engine::compile($request)`, returns `Compile_Result` carrying `Diagnostic[]` whether it succeeded or failed.
5. **URL rewriting** — rewrites relative `url()` references in the compiled CSS to absolute paths based on the source SCSS location.
6. **`sassy-css` filter** — passes CSS through registered post-processors (Lightning CSS hooks here at priority 20).
7. **Write to disk** — CSS to `wp-content/scss/{name}.css`; source map alongside if enabled.
8. **Update transients** — refreshes filemtime cache.
9. Returns the compiled CSS URL (passing through any query string from the original `.scss` URL).

### Engine Selection

The engine is resolved lazily in `Printer::get_engine()`:
- Applies `sassy-engine` filter — return a `Compiler_Engine` instance to override.
- Default: `Scssphp_Engine` (no external binaries required).

### Variables

`Variable_Resolver::get_variables()` seeds three defaults, then applies the `sassy-variables` filter:

```php
[
    'wp-content-url'           => '"..."',
    'template-directory-url'   => '"..."',
    'stylesheet-directory-url' => '"..."',
]
```

After filtering, any array values are automatically converted to SCSS map syntax via `Scss_Map::from_array()` (supports nesting).

### URL scheme normalization

Variable values containing the site host are forced onto the scheme of the `home` option.

> Deliberately blunt: it is a string replace across every string-valued variable, so an
> intentionally `http` URL to your own host is rewritten too. It does not touch
> protocol-relative `//host` URLs, and it keys on `home` while content URLs derive from
> `siteurl` — the same host in practice, but the two options can legitimately differ.

Anything WordPress derives from `is_ssl()` — `get_template_directory_uri()` and friends — returns
`http` under WP-CLI, which makes no HTTPS request, while constants set in `wp-config.php` keep
their literal scheme. Left alone that mixes schemes inside one stylesheet, and gives a CLI compile
a different variable signature than a web request, so `wp sassy compile` would prime a cache the
first visitor immediately discards. The CLI also sets `$_SERVER['HTTPS']` up front, but that
cannot help values other plugins baked into constants at load time, which is why the
normalization runs over the final variable set.

### Caching Transients

Owned entirely by `Compile_Cache`. Nothing else reads or writes these keys.

| Transient key | Content | Invalidated when |
|---|---|---|
| `sassy-filemtimes-{handle}` | `build_file => filemtime`, `__compile_time__`, `deps`, `dirs`, `truncated` | Directory creation error; rewritten after each successful compile |
| `sassy-vars-sig-{handle}` | sha1 of serialized variables | Written only after a **successful** compile, so a failed one is never remembered as current |

### Dependency tracking

`Import_Scanner::scan()` walks the `@use` / `@forward` / `@import` graph from the entry file and
returns an `Import_Graph`, which is stored in the `sassy-filemtimes-{handle}` transient and owns
the "has anything changed?" question. It holds two sets:

- **`deps`** — `path => [mtime, size]` for every file the build was compiled from. A change to any of them
  (including a partial) triggers a recompile. This is what makes editing a partial work without
  touching the entry file.
- **`dirs`** — `dir => mtime` for directories searched during resolution, up to and including the
  one that won, **that currently hold at least one `.scss` or `.sass` file**. A directory's mtime
  moves when an entry is added or removed, which is exactly when a new file could shadow the
  candidate currently in use, or create an ambiguous pair.

  Sass-free load-path roots — the plugin directory, a framework root — are skipped. They churn
  for unrelated reasons and every such churn would invalidate every handle on the site. A
  directory *appearing* is still caught: creating it moves its parent's mtime, and the entry
  file's own directory is always watched. On one real project this cut the watch set from 145
  directories to 6.

The graph itself is only rebuilt when a compile happens. Adding an import necessarily changes the
mtime of the file declaring it, so between compiles a plain stat of the known set is sufficient.

Built-in modules (`sass:*`), remote URLs and plain-CSS imports are skipped. Over-inclusion is
deliberately preferred to under-inclusion: a spurious entry costs one unnecessary recompile,
a missing one serves stale CSS.

> **Why directories rather than paths.** Recording each individual path that was tried and missed
> is exact but does not scale: an import that resolves from the last load path tries every earlier
> candidate first. A real project measured 125 dependencies against **12,588** miss paths, roughly
> 15–25 ms of `file_exists()` per request. Watching directories brought the same project to 125
> deps + 145 dirs, about 0.85 ms.
>
> The trade is granularity: `filemtime()` is second-resolution, so a file created in the same
> second as the scan leaves the directory's mtime unchanged and is not seen. Any later change to
> any tracked file resolves it.
>
> Tracked *files* do not have this window — `deps` stores `[mtime, size]` from a single `stat`,
> so a second save inside the same second is still caught unless it happens to preserve the byte
> count exactly.

### Cost, and turning it off

The check is one `stat` per dependency plus one per watched directory, on every request, per
handle. The same project measures ~0.45 ms for 125 dependencies and 6 directories — a fraction of
a percent of a typical response, and it scales linearly with project size.

That assumes local disk. On NFS, EFS or a container bind mount, stat latency is an order of
magnitude higher and those calls become tens of milliseconds per request. Return false from
`sassy-check-dependencies` there and drive compilation explicitly instead:

```php
add_filter('sassy-check-dependencies', function ($check, $src, $handle, $compiler) {
    return defined('WP_DEBUG') && WP_DEBUG;   // watch in dev, deploy-compile in production
}, 10, 4);
```

With it off, a handle still compiles when it has never been built, when the build file is missing,
when variables change, and on `sassy-force-compile` — so `wp sassy compile --hooks=all` in a
deploy hook remains sufficient. What stops happening is noticing edits on disk.

---

## Compiler Engines

### `Scssphp_Engine` (default)

Uses `scssphp/scssphp` v2.x — pure PHP, no external processes.

- Variables passed as `ScssPhp\ScssPhp\Value\Value` instances (parsed via `ValueConverter::parseValue()`).
- Exposes the `sassy-compiler` action, passing the raw `ScssPhp\ScssPhp\Compiler` object for direct manipulation before compile. Engine-specific by nature, so it has no equivalent on Dart Sass.
- Source maps generated via `Compiler::SOURCE_MAP_FILE`.
- Warnings returned in `Compile_Result::info`.

> **scssphp v2.1.0 does not implement Sass modules.** `@use` and `@forward` throw
> `Sass modules are not implemented yet`; only `@import` works. This engine is the zero-dependency
> option for small projects, but anything written in modern Sass needs `Dart_Sass_Engine`.

### `Dart_Sass_Engine` (optional)

Shells out to Dart Sass CLI via `exec()`. Not used by default — must be returned from the `sassy-engine` filter.

Binary resolution order:
1. Constructor argument
2. `sassy-dart-sass-binary` filter (with `SASSY_DART_SASS_BIN` constant as its default value)
3. If nothing is configured, `get_sass_bin()` returns `null` and compilation fails with an error message.

Variables are injected by **prepending** `$var: value;` declarations to the SCSS source (via `Variable_Resolver::prepend()`), not via the library API. The prelude is emitted as a *single line joined to the source's first line* — the CLI has no equivalent of scssphp's `addVariables()`, and any taller prelude shifts every source map line number by the number of variables injected.

#### Source maps

The CLI can only compile a file, so variable injection means compiling a temp copy. Three things follow, all handled inside the engine:

- The temp input goes in **`{build_dir}/.sassy-tmp/`**, never a source or load-path directory. Dependency tracking watches directory mtimes, so a temp file written into a watched directory invalidates every handle compiled from it — three handles sharing one source directory would recompile each other on every request. Restoring the mtime afterwards is not an option: setting an explicit mtime requires *ownership* of the directory, not merely write access, so it fails silently whenever CLI and web-server users differ.
- Output and map are written **into the build directory**, so the `sources` paths Dart Sass emits — which are relative to the map — are already correct for where the map is served from.
- `sources` entry for the temp copy is rewritten to the real file, and the `sourceMappingURL` comment (which Dart Sass names after the temp output) is replaced with the request's `map_url`.

Because the temp input is not co-located with the real source, explicitly relative imports (`@use "./x"`, `@use "../x"`) resolve against `.sassy-tmp/`. Bare and subdirectory forms are unaffected — `dirname($src_path)` is always a load path.

> Post-compile CSS mutation invalidates the map: both the `url()` rewriting in `Printer::compile()` and any `sassy-css` filter (Lightning CSS included) run *after* the engine has produced it.
>
> With Lightning CSS enabled the map is not merely stale, it is **unreachable** — Lightning
> strips the `sourceMappingURL` comment from the CSS it emits, so nothing links to the `.map`
> file even though it is still written to disk.

---

## Post-Processing: Lightning CSS

`Lightning_CSS_Postprocessor` is a static class that hooks into `sassy-css` (priority 20). It is **disabled by default** — it only activates when a binary is configured.

Binary resolution order:
1. `SASSY_LIGHTNINGCSS_BIN` constant
2. `sassy-lightning-css-binary` filter
3. `SASSY_TOOLS_DIR` constant — looks for `npx` on PATH, then falls back to `node_modules/lightningcss-cli/dist/cli.js` inside that directory

If none of these are configured, `resolve_bin()` returns `null` and the post-processor returns the CSS untouched. There is deliberately no "try `npx` and hope" fallback — that made the post-processor appear enabled on every install.

Process execution uses `proc_open()` with non-blocking I/O and a 60s timeout. Fails gracefully (returns original CSS) if binary is missing or process fails. stderr is discarded silently (logged to PHP error log on error).

Default CLI options: `--minify`. Customizable via `sassy-lightning-css-options` filter (keys: `minify`, `bundle`, `targets`, `error_recovery`).

---

## `wp sassy check`

One call, one exit code, over every discovered style. It is the phase 5 deliverable and the thing the model was built to answer.

`Style_Stack::audit()` decides what is true and returns `Diagnostic[]`; the command decides which severities are failures and renders them. That split is deliberate: what counts as broken is domain, what a flag promotes is surface.

| Condition | Severity | Fails |
|---|---|---|
| Source file missing | `error` | always |
| Never built | `error` | always |
| Stale | `error` | always |
| Import graph truncated | `warning` | **always**, via the diagnostic's `fatal` flag |
| Warnings or deprecations at last compile | as recorded | `--strict` / `--strict=all` |
| Orphaned output | `warning` | `--strict` |

**`check` never compiles.** It does not need to: a failed compile is never recorded as current (phase 2's rule), so an erroring handle arrives as stale. The remedy printed is always `wp sassy compile`.

**Truncation is a warning that fails anyway**, which is what `Diagnostic::$fatal` exists for. A truncated graph makes "everything is current" *unknowable*, so it cannot be demoted to a stylistic preference. An orphan only makes the answer *noisy*.

**Orphan detection is scoped.** Dotfiles and directories are skipped, because the Dart engine's own `.sassy-tmp` lives in the build directory and reporting Sassy's own working directory on a first run is how a tool teaches people to ignore it. It stays a warning however well it is scoped: a handle enqueued only on some template is discovered by no hook set at all, and its output is indistinguishable from an abandoned one.

**`check` defaults to `--hooks=all`**, alone among the commands, for the same reason.

Severity counts from each compile are stored in the existing `sassy-filemtimes-{handle}` record as `__diagnostics__`, so `--strict` has something to gate on without compiling. Counts, not the diagnostics themselves: that record is read on every request and one compile's frames run to kilobytes.

---

## Extension API

Four things are extensible: **load paths**, **variables**, **post-processors** and **engines**. Each keeps its filter, and each gains a registration that carries a name.

```php
add_action('sassy-register', function () {
    Sassy\Extensions::register_variables('my-theme', function (array $variables, Sassy\Asset $asset) { … });
});
```

`register_load_paths()`, `register_post_processor()` and `register_engine()` follow the same shape. Re-registering a slug replaces it. `Extensions::providers()` returns kind => slugs, which is what `wp sassy status` prints.

**Registration timing is per asset, not global.** Each `Printer` resolves its variables lazily, so a provider registered between two compiles applies to the second and not the first. `sassy-register` fires on `after_setup_theme` because that is where integrations used to load, not because later is forbidden.

**The two routes are not equal.** A filter binds and works exactly as before, but a `sassy-css` callback takes a string and returns a string, so it cannot report anything. A registered post-processor is handed a `Post_Process_Context` carrying the `Asset` plus `report()`, `warn()` and `notice()`, and whatever it reports joins the compile's diagnostics. That asymmetry is the reason the registry exists at all: Lightning CSS could fail on every request and look identical to success.

`sassy-compiler` is not one of the four. It hands out scssphp's own `Compiler` object, so it is an engine-specific escape hatch with no equivalent under Dart Sass, and the registry does not describe it.

### Builder integrations are gone

Bricks, Oxygen and Digitalis shipped as built-in integrations through 2.x. Neither builder is installed on the development machine, so the code had no test surface, which is how the `resolve_bin()` and output-style bugs got in. They now live in `tests/fixtures/` as worked examples, rebuilt on the extension API and tested against stubbed builder APIs (`Bricks\Breakpoints`, `Bricks\Theme_Styles`, `oxy_get_global_colors()`, `ct_get_global_settings()` and friends). `tests/test-fixtures.php` asserts each one still produces what it used to, including Oxygen standing down when the Digitalis framework handles SCSS itself.

If the extension API ever cannot express one of them, the API is wrong. That is what those fixtures are for.

---

## Admin UI

`UI` (`include/view/ui.class.php`) adds a **SCSS** item to the WordPress admin bar (visible to users with `edit_theme_options`). Requires at least one active compiler to appear.

Admin bar structure:
- **SCSS** (root) — shows ❌ SCSS on compile errors
  - ⚡ **Live Compile** — triggers AJAX recompile + in-place stylesheet reload (see JS)
  - 🤖 **Force Recompile** — toggles `?sassy-recompile=1` query param
  - 📝 **Log Variables** — toggles `?sassy-vars=1`; logs all SCSS variables to browser console on page load
  - _(separator)_
  - Per-compiler entries (filename, state badge: error/compiled/cache)
    - Source SCSS link
    - Compiled CSS link
    - Source map link
    - Imported partials (from source map sources)

**Force recompile** via `?sassy-recompile=1`: `UI::run_compiler()` hooks `sassy-force-compile` and returns true.

---

## WP-CLI

Registered only when `WP_CLI` is defined. Command group: `sassy`.

| Command | Purpose |
|---|---|
| `wp sassy status` | Engine, binaries and versions, build path, Lightning CSS state, constants |
| `wp sassy list` | Every discovered style; `--compilable` narrows to the ones Sassy builds |
| `wp sassy compile [<handle>...]` | Compile; `--force` ignores the cache |
| `wp sassy vars [<handle>]` | Resolved SCSS variables, after integrations and filters |
| `wp sassy deps <handle>` | The recorded import graph, with each file's state |
| `wp sassy deps --file=<path>` | The reverse: every handle that imports this file |
| `wp sassy check` | Is everything current, unbroken and accounted for? One exit code |
| `wp sassy clear [<handle>...]` | Drop compile caches |
| `wp sassy watch [<handle>...]` | Recompile on change until interrupted; `--interval` sets the poll |

All data commands take `--format=table|csv|json|yaml`; `vars` also accepts `--format=scss`.

### Style discovery and `--hooks`

Styles are found by `Style_Stack::discover()`, which fires enqueue hooks and then reads every
queue. `--hooks` selects which:

| Value | Fires |
|---|---|
| `frontend` (default) | `wp_enqueue_scripts` |
| `admin` | `admin_enqueue_scripts` |
| `editor` | `enqueue_block_editor_assets` |
| `all` | all three |

Comma-separated combinations work. Anything registered on an admin or editor hook is invisible to
the default, which is why deploy-time priming needs `--hooks=all`.

> The flag is `--hooks`, not `--context`: WP-CLI reserves `--context` as a global parameter and
> rejects the value before the command is reached.

`wp sassy list` reports **every** discovered handle, not only the buildable ones — 337 here
under `--hooks=all`, of which 3 compile. Columns: `handle`, `type`, `state`, `deps`, `imports`,
`engine`, `time`, `source`, `built`. `deps` is the WordPress handle dependency list (an array
under `json` and `yaml`, comma-joined for the row formats); `imports` is the file count in the
recorded import graph, which is what `deps` meant before phase 1. Handles Sassy does not build
carry identity and `source` only — no engine is constructed for them. `--compilable` narrows to
the buildable set.

Asking to compile a handle that cannot be says why: unregistered, registers no source of its
own, not a local file, or not compilable with the extension named.

Firing admin and editor hooks outside a real request runs third-party callbacks that assume a
screen exists, so `set_current_screen()` is called first and each hook set is wrapped in a
`Throwable` guard that warns and skips rather than losing the run.

### `wp sassy watch`

A loop around the same cache check `style_loader_src` runs, so it needed no new change detection.
Three things it has to get right:

- **`clearstatcache()` every tick.** PHP caches stat results for the life of the process, so
  without it the watcher reads the mtimes from its first pass forever and never sees anything.
- **It ignores `sassy-check-dependencies`.** Turning the check off is a production decision;
  asking to watch is an explicit request for it.
- **Discovery happens once.** Re-firing enqueue hooks each tick would run third-party callbacks
  in a loop, so a newly registered handle needs a restart.

Not a deploy mechanism: under an external object cache a long-running process holds a runtime
cache and will not see a concurrent web request recompiling.

> `wp sassy clear` with no handles deletes by pattern from the options table. Under an external
> object cache transients are not there, so it falls back to clearing discovered handles and says
> so — widen it with `--hooks=all`.

---

## Tests

```bash
php tests/run.php                    # this checkout
php tests/run.php /path/to/checkout  # another one
```

No PHPUnit and no WordPress: `tests/bootstrap.php` stubs the handful of functions the compile
path touches and builds a fixture docroot under `tests/.tmp/`. Each file runs in its own process
because the fixture root is bound to constants. Tests needing Dart Sass skip themselves when the
binary is absent.

| File | Covers |
|---|---|
| `test-frontend-safety.php` | Nothing in the compile path calls an admin-only function; Lightning CSS stays off unless configured |
| `test-import-graph.php` | Import parsing and resolution; editing a partial invalidates; shadowing; a failed compile is not remembered as current |
| `test-multiple-handles.php` | Handles sharing a source directory do not invalidate each other |
| `test-source-maps.php` | Every map source resolves from where the map is served, and line numbers are unshifted |
| `test-output-style.php` | `sassy-style` accepts the enum and the string, on both engines |
| `test-printer.php` | `Build_Target` path math and its filters; `Variable_Resolver` defaults, Sass maps, scheme normalization and signatures; `Compile_Cache` currency across a partial edit, a variable change, a missing build file and both cache filters; `Printer` agreeing with all three |
| `test-check.php` | `dependents_of()` including non-canonical paths; the audit's three hard failures; orphan scoping against a dotfile, a directory and a real orphan; truncation as a fatal warning; the severity tally |
| `test-extensions.php` | The registry: four kinds, named providers, re-registration replacing by slug, per-asset timing, and a post-processor's report reaching the `Printer` |
| `test-fixtures.php` | The retired Bricks, Oxygen and Digitalis integrations rebuilt on the extension API, against stubbed builder APIs |
| `test-diagnostics.php` | The `Diagnostic` schema and its rendering; Dart stderr parsing for all three shapes plus unrecognised output; the scssphp logger and its structured exceptions; engine capabilities |
| `test-style-stack.php` | `Asset` field resolution (local, root-relative, remote, `src === false`), `sassy-src-path` over an unresolvable URL, discovery per context, a context that raises, the multi-queue filter, and `Printer` delegating rather than duplicating |

The second argument is what makes these worth having: point the runner at a checkout from before
a fix and the relevant tests should fail. A test that passes against both is not testing the fix.

---

## Complete Filter/Action Reference

### Filters consumed by Sassy

| Filter | Default | Description |
|---|---|---|
| `sassy-compile` | `true` | Skip compilation for a handle entirely |
| `sassy-force-compile` | `false` | Force recompile regardless of cache |
| `sassy-check-dependencies` | `true` | Whether to stat the import graph on each request. False trades edit detection for the stat cost |
| `sassy-build-path` | `WP_CONTENT_DIR` | Base filesystem path for compiled output |
| `sassy-build-url` | `WP_CONTENT_URL` | Base URL for compiled output |
| `sassy-build-directory` | `'/scss/'` (or `'/scss/{blog_id}/'` on multisite) | Subdirectory under build path/url |
| `sassy-build-name` | `{source-name}.css` | Compiled CSS filename |
| `sassy-style` | `OutputStyle::EXPANDED` | Output style. May return the enum case or `'expanded'` / `'compressed'`; `get_style()` normalizes to a string before engines see it |
| `sassy-variables` | (see defaults above) | SCSS variables array |
| `sassy-import-paths` | `[dirname($src_path), SASSY_PATH]` (+ `DIGITALIS_FRAMEWORK_PATH` if defined) | Filesystem paths searched by `@import`/`@use` |
| `sassy-src-map` | `true` | Whether to generate source maps |
| `sassy-src-path` | (resolved from URL, or `null`) | Override the source filesystem path. Applies even when resolution returned `null`, which is how a source Sassy cannot resolve gets placed. Receives `($path, $src, $handle, $asset)` |
| `sassy-style-queues` | `[wp_styles()]` | Registries discovery reads. Later queues win on a duplicate handle |
| `sassy-engine` | `null` (→ Scssphp_Engine) | Return a `Compiler_Engine` instance to override |
| `sassy-dart-sass-binary` | `null` (→ `"sass"`) | Dart Sass binary path |
| `sassy-css` | N/A | Post-process compiled CSS string |
| `sassy-lightning-css` | `true` | Enable/disable Lightning CSS post-processing |
| `sassy-lightning-css-binary` | `null` | Lightning CSS binary path |
| `sassy-lightning-css-options` | `['minify'=>true, 'bundle'=>false, ...]` | Lightning CSS CLI flags |
| `sassy-print-errors` | `true` | Whether to render compile errors to the page footer |

All per-compile filters receive `($value, $src, $handle, $asset)`. The fourth argument was the `SCSS_Compiler` before 3.0; it is now the `Asset`, which is available before a compile starts and carries no build state.

### Actions consumed by Sassy

| Action | When |
|---|---|
| `sassy-compiler` | Inside `Scssphp_Engine::compile()` before compilation — receives `(Compiler $compiler, Compile_Request $request)`. An engine-specific escape hatch: it hands out scssphp's own compiler and does nothing under Dart Sass |
| `sassy-admin-bar` | Inside admin bar build — receives `$admin_bar` for extending the SCSS menu |

### Actions registered by Sassy

| Hook | Callback |
|---|---|
| `plugins_loaded` | `Sassy::boot()` |
| `after_setup_theme` | `Sassy::load_integrations()` |
| `wp_ajax_sassy_compile` | `Sassy::compile_all()` (authenticated) |
| `wp_ajax_nopriv_sassy_compile` | `Sassy::compile_all()` (unauthenticated — nonce verified inside) |
| `wp_enqueue_scripts` | `Sassy::enqueue_scripts()` |
| `admin_enqueue_scripts` | `Sassy::enqueue_scripts()` |
| `wp_footer` / `admin_footer` | `Sassy::print_errors()` |
| `admin_bar_menu` (priority 100) | `UI::admin_bar_menu()` |

---

## Constants

| Constant | Set in | Value |
|---|---|---|
| `SASSY_VERSION` | `sassy.php` | `'2.1.0'` — kept identical to the plugin header |
| `SASSY_PATH` | `sassy.php` | Absolute path to plugin directory (trailing slash) |
| `SASSY_URI` | `sassy.php` | URL to plugin directory (trailing slash) |
| `SASSY_ROOT_FILE` | `sassy.php` | `__FILE__` of sassy.php |
| `SASSY_PLUGIN_BASE` | `sassy.php` | `plugin_basename()` e.g. `sassy/sassy.php` |
| `SASSY_PLUGIN_SLUG` | `sassy.php` | `'sassy'` |
| `SASSY_LIGHTNINGCSS_BIN` | User's `wp-config.php` | Path/command to Lightning CSS binary |
| `SASSY_TOOLS_DIR` | User's `wp-config.php` | Directory containing `node_modules/lightningcss-cli` |
| `SASSY_DART_SASS_BIN` | User's `wp-config.php` | Path to Dart Sass binary |

---

## Global Helper

```php
SASSY() // Returns the global Sassy\Sassy instance
```

Used throughout the plugin to access compilers, variables, UI, and error state from any context.

---

## Diagnostics

Every reportable event from a compile is a `Diagnostic`, and every surface renders it through
`Diagnostic::render_all()`. 2.x flattened an error to a string and warnings to an array of stderr
*lines*, which reported 97 warnings for the 10 diagnostics in one real compile.

| Field | Notes |
|---|---|
| `severity` | `error`, `warning`, `deprecation` or `notice` |
| `message` | The engine's own, verbatim. May wrap; the header takes the first line and the rest follows |
| `file`, `line`, `column` | The caret's position, matching the top trace frame |
| `frame`, `trace` | The engine's own drawing, reproduced verbatim, gutters and all |
| `code`, `url` | Engine identifiers, e.g. `global-builtin` and its documentation link |
| `source` | `engine`, or `sassy` for Sassy's own advisories |

The two engines are read differently because they offer different things:

- **scssphp is structured.** `Compiler::setLogger()` takes a `LoggerInterface`, and
  `SassException` exposes `getSpan()`, `getSassTrace()` and `getOriginalMessage()`. Nothing is
  parsed. Note a `@warn` arrives with a trace and no span while a deprecation arrives with a span
  and no trace, so the location comes from whichever is present. The logger runs inside
  compilation, so `Scssphp_Logger` never throws: an exception there would surface as a compile
  failure describing the logger.
- **Dart is text.** `Dart_Sass_Parser` reads stderr. Anything it cannot recognise survives as a
  single `warning` carrying the raw text. The engine passes `--verbose`, because Dart otherwise
  drops repeated deprecations and reports only that it did, which `--strict=all` could not gate
  on.

A `url` is carried exactly as the engine printed it, including when it is wrong: Dart cites
`https://sass-lang.com/d/import` for `global-builtin` deprecations.

Engines declare what they can do. `Scssphp_Engine` reports `source_maps, compressed`;
`Dart_Sass_Engine` adds `modules`. `wp sassy status` lists them, and a `@use` under scssphp is
refused with the file, the line and the remedy.

Because the engines implement different deprecation sets, **`--strict=all` is engine-dependent by
design**: scssphp fires a fraction of what Dart does. See plan §6.

---

## The style stack

Phase 1 of the 3.0 plan. `Style_Stack` is the only thing that reads `wp_styles()->registered`;
everything else asks it.

### `Asset`

A value object over a `_WP_Dependency`. On the reference install 337 of these exist under
`--hooks=all` and Sassy builds 3 of them.

| Member | Notes |
|---|---|
| `handle`, `type`, `src`, `deps` | As registered. `type` is `'style'`; scripts arrive in phase 8. `src` is `false` for dependency-only handles (90 of them here) |
| `extension` | Lowercased, read from the URL path. `null` when there is none |
| `get_source_path()` | Filesystem path, or `null`. Resolved lazily and cached |
| `is_local()` | Whether Sassy has a path for it — i.e. `get_source_path() !== null` |
| `is_compilable()` | `is_local()` and an extension in `Asset::COMPILABLE` (`scss` only; `.sass` is deferred to plan phase 3, where it belongs as an engine capability) |

**`null` means the URL maps nowhere** — a remote host, a hostless src that is not root-relative,
or `src === false`. It does **not** mean the file is missing: a local URL resolves whether or not
anything is there, so callers can name the path they looked at. That is why
`Printer::compile()` can still report `Source file not found: /var/www/…/style.scss`
rather than echoing the URL back.

Two things it does that 2.x's `get_src_path()` did not:

- **Root-relative sources resolve.** 65 of this install's handles register as
  `/wp-admin/css/common.min.css` — every wp-admin stylesheet. Treating a missing host as remote
  put a fifth of the stack out of reach.
- **The `DOCUMENT_ROOT` fallback is taken only when it names a real file.** WP-CLI leaves
  `DOCUMENT_ROOT` unset, so the old unconditional swap built a rootless path and reported it as
  the file it had looked for.

`sassy-src-path` applies **unconditionally, including over a `null`** — it is the only way to
place an asset Sassy cannot resolve itself. It receives `($path, $src, $handle, $asset)`.

`Printer::get_src_path()` delegates here and keeps returning the URL when resolution comes
back `null`, because its callers `file_exists()` that value and print it, so both outcomes are
still plain strings. Phase 3 converts them to `Diagnostic`s, an absent local source naming the
path and one that maps nowhere naming the URL.

### `Style_Stack`

```php
Style_Stack::discover(['frontend', 'admin'])   // fires those enqueue hooks, then reads the queues
    ->all()            // Asset[] keyed by handle
    ->compilable()     // just the ones Sassy builds
    ->handle('x')      // ?Asset
    ->dependents_of($file)   // [] until phase 5 inverts the import graph
    ->context_errors() // context => message, for contexts that raised
```

`discover()` fires each context inside an output buffer and records anything that raises rather
than losing the run — third-party callbacks on the admin and editor hooks assume a request
WP-CLI is not making. Surfaces render `context_errors()`; they do not decide. Passing no contexts reads
the queues as they stand.

Queues come from the **`sassy-style-queues`** filter, defaulting to `[wp_styles()]`; later
queues win on a duplicate handle. This replaces the `$digitalis_styles` global, which
`get_scss_styles()` used to merge by hand and which is no longer special-cased. Anything needing
a second registry adds it through the filter.

Lattice's `Theme::enqueue_style_last()` still populates that global. It has no callers on *this*
install, but Lattice is a shared submodule and other sites call it, so styles registered that way
need the filter or they stop being discovered — see
[docs/upgrading-to-3.0.md](docs/upgrading-to-3.0.md).

---

## Key Architectural Decisions

- **`Style_Stack` owns discovery** — nothing else reads `wp_styles()->registered`, and nothing else resolves a URL to a path. Both were duplicated before phase 1, which is how the scheme bugs and the divergent staleness rules happened.
- **One `Printer` per file**: stateful, tracks compile result, errors, warnings and metadata for that file. It orchestrates; it does not own paths, currency or values.
- **`Compile_Cache` is the sole owner of "is it stale"**, including both transient keys. The CLI and admin bar used to read and delete them directly, which is how `wp sassy list` grew a staleness rule that disagreed with the one the compiler acted on.
- **Transient-based caching** — avoids recompilation on every page load; invalidated by file changes or variable changes.
- **Engine abstraction** — `Compiler_Engine` interface allows swapping scssphp for Dart Sass (or a custom engine) without changing the orchestration layer. Engines never call back into the domain: `Variable_Resolver::prepend()` is theirs to call, and each backend owns its own temp files.
- **Filter-driven extensibility** — nearly every step is filterable; integrations work entirely through `sassy-variables`.
- **Graceful degradation** — Lightning CSS and Dart Sass both fail silently (returning unprocessed CSS), so the site never breaks due to missing binaries.
- **All three integrations are instantiated by Sassy** — Bricks, Oxygen, and Digitalis are all booted in `load_integrations()`; each self-disables via `condition()` if its builder/framework is absent.

---

## Development Notes

- The plugin's own admin CSS is `assets/css/sassy.css`, edited directly — the SCSS source was dropped in `398e1c6`, so the VS Code Sass task in `.vscode/tasks.json` no longer has an input.
- The `Scssphp_Engine` is the only engine that needs no external binaries — safe default for all environments.
- When adding a new per-compile filter, keep the signature consistent: `($value, $src, $handle, $asset)`.
- The `Compile_Result::info` field carries warnings (array of strings) from the engine. `Dart_Sass_Engine` populates it from stderr output; `Scssphp_Engine` currently returns `null` (reserved for future use).
