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

namespace Blnk\Cmd\CertMagic\Solvers;

use Blnk\Cmd\CertMagic\Acme\Challenge;
use Blnk\Cmd\CertMagic\Acmez\Solver;

/**
 * NoopSolver is the embedded solver of a distributedSolver that is only used
 * for its storage-key helpers (Go: `distributedSolver{storage, prefix}` with a
 * nil `solver`, as built by `Config.getChallengeInfo`).
 */
final class NoopSolver implements Solver
{
    public function present(Challenge $challenge): void
    {
    }

    public function cleanUp(Challenge $challenge): void
    {
    }
}
