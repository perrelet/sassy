# Sassy 3.0 — The Style Stack

Architecture plan. Written to be built from, including by agents. **This document is the spec
of record for the `style-stack` branch** — see §8 for how to change it.

---

## 1. Premise

Sassy was a shim: intercept a `.scss` URL, compile it, hand back a `.css` URL. Version 2.x grew
an engine abstraction, a dependency graph, a CLI and a post-processing pipeline. The name no
longer describes the product.

Two measurements from the reference install decide the direction.

```
registered styles: 329, of which Sassy models:  1
styles declaring WP handle deps: 47, of which Sassy models:  0

Sassy-injected SCSS variables used across 17,113 lines of project SCSS:  0
```

The PHP→SCSS variable bridge — the thing the whole integration architecture exists to serve — is
unused by the project that uses Sassy most heavily. That project solved dynamic values a better
way: tokens authored as CSS custom properties, read *into* PHP, with variation by cascade scope
at render time.

Unused, not unsupported. The bridge is a live API — Lattice's `Design_System::add_variables()`
takes an `scss` flag defaulting to *true*, and Lattice injects two variables of its own — it
simply carries nothing the SCSS reads. The measured variable set on the reference install is
five: three Sassy defaults plus those two. So `sassy-variables` stays first-class in phase 4;
what dies is the integration architecture built to feed it.

Meanwhile Sassy discards 99.7% of what it can see.

**The product is not an SCSS compiler. It is a queryable model of everything that styles the
page** — what is enqueued, where it came from, what it depends on, whether it is current, and
what can change it at runtime. Compilation is one capability of that model.

Nothing else can build this model. A Node build does not know WordPress's enqueue graph. WordPress
does not know a Sass import tree. Only the intersection does, and that intersection is the moat.

### Design commitments

- **Sassy compiles CSS. Sassy observes JS.** Deliberately asymmetric. Detection, never
  interpretation.
- **Over-inclusion is safe, under-inclusion is not.** A spurious dependency costs one recompile.
  A missing one serves stale CSS.
- **Every string the tool emits names something a machine can open.** Diagnostics citing a temp
  path are a correctness bug — an agent will try to edit that file.
- **One diagnostic schema, four renderings.** CLI, browser console, on-page panel and clipboard
  all render the same structure. No surface invents its own.
- **Machine-readable by default.** `--format=json` on everything returning data, stable schemas,
  meaningful exit codes.
- **Config is code.** Constants and filters. The admin page is a dashboard, never a settings form.
- **Production is a context, not an exception.** The team codes in production. Gates are about
  *who*, never *where*; environment is just one thing a policy filter may consult.
- **No framework architecture.** No DI container, no service locator, no event layer over
  WordPress hooks, no deep inheritance. This boots inside someone else's request.

### Version

Breaking: renamed classes, changed engine contract, changed filter surface, integrations removed.
**3.0.0.** Branch: `style-stack`.

**3.0.0 ships phases 1–6.** Phase 6b (the admin page), 7 and 8 land as 3.x minors; phase 9 is
unversioned.

Two release preconditions, named here because neither belongs to a phase's acceptance and both
were drifting unowned:

- **The version constant is bumped by phase 6, not before.** `SASSY_VERSION` and the plugin
  header stay at `2.1.0` for phases 1–5 and move together — the updater compares the constant, so
  a builder bumping it early on a branch that has not shipped offers a half-built 3.0 to anything
  polling. Phases 1–5 leave it alone deliberately; that is not an oversight to fix.
- **The digitalis.ca update JSON is version-fenced before 3.0 publishes.** Wild consumers on 1.x
  poll it and must not be offered a breaking auto-update. This lives outside this repository, so
  no builder will trip over it and no acceptance criterion can catch it — **Jamie's, and the one
  item here that can do damage off this machine.**

---

## 2. Target architecture

```
                    ┌────────────────────────────────────────────────┐
  surfaces          │ style_loader_src  AJAX  WP-CLI  admin bar/page │   thin adapters, no policy
                    └────────────────────┬───────────────────────────┘
                                         │
                    ┌────────────────────▼───────────────────────────┐
  domain            │  Style_Stack        discovery + queries        │
                    │  Asset              one enqueued thing         │
                    │  Printer            produce one asset          │
                    │  Compile_Cache      is it current?             │
                    │  Build_Target       where output goes          │
                    │  Variable_Resolver  what values apply          │
                    │  Diagnostic         one thing to report        │
                    │  Policy             is the dev surface active? │
                    └────────────────────┬───────────────────────────┘
                                         │
                    ┌────────────────────▼───────────────────────────┐
  backends          │  Compiler_Engine    scssphp | dart-sass        │
                    │  Import_Scanner     SCSS dependency walk       │
                    │  Style_Surface      JS style-mutation profile  │
                    └────────────────────────────────────────────────┘
```

The rule that makes it hold: **surfaces contain no policy**. Every inconsistency found in 2.x —
divergent error handling, `wp sassy list` inventing its own staleness rule, `compile_all()`
building metadata `watch` re-derived — was a surface that grew policy because there was nothing
to delegate to.

**A vocabulary is policy.** Moving the staleness rule into `Compile_Cache` in phase 2 left three
surfaces still naming its answers for themselves: the admin bar says `error` / `warning` /
`compiled` / `cache`, `wp sassy list` says `no source` / `not built` / `current` / `stale`, and
`wp sassy deps` says `MISSING` / `current` / `changed`, where `current` means something different
from the one in `list`. One owner for the rule and four for the words is the same defect wearing
a smaller hat. `Diagnostic` gets this right and is the model: one schema, four renderings, one
formatter. Phase 6 does the same for state.

### Naming

| Name | Is | Notes |
|---|---|---|
| `Asset` | One enqueued thing | Styles now, scripts in phase 8. Not symmetric subtypes |
| `Style_Stack` | The registry | Discovery and queries over all assets |
| `Printer` | Produces one asset's output | Replaces `SCSS_Compiler`. `Build_*` is taken by output paths |
| `Build_Target` | Where output goes | Path, URL, filename, map path |
| `Compile_Cache` | Currency decisions | Sole owner of "is it stale" |
| `Compile_Request` / `Compile_Result` | Engine contract | Symmetrical, typed, engine-neutral |
| `Diagnostic` | One reportable event | The contract between every surface |
| `Policy` | Whether the dev surface is active for this request | One filterable question |
| `Import_Graph` / `Import_Scanner` / `Import_Resolver` | SCSS dependency tracking | Unchanged from 2.1 |
| `Style_Surface` | A script's style-mutation profile | Phase 8 |

`Typesetter` is reserved. If a layer ever *composes* multiple sources into one sheet, that is its
name; too good to spend on an executor.

House idiom unchanged: `Class_Name`, `*.class.php`, `snake_case()`, `require_once` in
`load_models()`, no autoloader for plugin classes. No PSR-4, no camelCase.

---

## 3. Phases

1–4 sequential, each assuming the last. 5 needs 1–3 only and may run parallel with 4. 6 needs
1–3 (`Compile_Cache` for state, `Diagnostic` for the panel). 6b is the admin page, carved out of
6 because it is the only part needing visual design; it needs 6 and ships with the 3.x minors.
7 follows 6 and a successful spike. 8 is independent of 5–7. 9 is recorded, not scheduled.

### Phase 1 — `Asset` and `Style_Stack`

The spine.

**`Asset`** — value object over a `_WP_Dependency`:

