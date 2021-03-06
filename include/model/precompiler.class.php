<?php

namespace Sassy;

use ScssPhp\ScssPhp\Compiler;
use Exception;

class Precompiler {
	
	static $count = 0;

	protected $instance 	= null;
	protected $src 			= null;
	protected $folder 		= null;
	protected $formatter 	= 'ScssPhp\ScssPhp\Formatter\Expanded';
	protected $compiler 	= null;
	
	public function __construct () {
		
		$this->instance = ++static::$count;

	}
	
	public function compile () {
		
		if (is_null($this->src) || is_null($this->folder)) {
			$this->error('A source file and destination folder need to be set in order to run the compiler.');
			return $this->src;
		}
		
		$src_path = $this->get_src_path();
		$parse_src = parse_url($this->src);
		
		if (file_exists($src_path) === false) {
			
			$this->error('Source file not found: ' . $src_path);
			return $this->src;
			
		}
		
		$build_path 	= $this->get_build_path();
		$build_url 		= $this->get_build_url();
		$build_name 	= $this->get_build_name();

		$file = $build_path . $build_name;
		
		$run = apply_filters('sassy-run-compiler', false, $this->src);
		
		if (!$run) {

			if (($filemtimes = get_transient('sassy-filemtimes')) === false) $filemtimes = [];
			if (isset($filemtimes[$file]) === false || $filemtimes[$file] < filemtime($src_path)) {
				$run = true;
			}
		}
		
		// Compile if the variables have changed.
		$variables = $this->get_variables();
		if (!$run) {
			$signature = sha1(serialize($variables));
			if ($signature !== get_transient('sassy-variables-signature')) {
				$run = true;
				set_transient('sassy-variables-signature', $signature);
			}
		}
		
		if (!$run && !file_exists($file)) $run = true;
		
		if ($run) {
			
			if (is_dir($build_path) === false) {
				
				if (wp_mkdir_p($build_path) === false) {
					
					$this->error('File Permissions Error, unable to create cache directory: ' . $build_path);
					delete_transient('sassy-filemtimes');
					return $this->src;
					
				}
				
			}
			
			if (is_writable($build_path) === false) {
				
				$this->error('File Permissions Error, permission denied. Please make the directory writable: ' . $build_path);
				delete_transient('sassy-filemtimes');
				return $this->src;
				
			}
			
			$src_map_data = apply_filters('sassy-src-map-data', [
				'sourceMapWriteTo'  => $build_path . $build_name . '.map',	// Absolute path where the .map file will be written
				'sourceMapURL'      => $build_url . $build_name . '.map',	// Full or relative URL to archive .map
				'sourceMapBasepath' => rtrim(ABSPATH, '/'),					// ? - Partial path (server root) to create the relative URL
				'sourceMapFilename' => $build_url . $build_name,			// (Optional) Full or relative URL to compiled .css file
				'sourceRoot'        => $this->src,							// (Optional) Prepend the 'source' field entries to relocate source files
			]);	
			
			try {
			
				if (is_null($this->compiler)) $this->compiler = new Compiler();
				
				$this->compiler->setSourceMap(Compiler::SOURCE_MAP_FILE);
				$this->compiler->setSourceMapOptions($src_map_data);
				
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
			
			file_put_contents($file, $css);
			
			$filemtimes[$file] = filemtime($file);

			set_transient('sassy-filemtimes', $filemtimes);
			
		}
		
		$output = $build_url . $build_name;
		if (!empty($parse_src['query'])) $output .= '?' . $parse_src['query'];
		return $output;
		
	}
	
	protected function error ($e) {
		
		$e = "Precompiler -> " . $e;
		SASSY()->error($e);
		
	}
	
	//GET

	public function get_src_path () {
		
		$abs = preg_replace('/^' . preg_quote(site_url(), '/') . '/i', '', $this->src); 	// Convert the URL to absolute paths.
		if (preg_match('#^//#', $abs) || strpos($abs, '/') !== 0) return $this->src;		// Ignore SCSS from CDNs, other domains, and relative paths
		
		$path = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . parse_url($this->src)['path'];		// TODO: Switch $_SERVER['DOCUMENT_ROOT']
		
		// If it is part of a multi-site then the 'domain' must be removed
		if (is_multisite()) {
			$blog_details_path = get_blog_details()->path;
			if ($blog_details_path != PATH_CURRENT_SITE) $path = str_replace($blog_details_path, PATH_CURRENT_SITE, $path);
		}
		
		return apply_filters('sassy-src-path', $path, $this->src);
		
	}
	
	public function get_build_path () {
		
		return apply_filters('sassy-content-dir', WP_CONTENT_DIR) . $this->folder;
		
	}
	
	public function get_build_url () {
		
		return apply_filters('sassy-content-url', WP_CONTENT_URL) . $this->folder;
		
	}
	
	public function get_build_name () {
		
		$parts 		= explode('?', $this->src);
		$name 		= basename($parts[0], '.scss');
		$build_name = "{$name}.{$this->instance}.css";

		return apply_filters('sassy-build-name', $build_name, $this->src);
		
	}
	
	public function get_formatter () {
		
		return apply_filters('sassy-formatter', $this->formatter);
		
	}
	
	public function get_variables () {
		
		return apply_filters('sassy-variables', [
			'template_directory_uri'   => get_template_directory_uri(),
			'stylesheet_directory_uri' => get_stylesheet_directory_uri()
		]);
		
	}
	
	//SET
	
	public function set_src ($src) {
		
		$this->src = $src;
		return $this;
		
	}
	
	public function set_folder ($folder) {
		
		$this->folder = $folder;
		return $this;
		
	}
	
}