<?php

namespace Sassy;

use ScssPhp\ScssPhp\OutputStyle;

/**
 * Orchestrates SCSS compilation for a single source: resolves paths, manages cache,
 * delegates to a Compiler_Engine, and writes CSS (and optional source map).
 *
 * Engine is selected via the sassy-engine filter (default: Scssphp_Engine).
 */
class SCSS_Compiler {

    /** @var int Instance counter for admin/UI. */
    static $count = 0;

    protected $engine;

    protected $index     = null;
    protected $src       = null;
    protected $handle    = null;
    protected $style     = OutputStyle::EXPANDED;
    protected $variables = null;

    protected $build_dir;
    protected $build_path;
    protected $build_url;
    protected $build_name;
    protected $build_file;
    protected $src_path;
    protected $src_map_options;
    protected $import_paths;

    protected $compiled;
    protected $error;
    protected $src_map;
    protected $warnings = [];
    protected $compile_time = null;

    public function __construct () {

        $this->index = ++static::$count;

    }

    /**
     * Reset build state and cache for a new compile run.
     */
    public function init () {

        $this->build_dir        = null;
        $this->build_path       = null;
        $this->build_url        = null;
        $this->build_name       = null;
        $this->build_file       = null;
        $this->src_path         = null;
        $this->src_map_options  = null;
        $this->import_paths     = null;

        $this->compiled = false;
        $this->error    = false;
        $this->src_map  = false;
        $this->warnings = [];
        $this->compile_time = null;

    }

    /**
     * Lazily resolve and cache the compiler engine (filterable via sassy-engine).
     *
     * @return Compiler_Engine
     */
    protected function get_engine () : Compiler_Engine {

        if ($this->engine !== null) {
            return $this->engine;
        }

        $engine = apply_filters('sassy-engine', null, $this);

        if ($engine instanceof Compiler_Engine) {
            $this->engine = $engine;
            return $this->engine;
        }

        $this->engine = new Scssphp_Engine();
        return $this->engine;

    }

    /**
     * Compile the given SCSS source and return the URL to the built CSS.
     *
     * @param string $src   URL of the SCSS file.
     * @param string $handle Enqueue handle (used for cache keys).
     * @return string URL of the compiled CSS (or source URL on error).
     */
    public function compile ($src, $handle) {

        $this->init();
        $this->src    = $src;
        $this->handle = $handle;

        $src_path  = $this->get_src_path();
        $parse_src = parse_url($this->src);

        if (!file_exists($src_path)) {
            $this->error('Source file not found: ' . $src_path);
            return $this->src;
        }

        $build_path = $this->get_build_path();
        $build_url  = $this->get_build_url();
        $build_file = $this->get_build_file();
        $variables  = $this->get_variables();

        $run = $this->should_compile($build_file, $src_path, $variables);

        if ($run && !$this->ensure_build_directory($build_path)) {
            return $this->get_build_url();
        }

        if ($run) {
            $start = microtime(true);

            $args   = $this->build_compile_args($src_path, $variables);
            $result = $this->get_engine()->compile($args);

            $this->compile_time = microtime(true) - $start;

            if (!$result->ok()) {
                $this->error('A compiler error occurred: ' . $result->error);
                return $this->get_build_url();
            }

            $this->warnings = [];
            if (isset($result->info) && $result->info !== null) {
                if (is_array($result->info)) {
                    $this->warnings = $result->info;
                } else {
                    $this->warnings = [$result->info];
                }
            }

            $this->src_map = !empty($args['source_map']);
            $css = $result->css;

            $css = preg_replace('#(url\((?![\'"]?(?:[a-z][a-z0-9+.\-]*:|/|\#))[\'"]?)#miu', '$1' . dirname($parse_src['path']) . '/', $css);
            $css = apply_filters('sassy-css', $css, $this->src, $this->handle, $this);

            file_put_contents($build_file, $css);
            if ($result->map !== null) {
                $map_path = $this->get_src_map_options()['sourceMapWriteTo'] ?? null;
                if ($map_path) {
                    file_put_contents($map_path, $result->map);
                }
            }

            $scan = Import_Scanner::scan($src_path, $this->get_import_paths($src_path));

            set_transient('sassy-filemtimes-' . $this->handle, [
                $build_file        => filemtime($build_file),
                '__compile_time__' => $this->compile_time,
                'deps'             => $scan['deps'],
                'misses'           => $scan['misses'],
            ]);

            set_transient('sassy-vars-sig-' . $this->handle, sha1(serialize($variables)));

            $this->compiled = true;
        } else {
            $this->compile_time = 0.0;
        }

        $output = $build_url;
        if (!empty($parse_src['query'])) {
            $output .= '?' . $parse_src['query'];
        }
        return $output;
    }

