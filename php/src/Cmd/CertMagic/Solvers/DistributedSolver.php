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
use Blnk\Cmd\CertMagic\JsonUtil;
use Blnk\Cmd\CertMagic\Storage;
use Blnk\Cmd\CertMagic\StorageKeys;

/**
 * distributedSolver allows the ACME HTTP-01 and TLS-ALPN challenges
 * to be solved by an instance other than the one which initiated it.
 * This is useful behind load balancers or in other cluster/fleet
 * configurations. The only requirement is that the instance which
 * initiates the challenge shares the same storage and locker with
 * the others in the cluster. The storage backing the certificate
 * cache in distributedSolver.config is crucial.
 *
 * Obviously, the instance which completes the challenge must be
 * serving on the HTTPChallengePort for the HTTP-01 challenge or the
 * TLSALPNChallengePort for the TLS-ALPN-01 challenge (or have all
 * the packets port-forwarded) to receive and handle the request. The
 * server which receives the challenge must handle it by checking to
 * see if the challenge token exists in storage, and if so, decode it
 * and use it to serve up the correct response. HTTPChallengeHandler
 * in this package as well as the GetCertificate method implemented
 * by a Config support and even require this behavior.
 *
 * In short: the only two requirements for cluster operation are
 * sharing sync and storage, and using the facilities provided by
 * this package for solving the challenges.
 */
final class DistributedSolver implements Solver, Waiter
{
    /**
     * The storage backing the distributed solver. It must be
     * the same storage configuration as what is solving the
     * challenge in order to be effective.
     */
    private Storage $storage;

    /**
     * The storage key prefix, associated with the issuer
     * that is solving the challenge.
     */
    private string $storageKeyIssuerPrefix;

    /**
     * Since the distributedSolver is only a
     * wrapper over an actual solver, place
     * the actual solver here.
     */
    private Solver $solver;

    public function __construct(Storage $storage, string $storageKeyIssuerPrefix, Solver $solver)
    {
        $this->storage = $storage;
        $this->storageKeyIssuerPrefix = $storageKeyIssuerPrefix;
        $this->solver = $solver;
    }

    /**
     * Present invokes the underlying solver's Present method
     * and also stores domain, token, and keyAuth to the storage
     * backing the certificate cache of dhs.acmeIssuer.
     */
    public function present(Challenge $chal): void
    {
        $infoBytes = JsonUtil::marshal($chal);

        $this->storage->store($this->challengeTokensKey(SolverRegistry::challengeKey($chal)), $infoBytes);

        try {
            $this->solver->present($chal);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('presenting with embedded solver: %s', $err->getMessage()), 0, $err);
        }
    }

    /** Wait wraps the underlying solver's Wait() method, if any. Implements acmez.Waiter. */
    public function wait(Challenge $challenge): void
    {
        if ($this->solver instanceof Waiter) {
            $this->solver->wait($challenge);
        }
    }

    /**
     * CleanUp invokes the underlying solver's CleanUp method
     * and also cleans up any assets saved to storage.
     */
    public function cleanUp(Challenge $chal): void
    {
        $this->storage->delete($this->challengeTokensKey(SolverRegistry::challengeKey($chal)));
        try {
            $this->solver->cleanUp($chal);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('cleaning up embedded provider: %s', $err->getMessage()), 0, $err);
        }
    }

    /** challengeTokensPrefix returns the key prefix for challenge info. */
    public function challengeTokensPrefix(): string
    {
        return StorageKeys::join($this->storageKeyIssuerPrefix, 'challenge_tokens');
    }

    /**
     * challengeTokensKey returns the key to use to store and access
     * challenge info for domain.
     */
    public function challengeTokensKey(string $domain): string
    {
        return StorageKeys::join($this->challengeTokensPrefix(), StorageKeys::safe($domain) . '.json');
    }
}
