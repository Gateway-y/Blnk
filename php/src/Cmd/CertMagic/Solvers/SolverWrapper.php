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
use Blnk\Cmd\CertMagic\Acmez\Waiter;

/**
 * solverWrapper should be used to wrap all challenge solvers so that
 * we can add the challenge info to memory; this makes challenges globally
 * solvable by a single HTTP or TLS server even if multiple servers with
 * different configurations/scopes need to get certificates.
 */
final class SolverWrapper implements Solver, Waiter
{
    public Solver $solver;

    public function __construct(Solver $solver)
    {
        $this->solver = $solver;
    }

    public function present(Challenge $chal): void
    {
        SolverRegistry::setActiveChallenge(SolverRegistry::challengeKey($chal), $chal);
        $this->solver->present($chal);
    }

    public function wait(Challenge $chal): void
    {
        if ($this->solver instanceof Waiter) {
            $this->solver->wait($chal);
        }
    }

    public function cleanUp(Challenge $chal): void
    {
        SolverRegistry::deleteActiveChallenge(SolverRegistry::challengeKey($chal));
        $this->solver->cleanUp($chal);
    }
}
