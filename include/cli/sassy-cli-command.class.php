<?php

namespace Sassy;

use WP_CLI;
use WP_CLI_Command;
use WP_CLI\Utils;

/**
 * WP-CLI commands for Sassy.
 */
class Sassy_CLI_Command extends WP_CLI_Command {

    public function __construct () {

        // WP-CLI makes no HTTPS request, so is_ssl() is false and everything WordPress derives
        // from it -- get_template_directory_uri() and friends -- comes back http even on an
        // https site. That bakes mixed-content URLs into the CSS, and gives the variables a
        // different signature than a web request, so the first visitor recompiles anyway.
        if (!is_ssl() && parse_url((string) get_option('home'), PHP_URL_SCHEME) === 'https') {
            $_SERVER['HTTPS'] = 'on';
        }

    }

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

            $compiler = new Printer();
            $href     = $compiler->compile($style->src, $style->handle);

            if ($compiler->has_error()) {
                $errors[] = sprintf("%s\n%s", $style->handle, Diagnostic::render_all($compiler->get_errors()));
                continue;
            }

            foreach ($compiler->get_warnings() as $warning) {
                WP_CLI::warning(sprintf("%s\n%s", $style->handle, $warning->render()));
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
     * Recompile as files change, until interrupted.
     *
     * ## OPTIONS
     *
     * [<handle>...]
     * : Only watch these handles. Defaults to every discovered SCSS style.
     *
     * [--hooks=<hooks>]
     * : Which enqueue hooks to fire before looking for styles. Comma-separated, or "all".
     * ---
     * default: frontend
     * ---
     *
     * [--interval=<seconds>]
     * : Seconds between checks.
     * ---
     * default: 1
     * ---
     *
     * ## EXAMPLES
     *
     *     wp sassy watch
     *     wp sassy watch --hooks=all --interval=2
     *
     * @when after_wp_load
     */
    public function watch ($args, $assoc_args) {

        $styles = $this->discover($assoc_args, $args);

        if (!$styles) WP_CLI::error('No SCSS styles found. Try --hooks=all.');

        // Watching is an explicit request to watch; a production opt-out does not apply.
        add_filter('sassy-check-dependencies', '__return_true', 99);

        $interval = max(1, (int) ($assoc_args['interval'] ?? 1));
        $files    = 0;

        foreach ($styles as $style) {
            $compiler = $this->watch_once($style);
            $graph    = Compile_Cache::get_graph($style->handle);
            $files   += $graph ? count($graph->deps) : 0;
        }

        WP_CLI::log(sprintf('Watching %d handle(s), %d file(s), every %ds. Ctrl-C to stop.', count($styles), $files, $interval));

        $stop = function () {
            WP_CLI::log('');
            WP_CLI::log('Stopped.');
            exit(0);
        };

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, $stop);
            pcntl_signal(SIGTERM, $stop);
        }

