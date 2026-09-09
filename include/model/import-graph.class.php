<?php

namespace Sassy;

/**
 * The files a build was compiled from, and the paths that would change what it resolves to.
 */
class Import_Graph {

    const MISSING = 'missing';
    const CHANGED = 'changed';
    const CURRENT = 'current';

    /** @var array<string, array{0: int, 1: int}> path => [mtime, size] */
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

    /**
     * The one file-state vocabulary, for a single dependency inside a graph. A different question
     * from an asset's state, which is why it keeps its own words.
     */
    public static function state_of ($path, $stamp) {

        if (!is_file($path)) return static::MISSING;

        return static::stamp_matches($path, $stamp) ? static::CURRENT : static::CHANGED;

    }

    /**
     * @return array{0: int, 1: int}|null [mtime, size], from one stat call.
     */
    public static function stamp ($path) {

        $stat = @stat($path);

        return $stat ? [$stat['mtime'], $stat['size']] : null;

    }

    /**
     * Size is compared alongside mtime because mtime is only second-resolution: a file saved
     * twice inside the same second would otherwise never be seen as changed again.
     */
    public static function stamp_matches ($path, $stamp) {

        if (!is_array($stamp)) return false;   // pre-2.1 shape, stored as a bare mtime

        $now = static::stamp($path);

        return $now && $now[0] == $stamp[0] && $now[1] == $stamp[1];

    }

    public function has_changed ($entry) {

        if (!isset($this->deps[$entry])) return true;

        foreach ($this->deps as $path => $stamp) {
            if (!static::stamp_matches($path, $stamp)) return true;
        }

        // A directory's mtime moves when an entry is added or removed, which is exactly when
        // a new file could shadow whichever candidate currently wins.
        foreach ($this->dirs as $path => $mtime) {
            if ((is_dir($path) ? filemtime($path) : 0) != $mtime) return true;
        }

        return false;

    }

}
