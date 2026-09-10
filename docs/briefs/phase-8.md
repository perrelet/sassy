# Builder brief: Phase 8, script observation

One builder, one phase, then stop for review. The spec is [../style-stack-plan.md](../style-stack-plan.md): read §3 phase 8 for the work, §1's design commitments (Sassy compiles CSS, Sassy observes JS: detection, never interpretation), §8 for the rules of engagement. This brief adds only what the plan leaves to the builder.

## Mission

`Asset` learns that scripts exist, and each local script gets a `Style_Surface`: which of five kinds of style mutation its text contains, by marker, with no semantics. The value is the intersection nothing else has: WordPress knows which scripts are enqueued, and only Sassy will know which of them drive the cascade and how. Phase 9's cross-reference is built on this and is not this.

## Read before writing

1. `docs/style-stack-plan.md` §3 phase 8, in full, including the row about custom-property writes being the future primary mechanism and not pollution.
2. `include/model/style-stack.class.php` and `include/model/asset.class.php`: discovery already clones `wp_scripts()` during isolation and throws the copy away; this phase reads it.
3. `include/cli/sassy-cli-command.class.php`, `list_()`, and `Admin_Page::data()`: the two surfaces that grow a column.
4. `assets/js/sassy.js`, because it is the first file the profiler runs over, and the acceptance is that it reports only what phase 6 intended.

## Measured before writing, 2026-09-10

