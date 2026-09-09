<?php

/**
 * The error panel reports what has failed by the time it is asked, not by the time it was first
 * asked. print_errors() runs at wp_footer 10 and print_late_styles() at 20, so a footer-enqueued
 * style that failed was rendered into an already-empty panel.
 */

require __DIR__ . '/bootstrap.php';

require $SASSY_PLUGIN . 'include/sassy.class.php';
require $SASSY_PLUGIN . 'include/view/ui.class.php';

$SCSS = ABSPATH . 'wp-content/themes/t';
$BASE = 'http://test.local/wp-content/themes/t/';

fixture("$SCSS/good.scss", ".a { color: red; }\n");
fixture("$SCSS/bad.scss",  ".a { color: }\n");

$sassy = new Sassy\Sassy();

section('A style that is not SCSS passes through');

check('untouched', $sassy->style_loader_src('http://test.local/x.css', 'x') === 'http://test.local/x.css');
check('and gets no printer', $sassy->get_printers() === []);

section('Errors are read when asked, not remembered from the first ask');

$sassy->style_loader_src($BASE . 'good.scss', 'good');

check('nothing wrong in the head', $sassy->get_errors() === []);
check('has_error() agrees',        !$sassy->has_error());

// A footer-enqueued style, compiled after print_errors() has already asked once.
$sassy->style_loader_src($BASE . 'bad.scss', 'bad');
$errors = $sassy->get_errors();

check('the later failure is reported',  isset($errors['sassy-bad']));
check('keyed by the admin bar node id', array_keys($errors) === ['sassy-bad']);
check('rendered through Diagnostic',    str_starts_with($errors['sassy-bad'] ?? '', 'ERROR  '));
check('has_error() agrees',             $sassy->has_error());

finish();
