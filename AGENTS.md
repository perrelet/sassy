# AGENTS.md — Sassy WordPress Plugin

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
│   │   ├── scss-compiler.class.php    # Per-file compilation orchestrator
│   │   ├── compile-result.class.php   # DTO returned by compiler engines
│   │   ├── scss-map.class.php         # PHP array → SCSS map syntax converter
│   │   ├── import-graph.class.php     # Recorded dependency set; answers "has anything changed?"
│   │   ├── import-resolver.class.php  # Sass file-resolution rules (partials, _index, load paths)
│   │   ├── import-scanner.class.php   # Walks the @use/@forward/@import graph into an Import_Graph
│   │   └── lightning-css-postprocessor.class.php  # Optional Lightning CSS post-processing
│   ├── engines/
│   │   ├── compiler-engine.interface.php            # Contract for engine implementations
│   │   ├── scssphp-engine.compiler-engine.php       # Default: PHP-native scssphp v2
│   │   └── dart-sass-engine.compiler-engine.php     # Alternative: shells out to Dart Sass CLI
│   ├── integrations/
│   │   ├── integration.abstract.php   # Base class: condition check, variable injection via filter
│   │   ├── bricks.integration.php     # Bricks Builder — breakpoints, spacing vars
│   │   ├── oxygen.integration.php     # Oxygen Builder — colors, fonts, breakpoints, spacing
│   │   └── digitalis.integration.php  # Digitalis Framework — path/URI vars
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
   - Loads model classes (require_once in order: `Compile_Result`, `Lightning_CSS_Postprocessor`, `Scss_Map`, `Import_Graph`, `Import_Resolver`, `Import_Scanner`, `Compiler_Engine` interface, engine implementations, `SCSS_Compiler`)
   - Loads view (`UI` class, instantiated immediately)
   - Registers `Lightning_CSS_Postprocessor::filter` on `sassy-css` at priority 20
   - If `is_admin()`: loads and boots `Admin` → `Updater`
   - Registers `style_loader_src`, `wp_enqueue_scripts`, `wp_footer`, `admin_enqueue_scripts`, `admin_footer` hooks
3. **`after_setup_theme`** → `Sassy::load_integrations()`: requires integration files, instantiates `Bricks`, `Oxygen`, and `Digitalis` (their constructors check `condition()` before activating).

---

## Compilation Pipeline

### Trigger

`style_loader_src` filter intercepts any enqueued style whose URL ends in `.scss`. A fresh `SCSS_Compiler` is created per file and tracked in `Sassy::$compilers`.

### `SCSS_Compiler::compile($src, $handle)`

