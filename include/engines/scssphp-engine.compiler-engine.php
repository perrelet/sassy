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

    public function capabilities () : array {

        // No 'modules': scssphp 2.1 throws on @use and @forward.
        return ['source_maps', 'compressed'];

    }

    public function supports (string $capability) : bool {

        return in_array($capability, $this->capabilities(), true);

    }

    public function compile (Compile_Request $request) : Compile_Result {

        $logger = new Scssphp_Logger();

        try {

            if ($request->wants_map()) {
                $this->compiler->setSourceMap(Compiler::SOURCE_MAP_FILE);
                $this->compiler->setSourceMapOptions(static::map_options($request));
            }

            $style = $request->style;
            if (is_string($style)) {
                $style = OutputStyle::fromString($style);
            }
            $this->compiler->setOutputStyle($style);

            if ($request->variables) {
                $parsed = [];
                foreach ($request->variables as $name => $value) {
                    $parsed[$name] = $value instanceof \ScssPhp\ScssPhp\Value\Value
                        ? $value
                        : ValueConverter::parseValue($value);
                }
                $this->compiler->addVariables($parsed);
            }

            foreach ($request->load_paths as $path) {
                $this->compiler->addImportPath($path);
            }

            $this->compiler->setLogger($logger);

            do_action('sassy-compiler', $this->compiler, $request);

            $result = $this->compiler->compileString($request->source, $request->source_path);

            return new Compile_Result($result->getCss(), $result->getSourceMap(), null, $logger->get_diagnostics());

        } catch (SassException $e) {

            $diagnostics = array_merge($logger->get_diagnostics(), [static::diagnose($e)]);

            return new Compile_Result(null, null, $e->getOriginalMessage(), $diagnostics);

        } catch (Exception $e) {

            $diagnostics = array_merge($logger->get_diagnostics(), [new Diagnostic(Diagnostic::ERROR, $e->getMessage())]);

            return new Compile_Result(null, null, $e->getMessage(), $diagnostics);

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

    protected static function map_options (Compile_Request $request) {

        return [
            'sourceMapWriteTo'  => $request->map_path,
            'sourceMapURL'      => $request->map_url,
            'sourceMapFilename' => preg_replace('/\.map$/', '', (string) $request->map_url),
            // Forward slashes even on Windows: https://github.com/scssphp/scssphp/issues/35
            'sourceMapBasepath' => rtrim(str_replace('\\', '/', ABSPATH), '/'),
            'sourceMapRootpath' => trailingslashit(site_url()),
        ];

    }

}
