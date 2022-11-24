<?php

namespace Sassy;

class UI {

	public function __construct () {
		
		add_action('admin_bar_menu', [$this, 'admin_bar_menu'], 100);
		add_filter('sassy-force-compile', [$this, 'run_compiler']);

		if (isset($_GET['sassy-vars'])) add_action('wp_footer', [$this, 'print_variables']);
		
	}

	public function print_variables () {
		
        echo "<script>console.log('SCSS Variables:');console.log(" . json_encode(SASSY()->get_all_variables()) . ");</script>";

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
			'title' => __('SCSS', 'scsslib'),
			'href'  => '#',
		]);
		
		$admin_bar->add_menu([
			'id'		=> 'sassy-recompile',
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

		$admin_bar->add_menu([
			'id'		=> "sassy-line-1",
			'parent'	=> 'sassy',
			'title'		=> $horizontal_line,
			'href'		=> false,
		]);

		foreach (SASSY()->get_precompilers() as $i => $precompiler) {

			$icon = $precompiler->has_error() ? '❌' : ($precompiler->has_compiled() ? '✔️' : '💾');
			$title = $icon . " " . basename(explode('?', $precompiler->get_src())[0]);

			$admin_bar->add_menu([
				'id'		=> "sassy-{$i}",
				'parent'	=> 'sassy',
				'title'		=> $title,
				'href'		=> $precompiler->get_src(),
				'meta'		=> ['target' => '_blank'],
			]);

			//

			foreach ($precompiler_menus as $method => $label) {

				$admin_bar->add_menu([
					'id'     => "sassy-{$i}-{$method}",
					'parent' => "sassy-{$i}",
					'title'  => $label,
					'href'		=> $precompiler->$method(),
					'meta'		=> ['target' => '_blank'],
				]);

			}

			if ($precompiler->get_compiler()) {

				$compile_options = $precompiler->get_compiler()->getCompileOptions();

				if (isset($compile_options['sourceMapOptions']) && isset($compile_options['sourceMapOptions']['sourceMapURL'])) {

					$admin_bar->add_menu([
						'id'     => "sassy-{$i}-map-url",
						'parent' => "sassy-{$i}",
						'title'  => 'Source Map',
						'href'		=> $compile_options['sourceMapOptions']['sourceMapURL'],
						'meta'		=> ['target' => '_blank'],
					]);

				}

			}

		}		
		
	}
	
	public function run_compiler ($run) {
		
		if (isset($_GET['sassy-recompile']) && $_GET['sassy-recompile']) return true;
		
		return $run;
		
	}
	
}