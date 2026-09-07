<?php

namespace Sassy;

/**
 * The four things you can extend, and who is extending them.
 *
 * Filters remain the binding mechanism and keep working untouched. Registering instead gives a
 * provider a name, a typed signature and a line in `wp sassy status`; a post-processor also gains
 * somewhere to report, which a `sassy-css` callback has no way to do.
 */
class Extensions {

    const KINDS = ['load_paths', 'variables', 'post_processors', 'engines'];

    protected static $providers = [];

    /**
     * Fired so providers have a place to register. Registering later still works, as long as it
     * happens before the asset in question resolves its variables.
     */
    public static function boot () {

        do_action('sassy-register');

    }

    /** @param callable $provider function (array $paths, Asset $asset) : array */
    public static function register_load_paths ($slug, callable $provider) {

        static::add('load_paths', $slug, $provider);

    }

    /** @param callable $provider function (array $variables, Asset $asset) : array */
    public static function register_variables ($slug, callable $provider) {

        static::add('variables', $slug, $provider);

    }

    /** @param callable $processor function (string $css, Post_Process_Context $context) : string */
    public static function register_post_processor ($slug, callable $processor) {

        static::add('post_processors', $slug, $processor);

    }

    /** @param callable $provider function (?Compiler_Engine $engine, Asset $asset) : ?Compiler_Engine */
    public static function register_engine ($slug, callable $provider) {

        static::add('engines', $slug, $provider);

    }

    public static function unregister ($kind, $slug) {

        unset(static::$providers[$kind][$slug]);

    }

    /**
     * @return array<string, string[]> kind => slugs, in registration order.
     */
    public static function providers ($kind = null) {

        if ($kind !== null) return array_keys(static::$providers[$kind] ?? []);

        $listed = [];
        foreach (static::KINDS as $known) $listed[$known] = array_keys(static::$providers[$known] ?? []);

        return $listed;

    }

    public static function apply_load_paths (array $paths, Asset $asset) {

        foreach (static::of('load_paths') as $provider) $paths = $provider($paths, $asset);

        return $paths;

    }

    public static function apply_variables (array $variables, Asset $asset) {

        foreach (static::of('variables') as $provider) $variables = $provider($variables, $asset);

        return $variables;

    }

    public static function resolve_engine ($engine, Asset $asset) {

        foreach (static::of('engines') as $provider) $engine = $provider($engine, $asset);

        return $engine;

    }

    /**
     * Runs after the sassy-css filter, so a registered processor sees what the filters produced.
     */
    public static function post_process ($css, Post_Process_Context $context) {

        foreach (static::of('post_processors') as $processor) $css = $processor($css, $context);

        return $css;

    }

    protected static function add ($kind, $slug, callable $provider) {

        // Re-registering a slug replaces it, which is what makes a provider overridable by name.
        static::$providers[$kind][$slug] = $provider;

    }

    protected static function of ($kind) {

        return static::$providers[$kind] ?? [];

    }

}
