# Builder brief: Phase 7, the paintbrush, tier 1

One builder, one run, then stop for review. The spec is [../style-stack-plan.md](../style-stack-plan.md): read §3 phase 7 for the work, including the spike result recorded under it, §8 for the rules of engagement. This brief adds only what the plan leaves to the builder. **Tier 2 is not in this run.** Jamie decided on 2026-09-09: tier 1 lands and stops at the boundary; the write endpoint gets its own run inside the phase.

## Mission

Capture and copy. Inspector edits mutate the page's CSSOM; Sassy snapshots its own sheets at load, diffs on Capture, maps each change through the source map to file and line, and renders the patch. The patch copies as text, which is the agent handoff: "here is what I painted, integrate it properly". Pure observation. Nothing is written anywhere.

Tier 0 comes with it, as a page of documentation: DevTools Workspaces plus the source maps that are now exact plus `wp sassy watch` plus Auto-reload is editing source in the browser, and costs nothing to ship but the paragraph.

## Read before writing

1. `docs/style-stack-plan.md` §3 phase 7 in full. The spike result at the end of the tier 1 section carries a lesson the code has to follow.
2. `tests/fixtures/paintbrush-spike.html`: the reference implementation of the diff, the text scan, the alignment and the mapping. Everything in it that worked on staging is the shape to keep; everything it got wrong is listed below. Read the whole thing, it is 144 lines.
3. `assets/js/sassy.js` and `tests/js/harness.cjs`. Nine of eleven findings in phase 6's review were in that file, and this run adds the largest piece of JS since.
4. `include/model/source-map.class.php` and the last section of `tests/test-source-maps.php`: what makes the columns true, and how a test decodes a map without trusting the code under test.
5. `Sassy::print_errors()` and `Sassy::compile_all()`: the panel's markup and the payload the JS already understands.

## Judgement calls, pre-made

**Capture is always present on the dev surface.** Jamie's call. 🖌️ Capture in the bar beside Force Compile, `window.sassy.capture()`, and a `sassy:captured` event on `document` with `detail: { patch, changes }`. The baseline snapshot is a few milliseconds over 1,700 rules; there is nothing to gate.

**The footer panel renders the patch.** Jamie asked whether the bar could, and it cannot: a patch is multi-line monospace with a Copy button, which is the panel's shape and not a dropdown's. The panel is hidden until something shows it, and only errors have so far. Generalise it: one `panel(title, text, kind)` in the JS, the header title following the content (`SCSS Error`, `Captured styles`), a **Copy** button beside Dismiss that yields the panel's text, and `kind` on the element as `data-kind` so the stylesheet can colour the header bar red for errors and the accent for captures. `#sassy-errors` keeps its id; renaming it breaks `tests/manual.md` and the JS for no gain.

**The JS learns which sheets are Sassy's from the panel's markup.** The printers exist only once the head has printed, after `wp_localize_script` has run, so `print_errors()` puts them on the panel element as `data-sassy-sheets`, a JSON object of handle to `{ href, map, source }` from `get_build_url()`, `get_map_url()` when `has_src_map()` and `get_src_path()`. Attribute-driven, no inline script, and the phase 6 acceptance about no dev-surface markup reaching a non-dev stays true because the panel is already gated.

**Snapshot when the sheets have loaded, and again after every reload.** A footer script runs before a head stylesheet has necessarily finished loading, and reading `cssRules` of an unloaded sheet throws or answers empty. Snapshot on the window's `load` event. After Live Compile or the poll swaps a `link`'s `href`, the browser loads a new sheet asynchronously; listen for that link's `load` and snapshot again, or the next capture diffs the new sheet against the old baseline and reports the whole compile as a paint.

**Diff declarations as the rule serialises them.** The spike compared longhands and got nine `background-*` rows with empty baselines for one `background: red`. Parse `rule.style.cssText` on both sides into property to value, with a scanner that honours quotes and parentheses (a `;` inside `url("a;b")` is not a separator), and diff those. The shorthand survives, and a `var()` value that the CSSOM keeps as pending substitution reads back as written.

**Map lazily, and only what changed.** Fetch a sheet's text and map on the first capture that finds a change in it, not at load; decode once and keep it. Fetch the text as bytes and decode with `ignoreBOM: true`, or the byte-order mark Dart writes in compressed mode is stripped and every column is off by one. Align text blocks to flattened CSSOM rules by order, exactly as the spike does, and if the counts differ, say so in the patch and give every change in that sheet the sheet's name and no line rather than a wrong one. A declaration maps to its own line where the text has `prop:` inside its rule's block, otherwise to the rule's.

