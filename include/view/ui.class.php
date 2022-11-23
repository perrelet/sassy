<?php

namespace Sassy;

class UI {
	
	public function __construct () {
		
		add_action('admin_bar_menu', [$this, 'admin_bar_menu'], 100);
		add_filter('sassy-force-compile', [$this, 'run_compiler']);
		
	}
	

	public function admin_bar_menu ($admin_bar) {
		
		if (!current_user_can('edit_theme_options')) return;
		
		$admin_bar->add_menu([
			'id'    => 'sassy',
			'title' => __('SCSS', 'scsslib'),
			'href'  => '#',
		]);
		
		$admin_bar->add_menu([
			'id'     => 'sassy-recompile',
			'parent' => 'sassy',
			'title'  => !isset($_GET['sassy-recompile']) ? __('Recompile', 'sassy') : __('Normal compile', 'sassy'),
			'href'   => add_query_arg('sassy-recompile', !isset($_GET['sassy-recompile']))
		]);
		
	}
	
	public function run_compiler ($run) {
		
		if (isset($_GET['sassy-recompile']) && $_GET['sassy-recompile']) return true;
		
		return $run;
		
	}
	
}