<?php

namespace Sassy;

/**
 * Whether one asset's build is current, and the only thing that reads or writes the cache.
 */
class Compile_Cache {

    const NO_SOURCE = 'no source';
    const NOT_BUILT = 'not built';
    const STALE     = 'stale';
    const WARNING   = 'warning';
    const CURRENT   = 'current';
    const ERROR     = 'error';

    const GRAPH_KEY   = 'sassy-filemtimes-';
    const VARS_KEY    = 'sassy-vars-sig-';
    const HANDLES_KEY = 'sassy-handles';
    const DIAGNOSTICS_KEY = 'sassy-diagnostics-';
    const SURFACES_KEY    = 'sassy-surfaces';

    protected $asset;
    protected $target;
    protected $resolver;

    public function __construct (Asset $asset, Build_Target $target, Variable_Resolver $resolver) {

        $this->asset    = $asset;
        $this->target   = $target;
        $this->resolver = $resolver;

    }

    public function needs_compile () {

        if ($this->filter('sassy-force-compile', false)) return true;

        $build_file = $this->target->get_file();
        $recorded   = get_transient(static::GRAPH_KEY . $this->asset->handle) ?: [];

        if (!isset($recorded[$build_file])) return true;

        if ($this->filter('sassy-check-dependencies', true)) {
            $graph = Import_Graph::from_array($recorded);
            if (!$graph || $graph->has_changed($this->asset->get_source_path())) return true;
        }

        if ($this->resolver->get_signature() !== get_transient(static::VARS_KEY . $this->asset->handle)) return true;

        return !file_exists($build_file);

    }

    /**
     * The one asset-state vocabulary. Every surface renders these words; none derives its own.
     *
     * `error` is deliberately absent: a failed compile is never recorded, so the cache cannot
     * know about one. Only a Printer that just ran can report it, and Printer::get_state() does.
     */
    public function get_state () {

        $source = $this->asset->get_source_path();

        if (!$source || !file_exists($source))          return static::NO_SOURCE;
        if (!file_exists($this->target->get_file()))    return static::NOT_BUILT;
        if ($this->needs_compile())                     return static::STALE;

        $tally = static::get_tally($this->asset->handle);

        if (!empty($tally[Diagnostic::WARNING]) || !empty($tally[Diagnostic::DEPRECATION])) return static::WARNING;

        return static::CURRENT;

    }

    public function is_current () {

        $src_path = $this->asset->get_source_path();

        return $src_path && file_exists($src_path) && !$this->needs_compile();

    }

    /**
     * Written on success only: recording up front remembered a failed compile as current, so the
     * error vanished on the next request.
     */
    public function record (Import_Graph $graph, $compile_time, array $diagnostics = []) {

        $build_file = $this->target->get_file();

        // Counts, not the diagnostics themselves: this record is read on every request, and one
        // compile's frames and traces run to kilobytes. Enough for `check --strict` to gate on.
        set_transient(static::GRAPH_KEY . $this->asset->handle, array_merge([
            $build_file        => filemtime($build_file),
            '__compile_time__' => $compile_time,
            '__diagnostics__'  => static::tally($diagnostics),
        ], $graph->to_array()));

        set_transient(static::VARS_KEY . $this->asset->handle, $this->resolver->get_signature());

        static::index($this->asset->handle);

    }

    /**
     * Written after every compile that ran, failed or not, unlike the currency record: the page
     * has to show the error that made a handle stale. Nothing here feeds get_state().
     */
    public function record_diagnostics (array $diagnostics) {

        set_transient(static::DIAGNOSTICS_KEY . $this->asset->handle, [
            'time'        => time(),
            'diagnostics' => array_map(function ($diagnostic) { return $diagnostic->to_array(); }, $diagnostics),
        ]);

        static::index($this->asset->handle);

    }

    /**
     * @return array{time: int, diagnostics: Diagnostic[]}|null
     */
    public static function get_diagnostics ($handle) {

        $recorded = get_transient(static::DIAGNOSTICS_KEY . $handle);
        if (!is_array($recorded)) return null;

        return [
            'time'        => (int) ($recorded['time'] ?? 0),
            'diagnostics' => array_map([Diagnostic::class, 'from_array'], $recorded['diagnostics'] ?? []),
        ];

    }

    public function forget () {

        static::forget_handle($this->asset->handle);

    }

    public static function get_graph ($handle) {

        return Import_Graph::from_array(get_transient(static::GRAPH_KEY . $handle));

    }

    /**
     * How many diagnostics of each severity the last successful compile produced.
     *
     * @return array<string, int>
     */
    public static function get_tally ($handle) {

        $recorded = get_transient(static::GRAPH_KEY . $handle);

        return is_array($recorded) ? ($recorded['__diagnostics__'] ?? []) : [];

    }

    protected static function tally (array $diagnostics) {

        $counts = [];

        foreach ($diagnostics as $diagnostic) {
            $counts[$diagnostic->severity] = ($counts[$diagnostic->severity] ?? 0) + 1;
        }

        return $counts;

    }

    public static function get_last_compile_time ($handle) {

        $recorded = get_transient(static::GRAPH_KEY . $handle);

        return is_array($recorded) ? ($recorded['__compile_time__'] ?? null) : null;

    }

    public static function forget_handle ($handle) {

        delete_transient(static::GRAPH_KEY . $handle);
        delete_transient(static::VARS_KEY . $handle);
        delete_transient(static::DIAGNOSTICS_KEY . $handle);

    }

    /**
     * Under an external object cache the transients are not in the options table, so there is
     * nothing to pattern-match; the index record() keeps is walked instead.
     *
     * @return int Handles cleared under an external object cache, rows deleted otherwise.
     */
    public static function forget_all () {

        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {

            $handles = get_transient(static::HANDLES_KEY) ?: [];

            foreach ($handles as $handle) static::forget_handle($handle);
            delete_transient(static::HANDLES_KEY);
            // Not a handle, so not in the index; the SQL branch matches it by pattern.
            delete_transient(static::SURFACES_KEY);

            return count($handles);

        }

        global $wpdb;

        return (int) $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '\_transient\_sassy-%'
                OR option_name LIKE '\_transient\_timeout\_sassy-%'"
        );

    }

    /**
     * Every profiled script's markers, keyed by path with the stamp it was read at. One record:
     * 262 files on the reference install, and one key under an external object cache.
     *
     * @return array<string, array>
     */
    public static function get_surfaces () {

        $recorded = get_transient(static::SURFACES_KEY);

        return is_array($recorded) ? $recorded : [];

    }

    public static function set_surfaces (array $surfaces) {

        set_transient(static::SURFACES_KEY, $surfaces);

    }

    protected static function index ($handle) {

        $handles = get_transient(static::HANDLES_KEY) ?: [];

        if (in_array($handle, $handles, true)) return;

        $handles[] = $handle;
        set_transient(static::HANDLES_KEY, $handles);

    }

    protected function filter ($tag, $value) {

        return apply_filters($tag, $value, $this->asset->src, $this->asset->handle, $this->asset);

    }

}
