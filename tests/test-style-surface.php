<?php

/**
 * Phase 8: a script's style-mutation profile, by marker, with no semantics.
 *
 * The shapes are assignment-shaped where a read would otherwise count: Sassy's own JS reads
 * rule.style.cssText and document.styleSheets, and the plan's acceptance is that the profiler
 * run over Sassy's UI reports only what phase 6 intended.
 */

require __DIR__ . '/bootstrap.php';

use Sassy\Asset;
use Sassy\Compile_Cache;
use Sassy\Style_Surface;

$GLOBALS['ext_object_cache'] = false;
function wp_using_ext_object_cache () { return $GLOBALS['ext_object_cache']; }

$JS   = ABSPATH . 'wp-content/themes/t/js';
$BASE = 'http://test.local/wp-content/themes/t/js/';

function script ($name, $body) {
    global $JS, $BASE;
    fixture("$JS/$name", $body);
    return new Asset(basename($name, '.js'), $BASE . $name, [], 'script');
}

section('Each category, by its markers');

$scope = Style_Surface::of(script('scope.js', "el.classList.add('x'); el.dataset.theme = 'dark'; el.setAttribute('data-open', '1'); el.toggleAttribute('data-busy'); el.dataset['view'] = 1;\n"));
check('scope counts classList, dataset and data- attributes', $scope->counts['scope'] === 5, var_export($scope->counts, true));
check('and only scope',                                        $scope->categories() === ['scope']);

$custom = Style_Surface::of(script('custom.js', "el.style.setProperty('--theme', 'dark'); el.style.setProperty(\"--gap\", '4px');\n"));
check('custom properties are their own category',             $custom->categories() === ['custom_properties'] && $custom->counts['custom_properties'] === 2, var_export($custom->counts, true));

$inline = Style_Surface::of(script('inline.js', "el.style.width = '1px'; el.style.cssText = 'a: b'; el.setAttribute('style', 'x'); el.style.setProperty('width', '1px'); el.style = 'y';\n"));
check('inline writes: property, cssText, attribute, setProperty, whole style', $inline->categories() === ['inline_writes'] && $inline->counts['inline_writes'] === 5, var_export($inline->counts, true));

$reads = Style_Surface::of(script('reads.js', "el.getBoundingClientRect(); getComputedStyle(el); new ResizeObserver(f); matchMedia('(min-width: 1px)'); const w = el.offsetWidth + el.scrollTop;\n"));
check('layout reads',                                          $reads->categories() === ['layout_reads'] && $reads->counts['layout_reads'] === 6, var_export($reads->counts, true));

$cssom = Style_Surface::of(script('cssom.js', "document.adoptedStyleSheets = [s]; sheet.insertRule('a{}'); sheet.deleteRule(0); const s = new CSSStyleSheet(); CSS.registerProperty({}); document.createElement('style');\n"));
check('CSSOM injection',                                       $cssom->categories() === ['cssom'] && $cssom->counts['cssom'] === 6, var_export($cssom->counts, true));

section('What does not count');

$readonly = Style_Surface::of(script('readonly.js', "const t = rule.style.cssText; if (el.style.width == '1px') {} const same = el.style.height === h; for (const s of document.styleSheets) { s.cssRules; }\n"));
check('a style read is not an inline write',                   ($readonly->counts['inline_writes'] ?? 0) === 0, var_export($readonly->markers, true));
check('a comparison is not an assignment',                     !isset($readonly->markers['style.prop =']));
check('reading styleSheets and cssRules is not injection',    ($readonly->counts['cssom'] ?? 0) === 0);
check('so nothing is reported',                                $readonly->categories() === []);

$computed = Style_Surface::of(script('computed.js', "el.style.setProperty(name, value);\n"));
check('a computed property name is neither category',          $computed->categories() === []);

section('Attributes, as written in markup');

$attrs = Style_Surface::of(script('attrs.js', "el.setAttribute('data-theme', 'dark'); el.dataset.theme = 'light'; el.dataset.fooBar = 1; el.dataset['view'] = 'x'; el.removeAttribute(\"data-Open\"); el.classList.add('data-theme');\n"));
check('setAttribute and dataset agree on the name',            in_array('data-theme', $attrs->attributes, true) && count(array_keys($attrs->attributes, 'data-theme', true)) === 1, implode(', ', $attrs->attributes));
check('camelCase becomes kebab',                               in_array('data-foo-bar', $attrs->attributes, true));
check('bracket access counts',                                 in_array('data-view', $attrs->attributes, true));
check('and case is normalised',                                in_array('data-open', $attrs->attributes, true));
check('a class is not an attribute',                           count($attrs->attributes) === 4, implode(', ', $attrs->attributes));
check('touches() takes either form',                           $attrs->touches('data-theme') && $attrs->touches('theme') && !$attrs->touches('data-nope'));

section('What has no surface');

check('a remote script',        Style_Surface::of(new Asset('cdn', 'https://ajax.googleapis.com/j.js', [], 'script'))->reason === 'not a local file');
check('a style',                Style_Surface::of(new Asset('s', $BASE . 'a.css'))->reason === 'not a script');
check('a .php loader',          Style_Surface::of(script('loader.php', "<?php echo 'el.style.width = 1';"))->reason === 'not JavaScript');
check('a missing file',         Style_Surface::of(new Asset('gone', $BASE . 'gone.js', [], 'script'))->reason === 'missing');
check('a minified one-liner still counts', Style_Surface::of(script('m.min.js', "!function(e){e.classList.add(\"a\"),e.getBoundingClientRect()}(t);"))->categories() === ['scope', 'layout_reads']);

section('The cache is one record, stamped per file');

$record = Compile_Cache::get_surfaces();
check('every profiled file is in it',   isset($record["$JS/scope.js"]) && isset($record["$JS/cssom.js"]));
check('under sassy-surfaces',           is_array(get_transient('sassy-surfaces')));

$before = Compile_Cache::get_surfaces()["$JS/scope.js"]['counts']['scope'];
$rescan = Style_Surface::of(script('scope.js', "el.classList.add('x'); el.classList.remove('y'); el.classList.toggle('z');\n"));
// fixture() with no age writes the same mtime; size changed, and the stamp compares both.
check('a changed file is rescanned',    $rescan->counts['scope'] === 3 && $before === 5, (string) $rescan->counts['scope']);

$stamped = Compile_Cache::get_surfaces()["$JS/scope.js"]['stamp'];
Style_Surface::of(new Asset('scope', $BASE . 'scope.js', [], 'script'));
check('an unchanged one is not',        Compile_Cache::get_surfaces()["$JS/scope.js"]['stamp'] === $stamped);

$GLOBALS['ext_object_cache'] = true;
Compile_Cache::forget_all();
check('forget_all() drops it under an external object cache', get_transient('sassy-surfaces') === false);
$GLOBALS['ext_object_cache'] = false;

section('Sassy reports only what phase 6 intended');

$own = Style_Surface::of(new Asset('sassy', 'http://test.local/sassy.js', [], 'script'));
$GLOBALS['filter_overrides']['sassy-src-path'] = $GLOBALS['SASSY_PLUGIN'] . 'assets/js/sassy.js';
$own = Style_Surface::of(new Asset('sassy', 'http://test.local/sassy.js', [], 'script'));
unset($GLOBALS['filter_overrides']['sassy-src-path']);

check('the profiler ran over sassy.js',  $own->has_surface(), (string) $own->reason);
check('and found scope only',            $own->categories() === ['scope'], implode(', ', $own->categories()) . ' | ' . var_export($own->markers, true));

finish();
