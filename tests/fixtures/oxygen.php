<?php

/**
 * The retired Oxygen integration, rebuilt on the extension API.
 *
 * Stands down when the Digitalis framework handles SCSS itself, which is a suppression rather
 * than a version check and is the part a naive port loses.
 */

Sassy\Extensions::register_variables('oxygen', 'sassy_fixture_oxygen');

function sassy_fixture_oxygen (array $variables, Sassy\Asset $asset) {

    if (!defined('CT_VERSION')) return $variables;
    if (class_exists('Digitalis\Module\OXY_SCSS\OXY_SCSS')) return $variables;

    return array_merge(
        $variables,
        sassy_fixture_oxygen_colors(),
        sassy_fixture_oxygen_breakpoints(),
        sassy_fixture_oxygen_fonts(),
        sassy_fixture_oxygen_padding('sections', 'sec', 'container-padding-'),
        sassy_fixture_oxygen_padding('columns', 'col', 'padding-')
    );

}

function sassy_fixture_oxygen_slug ($string) {

    return trim(strtolower(str_replace(' ', '-', $string)));

}

function sassy_fixture_oxygen_colors () {

    if (!is_callable('oxy_get_global_colors')) return [];

    $variables = [];

    foreach (oxy_get_global_colors()['colors'] as $color) {
        $variables['c-' . sassy_fixture_oxygen_slug($color['name'])] = $color['value'];
    }

    return $variables;

}

function sassy_fixture_oxygen_breakpoints () {

    if (!is_callable('ct_get_global_settings') || !is_callable('oxygen_vsb_get_breakpoint_width') || !is_callable('oxygen_vsb_get_page_width')) return [];

    $defaults = ct_get_global_settings(true)['breakpoints'];
    asort($defaults);

    $variables = [];
    $sass_map  = [];

    foreach ($defaults as $name => $default_width) {

        $name  = sassy_fixture_oxygen_slug($name);
        $width = oxygen_vsb_get_breakpoint_width($name);

        $variables['b-' . $name] = $width;
        $sass_map[$name]         = $width . 'px';

    }

    $page_width = oxygen_vsb_get_page_width();

    $variables['b-page']      = $page_width;
    $sass_map['page']         = $page_width . 'px';
    $variables['breakpoints'] = $sass_map;

    return $variables;

}

function sassy_fixture_oxygen_fonts () {

    if (!is_callable('ct_get_global_settings')) return [];

    $variables = [];

    foreach (ct_get_global_settings()['fonts'] as $name => $font) {
        $variables['f-' . sassy_fixture_oxygen_slug($name)] = $font;
    }

    return $variables;

}

/**
 * Sections and columns differ only in their setting group and key prefix.
 */
function sassy_fixture_oxygen_padding ($group, $prefix, $key) {

    if (!is_callable('ct_get_global_settings')) return [];

    $settings  = ct_get_global_settings()[$group] ?? [];
    $variables = [];

    foreach (['left' => 'px', 'top' => 'py'] as $side => $suffix) {
        if (!empty($settings[$key . $side])) {
            $variables[$prefix . '-' . $suffix] = $settings[$key . $side] . ($settings[$key . $side . '-unit'] ?? '');
        }
    }

    return $variables;

}
