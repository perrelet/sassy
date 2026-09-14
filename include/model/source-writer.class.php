<?php

namespace Sassy;

/**
 * Applies a captured patch to source, and refuses whatever it cannot apply exactly.
 *
 * Textual only: a change needs the mapped line to declare the property once and carry,
 * literally, the value that was served; an addition needs the mapped line to open the block.
 * Nothing is parsed and nothing else moves.
 */
class Source_Writer {

    protected $graph;

    public function __construct (Import_Graph $graph) {

        $this->graph = $graph;

    }

    /**
     * @param array $changes Each: source, line, prop, from, to. A null `to` removes the
     *                       declaration; a null `from` adds one after the block's opening line.
     *                       A change with `selector` and `declarations` instead of `prop` is a
     *                       new rule, written as @at-root after the block the line opens.
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

            $is_rule = isset($change['selector']) && is_array($change['declarations'] ?? null);

            if (!$is_rule && (!isset($change['prop']) || (!isset($change['from']) && !isset($change['to'])))) { $results[$i]['reason'] = 'neither an old nor a new value'; continue; }

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

                if (isset($change['selector']) && is_array($change['declarations'] ?? null)) {

                    // A new rule goes after the block its neighbour opens, as @at-root so it
                    // compiles at the root wherever the neighbour is nested.
                    [$block, $reason] = static::rule($lines, $n, (string) $change['selector'], $change['declarations'], is_array($change['ancestors'] ?? null) ? $change['ancestors'] : []);

                    if ($reason !== null) { $results[$i]['reason'] = $reason; continue; }

                    [$after, $insert] = $block;
                    array_splice($lines, $after, 0, $insert);
                    $lines = array_values($lines);
                    $results[$i]['line'] = $after + 1;

                } else if (!isset($change['from'])) {

                    // An addition arrives only when the compiled rule had no such property, so
                    // the block has no such line. It goes after the mapped line: a sibling
                    // declaration where the rule has one, since a hoisted rule's opener is the
                    // outer rule's, else the opener itself.
                    [$insert, $reason] = static::insertion($lines, $n, (string) $change['prop'], (string) $change['to']);

                    if ($reason !== null) { $results[$i]['reason'] = $reason; continue; }

                    array_splice($lines, $n, 0, [$insert]);
                    $lines = array_values($lines);

                } else {

                    [$replacement, $reason] = static::edit($text, (string) $change['prop'], (string) $change['from'], $change['to'] ?? null);

                    if ($reason !== null) { $results[$i]['reason'] = $reason; continue; }

                    if ($replacement === null) unset($lines[$n - 1]);
                    else $lines[$n - 1] = $replacement;

                }

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
     * The line to insert after line $n, or a reason. After a sibling declaration, with its
     * indentation; or after the line that opens the block, with the next line's indentation
     * when it has more than the opener, else one level deeper. Anything else refuses.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function insertion (array $lines, $n, $prop, $to) {

        $anchor = $lines[$n - 1];
        $body   = rtrim(preg_replace('~\s*//.*$~', '', rtrim($anchor, "\r\n")));
        $eol    = str_ends_with($anchor, "\r\n") ? "\r\n" : "\n";
        $indent = static::indent($anchor);

        if (preg_match('/^\s*[-\w]+\s*:.*;$/', $body)) {
            return [$indent . $prop . ': ' . $to . ';' . $eol, null];
        }

        if (!str_ends_with($body, '{')) return [null, 'the mapped line neither holds a declaration nor opens the block'];

        $next = $lines[$n] ?? '';

        if (trim($next) !== '' && strlen(static::indent($next)) > strlen($indent)) $indent = static::indent($next);
        else $indent .= '    ';

        return [$indent . $prop . ': ' . $to . ';' . $eol, null];

    }

    /**
     * The lines of a new rule to insert after the block line $n opens, or a reason. Written as
     * @at-root (without: all) so it compiles at the root whatever the neighbour sits inside, with
     * exactly the at-rules the CSSOM had it under, outermost first.
     *
     * @return array{0: ?array{0: int, 1: string[]}, 1: ?string} [[line to insert after, lines], null]
     */
    public static function rule (array $lines, $n, $selector, array $declarations, array $ancestors = []) {

        $opener = $lines[$n - 1];
        $body   = rtrim(preg_replace('~\s*//.*$~', '', rtrim($opener, "\r\n")));

        if (!str_ends_with($body, '{')) return [null, 'the neighbour\'s line does not open a block'];
        if ($selector === '' || !$declarations)  return [null, 'a rule needs a selector and at least one declaration'];

        foreach ($ancestors as $ancestor) {
            if (!is_string($ancestor) || !preg_match('/^@[a-z-]+[^{}\r\n]*$/i', $ancestor)) return [null, 'an ancestor is not an at-rule prelude'];
        }

        $end = static::block_end($lines, $n);
        if ($end === null) return [null, 'the neighbour\'s block does not close'];

        $eol    = str_ends_with($opener, "\r\n") ? "\r\n" : "\n";
        $indent = static::indent($opener);
        $step   = '    ';
        $next   = $lines[$n] ?? '';
        if (trim($next) !== '' && strlen(static::indent($next)) > strlen($indent)) $step = substr(static::indent($next), strlen($indent));

        $out   = [$eol, $indent . '@at-root (without: all) {' . $eol];
        $depth = 1;

        foreach ($ancestors as $ancestor) { $out[] = $indent . str_repeat($step, $depth) . trim($ancestor) . ' {' . $eol; $depth++; }

        $out[] = $indent . str_repeat($step, $depth) . $selector . ' {' . $eol;
        foreach ($declarations as $prop => $value) $out[] = $indent . str_repeat($step, $depth + 1) . $prop . ': ' . $value . ';' . $eol;
        $out[] = $indent . str_repeat($step, $depth) . '}' . $eol;

        while (--$depth >= 0) $out[] = $indent . str_repeat($step, $depth) . '}' . $eol;

        return [[$end, $out], null];

    }

    /**
     * The 1-based line of the brace that closes the block line $n opens, by depth, skipping
     * strings and comments. Interpolation braces balance on their own. Null when unbalanced.
     */
    public static function block_end (array $lines, $n) {

        $depth = 0; $quote = null; $comment = false;

        for ($i = $n - 1, $count = count($lines); $i < $count; $i++) {

            $line = $lines[$i];

            for ($j = 0, $len = strlen($line); $j < $len; $j++) {

                $c = $line[$j];

                if ($comment) { if ($c === '*' && ($line[$j + 1] ?? '') === '/') { $comment = false; $j++; } continue; }
                if ($quote)   { if ($c === '\\') $j++; else if ($c === $quote) $quote = null; continue; }

                if ($c === '"' || $c === "'") { $quote = $c; continue; }
                if ($c === '/' && ($line[$j + 1] ?? '') === '*') { $comment = true; $j++; continue; }
                if ($c === '/' && ($line[$j + 1] ?? '') === '/') break;

                if ($c === '{') $depth++;
                else if ($c === '}') { $depth--; if ($depth === 0) return $i + 1; }

            }

        }

        return null;

    }

    protected static function indent ($line) {

        return preg_match('/^[ \t]*/', $line, $m) ? $m[0] : '';

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
