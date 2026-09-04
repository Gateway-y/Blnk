<?php

declare(strict_types=1);

namespace Blnk\Internal\Tokenization;

/**
 * TokenizationException is thrown where the Go tokenization package returns an
 * error (invalid tokens, cipher failures, ...), carrying equivalent messages.
 */
class TokenizationException extends \RuntimeException
{
}