- **282 scripts** are registered under all three contexts on the reference install: 262 local and readable (14.2 MB), 240 of them minified, 22 source; 8 remote (all `ajax.googleapis.com`), 11 with no `src`, 1 local and missing.
- **d-pace's baseline has moved** since the plan's August figures, which is why the plan says to assert against grep, never a constant: 16 files outside `lattice/` and `node_modules/` (28 with lattice's 12), `classList` in 3 files, `dataset` in 6, `.style.` in 4, `ResizeObserver` in 5, `getBoundingClientRect` in 1, `matchMedia` in 2, `data-theme` in **1** (`assets/js/site-header.js`), custom-property writes and CSSOM injection **0**.
- **Sassy's own JS** trips `classList` 8 times, `dataset` once, `setAttribute('data-` 3 times, and also `.style.` once and `styleSheets`/`cssRules` 5 times. The `.style.` is `rule.style.cssText`, a read; the CSSOM hits are reads too. Under a naive marker set Sassy would report herself as writing inline layout and injecting CSSOM, and the acceptance would fail on the first file it ran over.

## Judgement calls, pre-made

**Discovery reads scripts the way it reads styles.** `Style_Stack::read_queues()` also reads the registries from a `sassy-script-queues` filter, defaulting to `[wp_scripts()]`, into `Asset`s with `type: 'script'`. `all()` returns both types keyed by handle; a script and a style may share a handle, so key the internal store by `type:handle` and let `handle($h, $type = 'style')` disambiguate. `styles()` and `scripts()` narrow. `compilable()` is unchanged: `is_compilable()` requires `scss`, so scripts never reach a `Printer`, `audit()` or `check`. Isolation already copies `wp_scripts()`; nothing there changes.

**Styles and scripts are not symmetric.** The plan's line, and it decides two things here. `Asset` gains no script-shaped method; a script *has* a `Style_Surface` the way a style *has* an `Import_Graph`, reached as `Style_Surface::of($asset)`. And no surface pretends a script can be compiled, checked or pushed to.

**`wp sassy list` keeps its count.** `--type=style|script|all`, default `style`, so `wp sassy list --hooks=all --format=count` still says 352 and every phase 1 acceptance holds; `--type=all` says 634 today. A script row carries `handle`, `type`, `deps`, `source` and a new `surface` column: the categories present with their counts, `scope 4, layout reads 2` in the row formats and an object of category to count under `json` and `yaml`, with `markers` beside it giving the per-marker counts and `attributes` the `data-*` names touched. `--touches=<attribute>` narrows to scripts that touch it, `--touches=data-theme` being the plan's acceptance query. Styles show nothing in the column.

**Five categories, by assignment-shaped markers.** Regexes over the file's text, counted per occurrence and reported per category; comments and strings are not excluded, because a false positive costs a glance and a miss costs the signal. The shapes that matter:

| Category | Counts | Does not count |
|---|---|---|
| `scope` | `.classList.`, `.dataset.` and `.dataset[`, `setAttribute(`/`toggleAttribute(`/`removeAttribute(` with a `data-` literal | |
| `custom_properties` | `setProperty(` with a literal beginning `--` | a computed name, which is unknowable |
| `inline_writes` | `.style.<prop> =` (one `=`, not `==`), `.style.cssText =`, `setAttribute('style'`, `setProperty(` with a literal not beginning `--` | `.style.<prop>` read, which is how Sassy reads `rule.style.cssText` |
| `layout_reads` | `getBoundingClientRect`, `getComputedStyle`, `ResizeObserver`, `matchMedia`, `.offset/client/scroll` `Width/Height/Top/Left` | |
| `cssom` | `adoptedStyleSheets =`, `.insertRule(`, `.deleteRule(`, `new CSSStyleSheet`, `CSS.registerProperty`, `createElement('style'` | reading `document.styleSheets` or `cssRules`, which is what the paintbrush does |

`scope` also records the attribute names it saw: `setAttribute('data-theme'` and `dataset.theme` both yield `theme`; `classList` yields nothing, a class is not an attribute. That list is what `--touches` filters on and what phase 9 will cross-reference.

**Sassy's own JS is the first acceptance, as a test.** `tests/test-style-surface.php` runs the profiler over `assets/js/sassy.js` and asserts the categories present are exactly `['scope']`. That pins the phase 6 design intent the plan carried forward and catches a future `el.style.x =` in that file on the day it lands.

**Minified files are read, and they are the point.** Property names survive minification, so jQuery, the block editor and every vendor bundle report truthfully, and the `cssom` row is the plan's "what third parties do to you". Remote scripts and missing files have no surface and say so; a script with no `src` is a dependency-only handle and is listed as one.

**Cache by stamp, in one record.** 14 MB of regex per `list --type=script` is a second or two on the CLI and too much on every dashboard load. `Style_Surface` keeps one transient, `sassy-surfaces`, mapping path to `[stamp, counts, attributes]` with `Import_Graph::stamp()` as the stamp, recomputes only entries whose stamp moved, and drops the record in `Compile_Cache::forget_all()`. Under the external object cache staging runs that is one key, which is why it is one record and not 262.

**The dashboard shows scripts as a kind.** The Stack table gains a `script` kind with its own filter button, a `surface` column, and nothing per handle: there is no compile, so there is no section. The filter counts include them; the `all` count is the sum.

**The version moves to 3.4.0, last.**

## Strangler order

1. **`Asset` and `Style_Stack`** read scripts. Styles-only callers are unaffected because `compilable()` is, and `wp sassy list` defaults to `--type=style`.
2. **`Style_Surface`**, the markers, the attributes, the cache, tested against fixtures and against `sassy.js`.
3. **`wp sassy list --type` and `--touches`**, then the dashboard's kind, column and filter.
4. **`tests/manual.md`**, walked, then the version.

## Definition of done

Every acceptance item in plan §3 phase 8, plus:

- `tests/test-style-stack.php`: scripts are discovered from `wp_scripts()` and from a `sassy-script-queues` queue; a style and a script sharing a handle are both kept; `compilable()` never contains a script; `styles()` and `scripts()` narrow.
- `tests/test-style-surface.php`: one fixture per category and per "does not count" row above; `dataset.theme` and `setAttribute('data-theme'` both yield the attribute `theme`; a minified one-liner is still counted; a remote script has no surface; a recomputation happens only when the stamp moves; `forget_all()` drops the record; and `assets/js/sassy.js` reports exactly `['scope']`.
- `tests/test-admin-page.php`: a script row carries kind `script` and its surface; a style row's surface is empty.
- Live: `wp sassy list --hooks=all --format=count` still 352; `--type=all` 634 or whatever discovery says that day; `--type=script --touches=data-theme` names `d-pace/assets/js/site-header.js` and nothing else, checked against `grep -rl data-theme` over d-pace's unminified JS at that moment, never against this brief; `--type=script --format=json` for `sassy` reports `scope` only; the dashboard's Stack shows scripts with the column and the filter, and its footer is still free of theme scripts.
- `tests/manual.md` gains the script rows and has been walked.

## Out of bounds

- Phase 9's cross-reference: no reading of CSS for `[data-*]` selectors, no dead-scope or unstyled-switch findings.
- Any JS parsing beyond markers, any attempt to tell a read from a write beyond the assignment shape, any semantics.
- Compiling, checking or writing anything for a script.
- `docs/style-stack-plan.md` is the spec of record; changing it means saying so in the commit message.

## Handoff

End with: what was built, what the live verification showed including the grep it was checked against, anything that surprised you or contradicted the plan, and nothing else started.

Update `AGENTS.md`, `README.md` and `docs/upgrading-to-3.0.md` as part of landing. README's WP-CLI section gains `--type` and `--touches`; the upgrade notes carry `Style_Stack::all()` now including scripts, which anything iterating it and assuming styles will notice.
