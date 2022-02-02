<?php

namespace Puggan\GedMerge\File;

class FileRow
{
    public function __construct(
        public string $content,
        public int $seekStart,
        public int $seekEnd,
        public int $lineNr,
        public int $fileIndex = 0,
    )
    {
    }
}