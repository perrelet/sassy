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

    const GRAPH_KEY = 'sassy-filemtimes-';
    const VARS_KEY  = 'sassy-vars-sig-';

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

    }

    /**
     * Pattern-matches the options table, so it finds nothing under an external object cache.
     * Callers that care must check wp_using_ext_object_cache() and clear by handle instead.
     */
    public static function forget_all () {

        global $wpdb;

        return (int) $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '\_transient\_sassy-%'
                OR option_name LIKE '\_transient\_timeout\_sassy-%'"
        );

    }

    protected function filter ($tag, $value) {

        return apply_filters($tag, $value, $this->asset->src, $this->asset->handle, $this->asset);

    }

}