```
handle          string
type            'style'          ('script' in phase 8)
src             string|false     as registered
source_path     string|null      resolved path; null when remote or unresolvable
deps            string[]         WordPress handle dependencies — currently discarded
extension       string|null      'scss', 'css', …
is_compilable() bool
is_local()      bool
```

**`source_path` resolves lazily, and `sassy-src-path` filters the `null`.** URL→path resolution
moves out of `SCSS_Compiler::get_src_path()` and into `Asset`, which is the only place it exists
after phase 1. Three rules, because the 2.x version got each of them slightly wrong:

- The resolver returns `?string`. `null` means **the URL maps nowhere** — a remote host, a
  hostless src that is not root-relative, `src === false`. It does **not** mean the file is
  missing: a local URL resolves whether or not anything is there, so callers can name the path
  they looked at rather than echoing the URL back. Existence is a separate `file_exists()`,
  asked where it matters. It never returns the URL as a consolation; a URL in a variable named
  `source_path` is exactly the class of lie §1 rules out.
- **Root-relative sources resolve.** 65 of the 337 handles register as
  `/wp-admin/css/common.min.css` — every wp-admin stylesheet. 2.x reads a missing host as remote,
  which puts a fifth of the stack out of reach of the model this phase exists to build.
- **The `DOCUMENT_ROOT` fallback is taken only when it names a real file.** WP-CLI leaves
  `DOCUMENT_ROOT` unset, so 2.x's unconditional swap builds a rootless path whenever the ABSPATH
  candidate is missing, and reports that as the file it looked for.
- `sassy-src-path` applies **unconditionally, including over a `null`**. In 2.x it applies only
  on the branch that already succeeded, so the one filter documented as "override the source
  path" cannot rescue the one case that needs overriding — a CDN-hosted or otherwise unresolvable
  URL. A filter returning a path is honoured whether or not resolution found one; returning
  `null` leaves it unresolved.
- Resolution is lazy, so the filter receives a fully constructed `Asset` as its fourth argument.

`Printer` reports the two failures differently, because they are different: a local source that
is absent names **the path it looked at**, and an asset that maps nowhere gets *source could not
be resolved to a local file*, naming the URL. 2.x emitted `Source file not found: <url>` for the
second, formatting a URL as though it were a path and sending an agent after a file that was
never named. **Phase 3 owns this**, since it needs `Diagnostic` to exist; phase 1 kept the 2.x
string so that `Printer::get_src_path()` had something to return.

`is_compilable()` is `is_local()` **and** an extension of `scss`. Indented syntax was tried here
and is **closed as won't-do**; see §5 and §6. `Import_Resolver` keeps resolving `.sass` partials,
because consuming what a third-party library wrote is over-inclusion and safe.

**`Style_Stack`**:

```
discover(array $contexts): static
all(): Asset[]
compilable(): Asset[]
handle(string $h): ?Asset
dependents_of(string $file): Asset[]     phase 5 fills this in
context_errors(): array                  context => message, for contexts that raised
```

Discovery fires each context inside an output buffer and **records** anything that raises rather
than losing the run — third-party callbacks on the admin and editor hooks assume a request
WP-CLI is not making, and 2.x already guarded for it inside the CLI. Moving the guard here is
what lets the surfaces render the failure without owning the policy. The method is
`context_errors()`, not `errors()`: `Compile_Result::errors()` returns `Diagnostic[]` in phase 3,
and one name for two things is what the naming table exists to prevent.

Queues come from a `sassy-style-queues` filter defaulting to `[wp_styles()]`. The
`$digitalis_styles` global is **not** special-cased: a hard-coded second registry is exactly the
thing a filter should express.

`Theme::enqueue_style_last()` in Lattice still populates that global and **is live API on other
sites** — it has no callers on this install, which is a fact about this install and not about the
framework. Lattice is a shared submodule; its consumers are not enumerable from here. So the
method stays, and anything registering into a second queue adds it through the filter.

**Breaks:** `Sassy::get_scss_styles()` removed.

**Acceptance:**
- `wp sassy list --format=json` returns every registered handle (329 on the reference install at
  time of writing — assert against the live registry, never a constant) with WP deps populated;
  `--compilable` narrows to those Sassy can build. The count is per hook set, not a property of
  the install: 329 under the default `--hooks=frontend`, 337 under `--hooks=all`. A discovery
  run that reports more handles than §1 quotes is doing its job.
- Discovery tests cover each context and the multi-queue filter.
- No caller outside `Style_Stack` reaches into `wp_styles()->registered`.

---

### Phase 2 — One service, thin surfaces

`SCSS_Compiler` (619 lines, five responsibilities) splits into the four classes below — and the
work does not end at that file. The cache keys alone are read from three: the CLI reads
`sassy-filemtimes-` in `watch`, `list` and `deps` and deletes both keys in `clear`, and `UI`
deletes them with a raw `$wpdb` `LIKE` query. `Compile_Cache`'s public surface has to be wide
enough to retire those call sites, or the first acceptance item below cannot pass.

- **`Printer`** — takes an `Asset`, consults `Compile_Cache`, assembles the engine arguments,
  invokes the engine, post-processes, writes, records. Returns `Compile_Result`. The argument bag
  stays the untyped array until phase 3 replaces it with `Compile_Request`; pulling that forward
  is how a phase boundary stops being a review gate.
- **`Build_Target`** — pure path math. Every `get_build_*` and `sassy-build-*`.
- **`Compile_Cache`** — takes the `Asset`, `Build_Target` and `Variable_Resolver` on construction, and answers `needs_compile()`, `is_current()`, `record(Import_Graph, $time)` and `forget()`. It also carries handle-addressed statics (`get_graph`, `get_last_compile_time`, `forget_handle`, `forget_all`), because `wp sassy deps` and `wp sassy clear` have a handle and nothing else. Design that static surface from those call sites: without it the acceptance below cannot pass.
- **`Variable_Resolver`** — defaults, `sassy-variables`, map conversion, URL scheme
  normalization, signature hashing, and `prepend()` for engines that inject variables through the source.

All surfaces call `Printer` and render its result. They may format; they may not decide.

Staleness has two inputs and they live in different classes: the import graph (`Compile_Cache`)
and the variable signature (`Variable_Resolver`). `Compile_Cache` owns the *decision* and calls
`Variable_Resolver` for the signature — it does not receive it from a caller, or the rule leaks
back out to the surfaces it was extracted from.

