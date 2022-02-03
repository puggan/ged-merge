<?php

namespace Puggan\GedMerge;

use Puggan\GedMerge\File\FileRow;

class GedRow
{
    public const REGEXP = /** @lang regexp */
        '#^\s*(?<level>\d+)\s+(?:@(?<label>[^ ]+)@\s+)?(?<type>\w+)(?:\s+(?<value>.*))?$#';

    /** @var GedRow[][] $children */
    public array $children = [];
    public int $seekSectionEnd;
    public int $lineSectionEnd;
    public string $displayName;

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
        $this->displayName = trim($this->type . ' ' . ($this->label ?: $this->value));
    }

    public function addOwnChild(GedRow $child, bool $addContent)
    {
        if ($addContent) {
            $this->children += [$child->type => []];
            $this->children[$child->type][] = $child;
        }
        $this->seekSectionEnd = max($this->seekSectionEnd, $child->seekSectionEnd);
        $this->lineSectionEnd = max($this->lineSectionEnd, $child->lineSectionEnd);
    }

    public function makeChild(string $type, string $label, string $value): self
    {
        $fakeRow = new FileRow('', 0, 0, 0, -1);
        return new self($fakeRow, $this->level + 1, $type, $label, $value);
    }

    public function appendChild(GedRow $child): void
    {
        if ($child->level !== $this->level + 1) {
            throw new \RuntimeException(
                'Wrong level on child, expected: ' . ($this->level + 1) . ' got: ' . $child->level
            );
        }
        $this->children += [$child->type => []];
        $this->children[$child->type][] = $child;
    }

    public function replaceChild(GedRow $child): void
    {
        if ($child->level !== $this->level + 1) {
            throw new \RuntimeException(
                'Wrong level on child, expected: ' . ($this->level + 1) . ' got: ' . $child->level
            );
        }
        $this->children[$child->type] = [];
        $this->appendChild($child);
    }

    /**
     * @param string[] $labelTranslations
     * @return \Generator<self>|self[]
     */
    public function lines(array $labelTranslations = []): \Generator
    {
        yield $this->line($labelTranslations);
        foreach ($this->children as $childTypeList) {
            foreach ($childTypeList as $child) {
                yield from $child->lines($labelTranslations);
            }
        }
    }

    public function line(array $labelTranslations = []): string
    {
        $label = $labelTranslations[$this->label] ?? $this->label;
        if ($this->value && $labelTranslations && str_starts_with($this->value, '@')) {
            $value = $labelTranslations[trim($this->value, '@')] ?? $this->value;
        } else {
            $value = $this->value;
        }
        return $this->level . ($label ? ' @' . $label . '@' : '') . ' ' . $this->type . ($value ? ' ' . $value : '');
    }
}