<?php

namespace Sassy;

/**
 * How Sassy is configured and what it can reach, as rows. One source for `wp sassy status` and
 * the admin page, so the two cannot disagree.
 */
class Status {

    /**
     * @return array<string, string> setting => value, in display order.
     */
    public static function rows () {

        $printer = new Printer();
        $rows    = [];

        $rows['version']      = defined('SASSY_VERSION') ? SASSY_VERSION : '?';
        $rows['engine']       = $printer->get_engine_class();
        $rows['capabilities'] = implode(', ', $printer->get_engine()->capabilities());
        $rows['output style'] = $printer->get_style();

        // Every per-compile filter takes the Asset as its fourth argument, so a probe has to
        // hand one over: a callback typed against it would fatal otherwise.
        $probe = new Asset('sassy-status-probe', false);

        $rows['source maps']         = apply_filters('sassy-src-map', true, false, $probe->handle, $probe) ? 'on' : 'off';
        $rows['dependency checking'] = apply_filters('sassy-check-dependencies', true, false, $probe->handle, $probe) ? 'on' : 'off (compile explicitly)';

        foreach (Extensions::providers() as $kind => $slugs) {
            $rows[str_replace('_', ' ', $kind)] = $slugs ? implode(', ', $slugs) : '(none registered)';
        }

        $sass_bin = (new Dart_Sass_Engine())->get_sass_bin();
        $rows['dart sass binary'] = $sass_bin ?: '(not configured)';
        if ($sass_bin) $rows['dart sass version'] = static::probe($sass_bin . ' --version');

        if (!apply_filters('sassy-lightning-css', true, false, $probe->handle, $probe)) {
            $rows['lightning css'] = 'disabled by filter';
        } else if ($bin = Lightning_CSS_Postprocessor::resolve_bin()) {
            $rows['lightning css']        = 'enabled';
            $rows['lightning css binary'] = $bin;
        } else {
            $rows['lightning css'] = 'off (no binary configured)';
        }

        $build_path = $printer->get_build_path();
        $rows['build path']          = $build_path;
        $rows['build path writable'] = is_dir($build_path) ? (is_writable($build_path) ? 'yes' : 'NO') : '(not created yet)';

        // Per request, so this is all a policy can say about who sees the surface.
        $rows['dev surface'] = Policy::active() ? 'active for the current user' : 'inactive for the current user';
        $rows['sassy-dev']   = has_filter('sassy-dev') ? 'bound' : 'default (edit_theme_options)';

        foreach (['SASSY_DART_SASS_BIN', 'SASSY_LIGHTNINGCSS_BIN', 'SASSY_TOOLS_DIR'] as $constant) {
            $rows[$constant] = defined($constant) ? constant($constant) : '(undefined)';
        }

        return $rows;

    }

    protected static function probe ($command) {

        $out  = [];
        $code = 0;

        exec($command . ' 2>&1', $out, $code);

        return $code === 0 && $out ? trim($out[0]) : '(not runnable)';

    }

}