1. **Resolve source path** — converts the `.scss` URL to an absolute filesystem path. Handles multisite by normalizing the blog path. Filterable via `sassy-src-path`.
2. **Cache check** (`should_compile`) — skips actual compilation if:
   - `sassy-force-compile` filter returns false, AND
   - No file in the recorded import graph has changed (see [Dependency tracking](#dependency-tracking)), AND
   - `sassy-vars-sig-{handle}` transient matches sha1 of current serialized variables, AND
   - The compiled build file already exists on disk.
3. **Build compile args** — assembles `$args`: scss content, src_path, import_paths (source dir + SASSY_PATH + DIGITALIS_FRAMEWORK_PATH if defined), variables (resolved via `get_variables()`), output style, source map settings.
4. **Delegate to engine** — calls `Compiler_Engine::compile($args)`, returns `Compile_Result`.
5. **URL rewriting** — rewrites relative `url()` references in the compiled CSS to absolute paths based on the source SCSS location.
6. **`sassy-css` filter** — passes CSS through registered post-processors (Lightning CSS hooks here at priority 20).
7. **Write to disk** — CSS to `wp-content/scss/{name}.css`; source map alongside if enabled.
8. **Update transients** — refreshes filemtime cache.
9. Returns the compiled CSS URL (passing through any query string from the original `.scss` URL).

### Engine Selection

The engine is resolved lazily in `SCSS_Compiler::get_engine()`:
- Applies `sassy-engine` filter — return a `Compiler_Engine` instance to override.
- Default: `Scssphp_Engine` (no external binaries required).

### Variables

`SCSS_Compiler::get_variables()` seeds three defaults, then applies `sassy-variables` filter:

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
- Exposes `sassy-compiler` WordPress action, passing the raw `ScssPhp\ScssPhp\Compiler` object — allows direct manipulation before compile.
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

Variables are injected by **prepending** `$var: value;` declarations to the SCSS source (via `SCSS_Compiler::prepend_variables()`), not via the library API. The prelude is emitted as a *single line joined to the source's first line* — the CLI has no equivalent of scssphp's `addVariables()`, and any taller prelude shifts every source map line number by the number of variables injected.

#### Source maps

The CLI can only compile a file, so variable injection means compiling a temp copy. Three things follow, all handled inside the engine:

- The temp input goes in **`{build_dir}/.sassy-tmp/`**, never a source or load-path directory. Dependency tracking watches directory mtimes, so a temp file written into a watched directory invalidates every handle compiled from it — three handles sharing one source directory would recompile each other on every request. Restoring the mtime afterwards is not an option: setting an explicit mtime requires *ownership* of the directory, not merely write access, so it fails silently whenever CLI and web-server users differ.
- Output and map are written **into the build directory**, so the `sources` paths Dart Sass emits — which are relative to the map — are already correct for where the map is served from.
- `sources` entry for the temp copy is rewritten to the real file, and the `sourceMappingURL` comment (which Dart Sass names after the temp output) is replaced with `sourceMapURL` from `sassy-src-map-options`.

Because the temp input is not co-located with the real source, explicitly relative imports (`@use "./x"`, `@use "../x"`) resolve against `.sassy-tmp/`. Bare and subdirectory forms are unaffected — `dirname($src_path)` is always a load path.

> Post-compile CSS mutation invalidates the map: both the `url()` rewriting in `SCSS_Compiler::compile()` and any `sassy-css` filter (Lightning CSS included) run *after* the engine has produced it.
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

## Integrations

All integrations extend `Sassy\Integration`. The base constructor:
1. Calls `condition()` — returns false to abort.
2. Adds `compiler_variables()` to the `sassy-variables` filter.
3. Calls `run()` (hook for additional setup).

Integrations declare `get_variables()` returning `['scss-var-name' => 'value']` pairs.

### Bricks Builder (`BRICKS_VERSION` defined)

| SCSS variable | Source |
|---|---|
| `$b-{key}` | Each Bricks breakpoint (numeric px value) |
| `$b-page`, `$b-tablet`, `$b-phone-landscape`, `$b-phone-portrait` | Mapped aliases for standard breakpoint keys |
| `$breakpoints` | Sass map of all breakpoints with `px` suffix |
| `$col-px` | Column gap from active theme style |
| `$sec-px` | Section horizontal padding |
| `$sec-py` | Section vertical padding |

### Oxygen Builder (`CT_VERSION` defined, Digitalis OXY_SCSS module absent)

| SCSS variable | Source |
|---|---|
| `$c-{color-name}` | Global colors (`oxy_get_global_colors()`) |
| `$b-{breakpoint-name}` | Each breakpoint (`oxygen_vsb_get_breakpoint_width()`) |
| `$b-page` | Page width (`oxygen_vsb_get_page_width()`) |
| `$breakpoints` | Sass map of all breakpoints |
| `$f-{font-name}` | Global fonts (`ct_get_global_settings()`) |
| `$sec-px`, `$sec-py` | Section padding |
| `$col-px`, `$col-py` | Column padding |

Note: The Oxygen integration is suppressed when `Digitalis\Module\OXY_SCSS\OXY_SCSS` exists (Digitalis framework handles it).

### Digitalis Framework (`DIGITALIS_FRAMEWORK_VERSION` defined)

Instantiated automatically by `load_integrations()` alongside Bricks and Oxygen.

| SCSS variable | Value |
|---|---|
| `$digitalis_path` | `DIGITALIS_FRAMEWORK_PATH` (filesystem) |
| `$digitalis_uri` | `DIGITALIS_FRAMEWORK_URI` (web URL) |

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
| `wp sassy list` | Discovered SCSS styles with cache state, dependency count and last compile time |
| `wp sassy compile [<handle>...]` | Compile; `--force` ignores the cache |
| `wp sassy vars [<handle>]` | Resolved SCSS variables, after integrations and filters |
| `wp sassy deps <handle>` | The recorded import graph, with each file's state |
| `wp sassy clear [<handle>...]` | Drop compile caches |
| `wp sassy watch [<handle>...]` | Recompile on change until interrupted; `--interval` sets the poll |

All data commands take `--format=table|csv|json|yaml`; `vars` also accepts `--format=scss`.

### Style discovery and `--hooks`

Styles are found by firing enqueue hooks and then reading both queues via
`Sassy::get_scss_styles()`. `--hooks` selects which:

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
| `sassy-src-map-options` | (sourceMapWriteTo, sourceMapURL, etc.) | Source map config array |
| `sassy-src-path` | (resolved from URL) | Override source SCSS filesystem path |
| `sassy-engine` | `null` (→ Scssphp_Engine) | Return a `Compiler_Engine` instance to override |
| `sassy-dart-sass-binary` | `null` (→ `"sass"`) | Dart Sass binary path |
| `sassy-css` | N/A | Post-process compiled CSS string |
| `sassy-lightning-css` | `true` | Enable/disable Lightning CSS post-processing |
| `sassy-lightning-css-binary` | `null` | Lightning CSS binary path |
| `sassy-lightning-css-options` | `['minify'=>true, 'bundle'=>false, ...]` | Lightning CSS CLI flags |
| `sassy-print-errors` | `true` | Whether to render compile errors to the page footer |

All per-compile filters receive `($value, $src, $handle, $compiler)` as arguments (where applicable).

### Actions consumed by Sassy

| Action | When |
|---|---|
| `sassy-compiler` | Inside `Scssphp_Engine::compile()` before compilation — receives `(Compiler $compiler, array $args)` |
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

## `$digitalis_styles` Global

An alternative `WP_Styles` registry used by the Digitalis framework to register SCSS styles outside the standard WordPress queue. Both `Sassy::compile_all()` (AJAX) and the WP-CLI command merge this registry with `wp_styles()->registered` before iterating.

---

## Key Architectural Decisions

- **One `SCSS_Compiler` per file** — stateful, tracks compile result, errors, warnings, and metadata for that file.
- **Transient-based caching** — avoids recompilation on every page load; invalidated by file changes or variable changes.
- **Engine abstraction** — `Compiler_Engine` interface allows swapping scssphp for Dart Sass (or a custom engine) without changing the orchestration layer.
- **Filter-driven extensibility** — nearly every step is filterable; integrations work entirely through `sassy-variables`.
- **Graceful degradation** — Lightning CSS and Dart Sass both fail silently (returning unprocessed CSS), so the site never breaks due to missing binaries.
- **All three integrations are instantiated by Sassy** — Bricks, Oxygen, and Digitalis are all booted in `load_integrations()`; each self-disables via `condition()` if its builder/framework is absent.

---

## Development Notes

- The plugin's own admin CSS is `assets/css/sassy.css`, edited directly — the SCSS source was dropped in `398e1c6`, so the VS Code Sass task in `.vscode/tasks.json` no longer has an input.
- The `Scssphp_Engine` is the only engine that needs no external binaries — safe default for all environments.
- When adding a new integration: extend `Integration`, implement `condition()` and `get_variables()`, then add `new YourIntegration()` in `Sassy::load_integrations()`.
- When adding a new filter to `SCSS_Compiler`, keep the signature consistent: `($value, $src, $handle, $compiler)`.
- The `Compile_Result::info` field carries warnings (array of strings) from the engine. `Dart_Sass_Engine` populates it from stderr output; `Scssphp_Engine` currently returns `null` (reserved for future use).
