<?php

namespace Sassy;

use WP_CLI;
use WP_CLI_Command;

/**
 * WP-CLI commands for Sassy.
 */
class Sassy_CLI_Command extends WP_CLI_Command {

    /**
     * Compile all registered SCSS styles.
     *
     * This mirrors the logic used by the live compile endpoint, but is designed
     * to be run from the command line. It respects the same filters and
     * Lightning CSS post-processing pipeline.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Force a recompile of all SCSS (ignores cache/filemtimes).
     *
     * ## EXAMPLES
     *
     *     wp sassy compile
     *     wp sassy compile --force
     *
     * @when after_wp_load
     */
    public function compile ($args, $assoc_args) {

        $force = !empty($assoc_args['force']);

        if ($force) {
            add_filter('sassy-force-compile', '__return_true', 10, 4);
        }

        WP_CLI::log('Sassy: compiling SCSS styles...');

        // Ensure enqueued styles are registered like a normal page load.
        ob_start();
        do_action('wp_enqueue_scripts');
        ob_end_clean();

        global $digitalis_styles;

        $styles   = wp_styles()->registered;
        if ($digitalis_styles) {
            $styles = array_merge($styles, $digitalis_styles->registered);
        }

        if (!$styles) {
            WP_CLI::warning('No styles registered.');
            return;
        }

        $compiled = 0;
        $errors   = [];

        foreach ($styles as $style) {

            $path_parts = pathinfo(parse_url($style->src)['path']);
            if (!isset($path_parts['extension']) || $path_parts['extension'] !== 'scss') {
                continue;
            }

            $compiler = new SCSS_Compiler();

            $href = $compiler->compile($style->src, $style->handle);

            if ($compiler->has_error()) {
                $errors[] = sprintf(
                    '%s -> %s',
                    $style->handle,
                    $compiler->get_error()
                );
                continue;
            }

            $compiled++;

            WP_CLI::log(sprintf(
                '- %s: %s%s',
                $style->handle,
                $href,
                $compiler->get_compile_time() !== null
                    ? sprintf(' (%.3fs)', $compiler->get_compile_time())
                    : ''
            ));
        }

        if ($errors) {
            WP_CLI::error_multi_line(array_merge(
                ['Sassy: one or more SCSS compiles failed:'],
                $errors
            ));
            WP_CLI::halt(1);
        }

        if ($compiled === 0) {
            WP_CLI::success('No SCSS styles found to compile.');
        } else {
            WP_CLI::success(sprintf('Successfully compiled %d SCSS style(s).', $compiled));
        }

    }

}

