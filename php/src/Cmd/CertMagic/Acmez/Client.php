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
use Blnk\Cmd\CertMagic\Acme\AcmeCertificate;
use Blnk\Cmd\CertMagic\Acme\Authorization;
use Blnk\Cmd\CertMagic\Acme\Client as AcmeClient;
use Blnk\Cmd\CertMagic\Acme\Order;
use Blnk\Cmd\CertMagic\Acme\Problem;
use Blnk\Cmd\CertMagic\CertificateRequest;
use Blnk\Cmd\CertMagic\Crypto;
use Blnk\Cmd\CertMagic\Rfc3339;

/**
 * Client is a high-level API for ACME operations. It wraps
 * a lower-level ACME client with useful functions to make
 * common flows easier, especially for the issuance of
 * certificates.
 *
 * (acmez client.go: the sequence of RFC 8555 §7.1 with pluggable challenge
 * solvers.)
 */
final class Client
{
    /** The lower-level ACME client (Go: embedded `*acme.Client`). */
    public AcmeClient $client;

    /**
     * Map of solvers keyed by name of the challenge type.
     *
     * @var array<string, Solver>
     */
    public array $challengeSolvers = [];

    /**
     * Keep a list of challenges we've seen offered by servers, ordered by success rate.
     * (challengeTypes: a memory of how successful each challenge type is.)
     *
     * @var array<int, array{typeName: string, successes: int, total: int}>
     */
    private static array $preferredChallenges = [];

    public function __construct(AcmeClient $client)
    {
        $this->client = $client;
    }

