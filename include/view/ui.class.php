<?php

namespace Sassy;

class UI {

	public function __construct () {
		
		add_action('admin_bar_menu', [$this, 'admin_bar_menu'], 100);
		add_filter('sassy-force-compile', [$this, 'run_compiler']);

		if (isset($_GET['sassy-vars'])) add_action('wp_footer', [$this, 'print_variables']);
		
		add_action('wp_enqueue_scripts', [$this, 'scripts']);
		add_action('admin_enqueue_scripts', [$this, 'scripts']);
		
	}

	public function scripts () {

		if (current_user_can('edit_theme_options')) {
			wp_enqueue_script('clipboard');
			add_action('wp_footer', function () {
				echo "<script>new ClipboardJS('.sassy-clipboard');</script>";
			}, PHP_INT_MAX);
		}

	}

	public function print_variables () {

		$variables = SASSY()->get_all_variables();
		$sass_variables = [];

		if ($variables) foreach ($variables as $key => $value) $sass_variables['$' . $key] = $value;
		
        echo "<script>console.log('SCSS Variables:');console.log(" . json_encode($sass_variables) . ");</script>";

    }
	

	public function admin_bar_menu ($admin_bar) {
		
		if (!current_user_can('edit_theme_options')) return;
		if (!SASSY()->get_precompilers()) return;
		
		$precompiler_menus = [
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

		do_action('sassy-admin-bar', $admin_bar);

		$horizontal_line = "<span style='width: 100%;border-bottom: 1px solid currentColor;display: block;padding-top: 1em;opacity: 0.5;'></span>";

		//

		$admin_bar->add_menu([
			'id'		=> "sassy-line-1",
			'parent'	=> 'sassy',
			'title'		=> $horizontal_line,
			'href'		=> false,
		]);

		foreach (SASSY()->get_precompilers() as $i => $precompiler) {

			$instance = $precompiler->get_instance();

			//$icon = $precompiler->has_error() ? '❌' : ($precompiler->has_compiled() ? '✔️' : '💾');
			$state = $precompiler->has_error() ? 'error' : ($precompiler->has_compiled() ? 'compiled' : 'cache');
			$title = "<span data-state='{$state}'>" . basename(explode('?', $precompiler->get_src())[0]). "</span>";

			$admin_bar->add_menu([
				'id'		=> "sassy-{$instance}",
				'parent'	=> 'sassy',
				'title'		=> $title,
				'href'		=> $precompiler->get_build_url(),
				'meta'		=> [
					'target'	=> '_blank',
					'class'		=> 'sassy-file',
				],
			]);

			foreach ($precompiler_menus as $method => $label) {

				$admin_bar->add_menu([
					'id'     => "sassy-{$instance}-{$method}",
					'parent' => "sassy-{$instance}",
					'title'  => $label,
					'href'		=> $precompiler->$method(),
					'meta'		=> ['target' => '_blank'],
				]);

			}

			if ($precompiler->has_src_map()) {

				$src_map_options = $precompiler->get_src_map_options();

				if (isset($src_map_options['sourceMapURL'])) {

					$admin_bar->add_menu([
						'id'		=> "sassy-{$instance}-map-url",
						'parent'	=> "sassy-{$instance}",
						'title'		=> 'Source Map',
						'href'		=> $src_map_options['sourceMapURL'],
						'meta'		=> ['target' => '_blank'],
					]);

				}

				if (isset($src_map_options['sourceMapWriteTo'])) {

					$src_map_path = $src_map_options['sourceMapWriteTo'];

					if (file_exists($src_map_path) && $src_map = file_get_contents($src_map_path)) {

						$src_map = json_decode($src_map);

						if ($src_map->sources && ($src_map->sources > 1)) {

							$admin_bar->add_menu([
								'id'		=> "sassy-{$instance}-map-line-1",
								'parent'	=> "sassy-{$instance}",
								'title'		=> $horizontal_line,
								'href'		=> false,
							]);
							
							foreach ($src_map->sources as $j => $source) {

								if (strpos($precompiler->get_src(), $source) !== false) continue;

								$admin_bar->add_menu([
									'id'		=> "sassy-{$instance}-map-source-{$j}",
									'parent'	=> "sassy-{$instance}",
									'title'		=> basename($source),
									'href'		=> $source,
									'meta'		=> ['target' => '_blank'],
								]);

							}

						}

					}

				}

			}

		}
		
		//

		if ($variables = SASSY()->get_all_variables()) {

			$admin_bar->add_menu([
				'id'		=> "sassy-line-2",
				'parent'	=> 'sassy',
				'title'		=> $horizontal_line,
				'href'		=> false,
			]);

			$admin_bar->add_menu([
				'id'		=> 'sassy-variables',
				'parent'	=> 'sassy',
				'title'		=> '⭐ Variables',
				'href'		=> '#',
			]);

			foreach ($variables as $key => $value) {

				$sass_key = "\${$key}";

				$admin_bar->add_menu([
					'id'		=> "sassy-variables-{$key}",
					'parent'	=> 'sassy-variables',
					'title'		=> "<span class='sassy-clipboard' data-clipboard-text='{$sass_key}'>{$sass_key}</span>",
					'href'		=> "#",
				]);

				$admin_bar->add_menu([
					'id'		=> "sassy-variables-{$key}-value",
					'parent'	=> "sassy-variables-{$key}",
					'title'		=> "<span class='sassy-clipboard' data-clipboard-text='{$value}'>{$value}</span>",
					'href'		=> "#",
				]);

			}

		}

		
	}
	
	public function run_compiler ($run) {

		if (isset($_GET['sassy-recompile']) && $_GET['sassy-recompile']) return true;
		
		return $run;
		
	}
	
}