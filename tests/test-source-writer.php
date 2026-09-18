<?php

/**
 * Tier 2 of the paintbrush writes to files people authored, so every rule here is a refusal.
 * A change lands only where the mapped line literally carries the served value; everything
 * else comes back for the copy path with the line it looked at.
 */

require __DIR__ . '/bootstrap.php';

use Sassy\Import_Graph;
use Sassy\Source_Writer;

$DIR = ABSPATH . 'wp-content/themes/t/scss';

/** A graph recording the files as they are now, which is what a compile would have stored. */
function graph_for (...$files) {
    $deps = [];
    foreach ($files as $file) $deps[$file] = Import_Graph::stamp($file);
    return new Import_Graph($deps);
}

function change ($source, $line, $prop, $from, $to) {
    return compact('source', 'line', 'prop', 'from', 'to');
}

section('A change lands on the mapped line and nowhere else');

$card = "$DIR/_card.scss";
fixture($card, ".card {\n  display: grid;\n  gap: 4px;\n  color: red;\n}\n");
chmod($card, 0640);
$inode = fileinode($card);

$result = (new Source_Writer(graph_for($card)))->apply([change('_card.scss', 3, 'gap', '4px', '12px')]);

check('written',                  $result[0]['written'] === true, var_export($result[0], true));
check('naming the file',          $result[0]['file'] === $card);
check('the line changed',         file($card)[2] === "  gap: 12px;\n", file($card)[2]);
check('and only the line',        file_get_contents($card) === ".card {\n  display: grid;\n  gap: 12px;\n  color: red;\n}\n");
clearstatcache();
check('the inode survives',       fileinode($card) === $inode);
check('and the mode',             (fileperms($card) & 0777) === 0640, decoct(fileperms($card) & 0777));

section('The match is exact');

$compact = "$DIR/_compact.scss";
fixture($compact, ".a { color: red; }\n.b { background-color: red; }\n.c { gap: \$gap; }\n.d { color: white; }\n.e { color: red\n}\n");
$writer = new Source_Writer(graph_for($compact));

$r = $writer->apply([
    change('_compact.scss', 1, 'color', 'red', 'blue'),                    // 0: compact line applies
    change('_compact.scss', 2, 'color', 'red', 'blue'),                    // 1: inside background-color
    change('_compact.scss', 3, 'gap', '4px', '8px'),                       // 2: variable-backed
    change('_compact.scss', 4, 'color', 'rgb(255, 255, 255)', 'black'),    // 3: served differs from source
    change('_compact.scss', 5, 'color', 'red', 'blue'),                    // 4: no ; on the line
    change('_compact.scss', 99, 'color', 'red', 'blue'),                   // 5: past the end
]);

check('a compact single-line rule applies',       $r[0]['written'] && file($compact)[0] === ".a { color: blue; }\n", file($compact)[0]);
check('color: does not match background-color:', !$r[1]['written'] && str_contains($r[1]['reason'], 'exactly once'), (string) $r[1]['reason']);
check('a $variable-backed value refuses',        !$r[2]['written'] && str_contains($r[2]['reason'], 'not `4px`'), (string) $r[2]['reason']);
check('quoting the line it looked at',            $r[2]['text'] === '.c { gap: $gap; }');
check('a served value the source lacks refuses', !$r[3]['written'] && str_contains($r[3]['reason'], 'color: white'), (string) $r[3]['reason']);
check('a line without ; refuses',                !$r[4]['written'] && str_contains($r[4]['reason'], '`;`'), (string) $r[4]['reason']);
check('a line past the end refuses',             !$r[5]['written'] && str_contains($r[5]['reason'], 'past the end'), (string) $r[5]['reason']);
check('refused lines are untouched',              file($compact)[1] === ".b { background-color: red; }\n" && file($compact)[2] === ".c { gap: \$gap; }\n");

section('Only recorded, unchanged, writable files');

$other = "$DIR/_other.scss";
fixture($other, ".o {\n  color: red;\n}\n");

$r = (new Source_Writer(graph_for($card)))->apply([change('_other.scss', 2, 'color', 'red', 'blue')]);
check('a file outside the graph refuses', !$r[0]['written'] && str_contains($r[0]['reason'], 'not a recorded dependency'), (string) $r[0]['reason']);
check('and is untouched',                 file($other)[1] === "  color: red;\n");

