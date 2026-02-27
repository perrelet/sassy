<?php

namespace Sassy;

class Compile_Result {

    public $css;
    public $map;        // string|null
    public $error;      // string|null

    public function __construct ($css = null, $map = null, $error = null) {

        $this->css   = $css;
        $this->map   = $map;
        $this->error = $error;

    }

    public function ok () : bool {

        return $this->error === null;

    }

}