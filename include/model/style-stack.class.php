<?php

namespace Sassy;

/**
 * Every style WordPress knows about: discovery over the enqueue queues, and queries across them.
 *
 * Nothing outside this class reaches into wp_styles()->registered.
 */
class Style_Stack {

    /** @var string[] Enqueue hook sets discovery can fire. */
    const CONTEXTS = ['frontend', 'admin', 'editor'];

    protected $assets  = [];
    protected $context_errors = [];

    /**
     * Fire the given enqueue contexts, then read every registered style.
     *
     * Contexts that raise are recorded rather than thrown: third-party callbacks on the admin
     * and editor hooks assume a request WP-CLI is not making, and losing the whole run to one
     * of them is worse than reporting it. Surfaces render context_errors(); they do not decide.
     */
    public static function discover (array $contexts = []) {

        $stack = new static();

        foreach ($contexts as $context) $stack->fire($context);

        $stack->read_queues();

        return $stack;

    }

    /**
     * @return Asset[] Keyed by handle.
     */
    public function all () {

        return $this->assets;

    }

    /**
     * @return Asset[] Those Sassy can build, keyed by handle.
     */
    public function compilable () {

        return array_filter($this->assets, function ($asset) {
            return $asset->is_compilable();
        });

    }

    public function handle ($handle) {

        return $this->assets[$handle] ?? null;

    }

    /**
     * Assets whose recorded import graph contains the given file.
     *
     * There is no reverse index: this reads each compilable handle's graph. A second copy of the
     * same edges would need its own invalidation, which is the bug class phase 2 removed.
     *
     * @return Asset[] Keyed by handle.
     */
    public function dependents_of ($file) {

        $canonical = realpath($file) ?: $file;
        $found     = [];

        foreach ($this->compilable() as $handle => $asset) {

            $graph = Compile_Cache::get_graph($handle);
            if (!$graph) continue;

            if (isset($graph->deps[$file]) || isset($graph->deps[$canonical])) {
                $found[$handle] = $asset;
                continue;
            }

            // Only when the cheap comparison misses: recorded paths are usually canonical
            // already, and this is 124 stats per handle on the reference install.
            foreach (array_keys($graph->deps) as $path) {
                if ((realpath($path) ?: $path) === $canonical) {
                    $found[$handle] = $asset;
                    break;
                }
            }

        }

        return $found;

    }

    /**
     * @return array<string, string> context => message, for contexts that raised.
     */
    public function context_errors () {

        return $this->context_errors;

    }

    protected function fire ($context) {

        ob_start();

        try {
            $this->fire_context($context);
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->context_errors[$context] = $e->getMessage();
            return;
        }

        ob_end_clean();

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
        if (!function_exists('set_current_screen') && defined('ABSPATH') && file_exists(ABSPATH . 'wp-admin/includes/screen.php')) {
            require_once ABSPATH . 'wp-admin/includes/screen.php';
        }

        if (function_exists('set_current_screen')) set_current_screen($screen);

    }

    protected function read_queues () {

        foreach (apply_filters('sassy-style-queues', [wp_styles()]) as $queue) {

            if (!$queue || !isset($queue->registered)) continue;

            // Later queues win, matching the array_merge 2.x used for $digitalis_styles.
            foreach ($queue->registered as $dependency) {
                $this->assets[$dependency->handle] = Asset::from_dependency($dependency);
            }

        }

    }

}
