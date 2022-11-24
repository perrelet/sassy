<?php

namespace Sassy;

class Oxygen extends Integration {

    protected $variables = [];

    public function condition() {

        return defined("CT_VERSION") && !(class_exists("Digitalis\Module\OXY_SCSS\OXY_SCSS"));

    }

    public function run () {

        add_filter('scsslib_compiler_variables', [$this, 'compiler_variables'], 10, 1);
        add_filter('sassy-variables', [$this, 'compiler_variables'], 10, 1);

        if (isset($_GET['sassy-vars'])) add_action('wp_footer', [$this, 'print_variables']);

    }

    public function print_variables () {

        $variables = json_encode($this->variables);
        echo "<script>console.log($variables);</script>";

    }

    public function compiler_variables ($variables) {

        //error_log(print_r(oxy_get_global_colors(), true));

        $variables = $this->colors($variables);
        $variables = $this->breakpoints($variables);
        $variables = $this->fonts($variables);
        $variables = $this->sections($variables);
        $variables = $this->columns($variables);
		
        $this->variables = $variables;

        //error_log(print_r($variables, true));

        return $variables;

    }

    protected function colors ($variables) {

        if (!is_callable('oxy_get_global_colors')) return $variables;

        $colors = oxy_get_global_colors()['colors'];
        
        foreach ($colors as $color) {

            $name = $this->slug($color['name']);

            $variables["c-" . $color['id']] = $color['value'];
            $variables["c-" . $name] = $color['value'];

        }

        return $variables;

    }

    protected function breakpoints ($variables) {

        if (
            (!is_callable('ct_get_global_settings')) ||
            (!is_callable('oxygen_vsb_get_breakpoint_width')) ||
            (!is_callable('oxygen_vsb_get_page_width'))
        ) return $variables;

        //error_log(print_r(ct_get_global_settings(), true))
        
        $default_breakpoints = ct_get_global_settings(true)['breakpoints'];
        asort($default_breakpoints);

        $breakpoints = [];

        $i = 1;
        foreach ($default_breakpoints as $name => $default_width) {

            $name = $this->slug($name);

            $width = oxygen_vsb_get_breakpoint_width($name);

            $variables['b-' . $name]    = $width;
            $variables['b-' . $i]       = $width;
            $breakpoints[$name]         = $width . "px";
            $breakpoints['b-' . $i]     = $width . "px";

            $i++;

        }

        $page_width = oxygen_vsb_get_page_width();

        $variables['b-page']        = $page_width;
        $variables['b-' . $i]       = $page_width;
        $breakpoints['page']        = $page_width . "px";
        $breakpoints['b-' . $i]     = $page_width . "px";       

        $variables['breakpoints'] = $this->array_to_sass_map($breakpoints);

        return $variables;

    }

    protected function fonts ($variables) {

        if (!is_callable('ct_get_global_settings')) return $variables;

        $fonts = ct_get_global_settings()['fonts'];

        $i = 1;
        foreach ($fonts as $name => $font) {

            $name = $this->slug($name);
            
            $variables['f-' . $i]       = $font;
            $variables['f-' . $name]    = $font;

            $i++;

        }

        return $variables;

    }
	
	protected function sections ($variables) {
		
		if (!is_callable('ct_get_global_settings')) return $variables;
		
		$sections = ct_get_global_settings()['sections'];
		
		if (isset($sections['container-padding-left']) && $sections['container-padding-left']) {
			$variables['sec-px'] = $sections['container-padding-left'] . $sections['container-padding-left-unit'];
		}
		
		if (isset($sections['container-padding-top']) && $sections['container-padding-top']) {
			$variables['sec-py'] = $sections['container-padding-top'] . $sections['container-padding-top-unit'];
		}
		
		return $variables;
		
	}
	
	protected function columns ($variables) {
		
		if (!is_callable('ct_get_global_settings')) return $variables;
		
		$columns = ct_get_global_settings()['columns'];
		
		if (isset($columns['padding-left']) && $columns['padding-left']) {
			$variables['col-px'] = $columns['padding-left'] . $columns['padding-left-unit'];
		}
		
		if (isset($columns['padding-top']) && $columns['padding-top']) {
			$variables['col-py'] = $columns['padding-top'] . $columns['padding-top-unit'];
		}
		
		return $variables;
		
	}

    protected function slug ($string) {

        return trim(strtolower(str_replace(" ", "_", $string)));

    }

    protected function array_to_sass_map ($a) {

        $map = "(";
        $i = 0;

        foreach ($a as $k => $v) {

            $map .= "'" . $k . "': " . $v;
            if ($i < count($a) - 1) $map .= ", ";

            $i++;

        }

        $map .= ")";
        return $map;

    }

}