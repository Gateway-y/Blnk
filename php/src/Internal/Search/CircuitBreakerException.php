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

namespace Blnk\Internal\Search;

/**
 * CircuitBreakerException carries the two sentinel errors of gobreaker:
 *
 *  - ErrTooManyRequests is returned when the CB state is half open and the
 *    requests count is over the cb maxRequests
 *  - ErrOpenState is returned when the CB state is open
 */
final class CircuitBreakerException extends \RuntimeException
{
    public const ErrTooManyRequests = 'too many requests';

    public const ErrOpenState = 'circuit breaker is open';

    public static function tooManyRequests(): self
    {
        return new self(self::ErrTooManyRequests);
    }

    public static function openState(): self
    {
        return new self(self::ErrOpenState);
    }

    public function isOpenState(): bool
    {
        return $this->getMessage() === self::ErrOpenState;
    }

    public function isTooManyRequests(): bool
    {
        return $this->getMessage() === self::ErrTooManyRequests;
    }
}
