<?php

namespace Sassy;

use WP_CLI;
use WP_CLI_Command;
use WP_CLI\Utils;

/**
 * WP-CLI commands for Sassy.
 */
class Sassy_CLI_Command extends WP_CLI_Command {

    const CONTEXTS = ['frontend', 'admin', 'editor'];

    /**
     * Compile registered SCSS styles.
     *
     * ## OPTIONS
     *
     * [<handle>...]
     * : Only compile these handles. Defaults to every discovered SCSS style.
     *
     * [--force]
     * : Recompile even if the cache is current.
     *
     * [--hooks=<hooks>]
     * : Which enqueue hooks to fire before looking for styles. Comma-separated, or "all".
     * ---
     * default: frontend
     * options:
     *   - frontend
     *   - admin
     *   - editor
     *   - all
     * ---
     *
     * ## EXAMPLES
     *
     *     wp sassy compile
     *     wp sassy compile --force
     *     wp sassy compile my-theme --hooks=all
     *
     * @when after_wp_load
     */
    public function compile ($args, $assoc_args) {

        if (!empty($assoc_args['force'])) add_filter('sassy-force-compile', '__return_true', 10, 4);

        $styles = $this->discover($assoc_args, $args);

        $compiled = 0;
        $cached   = 0;
        $errors   = [];

        foreach ($styles as $style) {

            $compiler = new SCSS_Compiler();
            $href     = $compiler->compile($style->src, $style->handle);

            if ($compiler->has_error()) {
                $errors[] = sprintf('%s -> %s', $style->handle, $compiler->get_error());
                continue;
            }

            foreach ($compiler->get_warnings() as $warning) {
                WP_CLI::warning(sprintf('%s: %s', $style->handle, $warning));
            }

            if ($compiler->has_compiled()) {
                $compiled++;
                WP_CLI::log(sprintf('- %s: %s (%.3fs)', $style->handle, $href, $compiler->get_compile_time()));
            } else {
                $cached++;
                WP_CLI::log(sprintf('- %s: %s (cached)', $style->handle, $href));
            }

        }

        if ($errors) {
            WP_CLI::error_multi_line(array_merge(['Sassy: one or more SCSS compiles failed:'], $errors));
            WP_CLI::halt(1);
        }

        WP_CLI::success(sprintf('%d compiled, %d already current.', $compiled, $cached));

    }

    /**
     * List discovered SCSS styles and their cache state.
     *
     * ## OPTIONS
     *
     * [--hooks=<hooks>]
     * : Which enqueue hooks to fire before looking for styles. Comma-separated, or "all".
     * ---
     * default: frontend
     * ---
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - yaml
     *   - count
     * ---
     *
     * @subcommand list
     * @when after_wp_load
     */
    public function list_ ($args, $assoc_args) {

        $items = [];

        foreach ($this->discover($assoc_args) as $style) {

            $compiler = (new SCSS_Compiler())->prepare($style->src, $style->handle);
            $graph    = Import_Graph::from_array(get_transient('sassy-filemtimes-' . $style->handle));
            $built    = $compiler->get_build_file();

            $items[] = [
                'handle'  => $style->handle,
                'source'  => $compiler->get_src_path(),
                'built'   => $built,
                'state'   => !file_exists($built) ? 'not built' : (($graph && !$graph->has_changed($compiler->get_src_path())) ? 'current' : 'stale'),
                'deps'    => $graph ? count($graph->deps) : 0,
                'engine'  => $compiler->get_engine_class(),
                'time'    => ($t = $compiler->get_last_compile_time()) ? sprintf('%.3fs', $t) : '',
            ];

        }

        if (!$items) WP_CLI::warning('No SCSS styles found. Try --hooks=all.');

        Utils\format_items($assoc_args['format'] ?? 'table', $items, ['handle', 'state', 'deps', 'engine', 'time', 'source', 'built']);

    }

