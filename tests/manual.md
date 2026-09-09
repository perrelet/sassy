# Manual acceptance

`php tests/run.php` covers the PHP. It stubs WordPress and never opens a browser, so everything below is untested by anything except a person following this list. Walk it before a release.

Untested surface is named rather than implied covered. If you add browser behaviour, add a line here in the same commit.

## Before you start

- Log in as a user the dev surface is active for. On the reference install that is anyone holding the `dev` capability, since d-pace binds `sassy-dev` to it.
- **Hard reload** (ctrl/cmd + shift + R). Sassy's own assets version by file mtime, so a change should bust on its own, but a hard reload removes the question.
- Open the console. Several checks below are console-only.

## The gate

| Check | Expected |
|---|---|
| Load a page logged in as a dev | Admin bar shows the **SCSS** menu |
| Log out, load the same page | No admin bar node, no `sassy.js`, no `sass_params` in the source, and **the compiled CSS still loads**. The stylesheet is the product; the dev surface is not |
| `add_filter('sassy-dev', '__return_false')`, reload as a dev | Same as logged out |
| Revoke your `dev` capability (`wp lattice dev` on the reference install), reload as an administrator | No dev surface. This is the "gates are about who, not where" claim, and it is the only way to actually see it |

## Live Compile

| Check | Expected |
|---|---|
| Click **⚡ Live Compile** | Notice appears, stylesheets swap without a page reload, notice clears |
| Watch the network tab during it | The stylesheet URL gains a `?sassy=` value that is a **content hash**, not a random number. Compiling twice with no source change gives the **same** hash |
| Edit a partial, then Live Compile | The change is visible without a reload |
| Break a partial (`.a { color: }`), then Live Compile | Error panel appears at the bottom with the engine's own frame and trace, monospace and aligned. The admin bar glyph turns ❌ |
| Click **Dismiss** on the panel | Panel closes |
| Fix the partial, Live Compile again | Panel stays closed, glyph returns |

## Diagnostics

| Check | Expected |
|---|---|
| Compile a handle with deprecations (`d-pace-frontend` has ten) | With **Logging → Diagnostics** on, the console shows each one rendered like the CLI: severity, `file:line:col`, message, then the engine's frame and trace |
| Compare one console entry to `wp sassy compile <handle> --force` | **Byte-identical** apart from WP-CLI's own `Warning:` prefix. This is the "one schema, four renderings" claim |
| Turn **Logging → Diagnostics** off, reload, Live Compile | No warning output in the console |
| Turn **Logging → Compile meta** on | `console.info` per handle with the compile metadata |
| Toggle a Logging item, then reload | The tick survives. It is per-browser, in `localStorage`, and never shared |
| Toggle **Diagnostics** on and **Compile meta** off | They are independent. A bug in phase 6 had all toggles sharing one key, so check both, not one |

## Keybinding

| Check | Expected |
|---|---|
| Press CTRL+SPACE with focus on the page | Live Compile runs |
| Press CMD+SPACE (macOS) | Same. Both are bound by default |
| Click into any text field and press CTRL+SPACE | **Nothing happens.** The handler bails unless focus is on `body`, which is what stops it fighting IME and autocomplete |
| Hold CTRL+SPACE down | Fires once, not repeatedly |
| `add_filter('sassy-keybinding', '__return_false')`, reload | The key does nothing; the admin bar button still works |
| `add_filter('sassy-keybinding', fn () => ['ctrl+shift+k'])`, reload | That combination compiles; CTRL+SPACE no longer does |

## Admin bar

| Check | Expected |
|---|---|
| Open the **SCSS** menu | Live Compile, Force Compile, Capture, Logging, Dashboard. Nothing per handle: that detail is on the page |
| Click **🤖 Force Compile** with nothing changed | Handles recompile anyway. Live Compile in the same state should report cached instead: that difference is the reason both exist |
| Confirm what is absent | No **Log Variables**, no **Clear Cache**, no per-handle entries: `wp sassy vars`, the Logging menu and the page replace them |
| On a wp-admin screen, edit `admin.scss` and click **⚡ Live Compile** | The admin sheet on screen updates. Before 6b the endpoint only ever discovered the frontend |
| Click **🧭 Dashboard** | Tools → Sassy opens |

## The page

Tools → Sassy, as a dev. Walk it once on a current install and once after breaking a partial.

