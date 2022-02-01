<?php

namespace Puggan\GedMerge;

use Puggan\GedMerge\File\FileRow;

class GedRow
{
    public const REGEXP = /** @lang regexp */ '#^\s*(?<level>\d+)\s+(?:@(?<label>[^ ]+)@\s+)?(?<type>\w+)(?:\s+(?<value>.*))?$#';

    /** @var GedRow[][] $children */
    public array $children = [];
    public int $seekSectionEnd;
    public int $lineSectionEnd;

    public function __construct(
        public FileRow $row,
        public int $level,
        public string $type,
        public string $label,
        public string $value,
    )
    {
        $this->seekSectionEnd = $row->seekEnd;
        $this->lineSectionEnd = $row->lineNr;
    }

    public function addChild(GedRow $child, bool $addContent)
    {
        if ($addContent) {
            $this->children += [$child->type => []];
            $this->children[$child->type][] = $child;
        }
        $this->seekSectionEnd = max($this->seekSectionEnd, $child->seekSectionEnd);
        $this->lineSectionEnd = max($this->lineSectionEnd, $child->lineSectionEnd);
    }
}