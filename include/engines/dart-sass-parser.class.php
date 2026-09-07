<?php

namespace Sassy;

/**
 * Turns Dart Sass stderr into Diagnostics.
 *
 * Dart offers no structured output, so this is the one place in the plugin that reads a tool's
 * prose. Anything it cannot recognise survives as a warning carrying the raw text.
 */
class Dart_Sass_Parser {

    const HEADER = '/^(DEPRECATION WARNING(?: \[([a-z0-9-]+)\])?|WARNING|Error)(?:\s*\[[a-z0-9-]+\])?:\s*(.*)$/';

    /**
     * @return Diagnostic[]
     */
    public static function parse ($output) {

        $output = trim((string) $output);
        if ($output === '') return [];

        $blocks = static::split($output);
        if (!$blocks) return [new Diagnostic(Diagnostic::WARNING, $output)];

        return array_map([static::class, 'diagnose'], $blocks);

    }

    /**
     * Split on header lines. Anything before the first one is prepended to it rather than
     * dropped.
     */
    protected static function split ($output) {

        $lines    = preg_split('/\R/', $output);
        $blocks   = [];
        $open     = null;
        $preamble = [];

        foreach ($lines as $line) {

            if (preg_match(static::HEADER, $line)) {
                if ($preamble) { $blocks[] = $preamble; $preamble = []; }
                if ($open !== null) $blocks[] = $open;
                $open = [$line];
                continue;
            }

            if ($open === null) {
                // One unrecognised block, not one per line.
                if (trim($line) !== '' || $preamble) $preamble[] = $line;
                continue;
            }

            $open[] = $line;

        }

        if ($preamble)      $blocks[] = $preamble;
        if ($open !== null) $blocks[] = $open;

        return $blocks;

    }

    protected static function diagnose (array $lines) {

        if (!preg_match(static::HEADER, $lines[0], $header)) {
            return new Diagnostic(Diagnostic::WARNING, trim(implode("\n", $lines)));
        }

        $severity = static::severity($header[1]);
        $message  = [$header[3]];
        $frame    = [];
        $trace    = [];
        $url      = null;
        $in_frame = false;

        foreach (array_slice($lines, 1) as $line) {

            if (preg_match('/^More info(?: and automated migrator)?:\s*(\S+)/', $line, $m)) {
                $url = $m[1];
                continue;
            }

            if (str_contains($line, '╷')) { $in_frame = true; $frame[] = $line; continue; }
            if (str_contains($line, '╵')) { $in_frame = false; $frame[] = $line; continue; }
            if ($in_frame)                { $frame[] = $line; continue; }

            if (static::is_trace($line)) { $trace[] = $line; continue; }

            if (trim($line) !== '' && !$trace) $message[] = $line;

        }

        return new Diagnostic($severity, implode("\n", $message), [
            'code'  => ($header[2] ?? '') !== '' ? $header[2] : null,
            'url'   => $url,
            'frame' => $frame ? implode("\n", $frame) : null,
            'trace' => $trace ? implode("\n", $trace) : null,
        ] + static::locate($trace));

    }

    protected static function severity ($header) {

        if ($header === 'Error')  return Diagnostic::ERROR;
        if ($header === 'WARNING') return Diagnostic::WARNING;

        return Diagnostic::DEPRECATION;

    }

    /** An indented `file line:col  description` line. */
    protected static function is_trace ($line) {

        return (bool) preg_match('/^\s+\S+ \d+:\d+\s+\S/', $line);

    }

    /**
     * The top trace frame, which is the one the caret is in.
     */
    protected static function locate (array $trace) {

        if (!$trace || !preg_match('/^\s*(\S+) (\d+):(\d+)/', $trace[0], $m)) {
            return ['file' => null, 'line' => null, 'column' => null];
        }

        return ['file' => $m[1], 'line' => (int) $m[2], 'column' => (int) $m[3]];

    }

}
