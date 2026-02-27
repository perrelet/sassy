<?php

namespace Sassy;

use ScssPhp\ScssPhp\Compiler;
use ScssPhp\ScssPhp\OutputStyle;
use Exception;

class Scssphp_Engine implements Compiler_Engine {

    protected $compiler;
    protected $style = OutputStyle::EXPANDED;

    public function __construct () {

        $this->compiler = new Compiler();

    }

    public function get_style () {

        return apply_filters('sassy-style', $this->style, $this->src, $this->handle, $this);

    }

    public function compile (array $args) : Compile_Result {

        try {

            if (!empty($args['source_map'])) {
                $this->compiler->setSourceMap(Compiler::SOURCE_MAP_FILE);
                $this->compiler->setSourceMapOptions($args['source_map_options'] ?? []);
            }

            $this->compiler->setFormatter($args['formatter'] ?? 'ScssPhp\ScssPhp\Formatter\Expanded');
            $this->compiler->setVariables($args['variables'] ?? []);

            foreach (($args['import_paths'] ?? []) as $path) {
                $this->compiler->addImportPath($path);
            }

            do_action('sassy-compiler', $this->compiler, $args);

            $css = $this->compiler->compile($args['scss'], $args['src_path']);

            return new Compile_Result($css);

        } catch (Exception $e) {

            return new Compile_Result(null, null, $e->getMessage());

        }

    }

}