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
 * OnDemandConfig configures on-demand TLS (certificate
 * operations as-needed, like during TLS handshakes,
 * rather than immediately).
 *
 * When this package's high-level convenience functions
 * are used (HTTPS, Manage, etc., where the Default
 * config is used as a template), this struct regulates
 * certificate operations using an implicit whitelist
 * containing the names passed into those functions if
 * no DecisionFunc is set. This ensures some degree of
 * control by default to avoid certificate operations for
 * arbitrary domain names. To override this whitelist,
 * manually specify a DecisionFunc. To impose rate limits,
 * specify your own DecisionFunc.
 */
final class OnDemandConfig
{
    /**
     * If set, this function will be called to determine
     * whether a certificate can be obtained or renewed
     * for the given name. If it throws, the
     * request will be denied.
     *
     * @var callable(string): void|null
     */
    public $decisionFunc = null;

    /**
     * Sources for getting new, unmanaged certificates.
     * They will be invoked only during TLS handshakes
     * before on-demand certificate management occurs,
     * for certificates that are not already loaded into
     * the in-memory cache.
     *
     * TODO: EXPERIMENTAL: subject to change and/or removal.
     *
     * @var Manager[]
     */
    public array $managers = [];

    /**
     * List of allowed hostnames (SNI values) for
     * deferred (on-demand) obtaining of certificates.
     * Used only by higher-level functions in this
     * package to persist the list of hostnames that
     * the config is supposed to manage. This is done
     * because it seems reasonable that if you say
     * "Manage [domain names...]", then only those
     * domain names should be able to have certs;
     * we don't NEED this feature, but it makes sense
     * for higher-level convenience functions to be
     * able to retain their convenience (alternative
     * is: the user manually creates a DecisionFunc
     * that allows the same names it already passed
     * into Manage) and without letting clients have
     * their run of any domain names they want.
     * Only enforced if len > 0. (This is a map to
     * avoid O(n^2) performance; when it was a slice,
     * we saw a 30s CPU profile for a config managing
     * 110K names where 29s was spent checking for
     * duplicates. Order is not important here.)
     *
     * @var array<string, true>|null
     */
    public ?array $hostAllowlist = null;
}
