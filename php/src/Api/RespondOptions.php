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

namespace Blnk\Api;

/**
 * RespondOptions is the port of the `respondOptions` struct of api/errors.go:
 * the settings the `respondOpt` functional options ({@see Errors::withDefault()}
 * and friends) mutate before an error response is written.
 */
final class RespondOptions
{
    /** Fallback code used when respondError cannot classify the error (""/empty = GEN_INTERNAL). */
    public string $defaultCode = '';

    /** Client-facing message override used when the fallback code applies. */
    public string $fallbackMessage = '';

    /**
     * Resolved generic code → domain-specific code replacements.
     *
     * @var array<string, string>
     */
    public array $upgrades = [];

    /** Name of the legacy flat field ("error" or "errors"). */
    public string $legacyKey = 'error';

    /** Value of the legacy field when it is not the plain message string. */
    public mixed $legacyValue = null;
}
