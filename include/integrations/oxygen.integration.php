<?php

namespace Sassy;

class Oxygen extends Integration {

    protected $variables;

    protected $colors;
    protected $breakpoints;
    protected $fonts;
    protected $sections;
    protected $columns;

    public function condition() {

        return defined("CT_VERSION") && !(class_exists("Digitalis\Module\OXY_SCSS\OXY_SCSS"));

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

            $this->variables = array_merge($this->variables, $this->get_colors());
            $this->variables = array_merge($this->variables, $this->get_breakpoints());
            $this->variables = array_merge($this->variables, $this->get_fonts());
            $this->variables = array_merge($this->variables, $this->get_sections());
            $this->variables = array_merge($this->variables, $this->get_columns());

        }

        return $this->variables;

    }

    protected function get_colors () {

        if (!is_callable('oxy_get_global_colors')) return [];

        if (is_null($this->colors)) {

            $this->colors = [];
            $colors = oxy_get_global_colors()['colors'];
        
            foreach ($colors as $color) {
    
                $name = $this->slug($color['name']);
    
                $this->colors["c-" . $name] = $color['value'];
    
            }

        }

        return $this->colors;

    }

    protected function get_breakpoints () {

        if (
            (!is_callable('ct_get_global_settings')) ||
            (!is_callable('oxygen_vsb_get_breakpoint_width')) ||
            (!is_callable('oxygen_vsb_get_page_width'))
        ) return [];

        if (is_null($this->breakpoints)) {

            $this->breakpoints = [];
            $default_breakpoints = ct_get_global_settings(true)['breakpoints'];
            asort($default_breakpoints);

            $breakpoints = [];

            $i = 1;
            foreach ($default_breakpoints as $name => $default_width) {

                $name = $this->slug($name);

                $width = oxygen_vsb_get_breakpoint_width($name);

                $this->breakpoints['b-' . $name]    = $width;
                //$this->breakpoints['b-' . $i]       = $width;
                $breakpoints[$name]         = $width . "px";
                //$breakpoints['b-' . $i]     = $width . "px";

                $i++;

            }

            $page_width = oxygen_vsb_get_page_width();

            $this->breakpoints['b-page']        = $page_width;
            //$this->breakpoints['b-' . $i]       = $page_width;
            $breakpoints['page']        = $page_width . "px";
            //$breakpoints['b-' . $i]     = $page_width . "px";       

            $this->breakpoints['breakpoints'] = $this->array_to_sass_map($breakpoints);

        }

        return $this->breakpoints;

    }

    protected function get_fonts () {

        if (!is_callable('ct_get_global_settings')) return [];

        if (is_null($this->fonts)) {

            $this->fonts = [];
            $fonts = ct_get_global_settings()['fonts'];

            $i = 1;
            foreach ($fonts as $name => $font) {

                $name = $this->slug($name);
                
                $this->fonts['f-' . $name] = $font;

                $i++;

            }

        }

        return $this->fonts;

    }
    
    protected function get_sections () {
        
        if (!is_callable('ct_get_global_settings')) return [];
        
        if (is_null($this->sections)) {

            $this->sections = [];
            $sections = ct_get_global_settings()['sections'];
            
            if (isset($sections['container-padding-left']) && $sections['container-padding-left']) {
                $this->sections['sec-px'] = $sections['container-padding-left'] . $sections['container-padding-left-unit'];
            }
            
            if (isset($sections['container-padding-top']) && $sections['container-padding-top']) {
                $this->sections['sec-py'] = $sections['container-padding-top'] . $sections['container-padding-top-unit'];
            }

        }
        
        return $this->sections;
        
    }
    
    protected function get_columns () {
        
        if (!is_callable('ct_get_global_settings')) return [];
        
        if (is_null($this->columns)) {

            $this->columns = [];
            $columns = ct_get_global_settings()['columns'];
            
            if (isset($columns['padding-left']) && $columns['padding-left']) {
                $this->columns['col-px'] = $columns['padding-left'] . $columns['padding-left-unit'];
            }
            
            if (isset($columns['padding-top']) && $columns['padding-top']) {
                $this->columns['col-py'] = $columns['padding-top'] . $columns['padding-top-unit'];
            }

        }

        return $this->columns;

    }

    protected function slug ($string) {

        return trim(strtolower(str_replace(" ", "-", $string)));

    }

}