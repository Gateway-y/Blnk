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

use Blnk\Cmd\CertMagic\Acme\Account;
use Blnk\Cmd\CertMagic\Acmez\Client as AcmezClient;

/**
 * acmeClient holds state necessary to perform ACME operations
 * for certificate management with an ACME account. Call
 * ACMEIssuer.newACMEClientWithAccount() to get a valid one.
 * (certmagic acmeclient.go `acmeClient`.)
 */
final class AcmeClientWithAccount
{
    public ACMEIssuer $iss;

    public AcmezClient $acmeClient;

    public Account $account;

    public function __construct(ACMEIssuer $iss, AcmezClient $acmeClient, Account $account)
    {
        $this->iss = $iss;
        $this->acmeClient = $acmeClient;
        $this->account = $account;
    }

    /**
     * throttle waits on the internal rate limiter (scoped to CA + account email).
     *
     * @param string[] $names
     */
    public function throttle(array $names): void
    {
        $this->iss->throttle($this->acmeClient->client->directory, $names);
    }

    public function usingTestCA(): bool
    {
        return $this->iss->testCA !== '' && $this->acmeClient->client->directory === $this->iss->testCA;
    }

    /** @throws \RuntimeException */
    public function revoke(X509Certificate $cert, int $reason): void
    {
        $this->acmeClient->client->revokeCertificate($this->account, $cert, $this->account->privateKey, $reason);
    }
}
