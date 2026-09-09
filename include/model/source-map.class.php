<?php

namespace Sassy;

/**
 * Keeps a source map true after the CSS it describes is edited.
 *
 * Only the generated columns move: everything else in a segment is a delta from the previous
 * segment and is carried over verbatim, so no cross-line state is needed.
 */
class Source_Map {

    const B64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    /**
     * @param string $json       The map.
     * @param array  $insertions [[line, column, length], ...] in the coordinates of the CSS
     *                           before the insertions, columns in UTF-16 units as the map counts.
     * @return string The map with every segment at or after an insertion moved past it.
     */
    public static function shift ($json, array $insertions) {

        if (!$insertions) return $json;

        $map = json_decode($json, true);
        if (!is_array($map) || !isset($map['mappings']) || !is_string($map['mappings'])) return $json;

        $by_line = [];
        foreach ($insertions as [$line, $column, $length]) $by_line[$line][] = [$column, $length];

        $lines = explode(';', $map['mappings']);

        foreach ($by_line as $line => $points) {
            if (!isset($lines[$line])) continue;
            $lines[$line] = static::shift_line($lines[$line], $points);
        }

        $map['mappings'] = implode(';', $lines);

        $encoded = json_encode($map, JSON_UNESCAPED_SLASHES);

        return $encoded === false ? $json : $encoded;

    }

    protected static function shift_line ($encoded, array $points) {

        if ($encoded === '') return '';

        $out      = [];
        $column   = 0;
        $previous = 0;

        foreach (explode(',', $encoded) as $segment) {

            [$delta, $rest] = static::first($segment);
            $column += $delta;

            $moved = $column;
            foreach ($points as [$at, $length]) {
                if ($column >= $at) $moved += $length;
            }

            $out[]    = static::vlq($moved - $previous) . $rest;
            $previous = $moved;

        }

        return implode(',', $out);

    }

    /**
     * @return array{0: int, 1: string} The first VLQ value and the untouched remainder.
     */
    protected static function first ($segment) {

        $value = 0;
        $shift = 0;
        $i     = 0;

        for ($n = strlen($segment); $i < $n; $i++) {

            $digit = strpos(static::B64, $segment[$i]);
            if ($digit === false) break;

            $value += ($digit & 31) << $shift;
            $shift += 5;

            if (!($digit & 32)) { $i++; break; }

        }

        $decoded = ($value & 1) ? -($value >> 1) : ($value >> 1);

        return [$decoded, substr($segment, $i)];

    }

    protected static function vlq ($value) {

        $vlq = $value < 0 ? ((-$value) << 1) | 1 : ($value << 1);
        $out = '';

        do {
            $digit = $vlq & 31;
            $vlq >>= 5;
            if ($vlq > 0) $digit |= 32;
            $out .= static::B64[$digit];
        } while ($vlq > 0);

        return $out;

    }

    /**
     * Line and column of a byte offset, columns in UTF-16 units: the map counts a byte-order
     * mark as one column, and PHP as three bytes.
     */
    public static function position ($text, $offset) {

        $before = substr($text, 0, $offset);
        $line   = substr_count($before, "\n");
        $start  = strrpos($before, "\n");
        $prefix = $start === false ? $before : substr($before, $start + 1);

        return [$line, (int) (strlen(mb_convert_encoding($prefix, 'UTF-16LE', 'UTF-8')) / 2)];

    }

}
