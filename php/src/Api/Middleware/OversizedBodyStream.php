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

namespace Blnk\Api\Middleware;

use Psr\Http\Message\StreamInterface;

/**
 * OversizedBodyStream is the PSR-7 stand-in for a body wrapped by Go's
 * `http.MaxBytesReader` once the limit has been exceeded: every read fails
 * with "http: request body too large", which the JSON binding surfaces to the
 * client exactly as the Go handlers do.
 */
final class OversizedBodyStream implements StreamInterface
{
    public const ErrorMessage = 'http: request body too large';

    private int $size;

    public function __construct(int $size)
    {
        $this->size = $size;
    }

    public function __toString(): string
    {
        // PSR-7 forbids throwing here; an empty document makes the binding fail with EOF.
        return '';
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return false;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException(self::ErrorMessage);
    }

    public function rewind(): void
    {
        throw new \RuntimeException(self::ErrorMessage);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException(self::ErrorMessage);
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        throw new \RuntimeException(self::ErrorMessage);
    }

    public function getContents(): string
    {
        throw new \RuntimeException(self::ErrorMessage);
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
