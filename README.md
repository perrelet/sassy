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

1. The file has changed since it was last checked.
2. Any sass variables have been added, removed or changed value.
3. Compilation is forced via the `sassy-run-compiler` filter.
4. `CTRL + SPACE` is pressed in a relevant browser window (See [Live Compile](#live-compile))

## Hooks

| Hook | Default | Details |
| - | - | - |
| `sassy-compile` | `true` | Whether to compile the current asset. |
| `sassy-force-compile` | `false` | Force a recompile (skips file checks). |
| `sassy-build-path` | `WP_CONTENT_DIR` | The directory to compile to. |
| `sassy-build-url` | `WP_CONTENT_URL` | URL to the compile directory. |
| `sassy-build-directory` | `'/scss/'` or `'/scss/' . get_current_blog_id()` on multi_site. | The subdirectory to compile to.  |
| `sassy-build-name` | Same as source, appended with compiler index. | The name of the compiled files. |
| `sassy-style` | `'ScssPhp\ScssPhp\OutputStyle::EXPANDED'` | Class of the scss formatter. |
| `sassy-variables` | See [Variables](#variables) | Array of variables to be available. |
| `sassy-src-map` | `true` | Whether to generate the source map. |
| `sassy-src-map-options` | See [Source Maps](#source-maps) | Source map options array. |
| `sassy-css` | N/A | The compiled css (post‑SCSS engine; used by Lightning CSS). |
| `sassy-lightning-css` | `true` | Whether to run the optional Lightning CSS post‑processor. |
| `sassy-lightning-css-binary` | `null` | Returns the Lightning CSS CLI binary/command to use. See [Lightning CSS post-processing](#lightning-css-post-processing). |

## Variables

By default only the following variables are defined, however others may be added via the `sassy-variables` filter. See [Integrations](#integrations) below.

```php
[
    'wp-content-url' => WP_CONTENT_URL,
    'template_directory_url'   => get_template_directory_uri(),
    'stylesheet_directory_url' => get_stylesheet_directory_uri(),
]
```

Variable keys and values can be accessed via the SCSS admin bar menu:

![screenshot](https://digitalis.ca/static/screenshots/sassy-menu.jpg)

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

Fed up of refreshing the page to see your changes? Us too. Simply press `CTRL + SPACE` to recompile & reload your stylesheets at any time. This also works inside the Oxygen Builder. 🚀

When live compile runs, Sassy also:

- Reloads any compiled stylesheets in-place (by adding a cache-busting `sassy` query parameter).
- Logs compile metadata for each stylesheet to the browser console (engine, compiled file, source, handle, variables, source-map status, compile time, etc.).
- Logs any SCSS compiler warnings for each stylesheet as a single, readable block in the console.

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

## Source Maps

Source maps can be selectively generated via the `sassy-src-map` filter. The source map configuration can be hooked via the `sassy-src-map-data` filter. Refer to (https://scssphp.github.io/scssphp/docs/) for further information on these parameters. 

## Integrations

### Oxygen Builder

Sassy automatically converts the following global styles from Oxygen Builder's configuration into scss variables:

 - 🎨 Global Colors (`$c-color-name`, `$c-another-color`, etc)
 - 🔠 Global Fonts (`$f-text`, `$f-display`, etc)
 - 📱 Break Points (`$b-page`, `$b-tablet`, `$b-phone-landscape`, `$b-phone-portrait`)
 - 📐 Section & Column Spacing (`$sec-px`, `$sec-py`, `$col-px`)

Sassy also generates a list of breakpoints as a sass map called `$breakpoints`.

## Credits

Inspired by Juan Echeverry's [SCSS-Library](https://wordpress.org/plugins/scss-library/?ref=commonninja).

© Jamie Perrelet 2021 - 2026
<br><br>
![Digitalis](https://digitalisweb.ca/wp-content/plugins/digitalisweb/assets/png/logo/digitalis.222.250.png)