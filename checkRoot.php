<?php

use Puggan\GedMerge\GedMerger;

require_once __DIR__ . '/vendor/autoload.php';

(static function($file) {
    $ged = $file ? new GedMerger($file): GedMerger::instance();
    $root = $ged->readRoot();
    var_dump($root);
    echo implode(', ', array_keys($root->children)), PHP_EOL;
})($argv[1] ?? '');