| Check | Expected |
|---|---|
| Log in as an administrator without `dev` | No **Sassy** under Tools, and `tools.php?page=sassy` is refused |
| **Check** with everything current | The green "Everything current" notice, matching `wp sassy check` |
| Break a partial, run `wp sassy compile`, reload the page | The handle reads `stale` in Stack and Check, and its section shows the error under **Last diagnostics**, with the engine's frame in the dark block. `check` never compiles, so this is the recorded failure, not a fresh one |
| **Status** against `wp sassy status` | Same rows, same words. `dev surface` reads active for you |
| **Stack** filters | `all` shows every handle (353 here); `managed`, `compilable`, `third-party` narrow, and the pressed button is outlined |
| The page's own footer | No theme stylesheet, no frontend script: discovery ran into copies of the registries |
| A handle's **Last diagnostics** on `d-pace-frontend` | Ten deprecations folded into one `global-builtin` group; the group opens on click; each has a location like `wp-content/plugins/d-pace/lattice-css/scss/_harness.scss:54:13`, resolved from the bare `_harness.scss` Dart cites |
| Click a location | A "Copied" notice; the clipboard holds the absolute path with `:line:column` |
| Click **Copy** on one diagnostic, paste into a terminal beside `wp sassy compile d-pace-frontend --force` | Byte-identical to that diagnostic's block there. Copy yields the canonical text, never the DOM |
| **Copy all** | Every diagnostic for the handle, blank-line separated, as `Diagnostic::render_all()` prints them |
| Import graph `<details>` | Files with modified time and `current`/`changed`/`missing`; the watched directories below |
| **Compile all** | Notice, then the page reloads with every handle current and fresh compile times |
| **Clear cache** | Redirects back with "Caches cleared"; every handle now reads `not built` or `stale` until the next request compiles it. Works under the external object cache staging runs |
| Load the page over plain `http` (or with `navigator.clipboard` stubbed out in the console) | The Copy buttons are hidden; locations still render |

## Capture

Tier 1 of the paintbrush. Everything here is observation; nothing is written.

| Check | Expected |
|---|---|
| Press **🖌️ Capture** with nothing edited | The panel opens titled **Captured styles** reading "Nothing changed since the last snapshot" |
| Inspect the site header, set `background: red` in the styles pane, Capture | One header line `plugins/d-pace/scss/components/_site-header.scss:33  .site-header` over one row `background: var(--material-bg, var(--surface-dark)) → red`. One row, not nine longhands, and line 33 (the declaration), not 23 (the rule) |
| Open the cited file at that line | It is the `background:` declaration |
| Click **Copy** on the panel | The clipboard holds exactly the panel's text |
| Edit a declaration inside a `@media` rule, Capture | It maps to its own line inside the partial that declares it |
| Add a new rule in the inspector (the `+` in the styles pane), Capture | Listed as `inspector-stylesheet  <selector>  (no source location; try …frontend.scss)` with its declarations |
| Set a style on an element directly (`element.style` in the styles pane), Capture | Listed as `element.style on <tag#id.class>  (no stylesheet)` |
| Live Compile, then Capture | "Nothing changed": the reload re-baselined, so the compile is not a paint |
| Turn **Logging → Capture diffs** on, Capture again | The same patch is in the console |
| `document.addEventListener('sassy:captured', e => console.log(e.detail))` then Capture | `patch` is the panel's text; `changes` has one entry per row |

## Auto-reload

| Check | Expected |
|---|---|
| Turn **Logging → Auto-reload** on | Network tab shows the compile endpoint every two seconds with `hooks=<context>` and no `force` |
| Edit a partial and save, with the tab in the foreground | Within two seconds the affected sheet re-requests with `?sassy=<new hash>`; the others do not |
| Switch to another tab for ten seconds | No requests while hidden |
| Turn it off | The requests stop. Reload: they do not resume, and the toggle is unticked |
| Log out in another tab while it is on | One console error saying auto-reload stopped, then no further requests |

## The event contract

Paste into the console, then click Live Compile:

```js
document.addEventListener('sassy:before-compile', () => console.log('→ before'));
document.addEventListener('sassy:compiled', e => console.log('→ compiled', e.detail.diagnostics));
```

| Check | Expected |
|---|---|
| Both fire, in order | `before` then `compiled` |
| `e.detail.diagnostics` | Keyed by handle, each an array of diagnostic objects |
| `window.sassy.compile()` in the console | Compiles, exactly as the button does. `window.sassy.compile(true)` forces |
| `window.sassy.reload()` in the console | Same-origin stylesheets re-request with a fresh query value, no recompile, no page load |

The iframe bridge a builder integration needs, which replaced the Angular reach-in. Confirm it is genuinely this small:

```js
document.addEventListener('sassy:compiled', e => {
    const frame = document.querySelector('iframe');
    if (frame) frame.contentWindow.postMessage({ type: 'sassy:compiled', detail: e.detail }, '*');
});
```

## Finally

- Console is clean: no errors, no warnings Sassy did not intend.
- `wp sassy check` exits zero, and it says the same thing the page's Check section does.
- Note anything you had to hard-reload twice for. That means something is versioning wrong.
