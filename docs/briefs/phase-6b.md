# Builder brief: Phase 6b, the admin page

One builder, one phase, then stop for review. The spec is [../style-stack-plan.md](../style-stack-plan.md): read §3 phase 6b for the work, including "Design, settled", §2 for "surfaces contain no policy", §8 for the rules of engagement. This brief adds only what the plan leaves to the builder.

## Mission

The first surface with a face. Everything it shows can already be asked for: `wp sassy status`, `list`, `deps` and `check` between them print all of it. The page renders the same answers for a person who is not at a terminal and adds the one thing the CLI cannot, an action you click while looking at the result.

It is also where six things deferred from phase 6 land, and where the admin bar finally loses the per-handle entries and Clear Cache that stayed in 3.0.0 only because this page did not exist.

## Read before writing

1. `docs/style-stack-plan.md` §3 phase 6b in full, then the phase 7 tier 0 paragraph, which is what the poll serves.
2. `include/cli/sassy-cli-command.class.php`: `status()`, `list_()`, `deps()` and `check()` are the page's four sections already written as data. If the page needs a fact the CLI cannot get from the model, the model is missing a method. Add it there, not in the page.
3. `include/model/style-stack.class.php`, particularly `fire()`, `admin_screen()` and `read_queues()`. Discovery inside a real admin request is the trap this phase has that no earlier one had.
4. `include/model/compile-cache.class.php`, which gains a second record and already owns every transient.
5. `assets/js/sassy.js` and `include/view/ui.class.php`, which lose entries and gain the poll and copy.
6. `tests/manual.md`, which grows a section.

## What moves here

| Item | Where it stands | What this phase owes |
|---|---|---|
| Full diagnostic persistence | Phase 5's severity tally under `__diagnostics__` | Its own key, written on both outcomes. Below |
| Provider listing | `Extensions::providers()`, printed by `wp sassy status` | The Status section renders it |
| Change poll | `meta.hash` is in the payload; nothing polls it | An opt-in Logging toggle. Below |
| Copy | Nothing. `.sassy-clipboard` in the CSS has no consumer | Per-diagnostic and copy-all, canonical text |
| Stack summary and capture diff toggles | Not built | **Not this phase either.** They log what phases 8 and 7 produce. No placeholder toggles |
| Per-source links | In the bar, and they resolve nothing under Dart | The per-handle view, resolved through the recorded `Import_Graph` |
| Clear Cache and the per-handle bar entries | In the bar | Leave the bar once the page has them |

## Judgement calls, pre-made

**Tools → Sassy.** `add_management_page()`, slug `sassy`, capability `read`, registered on `admin_menu` only when `Policy::active()`, with the callback checking `Policy::active()` again. WordPress wants a capability string and no capability is the gate: the gate is the filter, and a `dev` on the reference install need not hold `edit_theme_options`. Tools rather than Appearance because this is a dashboard about the install, and Appearance is the menu a locked-down site hides first. Not top-level: one page does not earn a menu.

**Discovery inside a real request pollutes it.** `Style_Stack::discover()` fires the enqueue hooks. On the CLI and in admin-ajax nothing is listening; in a wp-admin page request everything the theme enqueues lands in the live `wp_styles()` and `wp_scripts()` queues and prints in the footer, and `admin_screen('post')` replaces the page's own screen. Fix both inside `Style_Stack`, not the page, because the endpoint has the same problem the moment it takes `hooks=all`: for the duration of `fire()`, swap fresh `WP_Styles` and `WP_Scripts` instances into the globals and restore the originals after (the `WP_Styles` constructor fires `wp_default_styles`, so a fresh instance carries the same registrations), and hand `get_current_screen()`'s object back to `set_current_screen()` after, which accepts a `WP_Screen`. The count to hold: `wp sassy list --hooks=all --format=count` says 352 on this install today.

**Diagnostics get their own key, written on both outcomes.** `Compile_Cache::record_diagnostics()` writes `sassy-diagnostics-{handle}` as `['time' => ..., 'diagnostics' => [...to_array()]]`, called from `Printer::compile()` after success and after failure. Phase 2's rule is untouched: the currency record and the tally are written on success only and `get_state()` does not read the new key. What it buys is the page showing the error that made a handle stale. Measured today: `d-pace-frontend`'s ten deprecations are 7.2 KB as JSON, against a currency record already at 17.5 KB, and only the page reads the key. `forget_handle()` and `forget_all()` drop it too, and the index from `2d017f9` is what makes the latter possible under the external object cache staging runs.

**Resolve what Dart cites against the recorded graph, and it is not only basenames.** One compile here cites `_harness.scss` and `_editorial.scss` bare and `wp-content/plugins/d-pace/scss/components/_service-card.scss` relative to the working directory, in the same run. Match the cited string as a path suffix against the recorded `deps`: exactly one recorded path ends in it, resolved; zero or several, keep the engine's text and say nothing. Ground truth for the fallback: 124 deps and exactly one colliding basename per big handle, `_index.scss`, which components and integrations both have. This is presentation. The stored `Diagnostic` keeps `file` as the engine said it, the resolved path is computed at render time and feeds the click-to-copy, and nothing rewrites a record.

**The endpoint takes `hooks`.** Compile all on the page has to reach admin and editor handles, and `compile_all()` discovers `frontend` only, which is also why Live Compile from a wp-admin screen has never rebuilt the sheet on that screen. Accept `hooks`, a comma list or `all`, validated against `Style_Stack::CONTEXTS`, default `frontend`. The JS sends the context it was enqueued in, `sass_params.context`, which is `admin` under `is_admin()` and `frontend` otherwise; the page sends `all`. The block editor is an admin screen.

