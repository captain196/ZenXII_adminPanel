<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| Hooks
| -------------------------------------------------------------------------
| This file lets you define "hooks" to extend CI without hacking the core
| files.  Please see the user guide for info:
|
|	https://codeigniter.com/userguide3/general/hooks.html
|
*/

// ── LIB-AUTOLOAD-1 — resolve application/libraries/*.php by class name ───────
//
// pre_system, because a static reference to a library can be evaluated by any
// code the framework subsequently loads. Registering later would leave exactly
// the ordering hole this closes.
//
// See application/hooks/Library_autoloader.php for why this exists and why it
// is safe (it appends, so Composer is always consulted first).
$hook['pre_system'][] = [
    'class'    => 'Library_autoloader',
    'function' => 'register',
    'filename' => 'Library_autoloader.php',
    'filepath' => 'hooks',
];

// ── Debug Logger (activated only when GRADER_DEBUG flag file exists) ──────────
$hook['pre_controller'][] = [
    'class'    => 'Debug_logger',
    'function' => 'pre_request',
    'filename' => 'Debug_logger.php',
    'filepath' => 'hooks',
];
$hook['post_system'][] = [
    'class'    => 'Debug_logger',
    'function' => 'post_system',
    'filename' => 'Debug_logger.php',
    'filepath' => 'hooks',
];
