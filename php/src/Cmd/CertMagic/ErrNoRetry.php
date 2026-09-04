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

namespace Blnk\Cmd\CertMagic;

/**
 * ErrNoRetry is an error type which signals
 * to stop retries early. (certmagic async.go)
 */
final class ErrNoRetry extends \RuntimeException
{
    public \Throwable $err;

    public function __construct(\Throwable $err)
    {
        parent::__construct($err->getMessage(), 0, $err);
        $this->err = $err;
    }

    /** Unwrap makes it so that e wraps e.Err. */
    public function unwrap(): \Throwable
    {
        return $this->err;
    }

    /** is reports whether the error (or any error it wraps) is an ErrNoRetry (Go: errors.As). */
    public static function is(?\Throwable $err): bool
    {
        while ($err !== null) {
            if ($err instanceof self) {
                return true;
            }
            $err = $err->getPrevious();
        }
        return false;
    }
}
