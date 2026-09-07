<?php

/**
 * The retired Digitalis integration, rebuilt on the extension API.
 *
 * The whole thing is one registration, which is the point: it never needed a base class.
 */

Sassy\Extensions::register_variables('digitalis', 'sassy_fixture_digitalis');

function sassy_fixture_digitalis (array $variables, Sassy\Asset $asset) {

    if (!defined('DIGITALIS_FRAMEWORK_VERSION')) return $variables;

    return array_merge($variables, [
        'digitalis_path' => '"' . wp_normalize_path(DIGITALIS_FRAMEWORK_PATH) . '"',
        'digitalis_uri'  => '"' . wp_normalize_path(DIGITALIS_FRAMEWORK_URI) . '"',
    ]);

}
