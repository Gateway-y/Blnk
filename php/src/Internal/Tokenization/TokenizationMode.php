<?php

declare(strict_types=1);

namespace Blnk\Internal\Tokenization;

/**
 * TokenizationMode defines the type of tokenization to use.
 *
 * Port of the Go `TokenizationMode` int type (internal/tokenization/tokenization.go).
 */
final class TokenizationMode
{
    /** StandardMode uses regular AES-GCM encryption with base64 encoding. */
    public const StandardMode = 0;

    /** FormatPreservingMode maintains the format of the original data. */
    public const FormatPreservingMode = 1;
}