**The fourth filter argument.** Every per-compile filter is documented as
`($value, $src, $handle, $compiler)`, and `sassy-engine` as `($engine, $compiler)`. With
`SCSS_Compiler` gone the fourth argument becomes the **`Asset`** — the identity of what is being
built, available before a `Compile_Request` exists, and a value object rather than a handle onto
the executor. Arity is unchanged, so a callback ignoring the argument (all four of d-pace's do)
is unaffected. Passing `Printer` instead would rebuild the god-object access the split exists to
remove.

**Breaks:** `SCSS_Compiler` renamed `Printer`; `SASSY()->get_compilers()` renamed `get_printers()`, keyed by a 1-based index; `get_index()` removed from the compiler; the fourth argument of every per-compile filter becomes the `Asset`; `SCSS_Compiler::prepend_variables()` and `::temp_file()` gone.

`Printer` carries no instance counter. `Sassy` assigns the index as it collects printers, because `assets/js/sassy.js` keys admin bar DOM nodes on `meta.index`; phase 6 rewrites that JS and rekeys by handle, and until it does the index has to keep being emitted.

**Acceptance:**
- No transient key or staleness rule exists outside `Compile_Cache`.
- The same failure produces **identical diagnostic text** on every surface — the CLI, the footer
  panel and the AJAX payload carry the engine's message verbatim and unwrapped. Surface chrome
  may still differ (2.1 prefixes it `SASSY -> {basename} -> ` in the footer and
  `{handle} -> ` in the CLI); unifying the chrome waits for phase 3's canonical formatter, which
  is what makes a single rendering possible. Phase 2's job is that no surface *rewords* the
  failure.
- The 2.1 suite passes unchanged except for renames.

---

### Phase 3 — Engine contract and diagnostics

**Sassy's own failures become diagnostics too.** `Printer::compile()` still reports an absent or
unresolvable source as a plain string, and `Import_Scanner` truncation as an appended warning.
Both become `source: 'sassy'` diagnostics here: an absent local source is an `error` naming the
path it looked at, an asset that maps nowhere is an `error` naming the URL, and truncation is the
`warning` the severity table already describes. Carried from phase 1, which described the split
but had nowhere to put it.

**`Compile_Request`** replaces the untyped `$args` bag. The current bag carries scssphp's own
option names (`sourceMapWriteTo`, `sourceMapBasepath`, `sourceMapRootpath`) which the Dart engine
reverse-engineers — the abstraction leaking its first implementation.

**`sassy-src-map-options` is removed**, not renamed: it is a public filter whose entire value
surface is scssphp option names, so preserving it would preserve the leak. `map_path` and
`map_url` replace what callers legitimately reached for. The remaining keys were never
configuration — `sourceMapBasepath` and `sourceMapRootpath` exist to make scssphp write correct
relative `sources`, which is the engine's own business and moves inside `Scssphp_Engine`, derived
from `map_path`. The Dart engine already achieves the same by writing output and map into the
build directory. No consumer on the reference install binds the filter.

```
source        string        SCSS text
source_path   string
load_paths    string[]
variables     array
style         'expanded'|'compressed'
source_map    bool
map_path      string|null   engine-neutral
map_url       string|null
```

**`Compiler_Engine`** gains capabilities:

```
capabilities(): array        e.g. ['modules', 'source_maps', 'compressed']
supports(string $c): bool
```

`Scssphp_Engine` declares no `modules` support. What it already produces is better than it looks: scssphp throws a `SimpleSassFormatException` whose message names the real source file, line and column and draws its own frame, and the engine already catches it and returns that message. The gap is the **remedy**, so the work is to add "compile this with `Dart_Sass_Engine`" and to declare the capability, not to rescue a message from inside the vendor.

#### Diagnostics

Today an error is a string and warnings are an array of strings. Dart Sass emits file, line,
column, a code frame, an include trace and a documentation URL; all of it is flattened. Lossy for
humans, unusable for agents.

**`Diagnostic`**:

```
severity   'error' | 'warning' | 'deprecation' | 'notice'
message    string        one line, no frame
file       ?string       author file, never a temp path
line       ?int
column     ?int
frame      ?string       code excerpt as the engine drew it
trace      ?string       include chain
code       ?string       engine identifier, e.g. 'global-builtin', 'import'
url        ?string       e.g. https://sass-lang.com/d/import
source     'engine' | 'sassy'
```

**Severity taxonomy:**

| Severity | Means | Output produced | Gates |
|---|---|---|---|
| `error` | Compilation failed | No | always |
| `warning` | Compiled, but something the author should fix (`@warn`, ambiguous import, truncated import graph†) | Yes | `--strict` |
| `deprecation` | Compiled, will break in a future Sass or CSS version | Yes | `--strict=all` |
| `notice` | Informational, Sassy's own (e.g. Lightning CSS stripped the source-map link) | Yes | never |

`deprecation` is separated from `warning` because it is time-bound and high-volume, and lumping them makes `--strict` useless. Hence the two gates: `--strict` promotes warnings only; `--strict=all` adds deprecations, for the day they matter (they become hard errors at Dart Sass 3.0).

Measured on the reference install with `--verbose`, so nothing withheld: **10 deprecations for `frontend`, 10 for `editor`, 0 for `admin`**. An earlier draft said 48 per compile; that figure does not reproduce and is withdrawn. Twenty across the install is still enough to drown `--strict`, which is all the argument needs, and every one of them becomes a hard error at Dart Sass 3.0.

† Truncation keeps `warning` severity but fails `wp sassy check` unconditionally — see phase 5
for the principle.

`source`
distinguishes engine diagnostics from Sassy's own advisories, which are not the author's fault
and should not read as if they were.

**`Compile_Result`** carries `Diagnostic[]`, with `errors()`, `warnings()`, `has_errors()`.

Parsing follows the over-inclusion rule: anything unparseable becomes a `warning` carrying the
raw text in `message`. Never dropped, never guessed at.

**Canonical text rendering** — one function used by CLI, console, panel and clipboard:

```
ERROR  _layout.scss:41:10  Undefined variable.
   ╷
41 │     gap: $gap;
   │          ^^^^
   ╵
  _layout.scss 41:10  layout()
  frontend.scss 12:1  root stylesheet
```

The header names the file, line and column of the caret, matching the top trace frame when a
trace exists (Sassy-source notices have neither frame nor trace). This example is the formatter's
spec; keep it self-consistent.

Everything below the header is the engine's own drawing, reproduced verbatim — `frame` keeps the
gutter rules and the engine's line-number padding, `trace` keeps its column alignment. **The two
engines draw differently**: Dart rules its frames with `╷ │ ╵`, scssphp with `, | '`. The parser
must find file, line and column under either, and the rendering therefore looks engine-dependent
by design. That is not in tension with the acceptance above about surfaces: the same failure from
the same engine renders identically everywhere, which is the claim being made. The
formatter composes; it does not redraw. `message` is likewise verbatim, which is why the header
above reads `Undefined variable.` and not `Undefined variable: $gap`: Dart names the offending
token only in the frame, and synthesizing a richer one-liner would mean guessing at output the
over-inclusion rule says to carry intact. Verified against sass 1.92.0 — the example is a real
trace, not a sketch.

**Breaks:** `sassy-src-map-options` removed; `Compile_Result::info` replaced by `Diagnostic[]`, so `get_warnings()` returns objects rather than strings; `Compiler_Engine::compile()` takes a `Compile_Request`; `Printer::get_error()` returns a `Diagnostic`, which fatals the two callers that interpolate it today (`Sassy::get_errors()` and the CLI's `compile`), so both move to the formatter.

**Acceptance:**
- `wp sassy status` reports the active engine's capabilities.
- A `@use` under scssphp names the source file and the remedy.
- No scssphp-shaped key in `Dart_Sass_Engine`.
- Dart's deprecation output round-trips into `Diagnostic` with file, line, code and url intact.
- Unparseable engine output survives as a `warning`.
- A missing local source reports the path it looked at, and one that maps nowhere reports the URL, both as `source: 'sassy'` diagnostics rather than strings.
- Every consumer of the old string-array contract is migrated with the schema: `get_warnings()`
  fed `WP_CLI::warning()` and `tests/test-source-maps.php` by string interpolation, which fatals
  on an object. Phase 2's "the 2.1 suite passes unchanged except for renames" stops holding here,
  and that is expected — the suite moves to the canonical formatter in this phase.

---

### Phase 4 — Extension API

Four extension points, one registry, discoverable via `wp sassy status`: **load paths, variables,
post-processors, engines**. Filters remain the binding mechanism, with typed contracts offered
alongside, not instead.

**Bricks and Oxygen do not ship in 3.0.** Neither builder is installed here and neither will be;
shipping code with no test surface is how the `resolve_bin()` and enum bugs happened.

They survive as **conformance fixtures**: each rewritten against the new extension API and tested
against stubbed builder functions (`oxy_get_global_colors()`, `ct_get_global_settings()`,
Bricks' theme-style lookups), exactly as the suite already stubs WordPress. If the API cannot
express them, the API is wrong.

`Digitalis` also goes: its two variables are unused across 17,113 lines.

Lightning CSS survives, ported to this API as the reference post-processor — the proof that the
post-processor extension point works, as the retired integrations are for variables. Its
source-map stripping is the canonical `notice`.

**Breaks:** `Bricks`, `Oxygen` and `Digitalis` integrations removed, and with them every variable they injected; `include/integrations/` is gone.

**Acceptance:**
- Each retired integration exists in `tests/fixtures/` expressed purely through documented
  extension points, with tests against stubbed builder APIs.
- `wp sassy status` lists registered providers.
- `include/integrations/` is gone.

---

### Phase 5 — Reverse dependencies, `check`, and strict mode

The agent loop. After editing a partial an agent needs: did it compile, what broke, **what else
is affected**. The third needs the graph inverted; the data is already recorded.

- `Style_Stack::dependents_of($file)` — file → handles.
- `wp sassy deps --file=<path>` for the reverse direction.
- **`wp sassy check`** — one call, one exit code: everything current, nothing erroring, no
  orphaned outputs, no truncated graphs.
- `--strict` promotes warnings to failures; `--strict=all` adds deprecations. Off by default.

An **orphaned output** is a file in the build directory that no discovered asset claims as its
`Build_Target` — CSS and maps left behind by a handle that was renamed, deregistered or had its
source deleted. They are reported, never auto-removed; `wp sassy clear` is the eraser.

Orphan detection is the reason **`check` defaults to `--hooks=all`**, alone among the commands.
Under any narrower hook set every admin- and editor-built file looks orphaned, because the
handle that owns it was never registered.

**An orphan is a `warning`, not a failure.** It is the one thing `check` reports that it cannot
be certain about: a handle enqueued only on some template is discovered by no hook set at all, so
its perfectly valid output looks unclaimed. Measured on the reference install, a naive
implementation also flags `.sassy-tmp`, which is the Dart engine's own directory. Detection is
therefore scoped to files matching the build-name pattern, skipping dotfiles and directories, and
graduating to a failure only under `--strict`.

That is a deliberate exception to the principle below rather than a hole in it. Truncation makes
`check`'s answer *unknowable*, so it fails. An orphan makes `check`'s answer *noisy*, and a check
that cries wolf on its first run teaches people to ignore it, which costs more than the orphan
did.

Truncation fails `check` by default even though its severity is `warning`. The principle:
**`check` fails on anything that makes its own answer untrustworthy.** Its promise is "everything
current", and with a truncated graph that answer is unknowable — epistemic, not stylistic, so it
does not wait for `--strict`.

**Breaks:** none. `wp sassy check` and `wp sassy deps --file` are additions.

**Acceptance:**
- Editing a shared partial and running `deps --file` names exactly the handles that recompile.
- `check` exits non-zero on a stale handle, a compile error and a truncated graph; zero
  otherwise. An orphaned output is reported as a `warning`, so it fails only under `--strict`.
- Orphan detection ignores dotfiles, directories and anything not matching the build-name
  pattern. On the reference install it reports the one real orphan and not `.sassy-tmp`.

---

### Phase 6 — Frontend and dev surface

**Status: agreed.**

#### Policy

One question, asked once per request: is Sassy's dev surface active?

```
Policy::active(): bool          default: current_user_can('edit_theme_options')
filter: sassy-dev
```

Sassy models nothing about environments or roles. Anything expressible in PHP is expressible in
the filter — capability, env constant, IP, user meta. The reference install composes with
Lattice's `Dev_Role` (a `dev` capability granted via `user_has_cap` from user meta, managed by
`wp lattice dev`):

```php
add_filter('sassy-dev', fn () => current_user_can('dev'));
```

The gate controls the UI assets, the live-compile endpoint and the admin page. A separate,
stricter gate — `sassy-write-source`, default **false**, never implied by `sassy-dev` — controls
phase 7 writes.

**Endpoint contract.** Every Sassy endpoint checks `Policy::active()` **server-side**, in
addition to a nonce. The `wp_ajax_nopriv_` registration is dropped — a dev is by definition
logged in, and 2.x's nopriv-plus-nonce-only model is not something 3.0 inherits. The tier-2
write endpoint additionally checks `sassy-write-source`. A nonce is a CSRF token, not an
authorization model; nothing in Sassy treats it as one.

#### State

Two questions, two owners, one vocabulary each. Every surface renders these and none of them
derives its own.

- **Asset state**, from `Compile_Cache`: `no source` (the source is absent or maps nowhere),
  `not built`, `stale`, `current`. Plus `error` and `warning`, which come from the last compile's
  `Diagnostic[]` rather than the cache, and outrank the rest when present: an erroring handle is
  not interesting as "stale".
- **File state**, from `Import_Graph`, for one dependency inside a graph: `missing`, `changed`,
  `current`. This is a different question from asset state and keeps its own words; `wp sassy
  deps` is the surface that asks it.

The admin bar's root glyph collapses asset state to a traffic light (`error` / `warning` /
otherwise ok). That is a rendering of the vocabulary, not a fifth vocabulary.

#### JS event contract

Replaces the Oxygen/Angular reach-in (`parent.angular`, the last Oxygen artifact). The capability
it hacked at — driving styles across a window boundary — becomes a declared surface:

```
window.sassy.compile()   window.sassy.reload()

events on document:
  sassy:before-compile
  sassy:compiled     detail: { diagnostics, styles }
  sassy:reload
```

A builder/iframe integration is a few lines of bridge: listen in the top window, forward into the
iframe. The Angular branch is deleted.

#### Admin bar

Pruned to actions. Detail lives on the admin page.

| Item | Fate |
|---|---|
| State glyph on root | Keep — error / warning / ok |
| ⚡ Live Compile | **Keep.** No reload, skips cache, instant, it just works |
| Force Recompile | **Keep**, renamed 🤖 Force Compile. An earlier draft dropped it as redundant because "Live Compile already skips cache", which contradicts the live-compile section below and is false: `compile_all()` goes through `Compile_Cache::needs_compile()` like everything else. Being a *check* and not a rebuild is the whole reason the keypress is cheap, so a forced route has to exist separately. Caught by code review in phase 6, after the claim had survived six passes |
| Log Variables | **Replaced by 📜 Logging** — a submenu of console-logging toggles (diagnostics, compile meta, stack summary, capture diffs), persisted client-side, rendered via the canonical formatter |
| 🖌️ Capture | New, phase 7, present only when enabled |
| Clear Cache | **Stays for 3.0.0.** It was dropped because the admin page would carry it, and that reason expires while the page waits in 6b. Removed from the bar when its replacement ships |
| Per-file submenus | Drop from bar; per-handle detail moves to the admin page |

#### Live compile

- Payload carries `Diagnostic[]` per handle; panel, console and clipboard render it through the
  canonical formatter. Per-diagnostic copy and copy-all produce agent-pasteable text.
- The compile keybinding is **configurable**:
  `apply_filters('sassy-keybinding', ['ctrl+space', 'meta+space'])`, passed to the browser via
  localized params and parsed there. Each entry is `+`-separated modifiers + key; the default
  pair preserves 2.x behaviour, which accepted ctrl *or* meta. `false` disables the binding
  entirely, leaving the admin bar button as the trigger. The collision with IME/autocomplete has
  bitten in practice, so this is a known need. Being a filter, it can branch per user — anything
  expressible in PHP.
- The endpoint is cheap post-2.1 (~1ms when everything is current), so the keypress is "check and
  reload", not "rebuild the world".
- Cache-busting via a server content-hash instead of `Math.random()` — exact, and doubles as the
  change-poll primitive.
- Auto-reload polling against that hash is **opt-in only**, toggled from the Logging menu — never
  on by default, even under `sassy-dev`. A page that reloads itself uninvited is the one
  behaviour that would make the team distrust the tool.
- Errors keyed by **handle**, not admin-bar DOM index.

**Verification.** The suite is PHP-only; nothing here gets a browser harness. Browser behaviour
is verified by a manual acceptance checklist kept at `tests/manual.md` and walked before each
release; the paintbrush spike page is kept as a fixture. **Phase 6 creates that file** — it does
not inherit one — covering every browser behaviour this phase ships. Untested surface is named, never
implied covered — the `watch` precedent.

**Breaks:** `wp sassy list`'s `state` column takes the canonical asset-state vocabulary, changing
`no source`/`not built`/`current`/`stale` into that set plus `error` and `warning`; the
`wp_ajax_nopriv_sassy_compile` registration is dropped; `?sassy-vars=1`, `Sassy::get_all_variables()` and `meta.variables` are gone; Force Recompile and Clear Cache leave the admin bar, as do the per-file submenus; errors and DOM nodes are keyed by handle, so `meta.index` goes with the JS rewrite.

**Acceptance:**
- With `sassy-dev` returning false, no dev-surface assets reach the page: no JS, no panel
  markup, no localized params, no admin bar node. (The compiled CSS itself always ships — that
  is the product, not the dev surface.)
- A compile error renders identically (same text) in CLI, console and panel; copy reproduces it.
- One vocabulary for asset state and one for file state, owned by `Compile_Cache` and
  `Import_Graph`. No surface computes a state label: `grep -rn "'stale'\|'not built'" include/`
  returns hits in one file each.
- With the reference binding in place (`sassy-dev` → `current_user_can('dev')`), a user holding the
  d-pace `dev` capability sees the UI and an administrator without it does not. Stated against the
  binding, not the default: the default policy *is* `edit_theme_options`, which every administrator
  holds. **Verified on staging with the capability granted and revoked**, not on production, which
  §8 puts out of reach. The point being tested is that the gate keys on who rather than where, and
  staging demonstrates that as well as production would.
- `sassy:compiled` fires with diagnostics attached; a five-line listener can forward it into an
  iframe.

---

### Phase 6b — Admin page

**Carved out of phase 6.** Everything else in that phase is mechanism: a gate, an event contract,
a pruned bar, a keybinding. This is the first thing in the plan that needs **visual design**, and
a design conversation inside a phase of plumbing gets neither the attention it needs nor the room
to be argued about. It lands with the 3.x minors alongside phase 7.

Not renumbered to 7, deliberately: commit messages across five phases already cite phase numbers,
and rewriting what "phase 7" means would make the history ambiguous in a way no file edit fixes.

Two debts move here with it: **full diagnostic persistence** (the per-handle view needs the
diagnostic *text*, which needs its own transient key, because phase 5's severity tally lives in a
record read on every request) and the **provider listing** in Status.

#### The page

v3 gets her admin page. A **dashboard, not a settings form** — config remains code.

- **Status** — engine + capabilities, registered extension providers, binaries + versions, build
  dir + writability, constants, resolved policy (who currently sees the dev surface). Providers
  arrived with phase 4 and `wp sassy status` already lists them; two status surfaces that
  disagree on their first day is not worth the saving.
- **Stack** — every handle (337 here under all hook sets), filterable: sassy-managed /
  compilable / third-party; WP deps; asset state, in the vocabulary phase 6 settled.
- **Per-handle** — import graph (deps + watched dirs), build target, last diagnostics,
  source / css / map links (relocated from the bar).
- **Actions** — Compile all, Clear cache.

Server-rendered. Behaviour declared in attributes (`data-sassy-copy`, `data-sassy-dismiss`,
`data-sassy-poll`); one small hand-rolled JS file does event delegation + fetch. No dependencies.
Design intent, verified once phase 8 exists: the profiler run over Sassy's own UI reports only
intended categories.

#### Design, settled

**It looks like WordPress with a side of Sassy spice.** wp-admin's structural furniture (`.wrap`,
the `.notice-*` palette, list-table conventions, the admin colour scheme) carries the page;
Sassy's own character lives in the accents and in the parts wp-admin has no vocabulary for, which
is mostly the diagnostic block below.

