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

use Blnk\Cmd\CertMagic\Acme\Account;
use Blnk\Cmd\CertMagic\Acme\Authorization;
use Blnk\Cmd\CertMagic\Acme\Challenge;

/**
 * AuthzState is the stateful authorization object the order flow keeps per
 * authz on the order (acmez `authzState`: the Authorization plus the
 * account, the challenge currently being solved, its solver, and the
 * challenges not yet attempted).
 */
final class AuthzState
{
    public Authorization $authorization;

    public Account $account;

    public ?Challenge $currentChallenge = null;

    public ?Solver $currentSolver = null;

    /** @var Challenge[] */
    public array $remainingChallenges = [];

    public function __construct(Account $account, Authorization $authorization)
    {
        $this->account = $account;
        $this->authorization = $authorization;
    }

    public function identifierValue(): string
    {
        return $this->authorization->identifierValue();
    }

    /** @return string[] */
    public function listOfferedChallenges(): array
    {
        return self::challengeTypeNames($this->authorization->challenges);
    }

    /** @return string[] */
    public function listRemainingChallenges(): array
    {
        return self::challengeTypeNames($this->remainingChallenges);
    }

    /**
     * @param Challenge[] $challengeList
     * @return string[]
     */
    public static function challengeTypeNames(array $challengeList): array
    {
        $names = [];
        foreach ($challengeList as $chal) {
            $names[] = $chal->type;
        }
        return $names;
    }
}
