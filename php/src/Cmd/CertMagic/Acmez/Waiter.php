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

namespace Blnk\Cmd\CertMagic\Acmez;

use Blnk\Cmd\CertMagic\Acme\Challenge;

/**
 * Waiter is an optional interface for Solvers to implement. It
 * is used by the client to wait for a challenge to be ready to
 * be solved; useful for slow challenges such as DNS.
 * (acmez solver.go)
 */
interface Waiter
{
    /**
     * Wait blocks until the challenge is ready to be validated.
     *
     * @throws \Throwable
     */
    public function wait(Challenge $challenge): void;
}
