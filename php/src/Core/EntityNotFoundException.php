<?php

declare(strict_types=1);

namespace Blnk\Core;

use Blnk\Internal\ApiError\ApiErrorException;
use Blnk\Internal\ApiError\ErrorCode;

/**
 * ErrEntityNotFound is returned by UpdateMetadata when the target entity
 * does not exist. API handlers match it with errors.Is to return 404.
 *
 * Port of the `ErrEntityNotFound` sentinel error in metadata.go
 * (`errors.New("entity not found")`): the Go `errors.Is(err, blnk.ErrEntityNotFound)`
 * check becomes `catch (EntityNotFoundException)` / `instanceof`. It carries the
 * catalog code the Go API layer's classifySentinel maps the sentinel to
 * (apierror.ErrMetaEntityNotFound → 404), so mapErrorToHTTPStatus resolves it
 * without a message lookup.
 */
final class EntityNotFoundException extends ApiErrorException
{
    /** The Go sentinel's message. */
    public const MESSAGE = 'entity not found';

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(ErrorCode::ErrMetaEntityNotFound, self::MESSAGE, null, $previous);
    }
}
