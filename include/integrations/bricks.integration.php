<?php

namespace Sassy;

use Bricks\Breakpoints;
use Bricks\Theme_Styles;

class Bricks extends Integration {

    protected $variables;

    public function condition () {

        return defined("BRICKS_VERSION");

    }

    public function run () {

        add_filter('sassy-variables', [$this, 'compiler_variables'], 10, 1);

    }

    public function compiler_variables ($variables) {

        $variables = array_merge($variables, $this->get_variables());

        return $variables;

    }

    public function get_variables () {

        if (is_null($this->variables)) {

            $this->variables = [];

            //$this->variables = array_merge($this->variables, $this->get_colors());
            $this->variables = array_merge($this->variables, $this->get_breakpoints());
            //$this->variables = array_merge($this->variables, $this->get_fonts());
            $this->variables = array_merge($this->variables, $this->get_theme_styles());

        }

        return $this->variables;

    }

    protected function get_breakpoints () {

        $map = [
            'desktop'          => 'page',
            'tablet_portrait'  => 'tablet',
            'mobile_landscape' => 'phone-landscape',
            'mobile_portrait'  => 'phone-portrait',
        ];

        $breakpoints = [];
        $sass_map    = [];

        if ($brickpoints = Breakpoints::get_breakpoints()) foreach ($brickpoints as $brickpoint) {

            $breakpoints["b-" . $brickpoint['key']] = $brickpoint['width'];
            $sass_map[$brickpoint['key']]           = $brickpoint['width'] . 'px';

            if ($alt_name = ($map[$brickpoint['key']] ?? false)) {

                $breakpoints["b-" . $alt_name] = $brickpoint['width'];
                $sass_map[$alt_name]           = $brickpoint['width'] . 'px';

            }

        }

        $breakpoints['breakpoints'] = $this->array_to_sass_map($sass_map);

        return $breakpoints;

    }

    protected function get_theme_styles () {

        $styles = Theme_Styles::$active_settings;

        $col_px  = $styles['block']['_columnGap'] ?? ($styles['container']['_columnGap'] ?? ($styles['section']['_columnGap'] ?? '0px'));
        $sec_px = $styles['section']['padding']['left'] ?? ($styles['section']['padding']['right'] ?? '0px');
        $sec_py = $styles['section']['padding']['top']  ?? ($styles['section']['padding']['bottom'] ?? '0px');

        return [
            'col-px' => $col_px,
            'sec-px' => $sec_px,
            'sec-py' => $sec_py,
        ];
    
    }

}