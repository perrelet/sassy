<?php

namespace Sassy;

use stdClass;

class Updater {
	
	protected $remote_json 		= 'https://digitalis.ca/plugins/update/sassy/info';
	protected $plugin_slug 		= SASSY_PLUGIN_SLUG;
	protected $plugin_base 		= SASSY_PLUGIN_BASE;
	protected $version 			= SASSY_VERSION;

	protected $cache_key;
	protected $cache_allowed = true;

	public function __construct () {

		$this->cache_key = $this->plugin_slug . '_updater';

		add_filter('plugins_api', [$this, 'info'], 20, 3);
		add_filter('site_transient_update_plugins', [$this, 'update']);
		add_action('upgrader_process_complete', [$this, 'purge'], 10, 2);

	}

	public function request () {

		$remote = get_transient($this->cache_key);

		if ($remote === false || !$this->cache_allowed) {

			$remote = wp_remote_get(
				$this->remote_json,
				[
					'timeout' => 10,
					'headers' => array(
						'Accept' => 'application/json'
					)
				]
			);

			if(is_wp_error($remote) || 200 !== wp_remote_retrieve_response_code($remote) || empty( wp_remote_retrieve_body($remote))) return false;

			set_transient($this->cache_key, $remote, HOUR_IN_SECONDS * 12);

		}

		$remote = json_decode(wp_remote_retrieve_body($remote));

		return $remote;

	}

	public function info ($res, $action, $args) {

		// print_r( $action );
		// print_r( $args );

		// do nothing if you're not getting plugin information right now
		if ('plugin_information' !== $action) return $res;

		// do nothing if it is not our plugin
		if ($this->plugin_slug !== $args->slug) return $res;

		// get updates

		if (!$remote = $this->request()) return $res;

		if (property_exists($remote, 'sections')) $remote->sections = json_decode(json_encode($remote->sections), true); 	// obj -> array
		if (property_exists($remote, 'banners')) $remote->banners = json_decode(json_encode($remote->banners), true);		// obj -> array

		/* echo "<pre>";
		var_dump($remote);
		exit;  */

		return $remote;

	}

	public function update ($transient) {

		if (empty($transient->checked)) return $transient;

		$remote = $this->request();

		if(
			$remote
			&& version_compare($this->version, $remote->version, '<')
			&& (!property_exists($remote, 'requires') || version_compare($remote->requires, get_bloginfo('version'), '<='))
			&& (!property_exists($remote, 'requires_php') || version_compare($remote->requires_php, PHP_VERSION, '<'))
		) {

			$res 				= new \stdClass();
			$res->slug 			= $this->plugin_slug;
			$res->plugin 		= $this->plugin_base;
			$res->new_version 	= $remote->version;
			$res->package 		= $remote->download_link;

			if (property_exists($remote, 'tested')) $res->tested = $remote->tested;

			$transient->response[$res->plugin] = $res;

		}

		return $transient;

	}

	public function purge ($upgrader, $options) {

		if (
			$this->cache_allowed
			&& 'update' === $options['action']
			&& 'plugin' === $options[ 'type' ]
		) {
			// just clean the cache when new plugin version is installed
			$this->delete_transient();
		}

	}

	public function delete_transient () {

		delete_transient( $this->cache_key );

	}

}