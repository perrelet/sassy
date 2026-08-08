<?php

namespace Sassy;

/**
 * The files a build was compiled from, and the paths that would change what it resolves to.
 */
class Import_Graph {

    /** @var array<string, int> path => mtime */
    public $deps;

    /** @var array<string, int> dir => mtime (0 when absent) for directories searched during resolution */
    public $dirs;

    /** @var bool The walk hit Import_Scanner::MAX_FILES, so the graph is incomplete. */
    public $truncated;

    public function __construct (array $deps = [], array $dirs = [], $truncated = false) {

        $this->deps      = $deps;
        $this->dirs      = $dirs;
        $this->truncated = (bool) $truncated;

    }

    /**
     * @return static|null Null when nothing usable was stored.
     */
    public static function from_array ($data) {

        if (!is_array($data) || empty($data['deps'])) return null;

        return new static($data['deps'], $data['dirs'] ?? [], $data['truncated'] ?? false);

    }

    public function to_array () {

        return [
            'deps'      => $this->deps,
            'dirs'      => $this->dirs,
            'truncated' => $this->truncated,
        ];

    }

    public function has_changed ($entry) {

        if (!isset($this->deps[$entry])) return true;

        foreach ($this->deps as $path => $mtime) {
            if (!is_file($path) || filemtime($path) != $mtime) return true;
        }

        // A directory's mtime moves when an entry is added or removed, which is exactly when
        // a new file could shadow whichever candidate currently wins.
        foreach ($this->dirs as $path => $mtime) {
            if ((is_dir($path) ? filemtime($path) : 0) != $mtime) return true;
        }

        return false;

    }

}