**A diagnostic renders as UI header plus verbatim body.** The split follows from phase 3: Sassy
composes the header and never redraws what the engine drew.

- The **header** becomes interface. `severity` gets a colour and label from the notice palette,
  with `deprecation` distinct from `warning` rather than borrowed from it, or the `--strict` /
  `--strict=all` split is invisible in the surface people actually look at. `message` is prose and
  may wrap. `code` and `url` become a link to the Sass documentation where present.
- The **frame and trace** are engine-drawn art: monospace, unwrapped, `overflow-x` rather than
  reflow. Their columns are aligned by the engine and rewrapping them destroys the caret.

**`file:line:column` is click-to-copy.** Not an editor link: `vscode://` works on one machine and
is dead on the next, while a copied `path:line` always works and feeds the paste-into-an-agent
loop directly.

This raises something phase 3 left standing: **Dart cites bare basenames**, so half the real
diagnostics on the reference install say `_harness.scss` rather than a path, and copying that is
copying nothing useful. 6b resolves cited names against the recorded `Import_Graph`, which is
unambiguous wherever exactly one recorded dependency matches the basename, and falls back to what
the engine said. That is the phase where §1's "every string names something a machine can open"
finally holds for diagnostics.

**Diagnostics collapse, grouped by `code`.** Thirty across the reference install, four frame lines
each, and ten of them are the same `global-builtin`. Folding a group into one expandable row is
presentation rather than a second structure, so it does not breach "no surface invents its own",
but it is the first place a surface deliberately differs from the CLI and is recorded as a
decision rather than left to drift.

