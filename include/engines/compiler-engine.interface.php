<?php

namespace Sassy;

/**
 * Contract for a SCSS compiler engine (e.g. Scssphp, Dart Sass).
 */
interface Compiler_Engine {

    /**
     * @return Compile_Result Success with css (and optionally map), or failure. Diagnostics either way.
     */
    public function compile (Compile_Request $request) : Compile_Result;

    /**
     * @return string[] e.g. ['modules', 'source_maps', 'compressed'].
     */
    public function capabilities () : array;

    public function supports (string $capability) : bool;

}