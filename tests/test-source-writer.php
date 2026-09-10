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

section('Additions refuse; removals apply only to a lone declaration');

$rm = "$DIR/_rm.scss";
fixture($rm, ".r {\n  color: red;\n  gap: 4px; margin: 0;\n}\n");
$r = (new Source_Writer(graph_for($rm)))->apply([
    change('_rm.scss', 2, 'padding', null, '1px'),   // 0: addition
    change('_rm.scss', 3, 'gap', '4px', null),        // 1: removal from a shared line
    change('_rm.scss', 2, 'color', 'red', null),      // 2: removal of a lone declaration
]);

check('an addition refuses',                        !$r[0]['written'] && str_contains($r[0]['reason'], 'addition'), (string) $r[0]['reason']);
check('a removal from a shared line refuses',       !$r[1]['written'] && str_contains($r[1]['reason'], 'more than that declaration'), (string) $r[1]['reason']);
check('a lone declaration is removed',               $r[2]['written'] && file_get_contents($rm) === ".r {\n  gap: 4px; margin: 0;\n}\n", file_get_contents($rm));

section('Two changes in one file apply bottom-up');

$two = "$DIR/_two.scss";
fixture($two, ".t {\n  color: red;\n  gap: 4px;\n  margin: 0;\n}\n");
$r = (new Source_Writer(graph_for($two)))->apply([
    change('_two.scss', 2, 'color', 'red', null),     // removal above
    change('_two.scss', 4, 'margin', '0', '1em'),     // change below, given the line before the removal
]);

check('both written',            $r[0]['written'] && $r[1]['written'], var_export($r, true));
check('the change landed where the map said', file_get_contents($two) === ".t {\n  gap: 4px;\n  margin: 1em;\n}\n", file_get_contents($two));

section('edit() alone');

check('keeps the spacing around the value',   Source_Writer::edit("    gap:   4px ;  // note\n", 'gap', '4px', '12px') === ["    gap:   12px ;  // note\n", null]);
check('a boundary at the start of the line',  Source_Writer::edit("gap: 4px;\n", 'gap', '4px', '1px') === ["gap: 1px;\n", null]);
check('two declarations of different props', Source_Writer::edit("color: red; gap: 4px;\n", 'gap', '4px', '1px') === ["color: red; gap: 1px;\n", null]);
check('the same prop twice refuses',          Source_Writer::edit("gap: 4px; gap: 8px;\n", 'gap', '4px', '1px')[1] !== null);

finish();