    /**
     * ObtainCertificateForSANs is a light wrapper over ObtainCertificate that generates a simple CSR
     * for the identifiers given in the list of SANs using the given private key; then it obtains a
     * certificate right away. If you require customizing the parameters of the order, use ObtainCertificate
     * instead.
     *
     * @param string[] $sans
     * @return AcmeCertificate[]
     * @throws \RuntimeException
     */
    public function obtainCertificateForSANs(Account $account, \OpenSSLAsymmetricKey $certPrivateKey, array $sans): array
    {
        try {
            $csr = self::newCSR($certPrivateKey, $sans);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('generating CSR: %s', $err->getMessage()), 0, $err);
        }
        try {
            $params = OrderParameters::orderParametersFromCSR($account, $csr);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('forming order parameters: %s', $err->getMessage()), 0, $err);
        }
        return $this->obtainCertificate($params);
    }

    /**
     * NewCSR creates and signs a Certificate Signing Request (CSR) for the given subject
     * identifiers (SANs) with the private key.
     *
     * Supported SAN types are IPs, email addresses, URIs, and DNS names.
     *
     * @param string[] $sans
     * @throws \RuntimeException
     */
    public static function newCSR(\OpenSSLAsymmetricKey $privateKey, array $sans): CertificateRequest
    {
        if ($sans === []) {
            throw new \RuntimeException(sprintf('no SANs provided: [%s]', implode(' ', $sans)));
        }

        $dnsNames = [];
        $ipAddresses = [];
        $emailAddresses = [];
        $uris = [];
        foreach ($sans as $name) {
            if (filter_var($name, \FILTER_VALIDATE_IP) !== false) {
                $ipAddresses[] = $name;
            } elseif (str_contains($name, '@')) {
                $emailAddresses[] = $name;
            } elseif (str_contains($name, '/') && parse_url($name) !== false) {
                $uris[] = $name;
            } else {
                // "The domain name MUST be encoded in the form in which it would appear
                // in a certificate.  That is, it MUST be encoded according to the rules
                // in Section 7 of [RFC5280]." §7.1.4
                try {
                    $dnsNames[] = Crypto::idnaToASCII($name);
                } catch (\Throwable $err) {
                    throw new \RuntimeException(sprintf("converting identifier '%s' to ASCII: %s", $name, $err->getMessage()), 0, $err);
                }
            }
        }

        // to properly fill out the CSR, we need to create it, then parse it
        try {
            return CertificateRequest::create($privateKey, '', $dnsNames, $ipAddresses, $emailAddresses, $uris, false);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('generating CSR: %s', $err->getMessage()), 0, $err);
        }
    }

    /**
     * ObtainCertificate obtains all certificate chains from the ACME server resulting from the
     * given order parameters. The private key passed in must be the one that was (or will
     * be) used to sign the CSR. The order parameters must be fully populated with an account,
     * a list of subject identifiers, and a CSR source; and the list of subject identifiers
     * must exactly match those in the CSR.
     *
     * The method implements every single part of the ACME flow described in RFC 8555 §7.1 with
     * the exception of "Create account" because account management is outside the scope of
     * certificate issuance. The account's status MUST be "valid" in order to succeed.
     *
     * @return AcmeCertificate[]
     * @throws \RuntimeException
     */
    public function obtainCertificate(OrderParameters $params): array
    {
        if ($params->account->status !== Account::StatusValid) {
            throw new \RuntimeException(sprintf('account status is not valid: %s', $params->account->status));
        }
        if ($params->csr === null) {
            throw new \RuntimeException('missing CSR source');
        }
        if ($params->identifiers === []) {
            throw new \RuntimeException('order does not list any identifiers');
        }

        // create the ACME order
        $order = new Order();
        $order->profile = $params->profile;
        $order->identifiers = $params->identifiers;
        if ($params->notBefore !== null) {
            $order->notBefore = $params->notBefore;
        }
        if ($params->notAfter !== null) {
            $order->notAfter = $params->notAfter;
        }
        if ($params->replaces !== null) {
            try {
                $certID = AcmeClient::ariUniqueIdentifier($params->replaces);
            } catch (\Throwable $err) {
                throw new \RuntimeException(sprintf('invalid Replaces cert value: %s', $err->getMessage()), 0, $err);
            }
            $order->replaces = $certID;
        }

        // prepare to retry the transaction multiple times if necessary
        // until it succeeds
        $err = null;

        // remember which challenge types failed for which identifiers
        // so we can retry with other challenge types
        /** @var array<string, string[]> $failedChallengeTypes */
        $failedChallengeTypes = [];

        $maxAttempts = 3; // hard cap on number of retries for good measure
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($attempt > 1) {
                AcmeClient::wait(1.0);
            }

            // create order for a new certificate
            try {
                $order = $this->client->newOrder($params->account, $order);
            } catch (\Throwable $e) {
                throw new \RuntimeException(sprintf('creating new order: %s', $e->getMessage()), 0, $e);
            }

            // solve one challenge for each authz on the order
            try {
                $this->solveChallenges($params->account, $order, $failedChallengeTypes);
                $err = null;
            } catch (\Throwable $e) {
                $err = $e;
            }

            // yay, we win!
            if ($err === null) {
                break;
            }

            // for some errors, we can retry with different challenge types
            $problem = Problem::from($err);
            if ($problem !== null) {
                $authz = $problem->resource instanceof Authorization ? $problem->resource : null;
                $context = ['problem' => $problem->getMessage(), 'order' => $order->location, 'attempt' => $attempt, 'max_attempts' => $maxAttempts];
                if ($authz !== null) {
                    $context['identifier'] = $authz->identifierValue();
                }
                $this->client->logger?->error('validating authorization', $context);
                $errStr = 'solving challenge';
                if ($authz !== null) {
                    $errStr .= ': ' . $authz->identifierValue();
                }
                $err = new \RuntimeException(sprintf('%s: %s', $errStr, $err->getMessage()), 0, $err);
                if (RetryableError::is($err)) {
                    continue;
                }
                throw $err;
            }

            throw new \RuntimeException(sprintf('solving challenges: %s (order=%s)', $err->getMessage(), $order->location), 0, $err);
        }
        if ($err !== null) {
            throw $err;
        }

        $this->client->logger?->info('validations succeeded; finalizing order', ['order' => $order->location]);

        // get the CSR
        $csr = $params->csr;

        // ensure the order identifiers match the CSR
        try {
            self::validateOrderIdentifiers($order, $csr);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('validating order identifiers: %s', $e->getMessage()), 0, $e);
        }

        // finalize the order, which requests the CA to issue us a certificate
        try {
            $order = $this->client->finalizeOrder($params->account, $order, $csr->raw);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('finalizing order %s: %s', $order->location, $e->getMessage()), 0, $e);
        }

        // finally, download the certificate
        try {
            $certChains = $this->client->getCertificateChain($params->account, $order->certificate);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('downloading certificate chain from %s: %s (order=%s)', $order->certificate, $e->getMessage(), $order->location), 0, $e);
        }

        if ($certChains === []) {
            $this->client->logger?->info('no certificate chains offered by server');
        } else {
            $this->client->logger?->info('successfully downloaded available certificate chains', ['count' => \count($certChains), 'first_url' => $certChains[0]->url]);
        }

        return $certChains;
    }

    /**
     * validateOrderIdentifiers checks if the ACME identifiers provided for the
     * Order match the identifiers that are in the CSR. A mismatch between the two
     * should result the certificate not being issued by the ACME server, but
     * checking this on the client side is faster. Currently there's no way to
     * skip this validation.
     *
     * @throws \RuntimeException
     */
    private static function validateOrderIdentifiers(Order $order, CertificateRequest $csr): void
    {
        $csrIdentifiers = OrderParameters::createIdentifiersUsingCSR($csr);
        if (\count($csrIdentifiers) !== \count($order->identifiers)) {
            throw new \RuntimeException(sprintf(
                'number of identifiers in Order %s (%d) does not match the number of identifiers extracted from CSR %s (%d)',
                self::identifiersString($order->identifiers),
                \count($order->identifiers),
                self::identifiersString($csrIdentifiers),
                \count($csrIdentifiers)
            ));
        }

        $identifiers = [];
        foreach ($order->identifiers as $identifier) {
            foreach ($csrIdentifiers as $csrIdentifier) {
                if ($csrIdentifier->value === $identifier->value && $csrIdentifier->type === $identifier->type) {
                    $identifiers[] = $identifier;
                }
            }
        }

        if (\count($identifiers) !== \count($csrIdentifiers)) {
            throw new \RuntimeException(sprintf('identifiers in Order %s do not match the identifiers extracted from CSR %s', self::identifiersString($order->identifiers), self::identifiersString($csrIdentifiers)));
        }
    }

    /**
     * getAuthzObjects constructs stateful authorization objects for each authz on the order.
     * It includes all authorizations regardless of their status so that they can be
     * deactivated at the end if necessary. Be sure to check authz status before operating
     * on the authz; not all will be "pending" - some authorizations might already be valid.
     *
     * @param array<string, string[]> $failedChallengeTypes
     * @return AuthzState[]
     * @throws \RuntimeException
     */
    private function getAuthzObjects(Account $account, Order $order, array &$failedChallengeTypes): array
    {
        $authzStates = [];

        // start by allowing each authz's solver to present for its challenge
        foreach ($order->authorizations ?? [] as $authzURL) {
            try {
                $authorization = $this->client->getAuthorization($account, $authzURL);
            } catch (\Throwable $err) {
                throw new \RuntimeException(sprintf('getting authorization at %s: %s', $authzURL, $err->getMessage()), 0, $err);
            }
            $authz = new AuthzState($account, $authorization);

            // add all offered challenge types to our memory if they
            // aren't there already; we use this for statistics to
            // choose the most successful challenge type over time;
            // if initial fill, randomize challenge order
            $preferredWasEmpty = self::$preferredChallenges === [];
            foreach ($authz->authorization->challenges as $chal) {
                self::addUniquePreferredChallenge($chal->type);
            }
            if ($preferredWasEmpty) {
                shuffle(self::$preferredChallenges);
            }

            // copy over any challenges that are not known to have already
            // failed, making them candidates for solving for this authz
            self::enqueueUnfailedChallenges($failedChallengeTypes, $authz);

            $authzStates[] = $authz;
        }

        // sort authzs so that challenges which require waiting go first; no point
        // in getting authorizations quickly while others will take a long time
        usort($authzStates, static function (AuthzState $i, AuthzState $j): int {
            $iIsWaiter = $i->currentSolver instanceof Waiter;
            $jIsWaiter = $j->currentSolver instanceof Waiter;
            // "if i is a waiter, and j is not a waiter, then i is less than j"
            if ($iIsWaiter && !$jIsWaiter) {
                return -1;
            }
            if ($jIsWaiter && !$iIsWaiter) {
                return 1;
            }
            return 0;
        });

        return $authzStates;
    }

    /**
     * @param array<string, string[]> $failedChallengeTypes
     * @throws \Throwable
     */
    private function solveChallenges(Account $account, Order $order, array &$failedChallengeTypes): void
    {
        $authzStates = $this->getAuthzObjects($account, $order, $failedChallengeTypes);

        $err = null;
        try {
            // present for all challenges first; this allows them all to begin any
            // slow tasks up front if necessary before we start polling/waiting
            foreach ($authzStates as $authz) {
                // see §7.1.6 for state transitions
                if ($authz->authorization->status !== Account::StatusPending && $authz->authorization->status !== Account::StatusValid) {
                    throw new \RuntimeException(sprintf('authz %s has unexpected status; order will fail: %s', $authz->authorization->location, $authz->authorization->status));
                }
                if ($authz->authorization->status === Account::StatusValid) {
                    continue;
                }

                $this->presentForNextChallenge($authz);
            }

            // now that all solvers have had the opportunity to present, tell
            // the server to begin the selected challenge for each authz
            foreach ($authzStates as $authz) {
                $this->initiateCurrentChallenge($authz);
            }

            // poll each authz to wait for completion of all challenges
            foreach ($authzStates as $authz) {
                $this->pollAuthorization($account, $authz, $failedChallengeTypes);
            }
        } catch (\Throwable $e) {
            $err = $e;
        } finally {
            // when the function returns, make sure we clean up any and all resources

            // always clean up any remaining challenge solvers
            foreach ($authzStates as $authz) {
                if ($authz->currentSolver === null || $authz->currentChallenge === null) {
                    // happens when authz state ended on a challenge we have no
                    // solver for or if we have already cleaned up this solver
                    continue;
                }
                try {
                    $authz->currentSolver->cleanUp($authz->currentChallenge);
                } catch (\Throwable $cleanupErr) {
                    $this->client->logger?->error('cleaning up solver', [
                        'identifier' => $authz->identifierValue(),
                        'challenge_type' => $authz->currentChallenge->type,
                        'error' => $cleanupErr->getMessage(),
                    ]);
                }
            }

            if ($err !== null) {
                // if this function returns with an error, make sure to deactivate
                // all pending or valid authorization objects so they don't "leak"
                // See: https://github.com/go-acme/lego/issues/383 and https://github.com/go-acme/lego/issues/353
                foreach ($authzStates as $authz) {
                    if ($authz->authorization->status !== Account::StatusPending && $authz->authorization->status !== Account::StatusValid) {
                        continue;
                    }
                    try {
                        $updatedAuthz = $this->client->deactivateAuthorization($account, $authz->authorization->location);
                        $authz->authorization = $updatedAuthz;
                    } catch (\Throwable $deactivateErr) {
                        $this->client->logger?->error('deactivating authorization', [
                            'identifier' => $authz->identifierValue(),
                            'authz' => $authz->authorization->location,
                            'error' => $deactivateErr->getMessage(),
                        ]);
                    }
                }
            }
        }

        if ($err !== null) {
            throw $err;
        }
    }

    /**
     * @throws \RuntimeException
     */
    private function presentForNextChallenge(AuthzState $authz): void
    {
        if ($authz->authorization->status !== Account::StatusPending) {
            if ($authz->authorization->status === Account::StatusValid) {
                $this->client->logger?->info('authorization already valid', [
                    'identifier' => $authz->identifierValue(),
                    'authz_url' => $authz->authorization->location,
                    'expires' => Rfc3339::encode($authz->authorization->expires),
                ]);
            }
            return;
        }

        $this->nextChallenge($authz);
        /** @var \Blnk\Cmd\CertMagic\Acme\Challenge $challenge */
        $challenge = $authz->currentChallenge;
        /** @var Solver $solver */
        $solver = $authz->currentSolver;

        $this->client->logger?->info('trying to solve challenge', [
            'identifier' => $authz->identifierValue(),
            'challenge_type' => $challenge->type,
            'ca' => $this->client->directory,
        ]);

        try {
            $solver->present($challenge);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('presenting for challenge: %s', $err->getMessage()), 0, $err);
        }
    }

    /**
     * @throws \RuntimeException
     */
    private function initiateCurrentChallenge(AuthzState $authz): void
    {
        if ($authz->authorization->status !== Account::StatusPending) {
            $this->client->logger?->debug('skipping challenge initiation because authorization is not pending', [
                'identifier' => $authz->identifierValue(),
                'authz_status' => $authz->authorization->status,
            ]);
            return;
        }
        if ($authz->currentChallenge === null || $authz->currentSolver === null) {
            return;
        }

        // by now, all challenges should have had an opportunity to present, so
        // if this solver needs more time to finish presenting, wait on it now
        // (yes, this does block the initiation of the other challenges, but
        // that's probably OK, since we can't finalize the order until the slow
        // challenges are done too)
        if ($authz->currentSolver instanceof Waiter) {
            $this->client->logger?->debug('waiting for solver before continuing', [
                'identifier' => $authz->identifierValue(),
                'challenge_type' => $authz->currentChallenge->type,
            ]);
            try {
                $authz->currentSolver->wait($authz->currentChallenge);
            } catch (\Throwable $err) {
                throw new \RuntimeException(sprintf('waiting for solver %s to be ready: %s', $authz->currentSolver::class, $err->getMessage()), 0, $err);
            } finally {
                $this->client->logger?->debug('done waiting for solver', [
                    'identifier' => $authz->identifierValue(),
                    'challenge_type' => $authz->currentChallenge->type,
                ]);
            }
        }

        // for device-attest-01 challenges the client needs to present a payload
        // that will be validated by the CA.
        if ($authz->currentSolver instanceof Payloader) {
            $this->client->logger?->debug('getting payload from solver before continuing', [
                'identifier' => $authz->identifierValue(),
                'challenge_type' => $authz->currentChallenge->type,
            ]);
            try {
                $p = $authz->currentSolver->payload($authz->currentChallenge);
            } catch (\Throwable $err) {
                throw new \RuntimeException(sprintf('getting payload from solver %s failed: %s', $authz->currentSolver::class, $err->getMessage()), 0, $err);
            } finally {
                $this->client->logger?->debug('done getting payload from solver', [
                    'identifier' => $authz->identifierValue(),
                    'challenge_type' => $authz->currentChallenge->type,
                ]);
            }
            $authz->currentChallenge->payload = $p;
        }

        // tell the server to initiate the challenge
        try {
            $authz->currentChallenge = $this->client->initiateChallenge($authz->account, $authz->currentChallenge);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('initiating challenge with server: %s', $err->getMessage()), 0, $err);
        }

        $this->client->logger?->debug('challenge accepted', [
            'identifier' => $authz->identifierValue(),
            'challenge_type' => $authz->currentChallenge->type,
        ]);
    }

    /**
     * nextChallenge sets the next challenge (and associated solver) on
     * authz; it throws if there is no compatible challenge.
     *
     * @throws \RuntimeException
     */
    private function nextChallenge(AuthzState $authz): void
    {
        // find the most-preferred challenge that is also in the list of
        // remaining challenges, then make sure we have a solver for it
        foreach (self::$preferredChallenges as $prefChalType) {
            foreach ($authz->remainingChallenges as $i => $remainingChal) {
                if ($remainingChal->type !== $prefChalType['typeName']) {
                    continue;
                }
                $authz->currentChallenge = $remainingChal;
                $authz->currentSolver = $this->challengeSolvers[$authz->currentChallenge->type] ?? null;
                if ($authz->currentSolver !== null) {
                    array_splice($authz->remainingChallenges, $i, 1);
                    return;
                }
                $this->client->logger?->debug('no solver configured', ['challenge_type' => $remainingChal->type]);
                break;
            }
        }
        throw new \RuntimeException(sprintf(
            '%s: no solvers available for remaining challenges (configured=[%s] offered=[%s] remaining=[%s])',
            $authz->identifierValue(),
            implode(' ', $this->enabledChallengeTypes()),
            implode(' ', $authz->listOfferedChallenges()),
            implode(' ', $authz->listRemainingChallenges())
        ));
    }

    /**
     * @param array<string, string[]> $failedChallengeTypes
     * @throws \Throwable
     */
    private function pollAuthorization(Account $account, AuthzState $authz, array &$failedChallengeTypes): void
    {
        // In §7.5.1, the spec says:
        //
        // "For challenges where the client can tell when the server has
        // validated the challenge (e.g., by seeing an HTTP or DNS request
        // from the server), the client SHOULD NOT begin polling until it has
        // seen the validation request from the server."
        //
        // However, in practice, this is difficult in the general case because
        // we would need to design some relatively-nuanced concurrency and hope
        // that the solver implementations also get their side right -- and the
        // fact that it's even possible only sometimes makes it harder, because
        // each solver needs a way to signal whether we should wait for its
        // approval. So no, I've decided not to implement that recommendation
        // in this particular library, but any implementations that use the lower
        // ACME API directly are welcome and encouraged to do so where possible.
        $err = null;
        try {
            $authz->authorization = $this->client->pollAuthorization($account, $authz->authorization);
        } catch (\Throwable $e) {
            $err = $e;
        }

        // if a challenge was attempted (i.e. did not start valid)...
        if ($authz->currentSolver !== null && $authz->currentChallenge !== null) {
            // increment the statistics on this challenge type before handling error
            self::incrementPreferredChallenge($authz->currentChallenge->type, $err === null);

            // always clean up the challenge solver after polling, regardless of error
            try {
                $authz->currentSolver->cleanUp($authz->currentChallenge);
            } catch (\Throwable $cleanupErr) {
                $this->client->logger?->error('cleaning up solver', [
                    'identifier' => $authz->identifierValue(),
                    'challenge_type' => $authz->currentChallenge->type,
                    'error' => $cleanupErr->getMessage(),
                ]);
            }
            $authz->currentSolver = null; // avoid cleaning it up again later
        }

        // finally, handle any error from validating the authz
        if ($err !== null) {
            $problem = Problem::from($err);
            if ($problem !== null) {
                $this->client->logger?->error('challenge failed', [
                    'identifier' => $authz->identifierValue(),
                    'challenge_type' => $authz->currentChallenge?->type,
                    'problem' => $problem->getMessage(),
                ]);

                self::rememberFailedChallenge($failedChallengeTypes, $authz);

                if ($this->countAvailableChallenges($authz) > 0) {
                    switch ($problem->type) {
                        case Problem::ProblemTypeConnection:
                        case Problem::ProblemTypeDNS:
                        case Problem::ProblemTypeServerInternal:
                        case Problem::ProblemTypeUnauthorized:
                        case Problem::ProblemTypeTLS:
                            // this error might be recoverable with another challenge type
                            throw new RetryableError($err);
                    }
                }
            }
            throw new \RuntimeException(sprintf('[%s] %s', $authz->authorization->identifierValue(), $err->getMessage()), 0, $err);
        }

        $this->client->logger?->info('authorization finalized', [
            'identifier' => $authz->identifierValue(),
            'authz_status' => $authz->authorization->status,
        ]);
    }

    private function countAvailableChallenges(AuthzState $authz): int
    {
        $count = 0;
        foreach ($authz->remainingChallenges as $remainingChal) {
            if (isset($this->challengeSolvers[$remainingChal->type])) {
                $count++;
            }
        }
        return $count;
    }

    /** @return string[] */
    private function enabledChallengeTypes(): array
    {
        $enabledChallenges = [];
        foreach ($this->challengeSolvers as $name => $val) {
            if ($val !== null) {
                $enabledChallenges[] = (string) $name;
            }
        }
        return $enabledChallenges;
    }

    /*
     * failedChallengeMap keeps track of failed challenge types per identifier.
     */

    /**
     * @param array<string, string[]> $fcm
     */
    private static function rememberFailedChallenge(array &$fcm, AuthzState $authz): void
    {
        if ($authz->currentChallenge === null) {
            return;
        }
        $idKey = self::failedIdKey($authz);
        $fcm[$idKey][] = $authz->currentChallenge->type;
    }

    /**
     * enqueueUnfailedChallenges enqueues each challenge offered in authz if it
     * is not known to have failed for the authz's identifier already.
     *
     * @param array<string, string[]> $fcm
     */
    private static function enqueueUnfailedChallenges(array &$fcm, AuthzState $authz): void
    {
        $idKey = self::failedIdKey($authz);
        foreach ($authz->authorization->challenges as $chal) {
            if (!\in_array($chal->type, $fcm[$idKey] ?? [], true)) {
                $authz->remainingChallenges[] = $chal;
            }
        }
    }

    private static function failedIdKey(AuthzState $authz): string
    {
        return $authz->authorization->identifier->type . $authz->identifierValue();
    }

    /*
     * challengeTypes is a list of challenges we've seen and/or
     * used previously. It sorts from most successful to least
     * successful, such that most successful challenges are first.
     */

    private static function addUniquePreferredChallenge(string $challengeType): void
    {
        foreach (self::$preferredChallenges as $c) {
            if ($c['typeName'] === $challengeType) {
                return;
            }
        }
        self::$preferredChallenges[] = ['typeName' => $challengeType, 'successes' => 0, 'total' => 0];
    }

    private static function incrementPreferredChallenge(string $challengeType, bool $successful): void
    {
        foreach (self::$preferredChallenges as $i => $c) {
            if ($c['typeName'] === $challengeType) {
                self::$preferredChallenges[$i]['total']++;
                if ($successful) {
                    self::$preferredChallenges[$i]['successes']++;
                }
                break;
            }
        }
        // keep most successful challenges in front (stable sort)
        usort(self::$preferredChallenges, static function (array $a, array $b): int {
            return self::successRatio($b) <=> self::successRatio($a);
        });
    }

    /** @param array{typeName: string, successes: int, total: int} $ch */
    private static function successRatio(array $ch): float
    {
        if ($ch['total'] === 0) {
            return 1.0;
        }
        return $ch['successes'] / $ch['total'];
    }

    /** @param \Blnk\Cmd\CertMagic\Acme\Identifier[] $ids */
    private static function identifiersString(array $ids): string
    {
        $parts = [];
        foreach ($ids as $id) {
            $parts[] = sprintf('{%s %s}', $id->type, $id->value);
        }
        return '[' . implode(' ', $parts) . ']';
    }
}
