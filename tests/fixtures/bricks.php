<?php

/**
 * The retired Bricks integration, rebuilt on the extension API.
 *
 * Reads Bricks\Breakpoints and Bricks\Theme_Styles, so the test stubs both.
 */

Sassy\Extensions::register_variables('bricks', 'sassy_fixture_bricks');

function sassy_fixture_bricks (array $variables, Sassy\Asset $asset) {

    if (!defined('BRICKS_VERSION')) return $variables;

    return array_merge($variables, sassy_fixture_bricks_breakpoints(), sassy_fixture_bricks_theme_styles());

}

function sassy_fixture_bricks_breakpoints () {

    $aliases = [
        'desktop'          => 'page',
        'tablet_portrait'  => 'tablet',
        'mobile_landscape' => 'phone-landscape',
        'mobile_portrait'  => 'phone-portrait',
    ];

    $variables = [];
    $sass_map  = [];

    foreach ((\Bricks\Breakpoints::get_breakpoints() ?: []) as $breakpoint) {

        $variables['b-' . $breakpoint['key']] = $breakpoint['width'];
        $sass_map[$breakpoint['key']]         = $breakpoint['width'] . 'px';

        if ($alias = ($aliases[$breakpoint['key']] ?? false)) {
            $variables['b-' . $alias] = $breakpoint['width'];
            $sass_map[$alias]         = $breakpoint['width'] . 'px';
        }

    }

    $variables['breakpoints'] = $sass_map;

    return $variables;

}

function sassy_fixture_bricks_theme_styles () {

    // active_settings is the modern property; older Bricks sorted by score into settings_by_id.
    $styles = property_exists(\Bricks\Theme_Styles::class, 'active_settings')
        ? \Bricks\Theme_Styles::$active_settings
        : reset(\Bricks\Theme_Styles::$settings_by_id);

    return [
        'col-px' => $styles['block']['_columnGap'] ?? ($styles['container']['_columnGap'] ?? ($styles['section']['_columnGap'] ?? '0px')),
        'sec-px' => $styles['section']['padding']['left'] ?? ($styles['section']['padding']['right'] ?? '0px'),
        'sec-py' => $styles['section']['padding']['top']  ?? ($styles['section']['padding']['bottom'] ?? '0px'),
    ];

}
