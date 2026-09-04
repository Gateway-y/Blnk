<?php

declare(strict_types=1);

namespace Blnk\Internal\Filter;

/**
 * FilterValidationException is thrown where the Go filter package returns an
 * error (Validate, Build, BuildWithOptions, ValidateSortField, ...), carrying
 * the same message text as the Go `fmt.Errorf` calls.
 */
class FilterValidationException extends \InvalidArgumentException
{
}
