# Builder brief — Phase 2: One service, thin surfaces

One builder, one phase, then stop for review. The spec is
[../style-stack-plan.md](../style-stack-plan.md) — read §2 for the frame, §3 phase 2 for the
work, §8 for the rules of engagement. This brief adds only what the plan leaves to the builder.

## Mission

`SCSS_Compiler` is 619 lines holding five responsibilities, and every surface reaches into it for
a different one — the admin bar alone uses ten of its accessors. Split it into `Printer`, `Build_Target`, `Compile_Cache` and `Variable_Resolver`,
and leave the surfaces formatting results they no longer compute.

This is the largest refactor in the plan, on the class every page request passes through, in a
directory that is the live plugin on staging.d-pace.com. Read the strangler order below before
writing anything.

## Read before writing

1. `docs/style-stack-plan.md` §2, §3 phase 2, §8
2. `AGENTS.md` — the compile pipeline, caching transients, dependency tracking
3. `include/model/scss-compiler.class.php` — all of it, once, before moving any of it
4. `include/view/ui.class.php` and `assets/js/sassy.js` — the admin bar reads ten different
   accessors off the compiler and the JS keys DOM nodes on `meta.index`
5. `include/model/asset.class.php` — phase 1's value object, and the fourth filter argument

## Disposition

Every member of `SCSS_Compiler` has a home. Nothing is left behind, and nothing is duplicated.

| Member | Goes to | Note |
|---|---|---|
| `compile()`, `init()`, `prepare()` | `Printer` | The orchestration that remains |
| `get_engine()`, `get_engine_class()`, `build_compile_args()` | `Printer` | `Compile_Request` is phase 3; keep the array bag for now |
| `error()`, `has_error()`, `get_error()`, `get_warnings()`, `has_compiled()`, `get_compile_time()`, `has_src_map()` | `Printer` | Result state. `Diagnostic` replaces it in phase 3 |
| `get_build_directory/path/url/name/file()`, `get_src_map_options()`, `get_src_url()` | `Build_Target` | Pure path math, every `sassy-build-*` |
| `should_compile()`, `is_current()`, `get_last_compile_time()`, the transient writes in `compile()`, the `delete_transient` in `ensure_build_directory()` | `Compile_Cache` | Both transient keys, and every rule that reads them |
| `get_variables()`, `normalize_url_schemes()`, `prepend_variables()`, the variable signature | `Variable_Resolver` | |
| `get_src_path()`, `get_asset()` | delete | `Asset` already owns resolution; callers take the `Asset` |
| `get_import_paths()` | `Printer` | Load paths become an extension point in phase 4, not here |
| `ensure_build_directory()` | `Printer`, minus its cache write | It decides nothing about currency |
| `temp_file()` | `Dart_Sass_Engine` / `Lightning_CSS_Postprocessor` | See "backends must stop calling the domain" |
| `get_index()`, `static $count` | `Sassy` | See "the index is a surface concern" |
| `get_src()`, `get_handle()`, `get_style()` | `Printer`, reading its `Asset` | `get_style()` keeps the `sassy-style` normalization |

## Judgement calls, pre-made

**Backends must stop calling the domain.** `Dart_Sass_Engine` calls
`SCSS_Compiler::prepend_variables()` and `Lightning_CSS_Postprocessor` calls
`SCSS_Compiler::temp_file()` twice — a backend reaching up into the layer that drives it. Move
`prepend_variables()` to `Variable_Resolver` and have the engine call it there; give each of the
two backends its own private temp-file helper rather than sharing one through a class neither of
them owns. `Printer` must not appear in `include/engines/` at all when you are done.

**The index is a surface concern.** `get_index()` is a static instance counter used to key
`Sassy::$errors` and, through `meta.index`, the admin bar DOM in `assets/js/sassy.js`. It is not
domain state and `Printer` must not carry it — but **`meta.index` has to keep working**, because
phase 6 is what rewrites that JS and rekeys errors by handle. So: `Sassy` assigns the index as it
collects printers, and keeps emitting it. Live Compile is verified by hand at the end of this
phase; a broken admin bar is a broken phase.

**The surfaces read the transients too, and they must stop.** The cache keys are not confined to
`SCSS_Compiler` today: the CLI reads `sassy-filemtimes-` directly in `watch`, `list` and `deps`,
deletes both keys in `clear`, and `UI` deletes them with a raw `$wpdb` `LIKE` query. So
`Compile_Cache` needs a public surface wide enough to retire all six sites — reading the recorded
`Import_Graph` for a handle, forgetting one handle, and forgetting everything — or the headline
acceptance below cannot pass. Design that API from these call sites, not from `Printer`'s needs
alone.

