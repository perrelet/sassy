# SASSY

A rather saucy way of implementing SCSS on your WordPress install. This plugin was written due to the limitations of similar plugins in the wp repository and was inspired by Juan Echeverry's [SCSS-Library](https://wordpress.org/plugins/scss-library/?ref=commonninja) plugin.

## Usage

Enqueue your sass files as if they were css and sassy will take care of the rest.

```php
wp_enqueue_style('your-scss', plugin_dir_url(__FILE__) . 'style.scss');
```

## Storage

By default, compiled css and scss source map files are saved to `wp-content\scss`. See the [hooks](#hooks) section to override this behaviour.

## Compiling

Sassy will compile a source file if any of the following are met:

1. The file — or any partial it pulls in via `@use`, `@forward` or `@import`, at any depth — has changed since it was last checked.
2. Any sass variables have been added, removed or changed value.
3. Compilation is forced via the `sassy-force-compile` filter or `wp sassy compile --force` (See [WP-CLI](#wp-cli)).
4. `CTRL + SPACE` is pressed in a relevant browser window (See [Live Compile](#live-compile)).

## Hooks

| Hook | Default | Details |
| - | - | - |
| `sassy-compile` | `true` | Whether to compile the current asset. |
| `sassy-force-compile` | `false` | Force a recompile (skips file checks). |
| `sassy-check-dependencies` | `true` | Whether to check imported partials for changes on each request. Return `false` on slow/networked filesystems and compile from a deploy hook instead. |
| `sassy-build-path` | `WP_CONTENT_DIR` | The directory to compile to. |
| `sassy-build-url` | `WP_CONTENT_URL` | URL to the compile directory. |
| `sassy-build-directory` | `'/scss/'` or `'/scss/' . get_current_blog_id()` on multi_site. | The subdirectory to compile to.  |
| `sassy-build-name` | Same as source (e.g. `style.css`). | The name of the compiled files. |
| `sassy-style` | `ScssPhp\ScssPhp\OutputStyle::EXPANDED` | Output style. Return the enum case or the string `'expanded'` / `'compressed'`. |
| `sassy-variables` | See [Variables](#variables) | Array of variables to be available. |
| `sassy-import-paths` | `[dirname($src_path), SASSY_PATH]` (plus `DIGITALIS_FRAMEWORK_PATH` if defined) | Filesystem paths searched by `@import`/`@use`. |
| `sassy-src-map` | `true` | Whether to generate the source map. |
| `sassy-src-map-options` | See [Source Maps](#source-maps) | Source map options array. |
| `sassy-css` | N/A | The compiled css (post‑SCSS engine; used by Lightning CSS). |
| `sassy-lightning-css` | `true` | Whether to run the optional Lightning CSS post‑processor. |
| `sassy-lightning-css-binary` | `null` | Returns the Lightning CSS CLI binary/command to use. See [Lightning CSS post-processing](#lightning-css-post-processing). |
| `sassy-engine` | `null` | Return a `Compiler_Engine` instance to override the default scssphp engine (e.g. to use Dart Sass). |
| `sassy-dart-sass-binary` | `SASSY_DART_SASS_BIN` constant (or `null`) | Path to the Dart Sass binary. If unset, compilation fails — there is no implicit fallback. |
| `sassy-src-path` | (resolved from URL) | Override the resolved filesystem path of the source SCSS file. |
| `sassy-print-errors` | `true` | Whether to render compile errors to the page footer. |

## Variables

By default only the following variables are defined, however others may be added via the `sassy-variables` filter or a registered provider. See [Extending Sassy](#extending-sassy) below.

```php
[
    'wp-content-url'           => WP_CONTENT_URL,
    'template-directory-url'   => get_template_directory_uri(),
    'stylesheet-directory-url' => get_stylesheet_directory_uri(),
]
```

### Array variables and Sass maps

The `sassy-variables` filter may also return nested PHP arrays. Sassy will automatically convert any array values into Sass maps before passing them to the compiler. For example:

```php
add_filter('sassy-variables', function ($vars) {
    $vars['breakpoints'] = [
        'page'   => '1200px',
        'tablet' => '768px',
        'phone'  => '480px',
    ];

    return $vars;
});
```

…becomes the following Sass map in your SCSS:

```scss
$breakpoints: ('page': 1200px, 'tablet': 768px, 'phone': 480px);
```

Nested arrays are supported and are converted to nested Sass maps.
## Live Compile

Fed up of refreshing the page to see your changes? Us too. Simply press `CTRL + SPACE` to recompile and reload your stylesheets at any time. 🚀

The binding is configurable, and `false` disables it while leaving the admin bar button:

```php
add_filter('sassy-keybinding', fn () => ['ctrl+shift+k']);
```

Who sees the dev surface at all is one filter. It defaults to `edit_theme_options`, and it is about *who* rather than *where*, so it works the same in production:

```php
add_filter('sassy-dev', fn () => current_user_can('dev'));
```

Sassy exposes `window.sassy.compile()` and fires `sassy:before-compile`, `sassy:compiled` and `sassy:reload` on `document`, so driving it from a builder iframe is a listener rather than a reach-in.

When live compile runs, Sassy also:

- Reloads any compiled stylesheets in-place (by adding a cache-busting `sassy` query parameter).
- Logs compile metadata for each stylesheet to the browser console (engine, compiled file, source, handle, variables, source-map status, compile time, etc.).
- Logs any SCSS compiler warnings for each stylesheet as a single, readable block in the console.

## WP-CLI

When [WP-CLI](https://wp-cli.org/) is available, Sassy registers a `sassy` command so you can compile all registered SCSS styles from the command line.

### Commands

```bash
wp sassy status                  # engine, binaries, build path, Lightning CSS state
wp sassy list                    # discovered styles, cache state, dependency counts
wp sassy compile                 # compile everything that is stale
wp sassy compile --force         # ignore the cache
wp sassy compile my-theme        # just one handle
wp sassy vars                    # resolved SCSS variables
wp sassy vars --format=scss      # ...as $name: value; declarations
wp sassy deps my-theme           # the recorded import graph
wp sassy deps --file=_mixins.scss # which handles import this file
wp sassy check                   # is everything current? one exit code
wp sassy clear                   # drop compile caches
wp sassy watch                   # recompile as you edit, until Ctrl-C
```

Data commands accept `--format=table|csv|json|yaml`, so they can be read by tooling as well as people.

### Finding admin and editor styles

Sassy discovers styles by firing enqueue hooks. By default only `wp_enqueue_scripts` runs, so
stylesheets registered on `admin_enqueue_scripts` or `enqueue_block_editor_assets` are not found —
they would stay stale until someone first loaded the admin or the editor.

Use `--hooks` to widen the search:

```bash
wp sassy compile --hooks=all             # frontend, admin and editor
wp sassy compile --hooks=admin,editor    # or pick them
```

`--hooks=all` is what you want in a deploy hook, so no visitor pays for a cold compile.

### Watching

`wp sassy watch` recompiles the moment a file changes, so the CSS is built before you switch to
the browser and Sass errors appear in the terminal you are editing in.

```bash
wp sassy watch                        # frontend styles, checked every second
wp sassy watch --hooks=all            # admin and editor styles too
wp sassy watch my-theme --interval=2  # one handle, less often
```

A compile error prints and the loop keeps going, so you fix and save rather than restarting.
Handles are discovered once at startup — registering a new one needs a restart.

## Lightning CSS post-processing

Sassy can optionally run your compiled CSS through [Lightning CSS](https://lightningcss.dev/) for minification and modern CSS transforms. This happens **after** SCSS compilation, via the `sassy-css` filter.

Lightning CSS is **disabled by default** until you point Sassy at a binary.

### Configuration

You can configure the Lightning CSS binary in one of three ways (checked in this order):

- **Constant in `wp-config.php`:**

```php
define('SASSY_LIGHTNINGCSS_BIN', 'npx'); // or an absolute path to lightningcss / cli.js
```

- **Tools directory (for Node-based installs):**

```php
define('SASSY_TOOLS_DIR', WP_CONTENT_DIR . '/tools'); // e.g. contains node_modules/lightningcss-cli
```

- **Filter override:**

```php
add_filter('sassy-lightning-css-binary', function ($bin) {
    return '/usr/local/bin/lightningcss'; // or 'npx', or 'node /path/to/cli.js'
});
```

To toggle Lightning CSS on/off without changing code, use:

```php
add_filter('sassy-lightning-css', function ($enabled, $src, $handle, $compiler) {
    // Example: only run in production, or skip for certain handles.
    if (defined('WP_DEBUG') && WP_DEBUG) {
        return false; // disable in debug/dev
    }
    return $enabled;
}, 10, 4);
```

By default, Sassy runs Lightning CSS with `--minify`. If the binary cannot be found, or Lightning CSS fails, the original compiled CSS is returned unchanged and the Lightning CSS error is logged to PHP’s error log.

To customize Lightning CSS options such as `--minify`, `--bundle`, `--targets`, or `--error-recovery`, you can use:

```php
add_filter('sassy-lightning-css-options', function ($options, $src, $handle, $compiler) {
    // Disable minification for certain handles:
    if (in_array($handle, ['editor-style', 'admin-style'], true)) {
        $options['minify'] = false;
    }

    // Example: set custom browser targets
    $options['targets'] = '= 0.25%';

    return $options;
}, 10, 4);
```

## Source Maps

Source maps can be selectively generated via the `sassy-src-map` filter. Where the map is written follows the build target, so `sassy-build-path` and `sassy-build-directory` relocate it.

## Checking

`wp sassy check` answers one question about the whole install and exits accordingly: is every style current, unbroken and accounted for?

```bash
wp sassy check              # fails on a missing source, an unbuilt or stale handle, a truncated graph
wp sassy check --strict     # ...and on warnings, which includes orphaned build files
wp sassy check --strict=all # ...and on deprecations from the last compile
```

It never compiles anything. A handle that fails to compile is never recorded as current, so it turns up as stale and the remedy is always `wp sassy compile`.

Two deliberate asymmetries. A **truncated import graph** is reported as a warning but fails anyway, because it makes the answer unknowable rather than untidy. An **orphaned output**, a built file no registered handle claims, is a warning that fails only under `--strict`, because a style enqueued on one template is indistinguishable from one that was deleted.

`wp sassy deps --file=<path>` answers the reverse of `deps <handle>`: given a partial, which handles recompile when it changes. Useful before editing something shared, and the reason the import graph is recorded at all.

## Extending Sassy

There are four things you can extend: **load paths**, **variables**, **post-processors** and **engines**. Each has a filter, and each has a registration that gives your provider a name, a typed signature and a line in `wp sassy status`.

```php
add_action('sassy-register', function () {

    Sassy\Extensions::register_variables('my-theme', function (array $variables, Sassy\Asset $asset) {
        $variables['brand'] = '#ff0000';
        return $variables;
    });

});
```

The same shape applies to `register_load_paths()`, `register_engine()` and `register_post_processor()`. Registering a slug that already exists replaces it, so a provider can be overridden by name. Registering later than `sassy-register` still works, as long as it happens before the asset in question compiles.

Filters keep working exactly as before and need no changes. What they cannot do is report: a `sassy-css` callback returns a string and has no way to tell you it did not run. A registered post-processor is handed a context and can say so:

```php
Sassy\Extensions::register_post_processor('my-minifier', function ($css, $context) {

    if (!$binary_exists) {
        $context->warn('my-minifier did not run; the CSS is unprocessed.');
        return $css;
    }

    return minify($css);

});
```

### Builder integrations

Sassy 2.x shipped built-in integrations for Bricks Builder, Oxygen Builder and the Digitalis Framework, which turned their settings into SCSS variables. **These are not part of 3.0.** Neither builder is installed on the machine Sassy is developed on, so the code had no test surface, and shipping it that way is how two real bugs got in.

They live on as worked examples: `tests/fixtures/` contains each of them rebuilt on the extension API and tested against stubbed builder APIs. If you need one, copy the fixture into your theme or plugin and register it. It is roughly forty lines and it is yours to change, which is better than a version of it you cannot see.

## Credits

Inspired by Juan Echeverry's [SCSS-Library](https://wordpress.org/plugins/scss-library/?ref=commonninja).

© Jamie Perrelet 2021 - 2026
<br><br>
![Digitalis](https://digitalisweb.ca/wp-content/plugins/digitalisweb/assets/png/logo/digitalis.222.250.png)