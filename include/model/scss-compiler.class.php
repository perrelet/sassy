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

    protected $compiled;
    protected $error;
    protected $src_map;
    protected $warnings = [];

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

        $this->compiled = false;
        $this->error    = false;
        $this->src_map  = false;
        $this->warnings = [];

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
            $args = $this->build_compile_args($src_path, $variables);
            $result = $this->get_engine()->compile($args);

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

            $css = preg_replace('#(url\((?![\'"]?(?:https?:|/))[\'"]?)#miu', '$1' . dirname($parse_src['path']) . '/', $css);
            $css = apply_filters('sassy-css', $css, $this->src, $this->handle, $this);

            file_put_contents($build_file, $css);
            if ($result->map !== null) {
                $map_path = $this->get_src_map_options()['sourceMapWriteTo'] ?? null;
                if ($map_path) {
                    file_put_contents($map_path, $result->map);
                }
            }

            $filemtimes = get_transient('sassy-filemtimes-' . $this->handle);
            if ($filemtimes === false) {
                $filemtimes = [];
            }
            $filemtimes[$build_file] = filemtime($build_file);
            set_transient('sassy-filemtimes-' . $this->handle, $filemtimes);
            $this->compiled = true;
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
            if (!isset($filemtimes[$build_file]) || $filemtimes[$build_file] < filemtime($src_path)) {
                $run = true;
            }
        }

        if (!$run) {
            $signature = sha1(serialize($variables));
            if ($signature !== get_transient('sassy-vars-sig-' . $this->handle)) {
                $run = true;
                set_transient('sassy-vars-sig-' . $this->handle, $signature);
            }
        }

        if (!$run && !file_exists($build_file)) {
            $run = true;
        }

        return $run;
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

        $import_paths = [dirname($src_path), SASSY_PATH];
        if (defined('DIGITALIS_FRAMEWORK_PATH')) {
            $import_paths[] = DIGITALIS_FRAMEWORK_PATH;
        }

        return [
            'scss'               => file_get_contents($src_path),
            'src_path'           => $src_path,
            'import_paths'       => $import_paths,
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

    public function has_src_map () {

        return $this->src_map;

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

            $abs = preg_replace('/^' . preg_quote(site_url(), '/') . '/i', '', $this->src);     // Convert the URL to absolute paths.
            if (preg_match('#^//#', $abs) || strpos($abs, '/') !== 0) return $this->src;        // Ignore SCSS from CDNs, other domains, and relative paths
            
            $path = ABSPATH . parse_url($this->src)['path'];
            if (!file_exists($path)) {
                $path = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . parse_url($this->src)['path'];
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
                'wp-content-url' => '"'. WP_CONTENT_URL . '"',
                'template-directory-url'   => '"'. get_template_directory_uri() . '"',
                'stylesheet-directory-url' => '"'. get_stylesheet_directory_uri() . '"',
            ];

            if (defined('DIGITALIS_FRAMEWORK_PATH')) {

                $variables['digitalis_path'] = '"' . str_replace('\\', '/', DIGITALIS_FRAMEWORK_PATH) . '"';
                $variables['digitalis_uri']  = '"' . str_replace('\\', '/', DIGITALIS_FRAMEWORK_URI) . '"';

            }

            $this->variables = apply_filters('sassy-variables', $variables, $this->src, $this->handle, $this);

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
    
}
