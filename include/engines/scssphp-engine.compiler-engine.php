<?php

namespace Sassy;

use ScssPhp\ScssPhp\Compiler;
use ScssPhp\ScssPhp\OutputStyle;
use ScssPhp\ScssPhp\ValueConverter;
use Exception;

/**
 * Compiler engine using the ScssPhp (scssphp) library.
 *
 * Accepts $args['variables'] as key => string (Sass expression, e.g. quoted URLs);
 * parses them to Value instances for the compiler.
 */
class Scssphp_Engine implements Compiler_Engine {

    protected $compiler;

    public function __construct () {

        $this->compiler = new Compiler();

    }

    /**
     * Compile SCSS to CSS using ScssPhp.
     *
     * @param array $args Must include scss, src_path; variables as Value[]; optional source_map, source_map_options, style, import_paths.
     * @return Compile_Result
     */
    public function compile (array $args) : Compile_Result {

        try {

            if (!empty($args['source_map'])) {
                $this->compiler->setSourceMap(Compiler::SOURCE_MAP_FILE);
                $this->compiler->setSourceMapOptions($args['source_map_options'] ?? []);
            }

            $style = $args['style'] ?? 'expanded';
            if (is_string($style)) {
                $style = OutputStyle::fromString($style);
            }
            $this->compiler->setOutputStyle($style);

            if (!empty($args['variables'])) {
                $parsed = [];
                foreach ($args['variables'] as $name => $value) {
                    $parsed[$name] = $value instanceof \ScssPhp\ScssPhp\Value\Value
                        ? $value
                        : ValueConverter::parseValue($value);
                }
                $this->compiler->addVariables($parsed);
            }

            foreach (($args['import_paths'] ?? []) as $path) {
                $this->compiler->addImportPath($path);
            }

            do_action('sassy-compiler', $this->compiler, $args);

            $result = $this->compiler->compileString($args['scss'], $args['src_path'] ?? null);
            $css = $result->getCss();
            $map = $result->getSourceMap();

            return new Compile_Result($css, $map);

        } catch (Exception $e) {

            return new Compile_Result(null, null, $e->getMessage());

        }

    }

}
