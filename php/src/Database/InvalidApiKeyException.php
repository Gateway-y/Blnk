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

namespace Blnk\Database;

use Blnk\Internal\ApiError\ApiErrorException;
use Blnk\Internal\ApiError\ErrorCode;

/**
 * InvalidApiKeyException is the port of the Go sentinel
 * `database.ErrInvalidAPIKey = errors.New("invalid api key")`
 * (database/api_key.go).
 *
 * Go callers compare with `errors.Is(err, database.ErrInvalidAPIKey)`;
 * api/errors.go classifies it as `apierror.ErrAuthInvalidAPIKey`, which is the
 * code carried here.
 */
class InvalidApiKeyException extends ApiErrorException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(ErrorCode::ErrAuthInvalidAPIKey, 'invalid api key', null, $previous);
    }
}
