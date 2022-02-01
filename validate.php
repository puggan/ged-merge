<?php

use Puggan\GedMerge\GedMerger;

require_once __DIR__ . '/vendor/autoload.php';

(static function ($file) {
    $ged = $file ? new GedMerger($file) : GedMerger::instance();
    $errors = 0;
    $ok = 0;
    foreach ($ged->validate() as $problem) {
        if (str_starts_with($problem, 'OK: ')) {
            echo '+ ', $problem, PHP_EOL;
            $ok++;
        } else {
            echo '* ', $problem, PHP_EOL;
            $errors++;
        }
    }
    echo 'Total: ', $ok, PHP_EOL, ($errors ? 'Errors: ' . $errors . ' errors' . PHP_EOL: '');
})(
    $argv[1] ?? ''
);