<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="assets/img/hero.dark.webp">
    <img src="assets/img/hero.light.webp" alt="Sassy. A rather saucy way of doing SCSS on WordPress. Cascade, a fox in a foxglove hat, holds a paintbrush.">
  </picture>
</p>

Enqueue an `.scss` file as if it were CSS:

```php
wp_enqueue_style('my-theme', get_template_directory_uri() . '/style.scss');
```

Sassy compiles it on the server, keeps track of the partials it imports and rebuilds it when one of them changes. No Node process and no build step to remember before you refresh.

And then we got a bit carried away.

Sassy keeps a record of every stylesheet WordPress enqueued, whether or not Sassy built it: what registered it, where it is on disk, what it depends on and whether what you are looking at is current. And the DevTools styles pane (which for a lot of us is where design actually happens) gets a paint brush. Define a rule in the inspector and Sassy will trace it to the correct SCSS partial and line and write it to disk for you. If there's a conflict, Sassy refuses to write with an explanation.

`wp sassy check` reports whether every stylesheet on the site is current and exits accordingly. Every command has a JSON format and each error names the correct file and line, so a deploy script or a coding agent can use Sassy the same way you do.

We wrote it for our own sites, where it has run in production since 2021. It is for you if you write custom themes in SCSS and want changes on screen without a build step. The developer suite is exposed by capability rather than environment, so the same site serves your visitors and you at once.

## ✌ The awesome bit

Open DevTools on a page you are working on and change something in the styles pane. Press `ctrl+shift+space` (capture) and you'll see your changes:

```
plugins/d-pace/scss/components/_site-header.scss:33  .site-header
  background: var(--material-bg, var(--surface-dark)) → red
```

That is the actual partial and line, with the value on disk and the value you painted. **Copy** puts the patch on your clipboard for a ticket, a message or a coding agent. **Push to source** writes the new value directly into the partial at the correct location, then recompiles and reloads the stylesheet. No hunting through partials for where the rule came from. Live styling is one of the few advantages that page builders have over traditional theming, Sassy provides it natively in the browsers DevTools.

## 🚀 Quick start

Sassy is not in the official WordPress plugin directory. Put the folder in `wp-content/plugins` and activate it, or let Composer put it there for you:

```bash
composer require digitalisweb/sassy
```

The package is a `wordpress-plugin`, so with `composer/installers` in your project it lands in `wp-content/plugins/sassy`. Updates come from digitalis.ca.

Tell Sassy where Dart Sass is, in `wp-config.php` or via a filter:

```php
define('SASSY_DART_SASS_BIN', '/usr/local/bin/sass');
add_filter('sassy-engine', fn () => new Sassy\Dart_Sass_Engine());
```

Enqueue your Sass like a normal stylesheet. The compiled CSS lands in `wp-content/scss/`, the admin bar has an **SCSS** menu and `wp sassy status` shows what Sassy found.

