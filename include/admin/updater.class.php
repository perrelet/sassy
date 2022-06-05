<?php

namespace Sassy;

use stdClass;

class Updater {
	
	private $api = 'https://digitaliswebdesign.com/update/sassy.json';
	private $transient_name = 'sassy_upgrade';
	private $slug = SASSY_PLUGIN_SLUG;
	private $plugin = SASSY_PLUGIN_BASE;
	private $version = SASSY_VERSION;
	
	function __construct() {
		
		//add_filter('plugins_api', [$this, 'update_info'], 20, 3); //No longer works 'plugin not found'
		add_filter('pre_set_site_transient_update_plugins', [$this, 'update'] );
		add_action('upgrader_process_complete', [$this, 'after_update'], 10, 2 );
		
	}
	
	public function get_remote ($use_transient = true) {
		
		/*if ( empty($transient->checked ) ) {
			return $transient;
		} else {
			remove_filter('pre_set_site_transient_update_plugins', [$this, 'update'] );
		}*/
		
		$remote = get_transient($this->transient_name);
		
		if((false == $remote) || !$use_transient) {
			
			//set_transient($this->transient_name, false, 0 );
			
			$remote = wp_remote_get(
				$this->api,
				array(
					'timeout' => 10,
					'headers' => array(
						'Accept' => 'application/json'
					)
				)
			);
			
			if (is_wp_error( $remote )) {
				
				return false;
				
			} else {
				
				if (isset( $remote['response']['code'] ) && $remote['response']['code'] == 200 && !empty( $remote['body'] ) ) {
					set_transient($this->transient_name, $remote, 10800 );
				}

			}

		}
		
		if (isset( $remote['response']['code'] ) && $remote['response']['code'] == 200 && !empty( $remote['body'] ) ) {
			return $remote;
		} else {
			return false;
		}
		
	}
	
	public function is_new_version ($json) {
		
		if (!$json) return false;
		if (!property_exists($json, "version")) return false;
		if (!property_exists($json, "requires")) return false;
		
		if (version_compare($this->version, $json->version, '<' ) && version_compare($json->requires, get_bloginfo('version'), '<' )) {
			return true;
		} else {
			return false;
		}
		
	}
	
	public function remote_to_json ($remote) {
		
		return json_decode($remote['body']);
	}
	
	public function check_for_updates ($use_transient = true, $delete_transient = false) {
		
		if ($delete_transient) delete_transient($this->transient_name);
		
		$remote = $this->get_remote($use_transient);
		
		if(!$remote) return false;
		
		$json = $this->remote_to_json($remote);
		if (!$json) return false;
		
		if ($this->is_new_version($json)) {
					
			return $json;
		} else {
			return false;
		}
		
	}
	
	public function update ($transient) {
		
		dlog($transient);
		
		$update = $this->check_for_updates(true);
		
		if ( $update ) {

			$res = new stdClass();
			
			$res->slug = $this->slug;
			$res->plugin = $this->plugin;
			$res->new_version = $update->version;
			$res->tested = $update->tested;
			$res->package = $update->download_url;
			/* $res->sections = [
				'description'		=> 'heloo',
				'installation'		=> '',
				'changelog'			=> 'asd',
				'upgrade_notice'	=> ''
			]; */
			
			$transient->response[$res->plugin] = $res;
			//$transient->checked[$res->plugin] = $update->version;

		}
		
		return $transient;
		
	}
	
	function after_update ($upgrader_object, $options) {
		
		if ( $options['action'] == 'update' && $options['type'] === 'plugin' )  {
			delete_transient($this->transient_name);
		}
		
	}
	
}