**Copy yields the canonical text, never the DOM.** Whatever the page looks like, the copy
affordance produces exactly what the CLI prints, because that is what gets pasted into an agent.
The raw rendering therefore stays in the markup even when a group is folded.

**Sassy compiles her own admin CSS.** The argument is stronger than dogfooding: the default engine
is scssphp, which cannot do `@use` or `@forward`, so Sassy's own stylesheet has to be authored to
compile on a default install. The team then permanently eats the limitation its own default
imposes on everyone who has not configured Dart.

The bootstrapping risk is real but mild. A stylesheet that fails to compile leaves the admin page
unstyled rather than broken, and the failure appears in the diagnostics panel it failed to style.
`.vscode/tasks.json` still runs `sass assets/scss/sassy.scss assets/css/sassy.css` against an
input deleted in `398e1c6`; this phase restores the source, which is also what makes that task
mean something again.

**Breaks:** Clear Cache leaves the admin bar here, not in phase 6. It was dropped from the bar
*because this page would have it*, and that reason expires while the page is unbuilt, so the bar
keeps it until its replacement exists.

---

### Phase 7 — Paintbrush (experimental, spike-gated)

The authoring loop today: sculpt in the inspector → copy into the stylesheet → `CTRL+SPACE` →
repeat. The ideal: inspector edits **push to source**. Three tiers.