**The poll is the endpoint again, not a second one.** With Logging → Auto-reload on, call the compile endpoint every two seconds with the page's context and reload only the sheets whose `meta.hash` changed. That endpoint is the thing that answers "is it current"; a cheaper one answering half the question would be a second policy surface. The cost is one discovery per tick per open tab, which is a dev machine's problem and off by default. The toggle persists in `localStorage` like the other two, and the admin page itself polls nothing.

**Copy is `navigator.clipboard.writeText` of canonical text kept in the markup.** Each diagnostic row carries `Diagnostic::render()` in a hidden `<pre>`; copy-all joins them the way `render_all()` does. The clipboard API needs a secure context, so hide the buttons when `navigator.clipboard` is absent rather than let them fail. Delete `.sassy-clipboard` from the stylesheet or build the button on it, not both.

**Sassy's own stylesheet becomes `assets/scss/sassy.scss` and is enqueued as such.** `Sassy::enqueue_scripts()` enqueues the `.scss`, `style_loader_src` builds it like any other handle, and it appears in `wp sassy list` as `sassy` with its output at `wp-content/scss/sassy.css`, under whatever engine the site's filter returns. Author it for scssphp: `@import`, no `@use`, and a test that compiles it on both engines. Two consequences to name in the docs rather than discover: it is enqueued only when `Policy::active()`, so a CLI run with no `--user` never sees it and a deploy compile never primes it, which is right for a dev-only sheet; and `asset_version()` now stamps the source's mtime, which still busts on every edit because every edit recompiles. `.vscode/` is gitignored and the build is now Sassy, so the old task is nobody's problem.

**Design exactly as settled in the plan, no wider.** wp-admin furniture, header-plus-verbatim diagnostics, click-to-copy locations, groups folded by `code`. No settings form, no colour beyond the notice palette and one accent, no icon font, no framework, no build step. Server-rendered, attributes drive one small JS file.

**Retire from the bar exactly what the page replaces.** Clear Cache and the per-handle entries go; Live Compile, Force Compile, Logging and the `sassy-admin-bar` action stay. The page's Clear cache and Compile all are nonced POSTs, to `admin_post_sassy_clear` and to the compile endpoint, so `UI::clear_cache()` and its `init` hook leave with the link.

**The version moves to 3.1.0, last**, the same two strings phase 6 moved. If 3.0.0 is still unreleased when this lands, whether the two fold into one is Jamie's call, not a guess.

## Strangler order

1. **`Style_Stack` isolation**, registries and screen. Testable with the harness's queue stub.
2. **`Compile_Cache::record_diagnostics()`** and its reads.
3. **Endpoint `hooks`** and `sass_params.context`.
4. **The page**: Status, Stack, per-handle, Actions, each reading the model and nothing else.
5. **Copy and the poll**, in the JS.
6. **`assets/scss/sassy.scss`**, then retire the bar entries and `UI::clear_cache()`.
7. **`tests/manual.md`, walked**, then the version.

## Definition of done

Every acceptance item in plan §3 phase 6b, plus:

- `tests/test-style-stack.php`: discovery leaves the caller's queue untouched (register a handle on the stub queue, discover a context that registers another, assert the first queue still holds only the first), and the current screen is handed back (stub `get_current_screen()` and `set_current_screen()`).
- `tests/test-clear-cache.php`: `record_diagnostics()` after a success and after a failure; `forget_handle()` and `forget_all()` drop the key.
- A renderer test: the page's sections are pure functions from model answers to HTML, so assert a diagnostic row carries its canonical text verbatim, a resolved path where exactly one dep matches and the engine's text where two do. The bootstrap needs `esc_html()`, `esc_attr()`, `esc_url()`, `admin_url()` and `wp_nonce_field()`; stub them there, not per test.
- `assets/scss/sassy.scss` compiles without error on scssphp and, when the binary is present, on Dart.
- `tests/test-js.php`: copy writes the canonical text to a stubbed `navigator.clipboard`; the poll reloads only on a changed hash; the endpoint URL carries `hooks`.
- `grep -rn "sassy-clear-cache\|sassy-clipboard" include/ assets/` returns nothing, or only the copy button's class if you kept the name.
- Live, as a dev: the page is under Tools and absent for an administrator without `dev`; Stack shows every handle under all three contexts and the four compilable ones, the three d-pace sheets and `sassy`; the page's footer prints no theme stylesheet; Compile all rebuilds all four; Clear cache empties all four under the external object cache; a diagnostic's copy pastes byte-identical to `wp sassy compile <handle> --force` output; the console is clean.
- `wp sassy list --hooks=all --format=count` still 352 with no user and 353 with `--user=<a dev>`.
- `tests/manual.md` gains the page, copy, the poll and the bar's new shape, and has been walked. Say what you saw.

## Out of bounds

- Phase 7: no capture, no CSSOM, no write endpoint. The poll is tier 0's reload, not tier 1.
- Phase 8: no `type: script`, no profiler, no stack summary toggle.
- No new filters beyond what the plan names, no settings, no dependencies, no build step.
- `docs/style-stack-plan.md` is the spec of record; changing it means saying so in the commit message.

## Handoff

End with: what was built, what the live verification showed including what you saw in the browser, anything that surprised you or contradicted the plan, and nothing else started.

Update `AGENTS.md`, `README.md` and `docs/upgrading-to-3.0.md` as part of landing. The upgrade notes carry the bar losing Clear Cache and the per-handle entries, `?sassy-clear-cache` going, and a new `sassy` handle appearing in `wp sassy list` for a logged-in dev. README gains the page and the Auto-reload toggle. AGENTS.md's Admin UI section loses the paragraph about the Dart links, which this phase makes true again.
