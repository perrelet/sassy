# Builder brief: Phase 3, engine contract and diagnostics

One builder, one phase, then stop for review. The spec is [../style-stack-plan.md](../style-stack-plan.md): read §3 phase 3 for the work, §8 for the rules of engagement. This brief adds only what the plan leaves to the builder.

## Mission

An error is a string and warnings are an array of strings, so everything the engines know about a failure is thrown away before any surface sees it. On one live compile of `d-pace-editor` the current code reports **54 warnings for 6 actual diagnostics**, one of which is Dart telling us it withheld more. Give diagnostics a type, give the engine contract a shape that is not scssphp's option names, and render all of it through one formatter.

## Read before writing

1. `docs/style-stack-plan.md` §3 phase 3, §8
2. `include/engines/compiler-engine.interface.php` and both engines
3. `vendor/scssphp/scssphp/src/Logger/LoggerInterface.php` and `Deprecation.php`, which change how half this phase is built (see below)
4. `include/model/printer.class.php`: `build_compile_args()` and how `$result->info` becomes `$this->warnings`
5. Every consumer of `get_warnings()`: `Sassy::get_errors()`, `UI::admin_bar_menu()`, the CLI's `compile` and `watch`, `tests/test-source-maps.php`

## The work

Per the plan: `Diagnostic`, `Compile_Result` carrying `Diagnostic[]`, `Compile_Request` replacing the untyped args bag, `capabilities()` and `supports()` on the engine contract, and the canonical formatter.

Plus one item carried from phase 1, which described it but had nowhere to put it: **Sassy's own failures become diagnostics too.** `Printer::compile()` reports an absent source as `Source file not found: <path-or-url>` and appends `Import_Scanner` truncation as a string warning. All three become `source: 'sassy'` diagnostics: an absent local source is an `error` naming the path it looked at, an asset that maps nowhere is an `error` naming the URL (never formatted as though it were a path), and truncation is a `warning`. This is the only work in the phase that is not about the engines, and it is the reason `Diagnostic` carries a `source` field at all.

## Judgement calls, pre-made

**The two engines are not the same kind of source, and must not be made to be.** scssphp hands you structured data: `Compiler::setLogger()` takes a `LoggerInterface` whose `warn()` receives `(string $message, ?Deprecation $deprecation, ?FileSpan $span, ?Trace $trace)`. That is a `Diagnostic` already: `Deprecation` is a backed enum whose value is exactly the `code` field (`slash-div`, `mixed-decls`, `global-builtin`), and the span carries file, line and column. Dart hands you text on stderr and nothing else. So write a **parser for Dart only**, and a **logger for scssphp**. Do not normalise scssphp into text so one parser can serve both; that discards structure you are being given for free. `Diagnostic` is the common type, not a common code path.

This also settles the plan's note that `Scssphp_Engine` populates no warnings: it is not a missing feature, it is an unimplemented `LoggerInterface`.

**What the logger actually hands you, probed on 2.1.0 rather than read off the interface:**

| Case | `$deprecation` | `$span` | `$trace` |
|---|---|---|---|
| `@warn`, top level or in a mixin | null | **null** | present, one frame per level |
| `elseif` deprecation | `elseif` | present, file plus line and column | **null** |
| `global-builtin`, `slash-div`, `moz-document` | never fires | | |

Three things follow, and each will cost you an hour if you meet it by surprise:

- **`span` and `trace` are alternatives, not a pair.** A `@warn` gives you a trace and no span, a deprecation gives you a span and no trace. Take file, line and column from whichever arrived. This mirrors Dart exactly, where `@warn` has a trace and no frame.
- **Trace source URLs are percent-encoded** (`file%3A///var/www/...`) while the span's are not. Decode before the path reaches a `Diagnostic`, or you emit something no editor will open, which §1 rules out.
- **scssphp implements a small subset of Dart's deprecations.** Of the four tried, only `elseif` fired. So the same source yields different deprecation sets on different engines, and `--strict=all` is engine-dependent by nature. That is a consequence of `capabilities()` working, not a bug to reconcile, but say it in the docs or someone will file it.

**A logger that throws takes the compile with it.** `warn()` runs inside compilation, so an exception there surfaces as a compile failure with a message that has nothing to do with the stylesheet. Mine did exactly this by calling `(string) $trace`, which is not supported: the accessor is `Trace::getFormattedTrace()`. Keep the logger total, and let a diagnostic you cannot parse fall back to the raw message rather than raising.

**Dart hides diagnostics from you by default.** After a few repeats it prints `WARNING: N repetitive deprecation warnings omitted. Run in verbose mode to see all warnings.` and drops the rest. `--strict=all` is supposed to gate on deprecations, and a gate that cannot see its input is not a gate, so **pass `--verbose`** and take the volume. Expect the reference install's per-compile deprecation count to rise once nothing is withheld; that is the true number, and the plan's figure of 48 was measured through the cap.

**Carry `url` verbatim even when Dart is wrong.** A `global-builtin` deprecation cites `https://sass-lang.com/d/import`, which is the wrong page. Verified in isolation on sass 1.92.0. It is not yours to correct: the field records what the engine said.

**`frame` is optional and its absence is meaningful.** A `@warn` from a partial produces a message and a trace but no frame. A Sassy-source notice has neither. Only `message` and `severity` are always present.

**Dart's shapes, verified, so you need not re-derive them:**

