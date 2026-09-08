# Builder brief: Phase 6, frontend and admin surface

One builder, one phase, then stop for review. The spec is [../style-stack-plan.md](../style-stack-plan.md): read §3 phase 6 for the work, §2 for the state vocabulary rule, §8 for the rules of engagement. This brief adds only what the plan leaves to the builder.

## Mission

The dev surface: who sees it, what it shows, and how it talks to the page. This is the largest phase in the plan, the last one in 3.0.0, and the only one whose output nobody can test automatically.

It also settles four debts earlier phases deliberately deferred here. Read those before planning your order, because two of them change files this phase is rewriting anyway.

## Read before writing

1. `docs/style-stack-plan.md` §3 phase 6 in full, plus §2's "a vocabulary is policy"
2. `assets/js/sassy.js`, all 346 lines. **This session shipped `[object Object]` to the console for an hour** because the AJAX payload was verified and the file consuming it was never opened. It is the least-read file in the repository and this phase rewrites it.
3. `include/view/ui.class.php` and `Sassy::enqueue_scripts()`, `print_errors()`, `compile_all()`
4. `include/model/diagnostic.class.php` and `Style_Stack::audit()`, which the panel and admin page render
5. `tests/manual.md`, which does not exist yet and is one of your deliverables

## The four debts

| Debt | Where it stands | What this phase owes |
|---|---|---|
| `meta.index` | `Printer` carries no counter; `Sassy` assigns one so the JS keeps working. `Sassy::$printers`, `add_printer()` and `index_of()` exist *only* for this | Rekey errors and admin-bar nodes by **handle**. The JS queries `#wp-admin-bar-sassy-${meta.index}` in three places, so both sides move together or neither does |
| State vocabulary | Three exist: `UI` says error/warning/compiled/cache, `wp sassy list` says no source/not built/current/stale, `wp sassy deps` says MISSING/current/changed | Two owners, per plan §3 phase 6: **asset state** on `Compile_Cache`, **file state** on `Import_Graph`. `wp sassy list`'s `state` column changes again, its second change in four phases |
| Diagnostic persistence | Phase 5 stores a severity **tally** under `__diagnostics__` in the filemtimes record | The per-handle panel needs the text. That needs **its own transient key**: the filemtimes record is read on every request and one compile's frames run to 5.5 KB. `Compile_Cache` owns it, like every other key |
| Version bump | `SASSY_VERSION` and the plugin header are still `2.1.0`, pinned here by plan §1 | Move both to `3.0.0` together, **last**. The updater compares the constant |

## Judgement calls, pre-made

**The suite cannot test any of this, so the verification is a deliverable rather than a step.** `tests/manual.md` is not a file that exists, it is a checklist a person walks before a release. Every browser behaviour this phase ships needs a line in it, written so someone who did not build it can follow. Untested surface is named, never implied covered.

**Hard reload and read the console, every time.** Sassy's own assets version by mtime since `55723b3`, so a JS change now busts on its own. Before that fix, five phases of JS changes were invisible to any browser that had cached the file. Do not reintroduce `SASSY_VERSION` on the plugin's own assets: the bump this phase performs is the plugin's, not the assets'.

**`Policy::active()` replaces four inline checks, and a missed one is a hole in the gate.** They are at `sassy.class.php:94` (`enqueue_scripts`), `sassy.class.php:200` (`print_errors`), `ui.class.php:32` (`admin_bar_menu`) and `ui.class.php:199` (`clear_cache`, which is the one that actually does something destructive). The first draft of this brief said three, because I counted the ones I remembered rather than grepping. Grep for `current_user_can` when you think you are done, and again after.

**The endpoint checks `Policy::active()` server-side, in addition to the nonce**, and `wp_ajax_nopriv_sassy_compile` is deregistered. A nonce is a CSRF token, not an authorization model, and a dev is by definition logged in.