    /**
     * Show the SCSS variables a handle compiles with.
     *
     * ## OPTIONS
     *
     * [<handle>]
     * : Resolve variables as this handle sees them. Omit for the global set.
     *
     * [--hooks=<hooks>]
     * : Which enqueue hooks to fire before looking for styles. Comma-separated, or "all".
     * ---
     * default: frontend
     * ---
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - yaml
     *   - scss
     * ---
     *
     * @when after_wp_load
     */
    public function vars ($args, $assoc_args) {

        $compiler = new SCSS_Compiler();

        if ($args) {
            $style = $this->find_style($assoc_args, $args[0]);
            $compiler->prepare($style->src, $style->handle);
        }

        $variables = $compiler->get_variables();

        if (($assoc_args['format'] ?? 'table') === 'scss') {
            foreach ($variables as $name => $value) WP_CLI::line(sprintf('$%s: %s;', $name, $value));
            return;
        }

        $items = [];
        foreach ($variables as $name => $value) $items[] = ['variable' => '$' . $name, 'value' => $value];

        Utils\format_items($assoc_args['format'] ?? 'table', $items, ['variable', 'value']);

    }

    /**
     * Show the recorded import graph for a handle.
     *
     * ## OPTIONS
     *
     * <handle>
     * : The style handle.
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - yaml
     *   - count
     * ---
     *
     * @when after_wp_load
     */
    public function deps ($args, $assoc_args) {

        $graph = Import_Graph::from_array(get_transient('sassy-filemtimes-' . $args[0]));

        if (!$graph) WP_CLI::error(sprintf("No import graph recorded for '%s'. Compile it first.", $args[0]));

        $items = [];

        foreach ($graph->deps as $path => $mtime) {
            $items[] = [
                'file'     => $path,
                'modified' => date('Y-m-d H:i:s', $mtime),
                'state'    => !is_file($path) ? 'MISSING' : (filemtime($path) != $mtime ? 'changed' : 'current'),
            ];
        }

        Utils\format_items($assoc_args['format'] ?? 'table', $items, ['file', 'modified', 'state']);

        if ($graph->dirs) WP_CLI::log(sprintf('%d directory(s) watched for shadowing.', count($graph->dirs)));

    }

    /**
     * Clear compile caches.
     *
     * ## OPTIONS
     *
     * [<handle>...]
     * : Only clear these handles. Omit to clear every Sassy cache entry.
     *
     * [--hooks=<hooks>]
     * : Which enqueue hooks to fire when discovering handles. Comma-separated, or "all".
     * ---
     * default: frontend
     * ---
     *
     * @when after_wp_load
     */
    public function clear ($args, $assoc_args) {

        // With an external object cache the transients are not in the options table, so there
        // is nothing to pattern-match; fall back to whatever discovery can reach.
        if (!$args && function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {

            $args = array_keys($this->discover($assoc_args));

            if (!$args) WP_CLI::error('External object cache in use and no handles discovered. Pass handles explicitly.');

            WP_CLI::warning('External object cache in use: clearing discovered handles only.');

        }

        if ($args) {

            foreach ($args as $handle) {
                delete_transient('sassy-filemtimes-' . $handle);
                delete_transient('sassy-vars-sig-' . $handle);
            }

            WP_CLI::success(sprintf('Cleared %d handle(s).', count($args)));
            return;

        }

        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            WP_CLI::error('An external object cache is in use, so transients are not in the options table. Pass handles explicitly.');
        }

        global $wpdb;

        $deleted = $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '\_transient\_sassy-%'
                OR option_name LIKE '\_transient\_timeout\_sassy-%'"
        );

