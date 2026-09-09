# Builder brief: Phase 7, the paintbrush, tier 2

One builder, one run, then stop for review. The spec is [../style-stack-plan.md](../style-stack-plan.md): read §3 phase 7's tier 2 paragraph and its sharp edges, §8 for the rules of engagement. Tier 1 landed as 3.2.0 and was verified in the browser; this run adds the write and nothing else.

## Mission

Push to source. A captured patch is POSTed; the server applies each change only where it can do so exactly, and refuses the rest back into the copy path. Then the sheet recompiles and the browser repaints, so the loop from styles pane to `.scss` closes without leaving the inspector. This is the only thing in Sassy that writes to a file a person authored, which is why every rule below is a refusal.

## Read before writing

1. `docs/style-stack-plan.md` §3 phase 7, tier 2 and "Sharp edges, named".
2. The paintbrush section of `assets/js/sassy.js`: what a change carries (`handle`, `index`, `selector`, `prop`, `from`, `to`, `location`) and what the panel already renders.
3. `include/model/import-graph.class.php`: `deps` are the only files this run may write, and `stamp_matches()` is how it knows the map still describes them.
4. `include/model/policy.class.php`: `can_write_source()` has waited since phase 6 for this.
5. `Sassy::compile_all()`, the endpoint whose contract this one copies.

## Judgement calls, pre-made

**Two gates, checked in this order, then the nonce.** `Policy::active()`, then `Policy::can_write_source()`, then `wp_verify_nonce()`. `sassy-write-source` defaults to false and is never implied by `sassy-dev`; a site opts in by binding it. On staging bind it in d-pace's `sassy.integration.php` to `current_user_can('dev')`, the same shape as `sassy-dev`, which is a §4 row this run adds. No `nopriv`.

**The endpoint is `wp_ajax_sassy_write`, POST only, and it takes what tier 1 captured.** The JS sends `changes` as one JSON-encoded form field, admin-ajax's native shape: for each change, `handle`, `source` (the map's own source string, which tier 1 must now keep on the change beside the `file` it displays), `line`, `prop`, `from`, `to`. The server refuses any request that is not a POST or whose `changes` do not decode to that shape.

**One resolution rule, in the model, for both engines.** A Dart map's sources are paths relative to the map (`../plugins/d-pace/scss/_x.scss`); a scssphp map's are URLs (`http://site/wp-content/themes/t/_x.scss`), measured, not assumed. `Admin_Page::resolve_file()` moves to `Import_Graph::find($cited)`: reduce a URL to its path, then match by path suffix against `deps`, comparing `realpath()` on both sides as `dependents_of()` does because recorded paths are not always canonical, and answer only when exactly one dependency matches. The page delegates to it, and the endpoint resolves `source` through `Compile_Cache::get_graph($handle)->find()`. A file that is not a recorded dependency of that handle cannot be written, whatever the request says; that is the "inside a recorded import graph" rule made concrete. Tier 1's display gains the same reduction: strip the page's origin as well as the leading `../`, so a scssphp install reads `wp-content/themes/t/_x.scss:3` rather than a URL.

**The map is only trusted while the source is as it was compiled.** Before touching a file, `Import_Graph::stamp_matches($path, $graph->deps[$path])` must hold. A source edited since the last compile has lines the map does not describe, so the change is refused with "changed since the last compile; compile first". `wp sassy watch` users will rarely see this; everyone else will, and it is right.

**Only a change whose old value is literally on the mapped line is applied.** Read the file, take line `N` (1-based from the map), and find `prop:` on it with a property boundary before it (start of line, whitespace, `{` or `;`), so `color:` never matches inside `background-color:`. Require exactly one such occurrence, and require the text between that colon and the next `;` on the line, trimmed, to equal `from`. Then replace that text with `to` and nothing else. A compact rule like `.a { color: red; }` passes; so does a line holding two declarations of different properties. Anything else refuses: `gap: $gap` when the served value was `4px`, `color: white` when the CSSOM said `rgb(255, 255, 255)`, a value continued on the next line, no `;` on the line. Refused changes come back marked for the copy path with the source line quoted, so the person sees why. **Textual replace only.** No SCSS parsing, no expression evaluation, no reformatting beyond the value. Note that `to` is the CSSOM's serialisation of what was typed: `#FF0000` arrives and is written as `rgb(255, 0, 0)`, which is valid SCSS and is what the person will see in the file.

**Additions are refused; removals are applied only when the line is the declaration alone.** A `+ prop: value` has no old value to match and no exact place to go; it stays copy-only. A `- prop: value` deletes the line when, trimmed, the line is exactly `prop: value;`, else refuses.

