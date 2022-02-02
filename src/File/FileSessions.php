<?php

namespace Puggan\GedMerge\File;

class FileSessions
{
    /** @var mixed $file ?resource */
    public mixed $file = null;
    /** @var int[] $seekStack */
    private array $seekStack = [];
    private int $seekIndex = 0;
    private int $seekLastIndex = 0;

    public function __construct(
        private string $filePath
    )
    {
        $this->openFile();
    }

    public function __destruct()
    {
        $this->closeFile();
    }

    private function openFile(): void
    {
        if (!$this->file) {
            $this->file = fopen($this->filePath, 'rb') ?: null;
        }
    }

    private function closeFile(): void
    {
        if ($this->file) {
            fclose($this->file);
            $this->file = null;
        }
    }

    public function fileSessionStart(): int
    {
        if (!$this->file) {
            $this->openFile();
        }
        if ($this->seekStack && $this->seekIndex) {
            $position = ftell($this->file);
            if ($position === false) {
                throw new \RuntimeException('ftell() -> false');
            }
            $this->seekStack[$this->seekIndex] = $position;
        }
        $this->seekIndex = ++$this->seekLastIndex;
        $this->seekStack[$this->seekLastIndex] = 0;
        fseek($this->file, 0);
        return $this->seekLastIndex;
    }

    public function fileSessionSave(): int
    {
        $position = ftell($this->file);
        if ($this->seekStack && $this->seekIndex) {
            if ($position === false) {
                throw new \RuntimeException('ftell() -> false');
            }
            $this->seekStack[$this->seekIndex] = $position;
        }
        return $position;
    }

    public function fileSessionSwitch(int $sessionIndex): void
    {
        if ($this->seekIndex === $sessionIndex) {
            return;
        }
        $this->fileSessionSave();
        fseek($this->file, $this->seekStack[$sessionIndex]);
    }

    public function fileSessionClose(int $sessionIndex): void
    {
        if ($this->seekIndex === $sessionIndex) {
            $this->seekIndex = 0;
        }
        unset($this->seekStack[$sessionIndex]);
    }

    /**
     * @param int|null $seekFrom
     * @param int|null $seekTo
     * @param int|null $lineFrom
     * @param int|null $lineTo
     * @return \Generator<FileRow>|FileRow[]
     */
    public function getRows(?int $seekFrom = null, ?int $seekTo = null, ?int $lineFrom = null, ?int $lineTo = null): \Generator
    {
        $sessionId = $this->fileSessionStart();
        if (!$lineFrom) {
            $lineFrom = 0;
            if ($seekFrom) {
                while (!feof($this->file)) {
                    $row = trim(fgets($this->file));
                    $lineFrom++;
                    $position = ftell($this->file);
                    if ($position >= $seekFrom) {
                        break;
                    }
                }
            } else {
                $seekFrom = 0;
            }
        } elseif ($seekFrom) {
            fseek($this->file, $seekFrom);
        } else {
            $position = ftell($this->file);
            if ($position === false) {
                throw new \RuntimeException('ftell() -> false');
            }
            $seekFrom = $position;
        }
        /** @var int $lineFrom null is taken care of above */
        $line = $lineFrom - 1;
        $lastPosition = $seekFrom;
        while (!feof($this->file)) {
            if ($lineTo && $line >= $lineTo) {
                break;
            }
            if ($seekTo && $lastPosition >= $seekTo) {
                break;
            }
            $content = trim(fgets($this->file));
            $line++;
            $position = $this->fileSessionSave();
            yield new FileRow($content, $lastPosition, $position, $line);
            $this->fileSessionSwitch($sessionId);
            $lastPosition = $position;
        }

        $this->fileSessionClose($sessionId);
    }
}
