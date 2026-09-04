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
 * PreChecker is an interface that can be optionally implemented by
 * Issuers. Pre-checks are performed before each call (or batch of
 * identical calls) to Issue(), giving the issuer the option to ensure
 * it has all the necessary information/state.
 */
interface PreChecker
{
    /**
     * @param string[] $names
     * @throws \RuntimeException when the batch is not eligible for certificates
     */
    public function preCheck(array $names, bool $interactive): void;
}
