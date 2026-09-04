<?php

declare(strict_types=1);

namespace Blnk\Internal\Filter;

/**
 * ParseOptions mirrors the Go `ParseOptions` struct (internal/filter/types.go).
 */
final class ParseOptions
{
    /** default 20 */
    public int $maxFilters = 0;

    /** default 100 */
    public int $maxInValues = 0;

    /** default 1000 */
    public int $maxCharLen = 0;

    public function __construct(int $maxFilters = 0, int $maxInValues = 0, int $maxCharLen = 0)
    {
        $this->maxFilters = $maxFilters;
        $this->maxInValues = $maxInValues;
        $this->maxCharLen = $maxCharLen;
    }
}
