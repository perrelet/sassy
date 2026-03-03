<?php

namespace Sassy;

/**
 * Optional Lightning CSS post-processor for compiled CSS.
 *
 * Invoked via the sassy-css filter, and shells out to a lightningcss CLI
 * binary when configured. If the binary is not configured or execution
 * fails, the original CSS is returned unchanged.
 */
class Lightning_CSS_Postprocessor {

    /**
     * Filter callback for sassy-css.
     *
     * @param string        $css      Compiled CSS from the SCSS engine.
     * @param string        $src      Original SCSS URL.
     * @param string        $handle   Style handle.
     * @param SCSS_Compiler $compiler Compiler instance.
     * @return string
     */
    public static function filter ($css, $src, $handle, $compiler) {

        $bin = self::resolve_bin();
    
        // Only run when enabled
        if (!apply_filters('sassy-lightning-css', true, $src, $handle, $compiler)) {
            return $css;
        }
    
        $in  = wp_tempnam("sassy-in-{$handle}.css");
        $out = wp_tempnam("sassy-out-{$handle}.css");
    
        if (!$in || !$out) return $css;
    
        file_put_contents($in, $css);
    
        $tools_dir = defined('SASSY_TOOLS_DIR') ? rtrim(SASSY_TOOLS_DIR, "\\/") : null;
    
        // Decide whether $bin is npx or a cli.js
        $cmd = [];
    
        if (preg_match('/npx(\.cmd)?$/i', $bin) || $bin === 'npx') {
    
            // npx lightningcss ...
            $cmd = [$bin, 'lightningcss', '--minify', $in, '-o', $out];
    
        } else if (preg_match('/\.js$/i', $bin)) {
    
            // node cli.js ...
            $node = self::find_node();
            if (!$node) return $css;
    
            $cmd = [$node, $bin, '--minify', $in, '-o', $out];
    
        } else {
    
            // direct binary (unlikely), treat as command
            $cmd = [$bin, '--minify', $in, '-o', $out];
    
        }
    
        $result = self::run_process($cmd, $tools_dir);
    
        if ($result['code'] !== 0 || !file_exists($out)) {
            // Optionally log $result['stderr']
            @unlink($in);
            @unlink($out);
            return $css;
        }
    
        $processed = file_get_contents($out);
    
        @unlink($in);
        @unlink($out);
    
        return $processed ?: $css;

    }

    protected static function resolve_bin () {

        // 1) Constant override
        if (defined('SASSY_LIGHTNINGCSS_BIN') && SASSY_LIGHTNINGCSS_BIN) {
            return SASSY_LIGHTNINGCSS_BIN;
        }
    
        // 2) Filter override (lets you set per environment without editing files)
        $filtered = apply_filters('sassy-lightning-css-binary', null);
        if ($filtered) return $filtered;
    
        // 3) Try "tools" folder (you choose the location)
        $tools_dir = defined('SASSY_TOOLS_DIR') ? rtrim(SASSY_TOOLS_DIR, "\\/") : null;
    
        if ($tools_dir) {
    
            // Prefer npx from the active node install if possible
            // We'll use npx but set cwd to $tools_dir when executing.
            $npx = self::find_npx();
            if ($npx) return $npx;
    
            // Or fall back to direct node + cli.js (less ideal)
            $cli = $tools_dir . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR . 'lightningcss-cli' . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'cli.js';
            if (file_exists($cli)) return $cli;
        }
    
        // 4) Last resort: hope npx is callable
        return 'npx';
    }
    
    protected static function find_npx () {
    
        // On Windows with nvm, where.exe can be unreliable, but try.
        if (stripos(PHP_OS, 'WIN') === 0) {
    
            $out = @shell_exec('where npx 2>NUL');
            if ($out) {
                $line = trim(strtok($out, "\r\n"));
                if ($line && file_exists($line)) return $line;
            }
    
            // Common locations:
            $candidates = [
                getenv('APPDATA') . '\\npm\\npx.cmd',
                'C:\\Program Files\\nodejs\\npx.cmd',
            ];
    
            foreach ($candidates as $c) {
                if ($c && file_exists($c)) return $c;
            }
    
            return null;

        }
    
        // mac/linux
        $out = @shell_exec('command -v npx 2>/dev/null');
        $path = $out ? trim($out) : null;
        return ($path && file_exists($path)) ? $path : null;

    }

    protected static function run_process (array $cmd, ?string $cwd = null, ?array $env = null, int $timeout_seconds = 60) : array {

        // Normalize cwd
        if ($cwd) {
            $cwd = rtrim($cwd, "\\/");
    
            if (!is_dir($cwd)) {
                return [
                    'ok'     => false,
                    'code'   => 127,
                    'stdout' => '',
                    'stderr' => "Invalid cwd: {$cwd}",
                    'cmd'    => $cmd,
                ];
            }
        }
    
        // Build descriptor spec: stdin, stdout, stderr
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
    
        // On Windows, proc_open expects either:
        // - array form (best), or
        // - a correctly quoted string (fragile).
        //
        // We'll keep array form, but ensure the first element exists.
        if (empty($cmd) || !is_string($cmd[0]) || $cmd[0] === '') {
            return [
                'ok'     => false,
                'code'   => 127,
                'stdout' => '',
                'stderr' => 'Empty command.',
                'cmd'    => $cmd,
            ];
        }
    
        // Merge env if provided
        $proc_env = null;
        if (is_array($env)) {
            $proc_env = array_merge($_ENV, $env);
        }
    
        $options = [];
    
        // Helpful on Windows to avoid opening a new console window.
        if (stripos(PHP_OS, 'WIN') === 0) {
            $options['bypass_shell'] = true;
            $options['suppress_errors'] = true;
        }
    
        $process = @proc_open($cmd, $descriptors, $pipes, $cwd ?: null, $proc_env, $options);
    
        if (!is_resource($process)) {
            return [
                'ok'     => false,
                'code'   => 127,
                'stdout' => '',
                'stderr' => 'Failed to start process (proc_open returned non-resource).',
                'cmd'    => $cmd,
            ];
        }
    
        // We don't send anything to stdin
        fclose($pipes[0]);
    
        // Non-blocking read so we can implement a timeout
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
    
        $stdout = '';
        $stderr = '';
    
        $start = time();
    
        while (true) {
    
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
    
            $status = proc_get_status($process);
    
            if (!$status['running']) {
                break;
            }
    
            if ((time() - $start) > $timeout_seconds) {
                // Kill the process
                proc_terminate($process);
    
                // Drain any remaining output
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
    
                fclose($pipes[1]);
                fclose($pipes[2]);
    
                proc_close($process);
    
                return [
                    'ok'     => false,
                    'code'   => 124,
                    'stdout' => $stdout,
                    'stderr' => trim($stderr . "\nProcess timed out after {$timeout_seconds}s."),
                    'cmd'    => $cmd,
                ];
            }
    
            // Sleep briefly to avoid busy-wait
            usleep(25_000);
        }
    
        // Drain final output
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
    
        fclose($pipes[1]);
        fclose($pipes[2]);
    
        $exit_code = proc_close($process);
    
        return [
            'ok'     => ($exit_code === 0),
            'code'   => $exit_code,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'cmd'    => $cmd,
        ];

    }

}