| Requirement | |
|---|---|
| PHP | 8.1 or later |
| WordPress | 6.0 or later |
| [Dart Sass](https://sass-lang.com/dart-sass/) | Recommended. Any recent release. Needed for `@use` and `@forward` |
| [Lightning CSS](https://lightningcss.dev/) | Optional, for minification and transforms after Sass |

Without Dart Sass, Sassy compiles with scssphp, the default, which is pure PHP and needs nothing installed. It understands `@import` but not the module system, so it suits shared hosting and older codebases. Dart Sass is where you want to be. See [Engines](#engines).

## 👾 If you are an agent

Hello, robot. If a site you are working on runs Sassy, these four commands will get you surprisingly far:

```bash
wp sassy status --format=json    # engine, binaries, build path, whether the gates are open
wp sassy list --format=json      # every registered style, whether Sassy builds it, its state
wp sassy check                   # exit 0: every style is current, unbroken and accounted for
wp sassy compile --hooks=all     # the remedy for anything check names
```

You are probably good at the structure and the plumbing, and patchy at the last ten percent of styling, the spacing and rhythm that make a page feel like someone's. That part gets done by a person, in the browser, with a paintbrush. What comes back to you is either a patch from 🖌️ Capture or a change already written into a partial by Push. Either way, a change to a partial that you did not make is a paint: keep it, integrate it properly, and do not rewrite or revert a file you did not change. `sassy-wrote-source` fires once per file Push writes, so a site can commit paints as they land or tell you about them.

A few promises:

- diagnostics point at a `file:line:column` that exists on disk, and a path you cannot open is a bug
- `check` does not compile anything; when it fails, `compile` is the fix
- a failed compile is never recorded as current, so a broken handle shows as stale rather than disappearing
- `wp sassy deps --file=_mixins.scss` tells you which handles you are about to upset
- a patch from 🖌️ Capture has two lines per change, `path:line  selector` then `property: old → new`, and each path is a file in the project

[AGENTS.md](AGENTS.md) describes the code as it stands. If it and the code disagree, the code is right. Please fix the document while you are there.

## 🏗️ When Sassy compiles

Sassy is lazy in the useful sense. Nothing compiles until a page asks for it, and then only if something changed. A stylesheet is compiled on the request that needs it, when any of these is true:

1. The entry file, or any partial it pulls in through `@use`, `@forward` or `@import` at any depth, changed since the last build.
2. A directory on the import path gained or lost a Sass file, which is exactly when a new file could shadow the one in use.
3. A variable was added, removed or changed value.
4. Compilation was forced: the `sassy-force-compile` filter, `wp sassy compile --force` or a keypress in the browser.
5. The build file is missing.

Between compiles the check is one `stat` per dependency, under a millisecond on a real project. On a networked filesystem that cost is real, so return `false` from `sassy-check-dependencies` there and compile from a deploy hook instead. A handle that has never been built still compiles.

Compiled CSS and source maps land in `wp-content/scss/`, or `wp-content/scss/{blog_id}/` on multisite. The [hooks](#hooks) move it.

Where the web server owns that directory, compiling from your own account fails and `wp sassy status` says `build path writable NO`. Group permissions do not hold, since php-fpm's umask makes each new sheet 644 and Sassy rewrites in place. A default ACL does:

```bash
sudo setfacl -R -m u:<you>:rwX -d -m u:<you>:rwX wp-content/scss
```

## ⚡ Live Compile

Fed up of refreshing the page to see your changes? Us too. Press `ctrl+space` and Sassy recompiles what is stale and swaps the stylesheets in place without a reload. 🚀

Three chords:

| Action | Default | |
|---|---|---|
| Live Compile | `ctrl+space`, `meta+space` | check the cache and reload |
| 🖌️ Capture | `ctrl+shift+space`, `meta+shift+space` | diff the paint, show the patch |
| 🖌️ Push to source | `ctrl+shift+x`, `meta+shift+x` | capture and write, only with the write gate open |

The chords are one filter over a map of action to `modifier+key` strings. `false` unbinds an action and leaves its button in the menu. `sassy-keybinding` (singular) still sets the compile entry on its own:

```php
add_filter('sassy-keybindings', fn ($bindings) => ['push' => false] + $bindings);
```

Chords are ignored while focus is in a field, ignored on key repeat and matched exactly, so a chord with an extra modifier does nothing. `meta+space` is Spotlight on macOS and never reaches the browser, so on a Mac use `ctrl+space`.

Who sees any of this is one filter. It defaults to the `edit_theme_options` capability:

```php
add_filter('sassy-dev', fn () => current_user_can('dev'));
```

The **SCSS** admin bar menu carries a 📜 **Logging** submenu of console toggles, persisted per browser:

- **Compile meta** logs each stylesheet's engine, files, handle, content hash, source-map status and compile time.
- **Diagnostics** logs warnings and deprecations, rendered exactly as the CLI renders them.
- **Auto-reload** polls every two seconds and swaps only the stylesheets whose content changed, so a save in your editor or a `wp sassy watch` compile appears without a keypress. Off by default, paused while the tab is hidden.
- **Capture diffs** sends a capture's patch to the console too.

For scripting, `window.sassy` has `compile(force, hooks)`, `reload()`, `poll()`, `capture()` and `push()`, and Sassy fires `sassy:before-compile`, `sassy:compiled`, `sassy:reload`, `sassy:captured` and `sassy:pushed` on `document`. A page builder that wants to react to a compile listens for the event. Live Compile sends the context it was served in, so on a wp-admin screen it rebuilds the stylesheets of that screen.

## 🎨 Painting in the inspector

The styles pane is the best CSS editor there is. It is live, it knows the real cascade, and for a lot of us it is where design actually happens, a paintbrush more than an inspector. What it never had was a way back: an edit lasted until you reloaded, and nothing knew which partial it belonged to. Sassy gives it the way back. There are two ways to work from DevTools, both behind the dev gate.

**Edit the source in the browser.** The compiled CSS ships a source map that is exact, `url()` rewriting included, so the styles pane links straight into the `.scss` that produced each rule. Add the project folder as a DevTools Workspace and edits saved from the Sources panel land on disk. With `wp sassy watch` running and Auto-reload on, the page repaints without a keypress.

**Paint, then capture.** Edit declarations in the styles pane as usual, then press 🖌️ **Capture** in the admin bar, or call `window.sassy.capture()`. Sassy diffs the page's live CSSOM against the snapshot it took when the stylesheets loaded, maps each change through the source map and shows the patch in the panel. Capture does not write anything.

**Push to source** appears beside it when the site has opted in, and writes each change into the `.scss` it maps to, then recompiles. Opting in is a line of code naming who may write, a constant in `wp-config.php` or a filter:

```php
define('SASSY_WRITE_SOURCE', 'edit_theme_options');
add_filter('sassy-write-source', fn () => current_user_can('dev'));   // or, for anything a constant cannot say
```

The rules for a write are strict. The file must be a recorded dependency of that handle and unchanged since the last compile, and the mapped line must contain `property: <the served value>;` exactly as served. Anything else is refused and comes back with the line quoted, for you to make by hand: a value the source expresses differently from what was served (see [Cascade's one rule](#cascades-one-rule)), anything the browser serialises differently from the source, and removing a declaration from a line that holds more than one. A declaration you added is written beside the rule's existing declarations. A rule you created with the styles pane's per-rule **+** is written after its neighbour's block inside `@at-root`, so it compiles at the root however deeply the neighbour is nested. A rule in the inspector stylesheet and an `element.style` edit have no source location and are listed as copy-only, the first with a suggested file. A rule you delete in the inspector is pushed as the deletion of its block, when the block's opening line is exactly that selector and holds no nested blocks; anything else is reported with what it had, for the copy path.

The bar's 🖌️ **Push to source** captures and pushes in one click. Anything refused is still in the panel to copy, so nothing is lost. `sassy-write-source` defaults to `false` and is separate from `sassy-dev`.

## 🦊 Cascade's one rule

Sassy writes source only when it can prove it is changing the thing you changed. If the source says

```scss
gap: 4px;
```

paint it to `8px`, push, done. But if the source says

```scss
gap: $gap;
```

and the browser served `4px`, Sassy stops. Changing that to `8px` might mean changing `$gap`, overriding a token or something else entirely, and that is a decision for you, not the fox. You still get the patch, with the file, the line, the selector and both values, so you can make the change properly. The browser is for how it looks and the file is for what it means, and this rule is where the two meet.

## ⚙️ The dashboard

For the days you would rather not open a terminal. Tools → Sassy shows what `wp sassy check`, `status`, `list` and `deps` print, to anyone the dev gate lets in:

- **Check**: is everything current, unbroken and accounted for.
- **Status**: engine, binaries, providers, build path and whether the dev surface is active for you.
- **Stack**: every registered style and script under every hook set, filterable by whether Sassy builds it.
- **Per handle**: source, build and map links, the recorded import graph with each file's state, and the last compile's diagnostics, grouped and folded, each with the engine's own frame and trace.
- **Actions**: Compile all and Clear cache.

Every `file:line:column` is click-to-copy, and **Copy** on a diagnostic gives the same text the CLI prints, so it pastes into a ticket or an agent unchanged. Where Dart Sass names a bare `_partial.scss`, the page resolves it to a path through the recorded import graph.

There are no settings on the page. Configuration is constants and filters.

## 💻 WP-CLI

Everything the admin bar does and a fair bit more, from the shell. With [WP-CLI](https://wp-cli.org/) available, Sassy registers a `sassy` command.

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

Commands that print data accept `--format=table|csv|json|yaml`.

### 🔎 Finding admin and editor styles

Sassy discovers styles by firing enqueue hooks. By default only `wp_enqueue_scripts` runs, so a stylesheet registered on `admin_enqueue_scripts` or `enqueue_block_editor_assets` is not found and would stay stale until someone first loaded the admin or the editor. `--hooks` widens the search:

```bash
wp sassy compile --hooks=all             # frontend, admin and editor
wp sassy compile --hooks=admin,editor    # or pick them
```

`--hooks=all` is what you want in a deploy hook, so no visitor pays for a cold compile.

Two things about the CLI that look like Sassy and are not. WP-CLI includes `wp-settings.php` from inside a method, so a plugin that does `$Thing = new Thing()` at file scope and reads `global $Thing` later has null under `wp` and nothing else, which breaks discovery with an error naming that file. And a plugin whose bootstrap skips the CLI registers nothing there, so its outputs look orphaned to `check` however wide `--hooks` is.

### 👀 Watching

If you would rather see Sass errors in your terminal than in the page footer, watch instead. `wp sassy watch` recompiles the moment a file changes, so the CSS is built before you switch to the browser and Sass errors appear in the terminal you are editing in.

```bash
wp sassy watch                        # frontend styles, checked every second
wp sassy watch --hooks=all            # admin and editor styles too
wp sassy watch my-theme --interval=2  # one handle, less often
```

A compile error is printed and the loop keeps going. Handles are discovered once at startup, so registering a new one needs a restart.

### ✅ Checking

This is the one for your deploy script. `wp sassy check` asks one question of the whole install and exits accordingly: is every style current, unbroken and accounted for?

```bash
wp sassy check              # fails on a missing source, an unbuilt or stale handle, a truncated graph
wp sassy check --strict     # and on warnings, which includes orphaned build files
wp sassy check --strict=all # and on deprecations from the last compile
```

It does not compile anything. A handle that failed to compile is never recorded as current, so it shows up as stale, and the fix is `wp sassy compile`.

Two cases are treated differently on purpose. A truncated import graph is reported as a warning but fails the check anyway, because without the full graph the question cannot be answered. An orphaned output, a built file that no registered handle claims, is a warning that fails only under `--strict`, because a stylesheet enqueued on one template looks the same as one that was deleted. Beside any orphan, `check` also notes a context that registered no styles of its own, since a plugin whose bootstrap skips the CLI looks exactly like that and its outputs look orphaned.

## 📜 What scripts do to your styles

Stylesheets are only half of what styles a page. Scripts do the rest, and Sassy at least knows which ones. `wp sassy list --type=script` lists every registered script with the kinds of style mutation its text contains, counted by marker. Five categories:

- scope control: `classList`, `dataset` and `data-*` attribute writes, which is how theming is usually driven
- custom property writes: `setProperty('--…')`
- inline layout writes: `el.style.width = …`, a cascade override worth knowing about
- layout reads: `getBoundingClientRect`, `ResizeObserver`, `matchMedia`
- CSSOM injection: `insertRule`, `adoptedStyleSheets`, `new CSSStyleSheet`, which is mostly what third-party scripts do

This is pattern matching over the file text, with no parsing. Reads do not count as writes. A marker inside a comment does count, since a false positive costs a glance and a miss costs the signal. `--touches=data-theme` narrows the list to scripts that touch that attribute, which is worth knowing before you rename one. Profiles are cached by file stamp, so only a changed script is read again.

## 🚂 Engines

There are two, and you want the first one.

| | Dart Sass | scssphp |
|---|---|---|
| Needs | a binary and `exec()` | nothing |
| Sass modules (`@use`, `@forward`) | yes | **no**, `@import` only |
| Source maps | yes | yes |
| Warnings and deprecations | yes, as diagnostics | yes, as diagnostics |

Use Dart Sass if you can. It is the reference implementation, and Sassy runs it with variable injection, accurate source maps and its own temp files, so nothing is written into your source tree.

scssphp is the default because it works with nothing installed. It is pure PHP, it compiles `@import` Sass, and it throws on `@use` and `@forward`. If your Sass is written with modules, or will be, configure Dart Sass first.

Both engines take a request and return CSS with diagnostics. Your own engine is a class and a filter. See [Extending Sassy](#extending-sassy).

A field data point on how close they are: on a 1388-rule production sheet compiled by both, scssphp 2.1 and Dart Sass 1.104 produced no effective differences across 3255 declarations and 83 custom properties. Dart splits a few more blocks where declarations follow nested rules, with no change to the cascade. One thing that looks like a regression and is not: legacy colour functions can make Dart emit an out-of-range `hsl()`, `hsl(163.125, 112.03%, 95.18%)` where scssphp precomputed `#e6fff8`. Browsers clamp the saturation, so the colour is the same.

## 🧮 Variables

PHP knows things your Sass would like to know. Three variables are always defined, and the `sassy-variables` filter or a registered provider adds more:

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

## 🌩️ Lightning CSS

Optional, and off until you ask. Compiled CSS can run through [Lightning CSS](https://lightningcss.dev/) for minification and modern CSS transforms. It is a registered post-processor, so it runs after the engine and after the `sassy-css` filter, and it is **off until you point Sassy at a binary**. Three ways, checked in this order:

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

The default flag is `--minify`. If the binary cannot be found or the run fails, the CSS is served unprocessed and a warning carrying Lightning's stderr is added to the compile's diagnostics, so `wp sassy compile` prints it and `wp sassy check --strict` fails on it. Lightning strips the source-map link, and Sassy adds a notice when that happens.

## 🪝 Hooks

There is no settings page and there will not be one. Everything is a constant or a filter. Per-compile filters receive `($value, $src, $handle, $asset)`, where `$asset` is the `Sassy\Asset` being built.

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
| `sassy-write-source` | `false`, or `current_user_can(SASSY_WRITE_SOURCE)` when that constant names a capability | Whether the paintbrush may write to source. Never implied by `sassy-dev` |
| `sassy-keybinding` | `['ctrl+space', 'meta+space']` | The Live Compile chords alone |
| `sassy-keybindings` | `compile`, `capture`, `push`, as above | Every action's chords. `false` unbinds one and leaves its button |

Three actions. `sassy-compiler` hands out scssphp's own `Compiler` before a compile, and does nothing under Dart Sass. `sassy-admin-bar` receives the admin bar for extending the **SCSS** menu. `sassy-wrote-source` fires once per file the paintbrush writes, with the file and the changes that landed in it, for a site that commits paints as they land.

## 🧩 Extending Sassy

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

The filters still work as before. What a filter cannot do is report a problem: a `sassy-css` callback returns a string and has no way to say it did not run. A registered post-processor is given a context and can:

```php
Sassy\Extensions::register_post_processor('my-minifier', function ($css, $context) {

    if (!$binary_exists) {
        $context->warn('my-minifier did not run; the CSS is unprocessed.');
        return $css;
    }

    return minify($css);

});
```

### 👷 Builder integrations

Sassy 2.x shipped integrations for Bricks Builder, Oxygen Builder and the Digitalis Framework, which turned their settings into SCSS variables. They are not in 3.0. Neither builder is installed where Sassy is developed, so the code was never exercised, and two real bugs shipped that way.

Each of them has been rebuilt on the extension API as a worked example in `tests/fixtures/`, tested against a stubbed builder. If you need one, copy the fixture into your theme or plugin and register it. It is about forty lines.

## 👆 Upgrading from 2.x

Some things break, and all of them are listed. 3.0 renames classes, changes the engine contract and removes the builder integrations. [docs/upgrading-to-3.0.md](docs/upgrading-to-3.0.md) lists each change and what to do about it. A site that only uses the filters should need no changes.

## 👨🏻‍💻 Development

`php tests/run.php` runs the suite. It stubs WordPress and needs no PHPUnit. Browser behaviour is checked by hand against `tests/manual.md` before a release. [AGENTS.md](AGENTS.md) describes the codebase as it stands.

## 🪶 History

Sassy was written in 2021 by Digitalis for its own sites, after the SCSS plugins in the WordPress directory turned out not to fit. The idea of enqueuing `.scss` and getting `.css` back came from Juan Echeverry's [SCSS-Library](https://wordpress.org/plugins/scss-library/). None of that code remains.

Cascade, the fox in the hat, is one of the three designers of Digit++ at Foxglove Farm, Dartmoor. Her portrait was drafted years ago and finished for this release.

## ⚖️ Licence

GPL-2.0-or-later, like WordPress. See [LICENSE](LICENSE). The images of Cascade in `assets/img/` belong to Digitalis and are not covered by it.

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="assets/img/digitalis.dark.svg">
    <img src="assets/img/digitalis.light.svg" width="220" alt="Digitalis">
  </picture>
  <br>
  © Jamie Perrelet 2021 to 2026, <a href="https://digitalis.ca/">Digitalis Web Corp</a>
</p>
