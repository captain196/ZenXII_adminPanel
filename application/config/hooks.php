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
