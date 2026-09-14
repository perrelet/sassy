<?php

namespace Sassy;

class UI {

	public function __construct () {

		add_action('admin_bar_menu', [$this, 'admin_bar_menu'], 100);

	}

	public function admin_bar_menu ($admin_bar) {
		
		if (!Policy::active()) return;
		if (!SASSY()->get_printers()) return;

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
		
		// Live Compile checks the cache rather than skipping it, which is the point of it being a
		// cheap keypress. So a forced route still has to exist.
		$admin_bar->add_menu([
			'id'     => 'sassy-force-compile',
			'parent' => 'sassy',
			'title'  => __('🤖 Force Compile', 'sassy'),
			'href'   => '#',
		]);

		// Pure observation: snapshot at load, diff on click, map through the source map.
		$admin_bar->add_menu([
			'id'     => 'sassy-capture',
			'parent' => 'sassy',
			'title'  => __('🖌️ Capture', 'sassy'),
			'href'   => '#',
		]);

		// Capture and push in one click. The safety is the server's refusals, not a review of
		// the patch first, so nothing is lost; Capture stays for looking without writing.
		if (Policy::can_write_source()) {
			$admin_bar->add_menu([
				'id'     => 'sassy-push',
				'parent' => 'sassy',
				'title'  => __('📤 Push to source', 'sassy'),
				'href'   => '#',
			]);
		}

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
				// A real box rather than a ☐ in a ::before: WordPress styles .ab-item::before for
				// its icon font, and the character came out as a blue glyph.
				'title'  => '<span class="sassy-toggle-box" aria-hidden="true"></span>' . $label,
				'href'   => '#',
				// class lands on the li, rel on the anchor: the JS reads the key off the anchor.
				'meta'   => ['class' => 'sassy-log-toggle', 'rel' => $key],
			]);

		}

		// Per-handle detail and Clear Cache live on the page now.
		$admin_bar->add_menu([
			'id'     => 'sassy-dashboard',
			'parent' => 'sassy',
			'title'  => __('🧭 Dashboard', 'sassy'),
			'href'   => Admin_Page::url(),
		]);

		do_action('sassy-admin-bar', $admin_bar);

	}

	/**
	 * WordPress handles are slug-like in practice but not guaranteed to be; the JS is handed the
	 * id the server computed rather than reimplementing this.
	 */
	public static function node_id ($handle) {

		return 'sassy-' . sanitize_key($handle);

	}

	/**
	 * Console logging toggles. State lives in the browser, so these render inert and the JS
	 * reflects and persists them.
	 */
	protected function log_toggles () {

		return [
			'diagnostics' => __('Diagnostics', 'sassy'),
			'meta'        => __('Compile meta', 'sassy'),
			// Never a default: a page that reloads itself uninvited is how a tool loses trust.
			'reload'      => __('Auto-reload', 'sassy'),
			'capture'     => __('Capture diffs', 'sassy'),
		];

	}

	
}