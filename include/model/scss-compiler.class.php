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

    protected $src_path;
    protected $asset;
    protected $target;
    protected $resolver;
    protected $cache;
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

        $this->src_path         = null;
        $this->asset            = null;
        $this->target           = null;
        $this->resolver         = null;
        $this->cache            = null;
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
     * Point this compiler at a source without compiling, so paths, variables and the
     * recorded import graph can be inspected.
     *
     * @return $this
     */
    public function prepare ($src, $handle) {

        $this->init();
        $this->src    = $src;
        $this->handle = $handle;

        return $this;

    }

    /**
     * Compile the given SCSS source and return the URL to the built CSS.
     *
     * @param string $src   URL of the SCSS file.
     * @param string $handle Enqueue handle (used for cache keys).
     * @return string URL of the compiled CSS (or source URL on error).
     */
    public function compile ($src, $handle) {

        $this->prepare($src, $handle);

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

        $run = $this->get_cache()->needs_compile();

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

            $graph = Import_Scanner::scan($src_path, $this->get_import_paths($src_path));

            if ($graph->truncated) {
                $this->warnings[] = sprintf(
                    'Import graph truncated at %d files. Changes beyond that will not invalidate the cache.',
                    Import_Scanner::MAX_FILES
                );
            }

            $this->get_cache()->record($graph, $this->compile_time);

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
     * Ensure build directory exists and is writable. Sets error state on failure.
     *
     * @param string $build_path Directory path.
     * @return bool True if ready, false on error.
     */
    protected function ensure_build_directory ($build_path) {

        if (!is_dir($build_path)) {
            if (!wp_mkdir_p($build_path)) {
                $this->error('File Permissions Error, unable to create cache directory: ' . $build_path);
                $this->get_cache()->forget();
                return false;
            }
        }

        if (!is_writable($build_path)) {
            $this->error('File Permissions Error, permission denied. Please make the directory writable: ' . $build_path);
            $this->get_cache()->forget();
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

            $path = $this->get_asset()->get_source_path();

            // Callers file_exists() this and report it, so an unmappable URL keeps 2.x's shape
            // rather than becoming null here.
            $this->src_path = ($path === null) ? $this->src : $path;

        }

        return $this->src_path;

    }

    /**
     * The Asset this compiler is pointed at. Sole owner of URL -> path resolution.
     */
    public function get_asset () {

        if (is_null($this->asset)) $this->asset = new Asset($this->handle, $this->src);

        return $this->asset;

    }

    /**
     * Filesystem paths searched by @use / @forward / @import.
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

    public function get_cache () {

        if (is_null($this->cache)) $this->cache = new Compile_Cache($this->get_asset(), $this->get_target(), $this->get_resolver());

        return $this->cache;

    }

    public function is_current () {

        return $this->get_cache()->is_current();

    }

    public function get_last_compile_time () {

        return Compile_Cache::get_last_compile_time($this->handle);

    }

    public function get_resolver () {

        if (is_null($this->resolver)) $this->resolver = new Variable_Resolver($this->get_asset());

        return $this->resolver;

    }

    public function get_variables () {

        return $this->get_resolver()->get_variables();

    }

    public function get_target () {

        if (is_null($this->target)) $this->target = new Build_Target($this->get_asset());

        return $this->target;

    }

    public function get_src_url () {

        return $this->get_target()->get_map_url();

    }

    public function get_build_directory () {

        return $this->get_target()->get_directory();

    }

    public function get_build_path () {

        return $this->get_target()->get_path();

    }

    public function get_build_url () {

        return $this->get_target()->get_url();

    }

    public function get_build_name () {

        return $this->get_target()->get_name();

    }

    public function get_build_file () {

        return $this->get_target()->get_file();

    }

    public function get_src_map_options () {

        return $this->get_target()->get_map_options();

    }

    public function get_engine_class () {

        return get_class($this->get_engine());

    }
    
    public function get_style () {

        $style = apply_filters('sassy-style', $this->style, $this->src, $this->handle, $this);

        // Engines are handed a string. The filter's default is an OutputStyle case, and an enum
        // never equals a string, so returning OutputStyle::COMPRESSED used to silently expand.
        return $style instanceof OutputStyle ? $style->value : (string) $style;

    }
    
}
