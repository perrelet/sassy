<?php

namespace Sassy;

/**
 * Applies a captured patch to source, and refuses whatever it cannot apply exactly.
 *
 * Textual replace only: the mapped line must declare the property once and carry, literally,
 * the value that was served. Nothing is parsed and nothing else on the line moves.
 */
class Source_Writer {

    protected $graph;

    public function __construct (Import_Graph $graph) {

        $this->graph = $graph;

    }

    /**
     * @param array $changes Each: source, line, prop, from, to. A null `to` removes the
     *                       declaration; a null `from` is an addition and is refused.
     * @return array One per change, in the order given: written, file, line, reason, text.
     */
    public function apply (array $changes) {

        $results = [];
        $by_file = [];

        foreach (array_values($changes) as $i => $change) {

            $results[$i] = ['written' => false, 'file' => null, 'line' => (int) ($change['line'] ?? 0), 'reason' => null, 'text' => null];

            $file = $this->graph->find((string) ($change['source'] ?? ''));

            if ($file === null) { $results[$i]['reason'] = 'not a recorded dependency of this handle'; continue; }

            $results[$i]['file'] = $file;

            if (!isset($change['from']) || !isset($change['prop'])) { $results[$i]['reason'] = 'an addition has no old value to match; copy it instead'; continue; }

            $by_file[$file][$i] = $change;

        }

        foreach ($by_file as $file => $list) {

            $refuse = function ($reason) use ($list, &$results) {
                foreach ($list as $i => $_) $results[$i]['reason'] = $reason;
            };

            // The map describes the file as it was compiled. A line number against anything else
            // is a guess, and a write follows this rule too: it holds until the next compile.
            if (!Import_Graph::stamp_matches($file, $this->graph->deps[$file] ?? null)) { $refuse('changed since the last compile; compile first'); continue; }
            if (!is_writable($file)) { $refuse('not writable by the web server'); continue; }

            $lines = file($file);
            if ($lines === false) { $refuse('could not be read'); continue; }

            // Bottom-up, so a removal cannot shift a later line.
            uasort($list, function ($a, $b) { return ((int) ($b['line'] ?? 0)) <=> ((int) ($a['line'] ?? 0)); });

            $dirty = false;

            foreach ($list as $i => $change) {

                $n = (int) ($change['line'] ?? 0);

                if ($n < 1 || $n > count($lines)) { $results[$i]['reason'] = "line $n is past the end of the file"; continue; }

                $text = $lines[$n - 1];
                $results[$i]['text'] = rtrim($text, "\r\n");

                [$replacement, $reason] = static::edit($text, (string) $change['prop'], (string) $change['from'], $change['to'] ?? null);

                if ($reason !== null) { $results[$i]['reason'] = $reason; continue; }

                if ($replacement === null) unset($lines[$n - 1]);
                else $lines[$n - 1] = $replacement;

                $results[$i]['written'] = true;
                $dirty = true;

            }

            // In place: a temp-and-rename would hand the file to the web server's user and move
            // the directory mtime the import graph watches.
            if ($dirty && file_put_contents($file, implode('', $lines), LOCK_EX) === false) {
                foreach ($list as $i => $_) { $results[$i]['written'] = false; $results[$i]['reason'] = 'the write failed'; }
            }

        }

        return $results;

    }

    /**
     * @return array{0: ?string, 1: ?string} [new line, null] to replace, [null, null] to delete,
     *                                        or [null, reason] to refuse.
     */
    public static function edit ($line, $prop, $from, $to) {

        // A property boundary before it: color: must not match inside background-color:.
        $pattern = '/(^|[\s{;])' . preg_quote($prop, '/') . '\s*:/';

        if (preg_match_all($pattern, $line, $m, PREG_OFFSET_CAPTURE) !== 1) return [null, "the line does not declare `$prop` exactly once"];

        $start = $m[0][0][1] + strlen($m[1][0][0]);
        $colon = strpos($line, ':', $start);
        $end   = strpos($line, ';', $colon);

        if ($end === false) return [null, 'no `;` on the line'];

        $raw   = substr($line, $colon + 1, $end - $colon - 1);
        $value = trim($raw);

        if ($value !== trim($from)) return [null, "the line has `$prop: $value`, not `$from`"];

        if ($to === null) {
            // Only a line that is this declaration and nothing else.
            if (trim($line) !== trim(substr($line, $start, $end - $start + 1))) return [null, 'the line holds more than that declaration'];
            return [null, null];
        }

        $lead  = strlen($raw) - strlen(ltrim($raw));
        $vstart = $colon + 1 + $lead;
        $vend   = $vstart + strlen($value);

        return [substr($line, 0, $vstart) . $to . substr($line, $vend), null];

    }

}
