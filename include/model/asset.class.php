<?php

namespace Sassy;

/**
 * One enqueued thing -- a value object over a _WP_Dependency.
 *
 * source_path resolves lazily because sassy-src-path receives the Asset, which cannot be
 * handed to a filter from a constructor that has not returned yet.
 */
class Asset {

    /** @var string[] Extensions Sassy can build. Indented syntax is deliberately excluded; see plan §6. */
    const COMPILABLE = ['scss'];

    /** @var string Enqueue handle. */
    public $handle;

    /** @var string 'style'. Scripts arrive in phase 8. */
    public $type;

    /** @var string|false Source as registered; false for dependency-only handles. */
    public $src;

    /** @var string[] WordPress handle dependencies. */
    public $deps;

    /** @var string|null Lowercased extension of the source path, if any. */
    public $extension;

    protected $source_path;
    protected $source_path_resolved = false;

    public function __construct ($handle, $src, array $deps = [], $type = 'style') {

        $this->handle    = $handle;
        $this->type      = $type;
        $this->src       = $src;
        $this->deps      = $deps;
        $this->extension = static::extension_of($src);

    }

    /**
     * Build from a _WP_Dependency as registered in a WP_Styles queue.
     */
    public static function from_dependency ($dependency, $type = 'style') {

        return new static(
            $dependency->handle,
            $dependency->src,
            is_array($dependency->deps) ? $dependency->deps : [],
            $type
        );

    }

    /**
     * Filesystem path of the source, or null when the URL maps nowhere.
     *
     * Existence is a separate question: a local URL resolves whether or not the file is there,
     * so callers can name the path they looked at. sassy-src-path applies either way -- it is
     * the only way to place an asset Sassy cannot resolve on its own.
     */
    public function get_source_path () {

        if (!$this->source_path_resolved) {

            $this->source_path_resolved = true;
            $this->source_path = apply_filters('sassy-src-path', static::resolve($this->src), $this->src, $this->handle, $this);

        }

        return $this->source_path;

    }

    /**
     * Whether Sassy has a filesystem path for this asset.
     */
    public function is_local () {

        return $this->get_source_path() !== null;

    }

    public function is_compilable () {

        return in_array($this->extension, static::COMPILABLE, true) && $this->is_local();

    }

    /**
     * URL (or root-relative path) to a filesystem path. Null when it maps nowhere.
     */
    protected static function resolve ($src) {

        if (!is_string($src) || ($src === '')) return null;

        $parts = parse_url($src);
        if (empty($parts['path'])) return null;

        if (!empty($parts['host'])) {

            // Compare host only. WP-CLI makes no HTTPS request, so plugin_dir_url() can yield
            // http:// against an https:// siteurl and a scheme-sensitive test calls our own
            // stylesheet remote.
            if (strcasecmp($parts['host'], (string) parse_url(site_url(), PHP_URL_HOST)) !== 0) return null;

        } else if (substr($src, 0, 1) !== '/') {

            return null;

        }

        $path = ABSPATH . ltrim($parts['path'], '/');

        // Only when it names a real file: DOCUMENT_ROOT is unset under WP-CLI, and swapping
        // unconditionally would report a path with no root on it.
        if (!file_exists($path) && !empty($_SERVER['DOCUMENT_ROOT'])) {
            $alternate = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $parts['path'];
            if (file_exists($alternate)) $path = $alternate;
        }

        if (is_multisite()) {
            $blog_path = get_blog_details()->path;
            if ($blog_path != PATH_CURRENT_SITE) $path = str_replace($blog_path, PATH_CURRENT_SITE, $path);
        }

        return $path;

    }

    protected static function extension_of ($src) {

        if (!is_string($src) || ($src === '')) return null;

        $path = parse_url($src, PHP_URL_PATH);
        if (!$path) return null;

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return ($extension !== '') ? strtolower($extension) : null;

    }

}
