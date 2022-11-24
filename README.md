# SASSY

A rather saucy way of implementing SCSS on your WordPress install. This plugin was written due to the limitations of similar plugins in the wp repository and was heavily inspired by Juan Echeverry's [SCSS-Library](https://wordpress.org/plugins/scss-library/?ref=commonninja) plugin.

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

## Hooks

| Hook | Default | Details |
| - | - | - |
| `sassy-compile` | `true` | Whether to compile the current asset. |
| `sassy-force-compile` | `false` | Force a recompile (skips file checks). |
| `sassy-build-path` | `WP_CONTENT_DIR` | The directory to compile to. |
| `sassy-build-url` | `WP_CONTENT_URL` | URL to the compile directory. |
| `sassy-build-directory` | `'/scss/'` or `'/scss/' . get_current_blog_id()` on multi_site. | The subdirectory to compile to.  |
| `sassy-build-name` | Same as source, appended with compiler index. | The name of the compiled files. |
| `sassy-formatter` | `'ScssPhp\ScssPhp\Formatter\Expanded'` | Class of the scss formatter. |
| `sassy-variables` | See [Variables](#variables) | Array of variables to be available. |
| `sassy-src-map` | `true` | Whether to generate the source map. |
| `sassy-src-map-data` | See [Source Maps](#source-maps) | Array of variables to be available. |

## Variables

By default only the following variables are defined, however others may be added via the `sassy-variables` filter. See [Integrations](#integrations) below.

```php
[
    'template_directory_uri'   => get_template_directory_uri(),
    'stylesheet_directory_uri' => get_stylesheet_directory_uri(),
]
```

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

© Jamie Perrelet 2021 - 2022
<br><br>
![Digitalis](https://digitalisweb.ca/wp-content/plugins/digitalisweb/assets/png/logo/digitalis.222.250.png)