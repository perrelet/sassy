<?php

namespace Sassy;

use ScssPhp\ScssPhp\Compiler;
use Exception;

class Precompiler {
	
	static $count = 0;

	protected $instance 	= null;
	protected $src 			= null;
	protected $handle 		= null;
	protected $formatter 	= 'ScssPhp\ScssPhp\Formatter\Expanded';
	protected $compiler 	= null;
	protected $variables 	= null;

	protected $build_dir;
	protected $build_path;
	protected $build_url;
	protected $build_name;
	protected $build_file;
	protected $src_path;

	protected $compiled = false;
	protected $error = false;
	
	public function __construct () {
		
		$this->instance = ++static::$count;

	}
	
	public function compile ($src, $handle) {

		$this->build_dir	= null;
		$this->build_path	= null;
		$this->build_url	= null;
		$this->build_name	= null;
		$this->build_file	= null;
		$this->src_path		= null;
		
		$this->src 			= $src;
		$this->handle 		= $handle;
		
		$src_path = $this->get_src_path();
		$parse_src = parse_url($this->src);
		
		if (file_exists($src_path) === false) {
			
			$this->error('Source file not found: ' . $src_path);
			return $this->src;
			
		}
		
		$build_path = $this->get_build_path();
		$build_url = $this->get_build_url();
		$build_name = $this->get_build_name();
		$build_file = $this->get_build_file();
		
		$run = apply_filters('sassy-force-compile', false, $this->src, $this->handle);
		
		if (!$run) {

			if (($filemtimes = get_transient('sassy-filemtimes-' . $this->handle)) === false) $filemtimes = [];
			if (isset($filemtimes[$build_file]) === false || $filemtimes[$build_file] < filemtime($src_path)) {
				$run = true;
			}
		}
		
		// Compile if the variables have changed.

		$variables = $this->get_variables();
		if (!$run) {
			$signature = sha1(serialize($variables));
			if ($signature !== get_transient('sassy-vars-sig-' . $this->handle)) {
				$run = true;
				set_transient('sassy-vars-sig-' . $this->handle, $signature);
			}
		}
		
		if (!$run && !file_exists($build_file)) $run = true;
		
		if ($run) {
			
			if (is_dir($build_path) === false) {
				
				if (wp_mkdir_p($build_path) === false) {
					
					$this->error('File Permissions Error, unable to create cache directory: ' . $build_path);
					delete_transient('sassy-filemtimes-' . $this->handle);
					return $this->src;
					
				}
				
			}
			
			if (is_writable($build_path) === false) {
				
				$this->error('File Permissions Error, permission denied. Please make the directory writable: ' . $build_path);
				delete_transient('sassy-filemtimes-' . $this->handle);
				return $this->src;
				
			}
			
			try {
			
				if (is_null($this->compiler)) $this->compiler = new Compiler();

				if (apply_filters('sassy-src-map', true, $this->src, $this->handle)) {

					$source_map_data = apply_filters('sassy-src-map-data', [
						'sourceMapWriteTo'	=> str_replace('\\', '/', $build_path) . $build_name . '.map',	// Absolute path where the .map file will be written
						'sourceMapURL'		=> $build_url . '.map',											// Full or relative URL to archive .map
						'sourceMapBasepath'	=> rtrim(str_replace('\\', '/', ABSPATH), '/'),					// Configures the base path to replace (for instance C:/www/domain/wp-content/themes/theme-name/classes/../scss/ or C:/www/domain/wp-content/ in your cases (notice that we have a weird thing where this options must use / instead of \ on Windows) (https://github.com/scssphp/scssphp/issues/35) // ? - Partial path (server root) to create the relative URL
						'sourceMapFilename'	=> $build_url,													// (Optional) Full or relative URL to compiled .css file
						'sourceMapRootpath'	=> trailingslashit(site_url()),									
						//'sourceRoot'		=> $this->src,													// (Optional) Prepend the 'source' field entries to relocate source files
					], $this->src, $this->handle);	

					$this->compiler->setSourceMap(Compiler::SOURCE_MAP_FILE);
					$this->compiler->setSourceMapOptions($source_map_data);
					
				}
				
				$this->compiler->setFormatter($this->get_formatter());
				$this->compiler->setVariables($variables);
				$this->compiler->addImportPath(dirname($src_path));
				$this->compiler->addImportPath(SASSY_PATH);

				//$this->compiler->addImportPath(dirname($src_path));
				
				do_action('sassy-compiler', $this->compiler, $this);

				$css = $this->compiler->compile(file_get_contents($src_path), $src_path);
				
			} catch (Exception $e) {
				
				$this->error('A SCSS compiler error occurred: ' . $e->getMessage());
				return $this->src;
				
			}
			
			//Transform the relative paths to work correctly
			$css = preg_replace('#(url\((?![\'"]?(?:https?:|/))[\'"]?)#miu', '$1' . dirname($parse_src['path']) . '/', $css);
			
			file_put_contents($build_file, $css);
			
			$filemtimes[$build_file] = filemtime($build_file);

			set_transient('sassy-filemtimes-' . $this->handle, $filemtimes);
			$this->compiled = true;
			
		}
		
		$output = $build_url;
		if (!empty($parse_src['query'])) $output .= '?' . $parse_src['query'];
		return $output;
		
	}
	
