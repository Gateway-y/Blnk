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
 * SearchException is thrown where the Go search package returns an error.
 * For Typesense HTTP failures the exception code carries the HTTP status and
 * the message embeds "status: <code>" plus the response body, so callers can
 * apply the same string checks the Go code performs on typesense-go errors
 * (e.g. "already exists", "not found", "status: 404").
 */
class SearchException extends \RuntimeException
{
}
