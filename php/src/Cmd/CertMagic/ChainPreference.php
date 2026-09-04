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
 * ChainPreference describes the client's preferred certificate chain,
 * useful if the CA offers alternate chains. The first matching chain
 * will be selected.
 */
final class ChainPreference
{
    /** Prefer chains with the fewest number of bytes. */
    public ?bool $smallest = null;

    /**
     * Select first chain having a root with one of
     * these common names.
     *
     * @var string[]
     */
    public array $rootCommonName = [];

    /**
     * Select first chain that has any issuer with one
     * of these common names.
     *
     * @var string[]
     */
    public array $anyCommonName = [];
}
