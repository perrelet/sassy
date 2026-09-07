<?php

namespace Sassy;

use ScssPhp\ScssPhp\OutputStyle;

/**
 * Produces one asset's output: consults Compile_Cache, drives a Compiler_Engine, writes the CSS
 * and its source map.
 */
class Printer {

    protected $engine;

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
    protected $src_map;
    protected $diagnostics = [];
    protected $compile_time = null;

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
        $this->src_map  = false;
        $this->diagnostics = [];
        $this->compile_time = null;

    }

    /**
     * Lazily resolve and cache the compiler engine (filterable via sassy-engine).
     *
     * @return Compiler_Engine
     */
    public function get_engine () : Compiler_Engine {

        if ($this->engine !== null) {
            return $this->engine;
        }

        $engine = Extensions::resolve_engine(apply_filters('sassy-engine', null, $this->get_asset()), $this->get_asset());

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
            $this->fail($this->unresolved());
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

            $request = $this->build_request($src_path, $variables);
            $result  = $this->get_engine()->compile($request);

            $this->compile_time = microtime(true) - $start;

            if (!$result->ok()) {

                $this->diagnostics = $result->diagnostics;
                if (!$result->has_errors()) $this->fail(new Diagnostic(Diagnostic::ERROR, (string) $result->error));

                return $this->get_build_url();

            }

            $this->diagnostics = $result->diagnostics;

            $this->src_map = $request->source_map;
            $css = $result->css;

            $css = preg_replace('#(url\((?![\'"]?(?:[a-z][a-z0-9+.\-]*:|/|\#))[\'"]?)#miu', '$1' . dirname($parse_src['path']) . '/', $css);
            $css = apply_filters('sassy-css', $css, $this->src, $this->handle, $this->get_asset());

            $context = new Post_Process_Context($this->get_asset());
            $css     = Extensions::post_process($css, $context);
            $this->diagnostics = array_merge($this->diagnostics, $context->get_diagnostics());

            file_put_contents($build_file, $css);
            if ($result->map !== null && $request->map_path) file_put_contents($request->map_path, $result->map);

            $graph = Import_Scanner::scan($src_path, $this->get_import_paths($src_path));

            if ($graph->truncated) {
                $this->diagnostics[] = new Diagnostic(Diagnostic::WARNING, sprintf(
                    'Import graph truncated at %d files. Changes beyond that will not invalidate the cache.',
                    Import_Scanner::MAX_FILES
                ), ['file' => $src_path, 'source' => 'sassy']);
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
                $this->fail(new Diagnostic(Diagnostic::ERROR, 'Unable to create the build directory: ' . $build_path, ['source' => 'sassy']));
                $this->get_cache()->forget();
                return false;
            }
        }

        if (!is_writable($build_path)) {
            $this->fail(new Diagnostic(Diagnostic::ERROR, 'Build directory is not writable: ' . $build_path, ['source' => 'sassy']));
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
    protected function build_request ($src_path, array $variables) {

        $asset = $this->get_asset();

        return new Compile_Request([
            'source'      => file_get_contents($src_path),
            'source_path' => $src_path,
            'load_paths'  => $this->get_import_paths($src_path),
            'variables'   => $variables,
            'style'       => $this->get_style(),
            'source_map'  => (bool) apply_filters('sassy-src-map', true, $asset->src, $asset->handle, $asset),
            'map_path'    => $this->get_target()->get_map_path(),
            'map_url'     => $this->get_target()->get_map_url(),
        ]);

    }
    
    /**
     * Record an error message for this compile run.
     *
     * @param string $e Error message.
     */
    protected function fail (Diagnostic $diagnostic) {

        $this->diagnostics[] = $diagnostic;

    }

    /**
     * A source that is absent names the path it looked at; one that maps nowhere names the URL,
     * because there is no path to name.
     */
    protected function unresolved () {

        $path = $this->get_asset()->get_source_path();

        if ($path === null) {
            return new Diagnostic(Diagnostic::ERROR, 'Source could not be resolved to a local file: ' . $this->src, ['source' => 'sassy']);
        }

        return new Diagnostic(Diagnostic::ERROR, 'Source file not found.', ['file' => $path, 'source' => 'sassy']);

    }

    public function has_compiled () {

        return $this->compiled;

    }

    public function has_error () {

        return (bool) $this->get_errors();

    }

    /**
     * @return Diagnostic|null The first error, which is the one surfaces lead with.
     */
    public function get_error () {

        return $this->get_errors()[0] ?? null;

    }

    /**
     * @return Diagnostic[]
     */
    public function get_diagnostics () {

        return $this->diagnostics;

    }

    /**
     * @return Diagnostic[]
     */
    public function get_errors () {

        return $this->of(Diagnostic::ERROR);

    }

    /**
     * Everything that did not stop the compile: warnings, deprecations and notices. Phase 5
     * splits them by severity for --strict.
     *
     * @return Diagnostic[]
     */
    public function get_warnings () {

        return array_values(array_filter($this->diagnostics, function ($diagnostic) {
            return !$diagnostic->is_error();
        }));

    }

    protected function of ($severity) {

        return array_values(array_filter($this->diagnostics, function ($diagnostic) use ($severity) {
            return $diagnostic->severity === $severity;
        }));

    }

    public function get_compile_time () {

        return $this->compile_time;

    }

    public function has_src_map () {

        if ($this->src_map) return true;

        // On cache hits src_map is never set; check whether the map file exists.
        return file_exists($this->get_map_path());

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

            $this->import_paths = apply_filters('sassy-import-paths', $import_paths, $src_path, $this->handle, $this->get_asset());
            $this->import_paths = Extensions::apply_load_paths($this->import_paths, $this->get_asset());

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

    public function get_map_url () {

        return $this->get_target()->get_map_url();

    }

    public function get_map_path () {

        return $this->get_target()->get_map_path();

    }

    /** @deprecated Reports the map URL, despite the name. Kept until phase 6 rewrites the payload. */
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

    public function get_engine_class () {

        return get_class($this->get_engine());

    }
    
    public function get_style () {

        $style = apply_filters('sassy-style', $this->style, $this->src, $this->handle, $this->get_asset());

        // Engines are handed a string. The filter's default is an OutputStyle case, and an enum
        // never equals a string, so returning OutputStyle::COMPRESSED used to silently expand.
        return $style instanceof OutputStyle ? $style->value : (string) $style;

    }
    
}
