<?php

namespace Sassy;

/**
 * One reportable event from a compile: the contract between every surface.
 */
class Diagnostic {

    const ERROR       = 'error';
    const WARNING     = 'warning';
    const DEPRECATION = 'deprecation';
    const NOTICE      = 'notice';

    /** @var string One of the severity constants. */
    public $severity;

    /** @var string One line, no frame. */
    public $message;

    /** @var string|null Author file, never a temp path. */
    public $file;

    public $line;
    public $column;

    /** @var string|null Code excerpt as the engine drew it, gutters and all. */
    public $frame;

    /** @var string|null Include chain as the engine drew it. */
    public $trace;

    /** @var string|null Engine identifier, e.g. 'global-builtin'. */
    public $code;

    public $url;

    /** @var string 'engine' or 'sassy'. */
    public $source;

    /** @var bool Fails a check regardless of severity. Set by Style_Stack::audit(). */
    public $fatal = false;

    public function __construct ($severity, $message, array $fields = []) {

        $this->severity = $severity;
        $this->message  = trim($message);

        foreach (['file', 'line', 'column', 'frame', 'trace', 'code', 'url'] as $field) {
            $this->$field = $fields[$field] ?? null;
        }

        $this->source = $fields['source'] ?? 'engine';

    }

    public function is_error () {

        return $this->severity === static::ERROR;

    }

    /**
     * The canonical rendering, shared by the CLI, the panel and the clipboard.
     *
     * The header is Sassy's; everything below it is the engine's own drawing, reproduced
     * verbatim. Engines draw differently (Dart rules frames with box characters, scssphp with
     * ASCII) and that difference is carried, not normalized.
     */
    public function render () {

        // Both engines wrap longer messages. The header takes the first line; the rest follows
        // rather than being dropped.
        $lines = preg_split('/\R/', $this->message, 2);
        $where = $this->file ? $this->file . $this->position() . '  ' : '';
        $out   = strtoupper($this->severity) . '  ' . $where . $lines[0];

        if (isset($lines[1]) && trim($lines[1]) !== '') $out .= "\n" . rtrim($lines[1], "\n");

        if ($this->frame) $out .= "\n" . rtrim($this->frame, "\n");
        if ($this->trace) $out .= "\n" . rtrim($this->trace, "\n");

        return $out;

    }

    public static function render_all (array $diagnostics) {

        return implode("\n\n", array_map(function ($diagnostic) {
            return $diagnostic->render();
        }, $diagnostics));

    }

    public function to_array () {

        return [
            'severity' => $this->severity,
            'message'  => $this->message,
            'file'     => $this->file,
            'line'     => $this->line,
            'column'   => $this->column,
            'frame'    => $this->frame,
            'trace'    => $this->trace,
            'code'     => $this->code,
            'url'      => $this->url,
            'source'   => $this->source,
        ];

    }

    protected function position () {

        if (is_null($this->line))   return '';
        if (is_null($this->column)) return ':' . $this->line;

        return ':' . $this->line . ':' . $this->column;

    }

}
