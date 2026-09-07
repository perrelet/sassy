<?php

/**
 * Conformance: the retired integrations, rebuilt on the extension API, against stubbed builders.
 *
 * Neither builder is installed here and neither will be. If the API cannot express what these
 * integrations did, the API is wrong -- that is what this file is for.
 */

require __DIR__ . '/bootstrap.php';

use Sassy\Asset;
use Sassy\Extensions;

// Builder APIs. Bricks reads static class state, which the bootstrap's function stubs cannot
// cover, and neither can be redefined once declared, so they live here rather than in bootstrap.
define('BRICKS_VERSION', '1.9');
define('CT_VERSION', '4.0');
define('DIGITALIS_FRAMEWORK_VERSION', '1.0');
define('DIGITALIS_FRAMEWORK_PATH', '/var/www/framework/');
define('DIGITALIS_FRAMEWORK_URI', 'http://test.local/framework/');

eval('namespace Bricks; class Breakpoints {
    public static function get_breakpoints () {
        return [
            ["key" => "desktop",          "width" => 1280],
            ["key" => "tablet_portrait",  "width" => 991],
            ["key" => "mobile_portrait",  "width" => 478],
        ];
    }
}
class Theme_Styles {
    public static $active_settings = [
        "block"   => ["_columnGap" => "24px"],
        "section" => ["padding" => ["left" => "32px", "top" => "64px"]],
    ];
    public static $settings_by_id = [];
}');

function oxy_get_global_colors () {
    return ['colors' => [
        ['name' => 'Brand Primary', 'value' => '#ff0000'],
        ['name' => 'Ink',           'value' => '#111111'],
    ]];
}
function ct_get_global_settings ($defaults = false) {
    return [
        'breakpoints' => ['tablet' => 992, 'phone' => 480],
        'fonts'       => ['Body Copy' => 'Inter', 'Display' => 'Fraunces'],
        'sections'    => ['container-padding-left' => '20', 'container-padding-left-unit' => 'px',
                          'container-padding-top'  => '40', 'container-padding-top-unit'  => 'px'],
        'columns'     => ['padding-left' => '10', 'padding-left-unit' => 'px'],
    ];
}
function oxygen_vsb_get_breakpoint_width ($name) { return ['tablet' => 992, 'phone' => 480][$name] ?? 0; }
function oxygen_vsb_get_page_width ()            { return 1200; }

require __DIR__ . '/fixtures/digitalis.php';
require __DIR__ . '/fixtures/bricks.php';
require __DIR__ . '/fixtures/oxygen.php';

$asset = new Asset('entry', 'http://test.local/wp-content/themes/t/scss/entry.scss');

// No install runs Bricks and Oxygen together and they claim several of the same keys, so ask each
// provider directly rather than letting registration order decide the answer.

section('All three register as named providers');

check('three providers', Extensions::providers('variables') === ['digitalis', 'bricks', 'oxygen'], implode(',', Extensions::providers('variables')));

section('Digitalis');

$digitalis = sassy_fixture_digitalis([], $asset);

check('path', $digitalis['digitalis_path'] === '"/var/www/framework/"', $digitalis['digitalis_path'] ?? 'missing');
check('uri',  $digitalis['digitalis_uri'] === '"http://test.local/framework/"');
check('and nothing else',  count($digitalis) === 2);

section('Bricks');

$bricks = sassy_fixture_bricks([], $asset);

check('breakpoint by key',    $bricks['b-desktop'] === 1280);
check('desktop aliases page', $bricks['b-page'] === 1280);
check('tablet alias',         $bricks['b-tablet'] === 991);
check('phone-portrait alias', $bricks['b-phone-portrait'] === 478);
check('column gap',           $bricks['col-px'] === '24px');
check('section padding',      $bricks['sec-px'] === '32px' && $bricks['sec-py'] === '64px');
check('breakpoints map',      $bricks['breakpoints']['tablet'] === '991px');

section('Oxygen');

$oxygen = sassy_fixture_oxygen([], $asset);

check('colors are slugged',   $oxygen['c-brand-primary'] === '#ff0000');
check('second colour',        $oxygen['c-ink'] === '#111111');
check('fonts are slugged',    $oxygen['f-body-copy'] === 'Inter');
check('page width',           $oxygen['b-page'] === 1200);
check('breakpoint width',     $oxygen['b-tablet'] === 992);
check('breakpoints map',      $oxygen['breakpoints']['page'] === '1200px');
check('section padding',      $oxygen['sec-px'] === '20px' && $oxygen['sec-py'] === '40px');
check('column padding',       $oxygen['col-px'] === '10px');
check('no column py when unset', !isset($oxygen['col-py']));

section('Oxygen stands down for the framework');

eval('namespace Digitalis\Module\OXY_SCSS; class OXY_SCSS {}');

$suppressed = Extensions::apply_variables([], $asset);

check('no Oxygen colors',         !isset($suppressed['c-brand-primary']));
check('Bricks still applies',     $suppressed['b-desktop'] === 1280);
check('and Digitalis still does', isset($suppressed['digitalis_path']));

finish();