$graph = graph_for($other);
fixture($other, ".o {\n  color: red;\n}\n", 50);   // edited since the compile that recorded it
$r = (new Source_Writer($graph))->apply([change('_other.scss', 2, 'color', 'red', 'blue')]);
check('a file changed since the compile refuses', !$r[0]['written'] && str_contains($r[0]['reason'], 'changed since the last compile'), (string) $r[0]['reason']);
check('and is untouched',                          file($other)[1] === "  color: red;\n");

section('Removals apply only to a lone declaration');

$rm = "$DIR/_rm.scss";
fixture($rm, ".r {\n  color: red;\n  gap: 4px; margin: 0;\n}\n");
$r = (new Source_Writer(graph_for($rm)))->apply([
    change('_rm.scss', 3, 'gap', '4px', null),        // 0: removal from a shared line
    change('_rm.scss', 2, 'color', 'red', null),      // 1: removal of a lone declaration
    change('_rm.scss', 2, 'color', null, null),       // 2: neither value
]);

check('a removal from a shared line refuses',       !$r[0]['written'] && str_contains($r[0]['reason'], 'more than that declaration'), (string) $r[0]['reason']);
check('a lone declaration is removed',               $r[1]['written'] && file_get_contents($rm) === ".r {\n  gap: 4px; margin: 0;\n}\n", file_get_contents($rm));
check('a change with neither value refuses',        !$r[2]['written'] && str_contains($r[2]['reason'], 'neither'), (string) $r[2]['reason']);

section('Additions go in first, after the line that opens the block');

$add = "$DIR/_add.scss";
fixture($add, ".a {\n    color: red;\n}\n.b\n{\n    color: red;\n}\n.c {\n}\n.d { // note\n    gap: 1px;\n}\n");
$r = (new Source_Writer(graph_for($add)))->apply([
    change('_add.scss', 1, 'margin', null, '0'),        // 0: after `.a {`, indented like the next line
    change('_add.scss', 2, 'padding', null, '1px'),     // 1: a declaration line does not open a block
    change('_add.scss', 4, 'padding', null, '1px'),     // 2: `.b` with its brace on the next line
    change('_add.scss', 8, 'padding', null, '1px'),     // 3: an empty block: one level deeper than the opener
    change('_add.scss', 10, 'padding', null, '1px'),    // 4: a trailing comment on the opener
]);

check('an addition lands after the opener',       $r[0]['written'], (string) $r[0]['reason']);
check('with the block\'s indentation',            str_starts_with(file_get_contents($add), ".a {\n    margin: 0;\n    color: red;\n    padding: 1px;\n}\n"), file_get_contents($add));
check('a declaration line anchors it after itself', $r[1]['written'] && str_contains(file_get_contents($add), "    color: red;\n    padding: 1px;\n}\n.b"), file_get_contents($add));
check('a selector with its brace below refuses',    !$r[2]['written'] && str_contains($r[2]['reason'], 'neither'), (string) $r[2]['reason']);
check('an empty block gets one level deeper',    $r[3]['written'] && str_contains(file_get_contents($add), ".c {\n    padding: 1px;\n}\n"), file_get_contents($add));
check('a trailing comment on the opener is fine', $r[4]['written'] && str_contains(file_get_contents($add), ".d { // note\n    padding: 1px;\n    gap: 1px;\n}\n"), file_get_contents($add));

$mixed = "$DIR/_mixed.scss";
fixture($mixed, ".m {\n  color: red;\n  gap: 4px;\n}\n");
$r = (new Source_Writer(graph_for($mixed)))->apply([
    change('_mixed.scss', 1, 'margin', null, '0'),     // an insertion above
    change('_mixed.scss', 3, 'gap', '4px', '8px'),     // a change below, given the line before the insertion
]);
check('an addition and a change in one file, bottom-up', $r[0]['written'] && $r[1]['written'] && file_get_contents($mixed) === ".m {\n  margin: 0;\n  color: red;\n  gap: 8px;\n}\n", file_get_contents($mixed));

// A rule hoisted out of an @supports keeps its selector's source line, which is the outer rule's;
// anchored to a sibling declaration inside the at-rule, the addition lands in the right block.
$hoisted = "$DIR/_hoisted.scss";
fixture($hoisted, ".h {\n    color: red;\n\n    @supports (a: b) {\n        background: blue;\n    }\n}\n");
$r = (new Source_Writer(graph_for($hoisted)))->apply([
    change('_hoisted.scss', 5, 'outline', null, '1px solid'),   // the sibling inside @supports
    change('_hoisted.scss', 6, 'gap', null, '1px'),              // a closing brace: no anchor
]);
check('anchored to a sibling, it lands in the at-rule block', $r[0]['written'] && str_contains(file_get_contents($hoisted), "        background: blue;\n        outline: 1px solid;\n    }\n"), file_get_contents($hoisted));
check('a closing brace refuses',                            !$r[1]['written'] && str_contains($r[1]['reason'], 'neither'), (string) $r[1]['reason']);

