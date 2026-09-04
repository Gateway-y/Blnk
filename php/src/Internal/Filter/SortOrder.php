<?php

declare(strict_types=1);

namespace Blnk\Internal\Filter;

/**
 * SortOrder represents the sort direction.
 *
 * Port of the Go `SortOrder` string type and its constants (internal/filter/types.go).
 */
final class SortOrder
{
    public const SortAsc = 'asc';
    public const SortDesc = 'desc';
}
