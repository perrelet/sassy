<?php

namespace Sassy;

/**
 * Resolves a Sass import target against a search path.
 */
class Import_Resolver {

    /**
     * @return array{found: array<int, string>, misses: array<int, string>}
     */
    public static function resolve ($rule, array $search_dirs) {

        $misses = [];

        foreach ($search_dirs as $dir) {

            $found = [];
            $tried = [];

            foreach (static::candidates($rule, $dir) as $candidate) {

                // Every match is returned, not just the first: Sass treats an ambiguous pair
                // (foo.scss beside _foo.scss) as an error, so tracking both keeps us correct
                // without replicating its precedence rules.
                if (is_file($candidate)) {
                    $found[] = $candidate;
                } else if (!$found) {
                    $tried[] = $candidate;
                }

            }

            $misses = array_merge($misses, $tried);

            // Only candidates ahead of the winner can shadow it, so later directories are
            // not recorded as misses.
            if ($found) return ['found' => $found, 'misses' => $misses];

        }

        return ['found' => [], 'misses' => $misses];

    }

    /**
     * Whether a target is a file on disk rather than a built-in module, remote URL or plain CSS.
     */
    public static function is_resolvable ($target) {

        if ($target === '')                                        return false;
        if (stripos($target, 'sass:') === 0)                       return false;
        if (preg_match('~^(?:[a-z][a-z0-9+.\-]*:)?//~i', $target)) return false;
        if (preg_match('/\.css$/i', $target))                      return false;

        return true;

    }

    protected static function candidates ($rule, $dir) {

        $base    = rtrim($dir, '/\\') . '/' . ltrim($rule, '/');
        $dirname = dirname($base);
        $name    = basename($base);

        if (preg_match('/\.(scss|sass)$/i', $name)) return ["{$dirname}/_{$name}", "{$dirname}/{$name}"];

        $candidates = [];

        foreach (['scss', 'sass'] as $ext) {
            $candidates[] = "{$dirname}/_{$name}.{$ext}";
            $candidates[] = "{$dirname}/{$name}.{$ext}";
        }

        foreach (['scss', 'sass'] as $ext) {
            $candidates[] = "{$dirname}/{$name}/_index.{$ext}";
            $candidates[] = "{$dirname}/{$name}/index.{$ext}";
        }

        return $candidates;

    }

}
