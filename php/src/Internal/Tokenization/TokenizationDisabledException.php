<?php

declare(strict_types=1);

namespace Blnk\Internal\Tokenization;

/**
 * Port of the Go `ErrTokenizationDisabled` sentinel error
 * (internal/tokenization/tokenization.go), carrying the same message.
 */
class TokenizationDisabledException extends TokenizationException
{
    public const MESSAGE = 'tokenization is disabled: BLNK_TOKENIZATION_SECRET or tokenization_secret in your blnk.json file must be set to a 32-byte value';

    public function __construct(string $message = self::MESSAGE, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