        while (true) {

            sleep($interval);

            if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();

            // Long-running process: PHP would otherwise keep serving the stat results it cached
            // on the first pass, and nothing would ever look changed.
            clearstatcache();

            foreach ($styles as $style) $this->watch_once($style);

        }

    }

    /**
     * Compile one style if it is stale, reporting only when something actually happened.
     */
    protected function watch_once ($style) {

        $compiler = new Printer();
        $compiler->compile($style->src, $style->handle);

        $stamp = wp_date('H:i:s');

        if ($compiler->has_error()) {

            WP_CLI::log(sprintf('%s  %s  %s', $stamp, $style->handle, \WP_CLI::colorize('%RERROR%n')));

            foreach (preg_split('/\R/', Diagnostic::render_all($compiler->get_errors())) as $line) {
                WP_CLI::log('           ' . $line);
            }

            return $compiler;

        }

        if (!$compiler->has_compiled()) return $compiler;

        $warnings = $compiler->get_warnings();

        WP_CLI::log(sprintf(
            '%s  %s  %.2fs%s',
            $stamp,
            $style->handle,
            (float) $compiler->get_compile_time(),
            $warnings ? sprintf('  (%d warning%s)', count($warnings), count($warnings) === 1 ? '' : 's') : ''
        ));

        return $compiler;

    }

    /**
     * List every discovered style, and the cache state of the ones Sassy builds.
     *
     * ## OPTIONS
     *
     * [--compilable]
     * : Only styles Sassy can build.
     *
     * [--type=<type>]
     * : Styles, scripts, or both. A script row carries its style-mutation surface.
     * ---
     * default: style
     * options:
     *   - style
     *   - script
     *   - all
     * ---
     *
     * [--touches=<attribute>]
     * : Only scripts whose surface touches this data attribute, e.g. data-theme.
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

        $format  = $assoc_args['format'] ?? 'table';
        $type    = $assoc_args['type'] ?? 'style';
        $touches = $assoc_args['touches'] ?? null;
        $stack   = $this->stack($assoc_args);
        $assets  = empty($assoc_args['compilable']) ? $stack->all($touches ? 'script' : $type) : $stack->compilable();
        $items   = [];

        $surfaces = ($type !== 'style' || $touches)
            ? Style_Surface::profile(array_filter($assets, function ($asset) { return $asset->type === 'script'; }))
            : [];

        foreach ($assets as $asset) {

            $surface = ($asset->type === 'script') ? ($surfaces[$asset->handle] ?? null) : null;

            if ($touches && (!$surface || !$surface->touches($touches))) continue;

            $item = [
                'handle'  => $asset->handle,
                'type'    => $asset->type,
                'state'   => '',
                // An array survives json and yaml; the row formats cannot render one.
                'deps'    => in_array($format, ['json', 'yaml'], true) ? $asset->deps : implode(',', $asset->deps),
                'imports' => '',
                'engine'  => '',
                'time'    => '',
                'source'  => $asset->get_source_path() ?? (is_string($asset->src) ? $asset->src : ''),
                'built'   => '',
                'surface' => $surface ? (in_array($format, ['json', 'yaml'], true)
                    ? ['categories' => array_filter($surface->counts), 'markers' => $surface->markers, 'attributes' => $surface->attributes, 'reason' => $surface->reason]
                    : $surface->summary()) : '',
            ];

            if ($asset->is_compilable()) {

                $compiler = (new Printer())->prepare($asset->src, $asset->handle);
                $graph    = Compile_Cache::get_graph($asset->handle);
                $built    = $compiler->get_build_file();

                $item['state']   = $compiler->get_state();
                $item['imports'] = $graph ? count($graph->deps) : 0;
                $item['engine']  = $compiler->get_engine_class();
                $item['time']    = ($t = $compiler->get_last_compile_time()) ? sprintf('%.3fs', $t) : '';
                $item['built']   = $built;

            }

            $items[] = $item;

        }

        if (!$items) WP_CLI::warning($touches ? sprintf('No script touches %s.', Style_Surface::attribute_name($touches)) : 'Nothing found. Try --hooks=all.');

        $columns = ['handle', 'type', 'state', 'deps', 'imports', 'engine', 'time', 'source', 'built'];
        if ($surfaces || $touches) $columns[] = 'surface';

        Utils\format_items($format, $items, $columns);

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

        $compiler = new Printer();

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
     * Show the recorded import graph for a handle, or which handles import a file.
     *
     * ## OPTIONS
     *
     * [<handle>]
     * : The style handle. Omit when using --file.
     *
     * [--file=<path>]
     * : Report every handle whose import graph contains this file, the reverse direction.
     *
     * [--hooks=<hooks>]
     * : Which enqueue hooks to fire when discovering handles. Only used with --file.
     * ---
     * default: all
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
     * @when after_wp_load
     */
    public function deps ($args, $assoc_args) {

        if (!empty($assoc_args['file'])) {
            $this->dependents($assoc_args);
            return;
        }

        if (!$args) WP_CLI::error('Pass a handle, or --file=<path> for the reverse direction.');

        $graph = Compile_Cache::get_graph($args[0]);

        if (!$graph) WP_CLI::error(sprintf("No import graph recorded for '%s'. Compile it first.", $args[0]));

        $items = [];

        foreach ($graph->deps as $path => $stamp) {
            $items[] = [
                'file'     => $path,
                'modified' => wp_date('Y-m-d H:i:s', is_array($stamp) ? $stamp[0] : $stamp),
                'state'    => Import_Graph::state_of($path, $stamp),
            ];
        }

        Utils\format_items($assoc_args['format'] ?? 'table', $items, ['file', 'modified', 'state']);

        if ($graph->dirs) WP_CLI::log(sprintf('%d directory(s) watched for shadowing.', count($graph->dirs)));

        if ($graph->truncated) {
            WP_CLI::warning(sprintf('Graph truncated at %d files; changes beyond that will not invalidate the cache.', Import_Scanner::MAX_FILES));
        }

    }

    /**
     * Clear compile caches.
     *
     * ## OPTIONS
     *
     * [<handle>...]
     * : Only clear these handles. Omit to clear every Sassy cache entry.
     *
     * @when after_wp_load
     */
    public function clear ($args, $assoc_args) {

        if ($args) {

            foreach ($args as $handle) Compile_Cache::forget_handle($handle);

            WP_CLI::success(sprintf('Cleared %d handle(s).', count($args)));
            return;

        }

        WP_CLI::success(sprintf('Cleared %d cache entries.', Compile_Cache::forget_all()));

    }

    /**
     * One call, one exit code: is every style current, unbroken and accounted for?
     *
     * ## OPTIONS
     *
     * [--strict]
     * : Fail on warnings too, which includes orphaned outputs. Pass --strict=all to fail on
     * deprecations as well.
     *
     * [--hooks=<hooks>]
     * : Which enqueue hooks to fire. Defaults to all, alone among the commands: under a narrower
     * set every admin and editor output looks orphaned.
     * ---
     * default: all
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
     * ---
     *
     * ## EXAMPLES
     *
     *     wp sassy check
     *     wp sassy check --strict
     *
     * @when after_wp_load
     */
    public function check ($args, $assoc_args) {

        $strict      = $assoc_args['strict'] ?? false;
        $stack       = $this->stack($assoc_args + ['hooks' => 'all']);
        $diagnostics = $stack->audit();

        if (!$diagnostics) {
            WP_CLI::success(sprintf('%d handle(s) current, nothing erroring, no orphans.', count($stack->compilable())));
            return;
        }

        Utils\format_items($assoc_args['format'] ?? 'table', array_map(function ($diagnostic) {
            return [
                'severity' => $diagnostic->severity,
                'subject'  => $diagnostic->code,
                'message'  => $diagnostic->message,
                'file'     => $diagnostic->file,
            ];
        }, $diagnostics), ['severity', 'subject', 'message', 'file']);

        if (static::fails($diagnostics, $strict)) WP_CLI::halt(1);

        WP_CLI::success('No failures. Pass --strict to fail on the warnings above.');

    }

    /**
     * Which severities count as failure is the flag's business; what is true is the stack's.
     */
    protected static function fails (array $diagnostics, $strict) {

        foreach ($diagnostics as $diagnostic) {

            if ($diagnostic->fatal) return true;
            if (!$strict) continue;

            if ($diagnostic->severity === Diagnostic::WARNING) return true;
            if (($strict === 'all') && ($diagnostic->severity === Diagnostic::DEPRECATION)) return true;

        }

        return false;

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

        $items = [];

        foreach (Status::rows() as $setting => $value) $items[] = ['setting' => $setting, 'value' => (string) $value];

        Utils\format_items($assoc_args['format'] ?? 'table', $items, ['setting', 'value']);

    }

    /**
     * The reverse direction: which handles recompile when this file changes.
     */
    protected function dependents ($assoc_args) {

        $file = $assoc_args['file'];

        if (!file_exists($file)) WP_CLI::warning(sprintf('%s does not exist; reporting what the recorded graphs still reference.', $file));

        $stack = $this->stack($assoc_args + ['hooks' => 'all']);
        $items = [];

        foreach ($stack->dependents_of($file) as $handle => $asset) {
            $items[] = [
                'handle' => $handle,
                'source' => $asset->get_source_path(),
                'built'  => (new Build_Target($asset))->get_file(),
            ];
        }

        if (!$items) {
            WP_CLI::warning(sprintf('No compiled handle imports %s. It may be unused, or nothing has compiled since it was added.', $file));
            return;
        }

        Utils\format_items($assoc_args['format'] ?? 'table', $items, ['handle', 'source', 'built']);

    }

    protected function stack ($assoc_args) {

        $hooks    = $assoc_args['hooks'] ?? 'frontend';
        $contexts = Style_Stack::parse_contexts($hooks);

        if ($contexts === null) {
            WP_CLI::error(sprintf("Unknown hook set '%s'. Use: %s, all.", $hooks, implode(', ', Style_Stack::CONTEXTS)));
        }

        $stack = Style_Stack::discover($contexts);

        foreach ($stack->context_errors() as $context => $message) {
            WP_CLI::warning(sprintf("Context '%s' raised: %s", $context, $message));
        }

        return $stack;

    }

    /**
     * The styles Sassy can build, narrowed to $handles if given.
     *
     * @return Asset[]
     */
    protected function discover ($assoc_args, $handles = []) {

        $stack  = $this->stack($assoc_args);
        $styles = $stack->compilable();

        if ($handles) {

            $styles = array_intersect_key($styles, array_flip($handles));

            foreach ($handles as $handle) {
                if (isset($styles[$handle])) continue;
                WP_CLI::warning($this->why_not($stack, $handle));
            }

        }

        return $styles;

    }

    /**
     * Why a requested handle is not in the compilable set. Every answer names something the
     * caller can act on.
     */
    protected function why_not ($stack, $handle) {

        $asset = $stack->handle($handle);

        if (!$asset)                return sprintf("No style registered for handle '%s'. Try --hooks=all.", $handle);
        if ($asset->src === false)  return sprintf("Handle '%s' registers no source of its own.", $handle);
        if (!$asset->is_local())    return sprintf("Handle '%s' is not a local file: %s", $handle, $asset->src);

        return sprintf("Handle '%s' is not compilable: %s", $handle, $asset->extension ? '.' . $asset->extension : 'no extension');

    }

    protected function find_style ($assoc_args, $handle) {

        $stack = $this->stack($assoc_args);
        $asset = $stack->compilable()[$handle] ?? null;

        if (!$asset) WP_CLI::error($this->why_not($stack, $handle));

        return $asset;

    }

}
