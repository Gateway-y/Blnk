<?php

/*
Copyright 2024 Blnk Finance Authors.

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

    http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
*/

declare(strict_types=1);

namespace Blnk\Cmd;

/**
 * Migration is the port of rubenv/sql-migrate's `Migration` struct (the
 * library cmd/migrate.go drives): one parsed `sql/<id>.sql` file with its Up
 * and Down statements and the `notransaction` flags of each direction.
 * `id` is the file name (e.g. "1708676327.sql"), which is also what the
 * migration table records.
 */
final class Migration
{
    private const NumberPrefixRegex = '/^(\d+).*$/';

    public string $id;

    /** @var string[] Up statements */
    public array $up = [];

    /** @var string[] Down statements */
    public array $down = [];

    public bool $disableTransactionUp = false;

    public bool $disableTransactionDown = false;

    public function __construct(string $id = '')
    {
        $this->id = $id;
    }

    /**
     * NumberPrefixMatches returns the regexp submatches of the numeric id
     * prefix, or null when the id does not start with digits.
     *
     * @return string[]|null
     */
    public function numberPrefixMatches(): ?array
    {
        if (preg_match(self::NumberPrefixRegex, $this->id, $m) === 1) {
            return $m;
        }
        return null;
    }

    /**
     * VersionInt parses the numeric prefix of the id (Go panics when it does
     * not fit an int64; PHP throws).
     *
     * @throws \RuntimeException
     */
    public function versionInt(): int
    {
        $m = $this->numberPrefixMatches();
        $v = $m[1] ?? '';
        $normalized = ltrim($v, '0');
        $parsed = (int) $v;
        if ($v === '' || !ctype_digit($v) || ($normalized === '' ? $parsed !== 0 : (string) $parsed !== $normalized)) {
            throw new \RuntimeException(sprintf('Could not parse %s into int64: value out of range', json_encode($v)));
        }
        return $parsed;
    }

    /** isNumeric reports whether the id starts with a number. */
    public function isNumeric(): bool
    {
        return $this->numberPrefixMatches() !== null;
    }

    /**
     * Less orders migrations: numerically by version prefix when both are
     * numeric, numeric ids before non-numeric ones, otherwise by id string.
     */
    public function less(Migration $other): bool
    {
        $mNumeric = $this->isNumeric();
        $oNumeric = $other->isNumeric();
        switch (true) {
            case $mNumeric && $oNumeric && $this->versionInt() !== $other->versionInt():
                return $this->versionInt() < $other->versionInt();
            case $mNumeric && !$oNumeric:
                return true;
            case !$mNumeric && $oNumeric:
                return false;
            default:
                return strcmp($this->id, $other->id) < 0;
        }
    }

    /**
     * compare is the `byId` sort comparator (sort.Sort(byId(migrations))).
     */
    public static function compare(Migration $a, Migration $b): int
    {
        if ($a->less($b)) {
            return -1;
        }
        if ($b->less($a)) {
            return 1;
        }
        return 0;
    }
}
