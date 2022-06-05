<?php

namespace Sassy;

class Admin {
	
	public function __construct () {
		
		require_once(SASSY_PATH . "include/admin/updater.class.php");
		
		new Updater();
		
	}
	
}