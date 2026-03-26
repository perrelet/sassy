# AGENTS.md — Sassy WordPress Plugin

## Overview

**Sassy** is a WordPress plugin (v2.0.0, by Digitalis Web Build Co.) that compiles SCSS files on-demand. The core premise: enqueue `.scss` files exactly as you would `.css` files via `wp_enqueue_style`, and Sassy intercepts the URL, compiles the SCSS to CSS, writes the result to disk, and returns the compiled CSS URL to WordPress instead.

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
│       └── sassy-cli-command.class.php  # WP-CLI: `wp sassy compile [--force]`
├── assets/
│   ├── scss/sassy.scss               # Plugin's own admin styles (source)
│   └── css/sassy.css                 # Compiled output of the above (committed)
├── vendor/                           # Composer dependencies (scssphp/scssphp v2.x)
└── composer.json                     # Requires scssphp/scssphp ^2.1.0
```

---

## Boot Sequence

1. **`sassy.php`** — Defines constants, creates `new Sassy\Sassy()` stored in `$Sassy` global, registers `SASSY()` helper. Registers WP-CLI command if `WP_CLI` is defined.
2. **`plugins_loaded`** → `Sassy::boot()`:
   - Loads vendors (Composer autoload)
   - Loads model classes (require_once in order: `Compile_Result`, `Lightning_CSS_Postprocessor`, `Scss_Map`, `Compiler_Engine` interface, engine implementations, `SCSS_Compiler`)
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
   - `sassy-filemtimes-{handle}` transient shows the file hasn't changed, AND
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

### Caching Transients

| Transient key | Content | Invalidated when |
|---|---|---|
| `sassy-filemtimes-{handle}` | `build_file => filemtime` | Directory creation error; refreshed on each compile |
| `sassy-vars-sig-{handle}` | sha1 of serialized variables | Set on signature mismatch (triggers recompile) |

---

## Compiler Engines

### `Scssphp_Engine` (default)

Uses `scssphp/scssphp` v2.x — pure PHP, no external processes.

- Variables passed as `ScssPhp\ScssPhp\Value\Value` instances (parsed via `ValueConverter::parseValue()`).
- Exposes `sassy-compiler` WordPress action, passing the raw `ScssPhp\ScssPhp\Compiler` object — allows direct manipulation before compile.
- Source maps generated via `Compiler::SOURCE_MAP_FILE`.
- Warnings returned in `Compile_Result::info`.

### `Dart_Sass_Engine` (optional)

Shells out to Dart Sass CLI via `exec()`. Not used by default — must be returned from the `sassy-engine` filter.

Binary resolution order:
1. Constructor argument
2. `sassy-dart-sass-binary` filter
3. `SASSY_DART_SASS_BIN` constant (set in `wp-config.php`)
4. `"sass"` on PATH

Variables are injected by **prepending** `$var: value;` declarations to the SCSS source (via `SCSS_Compiler::prepend_variables()`), not via the library API.

---

## Post-Processing: Lightning CSS

`Lightning_CSS_Postprocessor` is a static class that hooks into `sassy-css` (priority 20). It is **disabled by default** — it only activates when a binary is configured.

Binary resolution order:
1. `SASSY_LIGHTNINGCSS_BIN` constant
2. `sassy-lightning-css-binary` filter
3. `SASSY_TOOLS_DIR` constant — looks for `npx` on PATH, then falls back to `node_modules/lightningcss-cli/dist/cli.js` inside that directory
4. Last resort: `npx` on PATH

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
  - _(separator)_
  - ⭐ **Variables** — lists all SCSS variables with clipboard copy (ClipboardJS)

**Force recompile** via `?sassy-recompile=1`: `UI::run_compiler()` hooks `sassy-force-compile` and returns true.

---

## WP-CLI

Registered only when `WP_CLI` is defined. Command group: `sassy`.

```bash
wp sassy compile           # Compile all registered .scss styles
wp sassy compile --force   # Force full recompile (ignores cache)
```

The CLI mirrors the AJAX compile endpoint: fires `wp_enqueue_scripts`, iterates all registered styles (including `$digitalis_styles` global), filters for `.scss` extensions, and compiles each. Outputs handle → URL → compile time per file.

---

## Complete Filter/Action Reference

### Filters consumed by Sassy

| Filter | Default | Description |
|---|---|---|
| `sassy-compile` | `true` | Skip compilation for a handle entirely |
| `sassy-force-compile` | `false` | Force recompile regardless of cache |
| `sassy-build-path` | `WP_CONTENT_DIR` | Base filesystem path for compiled output |
| `sassy-build-url` | `WP_CONTENT_URL` | Base URL for compiled output |
| `sassy-build-directory` | `'/scss/'` (or `'/scss/{blog_id}/'` on multisite) | Subdirectory under build path/url |
| `sassy-build-name` | `{source-name}.css` | Compiled CSS filename |
| `sassy-style` | `OutputStyle::EXPANDED` | ScssPhp output style |
| `sassy-variables` | (see defaults above) | SCSS variables array |
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
| `wp_enqueue_scripts` | `Sassy::enqueue_scripts()` + `UI::scripts()` |
| `admin_enqueue_scripts` | `Sassy::enqueue_scripts()` + `UI::scripts()` |
| `wp_footer` / `admin_footer` | `Sassy::print_errors()` |
| `admin_bar_menu` (priority 100) | `UI::admin_bar_menu()` |

---

## Constants

| Constant | Set in | Value |
|---|---|---|
| `SASSY_VERSION` | `sassy.php` | `'2.0.0'` |
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

- The plugin's own admin CSS (`assets/scss/sassy.scss`) is compiled via a VS Code task (`sass assets/scss/sassy.scss assets/css/sassy.css`), not by the plugin itself.
- The `Scssphp_Engine` is the only engine that needs no external binaries — safe default for all environments.
- When adding a new integration: extend `Integration`, implement `condition()` and `get_variables()`, then add `new YourIntegration()` in `Sassy::load_integrations()`.
- When adding a new filter to `SCSS_Compiler`, keep the signature consistent: `($value, $src, $handle, $compiler)`.
- The `Compile_Result::info` field carries warnings (array of strings) from the engine. `Dart_Sass_Engine` populates it from stderr output; `Scssphp_Engine` currently returns `null` (reserved for future use).
