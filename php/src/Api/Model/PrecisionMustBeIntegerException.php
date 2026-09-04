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

namespace Blnk\Api\Model;

/**
 * PrecisionMustBeIntegerException is the port of the api/model sentinel
 * `ErrPrecisionMustBeInteger = errors.New("precision must be an integer value")`.
 * `errors.Is(err, ErrPrecisionMustBeInteger)` becomes an instanceof check on
 * the exception chain (see {@see \Blnk\Api\Errors::classifySentinel()}).
 */
final class PrecisionMustBeIntegerException extends \RuntimeException
{
    /** The Go sentinel's message. */
    public const MESSAGE = 'precision must be an integer value';

    /** Go name of the sentinel, for call sites that spell it out. */
    public const ErrPrecisionMustBeInteger = self::MESSAGE;

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(self::MESSAGE, 0, $previous);
    }
}
