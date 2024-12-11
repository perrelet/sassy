<?php

namespace Sassy;

class Sassy {
	
	protected $build_dir;
	protected $build_url;
	protected $ui;
	protected $precompilers = [];
	protected $errors;
	
	public function __construct() {
		
		add_action('plugins_loaded', [$this, 'boot']);

		add_action('wp_ajax_sassy_compile', [$this, 'compile_all']);
		add_action('wp_ajax_nopriv_sassy_compile', [$this, 'compile_all']);

	}

	public function boot () {

		$this->load_vendors();
		$this->load_models();
		$this->load_views();

		add_action('after_setup_theme', [$this, 'load_integrations']);

		if (is_admin()) $this->load_admin();
	
		add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);	
		add_filter('style_loader_src', [$this, 'style_loader_src'], 10, 2);
		add_action('wp_footer', [$this, 'print_errors']);

		add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
		add_action('admin_footer', [$this, 'print_errors']);

	}
	
	protected function load_vendors () {
		
		require_once(SASSY_PATH . "vendor/scssphp-1.11.0/scss.inc.php");
		
	}
	
	protected function load_models () {
		
		require_once(SASSY_PATH . "include/model/precompiler.class.php");
		
	}
	
	public function load_integrations () {

		require_once(SASSY_PATH . "include/integrations/integration.abstract.php");
		require_once(SASSY_PATH . "include/integrations/bricks.integration.php");
		require_once(SASSY_PATH . "include/integrations/oxygen.integration.php");

		new Bricks();
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

	public function enqueue_scripts () {

		if (!current_user_can('edit_theme_options')) return;

		wp_enqueue_style('sassy', SASSY_URI . 'assets/css/sassy.css', [], SASSY_VERSION);

		$builder = false;
		if (defined('CT_VERSION')) $builder = 'oxygen';

		$backend = false;
		if (($builder == 'oxygen') && defined("SHOW_CT_BUILDER")) $backend = true;

		if (!$backend || ($backend && defined("OXYGEN_IFRAME"))) {

			wp_enqueue_script('sassy', SASSY_URI . 'assets/js/sassy.js', [], SASSY_VERSION, true);
			wp_localize_script('sassy', 'sass_params', [
				'ajax_url'				=> admin_url('admin-ajax.php'),
				'sassy_compile_nonce'	=> wp_create_nonce('sassy_compile'),
				'builder'				=> $builder,
				'backend'				=> $backend,
			]);      

		}  

	}
	
	public function style_loader_src ($src, $handle) {

		$path_parts = pathinfo(parse_url($src)['path']);
		if (!isset($path_parts['extension']) || ($path_parts['extension'] != 'scss')) return $src;

		if (!apply_filters('sassy-compile', true, $src, $handle)) return $src;

		$precompiler = new Precompiler();
		$this->precompilers[] = $precompiler;
		return $precompiler->compile($src, $handle);
		
	}

	public function compile_all () {

		if (!wp_verify_nonce($_REQUEST['nonce'], 'sassy_compile')) {
			wp_send_json_error('Sassy. But no sassy enough.', 401);
			wp_die(); 
		}

		ob_start();
		do_action('wp_enqueue_scripts');
		ob_end_clean();

		global $digitalis_styles;

		$response = [];

		$styles = wp_styles()->registered;
		if ($digitalis_styles) $styles = array_merge($styles, $digitalis_styles->registered);
		
		if ($styles) foreach ($styles as $style) {

			$path_parts = pathinfo(parse_url($style->src)['path']);
			if (!isset($path_parts['extension']) || ($path_parts['extension'] != 'scss')) continue;

			$precompiler = new Precompiler();
			$this->precompilers[] = $precompiler;
			$response[$style->handle] = $precompiler->compile($style->src, $style->handle);

		}

		if ($this->has_error()) {

			wp_send_json_error($this->get_errors());

		} else {

			wp_send_json_success($response);

		}
		
		wp_die(); 

	}

	//
	
	public function print_errors () {

		if (!current_user_can('edit_theme_options')) return;
		if (!apply_filters('sassy-print-errors', true)) return;

		echo "<div id='sassy-errors' class='" . ($this->has_error() ? 'show' : '') . "'>";

			if ($this->has_error()) foreach ($this->get_errors() as $i => $error) echo "<pre class='sassy-error'>{$error}</pre>";

		echo "</div>";
		
	}

	//

	public function has_error () {

		return $this->get_errors() ? true : false;

	}

	public function get_errors () {

		if (is_null($this->errors)) {

			$this->errors = [];

			if ($this->precompilers) foreach ($this->precompilers as $precompiler) {

				if ($precompiler->has_error()) {
				
					$basename = basename(explode('?', $precompiler->get_src())[0]);
					$this->errors[$precompiler->get_instance()] = "SASSY -> {$basename} -> " . $precompiler->get_error();

				}
	
			}

		}

		return $this->errors;

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