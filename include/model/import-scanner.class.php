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

        $deps  = [];
        $dirs  = [];
        $queue = [$entry_path];
        $seen  = [];

        while ($queue) {

            $file = array_shift($queue);

            if (isset($seen[$file])) continue;
            $seen[$file] = true;

            if (count($deps) >= self::MAX_FILES) break;
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

        foreach ($dirs as $dir => $_) {
            $dirs[$dir] = is_dir($dir) ? filemtime($dir) : 0;
        }

        return new Import_Graph($deps, $dirs);

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
