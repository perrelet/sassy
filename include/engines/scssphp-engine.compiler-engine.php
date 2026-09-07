<?php

namespace Sassy;

use ScssPhp\ScssPhp\Compiler;
use ScssPhp\ScssPhp\OutputStyle;
use ScssPhp\ScssPhp\ValueConverter;
use ScssPhp\ScssPhp\Exception\SassException;
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

        $logger = new Scssphp_Logger();

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

            $this->compiler->setLogger($logger);

            do_action('sassy-compiler', $this->compiler, $args);

            $result = $this->compiler->compileString($args['scss'], $args['src_path'] ?? null);

            return new Compile_Result($result->getCss(), $result->getSourceMap(), null, null, $logger->get_diagnostics());

        } catch (SassException $e) {

            $diagnostics = array_merge($logger->get_diagnostics(), [static::diagnose($e)]);

            return new Compile_Result(null, null, $e->getOriginalMessage(), null, $diagnostics);

        } catch (Exception $e) {

            $diagnostics = array_merge($logger->get_diagnostics(), [new Diagnostic(Diagnostic::ERROR, $e->getMessage())]);

            return new Compile_Result(null, null, $e->getMessage(), null, $diagnostics);

        }

    }

    protected static function diagnose (SassException $e) {

        $span  = $e->getSpan();
        $trace = trim($e->getSassTrace()->getFormattedTrace());

        return new Diagnostic(Diagnostic::ERROR, $e->getOriginalMessage(), [
            'file'   => preg_replace('#^file://#', '', rawurldecode((string) $span->getSourceUrl())) ?: null,
            'line'   => $span->getStart()->getLine() + 1,
            'column' => $span->getStart()->getColumn() + 1,
            'trace'  => $trace !== '' ? preg_replace('/^/m', '  ', $trace) : null,
        ]);

    }

}