section('Two changes in one file apply bottom-up');

$two = "$DIR/_two.scss";
fixture($two, ".t {\n  color: red;\n  gap: 4px;\n  margin: 0;\n}\n");
$r = (new Source_Writer(graph_for($two)))->apply([
    change('_two.scss', 2, 'color', 'red', null),     // removal above
    change('_two.scss', 4, 'margin', '0', '1em'),     // change below, given the line before the removal
]);

check('both written',            $r[0]['written'] && $r[1]['written'], var_export($r, true));
check('the change landed where the map said', file_get_contents($two) === ".t {\n  gap: 4px;\n  margin: 1em;\n}\n", file_get_contents($two));

section('A new rule goes after its neighbour\'s block, as @at-root');

$nr = "$DIR/_new-rule.scss";
fixture($nr, ".a {\n    color: red;\n}\n.p {\n    color: red;\n    &-inner {\n        gap: 1px;\n    }\n}\n");
$r = (new Source_Writer(graph_for($nr)))->apply([
    ['source' => '_new-rule.scss', 'line' => 1, 'selector' => 'header.x.a', 'declarations' => ['color' => 'pink', 'gap' => '0']],
    ['source' => '_new-rule.scss', 'line' => 6, 'selector' => '.q', 'declarations' => ['margin' => '0'], 'ancestors' => ['@media (min-width: 440px)', '@supports (a: b)']],   // after a nested block, inside two at-rules
    ['source' => '_new-rule.scss', 'line' => 2, 'selector' => '.z', 'declarations' => ['margin' => '0']],   // a declaration line
    ['source' => '_new-rule.scss', 'line' => 1, 'selector' => '.z', 'declarations' => []],                  // nothing to write
]);

check('written after the block, blank line first', $r[0]['written'] && str_contains(file_get_contents($nr), ".a {\n    color: red;\n}\n\n@at-root (without: all) {\n    header.x.a {\n        color: pink;\n        gap: 0;\n    }\n}\n.p {"), file_get_contents($nr));
check('naming the line it went after',              $r[0]['line'] === 4);
check('after a nested block, under its at-rules',   $r[1]['written'] && str_contains(file_get_contents($nr), "        gap: 1px;\n    }\n\n    @at-root (without: all) {\n        @media (min-width: 440px) {\n            @supports (a: b) {\n                .q {\n                    margin: 0;\n                }\n            }\n        }\n    }\n}\n"), file_get_contents($nr));
check('a malformed ancestor refuses',                (new Source_Writer(graph_for($nr)))->apply([['source' => '_new-rule.scss', 'line' => 1, 'selector' => '.z', 'declarations' => ['a' => 'b'], 'ancestors' => ['@media { x']]])[0]['reason'] !== null);
check('a declaration line refuses',                 !$r[2]['written'] && str_contains($r[2]['reason'], 'does not open'), (string) $r[2]['reason']);
check('no declarations refuses',                    !$r[3]['written'] && str_contains($r[3]['reason'], 'at least one'), (string) $r[3]['reason']);

section('block_end() by depth, skipping what is not structure');

$tricky = [".t {\n", "    content: \"}\";  // }\n", "    /* { */\n", "    width: calc(#{\$a} + 1px);\n", "    &:hover { color: red; }\n", "}\n", ".u {\n"];
check('a string, a comment and interpolation do not count', Source_Writer::block_end($tricky, 1) === 6);
check('an unbalanced block is null',                        Source_Writer::block_end([".v {\n", "    color: red;\n"], 1) === null);
check('a one-line block closes on its own line',            Source_Writer::block_end([".w { color: red; }\n"], 1) === 1);

section('What the CSSOM cannot produce is refused before any file is read');

