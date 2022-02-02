<?php

use Puggan\GedMerge\GedMerger;

require_once __DIR__ . '/vendor/autoload.php';

(static function($file) {
    $ged = $file ? new GedMerger($file): GedMerger::instance();
    foreach($ged->nameList() as $nameRow) {
        echo $nameRow, PHP_EOL;
    }
})($argv[1] ?? '');
