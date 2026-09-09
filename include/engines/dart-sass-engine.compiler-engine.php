<?php

namespace Sassy;

/**
 * Compiler engine using the Dart Sass CLI.
 *
 * Binary path resolves from the constructor argument, then the sassy-dart-sass-binary
 * filter, then the SASSY_DART_SASS_BIN constant. There is no implicit fallback.
 */
class Dart_Sass_Engine implements Compiler_Engine {

    /** @var string|null */
    protected $sass_bin;

    public function __construct ($sass_bin = null) {

        $this->sass_bin = $sass_bin;

    }

    public function get_sass_bin () {

        if ($this->sass_bin !== null && $this->sass_bin !== '') {
            return $this->sass_bin;
        }

        return apply_filters('sassy-dart-sass-binary', defined('SASSY_DART_SASS_BIN') ? SASSY_DART_SASS_BIN : null);

    }

    public function capabilities () : array {

        return ['modules', 'source_maps', 'compressed'];

    }

    public function supports (string $capability) : bool {

        return in_array($capability, $this->capabilities(), true);

    }

    public function compile (Compile_Request $request) : Compile_Result {

        if (!$sass_bin = $this->get_sass_bin()) {
            return new Compile_Result(null, null, 'Dart Sass binary path not set. Define SASSY_DART_SASS_BIN or use the sassy-dart-sass-binary filter.');
        }

        $src_path  = $request->source_path;
        $build_dir = $request->map_path
            ? rtrim(dirname($request->map_path), '/\\')
            : rtrim(get_temp_dir(), '/\\');

        // The temp input gets its own directory, never a source or load-path one: the import
        // graph watches directory mtimes for shadowing files, and creating a file in a watched
        // directory invalidates every handle compiled from it. Output goes in the build
        // directory so the map's source paths are relative to where the map is served from.
        $tmp_dir = $build_dir . '/.sassy-tmp';
        if (!is_dir($tmp_dir)) wp_mkdir_p($tmp_dir);

        static::sweep($tmp_dir . '/_sassy-*.tmp.scss');
        static::sweep($build_dir . '/.sassy-*.tmp.css*');

        $uniq    = uniqid();
        $tmp_in  = $tmp_dir . '/_sassy-' . $uniq . '.tmp.scss';
        $tmp_out = $build_dir . '/.sassy-' . $uniq . '.tmp.css';
        $tmp_map = $tmp_out . '.map';

        $scss = $request->source;
        if ($request->variables) $scss = Variable_Resolver::prepend($scss, $request->variables);

        if (file_put_contents($tmp_in, $scss) === false) {
            return new Compile_Result(null, null, 'Unable to write temporary SCSS file: ' . $tmp_in);
        }

        $cmd = [];

        $cmd[] = escapeshellarg($sass_bin);
        $cmd[] = escapeshellarg($tmp_in);
        $cmd[] = escapeshellarg($tmp_out);

        foreach ($request->load_paths as $path) {
            $cmd[] = '--load-path=' . escapeshellarg($path);
        }

        $cmd[] = $request->source_map ? '--source-map' : '--no-source-map';
        $cmd[] = ($request->style === 'compressed') ? '--style=compressed' : '--style=expanded';

        // Without this Dart Sass writes the error message into the output file as CSS.
        $cmd[] = '--no-error-css';

        // Dart drops repeated deprecations and says so. --strict=all cannot gate on what it
        // cannot see.
        $cmd[] = '--verbose';

        $output_lines = [];
        $exit_code    = 0;
        exec(implode(' ', $cmd) . ' 2>&1', $output_lines, $exit_code);
        $out = trim(implode("\n", $output_lines));

        // Dart Sass cites the temp copy, path and all, relative to the working directory. Match
        // the whole path token or the reader is left with a .sassy-tmp/ prefix that resolves to
        // nothing. Bare basenames are what Dart prints for every other frame.
        if ($out !== '' && $src_path) {
            $out = preg_replace('~\S*' . preg_quote(basename($tmp_in), '~') . '~', basename($src_path), $out);
        }

        if ($exit_code !== 0 || !file_exists($tmp_out)) {

            static::cleanup($tmp_in, $tmp_out, $tmp_map);

            $diagnostics = Dart_Sass_Parser::parse($out);
            if (!$diagnostics) $diagnostics = [new Diagnostic(Diagnostic::ERROR, 'Dart Sass compile failed.')];

            return new Compile_Result(null, null, $out !== '' ? $out : 'Dart Sass compile failed.', $diagnostics);

        }

        $css = file_get_contents($tmp_out);
        $map = file_exists($tmp_map) ? file_get_contents($tmp_map) : null;

        if ($map !== null) {
            $map = static::rewrite_map($map, basename($tmp_in), $build_dir, $src_path, $request->map_url ? basename(preg_replace('/\.map$/', '', $request->map_url)) : null);
        }

        $css = static::rewrite_map_url($css, $request->map_url);

        $diagnostics = Dart_Sass_Parser::parse($out);

        static::cleanup($tmp_in, $tmp_out, $tmp_map);

        return new Compile_Result($css, $map, null, $diagnostics);

    }

    /**
     * Point the map's entry source at the real file rather than the temp copy compiled from.
     */
    protected static function rewrite_map ($map, $tmp_basename, $map_dir, $src_path, $file = null) {

        if (!$src_path) return $map;

        $data = json_decode($map, true);
        if (!is_array($data) || empty($data['sources'])) return $map;

        // Dart names the temp output it was handed.
        if ($file) $data['file'] = $file;

        foreach ($data['sources'] as $i => $source) {
            if (basename($source) === $tmp_basename) {
                $data['sources'][$i] = static::relative_path($map_dir, $src_path);
            }
        }

        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES);

        return $encoded === false ? $map : $encoded;

    }

    /**
     * Dart Sass names whatever output file it was handed, which is a temp file.
     */
    protected static function rewrite_map_url ($css, $url) {

        $css = preg_replace('~/\*#\s*sourceMappingURL=[^\r\n]*\*/\s*$~', '', $css);

        return $url ? rtrim($css) . "\n\n/*# sourceMappingURL={$url} */\n" : $css;

    }

    protected static function relative_path ($from_dir, $to) {

        $from = explode('/', trim(str_replace('\\', '/', $from_dir), '/'));
        $to   = explode('/', trim(str_replace('\\', '/', $to), '/'));

        while ($from && $to && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        return str_repeat('../', count($from)) . implode('/', $to);

    }

    protected static function cleanup (...$paths) {

        foreach ($paths as $path) {
            if ($path) @unlink($path);
        }

    }

    /**
     * Drop temp files a killed compile never got to clean up.
     */
    protected static function sweep ($pattern, $max_age = 3600) {

        $cutoff = time() - $max_age;

        foreach ((glob($pattern) ?: []) as $path) {
            if (is_file($path) && filemtime($path) < $cutoff) @unlink($path);
        }

    }

}
