<?php

namespace Sassy;

class Sassy {
	
	protected $build_dir;
	protected $build_url;
	protected $errors = [];
	protected $ui;
	protected $precompilers = [];
	
	public function __construct() {
		
		add_action('plugins_loaded', [$this, 'boot']);

	}

	public function boot () {

		$this->load_vendors();
		$this->load_models();
		$this->load_integrations();
		$this->load_views();

		if (is_admin()) $this->load_admin();
	
		add_filter('style_loader_src', [$this, 'style_loader_src'], 10, 2);
		add_action('wp_footer', [$this, 'print_errors']);

	}
	
	protected function load_vendors () {
		
		require_once(SASSY_PATH . "vendor/scssphp-1.11.0/scss.inc.php");
		
	}
	
	protected function load_models () {
		
		require_once(SASSY_PATH . "include/model/precompiler.class.php");
		
	}
	
	public function load_integrations () {

		require_once(SASSY_PATH . "include/integrations/integration.abstract.php");
		require_once(SASSY_PATH . "include/integrations/oxygen.integration.php");

		new Oxygen();

	}
	
	protected function load_views () {
		
		require_once(SASSY_PATH . "include/view/ui.class.php");
		
		$this->ui = new UI();
		
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
		$this->precompilers[] = $precompiler;
		return $precompiler->compile($src, $handle);
		
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

	public function get_ui () {

		return $this->ui;

	}
	
	public function get_precompilers () {

		return $this->precompilers;

	}

	public function get_all_variables () {

		if (!$this->get_precompilers()) return [];

		$variables = [];

		foreach ($this->get_precompilers() as $precompiler) {

			$variables = array_merge($variables, $precompiler->get_variables());

		}

		return $variables;

	}
	
}