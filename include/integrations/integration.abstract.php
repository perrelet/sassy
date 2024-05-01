<?php

namespace Sassy;

abstract class Integration {

    protected $loaded = false;

    public function __construct () {

        if (!$this->condition()) return false;

        $this->loaded = true;
        $this->run();

    }

    public function condition () {

        return true;
    
    }
    
    public function run () {

        // ..

    }
    
    public function is_active () {
        
        return $this->loaded;
        
    }

    //

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