**`Compile_Cache` calls `Variable_Resolver`, not the other way round.** Staleness has two inputs
— the import graph and the variable signature — and `is_current(Asset)` cannot take the signature
as a parameter without handing the rule back to the caller it was extracted from. `Compile_Cache`
owns the decision and asks `Variable_Resolver` for the hash.

**The fourth filter argument becomes the `Asset`** (plan §6). Thirteen filters live in this class
and every one of them receives `$this` as its last argument; `sassy-engine` receives
`($engine, $this)`. All become the `Asset`. Arity does not
change. `Lightning_CSS_Postprocessor::filter()` takes it as its fourth parameter and passes it to
`sassy-lightning-css` — update both, and its docblock, which names `SCSS_Compiler`.

**`get_src_url()` is misnamed and stays misnamed until phase 3.** It returns the *source map*
URL, and `compile_all()` reports it as `src_url`. Name it `get_map_url()` on `Build_Target`, but
keep the `src_url` key in the AJAX payload — the JS reads it. Phase 3 owns the payload.

**Leave `sassy-src-map-options` alone.** Phase 3 removes it. Move `get_src_map_options()` into
`Build_Target` unchanged.

## Strangler order

The plugin works at every commit (§8). That is not advice here; a broken commit is a broken
staging site. Build outward-in, one commit per class:

1. **`Build_Target`** — pure functions of `Asset` plus filters, no state to migrate. Build it,
   have `SCSS_Compiler` delegate every `get_build_*` to it, commit. The suite should not move.
2. **`Variable_Resolver`** — same shape. Delegate `get_variables()`, repoint the engine's
   `prepend_variables()` call, commit.
3. **`Compile_Cache`** — the risky one, because it owns both transient keys and a wrong answer
   here either serves stale CSS or recompiles on every request. Delegate `should_compile()` and
   `is_current()`, commit, and check `wp sassy list` still reports `current` for all three d-pace
   handles rather than `stale`.
4. **`Printer`** — rename `SCSS_Compiler` last, once it is a thin orchestrator. Move the callers
   (`Sassy::style_loader_src()`, `Sassy::compile_all()`, the CLI's five call sites, `UI`),
   then delete the old name.

Do not start step 4 before steps 1–3 are committed and green. A half-renamed class with three
half-extracted responsibilities is not recoverable by review.

## Definition of done

Every acceptance item in plan §3 phase 2, plus:

- **No transient key outside `Compile_Cache`.**
  `grep -rn "sassy-filemtimes-\|sassy-vars-sig-" include/` returns hits in exactly one file. It
  currently returns thirteen hits across three — seven in `SCSS_Compiler`, five in the CLI, one
  in `UI`. This is the phase's headline acceptance and it is mechanically checkable, so check it
  rather than asserting it.
- **No `wp_styles()`, no URL→path resolution, no `get_build_*` outside their owners.** Same test,
  same rigour.
- `tests/test-printer.php`: `Build_Target` path math including the `sassy-build-*` filters and the
  multisite build directory; `Compile_Cache` currency across a partial edit, a variable change and
  a missing build file; `Variable_Resolver` defaults, map conversion and scheme normalization;
  and that a failed compile is still not recorded as current.
- The 2.1 suite passes unchanged except for renames. If a test needs more than a rename, say so
  in the commit message — it means a behaviour moved that was not supposed to.
- `php tests/run.php` green, and green against a pre-phase-2 checkout for everything except the
  new file.
- Live: `wp sassy list --hooks=all`, `wp sassy compile --hooks=all`, `wp sassy deps`,
  `wp sassy vars` and `wp sassy status` all unchanged from phase 1 output. Then load a page as a
  logged-in admin and use **Live Compile** — the admin bar, the state badges and the error panel
  are not covered by the suite and this is the only thing that exercises them.

## Out of bounds

- Phase 3's territory: no `Compile_Request`, no `Diagnostic`, no `capabilities()`, no engine
  contract changes beyond removing the two upward calls named above. Warnings stay
  `string[]`.
- No `.sass` work — it is phase 3, and the plan records why.
- No changes to what the AJAX payload contains. Phase 6 owns that surface.
- No d-pace edits. The fourth-argument switch needs none: all four of its callbacks ignore it.
- `docs/style-stack-plan.md` is the spec of record; changing it means saying so in the commit
  message.

## Handoff

End with: what was built, what the live verification showed — including the Live Compile check —
anything that surprised you or contradicted the plan, and nothing else started.

Update `AGENTS.md` as part of landing: the banner marks which phases are in, and the compile
pipeline, caching transients and architectural decisions sections all describe classes this phase
replaces.
