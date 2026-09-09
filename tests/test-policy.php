<?php

/**
 * One question, asked once: is the dev surface active for this request?
 *
 * 2.x asked current_user_can('edit_theme_options') in four places, so a fifth surface could be
 * added without a gate and nothing would notice.
 */

require __DIR__ . '/bootstrap.php';

use Sassy\Policy;

section('The default is edit_theme_options');

$GLOBALS['capabilities'] = [];
check('a visitor is out',      !Policy::active());

$GLOBALS['capabilities'] = ['edit_theme_options'];
check('a theme editor is in',   Policy::active());

$GLOBALS['capabilities'] = ['read'];
check('a subscriber is out',   !Policy::active());

section('sassy-dev overrides it, both ways');

$GLOBALS['capabilities'] = [];
$GLOBALS['filter_overrides']['sassy-dev'] = true;
check('open to someone the default excludes', Policy::active());

$GLOBALS['capabilities'] = ['edit_theme_options'];
$GLOBALS['filter_overrides']['sassy-dev'] = false;
check('closed to someone the default admits', !Policy::active());

// The reference binding: a capability the site grants rather than a role WordPress ships.
$GLOBALS['filter_overrides']['sassy-dev'] = function ($default) {
    return current_user_can('dev');
};

$GLOBALS['capabilities'] = ['edit_theme_options'];
check('an administrator without the capability is out', !Policy::active());

$GLOBALS['capabilities'] = ['dev'];
check('a dev with no other capability is in',            Policy::active());

unset($GLOBALS['filter_overrides']['sassy-dev']);

section('Writing source is a separate, stricter gate');

$GLOBALS['capabilities'] = ['edit_theme_options'];

check('off by default even for a dev',   !Policy::can_write_source());

$GLOBALS['filter_overrides']['sassy-write-source'] = true;
check('on when explicitly granted',       Policy::can_write_source());

$GLOBALS['filter_overrides']['sassy-dev'] = false;
check('never implied by sassy-dev being off', !Policy::can_write_source(), 'the dev gate still has to pass');

unset($GLOBALS['filter_overrides']['sassy-write-source'], $GLOBALS['filter_overrides']['sassy-dev']);
$GLOBALS['capabilities'] = [];

finish();
