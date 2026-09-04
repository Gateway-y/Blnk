<?php

declare(strict_types=1);

namespace Blnk\Internal\Filter;

/**
 * TimestampValue represents a parsed timestamp with its original string format.
 *
 * Port of the Go `TimestampValue` struct (internal/filter/types.go).
 */
final class TimestampValue
{
    public \DateTimeImmutable $time;
    public string $original;
    public string $precision;

    public function __construct(\DateTimeImmutable $time, string $original = '', string $precision = '')
    {
        $this->time = $time;
        $this->original = $original;
        $this->precision = $precision;
    }
}