    /**
     * Whether we need to run compilation (cache invalid or missing).
     *
     * @param string $build_file Path to built CSS file.
     * @param string $src_path   Path to source SCSS file.
     * @param array  $variables  Current variables (signature used for cache).
     * @return bool
     */
    protected function should_compile ($build_file, $src_path, array $variables) {

        $run = apply_filters('sassy-force-compile', false, $this->src, $this->handle, $this);

        if (!$run) {
            $filemtimes = get_transient('sassy-filemtimes-' . $this->handle);
            if ($filemtimes === false) {
                $filemtimes = [];
            }
            if (!isset($filemtimes[$build_file]) || $this->dependencies_changed($filemtimes, $src_path)) {
                $run = true;
            }
        }

        if (!$run) {
            // Deliberately not written here. Recording the signature before compiling meant a
            // failed compile was remembered as current: the error vanished on the next request
            // and the stale build file was served on. It is written once the compile succeeds.
            if (sha1(serialize($variables)) !== get_transient('sassy-vars-sig-' . $this->handle)) {
                $run = true;
            }
        }

        if (!$run && !file_exists($build_file)) {
            $run = true;
        }

        return $run;
    }

    /**
     * Whether any file the last build was compiled from has changed.
     *
     * Covers the whole @use / @forward / @import graph, so editing a partial invalidates
     * the cache without having to touch the entry file. The graph itself only needs
     * rebuilding when a compile happens: adding an import necessarily changes the mtime of
     * the file that declares it, so a plain stat of the known set is enough in between.
     *
     * @param array  $cache    Stored filemtimes transient.
     * @param string $src_path Current entry file.
     * @return bool
     */
    protected function dependencies_changed (array $cache, $src_path) {

        // No graph recorded yet, or the entry file itself has moved.
        if (empty($cache['deps']))                 return true;
        if (!isset($cache['deps'][$src_path]))     return true;

        foreach ($cache['deps'] as $path => $mtime) {
            if (!is_file($path) || filemtime($path) != $mtime) return true;
        }

        // A file appearing at a path we searched and did not find would shadow whichever
        // candidate won, changing what the next compile resolves to.
        foreach (($cache['misses'] ?? []) as $path) {
            if (file_exists($path)) return true;
        }

        return false;

    }

    /**
     * Ensure build directory exists and is writable. Sets error state on failure.
     *
     * @param string $build_path Directory path.
     * @return bool True if ready, false on error.
     */
    protected function ensure_build_directory ($build_path) {

        if (!is_dir($build_path)) {
            if (!wp_mkdir_p($build_path)) {
                $this->error('File Permissions Error, unable to create cache directory: ' . $build_path);
                delete_transient('sassy-filemtimes-' . $this->handle);
                return false;
            }
        }

        if (!is_writable($build_path)) {
            $this->error('File Permissions Error, permission denied. Please make the directory writable: ' . $build_path);
            delete_transient('sassy-filemtimes-' . $this->handle);
            return false;
        }

        return true;
    }

    /**
     * Build the args array passed to the compiler engine.
     *
     * @param string $src_path  Path to the main SCSS file.
     * @param array  $variables Sass variables (key => string expression).
     * @return array
     */
    protected function build_compile_args ($src_path, array $variables) {

        return [
            'scss'               => file_get_contents($src_path),
            'src_path'           => $src_path,
            'import_paths'       => $this->get_import_paths($src_path),
            'variables'          => $variables,
            'style'              => $this->get_style(),
            'source_map'         => apply_filters('sassy-src-map', true, $this->src, $this->handle, $this),
            'source_map_options' => $this->get_src_map_options(),
        ];
    }
    
