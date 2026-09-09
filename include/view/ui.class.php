<?php

namespace Sassy;

class UI {

	public function __construct () {

		add_action('admin_bar_menu', [$this, 'admin_bar_menu'], 100);

		if (isset($_GET['sassy-clear-cache'])) add_action('init', [$this, 'clear_cache']);

	}

	public function admin_bar_menu ($admin_bar) {
		
		if (!Policy::active()) return;
		if (!SASSY()->get_printers()) return;
		
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
		
		// Force Recompile is gone: Live Compile already skips the cache, which makes it a genuine
		// redundancy rather than something waiting for a replacement.

		$admin_bar->add_menu([
			'id'     => 'sassy-logging',
			'parent' => 'sassy',
			'title'  => __('📜 Logging', 'sassy'),
			'href'   => false,
		]);

		foreach ($this->log_toggles() as $key => $label) {

			$admin_bar->add_menu([
				'id'     => "sassy-logging-{$key}",
				'parent' => 'sassy-logging',
				'title'  => $label,
				'href'   => '#',
				'meta'   => ['class' => 'sassy-log-toggle', 'html' => '', 'rel' => $key],
			]);

		}

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

		foreach (SASSY()->get_printers() as $handle => $compiler) {

			$node = static::node_id($handle);

			//$icon = $compiler->has_error() ? '❌' : ($compiler->has_compiled() ? '✔️' : '💾');
			$state = $compiler->get_state();
			$title = "<span data-state='{$state}'>" . basename(explode('?', $compiler->get_src())[0]). "</span>";

			$admin_bar->add_menu([
				'id'		=> "{$node}",
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
					'id'     => "{$node}-{$method}",
					'parent' => "{$node}",
					'title'  => $label,
					'href'		=> $compiler->$method(),
					'meta'		=> ['target' => '_blank'],
				]);

			}

			$engine_label = str_replace(['Sassy\\', '_Engine', '_'], ['', '', ' '], $compiler->get_engine_class());

			$last_compile_time = $compiler->get_last_compile_time();
			$compile_label = $last_compile_time ? round($last_compile_time * 1000) . 'ms' : '—';

			$admin_bar->add_menu([
				'id'     => "{$node}-engine",
				'parent' => "{$node}",
				'title'  => $engine_label . ' &mdash; ' . $compile_label,
				'href'   => false,
			]);

			if ($compiler->has_src_map()) {

				if ($map_url = $compiler->get_map_url()) {

					$admin_bar->add_menu([
						'id'		=> "{$node}-map-url",
						'parent'	=> "{$node}",
						'title'		=> 'Source Map',
						'href'		=> $map_url,
						'meta'		=> ['target' => '_blank'],
					]);

				}

				if ($src_map_path = $compiler->get_map_path()) {

					if (file_exists($src_map_path) && $src_map = file_get_contents($src_map_path)) {

						$src_map = json_decode($src_map);

						if (!empty($src_map->sources) && count($src_map->sources) > 1) {

							$admin_bar->add_menu([
								'id'		=> "{$node}-map-line-1",
								'parent'	=> "{$node}",
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
									'id'		=> "{$node}-map-source-{$j}",
									'parent'	=> "{$node}",
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
	
	/**
	 * Console logging toggles. State lives in the browser, so these are rendered inert and the
	 * JS reflects and persists them.
	 */
	/**
	 * WordPress handles are slug-like in practice but not guaranteed to be; the JS is handed the
	 * id the server computed rather than reimplementing this.
	 */
	public static function node_id ($handle) {

		return 'sassy-' . sanitize_key($handle);

	}

	protected function log_toggles () {

		return [
			'diagnostics' => __('Diagnostics', 'sassy'),
			'meta'        => __('Compile meta', 'sassy'),
			'stack'       => __('Stack summary', 'sassy'),
		];

	}

	public function clear_cache () {

		if (!Policy::active()) return;

		Compile_Cache::forget_all();

		wp_safe_redirect(remove_query_arg('sassy-clear-cache'));
		exit;

	}

	
}