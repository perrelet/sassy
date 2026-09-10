<?php

namespace Sassy;

/**
 * Every style and script WordPress knows about: discovery over the enqueue queues, and queries
 * across them.
 *
 * Nothing outside this class reaches into wp_styles()->registered or wp_scripts()->registered.
 */
class Style_Stack {

    /** @var string[] Enqueue hook sets discovery can fire. */
    const CONTEXTS = ['frontend', 'admin', 'editor'];

    // Two stores, both keyed by handle: 48 handles on the reference install name a style and a
    // script, and every caller of compilable() reads its keys as handles.
    protected $assets  = [];
    protected $scripts = [];
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

        if (!$contexts) {
            $stack->read_queues();
            return $stack;
        }

        // Inside a real request the enqueue hooks would land the theme's assets in the live
        // registries and print them in the footer, and admin_screen() would replace the page's
        // own screen. Discover into copies and hand the originals back.
        $restore = static::isolate();

        try {
            foreach ($contexts as $context) $stack->fire($context);
            $stack->read_queues();
        } finally {
            $restore();
        }

        return $stack;

    }

    /**
     * The contexts a --hooks value names, or null when one is unknown.
     *
     * @return string[]|null
     */
    public static function parse_contexts ($hooks) {

        if ($hooks === 'all') return static::CONTEXTS;

        $contexts = array_values(array_filter(array_map('trim', explode(',', (string) $hooks))));

        foreach ($contexts as $context) {
            if (!in_array($context, static::CONTEXTS, true)) return null;
        }

        return $contexts ?: null;

    }

    /**
     * @param string $type 'style' (the default, keyed by handle), 'script' (likewise), or 'all'
     *                     (both, keyed type:handle, for the two surfaces that list everything).
     * @return Asset[]
     */
    public function all ($type = 'style') {

        if ($type === 'script') return $this->scripts;

        if ($type === 'all') {
            $all = [];
            foreach ($this->assets  as $handle => $asset) $all["style:$handle"]  = $asset;
            foreach ($this->scripts as $handle => $asset) $all["script:$handle"] = $asset;
            return $all;
        }

        return $this->assets;

    }

    /** @return Asset[] Keyed by handle. */
    public function styles () {

        return $this->assets;

    }

    /** @return Asset[] Keyed by handle. */
    public function scripts () {

        return $this->scripts;

    }

    /**
     * @return Asset[] Those Sassy can build, keyed by handle.
     */
    public function compilable () {

        return array_filter($this->assets, function ($asset) {
            return $asset->is_compilable();
        });

    }

    public function handle ($handle, $type = 'style') {

        return $type === 'script' ? ($this->scripts[$handle] ?? null) : ($this->assets[$handle] ?? null);

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
     * Everything wrong with the stack, as diagnostics. The caller decides which severities are
     * failures; this decides what is true.
     *
     * A handle that fails to compile is never recorded as current, so erroring handles surface
     * here as stale without anything needing to compile them.
     *
     * @return Diagnostic[]
     */
    public function audit () {

        $found = [];

        foreach ($this->compilable() as $handle => $asset) {

            $target = new Build_Target($asset);
            $cache  = new Compile_Cache($asset, $target, new Variable_Resolver($asset));
            $source = $asset->get_source_path();

            if (!file_exists($source)) {
                $found[] = static::finding(Diagnostic::ERROR, $handle, 'Source file not found.', $source);
                continue;
            }

            if (!file_exists($target->get_file())) {
                $found[] = static::finding(Diagnostic::ERROR, $handle, 'Never built. Run wp sassy compile.', $target->get_file());
            } else if (!$cache->is_current()) {
                $found[] = static::finding(Diagnostic::ERROR, $handle, 'Stale. Run wp sassy compile.', $target->get_file());
            }

            $graph = Compile_Cache::get_graph($handle);

            if ($graph && $graph->truncated) {
                $found[] = static::finding(Diagnostic::WARNING, $handle, sprintf('Import graph truncated at %d files, so staleness cannot be trusted.', Import_Scanner::MAX_FILES), $source, true);
            }

            foreach (Compile_Cache::get_tally($handle) as $severity => $count) {
                if (in_array($severity, [Diagnostic::WARNING, Diagnostic::DEPRECATION], true)) {
                    $found[] = static::finding($severity, $handle, sprintf('%d %s%s at last compile.', $count, $severity, $count === 1 ? '' : 's'), $source);
                }
            }

        }

        foreach ($this->orphaned_outputs() as $path) {
            $found[] = static::finding(Diagnostic::WARNING, basename($path), 'Orphaned output: no registered handle builds this.', $path);
        }

        return $found;

    }

    /**
     * Build-directory files no discovered asset claims.
     *
     * Dotfiles and directories are skipped: the Dart engine's own .sassy-tmp lives here, and a
     * check that reports Sassy's own working directory on its first run teaches people to
     * ignore it. An orphan stays a warning however well this is scoped, because a handle
     * enqueued only on some template is discovered by no hook set at all.
     *
     * @return string[]
     */
    public function orphaned_outputs () {

        $claimed = [];
        $roots   = [];

        foreach ($this->compilable() as $asset) {

            $target = new Build_Target($asset);

            $claimed[$target->get_file()]     = true;
            $claimed[$target->get_map_path()] = true;
            $roots[$target->get_path()]       = true;

        }

        $found = [];

        foreach (array_keys($roots) as $root) {
            foreach ((glob(rtrim($root, '/') . '/*.css') ?: []) as $path) {
                if (!isset($claimed[$path]) && is_file($path))                  $found[] = $path;
                if (!isset($claimed[$path . '.map']) && is_file($path . '.map')) $found[] = $path . '.map';
            }
        }

        return $found;

    }

    /**
     * $fatal marks a diagnostic that fails regardless of severity: truncation is a warning, but
     * it makes "everything is current" unknowable rather than merely untidy.
     */
    protected static function finding ($severity, $subject, $message, $file = null, $fatal = false) {

        $diagnostic = new Diagnostic($severity, $message, ['code' => $subject, 'file' => $file, 'source' => 'sassy']);
        $diagnostic->fatal = $fatal || ($severity === Diagnostic::ERROR);

        return $diagnostic;

    }

    /**
     * @return array<string, string> context => message, for contexts that raised.
     */
    public function context_errors () {

        return $this->context_errors;

    }

    /**
     * Copies rather than fresh registries: a handle registered at init outside any enqueue hook
     * is in the live registry and nowhere else, and discovery has to keep seeing it.
     *
     * @return callable Puts the originals back.
     */
    protected static function isolate () {

        $styles  = wp_styles();
        $scripts = function_exists('wp_scripts') ? wp_scripts() : null;
        $screen  = function_exists('get_current_screen') ? get_current_screen() : null;

        $GLOBALS['wp_styles'] = static::copy($styles);
        if ($scripts) $GLOBALS['wp_scripts'] = static::copy($scripts);

        return function () use ($styles, $scripts, $screen) {
            $GLOBALS['wp_styles'] = $styles;
            if ($scripts) $GLOBALS['wp_scripts'] = $scripts;
            if ($screen) set_current_screen($screen);
        };

    }

    protected static function copy ($registry) {

        $copy = clone $registry;

        // A shallow clone shares the _WP_Dependency objects, so wp_add_inline_style() on an
        // existing handle would reach the live page.
        if (isset($copy->registered) && is_array($copy->registered)) {
            foreach ($copy->registered as $handle => $dependency) {
                if (is_object($dependency)) $copy->registered[$handle] = clone $dependency;
            }
        }

        return $copy;

    }

    protected function fire ($context) {

        // Unwind to the level we started at, not one buffer: a callback that throws while
        // holding its own buffer would otherwise leave ours open and swallow later output.
        $level = ob_get_level();

        ob_start();

        try {
            $this->fire_context($context);
        } catch (\Throwable $e) {
            $this->context_errors[$context] = $e->getMessage();
        }

        while (ob_get_level() > $level) ob_end_clean();

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

        foreach (apply_filters('sassy-script-queues', [function_exists('wp_scripts') ? wp_scripts() : null]) as $queue) {

            if (!$queue || !isset($queue->registered)) continue;

            foreach ($queue->registered as $dependency) {
                $this->scripts[$dependency->handle] = Asset::from_dependency($dependency, 'script');
            }

        }

    }

}
