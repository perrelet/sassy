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

    /**
     * The recorded dependency a cited name refers to, or null. Engines cite differently: Dart a
     * bare basename or a path relative to its working directory, scssphp a URL. Reduce a URL to
     * its path, match by path suffix with canonical paths on both sides, and answer only when
     * exactly one dependency fits. Returns the key as recorded, which is what the stamp is under.
     */
    public function find ($cited) {

        if (!is_string($cited) || $cited === '' || !$this->deps) return null;

        if (preg_match('~^[a-z][a-z0-9+.\-]*://~i', $cited)) $cited = (string) parse_url($cited, PHP_URL_PATH);

        $cited = preg_replace('~^(\.\./)+~', '', str_replace('\\', '/', $cited));
        if ($cited === '') return null;

        $canonical = realpath($cited) ?: null;
        $suffix    = '/' . ltrim($cited, '/');
        $matches   = [];

        foreach (array_keys($this->deps) as $path) {
            $real = realpath($path) ?: $path;
            if ($path === $cited || ($canonical !== null && $real === $canonical) || str_ends_with($path, $suffix) || str_ends_with($real, $suffix)) $matches[$path] = true;
        }

        return count($matches) === 1 ? array_key_first($matches) : null;

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
