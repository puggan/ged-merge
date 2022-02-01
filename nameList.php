<?php

use Puggan\GedMerge\GedMerger;

require_once __DIR__ . '/vendor/autoload.php';

(static function($file) {
    $ged = $file ? new GedMerger($file): GedMerger::instance();
    foreach($ged->parseRows([0 => ['INDI'], 1 => ['NAME'], 2=> false]) as $child) {
        echo $child->children['NAME'][0]->value ?? '(unknown)', PHP_EOL;
    }
})($argv[1] ?? '');
