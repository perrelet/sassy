<?php

namespace Sassy;

/**
 * Contract for a SCSS compiler engine (e.g. Scssphp, Dart Sass).
 *
 * Implementations receive a normalized $args array and return a Compile_Result.
 *
 * Expected $args keys:
 *   - scss (string): SCSS source to compile
 *   - src_path (string): Path or URI of the main source file (for imports/source maps)
 *   - import_paths (array): List of directories for @import resolution
 *   - variables (array): Sass variables (key => value; format depends on engine)
 *   - style (string): Output style, e.g. 'expanded' or 'compressed'
 *   - source_map (bool): Whether to generate a source map
 *   - source_map_options (array): Engine-specific source map options
 */
interface Compiler_Engine {

    /**
     * Compile SCSS to CSS.
     *
     * @param array $args Normalized compile arguments (see interface docblock).
     * @return Compile_Result Success with css (and optionally map), or failure. Diagnostics either way.
     */
    public function compile (array $args) : Compile_Result;

}