<?php

use Puggan\GedMerge\GedMerger;
use Puggan\GedMerge\GedRow;

require_once __DIR__ . '/vendor/autoload.php';

(static function($file) {
    if (!$file) {
        $file = GedMerger::readConfig('config.ini')->gedfile;
    }
    $ged = new GedMerger($file);
    $root = $ged->readRoot([0 => true, 1 => false]);
    $head = $ged->reReadRow($root->children['HEAD'][0]) ?? throw new \RuntimeException('Failed to read head');
    $foot = $root->children['TRLR'][0] ?? throw new \RuntimeException('Failed to read foot');
    unset($root->children['@'], $root->children['HEAD'], $root->children['TRLR']);

    $time = time();
    $headDate = $head->makeChild('DATE', '', strtoupper(date("j M Y", $time)));
    $headTime = $headDate->makeChild('TIME', '', date('H:i:s', $time));
    $headDate->appendChild($headTime);
    $head->replaceChild($headDate);

    //    $newFilePath = 'php://output'; //$file . '.new';
    $newFilePath = $file . '.new';
    $newFile = fopen($newFilePath, 'wb');
    if ($newFile === false) {
        throw new \RuntimeException('failed to open file: ' . $newFilePath);
    }
    /** @var GedRow[] $sections */
    foreach($head->lines() as $line) {
        fwrite($newFile, $line . PHP_EOL);
    }
    unset($head);
    foreach($root->children as $childTypeList) {
        foreach($childTypeList as $child) {
            $section = $ged->reReadRow($child);
            if (!$section) {
                print_r([$section, $child]);
                throw new \RuntimeException('re-loaded child is null');
            }
            foreach($section->lines() as $line) {
                fwrite($newFile, $line . PHP_EOL);
            }
        }
    }
    fwrite($newFile, $foot->line() . PHP_EOL);
    fclose($newFile);
})($argv[1] ?? '');
