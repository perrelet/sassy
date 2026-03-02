<?php

namespace Sassy;

/**
 * Compiler engine using the Dart Sass CLI.
 *
 * Expects $args to match the Compiler_Engine contract (scss, src_path, import_paths,
 * variables as key => string, style, source_map). Variables are prepended to the
 * SCSS source for the CLI.
 *
 * Binary path is resolved at compile time for portability across dev/staging/production:
 * 1. Pass a path to the constructor when instantiating via the sassy-engine filter, or
 * 2. Define SASSY_DART_SASS_BIN in wp-config.php (e.g. per environment), or
 * 3. Use the sassy-dart-sass-binary filter to return the path.
 * Default if none set: "sass" (rely on PATH).
 */
class Dart_Sass_Engine implements Compiler_Engine {

    /** @var string|null Path to the sass executable, or null to resolve via filter/constant. */
    protected $sass_bin;

    /**
     * @param string|null $sass_bin Path to the Dart Sass binary, or null to use sassy-dart-sass-binary filter / SASSY_DART_SASS_BIN constant (default "sass").
     */
    public function __construct ($sass_bin = null) {

        $this->sass_bin = $sass_bin;

    }

    /**
     * Resolve the Dart Sass binary path (constructor, filter, constant, or "sass").
     *
     * @return string
     */
    protected function get_sass_bin () {

        if ($this->sass_bin !== null && $this->sass_bin !== '') {
            return $this->sass_bin;
        }

        return apply_filters('sassy-dart-sass-binary', defined('SASSY_DART_SASS_BIN') ? SASSY_DART_SASS_BIN : null);

    }

    /**
     * Compile SCSS to CSS by shelling out to the Dart Sass binary.
     *
     * @param array $args Must include scss; optional variables, import_paths, style, source_map.
     * @return Compile_Result
     */
    public function compile (array $args) : Compile_Result {

        if (!$sass_bin = $this->get_sass_bin()) {
            return new Compile_Result(null, null, 'Dart Sass binary path not set. Define SASSY_DART_SASS_BIN or use the sassy-dart-sass-binary filter.', null);
        }

        $tmp_in  = wp_tempnam('sassy-in.scss');
        $tmp_out = wp_tempnam('sassy-out.css');
        $tmp_map = $tmp_out . '.map';

        if (!$tmp_in || !$tmp_out) {
            return new Compile_Result(null, null, 'Unable to create temp files.', null);
        }

        $scss = $args['scss'] ?? '';
        if (!empty($args['variables'])) {
            $scss = SCSS_Compiler::prepend_variables($scss, $args['variables']);
        }

        file_put_contents($tmp_in, $scss);

        $cmd = [];

        $cmd[] = escapeshellarg($sass_bin);
        $cmd[] = escapeshellarg($tmp_in);
        $cmd[] = escapeshellarg($tmp_out);

        foreach (($args['import_paths'] ?? []) as $path) {
            $cmd[] = '--load-path=' . escapeshellarg($path);
        }

        // Variables: easiest is to prepend them as SCSS before compilation.
        // You can also generate a partial and @use it, but prepend works well for simple var injection.
        // If you want maps and quoted strings reliably, generate SCSS assignments carefully.

        if (!empty($args['source_map'])) {
            $cmd[] = '--source-map';
        } else {
            $cmd[] = '--no-source-map';
        }

        // Choose style: expanded or compressed
        if (($args['style'] ?? '') === 'compressed') {
            $cmd[] = '--style=compressed';
        } else {
            $cmd[] = '--style=expanded';
        }

        // Prevent Dart Sass from writing "error CSS" into the output file on failure.
        $cmd[] = '--no-error-css';

        $full = implode(' ', $cmd) . ' 2>&1';

        $output_lines = [];
        $exit_code    = 0;
        exec($full, $output_lines, $exit_code);
        $out = implode("\n", $output_lines);

        if ($exit_code !== 0 || !file_exists($tmp_out)) {
            @unlink($tmp_in);
            @unlink($tmp_out);
            @unlink($tmp_map);

            $msg = is_string($out) && $out !== '' ? trim($out) : 'Dart Sass compile failed.';
            return new Compile_Result(null, null, $msg, null);
        }

        $css = file_get_contents($tmp_out);
        $map = file_exists($tmp_map) ? file_get_contents($tmp_map) : null;

        $warnings = null;
        if (is_string($out)) {
            $trimmed = trim($out);
            if ($trimmed !== '') {
                $lines = preg_split('/\R/', $trimmed);
                $warnings = array_values(array_filter($lines, static function ($line) {
                    return trim($line) !== '';
                }));
            }
        }

        @unlink($tmp_in);
        @unlink($tmp_out);
        @unlink($tmp_map);

        return new Compile_Result($css, $map, null, $warnings);

    }

}