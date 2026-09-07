<?php

namespace Sassy;

/**
 * Where one asset's output goes: path, URL, filename and source map.
 */
class Build_Target {

    protected $asset;

    protected $directory;
    protected $path;
    protected $url;
    protected $name;
    protected $file;
    protected $map_options;

    public function __construct (Asset $asset) {

        $this->asset = $asset;

    }

    public function get_directory () {

        if (is_null($this->directory)) {

            $suffix = is_multisite() ? get_current_blog_id() . '/' : '';
            $this->directory = $this->filter('sassy-build-directory', '/scss/' . $suffix);

        }

        return $this->directory;

    }

    public function get_path () {

        if (is_null($this->path)) $this->path = $this->filter('sassy-build-path', WP_CONTENT_DIR) . $this->get_directory();

        return $this->path;

    }

    public function get_url () {

        if (is_null($this->url)) $this->url = $this->filter('sassy-build-url', WP_CONTENT_URL) . $this->get_directory() . $this->get_name();

        return $this->url;

    }

    public function get_name () {

        if (is_null($this->name)) {

            $source = explode('?', (string) $this->asset->src)[0];
            $this->name = $this->filter('sassy-build-name', basename($source, '.scss') . '.css');

        }

        return $this->name;

    }

    public function get_file () {

        if (is_null($this->file)) $this->file = $this->get_path() . $this->get_name();

        return $this->file;

    }

    public function get_map_path () {

        return $this->get_map_options()['sourceMapWriteTo'] ?? null;

    }

    public function get_map_url () {

        return $this->get_map_options()['sourceMapURL'] ?? null;

    }

    public function get_map_options () {

        if (is_null($this->map_options)) {

            $this->map_options = $this->filter('sassy-src-map-options', [
                'sourceMapWriteTo'  => str_replace('\\', '/', $this->get_path()) . $this->get_name() . '.map',
                'sourceMapURL'      => $this->get_url() . '.map',
                // Forward slashes even on Windows: https://github.com/scssphp/scssphp/issues/35
                'sourceMapBasepath' => rtrim(str_replace('\\', '/', ABSPATH), '/'),
                'sourceMapFilename' => $this->get_url(),
                'sourceMapRootpath' => trailingslashit(site_url()),
            ]);

        }

        return $this->map_options;

    }

    protected function filter ($tag, $value) {

        return apply_filters($tag, $value, $this->asset->src, $this->asset->handle, $this->asset);

    }

}
