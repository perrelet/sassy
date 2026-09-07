# Builder brief: Phase 5, reverse dependencies and `check`

One builder, one phase, then stop for review. The spec is [../style-stack-plan.md](../style-stack-plan.md): read §3 phase 5 for the work, §8 for the rules of engagement. This brief adds only what the plan leaves to the builder.

## Mission

Four phases have built a model and nothing yet asks it a question. `wp sassy check` is the first thing anyone invokes for its own sake: one call, one exit code, over every style on the install. Alongside it, `dependents_of()` finally reads the import graph backwards, which it has never done despite recording the data since 2.x.

This is the smallest phase by code and the largest by leverage. Almost everything it needs exists: `Style_Stack` discovers, `Compile_Cache` decides currency, `Diagnostic` carries failures, `Import_Graph` holds the edges. Resist adding machinery.

## Read before writing

1. `docs/style-stack-plan.md` §3 phase 5, §8
2. `include/model/import-graph.class.php` in full: it is 91 lines and you need all of it
3. `include/model/compile-cache.class.php`, particularly `get_graph()` and `is_current()`
4. The CLI's `deps` command, which gains `--file`, and `list`, whose state vocabulary `check` must not duplicate
5. `include/model/diagnostic.class.php`: `check` reports through it, it does not invent its own strings

## Judgement calls, pre-made

**`dependents_of()` reads every recorded graph, and that is acceptable here.** There is no reverse index; answering "which handles import this file" means loading each compilable handle's `Import_Graph` and looking for the path. Three transients on this install, and correct. **Do not build a reverse index**: it would be a second copy of the same data with its own staleness problem, which is the class of bug phase 2 spent its whole length removing. If it ever matters, the fix is caching the inversion for one request, not persisting it.

Match paths with `realpath()` on both sides. A partial cited as `/a/b/../c/_x.scss` and recorded as `/a/c/_x.scss` is the same file, and an agent passing a path from its own working directory will hit this on the first try.

**An orphan is a warning, not a failure, and the plan now says so** (§3 phase 5, changed on the strength of the measurement below). `check` fails on a stale handle, a compile error and a truncated graph. An orphan fails only under `--strict`.

**Scope orphan detection or it will lie on its first run.** Measured on staging before this brief was written, a naive implementation reports:

```
unclaimed: .sassy-tmp, test.css, test.css.map
```

`test.css` is a real orphan and the reason to have the feature. `.sassy-tmp` is the Dart engine's own temp directory, which Sassy creates itself. So: skip dotfiles and directories, and only consider files matching what `Build_Target::get_name()` produces plus a `.map` suffix. A handle enqueued only on some template is discovered by no hook set at all and its output is indistinguishable from an abandoned one, which is why this stays a warning however well you scope it.

**`check` reports diagnostics, it does not print prose.** Every finding is a `Diagnostic` rendered through `Diagnostic::render_all()`, `source: 'sassy'` for the ones `check` raises itself (stale, orphaned) and the engine's own for compile failures. `--format=json` returns them through `to_array()`. This is what makes `check` output pasteable into the agent that caused the failure, which is the point of the phase.

**Do not invent a state vocabulary.** `wp sassy list` and `wp sassy deps` each have one and phase 6 unifies them; `check` is a third surface and must not add a fourth set of words. Ask `Compile_Cache`, report what it says.

**`--strict` is a flag and a value.** `--strict` promotes warnings, `--strict=all` adds deprecations, so `$assoc_args['strict']` is `true` or `'all'`. Both are valid WP-CLI and both must work.

**`--strict=all` means different things per engine, by design.** scssphp implements a fraction of Dart's deprecations, so the same source passes under one and fails under the other. Settled in plan §6; document it in the command's help text rather than treating it as a bug.

**Never compiled is not current.** A handle with no build file fails `check`, which means `check` on a fresh install fails until something has compiled. That is correct: its promise is "everything is current", and nothing is.

## Strangler order

Everything here is additive, so the order is about testability rather than safety:

1. **`Style_Stack::dependents_of()`**, replacing the `[]` phase 1 left with a note. Pure query, fully testable.
2. **`wp sassy deps --file=<path>`**, the surface over it. `deps <handle>` keeps working unchanged.
3. **`wp sassy check`**, composing the four conditions.
4. **`--strict` and `--strict=all`.**

## Definition of done

Every acceptance item in plan §3 phase 5, plus:

- `tests/test-check.php`: `dependents_of()` naming exactly the handles that import a shared partial and nothing else; a path given in non-canonical form still matching; `check` exiting non-zero for each of the three hard failures separately and zero when clean; an orphan producing a warning that does not fail until `--strict`; `--strict=all` failing on a deprecation that `--strict` alone passes.
- Orphan detection tested against a build directory containing a dotfile, a subdirectory and a genuine orphan.
- `php tests/run.php` green. Say in the handoff which pre-phase-5 failures are migration and which are the proof.
- Live: `wp sassy deps --file=/var/www/dpace/staging/public/wp-content/plugins/d-pace/scss/_fonts.scss` should name all three d-pace handles, which is verified ground truth: each one's recorded graph contains that path today. Confirm by touching it and watching all three go stale, rather than trusting the query against itself. `wp sassy check` exiting zero on a current install, then non-zero after touching a partial. And `check` finding `test.css`, which is a real orphan sitting in the build directory today.

## Out of bounds

- Phase 6's surfaces entirely: no admin page, no panel, no `Policy`. `check` is CLI only.
- The state vocabulary unification, which is phase 6's.
- No auto-removal of anything. `check` reports; `wp sassy clear` erases.
- No reverse index, no new persistence, no new transients.
- `docs/style-stack-plan.md` is the spec of record; changing it means saying so in the commit message.

## Handoff

End with: what was built, what the live verification showed, anything that surprised you or contradicted the plan, and nothing else started.

Update `AGENTS.md`, `README.md` and `docs/upgrading-to-3.0.md` as part of landing. `check` and `deps --file` are new user-facing commands, so README's WP-CLI section needs both; the upgrade notes need nothing unless something existing changed shape.
