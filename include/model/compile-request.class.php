<?php

namespace Sassy;

/**
 * What an engine is asked to build. Engine-neutral: no key here is any engine's option name.
 */
class Compile_Request {

    /** @var string SCSS source text. */
    public $source;

    /** @var string Path of the file that source came from. */
    public $source_path;

    /** @var string[] Directories searched by @use / @forward / @import. */
    public $load_paths = [];

    /** @var array<string, string> Variable name => Sass expression. */
    public $variables = [];

    /** @var string 'expanded' or 'compressed'. */
    public $style = 'expanded';

    public $source_map = false;

    /** @var string|null Where the map is written. */
    public $map_path;

    /** @var string|null Where the map is served from. */
    public $map_url;

    public function __construct (array $fields = []) {

        foreach ($fields as $key => $value) {
            if (property_exists($this, $key)) $this->$key = $value;
        }

    }

    public function wants_map () {

        return $this->source_map && $this->map_path;

    }

}
