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
 * CacheOptions is used to configure certificate caches.
 * Once a cache has been created with certain options,
 * those settings cannot be changed.
 */
final class CacheOptions
{
    /**
     * REQUIRED. A function that returns a configuration
     * used for managing a certificate, or for accessing
     * that certificate's asset storage (e.g. for
     * OCSP staples, etc). The returned Config MUST
     * be associated with the same Cache as the caller,
     * use New to obtain a valid Config.
     *
     * The reason this is a callback function, dynamically
     * returning a Config (instead of attaching a static
     * pointer to a Config on each certificate) is because
     * the config for how to manage a domain's certificate
     * might change from maintenance to maintenance. The
     * cache is so long-lived, we cannot assume that the
     * host's situation will always be the same; e.g. the
     * certificate might switch DNS providers, so the DNS
     * challenge (if used) would need to be adjusted from
     * the last time it was run ~8 weeks ago.
     *
     * @var callable(Certificate): Config|null
     */
    public $getConfigForCert = null;

    /**
     * How often to check certificates for renewal (seconds);
     * if unset, DefaultOCSPCheckInterval will be used.
     */
    public float $ocspCheckInterval = 0;

    /**
     * How often to check certificates for renewal (seconds);
     * if unset, DefaultRenewCheckInterval will be used.
     */
    public float $renewCheckInterval = 0;

    /**
     * Maximum number of certificates to allow in the cache.
     * If reached, certificates will be randomly evicted to
     * make room for new ones. 0 means unlimited.
     */
    public int $capacity = 0;
}
