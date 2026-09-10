<?php

namespace Sassy;

/**
 * The dashboard under Tools: what status, list, deps and check print, for a person who is not
 * at a terminal, plus the two actions. Sections are functions from model answers to markup so
 * the suite can read what they render.
 */
class Admin_Page {

    const SLUG = 'sassy';

    public function __construct () {

        add_action('admin_menu', [$this, 'register']);
        add_action('admin_post_sassy_clear', [$this, 'clear']);

    }

    public function register () {

        if (!Policy::active()) return;

        // 'read' is not the gate, the filter is: a dev need not hold edit_theme_options.
        add_management_page('Sassy', 'Sassy', 'read', static::SLUG, [$this, 'render']);

    }

    public static function url () {

        return admin_url('tools.php?page=' . static::SLUG);

    }

    public function render () {

        if (!Policy::active()) wp_die('Sassy. But not sassy enough.', '', ['response' => 403]);

        echo static::html(static::data(), !empty($_GET['sassy-cleared']));

    }

    public function clear () {

        if (!Policy::active()) wp_die('Sassy. But not sassy enough.', '', ['response' => 403]);

        check_admin_referer('sassy-clear');

        Compile_Cache::forget_all();

        wp_safe_redirect(add_query_arg('sassy-cleared', 1, static::url()));
        exit;

    }

    // --- Data --------------------------------------------------------------

    /**
     * Everything the page shows, read from the model once.
     */
    public static function data (Style_Stack $stack = null) {

        $stack   = $stack ?: Style_Stack::discover(Style_Stack::CONTEXTS);
        $rows    = [];
        $handles = [];

        foreach ($stack->all() as $asset) {

            $row = [
                'handle'  => $asset->handle,
                'type'    => $asset->type,
                'kind'    => 'third-party',
                'state'   => '',
                'deps'    => $asset->deps,
                'imports' => '',
                'engine'  => '',
                'time'    => null,
                'source'  => $asset->get_source_path() ?? (is_string($asset->src) ? $asset->src : ''),
            ];

            if ($asset->is_compilable()) {

                $printer = (new Printer())->prepare($asset->src, $asset->handle);
                $graph   = Compile_Cache::get_graph($asset->handle);
                $state   = $printer->get_state();

                $row['kind']    = ($state === Compile_Cache::NOT_BUILT) ? 'compilable' : 'managed';
                $row['state']   = $state;
                $row['imports'] = $graph ? count($graph->deps) : 0;
                $row['engine']  = $printer->get_engine_class();
                $row['time']    = $printer->get_last_compile_time();

                $handles[$asset->handle] = static::handle_data($asset, $printer, $graph);

            }

            $rows[] = $row;

        }

        return [
            'status'         => Status::rows(),
            'findings'       => $stack->audit(),
            'stack'          => $rows,
            'handles'        => $handles,
            'context_errors' => $stack->context_errors(),
        ];

    }

    protected static function handle_data (Asset $asset, Printer $printer, $graph) {

        $target = new Build_Target($asset);

        return [
            'state'       => $printer->get_state(),
            'engine'      => $printer->get_engine_class(),
            'time'        => $printer->get_last_compile_time(),
            'source'      => $asset->get_source_path(),
            'source_url'  => is_string($asset->src) ? $asset->src : null,
            'built'       => $target->get_file(),
            'built_url'   => $target->get_url(),
            'is_built'    => file_exists($target->get_file()),
            'map'         => $target->get_map_path(),
            'map_url'     => $target->get_map_url(),
            'has_map'     => file_exists($target->get_map_path()),
            'graph'       => $graph,
            'diagnostics' => Compile_Cache::get_diagnostics($asset->handle),
        ];

    }

    /**
     * A cited file against the recorded graph. One rule for the page and the write endpoint,
     * and it lives with the graph.
     */
    public static function resolve_file ($cited, $graph) {

        return $graph ? $graph->find($cited) : null;

    }

    // --- Markup ------------------------------------------------------------

    public static function html (array $data, $cleared = false) {

        $out  = '<div class="wrap sassy-page">';
        $out .= '<h1>Sassy</h1>';

        if ($cleared) $out .= '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Caches cleared. The next request rebuilds.', 'sassy') . '</p></div>';

        foreach ($data['context_errors'] as $context => $message) {
            $out .= '<div class="notice notice-warning"><p>' . esc_html(sprintf(__('Discovery: the %s context raised: %s', 'sassy'), $context, $message)) . '</p></div>';
        }

