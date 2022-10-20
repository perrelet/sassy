<?php

namespace Sassy;

class Sassy {
	
	protected $build_dir;
	protected $build_url;
	protected $errors = [];
	
	public function __construct() {
		
		$this->load_vendors();
		$this->load_models();
		$this->load_views();
		
		if (is_admin()) $this->load_admin();
		
		add_filter('style_loader_src', [$this, 'style_loader_src'], 10, 2);
		add_action('wp_footer', [$this, 'print_errors']);
		
	}
	
	protected function load_vendors () {
		
		require_once(SASSY_PATH . "vendor/scssphp-1.4.1/scss.inc.php");
		
	}
	
	protected function load_models () {
		
		require_once(SASSY_PATH . "include/model/precompiler.class.php");
		
	}
	
	protected function load_views () {
		
		require_once(SASSY_PATH . "include/view/ui.class.php");
		
		new UI();
		
	}
	
	protected function load_admin () {
		
		require_once(SASSY_PATH . "include/admin/admin.class.php");
		
		new Admin();
		
	}
	
	public function style_loader_src ($src, $handle) {
		
		$path_parts = pathinfo(parse_url($src)['path']);
		if (!isset($path_parts['extension']) || ($path_parts['extension'] != 'scss')) return $src;

		if (!apply_filters('sassy-compile', true, $src, $handle)) return $src;

		$precompiler = new Precompiler();
		
		$build_directory = $this->get_build_directory();
		$build_directory = apply_filters('sassy-build-directory', $build_directory, $src, $handle);

		$precompiler->set_src($src);
		$precompiler->set_directory($this->get_build_directory());
		
		return $precompiler->compile();
		
		
	}
	
	public function get_build_directory () {
		
		$suffix = is_multisite() ? get_current_blog_id() . '/' : '';
		return '/scss/' . $suffix;
		
	}
	
	public function error ($e) {
		
		$e = "Sassy -> " . $e;
		
		$this->errors[] = $e;
		error_log($e);
		
	}
	
	public function print_errors () {
		
		if (!$this->errors) return;
		
		$proceed = current_user_can('administrator');
		$proceed = apply_filters('sassy-print-errors', $proceed);

		echo "<style>#sassy-errors { background: #ffc82e; border: 10px solid #ff8400; width: 100%; padding: 10px; position: absolute; left: 0; top: 0; z-index: 999; }</style>";
		
		echo "<div id='sassy-errors'>";
		foreach ($this->errors as $i => $error) {
			echo "<pre class='sassy-error'>$error</pre>";
		}
		echo "</div>";
		
	}
	

	
}