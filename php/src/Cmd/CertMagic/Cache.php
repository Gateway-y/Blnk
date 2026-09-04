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

use Blnk\Internal\Log;

/**
 * Cache is a structure that stores certificates in memory.
 * A Cache indexes certificates by name for quick access
 * during TLS handshakes, and avoids duplicating certificates
 * in memory. Generally, there should only be one per process.
 * However, that is not a strict requirement; but using more
 * than one is a code smell, and may indicate an
 * over-engineered design.
 *
 * An empty cache is INVALID and must not be used. Be sure
 * to call NewCache to get a valid value.
 *
 * Caches are not usually manipulated directly; create a
 * Config value with a pointer to a Cache, and then use
 * the Config to interact with the cache. Caches are
 * agnostic of any particular storage or ACME config,
 * since each certificate may be managed and stored
 * differently.
 *
 * PHP port: Go tends to the certificates from a maintenance goroutine
 * (`maintainAssets`, a ticker every RenewCheckInterval); here the server
 * loop calls {@see maintainAssets()} regularly and one renewal check runs
 * once the interval has elapsed. OCSP staple maintenance is not ported.
 */
final class Cache
{
    /**
     * DefaultRenewCheckInterval is how often to check certificates for expiration (seconds).
     * Scans are very lightweight, so this can be semi-frequent. This default should
     * be smaller than <Minimum Cert Lifetime>*DefaultRenewalWindowRatio/3, which
     * gives certificates plenty of chance to be renewed on time.
     */
    public const DefaultRenewCheckInterval = 600.0; // 10 * time.Minute

    /**
     * DefaultRenewalWindowRatio is how much of a certificate's lifetime becomes the
     * renewal window. The renewal window is the span of time at the end of the
     * certificate's validity period in which it should be renewed. A default value
     * of ~1/3 is pretty safe and recommended for most certificates.
     */
    public const DefaultRenewalWindowRatio = 1.0 / 3.0;

    /** DefaultOCSPCheckInterval is how often to check if OCSP stapling needs updating (seconds). */
    public const DefaultOCSPCheckInterval = 3600.0; // 1 * time.Hour

    /** User configuration of the cache */
    public CacheOptions $options;

    /**
     * The cache is keyed by certificate hash
     *
     * @var array<string, Certificate>
     */
    private array $cache = [];

    /**
     * cacheIndex is a map of SAN to cache key (cert hash)
     *
     * @var array<string, string[]>
     */
    private array $cacheIndex = [];

    private bool $stopped = false;

    private float $nextRenewalCheck;

    private function __construct(CacheOptions $options)
    {
        $this->options = $options;
        $this->nextRenewalCheck = microtime(true) + $options->renewCheckInterval;
    }

    /**
     * NewCache returns a new, valid Cache for efficiently
     * accessing certificates in memory. It also begins
     * maintenance of the certificates in the cache (driven by
     * {@see maintainAssets()} in the port). Call Stop() when
     * you are done with the cache so it can clean up locks and stuff.
     *
     * Most users of this package will not need to call this
     * because a default certificate cache is created for you.
     * Only advanced use cases require creating a new cache.
     *
     * This function throws if opts.getConfigForCert is not
     * set. The reason is that a cache absolutely needs to
     * be able to get a Config with which to manage TLS
     * assets, and it is not safe to assume that the Default
     * config is always the correct one, since you have
     * created the cache yourself.
     *
     * @throws \LogicException
     */
    public static function newCache(CacheOptions $opts): self
    {
        // assume default options if necessary
        if ($opts->ocspCheckInterval <= 0) {
            $opts->ocspCheckInterval = self::DefaultOCSPCheckInterval;
        }
        if ($opts->renewCheckInterval <= 0) {
            $opts->renewCheckInterval = self::DefaultRenewCheckInterval;
        }
        if ($opts->capacity < 0) {
            $opts->capacity = 0;
        }

        // this must be set, because we cannot not
        // safely assume that the Default Config
        // is always the correct one to use
        if ($opts->getConfigForCert === null) {
            throw new \LogicException('cache must be initialized with a GetConfigForCert callback');
        }

        $c = new self($opts);

        // go c.maintainAssets(0)
        Log::get()->info('started background certificate maintenance', ['cache' => spl_object_id($c)]);

        return $c;
    }

