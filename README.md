# SASSY

A rather saucy way of implementing SCSS on your WordPress install. This plugin was written due to the limitations of similar plugins in the wp repository and was heavbily inspired by Juan Echeverry's [SCSS-Library](https://wordpress.org/plugins/scss-library/?ref=commonninja) plugin.

## Usage

Enqueue your sass files as if they were css and sassy will handle the rest. Example:

```php
wp_enqueue_style('your-scss', plugin_dir_url(__FILE__) . 'style.scss');
```

## Storage

By default, compiled css and scss source map files are saved to `wp-content\scss`. See the [hooks](#hooks) section to override this behaviour.

## Compiling

Sassy will compile a source file if any of the following are met:

1. The file has changed since it was last checked.
2. Any sass varaibles have been added, removed or changed value.
3. Compilation is forced via the `sassy-run-compiler` filter.

## Hooks

| Hook | Default | Details |
| - | - | - |
| `sassy-compile` | `true` | Whether to compile the current asset. |
| `sassy-force-compile` | `false` | Force a recompile (skips file checks). |
| `sassy-content-dir` | `WP_CONTENT_DIR` | The directory to compile to. |
| `sassy-content-url` | `WP_CONTENT_URL` | URL to the compile directory. |
| `sassy-build-directory` | `'/scss/'` or `'/scss/' . get_current_blog_id()` on multi_site. | The subdirectory to compile to.  |
| `sassy-build-name` | Same as source, appended with compiler index. | The name of the compiled files. |
| `sassy-formatter` | `'ScssPhp\ScssPhp\Formatter\Expanded'` | Class of the scss formatter. |
| `sassy-variables` | See [Variables](#variables) | Array of variables to be available. |

## Variables

By default only the following variables are defined, however others may be added via the `sassy-variables` filter.

```php
[
    'template_directory_uri'   => get_template_directory_uri(),
    'stylesheet_directory_uri' => get_stylesheet_directory_uri(),
]
```

>**Tip:** The 'Oxygen global variables in SCSS files' module in our [digitalis plugin](https://github.com/perrelet/digitalis) uses the `sassy-variables` filter to inject Oxygen Builder's global colors, fonts and breakpoints in this way.

## Credits

Inspired by Juan Echeverry's [SCSS-Library](https://wordpress.org/plugins/scss-library/?ref=commonninja).

© Jamie Perrelet 2021 - 2022
<br><br>
![Digitalis](https://digitalisweb.ca/wp-content/plugins/digitalisweb/assets/png/logo/digitalis.222.250.png)