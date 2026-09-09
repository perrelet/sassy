<?php

namespace Sassy;

class Sassy {
	
	protected $build_dir;
	protected $build_url;
	protected $ui;
	protected $printers = [];
	protected $errors;
	
	public function __construct() {
		
		add_action('plugins_loaded', [$this, 'boot']);

		// No nopriv: a dev is by definition logged in, and a nonce is a CSRF token rather than
		// an authorization model.
		add_action('wp_ajax_sassy_compile', [$this, 'compile_all']);

	}

	public function boot () {

		$this->load_vendors();
		$this->load_models();
		$this->load_views();

		Extensions::register_post_processor('lightning-css', [Lightning_CSS_Postprocessor::class, 'process']);

		add_action('after_setup_theme', [Extensions::class, 'boot']);

		if (is_admin()) $this->load_admin();
	
		add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);	
		add_filter('style_loader_src', [$this, 'style_loader_src'], 10, 2);
		add_action('wp_footer', [$this, 'print_errors']);

		add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
		add_action('admin_footer', [$this, 'print_errors']);

	}
	
	protected function load_vendors () {

		require __DIR__ . '/../vendor/autoload.php';
	
	}
	
	protected function load_models () {

		require_once(SASSY_PATH . 'include/model/diagnostic.class.php');
		require_once(SASSY_PATH . 'include/model/compile-request.class.php');
		require_once(SASSY_PATH . 'include/model/compile-result.class.php');
		require_once(SASSY_PATH . 'include/model/lightning-css-postprocessor.class.php');
		require_once(SASSY_PATH . 'include/model/scss-map.class.php');
		require_once(SASSY_PATH . 'include/model/policy.class.php');
		require_once(SASSY_PATH . 'include/model/asset.class.php');
		require_once(SASSY_PATH . 'include/model/post-process-context.class.php');
		require_once(SASSY_PATH . 'include/model/extensions.class.php');
		require_once(SASSY_PATH . 'include/model/style-stack.class.php');
		require_once(SASSY_PATH . 'include/model/build-target.class.php');
		require_once(SASSY_PATH . 'include/model/variable-resolver.class.php');
		require_once(SASSY_PATH . 'include/model/compile-cache.class.php');
		require_once(SASSY_PATH . 'include/model/import-graph.class.php');
		require_once(SASSY_PATH . 'include/model/import-resolver.class.php');
		require_once(SASSY_PATH . 'include/model/import-scanner.class.php');
		require_once(SASSY_PATH . 'include/engines/compiler-engine.interface.php');
		require_once(SASSY_PATH . 'include/engines/scssphp-logger.class.php');
		require_once(SASSY_PATH . 'include/engines/scssphp-engine.compiler-engine.php');
		require_once(SASSY_PATH . 'include/engines/dart-sass-parser.class.php');
		require_once(SASSY_PATH . 'include/engines/dart-sass-engine.compiler-engine.php');
		require_once(SASSY_PATH . 'include/model/printer.class.php');

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

		if (!Policy::active()) return;

		wp_enqueue_style('sassy', SASSY_URI . 'assets/css/sassy.css', [], static::asset_version('assets/css/sassy.css'));

		// The last Oxygen artifact was deciding here whether to load at all, based on which
		// window the builder had put us in. A builder integration is now a listener on the
		// sassy:* events, so nothing here needs to know one exists.
		wp_enqueue_script('sassy', SASSY_URI . 'assets/js/sassy.js', [], static::asset_version('assets/js/sassy.js'), true);

		wp_localize_script('sassy', 'sass_params', [
			'ajax_url'            => admin_url('admin-ajax.php'),
			'sassy_compile_nonce' => wp_create_nonce('sassy_compile'),
			'keybinding'          => apply_filters('sassy-keybinding', ['ctrl+space', 'meta+space']),
		]);  

	}
	
	/**
	 * Sassy's own assets version by mtime, not SASSY_VERSION.
	 *
	 * The constant changes once a release; these files change while you are working on them, and
	 * a browser that has cached one will not fetch it again until the URL does.
	 */
	protected static function asset_version ($relative) {

		$mtime = @filemtime(SASSY_PATH . $relative);

		return $mtime ? (string) $mtime : SASSY_VERSION;

	}

	public function style_loader_src ($src, $handle) {

		$asset = new Asset($handle, $src);
		if (!in_array($asset->extension, Asset::COMPILABLE, true)) return $src;

		if (!apply_filters('sassy-compile', true, $src, $handle)) return $src;

		return $this->add_printer(new Printer(), $handle)->compile($src, $handle);
		
	}

	public function compile_all () {

		if (!Policy::active()) {
			wp_send_json_error('Sassy. But not sassy enough.', 403);
			wp_die();
		}

		if (!wp_verify_nonce($_REQUEST['nonce'] ?? '', 'sassy_compile')) {
			wp_send_json_error('Sassy. But no sassy enough.', 401);
			wp_die(); 
		}

		if (!empty($_REQUEST['force'])) add_filter('sassy-force-compile', '__return_true', 10, 4);

		$response = [];

		foreach (Style_Stack::discover(['frontend'])->compilable() as $asset) {

            $compiler = $this->add_printer(new Printer(), $asset->handle);

            $href     = $compiler->compile($asset->src, $asset->handle);
            $warnings = array_map(function ($diagnostic) { return $diagnostic->to_array(); }, $compiler->get_warnings());

            $meta = [
                'engine'         => $compiler->get_engine_class(),
                'compiled_file'  => $compiler->get_build_file(),
                'compiled_url'   => $compiler->get_build_url(),
                'src'            => $compiler->get_src(),
                'src_path'       => $compiler->get_src_path(),
                'src_url'        => $compiler->get_src_url(),
                'handle'         => $compiler->get_handle(),
                'node'           => UI::node_id($asset->handle),
                'style'          => $compiler->get_style(),
                'hash'           => $compiler->get_content_hash(),
                'has_source_map' => $compiler->has_src_map(),
                'compile_time'   => $compiler->get_compile_time(),
            ];

            $response[$asset->handle] = [
                'href'      => $href,
                'warnings'  => $warnings ?: [],
                'meta'      => $meta,
            ];

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

		if (!Policy::active()) return;
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

			foreach ($this->printers as $handle => $printer) {

				if (!$printer->has_error()) continue;

				$this->errors[UI::node_id($handle)] = Diagnostic::render_all($printer->get_errors());

			}

		}

		return $this->errors;

	}

	public function get_ui () {

		return $this->ui;

	}
	
	public function get_printers () {

		return $this->printers;

	}

	protected function add_printer (Printer $printer, $handle) {

		$this->printers[$handle] = $printer;

		return $printer;

	}

	
}