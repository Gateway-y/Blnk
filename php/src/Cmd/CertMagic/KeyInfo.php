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
 * KeyInfo holds information about a key in storage.
 * Key and IsTerminal are required; Modified and Size
 * are optional if the storage implementation is not
 * able to get that information. Setting them will
 * make certain operations more consistent or
 * predictable, but it is not crucial to basic
 * functionality.
 *
 * (certmagic storage.go `KeyInfo`.)
 */
final class KeyInfo
{
    public string $key = '';

    public ?\DateTimeImmutable $modified = null;

    public int $size = 0;

    /** false for directories (keys that act as prefix for other keys) */
    public bool $isTerminal = false;

    public function __construct(string $key = '', ?\DateTimeImmutable $modified = null, int $size = 0, bool $isTerminal = false)
    {
        $this->key = $key;
        $this->modified = $modified;
        $this->size = $size;
        $this->isTerminal = $isTerminal;
    }
}
