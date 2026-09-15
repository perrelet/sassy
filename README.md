<p align="center">
  <img src="https://digitalis.ca/wp-content/plugins/digitalis-co/assets/svg/logo/digitalis.current.svg" width="140" alt="Digitalis">
</p>

# Sassy

**Sassy compiles your SCSS on the server, knows every stylesheet WordPress enqueued and pushes a DevTools edit back into the partial it came from.**

Enqueue a `.scss` file the way you would a `.css` file. Sassy intercepts the URL, compiles it, watches every partial it pulls in and serves the CSS. For a long time that was the whole plugin. It is now the smallest part of it.

- **Every stylesheet on the site is modelled**, not only the ones Sassy compiles: which hook enqueued it, where it lives on disk, what it depends on and whether it is current. Nothing else can build that model. A Node build does not know WordPress's enqueue graph and WordPress does not know a Sass import tree.
- **The browser is an editor.** Paint in the styles pane, press 🖌️ Capture and Sassy maps each change through the source map to a file and a line. Press Push and it writes the change into that partial, exactly or not at all.
- **Machines are first-class readers.** Every CLI command speaks JSON, every diagnostic names a file an agent can open and `wp sassy check` answers one question with one exit code.

## Who it is for

Teams building custom WordPress themes in SCSS, on servers they control, who would rather not run a Node build to see a change land. Sassy is comfortable in production. The dev surface is gated by *who* you are, never by *where* you are, so the same site serves visitors and developers at once.

It is not for shared hosting. The engine you want is Dart Sass, and Dart Sass is a binary on the box.

## Requirements

