<?php

namespace Sassy;

use ScssPhp\ScssPhp\Deprecation;
use ScssPhp\ScssPhp\Logger\LoggerInterface;
use ScssPhp\ScssPhp\StackTrace\Trace;
use SourceSpan\FileSpan;
use SourceSpan\SourceSpan;

/**
 * Collects scssphp warnings as Diagnostics.
 *
 * Runs inside compilation, so it must never throw: an exception here surfaces as a compile
 * failure describing the logger rather than the stylesheet.
 */
class Scssphp_Logger implements LoggerInterface {

    protected $diagnostics = [];

    /**
     * @return Diagnostic[]
     */
    public function get_diagnostics () {

        return $this->diagnostics;

    }

    /**
     * A @warn arrives with a trace and no span; a deprecation with a span and no trace. Take the
     * location from whichever came.
     */
    public function warn (string $message, ?Deprecation $deprecation = null, ?FileSpan $span = null, ?Trace $trace = null) : void {

        try {

            $formatted = $trace ? trim($trace->getFormattedTrace()) : null;

            $this->diagnostics[] = new Diagnostic(
                $deprecation ? Diagnostic::DEPRECATION : Diagnostic::WARNING,
                $message,
                static::locate($span, $formatted) + [
                    'code'  => $deprecation ? $deprecation->value : null,
                    'trace' => $formatted ? static::indent($formatted) : null,
                ]
            );

        } catch (\Throwable $e) {

            $this->diagnostics[] = new Diagnostic(Diagnostic::WARNING, $message);

        }

    }

    public function debug (string $message, SourceSpan $span) : void {

        $this->diagnostics[] = new Diagnostic(Diagnostic::NOTICE, $message);

    }

    protected static function locate (?FileSpan $span, $trace) {

        if ($span) {
            return [
                'file'   => static::path((string) $span->getSourceUrl()),
                'line'   => $span->getStart()->getLine() + 1,
                'column' => $span->getStart()->getColumn() + 1,
            ];
        }

        if ($trace && preg_match('/^\s*(\S+) (\d+):(\d+)/', $trace, $m) && $m[1] !== '-') {
            return ['file' => static::path($m[1]), 'line' => (int) $m[2], 'column' => (int) $m[3]];
        }

        return ['file' => null, 'line' => null, 'column' => null];

    }

    /**
     * Spans report file:// URLs and, when handed one, percent-encode it. Emit a path something
     * can open.
     */
    protected static function path ($url) {

        $url = rawurldecode($url);

        return ($url === '' || $url === '-') ? null : preg_replace('#^file://#', '', $url);

    }

    /** Two spaces, matching the trace indentation Dart emits and the canonical rendering. */
    protected static function indent ($trace) {

        return preg_replace('/^/m', '  ', $trace);

    }

}
