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
 * Solver is a type that can solve ACME challenges. All
 * implementations MUST honor context cancellations if
 * they are performing I/O or otherwise blocking.
 * (acmez solver.go)
 */
interface Solver
{
    /**
     * Present is called just before a challenge is initiated.
     * The implementation MUST prepare anything that is necessary
     * for completing the challenge; for example, provisioning
     * an HTTP resource, TLS certificate, or a DNS record.
     *
     * It MUST return quickly. If presenting the challenge token
     * will take time, then the implementation MUST also implement
     * the Waiter interface.
     *
     * @throws \Throwable
     */
    public function present(Challenge $challenge): void;

    /**
     * CleanUp is called after a challenge is finished, whether
     * successful or not. It MUST free/remove any resources that
     * were allocated/created during Present. It SHOULD NOT require
     * that Present ran successfully. It MUST return quickly.
     *
     * @throws \Throwable
     */
    public function cleanUp(Challenge $challenge): void;
}