    public function setOptions(CacheOptions $opts): void
    {
        $this->options = $opts;
    }

    /**
     * Stop stops the maintenance of certificates in certCache.
     * Once a cache is stopped, it cannot be reused.
     */
    public function stop(): void
    {
        $this->stopped = true;
        Log::get()->info('stopped background certificate maintenance', ['cache' => spl_object_id($this)]);
    }

    /**
     * maintainAssets is one iteration of Go's permanently-blocking maintenance
     * loop: on a regular schedule, checks certificates for expiration and
     * initiates a renewal of certs that are expiring soon. Call it from the
     * server loop; it does nothing until RenewCheckInterval has elapsed.
     */
    public function maintainAssets(): void
    {
        if ($this->stopped) {
            return;
        }
        $now = microtime(true);
        if ($now < $this->nextRenewalCheck) {
            return;
        }
        $this->nextRenewalCheck = $now + $this->options->renewCheckInterval;
        try {
            $this->renewManagedCertificates();
        } catch (\Throwable $err) {
            Log::get()->error('renewing managed certificates', ['error' => $err->getMessage()]);
        }
    }

    /**
     * cacheCertificate adds cert to the in-memory cache unless
     * it already exists in the cache (according to cert.Hash). It
     * updates the name index.
     */
    public function cacheCertificate(Certificate $cert): void
    {
        $this->unsyncedCacheCertificate($cert);
    }

    private function unsyncedCacheCertificate(Certificate $cert): void
    {
        // if this certificate already exists in the cache, this is basically
        // a no-op so we reuse existing cert (prevent duplication), but we do
        // modify the cert to add tags it may be missing (see issue #211)
        if (isset($this->cache[$cert->hash])) {
            $existingCert = $this->cache[$cert->hash];
            $logMsg = 'certificate already cached';

            if ($cert->tags !== []) {
                foreach ($cert->tags as $tag) {
                    if (!$existingCert->hasTag($tag)) {
                        $existingCert->tags[] = $tag;
                    }
                }
                $this->cache[$cert->hash] = $existingCert;
                $logMsg .= '; appended any missing tags to cert';
            }

            Log::get()->debug($logMsg, [
                'subjects' => $cert->names,
                'expiration' => $cert->leaf !== null ? Rfc3339::encode($cert->leaf->expiresAt()) : '',
                'managed' => $cert->managed,
                'issuer_key' => $cert->issuerKey,
                'hash' => $cert->hash,
                'tags' => $cert->tags,
            ]);
            return;
        }

        // if the cache is at capacity, make room for new cert
        $cacheSize = \count($this->cache);
        $atCapacity = $this->options->capacity > 0 && $cacheSize >= $this->options->capacity;

        if ($atCapacity) {
            // evict a random certificate, which ensures a much better
            // distribution than chopping off the "front" of the map
            $keys = array_keys($this->cache);
            $randomCert = $this->cache[$keys[random_int(0, $cacheSize - 1)]];
            Log::get()->debug('cache full; evicting random certificate', [
                'removing_subjects' => $randomCert->names,
                'removing_hash' => $randomCert->hash,
                'inserting_subjects' => $cert->names,
                'inserting_hash' => $cert->hash,
            ]);
            $this->removeCertificate($randomCert);
        }

        // store the certificate
        $this->cache[$cert->hash] = $cert;

        // update the index so we can access it by name
        foreach ($cert->names as $name) {
            $this->cacheIndex[$name][] = $cert->hash;
        }

        Log::get()->debug('added certificate to cache', [
            'subjects' => $cert->names,
            'expiration' => $cert->leaf !== null ? Rfc3339::encode($cert->leaf->expiresAt()) : '',
            'managed' => $cert->managed,
            'issuer_key' => $cert->issuerKey,
            'hash' => $cert->hash,
            'cache_size' => \count($this->cache),
            'cache_capacity' => $this->options->capacity,
        ]);
    }

