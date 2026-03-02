<?php

namespace Sassy;

abstract class Integration {

    protected $loaded = false;

    public function __construct () {

        if (!$this->condition()) return false;

        $this->loaded = true;

        add_filter('sassy-variables', [$this, 'compiler_variables'], 10, 1);

    }

    public function condition () {

        return true;
    
    }
    
    public function run () {



    }
    
    public function is_active () {
        
        return $this->loaded;
        
    }

    public function compiler_variables ($variables) {

        return array_merge($variables, $this->get_variables());

    }

    /**
     * Return integration-specific SCSS variables as key => value.
     *
     * @return array
     */
    abstract public function get_variables ();

}