$safe = "$DIR/_safe.scss";
fixture($safe, ".s {\n    color: red;\n}\n");
$r = (new Source_Writer(graph_for($safe)))->apply([
    change('_safe.scss', 2, 'color', 'red', "blue;\n}\n.evil { x: y; }"),                                          // 0: newline
    change('_safe.scss', 2, 'color', 'red', 'red } .evil { x: y'),                                                   // 1: brace outside quotes
    change('_safe.scss', 2, 'color', 'red', '"}"'),                                                                  // 2: brace inside quotes is a value
    change('_safe.scss', 2, 'col or', 'red', 'blue'),                                                                // 3: not a property name
    ['source' => '_safe.scss', 'line' => 1, 'selector' => '.z } .evil {', 'declarations' => ['a' => 'b']],          // 4: a selector with braces
    ['source' => '_safe.scss', 'line' => 1, 'selector' => '.z', 'declarations' => ['a b' => 'c']],                  // 5: a bad name in a rule
]);
check('a newline refuses',                    !$r[0]['written'] && str_contains($r[0]['reason'], 'newline'), (string) $r[0]['reason']);
check('a brace outside quotes refuses',       !$r[1]['written'] && str_contains($r[1]['reason'], 'brace'), (string) $r[1]['reason']);
check('a brace inside quotes is a value',      $r[2]['written'] && file($safe)[1] === "    color: \"}\";\n", file($safe)[1]);
check('a property that is not a name refuses',!$r[3]['written'] && str_contains($r[3]['reason'], 'not a property name'), (string) $r[3]['reason']);
check('a selector with braces refuses',       !$r[4]['written'] && str_contains($r[4]['reason'], 'brace'), (string) $r[4]['reason']);
check('a bad name in a rule refuses',         !$r[5]['written'] && str_contains($r[5]['reason'], 'not a property name'), (string) $r[5]['reason']);

section('A deleted rule takes its block with it, exactly or not at all');

$block = "$DIR/_block.scss";
fixture($block, ".card {\n  gap: 4px;\n}\n\n.other {\n  color: red;\n}\n\n.parent {\n  .child {\n    gap: 1px;\n  }\n}\n\n.holder {\n  color: blue;\n  .inner { gap: 2px; }\n}\n");
$remove = function ($line, $selector) { return ['source' => '_block.scss', 'line' => $line, 'selector' => $selector, 'remove' => true]; };

$r = (new Source_Writer(graph_for($block)))->apply([$remove(1, '.card')]);
check('the block goes, opener to closer',         $r[0]['written'] === true && str_starts_with(file_get_contents($block), ".other {\n  color: red;\n}\n\n.parent"), var_export($r[0], true) . "\n" . file_get_contents($block));

$r = (new Source_Writer(graph_for($block)))->apply([$remove(6, '.parent .child'), $remove(5, '.parent'), $remove(2, '.other'), $remove(11, '.holder')]);
check('a nested rule\'s compiled selector refuses', !$r[0]['written'] && str_contains($r[0]['reason'], 'the block is `.child`, not `.parent .child`'), (string) $r[0]['reason']);
check('a block with nested blocks refuses',         !$r[1]['written'] && str_contains($r[1]['reason'], 'nested'), (string) $r[1]['reason']);
check('a line that opens no block refuses',         !$r[2]['written'] && str_contains($r[2]['reason'], 'does not open a block'), (string) $r[2]['reason']);
check('so does one holding a nested one-liner',    !$r[3]['written'] && str_contains($r[3]['reason'], 'nested'), (string) $r[3]['reason']);

section('A write is announced');

$fired = [];
$GLOBALS['action_callbacks']['sassy-wrote-source'][] = function ($file, $changes) use (&$fired) { $fired[] = [$file, $changes]; };
fixture($card, ".card {\n  display: grid;\n  gap: 4px;\n  color: red;\n}\n");
(new Source_Writer(graph_for($card)))->apply([change('_card.scss', 3, 'gap', '4px', '12px'), change('_card.scss', 4, 'color', 'blue', 'green')]);
check('once per file, with only what was written', count($fired) === 1 && $fired[0][0] === $card && count($fired[0][1]) === 1 && $fired[0][1][0]['prop'] === 'gap', var_export($fired, true));
$fired = [];
(new Source_Writer(graph_for($card)))->apply([change('_card.scss', 4, 'color', 'blue', 'green')]);
check('not when nothing was written',            $fired === []);

section('edit() alone');

check('keeps the spacing around the value',   Source_Writer::edit("    gap:   4px ;  // note\n", 'gap', '4px', '12px') === ["    gap:   12px ;  // note\n", null]);
check('a boundary at the start of the line',  Source_Writer::edit("gap: 4px;\n", 'gap', '4px', '1px') === ["gap: 1px;\n", null]);
check('two declarations of different props', Source_Writer::edit("color: red; gap: 4px;\n", 'gap', '4px', '1px') === ["color: red; gap: 1px;\n", null]);
check('the same prop twice refuses',          Source_Writer::edit("gap: 4px; gap: 8px;\n", 'gap', '4px', '1px')[1] !== null);

finish();