    /** removeCertificate removes cert from the cache. */
    private function removeCertificate(Certificate $cert): void
    {
        // delete all mentions of this cert from the name index
        foreach ($cert->names as $name) {
            $keyList = $this->cacheIndex[$name] ?? [];
            $keyList = array_values(array_filter($keyList, static fn (string $h): bool => $h !== $cert->hash));
            if ($keyList === []) {
                unset($this->cacheIndex[$name]);
            } else {
                $this->cacheIndex[$name] = $keyList;
            }
        }

        // delete the actual cert from the cache
        unset($this->cache[$cert->hash]);

        Log::get()->debug('removed certificate from cache', [
            'subjects' => $cert->names,
            'expiration' => $cert->leaf !== null ? Rfc3339::encode($cert->leaf->expiresAt()) : '',
            'managed' => $cert->managed,
            'issuer_key' => $cert->issuerKey,
            'hash' => $cert->hash,
            'cache_size' => \count($this->cache),
            'cache_capacity' => $this->options->capacity,
        ]);
    }

    /**
     * replaceCertificate atomically replaces oldCert with newCert in
     * the cache.
     */
    public function replaceCertificate(Certificate $oldCert, Certificate $newCert): void
    {
        $this->removeCertificate($oldCert);
        $this->unsyncedCacheCertificate($newCert);
        Log::get()->info('replaced certificate in cache', [
            'subjects' => $newCert->names,
            'new_expiration' => $newCert->leaf !== null ? Rfc3339::encode($newCert->leaf->expiresAt()) : '',
        ]);
    }

    /**
     * getAllMatchingCerts returns all certificates with exactly this subject
     * (wildcards are NOT expanded).
     *
     * @return Certificate[]
     */
    public function getAllMatchingCerts(string $subject): array
    {
        $certs = [];
        foreach ($this->cacheIndex[$subject] ?? [] as $hash) {
            if (isset($this->cache[$hash])) {
                $certs[] = $this->cache[$hash];
            }
        }
        return $certs;
    }

    /** @return Certificate[] */
    public function getAllCerts(): array
    {
        return array_values($this->cache);
    }

    /** getCertificateByHash returns the cached certificate with the hash, if any. */
    public function getCertificateByHash(string $hash): ?Certificate
    {
        return $this->cache[$hash] ?? null;
    }

    /**
     * getConfig returns the configuration used to manage the certificate.
     *
     * @throws \RuntimeException
     */
    public function getConfig(Certificate $cert): Config
    {
        /** @var callable(Certificate): Config $getCert */
        $getCert = $this->options->getConfigForCert;

        $cfg = $getCert($cert);
        if (!$cfg instanceof Config) {
            throw new \RuntimeException(sprintf('config returned for certificate [%s] is not a Config', implode(' ', $cert->names)));
        }
        if ($cfg->certCache === null) {
            throw new \RuntimeException(sprintf('config returned for certificate [%s] has nil cache; expected %d (this one)', implode(' ', $cert->names), spl_object_id($this)));
        }
        if ($cfg->certCache !== $this) {
            throw new \RuntimeException(sprintf('config returned for certificate [%s] is not nil and points to different cache; got %d, expected %d (this one)', implode(' ', $cert->names), spl_object_id($cfg->certCache), spl_object_id($this)));
        }
        return $cfg;
    }

    /**
     * AllMatchingCertificates returns a list of all certificates that could
     * be used to serve the given SNI name, including exact SAN matches and
     * wildcard matches.
     *
     * @return Certificate[]
     */
    public function allMatchingCertificates(string $name): array
    {
        // get exact matches first
        $certs = $this->getAllMatchingCerts($name);

        // then look for wildcard matches by replacing each
        // label of the domain name with wildcards
        $labels = explode('.', $name);
        foreach ($labels as $i => $label) {
            $labels[$i] = '*';
            $candidate = implode('.', $labels);
            foreach ($this->getAllMatchingCerts($candidate) as $c) {
                $certs[] = $c;
            }
        }

        return $certs;
    }

