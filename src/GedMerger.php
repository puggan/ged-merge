<?php

namespace Puggan\GedMerge;

use Puggan\GedMerge\File\FileRow;
use Puggan\GedMerge\File\FileSessions;

class GedMerger
{
    // date / DatePeriod / dateRange / dateAppro
    const REGEXP_DATE_DMY = '#^(?:(?<c>GREGORIAN|JULIAN|FRENCH_R|HEBREW) )?(?<d>\d+) (?<m>JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC) (?<y>\d+)(?: (?<e>.*))?$#';
    const REGEXP_DATE_DMY_INVALID = '#^(?:(?<c>GREGORIAN|JULIAN|FRENCH_R|HEBREW) )?(?<d>\d+) (?:[A-Za-z]+) (?<y>\d+)(?: (?<e>.*))?$#';
    const REGEXP_DATE_RANGE =
        '#^((?<t1>BET) (?:(?:(?:(?<c1>GREGORIAN|JULIAN|FRENCH_R|HEBREW) )?(?<d1>\d+) )(?<m1>JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC) )(?<y1>\d+)(?: (?<e1>.*))? ' .
        '(?<t2>AND) (?:(?:(?:(?<c2>GREGORIAN|JULIAN|FRENCH_R|HEBREW) )?(?<d2>\d+) )(?<m2>JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC) )(?<y2>\d+)(?: (?<e2>.*))?|' .
        '(?:(?<t>BEF|AFT|ABT|CAL|EST) )?(?:(?:(?:(?<c>GREGORIAN|JULIAN|FRENCH_R|HEBREW) )?(?<d>\d+) )(?<m>JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC) )(?<y>\d+)(?: (?<e>.*))?)$#';
    const MONTH_NAMES = [1 => 'JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
    const MONTH_LOOKUP = [
        'JAN' => 1,
        'FEB' => 2,
        'MAR' => 3,
        'APR' => 4,
        'MAY' => 5,
        'JUN' => 6,
        'JUL' => 7,
        'AUG' => 8,
        'SEP' => 9,
        'OCT' => 10,
        'NOV' => 11,
        'DEC' => 12,
    ];

    private FileSessions $file;

    public function __construct(
        private string $gedFilePath
    )
    {
        $this->file = new FileSessions($gedFilePath);
    }

    public function __destruct()
    {
        unset($this->file);
    }

    static function readConfig(string $path): object
    {
        return (object)parse_ini_file(str_starts_with($path, '/') ? $path : __DIR__ . '/../' . $path);
    }

    public static function instance(): self
    {
        $config = self::readConfig('config.ini');
        return new self(
            str_starts_with($config->gedfile, '/') ? $config->gedfile : __DIR__ . '/../' . $config->gedfile
        );
    }

    /**
     * @param array $filters
     * @param int|null $seekFrom
     * @param int|null $seekTo
     * @param int|null $lineFrom
     * @param int|null $lineTo
     * @param bool $children
     * @return \Generator<GedRow>|GedRow[]
     * @throws \JsonException
     */
    public function parseRows(array $filters, ?int $seekFrom = null, ?int $seekTo = null, ?int $lineFrom = null, ?int $lineTo = null, bool $children = false): \Generator
    {
        /** @var GedRow[] $stack */
        $stack = [];
        $wanted = static function (int $level, string $type) use ($stack, $filters): bool {
            $levelRules = $filters[$level] ?? null;
            if (is_bool($levelRules)) {
                return $levelRules;
            }

            if (is_array($levelRules)) {
                //echo $level, ' ', $type, in_array($type, $levelRules) ? '' : ' not', ' in ', json_encode($levelRules), PHP_EOL;
                return in_array($type, $levelRules, true);
            }
            foreach (range($level, 0, -1) as $stackLevel) {
                $levelRules = $filters[$level] ?? null;
                if (is_bool($levelRules)) {
                    return $levelRules;
                }

                if (is_array($levelRules)) {
                    $stackType = ($stack[$stackLevel] ?? null)?->type;
                    //echo $stackLevel, ' ', $type, in_array($stackType, $levelRules) ? '' : ' not', ' in ', json_encode($levelRules), PHP_EOL;
                    return $stackType && in_array($stackType, $levelRules, true);
                }
            }
            return false;
        };
        $popStack = static function (array &$stack) use ($children, $wanted): ?GedRow {
            $debugKey = implode(
                '->',
                array_map(function (GedRow $r) {
                    return $r->type;
                }, $stack)
            );
            /** @var GedRow[] $stack */
            $lastPopped = array_pop($stack);
            $lastLeft = end($stack);
            if ($lastLeft) {
                $addContent = $wanted($lastPopped->level, $lastPopped->type);
                $lastLeft->addChild($lastPopped, $addContent);
                //echo json_encode([$addContent, $lastPopped->type, $lastLeft->type, $lastPopped, $lastLeft]), PHP_EOL;
                if ($children && $addContent) {
                    return $lastPopped;
                }
            } elseif ($wanted($lastPopped->level, $lastPopped->type)) {
                return $lastPopped;
            }
            return null;
        };
        foreach ($this->file->getRows($seekFrom, $seekTo, $lineFrom, $lineTo) as $row) {
            if (!$row->content) {
                continue;
            }
            if (preg_match(GedRow::REGEXP, $row->content, $matches)) {
                $gedRow = new GedRow(
                    $row,
                    $matches['level'],
                    $matches['type'],
                    $matches['label'],
                    $matches['value'] ?? '',
                );
                while (!empty($stack[$gedRow->level])) {
                    //echo '- ', implode('->', array_map( function(GedRow $r) {return $r->type;}, $stack)), PHP_EOL;
                    $row = $popStack($stack);
                    if ($row) {
                        yield $row;
                    }
                }
                //echo '+ ', ($stack ? implode('->', array_map( function(GedRow $r) {return $r->type;}, $stack)) . '->' : ''), $gedRow->type, PHP_EOL;
                $stack[$gedRow->level] = $gedRow;
            } else {
                error_log('Bad row: ' . json_encode($row, JSON_THROW_ON_ERROR));
            }
        }
        while (!empty($stack)) {
            //echo '= ', implode('->', array_map( function(GedRow $r) {return $r->type;}, $stack)), PHP_EOL;
            $row = $popStack($stack);
            if ($row) {
                yield $row;
            }
        }
    }

    public function readRoot(array $filters = []): GedRow
    {
        $root = new GedRow(new FileRow(0, 0, 0, 0), -1, 'ROOT', '', '');
        $defaultFilter = [0 => true, 1 => ['NAME'], 2 => false];
        foreach ($this->parseRows($filters ?: $defaultFilter, null, null, null, null, true) as $child) {
            if ($child->label) {
                $root->children['@'][$child->label] = $child;
            }
            if ($child->level === 0) {
                $root->addChild($child, true);
            }
        }
        return $root;
    }

    /**
     * @return \Generator<string>|string[]
     */
    public function validate(): \Generator
    {
        $root = $this->readRoot(
            [0 => ['INDI', 'FAM'], 1 => ['NAME', 'BIRT', 'DEAT', 'WIFE', 'HUSB', 'CHIL'], 2 => ['DATE']]
        );
        if (!$root->children['INDI']) {
            yield 'Empty / No persons';
        }
        foreach ($root->children['INDI'] ?? [] as $indi) {
            //print_r($indi);
            $names = [];
            foreach ($indi->children['NAME'] ?? [] as $nameRow) {
                $names[] = $nameRow->value;
            }
            if (count($names) !== 1) {
                yield 'Multiple names: ' . json_encode($names, JSON_UNESCAPED_UNICODE);
            }
            $name = $names[0] ?? '(unknown)';
            $birthDates = [];
            foreach ($indi->children['BIRT'] ?? [] as $birthRow) {
                foreach ($birthRow->children['DATE'] ?? [] as $dateRow) {
                    if ($dateRow->value) {
                        $birthDates[$dateRow->value] = self::parseDateRange($dateRow->value);
                    }
                }
            }
            if ($birthDates) {
                $firstBirthDate = reset($birthDates);
                $birthDateFrom = $firstBirthDate[0];
                $birthDateTo = $firstBirthDate[1];
                foreach ($birthDates as $birthDate) {
                    $birthDateFrom = min($birthDateFrom, $birthDate[0]);
                    $birthDateTo = max($birthDateTo, $birthDate[1]);
                }
                $timeSpan = $birthDateTo - $birthDateFrom;
                $avgTimestamp = (int) floor($birthDateFrom / 2 + $birthDateTo / 2);
                if ($birthDateFrom === PHP_INT_MIN || $birthDateTo === PHP_INT_MAX || $timeSpan > 3000000000) {
                    $birthDate = 'xxxx-xx-xx';
                    yield 'Invalid birthdate for ' . $name . ': ' . json_encode(array_keys($birthDates));
                } elseif ($timeSpan < 0) {
                    $birthDate = 'xxxx-xx-xx';
                    yield 'Invalid birthdate-ranfe for ' . $name . ': ' . json_encode(array_keys($birthDates));
                } elseif ($timeSpan < 100000) {
                    $birthDate = date('Y-m-d', $avgTimestamp);
                } elseif ($timeSpan < 5000000) {
                    $birthDate = date('Y-m', $avgTimestamp) . '-xx';
                } elseif ($timeSpan < 35000000) {
                    $birthDate = date('Y', $avgTimestamp) . '-xx-xx';
                } elseif ($timeSpan < 350000000) {
                    $birthDate = floor(date('Y', $avgTimestamp) / 10) . 'x-xx-xx';
                } elseif ($timeSpan < 3500000000) {
                    $birthDate = floor(date('Y', $avgTimestamp) / 100) . 'xx-xx-xx';
                }
                if ($birthDate !== 'xxxx-xx-xx') {
                    yield 'OK: ' . $birthDate . ' ' . $name;
                }
            } else {
                yield 'No birthdate for ' . $name;
            }
        }
    }

    /**
     * @param string $dateString
     * @param int $aboutYears
     * @param int $maxYears
     * @return int[] array{0: int, 1:int} 0: from, 1: to
     */
    private static function parseDateRange(string $dateString, int $aboutYears = 5, int $maxYears = 100): array
    {
        if (!$dateString) {
            return [-PHP_INT_MIN, PHP_INT_MAX];
        }

        if (!preg_match(self::REGEXP_DATE_RANGE, $dateString, $matches)) {
            if (!preg_match(self::REGEXP_DATE_DMY_INVALID, $dateString, $matches)) {
                return [-PHP_INT_MIN, PHP_INT_MAX];
            }
        }

        if ($matches['t1'] ?? '') {
            return [
                self::dateSectionToTimes(
                    $matches['y1'] ?? '',
                    $matches['m1'] ?? '',
                    $matches['d1'] ?? '',
                    $matches['c1'] ?? '',
                    $matches['e1'] ?? '',
                )[0],
                self::dateSectionToTimes(
                    $matches['y2'] ?? '',
                    $matches['m2'] ?? '',
                    $matches['d2'] ?? '',
                    $matches['c2'] ?? '',
                    $matches['e2'] ?? '',
                )[1],
            ];
        }
        $range = self::dateSectionToTimes(
            $matches['y'] ?? '',
            $matches['m'] ?? '',
            $matches['d'] ?? '',
            $matches['c'] ?? '',
            $matches['e'] ?? '',
        );

        return match ($matches['t'] ?? '') {
            '' => $range,
            'ABT', 'CAL', 'EST', => [
                strtotime("-{$aboutYears} years", $range[0]),
                strtotime("+{$aboutYears} years", $range[1]),
            ],
            'BEF' => [
                strtotime("-{$maxYears} years", $range[0]),
                $range[1],
            ],
            'AFT' => [
                $range[0],
                strtotime("+{$maxYears} years", $range[1]),
            ],
            default => new \RuntimeException('Unknown type range, passed regexp: ' . ($matches['t'] ?? '')),
        };
    }

    private static function dateSectionToTimes($y, $m, $d, $c, $e): array
    {
        if ($c && $c !== 'GREGORIAN') {
            return [-PHP_INT_MIN, PHP_INT_MAX];
        }

        if ($e === 'BCE') {
            $y = -$y;
        } elseif ($e) {
            return [-PHP_INT_MIN, PHP_INT_MAX];
        }

        if ($m && isset(self::MONTH_LOOKUP[$m])) {
            $m = self::MONTH_LOOKUP[$m];
        }

        if ($m && is_numeric($m)) {
            $m1 = $m > 9 ? $m : '0' . $m;
            $m2 = $m1;
        } else {
            $m1 = '01';
            $m2 = '12';
        }

        if ($d > 9) {
            $d1 = $d;
            $d2 = $d;
        } elseif ($d > 0) {
            $d1 = '0' . +$d;
            $d2 = '0' . +$d;
        } else {
            $d1 = '01';
            $d2 = '31';
        }

        if ($d2 > '28') {
            $dmax = date('t', strtotime("{$y}-{$m2}-01"));
            if ($dmax < $d2) {
                $d2 = $dmax;
            }
        }

        return [
            strtotime("{$y}-{$m1}-{$d1} 00:00:00"),
            strtotime("{$y}-{$m2}-{$d2} 23:59:59"),
        ];
    }
}