**The patch is text first and markup second.** Compose it as the plan draws it:

```
plugins/d-pace/scss/components/_site-header.scss:33  .site-header
  background: var(--material-bg, var(--surface-dark)) → red
plugins/d-pace/scss/_atoms.scss:19  .btn
  + border-radius: 6px
```

File paths are the map's sources with leading `../` removed, so they read as paths under `wp-content/` and still name something a machine can open when joined to it. Copy yields this text and nothing else. The panel shows the same text in a `<pre>`, and the `sassy:captured` detail carries it as `patch` with the structured `changes` beside it.

**Sharp edges, as the plan names them.** A rule created in the inspector lives in a sheet with no `href` and no `ownerNode`; list each as `inspector-stylesheet  <selector>  (no source location)` with its declarations, and suggest the entry file of the first Sassy sheet whose rules already match that selector, or of the first Sassy sheet. An `element.style` edit maps to no stylesheet; compare `[style]` attributes against a baseline and list each as `element.style on <tag#id.class>  (no stylesheet)`. Both copy. Third-party sheets are ignored entirely: Sassy observes its own sheets, and the plan's design commitment is detection over interpretation.

**Where the map is unreachable, degrade.** Lightning CSS strips `sourceMappingURL`; with no map the capture still lists every change, with the sheet's name in place of a location and one line saying why, wording taken from the phase 4 notice. Never fail a capture for want of a map.

**Logging gains Capture diffs.** The fourth toggle the plan lists, arriving with the phase that produces what it logs: with it on, the patch also goes to `console.log` on capture. Same `localStorage` mechanism as the other three.

**One JS file still.** `sassy.js` grows by roughly two hundred lines. The plan's frontend idiom is one hand-rolled file with no build step, and a second file would need its own enqueue, version and test wiring for no gain at this size. Keep the paintbrush in its own clearly labelled section of the object.

**The version moves to 3.2.0, last.** Tier 2's run decides its own number.

## Strangler order

1. **The panel generalised**, with Copy, and `data-sassy-sheets` on it. Nothing observable changes for errors.
2. **Snapshot and diff**, behind `window.sassy.capture()`, mapping to nothing yet: the patch names the sheet and the selector.
3. **Mapping**: bytes, decoder, alignment, declaration offsets.
4. **The bar button, the event, the Logging toggle**, and the sharp edges.
5. **Tier 0's page** in README, then `tests/manual.md`, walked, then the version.

## Definition of done

Every acceptance item in plan §3 phase 7 for tier 1, plus:

- `tests/js/harness.cjs`: a declaration changed, added and removed between two fake sheets diffs to exactly those three lines; a shorthand stays a shorthand; a change maps through a hand-written map with three segments to the right file and line; a sheet with no map degrades to its name; an inspector-stylesheet rule and an `element.style` edit are listed as copy-only; `capture()` emits `sassy:captured` with `patch` equal to the panel's text; Copy on the panel writes that text to the clipboard stub.
- `tests/test-error-panel.php`: `print_errors()` carries `data-sassy-sheets` naming every printer's build URL, map URL and source path, and carries nothing when the gate is closed.
- The panel's existing behaviour is unchanged: an error still shows it, Dismiss still hides it, and the phase 6 manual rows still pass.
- Live, on staging: repeat the spike's edit (`background: red` on `.site-header`) and Capture. The panel reads `plugins/d-pace/scss/components/_site-header.scss:33  .site-header` over `background: var(--material-bg, var(--surface-dark)) → red`, one line, not nine. Copy pastes exactly that. A rule inside `@media` maps to its own line. A new rule in the inspector lists as copy-only with a suggested file. Console is clean.
- `tests/manual.md` gains a Capture section and has been walked. Say what you saw.

## Out of bounds

- Tier 2 entirely: no endpoint, no write, no use of `sassy-write-source` beyond what `Policy` already carries.
- No SCSS parsing, no attempt to rewrite `$variable`-backed values. Tier 1 reports served values; matching them to source text is tier 2's problem.
- Phase 8's toggle and profiler.
- No new dependencies, no second JS file, no build step.
- `docs/style-stack-plan.md` is the spec of record; changing it means saying so in the commit message.

## Handoff

End with: what was built, what the live verification showed including what you saw in the browser and pasted from the clipboard, anything that surprised you or contradicted the plan, and nothing else started.

Update `AGENTS.md`, `README.md` and `docs/upgrading-to-3.0.md` as part of landing. README gains tier 0's page and the Capture button; the upgrade notes carry the panel's new attribute and the new event; AGENTS.md's dev surface section gains the paintbrush.
