<?php

namespace Puggan\GedMerge;

use Puggan\GedMerge\File\FileRow;
use Puggan\GedMerge\File\FileSessions;

class GedMerger
{
    // date / DatePeriod / dateRange / dateAppro
    const REGEXP_DATE_ISO = '#^(?<y>\d\d\d\d)-0?(?<m>\d\d?)-0?(?<d>\d\d?)$#';
    const REGEXP_DATE_DMY = '#^(?:(?<c>GREGORIAN|JULIAN|FRENCH_R|HEBREW) )?(?<d>\d+) (?<m>JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC) (?<y>\d+)(?: (?<e>[A-Za-z]+))?$#';
    const REGEXP_DATE_DMY_INVALID = '#^(?:(?<c>GREGORIAN|JULIAN|FRENCH_R|HEBREW) )?(?<d>\d+) (?<month>[A-Za-z]+) (?<y>\d+)(?: (?<e>[A-Za-z]+))?$#';
    const REGEXP_DATE_RANGE =
        '#^((?<t1>BET) (?:(?:(?:(?<c1>GREGORIAN|JULIAN|FRENCH_R|HEBREW) )?(?<d1>\d+) )?(?<m1>JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC) )?(?<y1>\d+)(?: (?<e1>.*))? ' .
        '(?<t2>AND) (?:(?:(?:(?<c2>GREGORIAN|JULIAN|FRENCH_R|HEBREW) )?(?<d2>\d+) )?(?<m2>JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC) )?(?<y2>\d+)(?: (?<e2>.*))?|' .
        '(?:(?<t>BEF|AFT|ABT|CAL|EST) )?(?:(?:(?:(?<c>GREGORIAN|JULIAN|FRENCH_R|HEBREW) )?(?<d>\d+) )?(?<m>JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC) )?(?<y>\d+)(?: (?<e>[A-Za-z]+))?)$#';
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
    const MONTH_REPAIR = [
        'FEBR' => 'FEB',
        'MARS' => 'MAR',
        'APRIL' => 'APR',
        'MAJ' => 'MAY',
        'JUNI' => 'JUN',
        'JULI' => 'JUL',
        'SEPT' => 'SEP',
        'OKT' => 'OCT',
    ];
    const DATE_RANGE_MAX = [-PHP_INT_MIN, PHP_INT_MAX];

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
                //echo $level, ' ', $type, in_array($type, $levelRules) ? '' : ' not', ' in ', json_encode($levelRules, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), PHP_EOL;
                return in_array($type, $levelRules, true);
            }
            foreach (range($level, 0, -1) as $stackLevel) {
                $levelRules = $filters[$level] ?? null;
                if (is_bool($levelRules)) {
                    return $levelRules;
                }

                if (is_array($levelRules)) {
                    $stackType = ($stack[$stackLevel] ?? null)?->type;
                    //echo $stackLevel, ' ', $type, in_array($stackType, $levelRules) ? '' : ' not', ' in ', json_encode($levelRules, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), PHP_EOL;
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
                //echo json_encode([$addContent, $lastPopped->type, $lastLeft->type, $lastPopped, $lastLeft], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), PHP_EOL;
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
                error_log('Bad row: ' . json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
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
        $birthByLabel = [];
        $root = $this->readRoot(
            [0 => ['INDI', 'FAM'], 1 => ['NAME', 'BIRT', 'DEAT', 'WIFE', 'HUSB', 'CHIL'], 2 => ['DATE']]
        );
        if (!$root->children['INDI']) {
            yield 'Empty / No persons';
        }
        foreach ($root->children['INDI'] ?? [] as $indi) {
            //<editor-fold desc="Name">
            $names = [];
            foreach ($indi->children['NAME'] ?? [] as $nameRow) {
                $names[] = $nameRow->value;
            }
            if (count($names) !== 1) {
                yield 'Multiple names: ' . json_encode($names, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            }
            $name = $names[0] ?? '(unknown)';
            //</editor-fold>

            //<editor-fold desc="BirthDay">
            $birthDates = [];
            foreach ($indi->children['BIRT'] ?? [] as $birthRow) {
                foreach ($birthRow->children['DATE'] ?? [] as $dateRow) {
                    if ($dateRow->value) {
                        $birthDates[$dateRow->value] = self::parseDateRange($dateRow->value, false);
                    }
                }
            }
            $birthDates = array_values(array_filter($birthDates));
            if ($birthDates) {
                $firstBirthDate = reset($birthDates);
                $birthDateFrom = $firstBirthDate[0];
                $birthDateTo = $firstBirthDate[1];
                foreach ($birthDates as $birthDate) {
                    $birthDateFrom = min($birthDateFrom, $birthDate[0]);
                    $birthDateTo = max($birthDateTo, $birthDate[1]);
                }
                $timeSpan = $birthDateTo - $birthDateFrom;
                if ($birthDateFrom === PHP_INT_MIN || $birthDateTo === PHP_INT_MAX || $timeSpan > 3000000000) {
                    yield 'Invalid birthdate for ' . $name . ': ' .
                        json_encode(array_keys($birthDates), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                    // Try to repair, to enable more tests
                }
                if ($birthDateFrom === PHP_INT_MIN || $birthDateTo === PHP_INT_MAX || $timeSpan > 3000000000) {
                    $birthDate = 'xxxx-xx-xx';
                    // same error again, skip
                } elseif ($timeSpan < 0) {
                    $birthDate = 'xxxx-xx-xx';
                    yield 'Invalid birthdate-range for ' . $name . ': ' .
                        json_encode(array_keys($birthDates), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                } else {
                    $birthDate = $this->dateRangeShortText($birthDateFrom, $birthDateTo);
                    //yield 'OK: ' . $birthDate . ' ' . $name;
                    if ($indi->label) {
                        $birthByLabel[$indi->label] = floor($birthDateTo / 2 + $birthDateFrom / 2);
                    }
                }
            } else {
                $birthDate = 'xxxx-xx-xx';
                $birthDateFrom = null;
                $birthDateTo = null;
            }
            if ($birthDate === 'xxxx-xx-xx') {
                $birthDateString = $indi->children['BIRT'][0]->children['DATE'][0]->value ?? '';
                $firstBirthDate = self::parseDateRange(
                    $birthDateString
                );
                if ($firstBirthDate) {
                    yield 'Repairable birthdate for ' . $name . ': ' . $birthDateString;
                    $birthDateFrom = $firstBirthDate[0];
                    $birthDateTo = $firstBirthDate[1];
                    $birthDate = $this->dateRangeShortText($birthDateFrom, $birthDateTo);
                } else {
                    yield ($birthDateString ? 'Invalid' : 'No') . ' birthdate for ' . $name . ($birthDateString ? ': ' . $birthDateString : '');
                }
            }
            //</editor-fold>

            //<editor-fold desc="DeathDay">
            $deathDates = [];
            foreach ($indi->children['DEAT'] ?? [] as $deathRow) {
                foreach ($deathRow->children['DATE'] ?? [] as $dateRow) {
                    if ($dateRow->value) {
                        $deathDates[$dateRow->value] = self::parseDateRange($dateRow->value, false);
                    }
                }
            }
            $deathDates = array_values(array_filter($deathDates));
            if ($deathDates) {
                $firstDeathDate = reset($deathDates);
                $deathDateFrom = $firstDeathDate[0];
                $deathDateTo = $firstDeathDate[1];
                foreach ($deathDates as $deathDate) {
                    $deathDateFrom = min($deathDateFrom, $deathDate[0]);
                    $deathDateTo = max($deathDateTo, $deathDate[1]);
                }
                $timeSpan = $deathDateTo - $deathDateFrom;
                if ($deathDateFrom === PHP_INT_MIN || $deathDateTo === PHP_INT_MAX || $timeSpan > 3000000000) {
                    $deathDate = 'xxxx-xx-xx';
                    yield 'Invalid deathdate for ' . $name . ': ' .
                        json_encode(array_keys($deathDates), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                } elseif ($timeSpan < 0) {
                    $deathDate = 'xxxx-xx-xx';
                    yield 'Invalid deathdate-range for ' . $name . ': ' .
                        json_encode(array_keys($deathDates), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                } else {
                    $deathDate = $this->dateRangeShortText($deathDateFrom, $deathDateTo);
                }
            } else {
                $deathDate = 'xxxx-xx-xx';
                $deathDateFrom = null;
                $deathDateTo = null;
            }
            if ($deathDate === 'xxxx-xx-xx') {
                $firstDeathDate = self::parseDateRange(
                    $indi->children['DEAT'][0]->children['DATE'][0]->value ?? ''
                );
                if ($firstDeathDate) {
                    yield 'Invalid deathdate for ' . $name . ': ' . $indi->children['DEAT'][0]->children['DATE'][0]->value;
                    $deathDateFrom = $firstDeathDate[0];
                    $deathDateTo = $firstDeathDate[1];
                    $deathDate = $this->dateRangeShortText($deathDateFrom, $deathDateTo);
                }
            }
            //</editor-fold>

            if ($deathDateTo && $birthDateFrom) {
                $years = round(($deathDateFrom + $deathDateTo - $birthDateFrom - $birthDateTo) / 63113472);
                if ($birthDateFrom > $deathDateTo) {
                    yield 'Negative age: ' . $years . ' for ' . $name;
                } elseif ($years >= 100) {
                    yield 'Extream age: ' . $years . ' for ' . $name;
                }
            }

            if (!$indi->label) {
                yield 'INDI not labeld: ' . $name;
            }
        }

        $labelPairs = [];

        //<editor-fold desc="Family">
        foreach ($root->children['FAM'] ?? [] as $fam) {
            $husbandLabel = trim($fam->children['HUSB'][0]->value ?? '', '@');
            $wifeLabel = trim($fam->children['WIFE'][0]->value ?? '', '@');

            if ($husbandLabel && $wifeLabel) {
                $labelPairs[$husbandLabel][$wifeLabel] = $fam->label;
                $labelPairs[$wifeLabel][$husbandLabel] = $fam->label;
            }

            $husbandBirth = $birthByLabel[$husbandLabel] ?? 0;
            $wifeBirth = $birthByLabel[$wifeLabel] ?? 0;
            $husbandName = $root->children['@'][$husbandLabel]->children['NAME'][0]->value ?? '?';
            $wifeName = $root->children['@'][$wifeLabel]->children['NAME'][0]->value ?? '?';
            $parentBirth = max($wifeBirth, $husbandBirth);
            $parentName = $wifeBirth > $husbandBirth ? $wifeName : $husbandName;

            if ($wifeBirth && $husbandBirth) {
                $ageDiff = abs($wifeBirth - $husbandBirth);
                $ageDiffYears = round($ageDiff / 31556736);
                if ($ageDiffYears > 50) {
                    yield 'Parents age-diff ' . $ageDiffYears . ' years: ' . $husbandName . ' and ' . $wifeName;
                }
            }

            foreach ($fam->children['CHIL'] ?? [] as $child) {
                if (!$child->value) {
                    continue;
                }
                $childLabel = trim($child->value, '@');
                if ($husbandLabel) {
                    $labelPairs[$childLabel][$husbandLabel] = $fam->label;
                    $labelPairs[$husbandLabel][$childLabel] = $fam->label;
                }
                if ($wifeLabel) {
                    $labelPairs[$childLabel][$wifeLabel] = $fam->label;
                    $labelPairs[$wifeLabel][$childLabel] = $fam->label;
                }
                if ($parentBirth) {
                    $childBirth = $birthByLabel[$childLabel] ?? 0;
                    if ($childBirth) {
                        $ageDiffYears = round(($childBirth - $parentBirth) / 31556736);
                        if ($ageDiffYears < 0) {
                            $childName = $root->children['@'][$childLabel]->children['NAME'][0]->value ?? '?';
                            yield 'Parent younger ' . $ageDiffYears . ' then child: ' . $childName . ' child of ' . $parentName;
                        } elseif ($ageDiffYears < 15) {
                            $childName = $root->children['@'][$childLabel]->children['NAME'][0]->value ?? '?';
                            yield 'Young Parent ' . $ageDiffYears . ': ' . $childName . ' child of ' . $parentName;
                        } elseif ($ageDiffYears > 80) {
                            $childName = $root->children['@'][$childLabel]->children['NAME'][0]->value ?? '?';
                            yield 'Old Parent ' . $ageDiffYears . ': ' . $childName . ' child of ' . $parentName;
                        }
                    }
                }
            }
        }
        //</editor-fold>

        $individLabels = [];
        foreach ($root->children['INDI'] ?? [] as $indi) {
            if ($indi->label) {
                $individLabels[$indi->label] = $indi->label;
            }
        }

        //print_r($individLabels);
        //print_r($labelPairs);

        $trees = [];
        $notConnected = $individLabels;
        while ($notConnected) {
            $treeStart = array_rand($notConnected);
            $trees[] = $treeStart;
            $todo = [$treeStart];
            unset($notConnected[$treeStart]);
            while ($todo) {
                $nextIndi = array_pop($todo);
                if (empty($labelPairs[$nextIndi])) {
                    yield 'No family: ' . ($root->children['@'][$nextIndi]->children['NAME'][0]->value ?? $nextIndi);
                }
                foreach ($labelPairs[$nextIndi] ?? [] as $connectedIndi => $familyLabel) {
                    if (empty($notConnected[$connectedIndi])) {
                        continue;
                    }
                    unset($notConnected[$connectedIndi]);
                    $todo[] = $connectedIndi;
                }
            }
        }
        if (count($trees) > 1) {
            $treeNames = [];
            foreach ($trees as $treeLabel) {
                $treeNames[$treeLabel] = $root->children['@'][$treeLabel]->children['NAME'][0]->value ?? $treeLabel;
            }
            yield 'You have multiple unconnected trees: ' .
                json_encode(array_values($treeNames), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * @return \Generator<string>|string[]
     */
    public function nameList(): \Generator
    {
        $root = $this->readRoot(
            [0 => ['INDI'], 1 => ['NAME', 'BIRT'], 2 => ['DATE']]
        );
        if (!$root->children['INDI']) {
            return;
        }
        foreach ($root->children['INDI'] ?? [] as $indi) {
            $name = $indi->children['NAME'][0]->value ?? '(unknown)';

            $birthDate = $indi->children['BIRT'][0]->children['DATE'][0]->value ?? null;
            $birthDateRange = $birthDate ? self::parseDateRange($birthDate) : null;
            $birthDateIso = $birthDateRange ? $this->dateRangeShortText(
                $birthDateRange[0],
                $birthDateRange[1]
            ) : 'xxxx-xx-xx';
            if ($birthDate && str_contains($birthDateIso, 'x') && preg_match('#[a-zA-Z]#', $birthDate)) {
                error_log(
                    'badDate: ' .
                    json_encode($birthDate, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . ' -> ' .
                    json_encode($birthDateIso, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL
                );
            }
            yield "{$birthDateIso} {$name}";
        }
    }

    /**
     * @param string $dateString
     * @param bool $repair
     * @param int $aboutYears
     * @param int $maxYears
     * @return ?int[] array{0: int, 1:int} 0: from, 1: to
     */
    public static function parseDateRange(string $dateString, bool $repair = true, int $aboutYears = 5, int $maxYears = 100): ?array
    {
        if (!$dateString) {
            return null;
        }

        if (!preg_match(self::REGEXP_DATE_RANGE, $dateString, $matches)) {
            if ($repair && preg_match(self::REGEXP_DATE_ISO, $dateString, $matches)) {
                // OK
            } elseif (!preg_match(self::REGEXP_DATE_DMY_INVALID, $dateString, $matches)) {
                return null;
            }
            if ($repair && !empty($matches['month'])) {
                $monthOrg = $matches['month'];
                $monthTest = mb_strtoupper($monthOrg);
                var_dump(['$monthOrg' => $monthOrg, '$monthTest' => $monthTest, self::MONTH_LOOKUP[$monthTest] ?? null, self::MONTH_REPAIR[$monthTest] ?? null]);
                die();
                if (!empty(self::MONTH_LOOKUP[$monthTest])) {
                    return self::parseDateRange(
                        preg_replace('#\b' . $monthOrg . '\b#', $monthTest, $dateString),
                        false,
                        $aboutYears,
                        $maxYears,
                    );
                }
                if (!empty(self::MONTH_REPAIR[$monthTest])) {
                    return self::parseDateRange(
                        preg_replace('#\b' . $monthOrg . '\b#', self::MONTH_REPAIR[$monthTest], $dateString),
                        false,
                        $aboutYears,
                        $maxYears,
                    );
                }
            }
        }

        if ($matches['t1'] ?? '') {
            $fromRange = self::dateSectionToTimes(
                $matches['y1'] ?? '',
                $matches['m1'] ?? '',
                $matches['d1'] ?? '',
                $matches['c1'] ?? '',
                $matches['e1'] ?? '',
            );
            $toRange = self::dateSectionToTimes(
                $matches['y2'] ?? '',
                $matches['m2'] ?? '',
                $matches['d2'] ?? '',
                $matches['c2'] ?? '',
                $matches['e2'] ?? '',
            );
            if (!$fromRange || !$toRange) {
                return null;
            }
            return [
                $fromRange[0],
                $toRange[1],
            ];
        }
        $range = self::dateSectionToTimes(
            $matches['y'] ?? '',
            $matches['m'] ?? '',
            $matches['d'] ?? '',
            $matches['c'] ?? '',
            $matches['e'] ?? '',
        );

        if (!$range) {
            return null;
        }

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

    private static function dateSectionToTimes($y, $m, $d, $c, $e): ?array
    {
        if ($c && $c !== 'GREGORIAN') {
            return null;
        }

        if ($e === 'BCE') {
            $y = -$y;
        } elseif ($e) {
            return null;
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

    /**
     * @param mixed $birthDateFrom
     * @param mixed $birthDateTo
     * @param mixed $timeSpan
     * @param string $birthDate
     * @param int $avgTimestamp
     * @return string
     */
    private function dateRangeShortText(mixed $birthDateFrom, mixed $birthDateTo): string
    {
        $timeSpan = $birthDateTo - $birthDateFrom;
        $avgTimestamp = (int)floor($birthDateFrom / 2 + $birthDateTo / 2);

        if ($timeSpan < 0 || $birthDateFrom === PHP_INT_MIN || $birthDateTo === PHP_INT_MAX || $timeSpan > 3000000000) {
            return 'xxxx-xx-xx';
        }

        if ($timeSpan < 100000) {
            return date('Y-m-d', $avgTimestamp);
        }

        if ($timeSpan < 5000000) {
            return date('Y-m', $avgTimestamp) . '-xx';
        }

        if ($timeSpan < 35000000) {
            return date('Y', $avgTimestamp) . '-xx-xx';
        }

        if ($timeSpan < 350000000) {
            return floor(date('Y', $avgTimestamp) / 10) . 'x-xx-xx';
        }

        if ($timeSpan < 3500000000) {
            return floor(date('Y', $avgTimestamp) / 100) . 'xx-xx-xx';
        }

        return floor(date('Y', $avgTimestamp) / 1000) . 'xxx-xx-xx';
    }
}