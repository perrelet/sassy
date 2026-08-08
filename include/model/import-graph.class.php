<?php

namespace Sassy;

/**
 * The files a build was compiled from, and the paths that would change what it resolves to.
 */
class Import_Graph {

    /** @var array<string, int> path => mtime */
    public $deps;

    /** @var array<int, string> Paths searched and not found, ahead of the winning candidate. */
    public $misses;

    public function __construct (array $deps = [], array $misses = []) {

        $this->deps   = $deps;
        $this->misses = $misses;

    }

    /**
     * @return static|null Null when nothing usable was stored.
     */
    public static function from_array ($data) {

        if (!is_array($data) || empty($data['deps'])) return null;

        return new static($data['deps'], $data['misses'] ?? []);

    }

    public function to_array () {

        return [
            'deps'   => $this->deps,
            'misses' => $this->misses,
        ];

    }

    public function has_changed ($entry) {

        if (!isset($this->deps[$entry])) return true;

        foreach ($this->deps as $path => $mtime) {
            if (!is_file($path) || filemtime($path) != $mtime) return true;
        }

        // A file appearing at a path we searched and missed would shadow the candidate that won.
        foreach ($this->misses as $path) {
            if (file_exists($path)) return true;
        }

        return false;

    }

}
