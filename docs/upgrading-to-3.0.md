# Upgrading to Sassy 3.0

What changes for a site running Sassy, and what to do about it.

**In progress.** 3.0 ships phases 1-6 of [style-stack-plan.md](style-stack-plan.md), and each phase adds its breaks here as it lands. Anything not listed below has not changed yet. Landed so far: **phases 1 and 2**.

Nothing on 1.x is auto-updated to 3.0. The digitalis.ca update JSON is version-fenced before 3.0 publishes, so a wild 1.x install is never offered a breaking upgrade.

---

## Phase 1: the style stack

Sassy now models every registered stylesheet, not only the ones whose URL ends in `.scss`. On the reference install that is 337 handles instead of 1.

### `$digitalis_styles` is no longer read

**Who this affects:** sites where the Digitalis framework (or anything else) registered styles into a second `WP_Styles` registry held in the `$digitalis_styles` global. Sassy used to merge that registry with `wp_styles()->registered` before looking for SCSS.

**What happens if you do nothing:** styles registered *only* in that second registry stop being discovered. They will not compile and will not appear in `wp sassy list`.

**What to do:** register the queue through the new filter.

```php
add_filter('sassy-style-queues', function ($queues) {

    global $digitalis_styles;
    if ($digitalis_styles) $queues[] = $digitalis_styles;

    return $queues;

});
```

Queues are read in order and a later one wins on a duplicate handle, matching the old hard-coded `array_merge`.

**If your site uses Lattice's `Theme::enqueue_style_last()`, this is you.** That method populates `$digitalis_styles`. It is unchanged and still enqueues your styles (WordPress prints them either way); it is Sassy that stops looking in there, so anything registered through it needs the filter above.

The intended fix is for Lattice to register the filter once on behalf of every site that calls it, rather than each site adding the snippet. Until it does, the snippet is the workaround.

### `Sassy::get_scss_styles()` is removed

**Who this affects:** any code calling it directly.

**What to do:** use `Style_Stack`.

```php
$stack = Sassy\Style_Stack::discover(['frontend']);   // [] reads the queues as they stand

$stack->all();          // every registered style, as Assets keyed by handle
$stack->compilable();   // just the ones Sassy builds
$stack->handle('my-theme');
```

An `Asset` carries `handle`, `type`, `src`, `deps` (the WordPress handle dependencies, which Sassy previously discarded), `extension` and `get_source_path()`.

### `sassy-src-path` runs in more cases, and its last argument changed

**Who this affects:** anyone filtering `sassy-src-path`.

- **It now runs even when Sassy could not resolve the URL**, receiving `null` as the value. In 2.x it ran only after resolution had already succeeded, so the one filter documented as "override the source path" could not rescue the case that most needed it: a CDN-hosted or otherwise unresolvable URL. Returning a path from `null` places the asset; returning `null` leaves it unresolved.
- **Its fourth argument is now the `Asset`**, not the `SCSS_Compiler`. Callbacks that ignore the argument (most do) need no change.

### Root-relative sources now resolve

**Who this affects:** nobody who has to act, but the behaviour is visibly different.

A style registered as `/wp-admin/css/common.min.css` (no scheme, no host) used to be treated as remote and unresolvable. It now resolves against `ABSPATH`. This is why 65 of the reference install's core stylesheets went from invisible to modeled.

Relatedly, `source_path` is `null` only when the URL **maps nowhere**: a remote host, a hostless src that is not root-relative, or `src === false`. A missing file still resolves, so an error can name the path it looked at instead of echoing the URL back.

### `wp sassy list` reports everything

**Who this affects:** anything parsing its output.

- It lists **every** discovered handle, not only the buildable ones. `--compilable` restores the old narrowing.
- New columns `type` and `imports`.
- **`deps` changed meaning.** It was the count of files in the recorded import graph; it is now the WordPress handle dependency list, as an array under `--format=json` and `--format=yaml`, comma-joined for `table` and `csv`. The old meaning is now `imports`.
- Rows for handles Sassy does not build carry identity and `source` only. `state`, `engine`, `time`, `imports` and `built` are empty for them.

Asking to compile a handle that cannot be now says why: unregistered, registers no source of its own, not a local file, or not compilable with the extension named.

---

## Phase 2: one service, thin surfaces

`SCSS_Compiler` held five responsibilities and every surface reached into it for a different one. It is now four classes.

### `SCSS_Compiler` is renamed `Printer`

**Who this affects:** anything type-hinting it, instantiating it, or reading it off `SASSY()`.

| Before | After |
|---|---|
| `Sassy\SCSS_Compiler` | `Sassy\Printer` |
| `SASSY()->get_compilers()` | `SASSY()->get_printers()`, keyed by a 1-based index |
| `$compiler->get_index()` | the array key in `get_printers()` |
| `SCSS_Compiler::prepend_variables()` | `Variable_Resolver::prepend()` |
| `SCSS_Compiler::temp_file()` | gone; each backend owns its own |

Path math moved to `Build_Target`, currency to `Compile_Cache`, values to `Variable_Resolver`. `Printer` still exposes `get_build_file()`, `get_build_url()`, `get_variables()`, `is_current()` and the rest, so code that only reads those is unaffected.

### The fourth filter argument is now the `Asset`

**Who this affects:** any callback that uses the fourth argument of a per-compile filter. Callbacks that ignore it, which is most, need no change.

Every per-compile filter (`sassy-build-*`, `sassy-variables`, `sassy-style`, `sassy-import-paths`, `sassy-src-map`, `sassy-css`, `sassy-force-compile`, `sassy-check-dependencies` and the rest) now receives `($value, $src, $handle, $asset)` instead of the compiler. `sassy-engine` receives `($engine, $asset)`.

The `Asset` is available before a compile starts and carries no build state, so a filter can no longer reach through it into paths or the cache. Ask `Build_Target` or `Compile_Cache` for those.

### Cache transients are owned by `Compile_Cache`

**Who this affects:** anything reading `sassy-filemtimes-{handle}` or `sassy-vars-sig-{handle}` directly.

The keys are unchanged, but treat them as private. `Compile_Cache::get_graph($handle)`, `::get_last_compile_time($handle)`, `::forget_handle($handle)` and `::forget_all()` are the supported way in.