**Tier 0 — already works on 2.1; costs a docs page.** DevTools Workspaces + the fixed source
maps: map the project folder, styles-pane links jump through the map into the real `.scss` in
Sources, edit, save — DevTools writes to disk, `watch` recompiles, auto-reload repaints. This is
editing source in the browser rather than pushing painted styles back — half the paintbrush,
free.

**Tier 1 — capture & copy. The MVP. Pure observation.**
Inspector styles-pane edits mutate the page CSSOM. Snapshot each Sassy-managed sheet's rules at
load; on capture, diff live CSSOM against baseline; map changed declarations through the source
map to `{file, line}`; render as a patch in the panel:

```
frontend.scss:83  .card
  gap: 4px → 12px
_atoms.scss:19  .btn
  + border-radius: 6px
```

Copy as text. The same patch is a first-class **agent handoff**: "here is what I painted —
integrate it properly, hoist values to tokens where they belong." The paintbrush and the agent
loop are the same pipe.

> **SPIKE FIRST.** The tier stands on one assumption: styles-pane edits are visible to page JS
> via `document.styleSheets` in the browsers the team uses. Believed true for Chromium;
> unverified. If the spike fails, tier 1 dies and tier 0 is the story.

Two costs the tier carries, named here so they are priced before the spike rather than after:

- **Mapping requires decoding the map in the browser.** `{file, line}` comes from the `mappings`
  field, which is VLQ-encoded — roughly a hundred lines of hand-rolled decoder, against a
  frontend committed to zero dependencies. It is the one place in phase 6–7 where "hand-rolled
  minimal" is not also "small". The spike should decode one real mapping, not merely prove
  CSSOM visibility.
- **Tier 1 requires a reachable map.** Lightning CSS strips `sourceMappingURL` from the CSS it
  emits (the canonical `notice` in phase 4), so wherever it is enabled the paintbrush has
  nothing to map through and must degrade to copy-without-location rather than fail silently.

**Tier 2 — push to source.** POST the patch. The server applies it iff:
- the phase 6 endpoint contract passes, plus `sassy-write-source` (its own gate, checked
  server-side; never implied by `sassy-dev`),
- the target file is inside a recorded import graph (realpath-validated),
- the mapped source line **literally contains the old value** — `gap: 4px`, not `gap: $gap`.

When the source text differs from the served value (variables, mixins, functions), the write
downgrades to tier-1 copy with context: blind write-back would be wrong, so it is never
attempted. Textual replace only; no SCSS parsing. `watch` recompiles; the browser reloads; the
loop closes without leaving the inspector.

**Sharp edges, named:** new rules created in the inspector live in `inspector-stylesheet` with no
source location — offered copy-only, with the matched sheet's entry file suggested.
`element.style` edits map to no stylesheet — captured and offered copy-only. Compressed vs
expanded output is irrelevant: the diff is CSSOM-shaped, not text-shaped.

Not crazy. It is the import-graph shape again: observe, map through data already produced, act
only where the mapping is exact.

**Breaks:** none. Everything here is additive and gated.

**Acceptance:**
- Spike result recorded either way.
- Tier 1: an inspector edit to a compiled declaration appears in the panel with the correct
  source file and line; copy round-trips through the canonical formatter.
- Tier 2: the same edit lands in the `.scss` source; a `$variable`-backed declaration refuses the
  write and offers the copy path; a file outside every import graph is rejected.

---

### Phase 8 — Script observation

`Asset` gains `type: 'script'`. **Styles and scripts are not symmetric subtypes** — a stylesheet
has a build pipeline, a script has a profile. Composition: an asset *has* an `Import_Graph`, or
*has* a `Style_Surface`. A common `compile()` is the thing to refuse.

**`Style_Surface`** — marker detection, no semantics:

| Category | Markers | Reading |
|---|---|---|
| Scope control | `data-*` writes, `classList`, `dataset` | How theming is driven. Highest-value signal |
| Custom property writes | `setProperty('--…')` | **Intended mechanism**, not pollution |
| Inline layout writes | `style.width`, `style.top`, … | Genuine cascade override; a smell |
| Layout reads | `getBoundingClientRect`, `ResizeObserver`, `matchMedia` | Thrash and CLS surface |
| CSSOM injection | `adoptedStyleSheets`, `insertRule`, `new CSSStyleSheet`, `registerProperty` | What third parties do to you |

Rows 2 and 3 must be distinguished from the start. Cascades are gated by attributes today
(`[data-theme]`). When style container queries land, gating moves to inline custom properties —
`style="--theme:dark"` with `@container style(--theme: dark)` — so
`el.style.setProperty('--theme','dark')` is the *future primary mechanism*; filing it under
"inline style pollution" would report the intended design as a defect.

Reference baseline — `d-pace/`, 28 files, excluding `node_modules` and `*.min.js`: `classList`
10, `dataset` 8, `.style.` 7, `ResizeObserver` 5, `getBoundingClientRect` 4, `matchMedia` 4,
`data-theme` **1**, CSSOM injection **0**. Note `dataset` and `data-theme` are separate markers
with separate counts; an earlier draft fused them at 8, which is the `dataset` figure.

**Breaks:** none. `Asset` gains `type: 'script'`; existing style assets are unaffected.

**Acceptance:**
- `wp sassy list --type=script --format=json` reports each script's surface categories.
- Every file touching `data-theme` on the reference install is identified (one at time of
  writing, `d-pace/assets/js/site-header.js` — assert against grep ground truth, never a
  constant).
- Run over Sassy's own UI, the profiler reports only intended categories — the phase 6 design
  intent, verified here.
- No JS parsing beyond marker detection.

---

### Phase 9 — Cascade cross-reference (horizon)

Compiled CSS declares which scopes it responds to (`[data-theme="dark"]`, later
`@container style(--theme: dark)`). Phase 8 records which scripts set those attributes and
properties. Cross-referencing yields a lint nothing else can produce:

- **Dead scope** — CSS responds to `[data-mode]` but nothing sets it.
- **Unstyled switch** — a script sets `data-view` but no CSS responds.
- **Contended scope** — several scripts drive one attribute.

As gating moves from attributes to inline custom properties, the same cross-reference covers it
with a different extractor. Not scheduled; recorded so earlier phases do not foreclose it.

---

## 4. What breaks in d-pace

Updating staging is part of the work, not a follow-up — which means every row names the phase
that owns it. An unpinned row is a follow-up by another name, and the `$digitalis_styles`
deletion sat unscheduled through phase 1 proving it.

Anything here that a *third-party* site would also hit belongs in
[upgrading-to-3.0.md](upgrading-to-3.0.md), which each phase appends to as it lands. This table
is what breaks on this machine; that document is what breaks on someone else's. Each phase's
`**Breaks:**` line is the source for both.