    /**
     * RemoveManaged removes managed certificates for the given subjects from the cache.
     * This effectively stops maintenance of those certificates. If an IssuerKey is
     * specified alongside the subject, only certificates for that subject from the
     * specified issuer will be removed.
     *
     * @param array<int, array{subject: string, issuerKey: string}> $subjects
     */
    public function removeManaged(array $subjects): void
    {
        $deleteQueue = [];
        foreach ($subjects as $subj) {
            $certs = $this->getAllMatchingCerts($subj['subject']); // does NOT expand wildcards; exact matches only
            foreach ($certs as $cert) {
                if (!$cert->managed) {
                    continue;
                }
                if (($subj['issuerKey'] ?? '') === '' || $cert->issuerKey === $subj['issuerKey']) {
                    $deleteQueue[] = $cert->hash;
                }
            }
        }
        $this->remove($deleteQueue);
    }

    /**
     * Remove removes certificates with the given hashes from the cache.
     * This is effectively used to unload manually-loaded certificates.
     *
     * @param string[] $hashes
     */
    public function remove(array $hashes): void
    {
        foreach ($hashes as $h) {
            if (isset($this->cache[$h])) {
                $this->removeCertificate($this->cache[$h]);
            }
        }
    }

    /**
     * RenewManagedCertificates renews managed certificates,
     * including ones loaded on-demand. Note that this is done
     * automatically on a regular basis; normally you will not
     * need to call this. This method assumes non-interactive
     * mode (i.e. operating in the background).
     *
     * (certmagic maintain.go.)
     */
    public function renewManagedCertificates(): void
    {
        // configs will hold a map of certificate hash to the config
        // to use when managing that certificate
        /** @var array<string, Config> $configs */
        $configs = [];

        // our first iteration through the certificate cache does NOT
        // perform any operations--only queues them
        /** @var Certificate[] $renewQueue */
        $renewQueue = [];
        /** @var Certificate[] $reloadQueue */
        $reloadQueue = [];
        /** @var Certificate[] $deleteQueue */
        $deleteQueue = [];
        /** @var Certificate[] $ariQueue */
        $ariQueue = [];

        foreach ($this->cache as $certKey => $cert) {
            if (!$cert->managed) {
                continue;
            }

            // the list of names on this cert should never be empty... programmer error?
            if ($cert->names === []) {
                Log::get()->warning('certificate has no names; removing from cache', ['cert_key' => $certKey]);
                $deleteQueue[] = $cert;
                continue;
            }

            // get the config associated with this certificate
            try {
                $cfg = $this->getConfig($cert);
            } catch (\Throwable $err) {
                Log::get()->error('unable to get configuration to manage certificate; unable to renew', [
                    'identifiers' => $cert->names,
                    'error' => $err->getMessage(),
                ]);
                continue;
            }

            // ACME-specific: see if if ACME Renewal Info (ARI) window needs refreshing
            if (!$cfg->disableARI && $cert->ari->needsRefresh()) {
                $configs[$cert->hash] = $cfg;
                $ariQueue[] = $cert;
            }

            // if time is up or expires soon, we need to try to renew it
            if ($cert->needsRenewal($cfg)) {
                $configs[$cert->hash] = $cfg;

                // see if the certificate in storage has already been renewed, possibly by another
                // instance that didn't coordinate with this one; if so, just load it (this
                // might happen if another instance already renewed it - kinda sloppy but checking disk
                // first is a simple way to possibly drastically reduce rate limit problems)
                try {
                    $storedCertNeedsRenew = $cfg->managedCertInStorageNeedsRenewal($cert);
                    if (!$storedCertNeedsRenew) {
                        // if the certificate does NOT need renewal and there was no error, then we
                        // are good to just reload the certificate from storage instead of repeating
                        // a likely-unnecessary renewal procedure
                        $reloadQueue[] = $cert;
                        continue;
                    }
                } catch (\Throwable $err) {
                    // hmm, weird, but not a big deal, maybe it was deleted or something
                    Log::get()->warning('error while checking if stored certificate is also expiring soon', [
                        'identifiers' => $cert->names,
                        'error' => $err->getMessage(),
                    ]);
                }

                // the certificate in storage has not been renewed yet, so we will do it
                self::insert($renewQueue, $cert);
            }
        }

        // Update ARI, and then for any certs where the ARI window changed,
        // be sure to queue them for renewal if necessary
        foreach ($ariQueue as $cert) {
            $cfg = $configs[$cert->hash];
            [$cert, $changed, $err] = $cfg->updateARI($cert);
            if ($err !== null) {
                Log::get()->error('updating ARI', ['error' => $err->getMessage()]);
            }
            if ($changed && $cert->needsRenewal($cfg)) {
                // it's theoretically possible that another instance already got the memo
                // on the changed ARI and even renewed the cert already, and thus doing it
                // here is wasteful, but I have never heard of this happening in reality,
                // so to save some cycles for now I think we'll just queue it for renewal
                // (notice how we use 'insert' to avoid duplicates, in case it was already
                // scheduled for renewal anyway)
                self::insert($renewQueue, $cert);
            }
        }

        // Reload certificates that merely need to be updated in memory
        foreach ($reloadQueue as $oldCert) {
            $timeLeft = $oldCert->leaf !== null ? Rfc3339::seconds($oldCert->leaf->expiresAt()) - microtime(true) : 0.0;
            Log::get()->info('certificate expires soon, but is already renewed in storage; reloading stored certificate', [
                'identifiers' => $oldCert->names,
                'remaining' => Rfc3339::durationString($timeLeft),
            ]);

            $cfg = $configs[$oldCert->hash];

            try {
                $cfg->reloadManagedCertificate($oldCert);
            } catch (\Throwable $err) {
                Log::get()->error('loading renewed certificate', [
                    'identifiers' => $oldCert->names,
                    'error' => $err->getMessage(),
                ]);
                continue;
            }
        }

        // Renewal queue
        foreach ($renewQueue as $oldCert) {
            $cfg = $configs[$oldCert->hash];
            try {
                $this->queueRenewalTask($oldCert, $cfg);
            } catch (\Throwable $err) {
                Log::get()->error('queueing renewal task', [
                    'identifiers' => $oldCert->names,
                    'error' => $err->getMessage(),
                ]);
                continue;
            }
        }

        // Deletion queue
        foreach ($deleteQueue as $cert) {
            $this->removeCertificate($cert);
        }
    }

