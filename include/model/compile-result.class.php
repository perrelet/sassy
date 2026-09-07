<?php

namespace Sassy;

/**
 * Result of a single compiler engine run.
 *
 * On success: css (and optionally map) are set, error is null.
 * On failure: error is set, css/map may be null.
 */
class Compile_Result {

    /** @var string|null Compiled CSS output. */
    public $css;

    /** @var string|null Source map content (e.g. JSON string). */
    public $map;

    /** @var string|null Error message when compilation failed. */
    public $error;

    /** @var Diagnostic[] */
    public $diagnostics;

    public function __construct ($css = null, $map = null, $error = null, array $diagnostics = []) {

        $this->css         = $css;
        $this->map         = $map;
        $this->error       = $error;
        $this->diagnostics = $diagnostics;

    }

    /**
     * @return Diagnostic[]
     */
    public function of ($severity) {

        return array_values(array_filter($this->diagnostics, function ($diagnostic) use ($severity) {
            return $diagnostic->severity === $severity;
        }));

    }

    public function errors () {

        return $this->of(Diagnostic::ERROR);

    }

    public function warnings () {

        return $this->of(Diagnostic::WARNING);

    }

    public function deprecations () {

        return $this->of(Diagnostic::DEPRECATION);

    }

    public function has_errors () {

        return (bool) $this->errors();

    }

    /**
     * Whether compilation succeeded (no error).
     */
    public function ok () : bool {

        return $this->error === null && !$this->has_errors();

    }

}