	protected function error ($e) {
		
		$this->error = true;
		$e = "Precompiler -> " . $e;
		SASSY()->error($e);
		
	}
	
	//HAS

	public function has_compiled () {

		return $this->compiled;

	}

	public function has_error () {

		return $this->error;

	}

	//GET

	public function get_instance () {

		return $this->instance;

	}

	public function get_src () {

		return $this->src;

	}

	public function get_handle () {

		return $this->handle;

	}

	public function get_compiler () {

		return $this->compiler;

	}

	public function get_src_path () {

		if (is_null($this->src_path)) {

			$abs = preg_replace('/^' . preg_quote(site_url(), '/') . '/i', '', $this->src); 	// Convert the URL to absolute paths.
			if (preg_match('#^//#', $abs) || strpos($abs, '/') !== 0) return $this->src;		// Ignore SCSS from CDNs, other domains, and relative paths
			
			$path = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . parse_url($this->src)['path'];		// TODO: Switch $_SERVER['DOCUMENT_ROOT']
			
			// If it is part of a multi-site then the 'domain' must be removed
			if (is_multisite()) {
				$blog_details_path = get_blog_details()->path;
				if ($blog_details_path != PATH_CURRENT_SITE) $path = str_replace($blog_details_path, PATH_CURRENT_SITE, $path);
			}

			$this->src_path = apply_filters('sassy-src-path', $path, $this->src, $this->handle);

		}
		
		return $this->src_path;
		
	}

	public function get_build_directory () {

		if (is_null($this->build_dir)) {

			$suffix = is_multisite() ? get_current_blog_id() . '/' : '';
			$this->build_dir = apply_filters('sassy-build-directory', '/scss/' . $suffix, $this->src, $this->handle);

		}

		return $this->build_dir;
		
	}
	
	public function get_build_path () {
		
		if (is_null($this->build_path)) $this->build_path = apply_filters('sassy-build-path', WP_CONTENT_DIR, $this->src, $this->handle) . $this->get_build_directory();

		return $this->build_path;
		
	}
	
	public function get_build_url () {
		
		if (is_null($this->build_url)) $this->build_url = apply_filters('sassy-build-url', WP_CONTENT_URL, $this->src, $this->handle) . $this->get_build_directory() . $this->get_build_name();

		return $this->build_url;
		
	}
	
	public function get_build_name () {
		
		if (is_null($this->build_name)) {

			$parts 		= explode('?', $this->src);
			$name 		= basename($parts[0], '.scss');
			$build_name = "{$name}.{$this->instance}.css";

			$this->build_name = apply_filters('sassy-build-name', $build_name, $this->src, $this->handle);

		}

		return $this->build_name;
		
	}

	public function get_build_file () {

		if (is_null($this->build_file)) $this->build_file = $this->get_build_path() . $this->get_build_name();

		return $this->build_file;

	}
	
	public function get_formatter () {
		
		return apply_filters('sassy-formatter', $this->formatter, $this->src, $this->handle);
		
	}
	
	public function get_variables () {
		
		if (is_null($this->variables)) {

			$this->variables = apply_filters('sassy-variables', [
				'template-directory-uri'   => get_template_directory_uri(),
				'stylesheet-directory-uri' => get_stylesheet_directory_uri()
			], $this->src, $this->handle);

		}

		return $this->variables;
		
	}
	
}