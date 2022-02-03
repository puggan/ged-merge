<?php

use Puggan\GedMerge\GedMerger;
use Puggan\GedMerge\GedRow;

require_once __DIR__ . '/vendor/autoload.php';

(static function (string $mainFile, string $inputFile) {
    if (!$mainFile) {
        throw new \RuntimeException('arguments should include at least one file.');
    }
    if (!$inputFile) {
        $inputFile = $mainFile;
        $mainFile = GedMerger::readConfig('config.ini')->gedfile;
    }
    $mainFile = realpath($mainFile);
    $inputFile = realpath($inputFile);
    if ($mainFile === $inputFile) {
        throw new \RuntimeException('Must be 2 different files');
    }
    if (!is_file($mainFile)) {
        throw new \RuntimeException('MainFile not found: ' . $mainFile);
    }
    if (!is_file($inputFile)) {
        throw new \RuntimeException('InputFile not found: ' . $inputFile);
    }

    $mainGed = new GedMerger($mainFile);
    $mainRoot = $mainGed->readRoot([0 => true, 1 => false]);
    $mainLabels = array_keys($mainRoot->children['@']);
    $inputGed = new GedMerger($inputFile, 2);
    $inputRoot = $inputGed->readRoot([0 => true, 1 => false]);
    $inputLabels = array_keys($inputRoot->children['@']);
    $labelsInUse = array_combine($mainLabels, $mainLabels);
    $labelsInUse['VOID'] = 'VOID';
    $labelNonCollisions = array_diff($inputLabels, $mainLabels);
    $labelsInUse += array_combine($labelNonCollisions, $labelNonCollisions);
    $labelCollisions = array_intersect($mainLabels, $inputLabels);
    $labelTranslations = [];
    $newLabelInt = 0;
    foreach($labelCollisions as $collisionLabel) {
        $newLabel = GedRow::int2xref($newLabelInt++);
        while(!empty($labelsInUse[$newLabel])) {
            $newLabel = GedRow::int2xref($newLabelInt++);
        }
        $labelsInUse[$newLabel] = $newLabel;
        $labelTranslations[$collisionLabel] = $newLabel;
    }

    $newContent = $mainGed->concat($inputGed, $mainRoot, $inputRoot, $labelTranslations);

    //    $newFilePath = 'php://output'; //$file . '.new';
    $newFilePath = $mainFile . '.new';
    $newFile = fopen($newFilePath, 'wb');
    if ($newFile === false) {
        throw new \RuntimeException('failed to open file: ' . $newFilePath);
    }
    /** @var GedRow[] $sections */
    foreach ($newContent as $line) {
        fwrite($newFile, $line . PHP_EOL);
    }
    fclose($newFile);
})(
    $argv[1] ?? '',
    $argv[2] ?? ''
);
