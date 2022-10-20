<?php

/**
 * Plugin Name:       Sassy
 * Plugin URI:        http://www.digitaliswebdesign.com/
 * Description:       “So ripeness climbs the bells of Digitalis, flower by flower, undistracted by a Mind, or a Design, or by desire.
 * Version:           1.0.0
 * Author:            Digitalis Web Design
 * Author URI:        http://www.digitaliswebdesign.com/
 * Text Domain:       sassy
 */

if (!defined('WPINC')) die;
if (defined('SASSY_VERSION')) return;

/* DEFINES */
 
define('SASSY_VERSION', 		'1.0.0');
define('SASSY_PATH', 			plugin_dir_path( __FILE__));
define('SASSY_URI',				plugin_dir_url( __FILE__));
define('SASSY_ROOT_FILE',		__FILE__);
define('SASSY_PLUGIN_BASE',		plugin_basename(__FILE__));				//sassy/sassy.php
define('SASSY_PLUGIN_SLUG', 	basename(SASSY_PLUGIN_BASE, '.php'));	//sassy

//

require_once SASSY_PATH . 'include/sassy.class.php';

function SASSY() {
	global $Sassy;
	return $Sassy;
}

$Sassy = new Sassy\Sassy();

//