**Writes are in place, not renamed over.** On the reference install the sources are `admin:www-data` and the web server runs as `www-data`, so a temp-and-rename write would hand every pushed file to `www-data`, and it would move the directory's mtime, which the import graph watches for every handle compiled from that directory. `file_put_contents()` with `LOCK_EX` keeps the owner, the mode and the inode, and moves only the file. Each change is applied or refused on its own and the response says which; there is no rollback. Within a file, apply changes sorted by line descending so a removal cannot shift a later line.

**After a write, the file is "changed since the last compile" until it compiles.** That is the previous rule doing its job. The Live Compile below re-records the graph on success; if the pushed value does not compile, the file keeps refusing further pushes until someone fixes it and compiles, and the panel shows the compile error. Say so in the refusal.

**The loop closes with a Live Compile, not a watcher.** After a response with at least one write, the JS runs `liveCompile()` for the page's context; the dependency check sees the changed partial, recompiles, the sheet reloads, and the reload re-baselines the paintbrush. `wp sassy watch` still works alongside and is what the plan describes; it is not required.

**The panel shows the result in the patch's own format**, one line per change prefixed `✔ written` or `✗ refused: <reason>`, followed by the refused changes as a copyable patch so nothing is lost. The **Push** button appears in the panel header only when `sass_params.write` is true (the server's `can_write_source()` at enqueue), and only after a capture with at least one applicable change.

**A `Source_Writer` model does the work, and it is where the tests are.** `include/model/source-writer.class.php`, `Source_Writer::apply(array $changes) : array`, one result per change with `written` or `reason`. It takes an `Import_Graph` and a map directory, and knows nothing about HTTP. The endpoint validates the shape of the request, hands it over, and renders the result.

**The version moves to 3.3.0, last.**

## Strangler order

1. **`Source_Writer`** with every refusal, tested against fixtures on disk.
2. **The endpoint**, with the two gates and the nonce. The harness needs `wp_send_json_success()`, `wp_send_json_error()` and `wp_die()` stubbed, the last as an exception the test catches; phase 6's brief deferred this and it is due.
3. **Tier 1 keeps `source` on each change**, and the JS gains Push, the POST, the result rendering and the compile after.
4. **The d-pace binding**, the §4 row, `tests/manual.md`, walked, then the version.

## Definition of done

Every acceptance item in plan §3 phase 7 for tier 2, plus:

- `tests/test-source-writer.php`: a change lands on the mapped line and nowhere else; the file's owner, mode and inode survive; a compact `.a { color: red; }` line applies; `color:` on a `background-color:` line refuses; a `$variable`-backed line refuses; a served value that differs from the source text refuses; a file outside the graph refuses; a file changed since the compile refuses; an addition refuses; a removal of a lone declaration deletes the line and a removal of a shared line refuses; two changes in one file apply bottom-up; a refusal names the line it looked at.
- `Import_Graph::find()` tested where `Admin_Page::resolve_file()` was, plus a URL source reducing to its path and a non-canonical recorded path still matching.
- `tests/test-error-panel.php` or a new file: the endpoint answers 403 with `sassy-dev` off, 403 with `sassy-write-source` off, 401 with a bad nonce, and applies with all three passing.
- `tests/js/harness.cjs`: Push is absent without `sass_params.write`; present after a capture with a change; it POSTs the changes with `source`; a response renders `✔` and `✗` lines; a write triggers a compile; a URL source displays as a path.
- Live, on staging as a dev with the binding in place: paint the light-theme header `background` red, Capture, Push. `_site-header.scss:50` reads `background: red;`, the page repaints red, and `git diff` in d-pace shows that one line. Paint a `$variable`-backed declaration and Push: refused, quoted, still copyable. Edit a partial in your editor without compiling, Push something in it: refused, "changed since the last compile". Revert the file afterwards; d-pace's tree has uncommitted work that is not ours.
- `tests/manual.md` gains a Push section and has been walked. Say what you saw.

## Out of bounds

- Additions, multi-line values, shorthand expansion, anything needing SCSS parsing.
- Writing anywhere but a recorded dependency of the handle the change came from.
- Phase 8.
- `docs/style-stack-plan.md` is the spec of record; changing it means saying so in the commit message.

## Handoff

End with: what was built, what the live verification showed including the `git diff` in d-pace and what you saw repaint, anything that surprised you or contradicted the plan, and nothing else started.

Update `AGENTS.md`, `README.md`, `docs/upgrading-to-3.0.md` and plan §4 as part of landing. README's inspector section gains Push and the refusal rules in one paragraph; the upgrade notes carry the new endpoint and the new gate binding a site has to make.