        WP_CLI::success(sprintf('Cleared %d cache entries.', (int) $deleted));

    }

    /**
     * Report how Sassy is configured and what it can reach.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - yaml
     * ---
     *
     * @when after_wp_load
     */
    public function status ($args, $assoc_args) {

        $compiler = new SCSS_Compiler();
        $items    = [];

        $row = function ($key, $value) use (&$items) {
            $items[] = ['setting' => $key, 'value' => (string) $value];
        };

        $row('version',     defined('SASSY_VERSION') ? SASSY_VERSION : '?');
        $row('engine',      $compiler->get_engine_class());
        $row('output style', $compiler->get_style());
        $row('source maps', apply_filters('sassy-src-map', true, null, null, $compiler) ? 'on' : 'off');

        $sass_bin = (new Dart_Sass_Engine())->get_sass_bin();
        $row('dart sass binary', $sass_bin ?: '(not configured)');
        if ($sass_bin) $row('dart sass version', $this->probe($sass_bin . ' --version'));

        if (!apply_filters('sassy-lightning-css', true, null, null, $compiler)) {
            $row('lightning css', 'disabled by filter');
        } else if ($bin = Lightning_CSS_Postprocessor::resolve_bin()) {
            $row('lightning css', 'enabled');
            $row('lightning css binary', $bin);
        } else {
            $row('lightning css', 'off (no binary configured)');
        }

        $build_path = $compiler->get_build_path();
        $row('build path', $build_path);
        $row('build path writable', is_dir($build_path) ? (is_writable($build_path) ? 'yes' : 'NO') : '(not created yet)');

        foreach (['SASSY_DART_SASS_BIN', 'SASSY_LIGHTNINGCSS_BIN', 'SASSY_TOOLS_DIR'] as $constant) {
            $row($constant, defined($constant) ? constant($constant) : '(undefined)');
        }

        Utils\format_items($assoc_args['format'] ?? 'table', $items, ['setting', 'value']);

    }

    /**
     * Fire the requested enqueue hooks, then collect the SCSS styles they registered.
     *
     * @param array $assoc_args Parsed options.
     * @param array $handles    Optional handle filter.
     * @return array<string, object>
     */
    protected function discover ($assoc_args, $handles = []) {

        $context  = $assoc_args['hooks'] ?? 'frontend';
        $contexts = ($context === 'all') ? self::CONTEXTS : array_map('trim', explode(',', $context));

        foreach ($contexts as $context) {

            if (!in_array($context, self::CONTEXTS, true)) {
                WP_CLI::error(sprintf("Unknown hook set '%s'. Use: %s, all.", $context, implode(', ', self::CONTEXTS)));
            }

            ob_start();

            try {
                $this->fire_context($context);
            } catch (\Throwable $e) {
                // Third-party callbacks on the admin and editor hooks assume a request that
                // WP-CLI is not making. Skip the context rather than losing the whole run.
                ob_end_clean();
                WP_CLI::warning(sprintf("Context '%s' raised: %s", $context, $e->getMessage()));
                continue;
            }

            ob_end_clean();

        }

        $styles = Sassy::get_scss_styles();

        if ($handles) {

            $styles = array_filter($styles, function ($style) use ($handles) {
                return in_array($style->handle, $handles, true);
            });

            foreach ($handles as $handle) {
                if (!isset($styles[$handle])) WP_CLI::warning(sprintf("No SCSS style registered for handle '%s'.", $handle));
            }

        }

        return $styles;

    }

    protected function fire_context ($context) {

        switch ($context) {

            case 'frontend':
                do_action('wp_enqueue_scripts');
                break;

            case 'admin':
                $this->admin_screen('dashboard');
                do_action('admin_enqueue_scripts', 'index.php');
                break;

            case 'editor':
                $this->admin_screen('post');
                do_action('enqueue_block_editor_assets');
                break;

        }

    }

    protected function admin_screen ($screen) {

        // Callbacks on these hooks routinely dereference get_current_screen(), which is null
        // outside wp-admin.
        if (!function_exists('set_current_screen')) require_once ABSPATH . 'wp-admin/includes/screen.php';
        if (function_exists('set_current_screen')) set_current_screen($screen);

    }

    protected function find_style ($assoc_args, $handle) {

        $styles = $this->discover($assoc_args, [$handle]);

        if (!isset($styles[$handle])) WP_CLI::error(sprintf("No SCSS style registered for handle '%s'.", $handle));

        return $styles[$handle];

    }

    protected function probe ($command) {

        $out  = [];
        $code = 0;

        exec($command . ' 2>&1', $out, $code);

        return $code === 0 && $out ? trim($out[0]) : '(not runnable)';

    }

}
