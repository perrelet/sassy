<?php

/**
 * Drops every transient Sassy wrote, on every site. The build directory stays: its CSS is what
 * the site's enqueues point at, and where output goes is filtered by code this file cannot see.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) die;

require_once __DIR__ . '/include/model/compile-cache.class.php';

$sassy_uninstall_site = function () {
    Sassy\Compile_Cache::forget_all();
    delete_transient('sassy_updater');
};

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $blog_id) {
        switch_to_blog($blog_id);
        $sassy_uninstall_site();
        restore_current_blog();
    }
} else {
    $sassy_uninstall_site();
}