    /**
     * Record an error message for this compile run.
     *
     * @param string $e Error message.
     */
    protected function error ($e) {

        $this->error = $e;

    }

    public function has_compiled () {

        return $this->compiled;

    }

    public function has_error () {

        return $this->error ? true : false;

    }

    public function get_error () {

        return $this->error;

    }

    public function get_warnings () {

        return $this->warnings;

    }

    public function get_compile_time () {

        return $this->compile_time;

    }

    public function get_last_compile_time () {

        if ($this->compile_time > 0) return $this->compile_time;

        $filemtimes = get_transient('sassy-filemtimes-' . $this->handle);
        return $filemtimes['__compile_time__'] ?? null;

    }

    public function has_src_map () {

        if ($this->src_map) return true;

        // On cache hits src_map is never set; check whether the map file exists.
        $options = $this->get_src_map_options();
        return isset($options['sourceMapWriteTo']) && file_exists($options['sourceMapWriteTo']);

    }

    public function get_index () {

        return $this->index;

    }

    public function get_src () {

        return $this->src;

    }

    public function get_handle () {

        return $this->handle;

    }

    public function get_src_path () {

        if (is_null($this->src_path)) {

            // Compare host + path of the source URL against site_url(), scheme-agnostically. WP-CLI runs without HTTPS request context can yield plugin_dir_url()-derived URLs starting `http://` while the option-stored siteurl is `https://`; a strict prefix-strip of site_url() would fail and we'd treat our own SCSS as remote.
            $url_parts  = parse_url($this->src);
            $site_host  = parse_url(site_url(), PHP_URL_HOST);

            // Reject CDNs / other domains / relative-or-malformed URLs.
            if (empty($url_parts['host']) || empty($url_parts['path'])) return $this->src;
            if (strcasecmp($url_parts['host'], $site_host) !== 0)       return $this->src;

            $path = ABSPATH . ltrim($url_parts['path'], '/');
            if (!file_exists($path)) {
                $path = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $url_parts['path'];
            }

            // If it is part of a multi-site then the 'domain' must be removed
            if (is_multisite()) {
                $blog_details_path = get_blog_details()->path;
                if ($blog_details_path != PATH_CURRENT_SITE) $path = str_replace($blog_details_path, PATH_CURRENT_SITE, $path);
            }

            $this->src_path = apply_filters('sassy-src-path', $path, $this->src, $this->handle, $this);

        }

        return $this->src_path;

    }

    /**
     * Filesystem paths searched by @use / @forward / @import.
     *
     * Resolved separately from build_compile_args() because the cache check needs them
     * too — the import graph has to be walked before we know whether to compile at all.
     *
     * @param string|null $src_path Entry file, defaulting to the resolved source path.
     * @return array
     */
    public function get_import_paths ($src_path = null) {

        if (is_null($this->import_paths)) {

            $src_path = $src_path ?: $this->get_src_path();

            $import_paths = [dirname($src_path), SASSY_PATH];
            if (defined('DIGITALIS_FRAMEWORK_PATH')) {
                $import_paths[] = DIGITALIS_FRAMEWORK_PATH;
            }

            $this->import_paths = apply_filters('sassy-import-paths', $import_paths, $src_path, $this->handle, $this);

        }

        return $this->import_paths;

    }

    public function get_src_map_options () {

        if (is_null($this->src_map_options)) {

            $this->src_map_options = apply_filters('sassy-src-map-options', [
                'sourceMapWriteTo'    => str_replace('\\', '/', $this->get_build_path()) . $this->get_build_name() . '.map',    // Absolute path where the .map file will be written
                'sourceMapURL'        => $this->get_build_url() . '.map',                                                        // Full or relative URL to archive .map
                'sourceMapBasepath'   => rtrim(str_replace('\\', '/', ABSPATH), '/'),                                            // Configures the base path to replace (for instance C:/www/domain/wp-content/themes/theme-name/classes/../scss/ or C:/www/domain/wp-content/ in your cases (notice that we have a weird thing where this options must use / instead of \ on Windows) (https://github.com/scssphp/scssphp/issues/35) // ? - Partial path (server root) to create the relative URL
                'sourceMapFilename'   => $this->get_build_url(),                                                                // (Optional) Full or relative URL to compiled .css file
                'sourceMapRootpath'   => trailingslashit(site_url()),                                    
                //'sourceRoot'        => $this->src,                                                                            // (Optional) Prepend the 'source' field entries to relocate source files
            ], $this->src, $this->handle, $this);

        }

        return $this->src_map_options;

    }

