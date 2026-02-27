<?php

namespace Sassy;

class Dart_Sass_Engine implements Compiler_Engine {

    protected $sass_bin;

    public function __construct ($sass_bin) {

        $this->sass_bin = $sass_bin;

    }

    public function compile (array $args) : Compile_Result {

        $tmp_in  = wp_tempnam('sassy-in.scss');
        $tmp_out = wp_tempnam('sassy-out.css');
        $tmp_map = $tmp_out . '.map';

        if (!$tmp_in || !$tmp_out) {
            return new Compile_Result(null, null, 'Unable to create temp files.');
        }

        file_put_contents($tmp_in, $args['scss']);

        $cmd = [];

        $cmd[] = escapeshellcmd($this->sass_bin);
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

        $full = implode(' ', $cmd) . ' 2>&1';

        $out = shell_exec($full);

        if (!file_exists($tmp_out)) {
            @unlink($tmp_in);
            @unlink($tmp_out);
            @unlink($tmp_map);

            $msg = is_string($out) ? trim($out) : 'Dart Sass compile failed.';
            return new Compile_Result(null, null, $msg);
        }

        $css = file_get_contents($tmp_out);
        $map = file_exists($tmp_map) ? file_get_contents($tmp_map) : null;

        @unlink($tmp_in);
        @unlink($tmp_out);
        @unlink($tmp_map);

        return new Compile_Result($css, $map);

    }

}