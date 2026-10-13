<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_gradesheet';
$plugin->version   = 2026100602;
$plugin->requires  = 2023100900;      // Moodle 4.3 (hooks API, PHP 8.0+).
$plugin->supported = [403, 405];      // Tested on 4.3 - 4.5 (Bootstrap 4 themes).
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.13';
