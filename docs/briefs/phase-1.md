# Builder brief — Phase 1: `Asset` and `Style_Stack`

One builder, one phase, then stop for review. The spec is
[../style-stack-plan.md](../style-stack-plan.md) — read §1–2 for the frame, §3 phase 1 for the
work, §8 for the rules of engagement. This brief adds only what the plan leaves to the builder.

## Mission

Sassy currently models 1 of 329 registered stylesheets and discards WordPress's own
handle-dependency graph. Build the spine that models all of them: an `Asset` value object and a
`Style_Stack` registry, and move every existing caller onto them.

## Read before writing

1. `docs/style-stack-plan.md` §1, §2, §3 phase 1, §8
2. `AGENTS.md` — the 2.1 reality being refactored (note its banner)
3. `include/sassy.class.php` — `get_scss_styles()` (the thing you replace) and `compile_all()`
   (a caller)
4. `include/cli/sassy-cli-command.class.php` — `discover()` and every command that calls it
5. `include/model/scss-compiler.class.php` — `get_src_path()` (see "Path resolution" below)
6. `tests/bootstrap.php` and one `tests/test-*.php` — the test idiom to follow

## The work

- `include/model/asset.class.php` — `Asset` per the plan's field list.
- `include/model/style-stack.class.php` — `Style_Stack` per the plan's method list.
  `dependents_of()` may return `[]` with a note; phase 5 fills it in.
- Register both in `Sassy::load_models()` (order matters — before anything that uses them).
- `sassy-style-queues` filter, default `[wp_styles()]`.
- Migrate the callers: `Sassy::compile_all()`, CLI `discover()`. Delete
  `Sassy::get_scss_styles()`.
- `wp sassy list` now lists **every** discovered handle, with a `--compilable` flag to narrow.
  This is a deliberate behavioural change, not a regression. Columns gain `type` and WP `deps`.

## Path resolution — the one judgement call, pre-made

`Asset::source_path` needs URL→filesystem resolution. That logic currently lives in
`SCSS_Compiler::get_src_path()` (scheme-mismatch handling, multisite, `sassy-src-path` filter).
**Extract it; do not duplicate it.** Move the resolution into `Asset` (or a small shared static
it owns) and have `SCSS_Compiler::get_src_path()` delegate to it. Two implementations of
URL→path is how the 2.x scheme bugs happened. The `sassy-src-path` filter keeps working from
both call sites.

## Definition of done

Every acceptance item in plan §3 phase 1, plus:

- `tests/test-style-stack.php`: discovery per context, the multi-queue filter, `--compilable`
  narrowing, `Asset` field resolution (local, remote, `src === false` dependency-only handles),
  and path resolution delegating rather than duplicating (assert `SCSS_Compiler::get_src_path()`
  and `Asset::source_path` agree for the same URL).
- `php tests/run.php` fully green.
- Live verification recorded in the handoff note: `wp sassy list --hooks=all --format=json`
  returns every handle with deps populated; `wp sassy compile --hooks=all` still compiles the
  three d-pace handles; `wp sassy status` unchanged.

## Out of bounds

- Anything in phase 2's territory: do not split `SCSS_Compiler`, do not touch `should_compile()`,
  transients, engines, or the build pipeline beyond the `get_src_path()` delegation above.
- No d-pace edits — phase 1 requires none.
- No changes to `docs/style-stack-plan.md` without saying so in the commit message.

## Handoff

End with: what was built, what the live verification showed, anything that surprised you or
contradicted the plan, and nothing else started.