```
DEPRECATION WARNING [import]: <message>          # [code] in brackets
<optional second message line>
                                                 # blank
More info and automated migrator: <url>          # optional
                                                 # blank
  ╷
1 │ @import 'p';                                  # frame, engine-drawn
  │         ^^^
  ╵
    e.scss 1:9  root stylesheet                   # trace, 4-space indent

WARNING: <message>                                # no code, no frame
    _p.scss 1:1  @import
    e.scss 1:9   root stylesheet
```

Errors exit 65 and print `Error: <message>` with a frame and trace, no code and no url. Blank lines separate diagnostics; the trace is the last indented block before one.

**Warning counts change meaning, visibly.** Anything printing "N warnings" counts lines today (54 where 6 are meant). Once it counts diagnostics the numbers drop sharply. That is the fix landing, not a regression, but say so in the commit or it reads like one.

**`sassy-src-map-options` goes with the args bag, and it reaches further than the engines.** `Build_Target::get_map_options()` exists because the old bag needed scssphp's key names. `Compile_Request` carries `map_path` and `map_url`; `Scssphp_Engine` derives `sourceMapBasepath` and `sourceMapRootpath` internally from `map_path`. Nothing on this install binds the filter.

Count the call sites before you start: `grep -rn 'sourceMap' include/` currently returns 18 hits across four files, and only five are in `Dart_Sass_Engine`. `Printer` reads `sourceMapWriteTo` twice (writing the map, and `has_src_map()`), and `UI` reads both `sourceMapURL` and `sourceMapWriteTo` to build the admin bar's map submenu. Those move to `Build_Target::get_map_path()` and `get_map_url()`, which already exist and already return the right values. `Build_Target` keeps owning the paths; it stops publishing them under scssphp's names.

**Indented syntax lands here, and the extension list is not the whole of it.** `sass` joins `Asset::COMPILABLE`, `is_compilable()` stays extension plus locality (it must not consult an engine: see plan §3 phase 3 for why), `Scssphp_Engine` declares the `sass` capability and passes `Syntax::SASS` to `compileString()`, and `Dart_Sass_Engine` does **not** declare it. An engine refusing a syntax it cannot take is a `Diagnostic` naming the file and the remedy, the same shape as `@use` under scssphp.

**`capabilities()` is not about indented syntax.** `.sass` entry files are closed as won't-do (plan §6), so do not add `sass` to `Asset::COMPILABLE` and do not touch `Build_Target::get_name()`, whose hard-coded `.scss` is correct while `scss` is the only compilable extension. What `capabilities()` carries is `modules` (the scssphp `@use` refusal below), plus `source_maps` and `compressed`.

## Strangler order

The plugin works at every commit (§8). Diagnostics are additive until the last step, so build them alongside:

1. **`Diagnostic` and the formatter.** Pure, no callers, fully testable against the shapes above.
2. **`Compile_Result` carries `Diagnostic[]` alongside the existing `info`.** Both populated, nothing switched.
3. **Engines fill it:** the scssphp logger, then the Dart parser. `info` still populated.
4. **Surfaces move to the formatter,** then `info` and the string arrays go.
5. **`Compile_Request`,** replacing the args bag and `sassy-src-map-options`.
6. **`capabilities()` and `supports()`.**

Steps 5 and 6 are independent of 1 to 4 and of each other. If you run short, stop cleanly after 4 and say so; a phase that lands diagnostics properly and defers the request object is worth more than one that half-lands both.

## Definition of done

Every acceptance item in plan §3 phase 3, plus:

- Sassy-source diagnostics are covered too: a handle whose source file is missing, and one whose URL maps nowhere, each produce an `error` with `source: 'sassy'` and the right subject. `tests/test-style-stack.php` already asserts the two resolution outcomes, so extend rather than duplicate.
- `tests/test-diagnostics.php`: each Dart shape above parses into the right fields, including a deprecation with a `[code]` and a url, a `@warn` with a trace and no frame, and an error with neither code nor url; unparseable output survives as a `warning` carrying the raw text; the scssphp logger produces the same `Diagnostic` shape from a real `@warn` and a real deprecation; the formatter renders the plan's canonical example byte for byte.
- No scssphp-shaped key anywhere in `Dart_Sass_Engine`, and none in `Compile_Request`. `grep -rn 'sourceMap' include/` returns hits only inside `Scssphp_Engine`.
- The phase 2 acceptance this phase completes: the same failure renders identically in the CLI, the footer panel and the AJAX payload, chrome aside.
- `php tests/run.php` green, and green against a pre-phase-3 checkout for everything except the new file.
- Live: compile `d-pace-editor`, which currently reports 54 warnings for 6 diagnostics, and confirm the count is 10 with `--verbose` withholding nothing. Then `wp sassy status` for capabilities, and a `@use` under scssphp refused with a message naming the file and the remedy.

## Out of bounds

- Phase 4's territory: no extension registry, no provider discovery, no touching `include/integrations/`.
- No `--strict` or `wp sassy check`: that is phase 5, and it consumes this schema rather than defining it.
- No `.sass` support: closed as won't-do, and reopening it is not a builder's call.
- No changes to the admin bar or the panel beyond rendering through the formatter. Phase 6 owns those surfaces.
- No VLQ or source map surgery.
- `docs/style-stack-plan.md` is the spec of record; changing it means saying so in the commit message.

## Handoff

End with: what was built, what the live verification showed, anything that surprised you or contradicted the plan, and nothing else started.

Update `AGENTS.md` and `docs/upgrading-to-3.0.md` as part of landing. The upgrade notes need the `get_warnings()` shape change, the `sassy-src-map-options` removal and the new `Diagnostic` type; AGENTS.md needs the engine contract, the diagnostics section and the banner.
