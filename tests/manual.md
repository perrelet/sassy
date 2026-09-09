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
| Open the **SCSS** menu | Live Compile, Logging, Clear Cache, then one entry per compiled handle |
| Click **🤖 Force Compile** with nothing changed | Handles recompile anyway. Live Compile in the same state should report cached instead: that difference is the reason both exist |
| Confirm what is absent | No **Log Variables**: `wp sassy vars` and the Logging menu replace it |
| Inspect a per-handle node's id | `sassy-<handle>`, e.g. `sassy-d-pace-frontend`. Not a number: a handle keeps its node across requests even when another handle stops compiling |
| Check the glyph against `wp sassy list` | Same word for the same handle: `current`, `stale`, `warning`, `not built`, `no source` |
| Click **Clear Cache** | Page reloads, handles recompile, glyphs return to current |

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
- `wp sassy check` exits zero.
- Note anything you had to hard-reload twice for. That means something is versioning wrong.
