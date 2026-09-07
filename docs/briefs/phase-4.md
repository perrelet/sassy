# Builder brief: Phase 4, extension API

One builder, one phase, then stop for review. The spec is [../style-stack-plan.md](../style-stack-plan.md): read §3 phase 4 for the work, §8 for the rules of engagement. This brief adds only what the plan leaves to the builder.

## Mission

Sassy has four extension points and no way to describe them: `sassy-import-paths`, `sassy-variables`, `sassy-css` and `sassy-engine` are filters you either know about or you do not. Give them a registry, a typed contract each, and a `wp sassy status` that says who is extending what. Then prove the API is expressive enough by rebuilding the three integrations on top of it as test fixtures, and delete them from the plugin.

## Read before writing

1. `docs/style-stack-plan.md` §3 phase 4, §8
2. `include/integrations/` in full: 348 lines across four files, and the thing being retired
3. `include/model/lightning-css-postprocessor.class.php`, the reference post-processor
4. `include/model/diagnostic.class.php` and how `Printer` collects diagnostics, because a post-processor needs to reach them
5. `tests/bootstrap.php`, specifically how it stubs WordPress, because the fixtures need the same trick for builder APIs

## Judgement calls, pre-made

**A post-processor cannot report anything, and that is the real work here.** `sassy-css` is `($css, $src, $handle, $asset)` returning a string, so a post-processor can change the CSS and say nothing about it. Lightning CSS shows what that costs: when its binary is missing or the process fails it returns the CSS untouched and writes to the PHP error log, so from every Sassy surface it is indistinguishable from having run. The typed contract needs a channel back to the `Printer`'s diagnostics, and phase 3 built the type it carries. Design this first; the rest of the phase is registry plumbing.

**The source-map notice belongs to the `Printer`, not to Lightning.** The plan calls Lightning's map stripping "the canonical `notice`", but be precise about the mechanism: nothing in `Lightning_CSS_Postprocessor` strips anything. `lightningcss` simply emits CSS without a `sourceMappingURL`, and the plugin never adds one back, so the `.map` file is written and nothing links to it. That condition is general to *any* post-processor, so detect it where it is general: after the `sassy-css` filter, when source maps are on and a map was written but the returned CSS carries no `sourceMappingURL`, the `Printer` reports a `source: 'sassy'` notice naming the handle. Lightning is then the case that *triggers* the canonical notice rather than the code that emits it, and the reporting channel above is still earned, by Lightning's own silent failures.

**The registry describes; filters still bind.** Plan §3 phase 4 says typed contracts are offered "alongside, not instead". A provider registered through the API is listed by `wp sassy status`; one bound with a raw `add_filter` keeps working and is not listed. **Do not enumerate `$wp_filter` to find providers.** It yields closures with no identity, so status would report a callback count and call it a provider list, which is worse than reporting nothing.

**The fixtures need two kinds of stub and the bootstrap only does one.** `tests/bootstrap.php` stubs plain functions, which covers Oxygen entirely: `oxy_get_global_colors()`, `ct_get_global_settings($defaults)`, `oxygen_vsb_get_breakpoint_width($name)`, `oxygen_vsb_get_page_width()`, plus a `CT_VERSION` constant. **Bricks needs classes with static state**: `Bricks\Breakpoints::get_breakpoints()`, and `Bricks\Theme_Styles::$active_settings` with `::$settings_by_id` as the fallback path. Declaring those is fine but they are process-global and cannot be redefined, so give the builder fixtures their own test file rather than folding them into an existing one.

**Oxygen's condition is a suppression, not a version check.** It activates on `CT_VERSION` *and* stands down when `Digitalis\Module\OXY_SCSS\OXY_SCSS` exists, because the framework handles SCSS itself in that case. A fixture that only checks the version proves less than it claims, so carry the suppression across and test both branches.

**Deleting `Sassy\Integration` is safe.** Verified across the whole `wp-content` tree: nothing subclasses it. The one `extends Integration` hit is Lattice's own `Digitalis\Integration extends Singleton`, which is unrelated despite the name. Verified rather than assumed, because a grep read too quickly is how the `enqueue_style_last` mistake happened.

**Removing the `Digitalis` integration changes nothing at runtime here.** `lattice/load.php` injects the same `$digitalis_path` and `$digitalis_uri` through `sassy-variables` itself. That duplicate is dead too but it is outside the §4 table, so it stays a punch-list item rather than an edit.

**`Sassy::load_integrations()` goes with them,** and with it the `after_setup_theme` hook it was the only reason for.

## Strangler order

1. **The registry and the typed contracts.** Purely additive: filters keep working, nothing is switched.
2. **The post-processor contract, with its reporting channel.** Port Lightning CSS onto it and make the source-map notice appear. This is the acceptance that matters most.
3. **`wp sassy status` lists registered providers.**
4. **Rebuild the three integrations in `tests/fixtures/`**, expressed only through documented extension points, with the stubs above.
5. **Delete `include/integrations/` and `load_integrations()`.** Last, once the fixtures prove the API can express what is being deleted.

If step 4 shows the API cannot express one of them, **that is the phase's most valuable output**. Stop and report it: the plan says if the API cannot express them, the API is wrong.

## Definition of done

Every acceptance item in plan §3 phase 4, plus:

- `tests/test-extensions.php`: registering each of the four kinds and seeing it take effect; a provider registered through the API appearing in the status listing while a raw `add_filter` does not; a post-processor reporting a `Diagnostic` that reaches `Printer::get_warnings()`.
- `tests/test-fixtures.php` (or similar): each retired integration rebuilt on the API and producing the same variables it did before, against stubbed builder APIs, including Oxygen's suppression branch.
- With Lightning CSS enabled and source maps on, a compile produces a `source: 'sassy'` notice saying the map is written but unlinked, and it reaches the CLI and the payload like any other diagnostic. With Lightning off, no notice.
- A Lightning run whose binary is missing reports that rather than passing silently.
- `grep -rn 'Integration' include/` returns nothing.
- `php tests/run.php` green. Note in the handoff which pre-phase-4 failures are migration and which are the proof: §8 explains why that distinction now has to be stated.
- Live: `wp sassy status` lists providers, `wp sassy compile --hooks=all` still compiles the three d-pace handles, and `wp sassy vars` still shows the five variables (three defaults plus lattice's two), proving nothing was lost with the integrations.

## Out of bounds

- No builder installs, ever. Bricks and Oxygen are stubbed, never installed, which is the whole reason they became fixtures.
- Phase 5's `check` and `--strict`, phase 6's surfaces.
- No new runtime dependencies, no composer or npm.
- Do not touch the `sassy-css` filter's existing signature for callers that still use it; the typed contract is offered alongside.
- `docs/style-stack-plan.md` is the spec of record; changing it means saying so in the commit message.

## Handoff

End with: what was built, what the live verification showed, anything that surprised you or contradicted the plan, and nothing else started.

Update `AGENTS.md` and `docs/upgrading-to-3.0.md` as part of landing. The upgrade notes need the integrations' removal and the variables that go with them, which is the most visible break in 3.0 for anyone running Bricks or Oxygen. AGENTS.md's Integrations section describes three classes that will no longer exist.
