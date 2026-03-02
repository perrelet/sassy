<?php

namespace Sassy;

/**
 * Helper for converting PHP arrays into SCSS map syntax.
 */
class Scss_Map {

    /**
     * Convert an associative array into a SCSS map expression.
     *
     * Values are assumed to already be valid SCSS expressions (strings),
     * except nested arrays which are recursively converted to maps.
     *
     * @param array $a
     * @return string
     */
    public static function from_array (array $a) {

        $map = '(';
        $i   = 0;
        $c   = count($a);

        foreach ($a as $k => $v) {

            if (is_array($v)) {
                $v = static::from_array($v);
            }

            $map .= "'" . $k . "': " . $v;
            if ($i < $c - 1) {
                $map .= ', ';
            }

            $i++;
        }

        $map .= ')';
        return $map;

    }

}

