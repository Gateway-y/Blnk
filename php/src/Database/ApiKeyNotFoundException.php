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

/**
 * ApiKeyNotFoundException is the port of the Go sentinel
 * `database.ErrAPIKeyNotFound = errors.New("api key not found")`
 * (database/api_key.go).
 *
 * Go callers compare with `errors.Is(err, database.ErrAPIKeyNotFound)`
 * (api/errors.go maps it to `apierror.ErrAPIKeyNotFound`); PHP callers catch
 * this class. It extends {@see NotFoundException} so the generic
 * "no rows" handling of the repository contract still applies.
 */
class ApiKeyNotFoundException extends NotFoundException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('api key not found', null, $previous);
    }
}