**The Angular reach-in is two lines and deleting it is the easy half.** `sassy.js:67-68` calls `angular.element('body').on('keydown')` and `parent.angular.element(...)`, fed by `params.builder` and `params.backend` from `enqueue_scripts()`. The hard half is that the `sassy:*` events must genuinely let someone re-implement it. Write the five-line iframe bridge the plan promises, against the events you actually shipped, and put it in the docs. If it cannot be written in five lines, the event contract is wrong.

**Preserve the keybinding behaviours that exist for a reason.** The current handler debounces at 250ms, ignores repeats, and bails when `event.target.nodeName !== 'BODY'`. That last one is what stops CTRL+SPACE firing inside an editor field. `sassy-keybinding` defaults to `['ctrl+space', 'meta+space']`, matching today's `ctrlKey || metaKey`, and `false` disables the binding entirely while leaving the admin bar button.

**Cache-busting uses a server content hash, not `Math.random()`** (`sassy.js:199`). Compute it once when writing the CSS and put it in the payload. The plan wants the same hash as the change-poll primitive, so a random value would need replacing twice.

**Removals are a set, not a list.** `?sassy-vars=1`, `?sassy-recompile=1`, `UI::print_variables()`, `Sassy::get_all_variables()` and `meta.variables` all go together, along with Force Recompile and Clear Cache leaving the bar. Live Compile already skips the cache, which is what makes Force Recompile redundant rather than merely unfashionable.

**The admin page is a dashboard.** If you find yourself writing a form field, stop: config is code, and the only actions are Compile all and Clear cache.

## Strangler order

Server first, browser last, because the server half is the half you can test:

1. **`Policy`** and the endpoint contract.
2. **The state vocabulary**, in `Compile_Cache` and `Import_Graph`. Domain, testable, and `wp sassy list` moves onto it here.
3. **Diagnostic persistence** under its own key.
4. **Admin bar prune and rekey by handle**, PHP and JS in one commit because they cannot land apart.
5. **The JS**: event contract, keybinding filter, content-hash busting, Angular deleted.
6. **The admin page.**
7. **`tests/manual.md`, walked**, then the version bump.

## Definition of done

Every acceptance item in plan §3 phase 6, plus:

- `tests/test-policy.php`: `Policy::active()` defaulting to `edit_theme_options`, the `sassy-dev` filter overriding it both ways, and the endpoint refusing when it returns false even with a valid nonce.
- State vocabulary tested where it is owned, and `grep -rn "'stale'\|'not built'" include/` returning hits in one file each, which is the plan's own acceptance.
- `grep -rn 'current_user_can' include/` returns only `Policy`.
- `grep -rn 'angular\|sassy-vars\|sassy-recompile\|get_all_variables' include/ assets/` returns nothing.
- `tests/manual.md` exists, covers every browser behaviour this phase ships, and **has been walked once before you hand off**. Say in the handoff that you walked it and what you saw.
- Live, in an actual browser, logged in: hard reload, confirm no console errors, trigger Live Compile, confirm the panel and console render diagnostics through the canonical formatter, confirm the admin bar node ids the JS queries still resolve, and confirm CTRL+SPACE works and does nothing inside a text field.
- With `sassy-dev` returning false: no JS, no panel markup, no localized params, no admin bar node, and the compiled CSS still served.

## Out of bounds

- Phase 7 entirely: no capture, no CSSOM, no `sassy-write-source`.
- Phase 8's profiler. Phase 6's "profiler reports only intended categories" acceptance **cannot close in this phase** and is carried forward explicitly rather than waived, per §8.
- No build step, no framework, no dependencies. Server-rendered, attribute-driven, one hand-rolled JS file.
- No settings form, anywhere.
- `docs/style-stack-plan.md` is the spec of record; changing it means saying so in the commit message.

## Handoff

End with: what was built, what the live verification showed **including what you saw in the browser**, anything that surprised you or contradicted the plan, and nothing else started.

Update `AGENTS.md`, `README.md` and `docs/upgrading-to-3.0.md` as part of landing. The upgrade notes carry the largest break in the phase: `?sassy-vars=1`, `get_all_variables()`, `meta.variables` and `meta.index` all disappear, and anyone who scripted against the AJAX payload is affected. `README.md` documents Live Compile and CTRL+SPACE, both of which change.
