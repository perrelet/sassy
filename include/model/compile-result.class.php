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

    /** @var mixed Additional information about the compile (e.g. warnings). */
    public $info;

    public function __construct ($css = null, $map = null, $error = null, $info = null) {

        $this->css   = $css;
        $this->map   = $map;
        $this->error = $error;
        $this->info  = $info;

    }

    /**
     * Whether compilation succeeded (no error).
     */
    public function ok () : bool {

        return $this->error === null;

    }

}