<?php

declare(strict_types=1);

namespace Blnk\Internal\Filter;

/**
 * ParseResult mirrors the Go `ParseResult` struct (internal/filter/types.go).
 */
final class ParseResult
{
    public ?QueryFilterSet $filters = null;

    /** @var ParseError[] */
    public array $errors = [];
}