    public function get_src_url () {

        return isset($this->get_src_map_options()['sourceMapURL']) ? $this->get_src_map_options()['sourceMapURL'] : null;

    }

    public function get_build_directory () {

        if (is_null($this->build_dir)) {

            $suffix = is_multisite() ? get_current_blog_id() . '/' : '';
            $this->build_dir = apply_filters('sassy-build-directory', '/scss/' . $suffix, $this->src, $this->handle, $this);

        }

        return $this->build_dir;
        
    }
    
    public function get_build_path () {
        
        if (is_null($this->build_path)) $this->build_path = apply_filters('sassy-build-path', WP_CONTENT_DIR, $this->src, $this->handle, $this) . $this->get_build_directory();

        return $this->build_path;
        
    }
    
    public function get_build_url () {
        
        if (is_null($this->build_url)) $this->build_url = apply_filters('sassy-build-url', WP_CONTENT_URL, $this->src, $this->handle, $this) . $this->get_build_directory() . $this->get_build_name();

        return $this->build_url;
        
    }
    
    public function get_build_name () {
        
        if (is_null($this->build_name)) {

            $parts         = explode('?', $this->src);
            $name         = basename($parts[0], '.scss');
            $build_name = "{$name}.css";

            $this->build_name = apply_filters('sassy-build-name', $build_name, $this->src, $this->handle, $this);

        }

        return $this->build_name;
        
    }

    public function get_build_file () {

        if (is_null($this->build_file)) $this->build_file = $this->get_build_path() . $this->get_build_name();

        return $this->build_file;

    }

    public function get_engine_class () {

        return get_class($this->get_engine());

    }
    
    public function get_style () {
        
        return apply_filters('sassy-style', $this->style, $this->src, $this->handle, $this);
        
    }
    
    public function get_variables () {
        
        if (is_null($this->variables)) {

            $variables = [
                'wp-content-url'           => '"'. wp_normalize_path(WP_CONTENT_URL) . '"',
                'template-directory-url'   => '"'. wp_normalize_path(get_template_directory_uri()) . '"',
                'stylesheet-directory-url' => '"'. wp_normalize_path(get_stylesheet_directory_uri()) . '"',
            ];

            $this->variables = apply_filters('sassy-variables', $variables, $this->src, $this->handle, $this);

            foreach ($this->variables as $key => $value) {
                if (is_array($value)) {
                    $this->variables[$key] = Scss_Map::from_array($value);
                }
            }

        }

        return $this->variables;
        
    }

    /**
     * Prepend SCSS variable declarations to the given source.
     * Used by engines that inject variables via SCSS content (e.g. Dart Sass CLI).
     *
     * @param string $scss SCSS source.
     * @param array<string, string> $variables Variable name => Sass expression (e.g. quoted string).
     * @return string SCSS with variables prepended.
     */
    public static function prepend_variables ($scss, array $variables) {

        if (!$variables) {
            return $scss;
        }
        $lines = [];
        foreach ($variables as $key => $value) {
            $lines[] = '$' . $key . ': ' . $value . ';';
        }
        return implode("\n", $lines) . "\n\n" . $scss;

    }

    /**
     * Create a unique temporary file.
     *
     * Deliberately avoids wp_tempnam(), which lives in wp-admin/includes/file.php and is
     * not loaded on frontend requests — calling it there is a fatal error. get_temp_dir()
     * is in wp-includes/functions.php and is always available.
     *
     * @param string $prefix Filename prefix.
     * @return string|false Absolute path to the created file, or false on failure.
     */
    public static function temp_file ($prefix = 'sassy-') {

        return tempnam(get_temp_dir(), $prefix);

    }

}