Status is ✅ done, **open** for something still to decide or do, and unmarked for not yet reached.

| Phase | Change | Action |
|---|---|---|
| 1 ✅ | `Sassy::get_scss_styles()` removed | Verified: no usage in d-pace *or* lattice. Nothing to do |
| **1, open** | `$digitalis_styles` no longer read | `Theme::enqueue_style_last()` **stays** — it is live Lattice API on other sites. Earlier revisions of this table called it dead code on the strength of zero callers under this `wp-content/`, which measures one consumer of a shared submodule and proves nothing about the rest. The fix belongs in Lattice, not per-site: register `sassy-style-queues` there so every site calling `enqueue_style_last()` keeps working under 3.0. **Decision pending** |
| 2 ✅ | Fourth filter argument becomes `Asset` | All four d-pace callbacks ignore it: `engine($engine, $compiler)` declares it unused, the rest do not declare it. **No change needed**; verified against the shipped code, not assumed |
| 3 ✅ | `sassy-src-map-options` removed | No d-pace or lattice binding. Nothing to do |
| 4 ✅ | `Digitalis` integration removed | Confirmed unused (measured: 0 live occurrences — the only hits are two commented-out `@import`s in `scss-template/front.scss`). Note `lattice/load.php` injects the *same two variables* through `sassy-variables`, so removal changes nothing at runtime. That duplicate is dead too, but it is outside this table: **punch-list, not an edit** |
| 4 ✅ | `Sassy\Dart_Sass_Engine` may move namespace/directory | It did not move: still `Sassy\Dart_Sass_Engine` in `include/engines/`. Nothing to do |
| 6 ✅ | New: register the dev gate | Bound in `sassy.integration.php` as a `sassy-dev` hook returning `current_user_can('dev')`. Verified 2026-09-09: a holder of `dev` sees the surface, an administrator without it does not, which is the phase 6 acceptance and needed this binding to be testable at all |
| any ✅ | `wp_tempnam` workaround | Removed. Nothing in Sassy calls `wp_tempnam`: the Dart engine writes its own temp input and Lightning CSS owns a private `temp_file()` |
| — | Comment claiming source maps resolve | Now accurate. Row kept so nobody "corrects" a correct comment |

Production policy to adopt alongside (all built in 2.1):

- deploy hook runs `wp sassy compile --hooks=all`
- production returns `false` from `sassy-check-dependencies`
- development runs `wp sassy watch`

With check-dependencies off, an edit on production does not appear on reload — `CTRL+SPACE`
becomes mandatory there. That matches the team's stated loop by design, but it is a one-keystroke
change to what hot-wiring feels like, so it is named rather than implied.

---

## 5. Out of scope

- JS bundling, transpiling, or module dependency graphs
- DI container, service locator, event layer over WordPress hooks
- Autoloading plugin classes
- PostCSS or a CSS-transform plugin ecosystem — `sassy-css` suffices
- SSE or websockets — polling only
- A settings form, anywhere. Config is code
- Multi-site aggregation
- Anything about a script beyond its style-mutation surface
- Indented syntax (`.sass`) as an entry file. Closed, with reasoning, in §6
- SCSS parsing in the paintbrush — textual patches only, refused when inexact

---

## 6. Decisions taken

| Question | Decision |
|---|---|
| Bricks / Oxygen in 3.0? | **No** — conformance fixtures against stubbed builder APIs; no builder installs |
| `check` fails on warnings? | **No** — `--strict` promotes warnings, `--strict=all` adds deprecations. Truncated graphs fail by default: `check` fails on anything that makes its own answer untrustworthy |
| `Compile_Result` severity? | **Yes** — four levels: error / warning / deprecation / notice |
| Who is the dev surface for? | The digitalis team, production included. `sassy-dev` filter; d-pace binds it to the Lattice `dev` capability |
| Frontend idiom | Hand-rolled minimal; server-rendered; attribute-driven; zero dependencies |
| Admin page | **Yes** — dashboard, never a settings form. Carved out of phase 6 into **6b** (2026-09-09), because it is the only part of the dev surface needing visual design and that conversation should not be squeezed in beside a keybinding rewrite. Ships with the 3.x minors |
| Admin page look | **WordPress with a side of Sassy spice**: wp-admin furniture carries it, Sassy's character lives in the accents and the diagnostic block |
| Diagnostic rendering | Header becomes UI, frame and trace stay verbatim monospace. `file:line:col` is **click-to-copy**, not an editor link. Groups **collapse** by `code`. Copy always yields the canonical text, never the DOM |
| Sassy's own admin CSS | **She compiles it.** Authored to work on scssphp so the team eats the limitation its default engine imposes. Unstyled-on-failure is an acceptable bootstrapping risk |
| Admin bar | Glyph + Live Compile + Force Compile + Logging + Clear Cache (+ Capture in 7). The variables surface is removed everywhere (`meta.variables`, `?sassy-vars=1`, `get_all_variables()`), superseded by `wp sassy vars` and the Logging menu. Force Compile and Clear Cache stay: the first was dropped on a false premise, the second because 6b's page was to receive it |
| Oxygen/builder JS | Angular reach-in deleted; replaced by the `sassy:*` event contract |
| Paintbrush | In, as phase 7 — tier 0 documented, tier 1 after spike, tier 2 behind `sassy-write-source` |
| Clear Cache | **Stays in the bar for 3.0.0**, leaving when 6b's page replaces it. Dropping it was justified by the page carrying it, and that justification expires while the page is unbuilt |
| Auto-reload polling | Opt-in only, via the Logging menu; never a default |
| Keybinding | Configurable via `sassy-keybinding` filter (default `['ctrl+space', 'meta+space']`, preserving 2.x; `false` disables). The collision has bitten in practice |
| Unresolvable source paths | `source_path` is `?string` and never falls back to the URL. `sassy-src-path` applies unconditionally, including over a `null`, so the filter can rescue a URL Sassy cannot resolve |
| `.sass` entry files? | **No. Closed 2026-09-07, do not reopen.** It entered the plan because `Import_Resolver` resolves both extensions while the entry point accepts one. That asymmetry is real and harmless: importing a `.sass` partial someone else wrote is over-inclusion, which §1 calls safe. Entering on one is a feature nobody asked for, and there are zero `.sass` files on the reference install. Against it: §1's own premise. SCSS is being eroded by native CSS and style container queries are expected to finish the job, so a second Sass *syntax* is investment inside a shrinking part of the stack. The value in SCSS is that it is a CSS superset, so anything pasted from devtools or MDN compiles; indented syntax trades that for whitespace significance. **What it would have cost, measured, so nobody re-derives it:** scssphp needs `Syntax::SASS` passed to `compileString()` and is otherwise free; Dart infers syntax from the file extension, so its temp input must be renamed, and it then rejects the single-line variable prelude 2.1 depends on (`multiple statements on one line are not supported in the indented syntax`). A newline-separated prelude parses and shifts every source-map line by the number of injected variables, five here. `Import_Resolver` is unchanged either way |
| Fourth filter argument | The **`Asset`**. Not `Printer` — that rebuilds the god-object access the phase 2 split removes |
| `sassy-src-map-options` | **Removed, not renamed.** Its value surface is scssphp option names; `map_path` / `map_url` replace the legitimate uses and the rest moves inside the engine |
| `wp sassy check` hook set | **`--hooks=all` by default**, alone among the commands — a narrower set makes every admin/editor output look orphaned |
| Orphaned outputs | Reported as a `warning`, never auto-removed, failing `check` only under `--strict`. It is the one thing `check` cannot be certain about: a conditionally enqueued handle's output is indistinguishable from an abandoned one. `wp sassy clear` is the eraser |
| `--strict=all` differs per engine? | **Accepted.** scssphp implements a fraction of Dart's deprecations (of four probed, only `elseif` fired), so the same source yields different sets. That is `capabilities()` working, not a defect to reconcile. Document it; do not try to normalise one engine to the other |
| Execution | One builder at a time, in place on `style-stack` — no worktrees. The plugin works at every commit (strangler-style refactors) |
| d-pace access | Builders may edit d-pace/lattice **under `/staging/` only**, limited to the §4 breaks table |
| Version / branch | 3.0.0 / `style-stack` |

