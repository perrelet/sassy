<?php

namespace Sassy;

/**
 * Walks the @use / @forward / @import graph of an SCSS entry file so the compiler can
 * invalidate its cache when a partial changes, not just when the entry file does.
 *
 * Returns two sets:
 *
 * - deps:   files that were resolved and are compiled into the output. Recompile when any
 *           of these changes or disappears.
 * - misses: paths that were *tried and not found* while resolving, ahead of the candidate
 *           that won. Recompile if any of them appears, because it would shadow the file
 *           currently being used. Only candidates ahead of the winner can shadow it, so
 *           only those are recorded — in the common case (a bare @use resolving on the
 *           first candidate in the source directory) that set is empty.
 *
 * Over-inclusion is deliberately safe here: an extra tracked file costs at most one
 * unnecessary recompile, while a missed one serves stale CSS, which is the bug this
 * exists to fix.
 */
class Import_Scanner {

    /** @var int Hard ceiling on files visited, as a runaway guard. */
    const MAX_FILES = 1000;

    /**
     * Build the dependency set for an entry file.
     *
     * @param string $entry_path   Absolute path to the entry SCSS file.
     * @param array  $import_paths Load paths searched after the importing file's own directory.
     * @return array{deps: array<string, int>, misses: array<int, string>} deps map path => mtime.
     */
    public static function scan ($entry_path, array $import_paths = []) {

        $deps   = [];
        $misses = [];
        $queue  = [$entry_path];
        $seen   = [];

        while ($queue) {

            $file = array_shift($queue);

            if (isset($seen[$file])) continue;
            $seen[$file] = true;

            if (count($deps) >= self::MAX_FILES) break;
            if (!is_file($file)) continue;

            $deps[$file] = filemtime($file);

            $search_dirs = array_merge([dirname($file)], $import_paths);

            foreach (self::parse_rules(file_get_contents($file)) as $rule) {

                $found = self::resolve($rule, $search_dirs, $misses);

                foreach ($found as $path) {
                    if (!isset($seen[$path])) $queue[] = $path;
                }

            }

        }

        return [
            'deps'   => $deps,
            'misses' => array_values(array_unique($misses)),
        ];

    }

    /**
     * Extract import targets from SCSS source.
     *
     * Skips built-in modules (sass:*), remote URLs and plain-CSS imports, none of which
     * are files on disk we could stat.
     *
     * @param string $src SCSS source.
     * @return array<int, string> Import targets, as written.
     */
    protected static function parse_rules ($src) {

        // Drop comments so commented-out imports aren't tracked. The line-comment pattern
        // spares "https://" and friends by requiring the slashes not follow a colon.
        $src = preg_replace('~/\*.*?\*/~s', '', $src);
        $src = preg_replace('~(^|[^:])//[^\n]*~', '$1', $src);

        if (!preg_match_all('/@(use|forward|import)\s+([^;]+);/i', $src, $matches, PREG_SET_ORDER)) return [];

        $rules = [];

        foreach ($matches as $match) {

            $keyword = strtolower($match[1]);
            $clause  = $match[2];

            // url(...) is a plain CSS import, never a file we compile.
            if (stripos($clause, 'url(') !== false) continue;

            if (!preg_match_all('/["\']([^"\']+)["\']/', $clause, $strings)) continue;

            // @use and @forward take exactly one target; any later quoted string belongs to
            // a `with (...)` configuration. @import may list several.
            $targets = ($keyword === 'import') ? $strings[1] : [$strings[1][0]];

            foreach ($targets as $target) {

                $target = trim($target);

                if ($target === '')                                continue;
                if (stripos($target, 'sass:') === 0)               continue;   // built-in module
                if (preg_match('~^(?:[a-z][a-z0-9+.\-]*:)?//~i', $target)) continue;   // remote
                if (preg_match('/\.css$/i', $target))              continue;   // plain CSS

                $rules[] = $target;

            }

        }

        return $rules;

    }

    /**
     * Resolve one import target against the search path.
     *
     * Every candidate that exists is returned, not just the first: where Sass would treat
     * an ambiguous pair (foo.scss alongside _foo.scss) as an error, tracking both keeps us
     * correct without having to replicate its precedence rules exactly.
     *
     * @param string $rule        Import target as written.
     * @param array  $search_dirs Directories to search, in order.
     * @param array  $misses      Collects candidates tried ahead of the winner (by reference).
     * @return array<int, string> Resolved absolute paths.
     */
    protected static function resolve ($rule, array $search_dirs, array &$misses) {

        foreach ($search_dirs as $dir) {

            $found  = [];
            $tried  = [];

            foreach (self::candidates($rule, $dir) as $candidate) {

                if (is_file($candidate)) {
                    $found[] = $candidate;
                } else if (!$found) {
                    $tried[] = $candidate;
                }

            }

            if ($found) {
                // Only candidates ahead of the winner can shadow it. Later directories
                // cannot, so stop here rather than recording the rest of the search path.
                foreach ($tried as $path) $misses[] = $path;
                return $found;
            }

            foreach ($tried as $path) $misses[] = $path;

        }

        return [];

    }

    /**
     * Candidate filenames for an import target within one directory, in Sass's search order.
     *
     * @param string $rule Import target as written.
     * @param string $dir  Directory to search.
     * @return array<int, string>
     */
    protected static function candidates ($rule, $dir) {

        $base    = rtrim($dir, '/\\') . '/' . ltrim($rule, '/');
        $dirname = dirname($base);
        $name    = basename($base);

        $candidates = [];

        if (preg_match('/\.(scss|sass)$/i', $name)) {

            $candidates[] = "{$dirname}/_{$name}";
            $candidates[] = "{$dirname}/{$name}";

        } else {

            foreach (['scss', 'sass'] as $ext) {
                $candidates[] = "{$dirname}/_{$name}.{$ext}";
                $candidates[] = "{$dirname}/{$name}.{$ext}";
            }

            foreach (['scss', 'sass'] as $ext) {
                $candidates[] = "{$dirname}/{$name}/_index.{$ext}";
                $candidates[] = "{$dirname}/{$name}/index.{$ext}";
            }

        }

        return $candidates;

    }

}
