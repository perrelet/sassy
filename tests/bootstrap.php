<?php

/**
 * Test bootstrap: enough of WordPress to exercise the compiler without loading it.
 *
 * Each test runs in its own process (see run.php) because the fixture root is bound to
 * constants — ABSPATH and friends can only be defined once.
 *
 * Pass a plugin path as argv[1] to run the suite against a different checkout, which is how
 * a fix is confirmed to actually fail before it: `php tests/run.php /path/to/old/plugin`.
 */

$SASSY_TEST   = basename($_SERVER['SCRIPT_FILENAME'], '.php');
$SASSY_PLUGIN = rtrim($argv[1] ?? dirname(__DIR__), '/') . '/';
$SASSY_ROOT   = __DIR__ . '/.tmp/' . $SASSY_TEST;

exec('rm -rf ' . escapeshellarg($SASSY_ROOT));
@mkdir($SASSY_ROOT . '/wp-content', 0777, true);

define('ABSPATH',         $SASSY_ROOT . '/');
define('WP_CONTENT_DIR',  $SASSY_ROOT . '/wp-content');
define('WP_CONTENT_URL',  'http://test.local/wp-content');
define('SASSY_PATH',      $SASSY_PLUGIN);

$_SERVER['DOCUMENT_ROOT'] = $SASSY_ROOT;

// --- WordPress stubs ---------------------------------------------------------

$GLOBALS['transients']       = [];
$GLOBALS['filter_overrides'] = [];
$GLOBALS['actions']          = [];
$GLOBALS['action_callbacks'] = [];

function apply_filters ($tag, $value) {
    $override = $GLOBALS['filter_overrides'][$tag] ?? null;
    if ($override instanceof Closure) {
        $args = func_get_args();
        array_shift($args);
        return $override(...$args);
    }
    return $override ?? $value;
}
function do_action ($tag, ...$args) {
    $GLOBALS['actions'][] = $tag;
    foreach ($GLOBALS['action_callbacks'][$tag] ?? [] as $callback) $callback(...$args);
}
function get_transient ($key)              { return $GLOBALS['transients'][$key] ?? false; }
function set_transient ($key, $v, $e = 0)  { $GLOBALS['transients'][$key] = $v; return true; }
function delete_transient ($key)           { unset($GLOBALS['transients'][$key]); return true; }
function get_temp_dir ()                   { return '/tmp/'; }
function wp_normalize_path ($p)            { return str_replace('\\', '/', $p); }
function trailingslashit ($s)              { return rtrim($s, '/\\') . '/'; }
function site_url ()                       { return 'http://test.local'; }
function is_multisite ()                   { return false; }
function wp_mkdir_p ($d)                   { return is_dir($d) || mkdir($d, 0777, true); }
function get_option ($k, $default = false) { return $k === 'home' ? 'http://test.local' : $default; }
function get_template_directory_uri ()     { return 'http://test.local/wp-content/themes/t'; }
function get_stylesheet_directory_uri ()   { return 'http://test.local/wp-content/themes/t'; }

class Sassy_Test_Queue {

    public $registered = [];

    public function add ($handle, $src, $deps = []) {
        $this->registered[$handle] = (object) ['handle' => $handle, 'src' => $src, 'deps' => $deps];
        return $this;
    }

}

$GLOBALS['wp_styles'] = new Sassy_Test_Queue();

function wp_styles ()                      { return $GLOBALS['wp_styles']; }
function on_action ($tag, $callback)       { $GLOBALS['action_callbacks'][$tag][] = $callback; }

// --- Plugin ------------------------------------------------------------------

require $SASSY_PLUGIN . 'vendor/autoload.php';

foreach ([
    'include/model/diagnostic.class.php',
    'include/model/compile-result.class.php',
    'include/model/lightning-css-postprocessor.class.php',
    'include/model/scss-map.class.php',
    'include/model/asset.class.php',
    'include/model/style-stack.class.php',
    'include/model/build-target.class.php',
    'include/model/variable-resolver.class.php',
    'include/model/compile-cache.class.php',
    'include/model/import-graph.class.php',
    'include/model/import-resolver.class.php',
    'include/model/import-scanner.class.php',
    'include/engines/compiler-engine.interface.php',
    'include/engines/scssphp-engine.compiler-engine.php',
    'include/engines/dart-sass-engine.compiler-engine.php',
    'include/model/scss-compiler.class.php',
    'include/model/printer.class.php',
] as $file) {
    // Files absent from an older checkout are skipped so the suite still reports on it.
    if (file_exists($SASSY_PLUGIN . $file)) require $SASSY_PLUGIN . $file;
}

// Printer was SCSS_Compiler before 3.0. Aliased so the suite still runs against older checkouts.
if (!class_exists('Sassy\\Printer') && class_exists('Sassy\\SCSS_Compiler')) class_alias('Sassy\\SCSS_Compiler', 'Sassy\\Printer');

// --- Harness -----------------------------------------------------------------

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

function section ($title) {
    echo "\n  $title\n";
}

function check ($label, $condition, $detail = '') {
    if ($condition) {
        $GLOBALS['pass']++;
        echo "    PASS  $label\n";
    } else {
        $GLOBALS['fail']++;
        echo "    FAIL  $label" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }
}

function skip ($label, $why) {
    echo "    SKIP  $label  ($why)\n";
}

function finish () {
    $pass = $GLOBALS['pass'];
    $fail = $GLOBALS['fail'];
    echo "\n  " . ($fail === 0 ? "{$pass} passed" : "{$pass} passed, {$fail} FAILED") . "\n";
    exec('rm -rf ' . escapeshellarg($GLOBALS['SASSY_ROOT']));
    exit($fail === 0 ? 0 : 1);
}

/** Write a fixture with a deterministic mtime; second-granular clocks make bare writes flaky. */
function fixture ($path, $body, $age = 0) {
    $new = !file_exists($path);
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $body);
    touch($path, time() - 1000 + $age);
    // Creating a file moves its directory's mtime, which dependency tracking watches.
    if ($new) touch(dirname($path), time() - 1000 + $age);
}

function dart_available () {
    exec('command -v sass 2>/dev/null', $out, $code);
    return $code === 0;
}

function use_dart_engine () {
    $GLOBALS['filter_overrides']['sassy-engine']           = new Sassy\Dart_Sass_Engine();
    $GLOBALS['filter_overrides']['sassy-dart-sass-binary'] = 'sass';
}

/** A fresh compiler each time, as a new request would build. */
function compile ($src_url, $handle) {
    $compiler = new Sassy\Printer();
    $compiler->compile($src_url, $handle);
    return $compiler;
}