## 7. Still open

- **Paintbrush spike** — CSSOM visibility of inspector edits; tier 1 stands or falls on it. The
  first thing phase 7 builds, and buildable as a twenty-line scratch page any time before that.

---

## 8. Execution protocol

For builder agents. Every rule here exists because of something that actually happened during
the 2.x work.

**Starting a session.** Read `AGENTS.md` for what exists, this section for how to work, and the
brief for your phase. Then **prove the tree is where the documents say it is, before touching
anything**:

```
git status                       # clean, on style-stack
php tests/run.php                # 13 files
wp sassy check                   # exit 0
wp sassy status                  # version 3.0.0
```

That is not ceremony. Documentation in this repo has drifted from the code three times: filters
documented as taking the `Asset` while four still passed the `Printer`, AGENTS.md crediting phase 2
with a conversion it never made, and a removal justified by a capability the code did not have.
**Where a document and the code disagree, the code is what ships.** Fix the document and say so.

Two things about this machine that no document upstream of you will mention:

- `/var/www/dpace/staging/public/wp-content/plugins/d-pace` has **uncommitted work that is not
  yours**, and has had throughout. Two of the modified lines in `sassy.integration.php` are the §4
  dev-gate and `wp_tempnam` changes; everything else in that tree belongs to someone else. Do not
  commit there.
- 3.0.0 is tagged in the plugin header but **not released**. The digitalis.ca update JSON is not
  yet version-fenced, so publishing would offer a breaking upgrade to every 1.x install polling
  it. That fence is Jamie's and it is not done.

**One builder at a time, on `style-stack`, in place.** No worktrees, no parallel phases. The
phases are *mostly* serial by dependency; by §3's graph the genuinely parallelizable pairs are
4∥5, 4∥6, 5∥6 and 8∥anything-after-1, and none are worth the coordination cost. Note one
ordering consequence that is not a dependency: phase 6's "profiler reports only intended
categories" acceptance cannot close until phase 8 exists, so it is carried forward explicitly
rather than waived.

**This directory is the live plugin on staging.d-pace.com.** Real pages compile through this code
while you edit it. Therefore:

- **The plugin works at every commit.** Refactors land strangler-style: build the new class
  alongside, switch callers, delete the old — never delete-then-rebuild. A commit that leaves the
  plugin broken is wrong even if the next commit fixes it.
- Momentary breakage between tool calls (a half-written file) is tolerated; broken commits are
  not.

**One phase per builder run.** Stop at the phase boundary. Review happens between phases — this
repo moves in deliberate, reviewed bursts, and a builder that runs ahead of review has left the
protocol.

**Tests gate everything.**

- `php tests/run.php` green before every commit.
- Every fix ships with a test that fails against the pre-fix tree. Prove it:
  `php tests/run.php /path/to/old/checkout` — a test that passes against both versions is not
  testing the fix.
- **A contract change is past what that proof can reach.** Phase 3 replaced the engine argument
  and the result's warning type, so four existing test files had to be rewritten against the new
  API and now fail against any earlier checkout. That is not a regression signal, it is the rule
  hitting its limit: it proves a *new* test fails before the fix, and cannot prove anything about
  a *migrated* one. Expect the same in phase 4 and phase 6. Say in the handoff which failures are
  migration and which are the proof.
- **A rename breaks that proof unless you keep the harness bilingual.** Phase 2 renamed
  `SCSS_Compiler` to `Printer` and every test file failed against the old tree at once, which
  proves nothing and costs the suite its ability to report on any earlier checkout. Two moves fix
  it, both in `tests/bootstrap.php`: leave the old filename in the require list beside the new one
  (absent files are skipped by design), and `class_alias()` the old class to the new name when only
  the old one exists. Expect this again in phase 4, which deletes `include/integrations/`, and in
  phase 6.
- New behaviour gets a new `tests/test-*.php` following the existing bootstrap pattern. No
  PHPUnit, no new dev dependencies.
- After each phase, verify against the live install: `wp sassy status`,
  `wp sassy list --hooks=all`, and a real compile. The suite stubs WordPress; the live install is
  where discovery assumptions go to die.

**Before removing something, check its replacement exists.** Three capabilities were dropped this
way and had to come back: Clear Cache and the per-handle bar entries, justified by an admin page
that moved to 6b, and Force Recompile, justified by "Live Compile already skips cache", which the
code has never done. The pattern is always the same shape, a removal resting on a sentence about
some other phase, and it is invisible in review because the sentence reads as settled. When a
phase says X goes because Y covers it, open Y.

**The browser is not covered by the suite, and it is where the bugs are.** `tests/test-js.php`
boots the shipped JS under node and `tests/manual.md` is walked by a person; between them they are
all the coverage `assets/js/sassy.js` has. Nine of eleven findings in phase 6's review were in that
one file. A phase touching it owes both.

**Scope fences.**

- Never touch `scssphp-v2.x` or `main`.
- No composer or npm installs; no new runtime dependencies; `vendor/` is committed and stays as
  is.
- `docs/style-stack-plan.md` is the spec of record. If building reveals it is wrong, change it —
  but say so explicitly in the commit message. Silent spec drift is worse than a wrong spec.
- d-pace and lattice edits are permitted **only under `/var/www/dpace/staging/`**, and only for
  items in the §4 breaks table. Anything beyond the table becomes a written punch-list for Jamie,
  not an edit.
- Production (any path outside `/staging/`) is untouchable.

**Commits.** Subjects as terse and clear as possible, one concern per commit, `Co-Authored-By` as
established. A body is for a genuine gotcha, not a narration of the diff, and most commits need
none. The 2.x history on `scssphp-v2.x` shows the older, much longer style; do not copy it.

Comments in code are terse and reserved for real gotchas. Scrutinise every comment you touch,
including ones you did not write: is it obvious from the code, does it need to exist, can it be
shorter, who is it for?

**Where rationale lives.** This document and AGENTS.md, not above the method. Builder briefs in
`docs/briefs/` are a third home while a phase is live, and they accumulate real findings: phase 2's
brief carries the disposition of every member of the class it split, phase 3's carries what the
scssphp logger does when probed. **A landing phase graduates that into the plan or AGENTS.md**, or
it ends up in a document nobody reads again. Correct a brief that has been overtaken, or delete
the claim; a stale brief is worse than no brief, because it reads as current.

**When stuck or surprised.** If reality contradicts this plan — a discovery assumption fails, an
acceptance criterion is untestable, a d-pace breakage isn't in the table — stop and report rather
than improvising architecture. Improvised architecture is the architect's job to get wrong.
