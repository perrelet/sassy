# Upgrading to Sassy 3.0

What changes for a site running Sassy, and what to do about it. **In progress** — 3.0 ships
phases 1–6 of [style-stack-plan.md](style-stack-plan.md), and each phase adds its breaks here as
it lands. Anything not listed below has not changed yet.

Nothing on 1.x is auto-updated to 3.0: the digitalis.ca update JSON is version-fenced before 3.0
publishes, so a wild 1.x install is never offered a breaking upgrade.

Landed so far: **phase 1**.

---

## Phase 1 — the style stack

Sassy now models every registered stylesheet rather than only the ones whose URL ends in `.scss`.
On the reference install that is 337 handles instead of 1.

### `$digitalis_styles` is no longer read

**Who this affects:** sites where the Digitalis framework (or anything else) registered styles
into a second `WP_Styles` registry held in the `$digitalis_styles` global. Sassy used to merge
that registry with `wp_styles()->registered` before looking for SCSS.

**What happens if you do nothing:** styles registered *only* in that second registry stop being
discovered — they will not compile, and they will not appear in `wp sassy list`.

**What to do:** register the queue through the new filter.

```php
add_filter('sassy-style-queues', function ($queues) {
    global $digitalis_styles;
    if ($digitalis_styles) $queues[] = $digitalis_styles;
    return $queues;
});
```

Queues are read in order and a later one wins on a duplicate handle, which is the behaviour the
old hard-coded `array_merge` had.

**On the reference install this was already dead.** The only producer was Lattice's
`Theme::enqueue_style_last()`, which had no callers anywhere under `wp-content/` — an Oxygen-era
workaround that outlived its reason. It has been deleted from Lattice rather than carried
forward. If your site calls `enqueue_style_last()`, use `wp_enqueue_style()` and the filter
above.

### `Sassy::get_scss_styles()` is removed

**Who this affects:** any code calling it directly. Nothing in d-pace or Lattice did.

**What to do:** use `Style_Stack`.

```php
$stack = Sassy\Style_Stack::discover(['frontend']);   // or [] to read the queues as they stand

$stack->all();          // every registered style, as Asset objects keyed by handle
$stack->compilable();   // just the ones Sassy builds
$stack->handle('my-theme');
```

An `Asset` carries `handle`, `type`, `src`, `deps` (the WordPress handle dependencies, which
Sassy previously discarded), `extension`, and `get_source_path()`.

### `sassy-src-path` runs in more cases, and its last argument changed

**Who this affects:** anyone filtering `sassy-src-path`.

Two changes:

- **It now runs even when Sassy could not resolve the URL**, receiving `null` as the value. In
  2.x it ran only after resolution had already succeeded, so the one filter documented as
  "override the source path" could not rescue the case that most needed it — a CDN-hosted or
  otherwise unresolvable URL. Returning a path from `null` now places the asset; returning `null`
  leaves it unresolved.
- **Its fourth argument is now the `Asset`**, not the `SCSS_Compiler`. Callbacks that ignore the
  argument — most do — need no change.

### Root-relative sources now resolve

**Who this affects:** nobody who has to act, but the behaviour is visibly different.

A style registered as `/wp-admin/css/common.min.css` — no scheme, no host — used to be treated as
remote and unresolvable. It now resolves against `ABSPATH`. This is why 65 of the reference
install's core stylesheets went from invisible to modeled.

Relatedly, `source_path` is `null` only when the URL **maps nowhere** — a remote host, a hostless
src that is not root-relative, or `src === false`. It is *not* null merely because the file is
missing: a local URL resolves whether or not anything is there, so an error can name the path it
looked at instead of echoing the URL back.

### `wp sassy list` reports everything

**Who this affects:** anything parsing its output.

- It lists **every** discovered handle, not only the buildable ones. `--compilable` restores the
  old narrowing.
- New columns `type` and `imports`.
- **`deps` changed meaning.** It was the count of files in the recorded import graph; it is now
  the WordPress handle dependency list — an array under `--format=json` and `--format=yaml`,
  comma-joined for `table` and `csv`. The old meaning is now `imports`.
- Rows for handles Sassy does not build carry identity and `source` only; `state`, `engine`,
  `time`, `imports` and `built` are empty for them.

Asking to compile a handle that cannot be now says why — unregistered, registers no source of its
own, not a local file, or not compilable with the extension named.