        $out .= static::actions();
        $out .= static::findings($data['findings']);
        $out .= static::status($data['status']);
        $out .= static::stack($data['stack']);

        foreach ($data['handles'] as $handle => $detail) $out .= static::handle($handle, $detail);

        $out .= '</div>';

        return $out;

    }

    protected static function actions () {

        $out  = '<div class="sassy-actions">';
        $out .= '<button type="button" class="button button-primary" data-sassy-action="compile">' . esc_html__('Compile all', 'sassy') . '</button>';
        $out .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $out .= '<input type="hidden" name="action" value="sassy_clear">';
        $out .= wp_nonce_field('sassy-clear', '_wpnonce', true, false);
        $out .= '<button type="submit" class="button">' . esc_html__('Clear cache', 'sassy') . '</button>';
        $out .= '</form>';
        $out .= '<span class="description">' . esc_html__('Compile all rebuilds every handle under every hook set, cached or not. Clear cache forgets every recorded compile, so the next request rebuilds.', 'sassy') . '</span>';
        $out .= '</div>';

        return $out;

    }

    /**
     * What wp sassy check would say, rendered rather than exited on.
     */
    protected static function findings (array $findings) {

        $out = '<h2>' . esc_html__('Check', 'sassy') . '</h2>';

        if (!$findings) {
            return $out . '<div class="notice notice-success inline"><p>' . esc_html__('Everything current, nothing erroring, no orphans.', 'sassy') . '</p></div>';
        }

        $out .= '<table class="widefat striped sassy-findings"><thead><tr>';
        foreach (['severity', 'subject', 'message', 'file'] as $column) $out .= '<th>' . esc_html($column) . '</th>';
        $out .= '</tr></thead><tbody>';

        foreach ($findings as $finding) {
            $out .= '<tr>';
            $out .= '<td>' . static::badge($finding->severity, $finding->fatal) . '</td>';
            $out .= '<td>' . esc_html((string) $finding->code) . '</td>';
            $out .= '<td>' . esc_html($finding->message) . '</td>';
            $out .= '<td>' . ($finding->file ? static::copyable($finding->file, static::short($finding->file)) : '') . '</td>';
            $out .= '</tr>';
        }

        return $out . '</tbody></table>';

    }

    protected static function status (array $rows) {

        $out = '<h2>' . esc_html__('Status', 'sassy') . '</h2><table class="widefat striped sassy-status"><tbody>';

        foreach ($rows as $setting => $value) {
            $out .= '<tr><th scope="row">' . esc_html($setting) . '</th><td>' . esc_html($value) . '</td></tr>';
        }

        return $out . '</tbody></table>';

    }

    protected static function stack (array $rows) {

        $counts = ['all' => count($rows), 'managed' => 0, 'compilable' => 0, 'third-party' => 0];
        foreach ($rows as $row) $counts[$row['kind']]++;

        $out  = '<h2>' . esc_html__('Stack', 'sassy') . '</h2>';
        $out .= '<div class="sassy-filters">';

        foreach ($counts as $kind => $count) {
            $out .= '<button type="button" class="button" data-sassy-filter="' . esc_attr($kind) . '" aria-pressed="' . ($kind === 'all' ? 'true' : 'false') . '">' . esc_html($kind) . ' <span class="count">' . (int) $count . '</span></button>';
        }

        $out .= '</div>';
        $out .= '<table class="wp-list-table widefat striped sassy-stack"><thead><tr>';
        foreach (['handle', 'kind', 'state', 'deps', 'imports', 'engine', 'time', 'source'] as $column) $out .= '<th>' . esc_html($column) . '</th>';
        $out .= '</tr></thead><tbody>';

        foreach ($rows as $row) {

            $handle = ($row['kind'] === 'third-party')
                ? esc_html($row['handle'])
                : '<a href="#' . esc_attr(static::anchor($row['handle'])) . '">' . esc_html($row['handle']) . '</a>';

            $out .= '<tr data-kind="' . esc_attr($row['kind']) . '">';
            $out .= '<td>' . $handle . '</td>';
            $out .= '<td>' . esc_html($row['kind']) . '</td>';
            $out .= '<td>' . ($row['state'] !== '' ? static::state($row['state']) : '') . '</td>';
            $out .= '<td>' . esc_html(implode(', ', $row['deps'])) . '</td>';
            $out .= '<td>' . esc_html((string) $row['imports']) . '</td>';
            $out .= '<td>' . esc_html(static::engine_label($row['engine'])) . '</td>';
            $out .= '<td>' . esc_html(static::ms($row['time'])) . '</td>';
            $out .= '<td>' . esc_html(static::short($row['source'])) . '</td>';
            $out .= '</tr>';

        }

        return $out . '</tbody></table>';

    }

    protected static function handle ($handle, array $d) {

        $anchor = static::anchor($handle);

        $out  = '<section class="sassy-handle" id="' . esc_attr($anchor) . '">';
        $out .= '<h2>' . esc_html($handle) . ' ' . static::state($d['state']) . '</h2>';

        $out .= '<table class="widefat striped sassy-target"><tbody>';
        $out .= static::row(__('Source', 'sassy'),  static::link($d['source_url'], static::copyable($d['source'], static::short($d['source']))));
        $out .= static::row(__('Built', 'sassy'),   $d['is_built'] ? static::link($d['built_url'], static::copyable($d['built'], static::short($d['built']))) : esc_html__('not built', 'sassy'));
        $out .= static::row(__('Map', 'sassy'),     $d['has_map'] ? static::link($d['map_url'], static::copyable($d['map'], static::short($d['map']))) : esc_html__('none', 'sassy'));
        $out .= static::row(__('Engine', 'sassy'),  esc_html(static::engine_label($d['engine'])));
        $out .= static::row(__('Last compile', 'sassy'), esc_html(static::ms($d['time'])));
        $out .= '</tbody></table>';

        $out .= static::graph($d['graph']);
        $out .= static::diagnostics($anchor, $d['diagnostics'], $d['graph']);

        return $out . '</section>';

    }

    protected static function graph ($graph) {

        if (!$graph) return '<p class="description">' . esc_html__('No import graph recorded. Compile it first.', 'sassy') . '</p>';

        $out  = '<details class="sassy-graph"><summary>' . esc_html(sprintf(__('%d files, %d directories watched', 'sassy'), count($graph->deps), count($graph->dirs)));
        if ($graph->truncated) $out .= ' ' . static::badge(Diagnostic::WARNING, true) . ' ' . esc_html__('truncated', 'sassy');
        $out .= '</summary>';

        $out .= '<table class="widefat striped"><thead><tr><th>' . esc_html__('file', 'sassy') . '</th><th>' . esc_html__('modified', 'sassy') . '</th><th>' . esc_html__('state', 'sassy') . '</th></tr></thead><tbody>';

        foreach ($graph->deps as $path => $stamp) {
            $mtime = is_array($stamp) ? $stamp[0] : $stamp;
            $out  .= '<tr><td>' . static::copyable($path, static::short($path)) . '</td><td>' . esc_html(wp_date('Y-m-d H:i:s', $mtime)) . '</td><td>' . static::state(Import_Graph::state_of($path, $stamp)) . '</td></tr>';
        }

        $out .= '</tbody></table>';

        if ($graph->dirs) {
            $out .= '<p class="description">' . esc_html__('Watched for shadowing:', 'sassy') . ' ' . implode(', ', array_map(function ($dir) { return static::copyable($dir, static::short($dir)); }, array_keys($graph->dirs))) . '</p>';
        }

        return $out . '</details>';

    }

    /**
     * Header as interface, body verbatim, grouped by code. The canonical text stays in the
     * markup for copy, however the group is folded.
     */
    protected static function diagnostics ($anchor, $recorded, $graph) {

        $out = '<h3>' . esc_html__('Last diagnostics', 'sassy') . '</h3>';

        if (!$recorded) return $out . '<p class="description">' . esc_html__('Nothing recorded yet.', 'sassy') . '</p>';

        $diagnostics = $recorded['diagnostics'];
        $when        = $recorded['time'] ? wp_date('Y-m-d H:i:s', $recorded['time']) : '';

        if (!$diagnostics) return $out . '<p class="description">' . esc_html(sprintf(__('Clean at %s.', 'sassy'), $when)) . '</p>';

        $all_id = $anchor . '-canonical';

        $out .= '<p class="description">' . esc_html(sprintf(__('%d at %s.', 'sassy'), count($diagnostics), $when)) . ' ';
        $out .= '<button type="button" class="button button-small" data-sassy-copy-from="' . esc_attr($all_id) . '">' . esc_html__('Copy all', 'sassy') . '</button></p>';
        $out .= '<pre id="' . esc_attr($all_id) . '" class="sassy-canonical" hidden>' . esc_html(Diagnostic::render_all($diagnostics)) . '</pre>';

        $groups = [];
        foreach ($diagnostics as $i => $diagnostic) {
            $groups[$diagnostic->code ?: ($diagnostic->severity . ':' . strtok($diagnostic->message, "\n"))][] = [$i, $diagnostic];
        }

        foreach ($groups as $key => $members) {

            $first = $members[0][1];
            $open  = $first->is_error() ? ' open' : '';

            $out .= '<details class="sassy-group"' . $open . '><summary>';
            $out .= static::badge($first->severity, $first->fatal) . ' ';
            $out .= $first->code ? '<code>' . esc_html($first->code) . '</code> ' : '';
            $out .= '<span class="count">' . count($members) . '</span> ';
            $out .= '<span class="sassy-message">' . esc_html(strtok($first->message, "\n")) . '</span>';
            $out .= '</summary>';

            foreach ($members as [$i, $diagnostic]) $out .= static::diagnostic($anchor . '-' . $i, $diagnostic, $graph);

            $out .= '</details>';

        }

        return $out;

    }

    protected static function diagnostic ($id, Diagnostic $d, $graph) {

        $canonical = $d->render();
        $lines     = preg_split('/\R/', $canonical, 2);
        $body      = $lines[1] ?? '';
        $resolved  = static::resolve_file($d->file, $graph);
        $position  = ($d->line !== null ? ':' . $d->line . ($d->column !== null ? ':' . $d->column : '') : '');

        $out  = '<div class="sassy-diagnostic" data-severity="' . esc_attr($d->severity) . '">';
        $out .= '<div class="sassy-diagnostic-header">';
        $out .= static::badge($d->severity, $d->fatal);

        if ($d->file) {
            $out .= static::copyable(($resolved ?: $d->file) . $position, static::short($resolved ?: $d->file) . $position, $resolved ? '' : __('Not found in the recorded graph; the engine\'s text is kept.', 'sassy'));
        }

        $out .= '<span class="sassy-message">' . esc_html(strtok($d->message, "\n")) . '</span>';
        if ($d->url)  $out .= '<a class="sassy-doc" href="' . esc_url($d->url) . '" target="_blank" rel="noopener">' . esc_html($d->code ?: __('docs', 'sassy')) . '</a>';
        $out .= '<button type="button" class="button button-small" data-sassy-copy-from="' . esc_attr($id) . '">' . esc_html__('Copy', 'sassy') . '</button>';
        $out .= '</div>';

        if (trim($body) !== '') $out .= '<pre class="sassy-verbatim">' . esc_html($body) . '</pre>';

        $out .= '<pre id="' . esc_attr($id) . '" class="sassy-canonical" hidden>' . esc_html($canonical) . '</pre>';

        return $out . '</div>';

    }

    // --- Pieces ------------------------------------------------------------

    protected static function badge ($severity, $fatal = false) {

        return '<span class="sassy-badge" data-severity="' . esc_attr($severity) . '"' . ($fatal ? ' data-fatal="1"' : '') . '>' . esc_html($severity) . '</span>';

    }

    protected static function state ($state) {

        return '<span class="sassy-state" data-state="' . esc_attr($state) . '">' . esc_html($state) . '</span>';

    }

    protected static function copyable ($copy, $show, $title = '') {

        return '<code class="sassy-copy" data-sassy-copy="' . esc_attr($copy) . '" title="' . esc_attr($title ?: __('Click to copy', 'sassy')) . '">' . esc_html($show) . '</code>';

    }

    protected static function link ($url, $inner) {

        return $url ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . $inner . '</a>' : $inner;

    }

    protected static function row ($label, $inner) {

        return '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $inner . '</td></tr>';

    }

    public static function anchor ($handle) {

        return UI::node_id($handle) . '-handle';

    }

    /** Relative to ABSPATH where it is under it; the copy stays absolute. */
    protected static function short ($path) {

        $path = (string) $path;
        $root = rtrim(str_replace('\\', '/', ABSPATH), '/') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;

    }

    protected static function engine_label ($class) {

        return $class ? str_replace(['Sassy\\', '_Engine', '_'], ['', '', ' '], $class) : '';

    }

    protected static function ms ($seconds) {

        return $seconds ? round($seconds * 1000) . ' ms' : '';

    }

}
