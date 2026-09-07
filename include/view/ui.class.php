<?php

namespace Sassy;

class UI {

	public function __construct () {

		add_action('admin_bar_menu', [$this, 'admin_bar_menu'], 100);
		add_filter('sassy-force-compile', [$this, 'run_compiler']);

		if (isset($_GET['sassy-vars'])) add_action('wp_footer', [$this, 'print_variables']);

		if (isset($_GET['sassy-clear-cache'])) add_action('init', [$this, 'clear_cache']);

	}

	public function print_variables () {

		$variables = SASSY()->get_all_variables();
		$sass_variables = [];

		if ($variables) foreach ($variables as $key => $value) $sass_variables['$' . $key] = $value;
		
        echo "<script>console.log('SCSS Variables:');console.log(" . json_encode($sass_variables) . ");</script>";

    }
	

	public function admin_bar_menu ($admin_bar) {
		
		if (!current_user_can('edit_theme_options')) return;
		if (!SASSY()->get_compilers()) return;
		
		$compiler_menus = [
			'get_src' => 'Source SCSS',
			'get_build_url' => 'Compiled CSS',
		];

		$admin_bar->add_menu([
			'id'    => 'sassy',
			'title' => SASSY()->has_error() ? '❌ SCSS' : 'SCSS',
			'href'  => '#',
		]);

		$admin_bar->add_menu([
			'id'		=> 'sassy-live-compile',
			'parent'	=> 'sassy',
			'title'		=> '⚡ Live Compile',
			'href'		=> '#',
		]);
		
		$admin_bar->add_menu([
			'id'		=> 'sassy-force-recompile',
			'parent'	=> 'sassy',
			'title'		=> !isset($_GET['sassy-recompile']) ? __('🤖 Force Recompile', 'sassy') : __('🤖 Normal Compile', 'sassy'),
			'href'		=> add_query_arg('sassy-recompile', !isset($_GET['sassy-recompile']))
		]);

		$admin_bar->add_menu([
            'id'     => 'sassy-vars',
            'parent' => 'sassy',
            'title'  => !isset($_GET['sassy-vars']) ? __('📝 Log Variables', 'sassy') : __('📝 Don\'t Log Variables', 'sassy'),
            'href'   => add_query_arg('sassy-vars', !isset($_GET['sassy-vars'])),
        ]);

		$admin_bar->add_menu([
			'id'     => 'sassy-clear-cache',
			'parent' => 'sassy',
			'title'  => __('🗑️ Clear Cache', 'sassy'),
			'href'   => add_query_arg('sassy-clear-cache', 1),
		]);

		do_action('sassy-admin-bar', $admin_bar);

		$horizontal_line = "<span style='width: 100%;border-bottom: 1px solid currentColor;display: block;padding-top: 1em;opacity: 0.5;'></span>";

		//

		$admin_bar->add_menu([
			'id'		=> "sassy-line-1",
			'parent'	=> 'sassy',
			'title'		=> $horizontal_line,
			'href'		=> false,
		]);

		foreach (SASSY()->get_compilers() as $i => $compiler) {

			$index = $compiler->get_index();

			//$icon = $compiler->has_error() ? '❌' : ($compiler->has_compiled() ? '✔️' : '💾');
			$has_warnings = !empty($compiler->get_warnings());
		$state = $compiler->has_error() ? 'error' : ($compiler->has_compiled() ? ($has_warnings ? 'warning' : 'compiled') : 'cache');
			$title = "<span data-state='{$state}'>" . basename(explode('?', $compiler->get_src())[0]). "</span>";

			$admin_bar->add_menu([
				'id'		=> "sassy-{$index}",
				'parent'	=> 'sassy',
				'title'		=> $title,
				'href'		=> $compiler->get_build_url(),
				'meta'		=> [
					'target'	=> '_blank',
					'class'		=> 'sassy-file',
				],
			]);

			foreach ($compiler_menus as $method => $label) {

				$admin_bar->add_menu([
					'id'     => "sassy-{$index}-{$method}",
					'parent' => "sassy-{$index}",
					'title'  => $label,
					'href'		=> $compiler->$method(),
					'meta'		=> ['target' => '_blank'],
				]);

			}

			$engine_label = str_replace(['Sassy\\', '_Engine', '_'], ['', '', ' '], $compiler->get_engine_class());

			$last_compile_time = $compiler->get_last_compile_time();
			$compile_label = $last_compile_time ? round($last_compile_time * 1000) . 'ms' : '—';

			$admin_bar->add_menu([
				'id'     => "sassy-{$index}-engine",
				'parent' => "sassy-{$index}",
				'title'  => $engine_label . ' &mdash; ' . $compile_label,
				'href'   => false,
			]);

			if ($compiler->has_src_map()) {

				$src_map_options = $compiler->get_src_map_options();

				if (isset($src_map_options['sourceMapURL'])) {

					$admin_bar->add_menu([
						'id'		=> "sassy-{$index}-map-url",
						'parent'	=> "sassy-{$index}",
						'title'		=> 'Source Map',
						'href'		=> $src_map_options['sourceMapURL'],
						'meta'		=> ['target' => '_blank'],
					]);

				}

				if (isset($src_map_options['sourceMapWriteTo'])) {

					$src_map_path = $src_map_options['sourceMapWriteTo'];

					if (file_exists($src_map_path) && $src_map = file_get_contents($src_map_path)) {

						$src_map = json_decode($src_map);

						if (!empty($src_map->sources) && count($src_map->sources) > 1) {

							$admin_bar->add_menu([
								'id'		=> "sassy-{$index}-map-line-1",
								'parent'	=> "sassy-{$index}",
								'title'		=> $horizontal_line,
								'href'		=> false,
							]);
							
							foreach ($src_map->sources as $j => $source) {

								if (strpos($compiler->get_src(), $source) !== false) continue;

								// Convert filesystem path to browser URL.
								$source_url = false;
								$abs        = str_replace('\\', '/', $source);
								$content    = str_replace('\\', '/', WP_CONTENT_DIR);
								$abspath    = str_replace('\\', '/', rtrim(ABSPATH, '/'));
								if (strpos($abs, $content) === 0) {
									$source_url = WP_CONTENT_URL . substr($abs, strlen($content));
								} elseif (strpos($abs, $abspath) === 0) {
									$source_url = site_url(substr($abs, strlen($abspath)));
								}

								$admin_bar->add_menu([
									'id'		=> "sassy-{$index}-map-source-{$j}",
									'parent'	=> "sassy-{$index}",
									'title'		=> basename($source),
									'href'		=> $source_url ?: false,
									'meta'		=> $source_url ? ['target' => '_blank'] : [],
								]);

							}

						}

					}

				}

			}

		}
		

		
	}
	
	public function clear_cache () {

		if (!current_user_can('edit_theme_options')) return;

		Compile_Cache::forget_all();

		wp_safe_redirect(remove_query_arg('sassy-clear-cache'));
		exit;

	}

	public function run_compiler ($run) {

		if (isset($_GET['sassy-recompile']) && $_GET['sassy-recompile']) return true;
		
		return $run;
		
	}
	
}