| | |
|---|---|
| PHP | 8.1 or later |
| WordPress | 6.0 or later |
| [Dart Sass](https://sass-lang.com/dart-sass/) | Any recent release. Needed for modern Sass: `@use`, `@forward`, the module system |
| [Lightning CSS](https://lightningcss.dev/) | Optional, for minification and transforms after Sass |

## Quick start

Sassy is not in the WordPress directory. Drop the folder into `wp-content/plugins` and activate it. It updates itself from digitalis.ca.

Enqueue Sass as if it were CSS:

```php
wp_enqueue_style('my-theme', get_template_directory_uri() . '/style.scss');
```

Point it at Dart Sass, in `wp-config.php` or a plugin:

```php
define('SASSY_DART_SASS_BIN', '/usr/local/bin/sass');
add_filter('sassy-engine', fn () => new Sassy\Dart_Sass_Engine());
```

Load a page. The compiled CSS is served from `wp-content/scss/`, the admin bar has an **SCSS** menu, and `wp sassy status` tells you what it found.

## Engines

Two, and the choice matters.

| | Dart Sass | scssphp |
|---|---|---|
| Needs | a binary and `exec()` | nothing |
| Sass modules (`@use`, `@forward`) | yes | **no**, `@import` only |
| Source maps | yes | yes |
| Warnings and deprecations | yes, as diagnostics | yes, as diagnostics |

**Dart Sass is the engine.** It is the reference implementation, it compiles the Sass you write today, and Sassy runs it with variable injection, exact source maps and its own temp files, so nothing lands in your source tree.

**scssphp is what boots when nothing is installed.** It is pure PHP and needs no binary, which makes it the default. It cannot compile `@use` or `@forward`. If your Sass is modern, or you plan for it to be, configure Dart Sass before you write a line.

Either way the contract is the same: an engine takes a request and returns CSS with diagnostics. Your own engine is a class and a filter. See [Extending Sassy](#extending-sassy).

## When Sassy compiles

A source is compiled on the request that needs it, when any of these is true:

1. The entry file, or any partial it pulls in through `@use`, `@forward` or `@import` at any depth, has changed since the last build.
2. A directory on the import path gained or lost a Sass file, which is exactly when a new file could shadow the one in use.
3. A variable was added, removed or changed value.
4. Compilation was forced: the `sassy-force-compile` filter, `wp sassy compile --force` or a keypress in the browser.
5. The build file is missing.

Between compiles the check is one `stat` per dependency, under a millisecond on a real project. On a networked filesystem that cost is real, so return `false` from `sassy-check-dependencies` there and compile from a deploy hook instead. A handle that has never been built still compiles.

Compiled CSS and source maps land in `wp-content/scss/`, or `wp-content/scss/{blog_id}/` on multisite. The [hooks](#hooks) move it.

## Live Compile

Fed up of refreshing the page to see your changes? Us too. Press `ctrl+space` and Sassy recompiles what is stale and swaps the stylesheets in place.

Three chords, one hand each. Space looks, X commits:

| Action | Default | |
|---|---|---|
| Live Compile | `ctrl+space`, `meta+space` | check the cache and reload |
| 🖌️ Capture | `ctrl+shift+space`, `meta+shift+space` | diff the paint, show the patch |
| 🖌️ Push to source | `ctrl+shift+x`, `meta+shift+x` | capture and write, only with the write gate open |

All three are one filter over a map of action to `modifier+key` strings. `false` unbinds an action and leaves its button. `sassy-keybinding` (singular) still sets the compile entry alone:

```php
add_filter('sassy-keybindings', fn ($bindings) => ['push' => false] + $bindings);
```

Chords are ignored while focus is in a field, ignored on key repeat and matched exactly, so a chord with an extra modifier does nothing. `meta+space` is Spotlight on macOS and never reaches the browser, so Mac users are on `ctrl+space`.

Who sees the dev surface at all is one filter. It defaults to `edit_theme_options`, and it is about *who* rather than *where*, so it works the same in production:

```php
add_filter('sassy-dev', fn () => current_user_can('dev'));
```

The **SCSS** admin bar menu carries a 📜 **Logging** submenu of console toggles, persisted per browser:

- **Compile meta** logs each stylesheet's engine, files, handle, content hash, source-map status and compile time.
- **Diagnostics** logs warnings and deprecations, rendered exactly as the CLI renders them.
- **Auto-reload** polls every two seconds and swaps only the stylesheets whose content changed, so a save in your editor or a `wp sassy watch` compile appears without a keypress. Off by default, paused while the tab is hidden.
- **Capture diffs** sends a capture's patch to the console too.

Sassy exposes `window.sassy.compile(force, hooks)`, `.reload()`, `.poll()`, `.capture()` and `.push()`, and fires `sassy:before-compile`, `sassy:compiled`, `sassy:reload`, `sassy:captured` and `sassy:pushed` on `document`. Driving it from a builder iframe is a listener rather than a reach-in. Live Compile sends the context it was served in, so on a wp-admin screen it rebuilds the sheet on that screen.

## Painting in the inspector

Two ways to work from DevTools, both on the dev surface.

**Edit the source in the browser.** The compiled CSS ships a source map that is exact, `url()` rewriting included, so the styles pane links straight into the `.scss` that produced each rule. Add the project folder as a DevTools Workspace and edits saved from the Sources panel land on disk. With `wp sassy watch` running and Auto-reload on, the page repaints without a keypress.

**Paint, then capture.** Edit declarations in the styles pane as usual, then press 🖌️ **Capture** in the admin bar, or call `window.sassy.capture()`. Sassy diffs the page's live CSSOM against the snapshot it took when the sheets loaded, maps each change through the source map and shows the patch in the panel:

```
plugins/d-pace/scss/components/_site-header.scss:33  .site-header
  background: var(--material-bg, var(--surface-dark)) → red
```

**Copy** yields exactly that text, which is the handoff: paste it to an agent or a colleague with "integrate this properly".

**Push to source** appears beside it when the site has opted in, and writes each change into the `.scss` it maps to, then recompiles:

```php
add_filter('sassy-write-source', fn () => current_user_can('dev'));
```

It writes only where it can do so exactly. The file must be a recorded dependency of that handle, unchanged since the last compile, and the mapped line must carry `prop: <the served value>;` literally. `gap: $gap` when `4px` was served is refused and comes back for the copy path with the line quoted. So does anything the CSSOM serialises differently from the source, and a removal from a line holding more than that declaration. A declaration you added goes in beside the rule's existing declarations. A rule you created with the styles pane's per-rule **+** goes in after its neighbour's block as an `@at-root` block, which compiles at the root wherever the neighbour is nested. A rule created in the inspector stylesheet and an `element.style` edit have no source location and are listed as copy-only, the first with a suggested file.

The bar's 🖌️ **Push to source** does capture and push in one click. The safety is the server's refusals, so nothing is lost, and Capture stays for looking without writing. `sassy-write-source` defaults to `false` and is never implied by `sassy-dev`.

## The dashboard

Tools → Sassy, for anyone the dev surface is active for. It shows what `wp sassy check`, `status`, `list` and `deps` print, for a person who is not at a terminal:

- **Check**: is everything current, unbroken and accounted for.
- **Status**: engine, binaries, providers, build path and whether the dev surface is active for you.
- **Stack**: every registered style and script under every hook set, filterable by whether Sassy builds it.
- **Per handle**: source, build and map links, the recorded import graph with each file's state, and the last compile's diagnostics, grouped and folded, each with the engine's own frame and trace.
- **Actions**: Compile all and Clear cache.

Every `file:line:column` is click-to-copy, and **Copy** on a diagnostic yields exactly what the CLI prints, so it pastes into an agent or a ticket unchanged. Where Dart Sass cites a bare `_partial.scss`, the page resolves it to a path through the recorded import graph.

The dashboard is a dashboard. Configuration is code: constants and filters, never a settings form.

## WP-CLI

With [WP-CLI](https://wp-cli.org/) available, Sassy registers a `sassy` command.

```bash
wp sassy status                     # engine, binaries, build path, Lightning CSS state
wp sassy list                       # discovered styles, cache state, dependency counts
wp sassy list --type=script         # discovered scripts, with what each does to styles
wp sassy list --touches=data-theme  # the scripts that set or read that attribute
wp sassy compile                    # compile everything that is stale
wp sassy compile --force            # ignore the cache
wp sassy compile my-theme           # one handle
wp sassy vars                       # resolved SCSS variables
wp sassy vars --format=scss         # as $name: value; declarations
wp sassy deps my-theme              # the recorded import graph
wp sassy deps --file=_mixins.scss   # which handles import this file
wp sassy check                      # is everything current? one exit code
wp sassy clear                      # drop compile caches
wp sassy watch                      # recompile as you edit, until Ctrl-C
```

Data commands accept `--format=table|csv|json|yaml`, so tooling reads them as easily as people do.

### Finding admin and editor styles

Sassy discovers styles by firing enqueue hooks. By default only `wp_enqueue_scripts` runs, so a stylesheet registered on `admin_enqueue_scripts` or `enqueue_block_editor_assets` is not found and would stay stale until someone first loaded the admin or the editor. `--hooks` widens the search:

```bash
wp sassy compile --hooks=all             # frontend, admin and editor
wp sassy compile --hooks=admin,editor    # or pick them
```

`--hooks=all` is what you want in a deploy hook, so no visitor pays for a cold compile.

### Watching

`wp sassy watch` recompiles the moment a file changes, so the CSS is built before you switch to the browser and Sass errors appear in the terminal you are editing in.

```bash
wp sassy watch                        # frontend styles, checked every second
wp sassy watch --hooks=all            # admin and editor styles too
wp sassy watch my-theme --interval=2  # one handle, less often
```

A compile error prints and the loop keeps going, so you fix and save rather than restart. Handles are discovered once at startup, so registering a new one needs a restart.

### Checking

`wp sassy check` answers one question about the whole install and exits accordingly: is every style current, unbroken and accounted for?

```bash
wp sassy check              # fails on a missing source, an unbuilt or stale handle, a truncated graph
wp sassy check --strict     # and on warnings, which includes orphaned build files
wp sassy check --strict=all # and on deprecations from the last compile
```

It never compiles anything. A handle that fails to compile is never recorded as current, so it turns up as stale and the remedy is always `wp sassy compile`.

Two deliberate asymmetries. A **truncated import graph** is reported as a warning but fails anyway, because it makes the answer unknowable rather than untidy. An **orphaned output**, a built file no registered handle claims, is a warning that fails only under `--strict`, because a style enqueued on one template is indistinguishable from one that was deleted.

`wp sassy deps --file=<path>` answers the reverse of `deps <handle>`: given a partial, which handles recompile when it changes. Ask it before editing something shared. It is the reason the import graph is recorded at all.

## What scripts do to your styles

Sassy compiles CSS and observes JS. `wp sassy list --type=script` lists every registered script with its **surface**: which kinds of style mutation its text contains, by marker, counted. Five categories:

- **Scope control**: `classList`, `dataset`, `data-*` attribute writes. How theming is driven.
- **Custom property writes**: `setProperty('--…')`. The intended mechanism, not pollution.
- **Inline layout writes**: `el.style.width = …`. A cascade override worth knowing about.
- **Layout reads**: `getBoundingClientRect`, `ResizeObserver`, `matchMedia`. Thrash and layout-shift surface.
- **CSSOM injection**: `insertRule`, `adoptedStyleSheets`, `new CSSStyleSheet`. What third parties do to you.

Detection only, no semantics. Reads do not count as writes, and a marker in a comment counts, because a false positive costs a glance and a miss costs the signal. `--touches=data-theme` narrows to the scripts that touch an attribute, which is the question to ask before renaming one. Profiles are cached by file stamp, so only a changed script is read again.

## Variables

Three variables are always defined, and the `sassy-variables` filter or a registered provider adds more:

```php
[
    'wp-content-url'           => WP_CONTENT_URL,
    'template-directory-url'   => get_template_directory_uri(),
    'stylesheet-directory-url' => get_stylesheet_directory_uri(),
]
```

Nested PHP arrays become Sass maps:

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

```scss
$breakpoints: ('page': 1200px, 'tablet': 768px, 'phone': 480px);
```

A changed variable recompiles the handles that see it. `wp sassy vars` shows the resolved set.

## Lightning CSS

Compiled CSS can run through [Lightning CSS](https://lightningcss.dev/) for minification and modern CSS transforms. It is a registered post-processor, so it runs after the engine and after the `sassy-css` filter, and it is **off until you point Sassy at a binary**. Three ways, checked in this order:

```php
define('SASSY_LIGHTNINGCSS_BIN', 'npx');                 // or an absolute path to lightningcss / cli.js
define('SASSY_TOOLS_DIR', WP_CONTENT_DIR . '/tools');    // a directory holding node_modules/lightningcss-cli
add_filter('sassy-lightning-css-binary', fn () => '/usr/local/bin/lightningcss');
```

Toggle it per handle or per environment with `sassy-lightning-css`, and shape the flags with `sassy-lightning-css-options`:

```php
add_filter('sassy-lightning-css-options', function ($options, $src, $handle, $asset) {
    if (in_array($handle, ['editor-style', 'admin-style'], true)) $options['minify'] = false;
    $options['targets'] = '>= 0.25%';
    return $options;
}, 10, 4);
```

The default is `--minify`. If the binary cannot be found or the run fails, the CSS is served unprocessed and a warning carrying Lightning's stderr joins the compile's diagnostics, so `wp sassy compile` prints it and `wp sassy check --strict` fails on it. Lightning strips the source-map link, and Sassy says so.

## Hooks

Per-compile filters receive `($value, $src, $handle, $asset)`, where `$asset` is the `Sassy\Asset` being built.

| Hook | Default | Details |
|---|---|---|
| `sassy-compile` | `true` | Whether to compile this handle at all. Receives `($value, $src, $handle)` |
| `sassy-force-compile` | `false` | Force a recompile, skipping the cache |
| `sassy-check-dependencies` | `true` | Whether to stat the import graph on each request. Return `false` on networked filesystems and compile from a deploy hook |
| `sassy-build-path` | `WP_CONTENT_DIR` | The directory to compile to |
| `sassy-build-url` | `WP_CONTENT_URL` | URL to the compile directory |
| `sassy-build-directory` | `'/scss/'`, or `'/scss/{blog_id}/'` on multisite | The subdirectory to compile to |
| `sassy-build-name` | Same as the source, `style.css` | The compiled filename |
| `sassy-style` | `'expanded'` | Output style: `'expanded'` or `'compressed'`, or scssphp's `OutputStyle` case |
| `sassy-variables` | See [Variables](#variables) | The variables every compile sees |
| `sassy-import-paths` | `[dirname($src_path), SASSY_PATH]`, plus `DIGITALIS_FRAMEWORK_PATH` if defined | Filesystem paths searched by `@use` and `@import` |
| `sassy-src-map` | `true` | Whether to generate the source map |
| `sassy-src-path` | resolved from the URL, or `null` | Override the source's filesystem path. Runs even when resolution failed, which is how a source Sassy cannot place gets placed |
| `sassy-css` | | The compiled CSS, before post-processors |
| `sassy-engine` | `null`, meaning scssphp | Return a `Compiler_Engine` instance |
| `sassy-dart-sass-binary` | `SASSY_DART_SASS_BIN`, else `null` | The Dart Sass binary. Unset, the compile fails and the error names the constant and the filter |
| `sassy-lightning-css` | `true` | Whether to run Lightning CSS, once a binary is configured |
| `sassy-lightning-css-binary` | `null` | The Lightning CSS binary or command |
| `sassy-lightning-css-options` | `['minify' => true, ...]` | Lightning CSS CLI flags |
| `sassy-print-errors` | `true` | Whether to render compile errors in the page footer |
| `sassy-style-queues` | `[wp_styles()]` | Style registries discovery reads. Later queues win on a duplicate handle |
| `sassy-script-queues` | `[wp_scripts()]` | Script registries discovery reads |
| `sassy-dev` | `current_user_can('edit_theme_options')` | Whether the dev surface is active for this request |
| `sassy-write-source` | `false` | Whether the paintbrush may write to source. Never implied by `sassy-dev` |
| `sassy-keybinding` | `['ctrl+space', 'meta+space']` | The Live Compile chords alone |
| `sassy-keybindings` | `compile`, `capture`, `push`, as above | Every action's chords. `false` unbinds one and leaves its button |

Two actions: `sassy-compiler` hands out scssphp's own `Compiler` before a compile, and does nothing under Dart Sass. `sassy-admin-bar` receives the admin bar for extending the **SCSS** menu.

## Extending Sassy

Four extension points: **load paths**, **variables**, **post-processors** and **engines**. Each has a filter, and each has a registration that gives your provider a name, a typed signature and a line in `wp sassy status`.

```php
add_action('sassy-register', function () {

    Sassy\Extensions::register_variables('my-theme', function (array $variables, Sassy\Asset $asset) {
        $variables['brand'] = '#ff0000';
        return $variables;
    });

});
```

The same shape applies to `register_load_paths()`, `register_engine()` and `register_post_processor()`. Registering a slug that already exists replaces it, so a provider can be overridden by name. Registering later than `sassy-register` still works, as long as it happens before the asset in question compiles.

Filters keep working exactly as before. What they cannot do is report: a `sassy-css` callback returns a string and has no way to tell you it did not run. A registered post-processor is handed a context and can say so:

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

Sassy 2.x shipped built-in integrations for Bricks Builder, Oxygen Builder and the Digitalis Framework, which turned their settings into SCSS variables. **They are not part of 3.0.** Neither builder is installed where Sassy is developed, so the code had no test surface, and shipping it that way is how two real bugs got in.

They live on as worked examples: `tests/fixtures/` holds each of them rebuilt on the extension API and tested against stubbed builder APIs. If you need one, copy the fixture into your theme or plugin and register it. It is roughly forty lines and it is yours to change, which beats a version you cannot see.

## Upgrading from 2.x

3.0 renames classes, changes the engine contract and removes the builder integrations. [docs/upgrading-to-3.0.md](docs/upgrading-to-3.0.md) lists every break with what to do about it. Most sites that only use the filters need no changes.

## Development

`php tests/run.php` runs the suite. It stubs WordPress, opens no browser and needs no PHPUnit. Browser behaviour is walked by hand against `tests/manual.md` before a release. [AGENTS.md](AGENTS.md) describes the codebase as it stands and is kept accurate on every landing. It is written for people and for agents alike, and so is the plugin.

## History

Sassy started in 2021 because the SCSS plugins in the WordPress directory could not do what one project needed. Juan Echeverry's [SCSS-Library](https://wordpress.org/plugins/scss-library/) showed the shape, enqueue `.scss` and get `.css`, and none of that code remains but the shape does. Everything since has been a question of how much of the page's styling one plugin can be made to understand.

© Jamie Perrelet 2021 to 2026, [Digitalis Web Build Co.](https://digitalis.ca/)
