<?php

namespace Sassy;

/**
 * Walks the @use / @forward / @import graph from an entry file.
 *
 * Over-inclusion is safe here and under-inclusion is not: a spurious entry costs one
 * unnecessary recompile, a missing one serves stale CSS.
 */
class Import_Scanner {

    const MAX_FILES = 1000;

    public static function scan ($entry_path, array $import_paths = []) {

        $deps      = [];
        $dirs      = [];
        $queue     = [$entry_path];
        $seen      = [];
        $truncated = false;

        while ($queue) {

            $file = array_shift($queue);

            if (isset($seen[$file])) continue;
            $seen[$file] = true;

            if (count($deps) >= self::MAX_FILES) {
                $truncated = true;
                break;
            }

            if (!is_file($file)) continue;

            $deps[$file] = filemtime($file);

            $search_dirs = array_merge([dirname($file)], $import_paths);

            foreach (static::parse_rules(file_get_contents($file)) as $rule) {

                $result = Import_Resolver::resolve($rule, $search_dirs);

                foreach ($result['dirs'] as $dir) $dirs[$dir] = true;

                foreach ($result['found'] as $path) {
                    if (!isset($seen[$path])) $queue[] = $path;
                }

            }

        }

        $watch = [];

        foreach ($dirs as $dir => $_) {
            if (static::holds_sass($dir)) $watch[$dir] = filemtime($dir);
        }

        return new Import_Graph($deps, $watch, $truncated);

    }

    /**
     * Whether a directory is somewhere a shadowing partial could plausibly appear.
     *
     * Load-path roots that hold no Sass at all — the plugin directory, a framework root — are
     * skipped. They churn for unrelated reasons, and every such churn would invalidate every
     * handle on the site. A directory appearing is still caught: creating it moves its parent's
     * mtime, and the entry file's own directory is always watched.
     */
    protected static function holds_sass ($dir) {

        if (!is_dir($dir)) return false;

        return (bool) (glob($dir . '/*.scss') ?: glob($dir . '/*.sass'));

    }

    /**
     * @return array<int, string> Import targets, as written.
     */
    protected static function parse_rules ($src) {

        $src = preg_replace('~/\*.*?\*/~s', '', $src);
        $src = preg_replace('~(^|[^:])//[^\n]*~', '$1', $src);   // [^:] spares https://

        if (!preg_match_all('/@(use|forward|import)\s+([^;]+);/i', $src, $matches, PREG_SET_ORDER)) return [];

        $rules = [];

        foreach ($matches as [, $keyword, $clause]) {

            if (stripos($clause, 'url(') !== false) continue;
            if (!preg_match_all('/["\']([^"\']+)["\']/', $clause, $strings)) continue;

            // @import takes a list; @use and @forward take one, and any further quoted string
            // belongs to a with (...) configuration.
            $targets = (strtolower($keyword) === 'import') ? $strings[1] : [$strings[1][0]];

            foreach ($targets as $target) {
                $target = trim($target);
                if (Import_Resolver::is_resolvable($target)) $rules[] = $target;
            }

        }

        return $rules;

    }

}