    /**
     * queueRenewalTask renews the certificate (Go: submits the job to the
     * job manager; the port runs it right away — one attempt, the next
     * maintenance check retries).
     */
    private function queueRenewalTask(Certificate $oldCert, Config $cfg): void
    {
        $timeLeft = $oldCert->leaf !== null ? Rfc3339::seconds($oldCert->leaf->expiresAt()) - microtime(true) : 0.0;
        Log::get()->info('certificate expires soon; queuing for renewal', [
            'identifiers' => $oldCert->names,
            'remaining' => Rfc3339::durationString($timeLeft),
        ]);

        // Get the name which we should use to renew this certificate;
        // we only support managing certificates with one name per cert,
        // so this should be easy.
        $renewName = $oldCert->names[0];

        Log::get()->info('attempting certificate renewal', [
            'identifiers' => $oldCert->names,
            'remaining' => Rfc3339::durationString($timeLeft),
        ]);

        // perform renewal
        try {
            $cfg->renewCertAsync($renewName, false);
        } catch (\Throwable $err) {
            Log::get()->error('job failed', ['error' => sprintf('[%s] %s', implode(' ', $oldCert->names), $err->getMessage())]);
            return;
        }

        // successful renewal, so update in-memory cache by loading
        // renewed certificate so it will be used with handshakes
        try {
            $cfg->reloadManagedCertificate($oldCert);
        } catch (\Throwable $err) {
            Log::get()->error('job failed', ['error' => sprintf('[%s] %s', implode(' ', $oldCert->names), $err->getMessage())]);
        }
    }

    /**
     * insert appends cert to the list if it is not already in the list.
     * Efficiency: O(n)
     *
     * @param Certificate[] $certs
     */
    private static function insert(array &$certs, Certificate $cert): void
    {
        foreach ($certs as $c) {
            if ($c->hash === $cert->hash) {
                return;
            }
        }
        $certs[] = $cert;
    }
}
