<?php

namespace Sassy;

/**
 * What values one asset compiles with.
 */
class Variable_Resolver {

    protected $asset;
    protected $variables;

    public function __construct (Asset $asset) {

        $this->asset = $asset;

    }

    public function get_variables () {

        if (is_null($this->variables)) {

            $variables = [
                'wp-content-url'           => '"'. wp_normalize_path(WP_CONTENT_URL) . '"',
                'template-directory-url'   => '"'. wp_normalize_path(get_template_directory_uri()) . '"',
                'stylesheet-directory-url' => '"'. wp_normalize_path(get_stylesheet_directory_uri()) . '"',
            ];

            $variables = apply_filters('sassy-variables', $variables, $this->asset->src, $this->asset->handle, $this->asset);

            foreach ($variables as $key => $value) {
                if (is_array($value)) $variables[$key] = Scss_Map::from_array($value);
            }

            $this->variables = static::normalize_url_schemes($variables);

        }

        return $this->variables;

    }

    public function get_signature () {

        return sha1(serialize($this->get_variables()));

    }

    /**
     * One line, joined to the source's first: anything taller shifts every source map line
     * number by the number of variables injected.
     */
    public static function prepend ($scss, array $variables) {

        if (!$variables) return $scss;

        $lines = [];
        foreach ($variables as $key => $value) $lines[] = '$' . $key . ': ' . $value . ';';

        return implode(' ', $lines) . ' ' . $scss;

    }

    /**
     * is_ssl() is false under WP-CLI, so anything derived from it comes back http: mixed content
     * in the CSS, and a signature the first web request discards.
     */
    protected static function normalize_url_schemes (array $variables) {

        $home   = (string) get_option('home');
        $host   = parse_url($home, PHP_URL_HOST);
        $scheme = parse_url($home, PHP_URL_SCHEME);

        if (!$host || !$scheme) return $variables;

        $wrong = ($scheme === 'https' ? 'http' : 'https') . '://' . $host;
        $right = $scheme . '://' . $host;

        foreach ($variables as $key => $value) {
            if (is_string($value) && strpos($value, $wrong) !== false) $variables[$key] = str_replace($wrong, $right, $value);
        }

        return $variables;

    }

}
