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

use Blnk\Cmd\CertMagic\Acme\AcmeCertificate;
use Blnk\Cmd\CertMagic\Acme\Challenge;
use Blnk\Cmd\CertMagic\Acme\Problem;
use Blnk\Cmd\CertMagic\Acme\RenewalInfo;
use Blnk\Cmd\CertMagic\Acmez\TlsAlpn01;
use Blnk\Cmd\CertMagic\Solvers\ActiveChallenge;
use Blnk\Cmd\CertMagic\Solvers\DistributedSolver;
use Blnk\Cmd\CertMagic\Solvers\NoopSolver;
use Blnk\Cmd\CertMagic\Solvers\SolverRegistry;
use Blnk\Internal\Log;
use Psr\Log\LoggerInterface;

/**
 * Config configures a certificate manager instance.
 * An empty Config is not valid: use New() to obtain
 * a valid Config.
 *
 * (certmagic config.go, with the Config methods of handshake.go,
 * certificates.go, crypto.go and maintain.go.)
 *
 * PHP port notes: OCSP stapling is not ported (PHP's TLS streams cannot
 * staple responses; the `OCSP` config is absent and the revocation-driven
 * renewals of Go are unreachable), and background goroutines run inline —
 * the "async" operations are the non-interactive variants performed
 * synchronously (see {@see Async}).
 */
final class Config
{
    /**
     * certIssueLockOp is the name of the operation used
     * when naming a lock to make it mutually exclusive
     * with other certificate issuance operations for a
     * certain name.
     */
    public const certIssueLockOp = 'issue_cert';

    /** zerosslIssuerKey: ZeroSSL's API requires a CommonName in the CSR (see obtainCert). */
    public const zerosslIssuerKey = 'zerossl';

    /**
     * How much of a certificate's lifetime becomes the
     * renewal window, which is the span of time at the
     * end of the certificate's validity period in which
     * it should be renewed; for most certificates, the
     * global default is good, but for extremely short-
     * lived certs, you may want to raise this to ~0.5.
     * Ratio is remaining:total lifetime.
     */
    public float $renewalWindowRatio = 0;

    /**
     * An optional event callback clients can set
     * to subscribe to certain things happening
     * internally by this config; invocations are
     * synchronous, so make them return quickly!
     *
     * The callback should only throw to advise
     * the emitter to abort or cancel an upcoming
     * event. Some events, especially those that have
     * already happened, cannot be aborted. For example,
     * cert_obtaining can be canceled, but
     * cert_obtained cannot. Emitters may choose to
     * ignore thrown errors.
     *
     * @var callable(string, array<string, mixed>): void|null
     */
    public $onEvent = null;

    /**
     * DefaultServerName specifies a server name
     * to use when choosing a certificate if the
     * ClientHello's ServerName field is empty.
     */
    public string $defaultServerName = '';

    /**
     * FallbackServerName specifies a server name
     * to use when choosing a certificate if the
     * ClientHello's ServerName field doesn't match
     * any available certificate.
     * EXPERIMENTAL: Subject to change or removal.
     */
    public string $fallbackServerName = '';

    /**
     * The state needed to operate on-demand TLS;
     * if non-null, on-demand TLS is enabled and
     * certificate operations are deferred to
     * TLS handshakes (or as-needed).
     * TODO: Can we call this feature "Reactive/Lazy/Passive TLS" instead?
     */
    public ?OnDemandConfig $onDemand = null;

    /** Adds the must staple TLS extension to the CSR. */
    public bool $mustStaple = false;

    /**
     * Sources for getting new, managed certificates;
     * the default Issuer is ACMEIssuer. If multiple
     * issuers are specified, they will be tried in
     * turn until one succeeds.
     *
     * @var Issuer[]|null
     */
    public ?array $issuers = null;

    /**
     * How to select which issuer to use.
     * Default: UseFirstIssuer (subject to change).
     */
    public string $issuerPolicy = '';

    /**
     * If true, private keys already existing in storage
     * will be reused. Otherwise, a new key will be
     * created for every new certificate to mitigate
     * pinning and reduce the scope of key compromise.
     * Default: false (do not reuse keys).
     */
    public bool $reusePrivateKeys = false;

    /**
     * The source of new private keys for certificates;
     * the default KeySource is StandardKeyGenerator
     * (a function returning a new private key).
     *
     * @var callable(): \OpenSSLAsymmetricKey|null
     */
    public $keySource = null;

    /**
     * CertSelection chooses one of the certificates
     * with which the ClientHello will be completed;
     * if not set, DefaultCertificateSelector will
     * be used.
     */
    public ?CertificateSelector $certSelection = null;

    /**
     * The storage to access when storing or loading
     * TLS assets. Default is the local file system.
     */
    public ?Storage $storage = null;

    /**
     * CertMagic will verify the storage configuration
     * is acceptable before obtaining a certificate
     * to avoid information loss after an expensive
     * operation. If you are absolutely 100% sure your
     * storage is properly configured and has sufficient
     * space, you can disable this check to reduce I/O
     * if that is expensive for you.
     * EXPERIMENTAL: Subject to change or removal.
     */
    public bool $disableStorageCheck = false;

    /**
     * SubjectTransformer is a hook that can transform the
     * subject (SAN) of a certificate being loaded or issued.
     * For example, a common use case is to replace the
     * left-most label with an asterisk (*) to become a
     * wildcard certificate.
     * EXPERIMENTAL: Subject to change or removal.
     *
     * @var callable(string): string|null
     */
    public $subjectTransformer = null;

    /**
     * Disables both ARI fetching and the use of ARI for renewal decisions.
     * TEMPORARY: Will likely be removed in the future.
     */
    public bool $disableARI = false;

    /** required pointer to the in-memory cert cache (Go: unexported `certCache`). */
    public ?Cache $certCache = null;

    /**
     * obtainCertWaitChans is used to coordinate obtaining certs for each hostname.
     * (Single-threaded port: a re-entrancy guard.)
     *
     * @var array<string, true>
     */
    private static array $obtainCertWaitChans = [];

    /**
     * certLoadWaitChans: TODO: this lockset should probably be per-cache
     *
     * @var array<string, true>
     */
    private static array $certLoadWaitChans = [];

    /**
     * defaultKeyGenerator returns the default KeySource: StandardKeyGenerator{KeyType: P256}.
     *
     * @return callable(): \OpenSSLAsymmetricKey
     */
    public static function defaultKeyGenerator(): callable
    {
        return static function (): \OpenSSLAsymmetricKey {
            return Crypto::generateKey(Crypto::DefaultKeyType);
        };
    }

    /**
     * ManageSync causes the certificates for domainNames to be managed
     * according to cfg. If cfg.OnDemand is not nil, then this simply
     * allowlists the domain names and defers the certificate operations
     * to when they are needed. Otherwise, the certificates for each
     * name are loaded from storage or obtained from the CA if not already
     * in the cache associated with the Config. If loaded from storage,
     * they are renewed if they are expiring or expired. It then caches
     * the certificate in memory and is prepared to serve them up during
     * TLS handshakes. To change how an already-loaded certificate is
     * managed, update the cache options relating to getting a config for
     * a cert.
     *
     * Note that name allowlisting for on-demand management only takes
     * effect if cfg.OnDemand.DecisionFunc is not set (is nil); it will
     * not overwrite an existing DecisionFunc, nor will it overwrite
     * its decision; i.e. the implicit allowlist is only used if no
     * DecisionFunc is set.
     *
     * This method is synchronous, meaning that certificates for all
     * domainNames must be successfully obtained (or renewed) before
     * it returns. It returns immediately on the first error for any
     * of the given domainNames. This behavior is recommended for
     * interactive use (i.e. when an administrator is present) so
     * that errors can be reported and fixed immediately.
     *
     * @param string[] $domainNames
     * @throws \RuntimeException
     */
    public function manageSync(array $domainNames): void
    {
        $this->manageAll($domainNames, false);
    }

    /**
     * ManageAsync is the same as ManageSync, except that ACME
     * operations are performed asynchronously (in the background).
     * This method returns before certificates are ready. It is
     * crucial that the administrator monitors the logs and is
     * notified of any errors so that corrective action can be
     * taken as soon as possible. Any errors returned from this
     * method occurred before ACME transactions started.
     *
     * As long as logs are monitored, this method is typically
     * recommended for non-interactive environments.
     *
     * If there are failures loading, obtaining, or renewing a
     * certificate, it will be retried with exponential backoff
     * for up to about 30 days, with a maximum interval of about
     * 24 hours. Cancelling ctx will cancel retries and shut down
     * any goroutines spawned by ManageAsync.
     *
     * (PHP port: the "background" jobs run inline, see {@see Async}.)
     *
     * @param string[] $domainNames
     * @throws \RuntimeException
     */
    public function manageAsync(array $domainNames): void
    {
        $this->manageAll($domainNames, true);
    }

    /**
     * ClientCredentials returns a list of TLS client certificate chains for the given identifiers.
     * The return value can be used in a tls.Config to enable client authentication using managed certificates.
     * Any certificates that need to be obtained or renewed for these identifiers will be managed accordingly.
     *
     * @param string[] $identifiers
     * @return Certificate[]
     * @throws \RuntimeException
     */
    public function clientCredentials(array $identifiers): array
    {
        $this->manageAll($identifiers, false);
        $chains = [];
        foreach ($identifiers as $id) {
            $certRes = $this->loadCertResourceAnyIssuer($id);
            $chains[] = Certificate::makeCertificate($certRes->certificatePEM, $certRes->privateKeyPEM);
        }
        return $chains;
    }

    /**
     * @param string[] $domainNames
     * @throws \RuntimeException
     */
    private function manageAll(array $domainNames, bool $async): void
    {
        if ($this->onDemand !== null && $this->onDemand->hostAllowlist === null) {
            $this->onDemand->hostAllowlist = [];
        }

        foreach ($domainNames as $domainName) {
            $domainName = CertMagic::normalizedName($domainName);

            // if on-demand is configured, defer obtain and renew operations
            if ($this->onDemand !== null) {
                $this->onDemand->hostAllowlist[$domainName] = true;
                continue;
            }

            // TODO: consider doing this in a goroutine if async, to utilize multiple cores while loading certs
            // otherwise, begin management immediately
            $this->manageOne($domainName, $async);
        }
    }

    /**
     * @throws \RuntimeException
     */
    private function manageOne(string $domainName, bool $async): void
    {
        // if certificate is already being managed, nothing to do; maintenance will continue
        $certs = $this->certCache()->getAllMatchingCerts($domainName);
        foreach ($certs as $cert) {
            if ($cert->managed) {
                return;
            }
        }

        // first try loading existing certificate from storage
        try {
            $cert = $this->cacheManagedCertificate($domainName);
        } catch (\Throwable $err) {
            if (!self::isNotExist($err)) {
                throw new \RuntimeException(sprintf('%s: caching certificate: %s', $domainName, $err->getMessage()), 0, $err);
            }
            // if we don't have one in storage, obtain one
            $obtain = function () use ($domainName, $async): void {
                try {
                    if ($async) {
                        $this->obtainCertAsync($domainName);
                    } else {
                        $this->obtainCertSync($domainName);
                    }
                } catch (\Throwable $err) {
                    throw new \RuntimeException(sprintf('%s: obtaining certificate: %s', $domainName, $err->getMessage()), 0, $err);
                }
                try {
                    $this->cacheManagedCertificate($domainName);
                } catch (\Throwable $err) {
                    throw new \RuntimeException(sprintf('%s: caching certificate after obtaining it: %s', $domainName, $err->getMessage()), 0, $err);
                }
            };
            if ($async) {
                // Leave the job name empty so as to allow duplicate 'obtain'
                // jobs; this is because Caddy calls ManageAsync() before the
                // previous config is stopped (and before its context is
                // canceled), which means that if an obtain job is still
                // running for the same domain, Submit() would not queue the
                // new one because it is still running, even though it is
                // (probably) about to be canceled (it might not if the new
                // config fails to finish loading, however). In any case, we
                // presume it is safe to enqueue a duplicate obtain job because
                // either the old one (or sometimes the new one) is about to be
                // canceled. This seems like reasonable logic for any consumer
                // of this lib. See https://github.com/caddyserver/caddy/issues/3202
                Async::submit('', $obtain);
                return;
            }
            $obtain();
            return;
        }

        // for an existing certificate, make sure it is renewed; or if it is revoked,
        // force a renewal even if it's not expiring
        $renew = function () use ($domainName, $async, $cert): void {
            // first, ensure status is not revoked (it was just refreshed in CacheManagedCertificate above)
            // (PHP port: no OCSP status is available, so this branch never applies)

            // ensure ARI is updated before we check whether the cert needs renewing
            // (we ignore the second return value because we already check if needs renewing anyway)
            if (!$this->disableARI && $cert->ari->needsRefresh()) {
                [$cert, , $err] = $this->updateARI($cert);
                if ($err !== null) {
                    Log::get()->error('updating ARI upon managing', ['error' => $err->getMessage()]);
                }
            }

            // otherwise, simply renew the certificate if needed
            if ($cert->needsRenewal($this)) {
                try {
                    if ($async) {
                        $this->renewCertAsync($domainName, false);
                    } else {
                        $this->renewCertSync($domainName, false);
                    }
                } catch (\Throwable $err) {
                    throw new \RuntimeException(sprintf('%s: renewing certificate: %s', $domainName, $err->getMessage()), 0, $err);
                }
                // successful renewal, so update in-memory cache
                try {
                    $this->reloadManagedCertificate($cert);
                } catch (\Throwable $err) {
                    throw new \RuntimeException(sprintf('%s: reloading renewed certificate into memory: %s', $domainName, $err->getMessage()), 0, $err);
                }
            }
        };

        if ($async) {
            Async::submit('renew_' . $domainName, $renew);
            return;
        }
        $renew();
    }

    /**
     * ObtainCertSync generates a new private key and obtains a certificate for
     * name using cfg in the foreground; i.e. interactively and without retries.
     * It stows the renewed certificate and its assets in storage if successful.
     * It DOES NOT load the certificate into the in-memory cache. This method
     * is a no-op if storage already has a certificate for name.
     *
     * @throws \RuntimeException
     */
    public function obtainCertSync(string $name): void
    {
        $this->obtainCert($name, true);
    }

    /**
     * ObtainCertAsync is the same as ObtainCertSync(), except it runs in the
     * background; i.e. non-interactively, and with retries if it fails.
     *
     * @throws \RuntimeException
     */
    public function obtainCertAsync(string $name): void
    {
        $this->obtainCert($name, false);
    }

    /**
     * @throws \RuntimeException
     */
    private function obtainCert(string $name, bool $interactive): void
    {
        if ($this->issuers === null || $this->issuers === []) {
            throw new \RuntimeException('no issuers configured; impossible to obtain or check for existing certificate in storage');
        }

        $log = Log::get();

        $name = $this->transformSubject($name, true);

        // if storage has all resources for this certificate, obtain is a no-op
        if ($this->storageHasCertResourcesAnyIssuer($name)) {
            return;
        }

        // ensure storage is writeable and readable
        // TODO: this is not necessary every time; should only perform check once every so often for each storage, which may require some global state...
        try {
            $this->checkStorage();
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('failed storage check: %s - storage is probably misconfigured', $err->getMessage()), 0, $err);
        }

        $log->info('acquiring lock', ['identifier' => $name, 'op' => 'obtain']);

        // ensure idempotency of the obtain operation for this name
        $lockKey = $this->lockKey(self::certIssueLockOp, $name);
        try {
            Locks::acquireLock($this->storage(), $lockKey);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf("unable to acquire lock '%s': %s", $lockKey, $err->getMessage()), 0, $err);
        }
        try {
            $log->info('lock acquired', ['identifier' => $name, 'op' => 'obtain']);

            $f = function (IssueContext $ctx) use ($name, $interactive, $log): void {
                // check if obtain is still needed -- might have been obtained during lock
                if ($this->storageHasCertResourcesAnyIssuer($name)) {
                    $log->info('certificate already exists in storage', ['identifier' => $name]);
                    return;
                }

                $log->info('obtaining certificate', ['identifier' => $name]);

                try {
                    $this->emit('cert_obtaining', ['identifier' => $name]);
                } catch (\Throwable $err) {
                    throw new \RuntimeException(sprintf('obtaining certificate aborted by event handler: %s', $err->getMessage()), 0, $err);
                }

                // If storage has a private key already, use it; otherwise we'll generate our own.
                // Also create the slice of issuers we will try using according to any issuer
                // selection policy (it must be a copy of the slice so we don't mutate original).
                $privKey = null;
                $privKeyPEM = '';
                if ($this->reusePrivateKeys) {
                    [$privKey, $privKeyPEM, $issuers] = $this->reusePrivateKey($name);
                } else {
                    $issuers = array_values($this->issuers ?? []);
                }
                if ($this->issuerPolicy === CertMagic::UseFirstRandomIssuer) {
                    shuffle($issuers);
                }
                if ($privKey === null) {
                    $privKey = ($this->keySource())();
                    $privKeyPEM = Crypto::pemEncodePrivateKey($privKey);
                }

                $csr = $this->generateCSR($privKey, [$name], false);

                // try to obtain from each issuer until we succeed
                $issuedCert = null;
                $issuerUsed = null;
                $issuerKeys = [];
                $err = null;
                foreach ($issuers as $i => $issuer) {
                    $issuerKeys[] = $issuer->issuerKey();

                    $log->debug(sprintf('trying issuer %d/%d', $i + 1, \count($this->issuers ?? [])), ['issuer' => $issuer->issuerKey()]);

                    if ($issuer instanceof PreChecker) {
                        try {
                            $issuer->preCheck([$name], $interactive);
                        } catch (\Throwable $e) {
                            $err = $e;
                            continue;
                        }
                    }

                    // TODO: ZeroSSL's API currently requires CommonName to be set, and requires it be
                    // distinct from SANs. If this was a cert it would violate the BRs, but their certs
                    // are compliant, so their CSR requirements just needlessly add friction, complexity,
                    // and inefficiency for clients. CommonName has been deprecated for 25+ years.
                    $useCSR = $csr;
                    if ($issuer->issuerKey() === self::zerosslIssuerKey) {
                        $useCSR = $this->generateCSR($privKey, [$name], true);
                    }

                    try {
                        $issuedCert = $issuer->issue($useCSR, $ctx);
                        $issuerUsed = $issuer;
                        $err = null;
                        break;
                    } catch (\Throwable $e) {
                        $err = $e;
                    }

                    // err is usually wrapped, which is nice for simply printing it, but
                    // with our structured error logs we only need the problem string
                    $errToLog = $err->getMessage();
                    $problem = Problem::from($err);
                    if ($problem !== null) {
                        $errToLog = $problem->errorString();
                    }
                    $log->error('could not get certificate from issuer', [
                        'identifier' => $name,
                        'issuer' => $issuer->issuerKey(),
                        'error' => $errToLog,
                    ]);
                }
                if ($err !== null || $issuedCert === null || $issuerUsed === null) {
                    $this->emitIgnoringErrors('cert_failed', [
                        'renewal' => false,
                        'identifier' => $name,
                        'issuers' => $issuerKeys,
                        'error' => $err !== null ? $err->getMessage() : 'no issuer produced a certificate',
                    ]);

                    // only the error from the last issuer will be returned, but we logged the others
                    throw new \RuntimeException(sprintf('[%s] Obtain: %s', $name, $err !== null ? $err->getMessage() : 'no issuer produced a certificate'), 0, $err);
                }
                $issuerKey = $issuerUsed->issuerKey();

                // success - immediately save the certificate resource
                $metaJSON = null;
                try {
                    $metaJSON = self::marshalMetadata($issuedCert->metadata);
                } catch (\Throwable $e) {
                    $log->error('unable to encode certificate metadata', ['error' => $e->getMessage()]);
                }
                $certRes = new CertificateResource();
                $certRes->sans = Crypto::namesFromCSR($csr);
                $certRes->certificatePEM = $issuedCert->certificate;
                $certRes->privateKeyPEM = $privKeyPEM;
                $certRes->issuerData = $metaJSON;
                $certRes->issuerKey = $issuerUsed->issuerKey();
                try {
                    $this->saveCertResource($issuerUsed, $certRes);
                } catch (\Throwable $e) {
                    throw new \RuntimeException(sprintf('[%s] Obtain: saving assets: %s', $name, $e->getMessage()), 0, $e);
                }

                $log->info('certificate obtained successfully', [
                    'identifier' => $name,
                    'issuer' => $issuerUsed->issuerKey(),
                ]);

                $certKey = $certRes->namesKey();

                $this->emitIgnoringErrors('cert_obtained', [
                    'renewal' => false,
                    'identifier' => $name,
                    'issuer' => $issuerUsed->issuerKey(),
                    'storage_path' => StorageKeys::certsSitePrefix($issuerKey, $certKey),
                    'private_key_path' => StorageKeys::sitePrivateKey($issuerKey, $certKey),
                    'certificate_path' => StorageKeys::siteCert($issuerKey, $certKey),
                    'metadata_path' => StorageKeys::siteMeta($issuerKey, $certKey),
                    'csr_pem' => $csr->pem,
                ]);
            };

            if ($interactive) {
                $f(new IssueContext(0));
            } else {
                Async::doWithRetry($f);
            }
        } finally {
            $log->info('releasing lock', ['identifier' => $name, 'op' => 'obtain']);
            try {
                Locks::releaseLock($this->storage(), $lockKey);
            } catch (\Throwable $err) {
                $log->error('unable to unlock', [
                    'identifier' => $name,
                    'lock_key' => $lockKey,
                    'error' => $err->getMessage(),
                ]);
            }
        }
    }

    /**
     * reusePrivateKey looks for a private key for domain in storage in the configured issuers
     * paths. For the first private key it finds, it returns that key both decoded and PEM-encoded,
     * as well as the reordered list of issuers to use instead of cfg.Issuers (because if a key
     * is found, that issuer should be tried first, so it is moved to the front in a copy of
     * cfg.Issuers).
     *
     * @return array{0: \OpenSSLAsymmetricKey|null, 1: string, 2: Issuer[]}
     * @throws \RuntimeException
     */
    private function reusePrivateKey(string $domain): array
    {
        // make a copy of cfg.Issuers so that if we have to reorder elements, we don't
        // inadvertently mutate the configured issuers (see append calls below)
        $issuers = array_values($this->issuers ?? []);
        $privKey = null;
        $privKeyPEM = '';

        foreach ($issuers as $i => $issuer) {
            // see if this issuer location in storage has a private key for the domain
            $privateKeyStorageKey = StorageKeys::sitePrivateKey($issuer->issuerKey(), $domain);
            try {
                $privKeyPEM = $this->storage()->load($privateKeyStorageKey);
            } catch (ErrNotExist) {
                $privKeyPEM = ''; // obviously, it's OK to not have a private key; so don't prevent obtaining a cert
                continue;
            } catch (\Throwable $err) {
                throw new \RuntimeException(sprintf('loading existing private key for reuse with issuer %s: %s', $issuer->issuerKey(), $err->getMessage()), 0, $err);
            }

            // we loaded a private key; try decoding it so we can use it
            $privKey = Crypto::pemDecodePrivateKey($privKeyPEM);

            // since the private key was found in storage for this issuer, move it
            // to the front of the list so we prefer this issuer first
            array_splice($issuers, $i, 1);
            array_unshift($issuers, $issuer);
            break;
        }

        return [$privKey, $privKeyPEM, $issuers];
    }

    /**
     * storageHasCertResourcesAnyIssuer returns true if storage has all the
     * certificate resources in storage from any configured issuer. It checks
     * all configured issuers in order.
     */
    public function storageHasCertResourcesAnyIssuer(string $name): bool
    {
        foreach ($this->issuers ?? [] as $iss) {
            if ($this->storageHasCertResources($iss, $name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * RenewCertSync renews the certificate for name using cfg in the foreground;
     * i.e. interactively and without retries. It stows the renewed certificate
     * and its assets in storage if successful. It DOES NOT update the in-memory
     * cache with the new certificate. The certificate will not be renewed if it
     * is not close to expiring unless force is true.
     *
     * @throws \RuntimeException
     */
    public function renewCertSync(string $name, bool $force): void
    {
        $this->renewCert($name, $force, true);
    }

    /**
     * RenewCertAsync is the same as RenewCertSync(), except it runs in the
     * background; i.e. non-interactively, and with retries if it fails.
     *
     * @throws \RuntimeException
     */
    public function renewCertAsync(string $name, bool $force): void
    {
        $this->renewCert($name, $force, false);
    }

    /**
     * @throws \RuntimeException
     */
    private function renewCert(string $name, bool $force, bool $interactive): void
    {
        if ($this->issuers === null || $this->issuers === []) {
            throw new \RuntimeException('no issuers configured; impossible to renew or check existing certificate in storage');
        }

        $log = Log::get();

        $name = $this->transformSubject($name, true);

        // ensure storage is writeable and readable
        // TODO: this is not necessary every time; should only perform check once every so often for each storage, which may require some global state...
        try {
            $this->checkStorage();
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('failed storage check: %s - storage is probably misconfigured', $err->getMessage()), 0, $err);
        }

        $log->info('acquiring lock', ['identifier' => $name, 'op' => 'renew']);

        // ensure idempotency of the renew operation for this name
        $lockKey = $this->lockKey(self::certIssueLockOp, $name);
        try {
            Locks::acquireLock($this->storage(), $lockKey);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf("unable to acquire lock '%s': %s", $lockKey, $err->getMessage()), 0, $err);
        }
        try {
            $log->info('lock acquired', ['identifier' => $name, 'op' => 'renew']);

            $f = function (IssueContext $ctx) use ($name, $force, $interactive, $log): void {
                // prepare for renewal (load PEM cert, key, and meta)
                $certRes = $this->loadCertResourceAnyIssuer($name);

                // check if renew is still needed - might have been renewed while waiting for lock
                [$timeLeft, $leaf, $needsRenew] = $this->managedCertNeedsRenewal($certRes, false);
                if (!$needsRenew) {
                    if ($force) {
                        $log->info('certificate does not need to be renewed, but renewal is being forced', [
                            'identifier' => $name,
                            'remaining' => Rfc3339::durationString($timeLeft),
                        ]);
                    } else {
                        $log->info('certificate appears to have been renewed already', [
                            'identifier' => $name,
                            'remaining' => Rfc3339::durationString($timeLeft),
                        ]);
                        return;
                    }
                }

                $log->info('renewing certificate', [
                    'identifier' => $name,
                    'remaining' => Rfc3339::durationString($timeLeft),
                ]);

                try {
                    $this->emit('cert_obtaining', [
                        'renewal' => true,
                        'identifier' => $name,
                        'forced' => $force,
                        'remaining' => $timeLeft,
                        'issuer' => $certRes->issuerKey, // previous/current issuer
                    ]);
                } catch (\Throwable $err) {
                    throw new \RuntimeException(sprintf('renewing certificate aborted by event handler: %s', $err->getMessage()), 0, $err);
                }

                // reuse or generate new private key for CSR
                if ($this->reusePrivateKeys) {
                    $privateKey = Crypto::pemDecodePrivateKey($certRes->privateKeyPEM);
                } else {
                    $privateKey = ($this->keySource())();
                }

                // if we generated a new key, make sure to replace its PEM encoding too!
                if (!$this->reusePrivateKeys) {
                    $certRes->privateKeyPEM = Crypto::pemEncodePrivateKey($privateKey);
                }

                $csr = $this->generateCSR($privateKey, [$name], false);

                // try to obtain from each issuer until we succeed
                $issuedCert = null;
                $issuerUsed = null;
                $issuerKeys = [];
                $err = null;
                foreach ($this->issuers ?? [] as $issuer) {
                    // TODO: ZeroSSL's API currently requires CommonName to be set, and requires it be
                    // distinct from SANs. If this was a cert it would violate the BRs, but their certs
                    // are compliant, so their CSR requirements just needlessly add friction, complexity,
                    // and inefficiency for clients. CommonName has been deprecated for 25+ years.
                    $useCSR = $csr;
                    if ($issuer->issuerKey() === self::zerosslIssuerKey) {
                        $useCSR = $this->generateCSR($privateKey, [$name], true);
                    }

                    $issuerKeys[] = $issuer->issuerKey();
                    if ($issuer instanceof PreChecker) {
                        try {
                            $issuer->preCheck([$name], $interactive);
                        } catch (\Throwable $e) {
                            $err = $e;
                            continue;
                        }
                    }

                    // if we're renewing with the same ACME CA as before, have the ACME
                    // client tell the server we are replacing a certificate (but doing
                    // this on the wrong CA, or when the CA doesn't recognize the certID,
                    // can fail the order) -- TODO: change this check to whether we're using the same ACME account, not CA
                    $issueCtx = new IssueContext($ctx->attempts, $ctx->ariReplaces);
                    if (!$this->disableARI) {
                        try {
                            $acmeData = $certRes->getACMEData();
                            if ($acmeData->ca !== '' && $issuer instanceof ACMEIssuer && $issuer->ca === $acmeData->ca) {
                                $issueCtx->ariReplaces = $leaf;
                            }
                        } catch (\Throwable) {
                            // no ACME data: nothing to replace
                        }
                    }

                    try {
                        $issuedCert = $issuer->issue($useCSR, $issueCtx);
                        $issuerUsed = $issuer;
                        $err = null;
                        break;
                    } catch (\Throwable $e) {
                        $err = $e;
                    }

                    // err is usually wrapped, which is nice for simply printing it, but
                    // with our structured error logs we only need the problem string
                    $errToLog = $err->getMessage();
                    $problem = Problem::from($err);
                    if ($problem !== null) {
                        $errToLog = $problem->errorString();
                    }
                    $log->error('could not get certificate from issuer', [
                        'identifier' => $name,
                        'issuer' => $issuer->issuerKey(),
                        'error' => $errToLog,
                    ]);
                }
                if ($err !== null || $issuedCert === null || $issuerUsed === null) {
                    $this->emitIgnoringErrors('cert_failed', [
                        'renewal' => true,
                        'identifier' => $name,
                        'remaining' => $timeLeft,
                        'issuers' => $issuerKeys,
                        'error' => $err !== null ? $err->getMessage() : 'no issuer produced a certificate',
                    ]);

                    // only the error from the last issuer will be returned, but we logged the others
                    throw new \RuntimeException(sprintf('[%s] Renew: %s', $name, $err !== null ? $err->getMessage() : 'no issuer produced a certificate'), 0, $err);
                }
                $issuerKey = $issuerUsed->issuerKey();

                // success - immediately save the renewed certificate resource
                $metaJSON = null;
                try {
                    $metaJSON = self::marshalMetadata($issuedCert->metadata);
                } catch (\Throwable $e) {
                    $log->error('unable to encode certificate metadata', ['error' => $e->getMessage()]);
                }
                $newCertRes = new CertificateResource();
                $newCertRes->sans = Crypto::namesFromCSR($csr);
                $newCertRes->certificatePEM = $issuedCert->certificate;
                $newCertRes->privateKeyPEM = $certRes->privateKeyPEM;
                $newCertRes->issuerData = $metaJSON;
                $newCertRes->issuerKey = $issuerKey;
                try {
                    $this->saveCertResource($issuerUsed, $newCertRes);
                } catch (\Throwable $e) {
                    throw new \RuntimeException(sprintf('[%s] Renew: saving assets: %s', $name, $e->getMessage()), 0, $e);
                }

                $log->info('certificate renewed successfully', [
                    'identifier' => $name,
                    'issuer' => $issuerKey,
                ]);

                $certKey = $newCertRes->namesKey();

                $this->emitIgnoringErrors('cert_obtained', [
                    'renewal' => true,
                    'remaining' => $timeLeft,
                    'identifier' => $name,
                    'issuer' => $issuerKey,
                    'storage_path' => StorageKeys::certsSitePrefix($issuerKey, $certKey),
                    'private_key_path' => StorageKeys::sitePrivateKey($issuerKey, $certKey),
                    'certificate_path' => StorageKeys::siteCert($issuerKey, $certKey),
                    'metadata_path' => StorageKeys::siteMeta($issuerKey, $certKey),
                    'csr_pem' => $csr->pem,
                ]);
            };

            if ($interactive) {
                $f(new IssueContext(0));
            } else {
                Async::doWithRetry($f);
            }
        } finally {
            $log->info('releasing lock', ['identifier' => $name, 'op' => 'renew']);
            try {
                Locks::releaseLock($this->storage(), $lockKey);
            } catch (\Throwable $err) {
                $log->error('unable to unlock', [
                    'identifier' => $name,
                    'lock_key' => $lockKey,
                    'error' => $err->getMessage(),
                ]);
            }
        }
    }

    /**
     * generateCSR generates a CSR for the given SANs. If useCN is true, CommonName will get the first SAN (TODO: this is only a temporary hack for ZeroSSL API support).
     *
     * @param string[] $sans
     * @throws \RuntimeException
     */
    public function generateCSR(\OpenSSLAsymmetricKey $privateKey, array $sans, bool $useCN): CertificateRequest
    {
        $commonName = '';
        $dnsNames = [];
        $ipAddresses = [];
        $emailAddresses = [];
        $uris = [];

        foreach ($sans as $name) {
            // identifiers should be converted to punycode before going into the CSR
            // (convert IDNs to ASCII according to RFC 5280 section 7)
            try {
                $normalizedName = Crypto::idnaToASCII($name);
            } catch (\Throwable $err) {
                throw new \RuntimeException(sprintf("converting identifier '%s' to ASCII: %s", $name, $err->getMessage()), 0, $err);
            }

            // TODO: This is a temporary hack to support ZeroSSL API...
            if ($useCN && $commonName === '' && \strlen($normalizedName) <= 64) {
                $commonName = $normalizedName;
                continue;
            }

            if (filter_var($normalizedName, \FILTER_VALIDATE_IP) !== false) {
                $ipAddresses[] = $normalizedName;
            } elseif (str_contains($normalizedName, '@')) {
                $emailAddresses[] = $normalizedName;
            } elseif (str_contains($normalizedName, '/') && parse_url($normalizedName) !== false) {
                $uris[] = $normalizedName;
            } else {
                $dnsNames[] = $normalizedName;
            }
        }

        // IP addresses aren't printed here because I'm too lazy to marshal them as strings, but
        // we at least print the incoming SANs so it should be obvious what became IPs
        Log::get()->debug('created CSR', [
            'identifiers' => $sans,
            'san_dns_names' => $dnsNames,
            'san_emails' => $emailAddresses,
            'common_name' => $commonName,
            'extra_extensions' => $this->mustStaple ? 1 : 0,
        ]);

        return CertificateRequest::create($privateKey, $commonName, $dnsNames, $ipAddresses, $emailAddresses, $uris, $this->mustStaple);
    }

    /**
     * RevokeCert revokes the certificate for domain via ACME protocol. It requires
     * that cfg.Issuers is properly configured with the same issuer that issued the
     * certificate being revoked. See RFC 5280 §5.3.1 for reason codes.
     *
     * The certificate assets are deleted from storage after successful revocation
     * to prevent reuse.
     *
     * @throws \RuntimeException
     */
    public function revokeCert(string $domain, int $reason, bool $interactive): void
    {
        foreach ($this->issuers ?? [] as $i => $issuer) {
            $issuerKey = $issuer->issuerKey();

            if (!$issuer instanceof Revoker) {
                throw new \RuntimeException(sprintf('issuer %d (%s) is not a Revoker', $i, $issuerKey));
            }

            $certRes = $this->loadCertResource($issuer, $domain);

            if (!$this->storage()->exists(StorageKeys::sitePrivateKey($issuerKey, $domain))) {
                throw new \RuntimeException(sprintf('private key not found for [%s]', implode(' ', $certRes->sans)));
            }

            try {
                $issuer->revoke($certRes, $reason);
            } catch (\Throwable $err) {
                throw new \RuntimeException(sprintf('issuer %d (%s): %s', $i, $issuerKey, $err->getMessage()), 0, $err);
            }

            try {
                $this->deleteSiteAssets($issuerKey, $domain);
            } catch (\Throwable $err) {
                throw new \RuntimeException(sprintf('certificate revoked, but unable to fully clean up assets from issuer %s: %s', $issuerKey, $err->getMessage()), 0, $err);
            }
        }
    }

    /**
     * TLSConfig is an opinionated method that returns a recommended, modern
     * TLS configuration that can be used to configure TLS listeners. Aside
     * from safe, modern defaults, this method sets two critical fields on the
     * TLS config which are required to enable automatic certificate
     * management: GetCertificate and NextProtos.
     *
     * The GetCertificate field is necessary to get certificates from memory
     * or storage, including both manual and automated certificates. You
     * should only change this field if you know what you are doing.
     *
     * The NextProtos field is pre-populated with a special value to enable
     * solving the TLS-ALPN ACME challenge. Because this method does not
     * assume any particular protocols after the TLS handshake is completed,
     * you will likely need to customize the NextProtos field by prepending
     * your application's protocols to the slice. For example, to serve
     * HTTP, you will need to prepend "h2" and "http/1.1" values. Be sure to
     * leave the acmez.ACMETLS1Protocol value intact, however, or TLS-ALPN
     * challenges will fail (which may be acceptable if you are not using
     * ACME, or specifically, the TLS-ALPN challenge).
     *
     * Unlike the package TLS() function, this method does not, by itself,
     * enable certificate management for any domain names.
     */
    public function tlsConfig(): TlsConfig
    {
        return new TlsConfig(
            // these two fields necessary for TLS-ALPN challenge
            function (ClientHelloInfo $hello): Certificate {
                return $this->getCertificate($hello);
            },
            function (): array {
                return $this->certCache()->getAllCerts();
            }
        );
    }

    /**
     * getChallengeInfo loads the challenge info from either the internal challenge memory
     * or the external storage (implying distributed solving). The second return value
     * indicates whether challenge info was loaded from external storage. If true, the
     * challenge is being solved in a distributed fashion; if false, from internal memory.
     * If no matching challenge information can be found, an error is thrown.
     *
     * @return array{0: ActiveChallenge, 1: bool}
     * @throws \RuntimeException
     */
    public function getChallengeInfo(string $identifier): array
    {
        // first, check if our process initiated this challenge; if so, just return it
        $chalData = SolverRegistry::getACMEChallenge($identifier);
        if ($chalData !== null) {
            return [$chalData, false];
        }

        // otherwise, perhaps another instance in the cluster initiated it; check
        // the configured storage to retrieve challenge data

        $chalInfoBytes = '';
        $tokenKey = '';
        foreach ($this->issuers ?? [] as $issuer) {
            $ds = new DistributedSolver($this->storage(), ACMEIssuer::storageKeyACMECAPrefix($issuer->issuerKey()), new NoopSolver());
            $tokenKey = $ds->challengeTokensKey($identifier);
            try {
                $chalInfoBytes = $this->storage()->load($tokenKey);
                break;
            } catch (ErrNotExist) {
                continue;
            } catch (\Throwable $err) {
                throw new \RuntimeException(sprintf('opening distributed challenge token file %s: %s', $tokenKey, $err->getMessage()), 0, $err);
            }
        }
        if ($chalInfoBytes === '') {
            throw new \RuntimeException(sprintf('no information found to solve challenge for identifier: %s', $identifier));
        }

        try {
            $chalInfo = Challenge::fromArray(JsonUtil::unmarshal($chalInfoBytes));
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('decoding challenge token file %s (corrupted?): %s', $tokenKey, $err->getMessage()), 0, $err);
        }

        return [new ActiveChallenge($chalInfo), true];
    }

    private function transformSubject(string $name, bool $log): string
    {
        if ($this->subjectTransformer === null) {
            return $name;
        }
        $transformedName = ($this->subjectTransformer)($name);
        if ($log && $transformedName !== $name) {
            Log::get()->debug('transformed subject name', [
                'original' => $name,
                'transformed' => $transformedName,
            ]);
        }
        return $transformedName;
    }

    /**
     * checkStorage tests the storage by writing random bytes
     * to a random key, and then loading those bytes and
     * comparing the loaded value. If this fails, the provided
     * cfg.Storage mechanism should not be used.
     *
     * @throws \RuntimeException
     */
    public function checkStorage(): void
    {
        if ($this->disableStorageCheck) {
            return;
        }
        $key = sprintf('rw_test_%d', random_int(0, \PHP_INT_MAX));
        $contents = random_bytes(1024 * 10); // size sufficient for one or two ACME resources
        $this->storage()->store($key, $contents);
        try {
            $loaded = $this->storage()->load($key);
            if ($contents !== $loaded) {
                throw new \RuntimeException(sprintf('load yielded different value than was stored; expected %d bytes, got %d bytes of differing elements', \strlen($contents), \strlen($loaded)));
            }
        } finally {
            try {
                $this->storage()->delete($key);
            } catch (\Throwable $deleteErr) {
                Log::get()->error('deleting test key from storage', ['key' => $key, 'error' => $deleteErr->getMessage()]);
                // if there was no other error, make sure
                // to return any error returned from Delete
                throw $deleteErr;
            }
        }
    }

    /**
     * storageHasCertResources returns true if the storage
     * associated with cfg's certificate cache has all the
     * resources related to the certificate for domain: the
     * certificate, the private key, and the metadata.
     */
    public function storageHasCertResources(Issuer $issuer, string $domain): bool
    {
        $issuerKey = $issuer->issuerKey();
        $certKey = StorageKeys::siteCert($issuerKey, $domain);
        $keyKey = StorageKeys::sitePrivateKey($issuerKey, $domain);
        $metaKey = StorageKeys::siteMeta($issuerKey, $domain);
        return $this->storage()->exists($certKey)
            && $this->storage()->exists($keyKey)
            && $this->storage()->exists($metaKey);
    }

    /**
     * deleteSiteAssets deletes the folder in storage containing the
     * certificate, private key, and metadata file for domain from the
     * issuer with the given issuer key.
     *
     * @throws \RuntimeException
     */
    public function deleteSiteAssets(string $issuerKey, string $domain): void
    {
        try {
            $this->storage()->delete(StorageKeys::siteCert($issuerKey, $domain));
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('deleting certificate file: %s', $err->getMessage()), 0, $err);
        }
        try {
            $this->storage()->delete(StorageKeys::sitePrivateKey($issuerKey, $domain));
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('deleting private key: %s', $err->getMessage()), 0, $err);
        }
        try {
            $this->storage()->delete(StorageKeys::siteMeta($issuerKey, $domain));
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('deleting metadata file: %s', $err->getMessage()), 0, $err);
        }
        try {
            $this->storage()->delete(StorageKeys::certsSitePrefix($issuerKey, $domain));
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('deleting site asset folder: %s', $err->getMessage()), 0, $err);
        }
    }

    /**
     * lockKey returns a key for a lock that is specific to the operation
     * named op being performed related to domainName and this config's CA.
     */
    public function lockKey(string $op, string $domainName): string
    {
        return sprintf('%s_%s', $op, $domainName);
    }

    /**
     * managedCertNeedsRenewal returns true if certRes is expiring soon or already expired,
     * or if the process of decoding the cert and checking its expiration returned an error.
     * If there wasn't an error, the leaf cert is also returned, so it can be reused if
     * necessary, since we are parsing the PEM bundle anyway.
     *
     * @return array{0: float, 1: X509Certificate|null, 2: bool} remaining seconds, leaf, needs renewal
     */
    public function managedCertNeedsRenewal(CertificateResource $certRes, bool $emitLogs): array
    {
        try {
            $certChain = Crypto::parseCertsFromPEMBundle($certRes->certificatePEM);
        } catch (\Throwable) {
            return [0.0, null, true];
        }
        if ($certChain === []) {
            return [0.0, null, true];
        }
        $ari = new RenewalInfo();
        if (!$this->disableARI) {
            try {
                $ariPtr = $certRes->getARI();
                if ($ariPtr !== null) {
                    $ari = $ariPtr;
                }
            } catch (\Throwable) {
                // invalid ARI JSON: proceed without it
            }
        }
        $remaining = Rfc3339::seconds($certChain[0]->expiresAt()) - microtime(true);
        return [$remaining, $certChain[0], $this->certNeedsRenewal($certChain[0], $ari, $emitLogs)];
    }

    /**
     * emit invokes the OnEvent callback, if any; it throws whatever the
     * callback throws (the emitter decides whether to abort).
     *
     * @param array<string, mixed> $data
     * @throws \Throwable
     */
    public function emit(string $eventName, array $data): void
    {
        if ($this->onEvent === null) {
            return;
        }
        ($this->onEvent)($eventName, $data);
    }

    /**
     * emitIgnoringErrors emits an event whose outcome Go ignores
     * (`cfg.emit(...)` with the returned error discarded).
     *
     * @param array<string, mixed> $data
     */
    private function emitIgnoringErrors(string $eventName, array $data): void
    {
        try {
            $this->emit($eventName, $data);
        } catch (\Throwable) {
            // emitters may choose to ignore returned errors
        }
    }

    // ---- handshake.go -------------------------------------------------------

    /**
     * GetCertificate gets a certificate to satisfy clientHello. In getting
     * the certificate, it abides the rules and settings defined in the Config
     * that matches clientHello.ServerName. It tries to get certificates in
     * this order:
     *
     * 1. Exact match in the in-memory cache
     * 2. Wildcard match in the in-memory cache
     * 3. Managers (if any)
     * 4. Storage (if on-demand is enabled)
     * 5. Issuers (if on-demand is enabled)
     *
     * This method is safe for use as a tls.Config.GetCertificate callback
     * (the {@see TlsConfig::$getCertificate} of the PHP port).
     *
     * @throws \RuntimeException
     */
    public function getCertificate(ClientHelloInfo $clientHello): Certificate
    {
        try {
            $this->emit('tls_get_certificate', ['client_hello' => $clientHello->withoutConn()]);
        } catch (\Throwable $err) {
            Log::get()->error('TLS handshake aborted by event handler', [
                'server_name' => $clientHello->serverName,
                'remote' => $clientHello->remoteAddr,
                'error' => $err->getMessage(),
            ]);
            throw new \RuntimeException(sprintf('handshake aborted by event handler: %s', $err->getMessage()), 0, $err);
        }

        // special case: serve up the certificate for a TLS-ALPN ACME challenge
        // (https://www.rfc-editor.org/rfc/rfc8737.html)
        // "The ACME server MUST provide an ALPN extension with the single protocol
        // name "acme-tls/1" and an SNI extension containing only the domain name
        // being validated during the TLS handshake."
        if ($clientHello->serverName !== ''
            && \count($clientHello->supportedProtos) === 1
            && $clientHello->supportedProtos[0] === TlsAlpn01::ACMETLS1Protocol) {
            try {
                [$challengeCert, $distributed] = $this->getTLSALPNChallengeCert($clientHello);
            } catch (\Throwable $err) {
                Log::get()->error('tls-alpn challenge', [
                    'remote_addr' => $clientHello->remoteAddr,
                    'server_name' => $clientHello->serverName,
                    'error' => $err->getMessage(),
                ]);
                throw $err;
            }
            Log::get()->info('served key authentication certificate', [
                'server_name' => $clientHello->serverName,
                'challenge' => 'tls-alpn-01',
                'remote' => $clientHello->remoteAddr,
                'distributed' => $distributed,
            ]);
            return $challengeCert;
        }

        // get the certificate and serve it up
        return $this->getCertDuringHandshake($clientHello, true);
    }

    /**
     * getCertificateFromCache gets a certificate that matches name from the in-memory
     * cache, according to the lookup table associated with cfg. The lookup then
     * points to a certificate in the Instance certificate cache.
     *
     * The name is expected to already be normalized (e.g. lowercased).
     *
     * If there is no exact match for name, it will be checked against names of
     * the form '*.example.com' (wildcard certificates) according to RFC 6125.
     * If a match is found, matched will be true. If no matches are found, matched
     * will be false and a "default" certificate will be returned with defaulted
     * set to true. If defaulted is false, then no certificates were available.
     *
     * The logic in this function is adapted from the Go standard library,
     * which is by the Go Authors.
     *
     * @return array{0: Certificate|null, 1: bool, 2: bool} cert, matched, defaulted
     */
    public function getCertificateFromCache(ClientHelloInfo $hello): array
    {
        $name = CertMagic::normalizedName($hello->serverName);

        if ($name === '') {
            // if SNI is empty, prefer matching IP address
            if ($hello->conn !== null) {
                $addr = self::localIPFromConn($hello);
                [$cert, $matched] = $this->selectCert($hello, $addr);
                if ($matched) {
                    return [$cert, true, false];
                }
            }

            // use a "default" certificate by name, if specified
            if ($this->defaultServerName !== '') {
                $normDefault = CertMagic::normalizedName($this->defaultServerName);
                [$cert, $defaulted] = $this->selectCert($hello, $normDefault);
                if ($defaulted) {
                    return [$cert, false, true];
                }
            }
        } else {
            // if SNI is specified, try an exact match first
            [$cert, $matched] = $this->selectCert($hello, $name);
            if ($matched) {
                return [$cert, true, false];
            }

            // try replacing labels in the name with
            // wildcards until we get a match
            $labels = explode('.', $name);
            foreach ($labels as $i => $label) {
                $labels[$i] = '*';
                $candidate = implode('.', $labels);
                [$cert, $matched] = $this->selectCert($hello, $candidate);
                if ($matched) {
                    return [$cert, true, false];
                }
            }
        }

        // a fallback server name can be tried in the very niche
        // case where a client sends one SNI value but expects or
        // accepts a different one in return (this is sometimes
        // the case with CDNs like Cloudflare that send the
        // downstream ServerName in the handshake but accept
        // the backend origin's true hostname in a cert).
        if ($this->fallbackServerName !== '') {
            $normFallback = CertMagic::normalizedName($this->fallbackServerName);
            [$cert, $defaulted] = $this->selectCert($hello, $normFallback);
            if ($defaulted) {
                return [$cert, false, true];
            }
        }

        // otherwise, we're bingo on ammo; see issues
        // caddyserver/caddy#2035 and caddyserver/caddy#1303 (any
        // change to certificate matching behavior must
        // account for hosts defined where the hostname
        // is empty or a catch-all, like ":443" or
        // "0.0.0.0:443")

        return [null, false, false];
    }

    /**
     * selectCert uses hello to select a certificate from the
     * cache for name. If cfg.CertSelection is set, it will be
     * used to make the decision. Otherwise, the first matching
     * unexpired cert is returned. As a special case, if no
     * certificates match name and cfg.CertSelection is set,
     * then all certificates in the cache will be passed in
     * for the cfg.CertSelection to make the final decision.
     *
     * @return array{0: Certificate|null, 1: bool}
     */
    private function selectCert(ClientHelloInfo $hello, string $name): array
    {
        $logger = Log::get();
        $choices = $this->certCache()->getAllMatchingCerts($name);

        if ($choices === []) {
            if ($this->certSelection === null) {
                $logger->debug('no matching certificates and no custom selection logic', ['identifier' => $name]);
                return [null, false];
            }
            $logger->debug('no matching certificate; will choose from all certificates', ['identifier' => $name]);
            $choices = $this->certCache()->getAllCerts();
        }

        $logger->debug('choosing certificate', [
            'identifier' => $name,
            'num_choices' => \count($choices),
        ]);

        if ($this->certSelection === null) {
            try {
                $cert = self::defaultCertificateSelector($hello, $choices);
                $err = null;
            } catch (\Throwable $e) {
                $cert = null;
                $err = $e;
            }
            $logger->debug('default certificate selection results', [
                'error' => $err?->getMessage(),
                'identifier' => $name,
                'subjects' => $cert?->names,
                'managed' => $cert?->managed,
                'issuer_key' => $cert?->issuerKey,
                'hash' => $cert?->hash,
            ]);
            return [$cert, $err === null && $cert !== null];
        }

        try {
            $cert = $this->certSelection->selectCertificate($hello, $choices);
            $err = null;
        } catch (\Throwable $e) {
            $cert = null;
            $err = $e;
        }

        $logger->debug('custom certificate selection results', [
            'error' => $err?->getMessage(),
            'identifier' => $name,
            'subjects' => $cert?->names,
            'managed' => $cert?->managed,
            'issuer_key' => $cert?->issuerKey,
            'hash' => $cert?->hash,
        ]);

        return [$cert, $err === null && $cert !== null];
    }

    /**
     * DefaultCertificateSelector is the default certificate selection logic
     * given a choice of certificates. If there is at least one certificate in
     * choices, it always returns a certificate without error. It chooses the
     * first non-expired certificate that the client supports if possible,
     * otherwise it returns an expired certificate that the client supports,
     * otherwise it just returns the first certificate in the list of choices.
     *
     * @param Certificate[] $choices
     * @throws \RuntimeException "no certificates available"
     */
    public static function defaultCertificateSelector(ClientHelloInfo $hello, array $choices): Certificate
    {
        if (\count($choices) === 1) {
            // Fast path: There's only one choice, so we would always return that one
            // regardless of whether it is expired or not compatible.
            return $choices[0];
        }
        if ($choices === []) {
            throw new \RuntimeException('no certificates available');
        }

        // Slow path: There are choices, so we need to check each of them.
        $now = microtime(true);
        $best = $choices[0];
        foreach ($choices as $choice) {
            if ($hello->supportsCertificate($choice) !== null) {
                continue;
            }
            $best = $choice; // at least the client supports it...
            if ($choice->leaf !== null && $now > Rfc3339::seconds($choice->leaf->notBefore) && $now < Rfc3339::seconds($choice->leaf->expiresAt())) {
                return $choice; // ...and unexpired, great! "Certificate, I choose you!"
            }
        }
        return $best; // all matching certs are expired or incompatible, oh well
    }

    /**
     * getCertDuringHandshake will get a certificate for hello. It first tries
     * the in-memory cache. If no exact certificate for hello is in the cache, the
     * config most closely corresponding to hello (like a wildcard) will be loaded.
     * If none could be matched from the cache, it invokes the configured certificate
     * managers to get a certificate and uses the first one that returns a certificate.
     * If no certificate managers return a value, and if the config allows it
     * (OnDemand!=nil) and if loadIfNecessary == true, it goes to storage to load the
     * cert into the cache and serve it. If it's not on disk and if
     * obtainIfNecessary == true, the certificate will be obtained from the CA, cached,
     * and served. If obtainIfNecessary == true, then loadIfNecessary must also be == true.
     * An error will be thrown if and only if no certificate is available.
     *
     * @throws \RuntimeException
     */
    private function getCertDuringHandshake(ClientHelloInfo $hello, bool $loadOrObtainIfNecessary): Certificate
    {
        $logger = Log::get();

        // First check our in-memory cache to see if we've already loaded it
        [$cert, $matched, $defaulted] = $this->getCertificateFromCache($hello);
        if ($matched && $cert !== null) {
            $logger->debug('matched certificate in cache', [
                'subjects' => $cert->names,
                'managed' => $cert->managed,
                'expiration' => Rfc3339::encode(CertMagic::expiresAt($cert->leaf)),
                'hash' => $cert->hash,
            ]);
            if ($cert->managed && $this->onDemand !== null && $loadOrObtainIfNecessary) {
                // On-demand certificates are maintained in the background, but
                // maintenance is triggered by handshakes instead of by a timer
                // as in maintain.go.
                return $this->optionalMaintenance($cert, $hello);
            }
            return $cert;
        }

        $name = $this->getNameFromClientHello($hello);

        // By this point, we need to load or obtain a certificate. If a swarm of requests comes in for the same
        // domain, avoid pounding manager or storage thousands of times simultaneously. We use a similar sync
        // strategy for obtaining certificate during handshake.
        if (isset(self::$certLoadWaitChans[$name])) {
            // another goroutine is already loading the cert; just wait and we'll get it from the in-memory cache
            // (single-threaded port: a re-entrant call — serve from the cache)
            return $this->getCertDuringHandshake($hello, false);
        }
        // no other goroutine is currently trying to load this cert
        self::$certLoadWaitChans[$name] = true;

        try {
            // If an external Manager is configured, try to get it from them.
            // Only continue to use our own logic if it returns empty+nil.
            $externalCert = $this->getCertFromAnyCertManager($hello);
            if ($externalCert !== null && !$externalCert->empty()) {
                return $externalCert;
            }

            // Make sure a certificate is allowed for the given name. If not, it doesn't make sense
            // to try loading one from storage (issue #185) or obtaining one from an issuer.
            try {
                $this->checkIfCertShouldBeObtained($name, false);
            } catch (\Throwable $err) {
                throw new \RuntimeException(sprintf('certificate is not allowed for server name %s: %s', $name, $err->getMessage()), 0, $err);
            }

            // We might be able to load or obtain a needed certificate. Load from
            // storage if OnDemand is enabled, or if there is the possibility that
            // a statically-managed cert was evicted from a full cache.
            $cacheSize = \count($this->certCache()->getAllCerts());

            // A cert might have still been evicted from the cache even if the cache
            // is no longer completely full; this happens if the newly-loaded cert is
            // itself evicted (perhaps due to being expired or unmanaged at this point).
            // Hence, we use an "almost full" metric to allow for the cache to not be
            // perfectly full while still being able to load needed certs from storage.
            // See https://caddy.community/t/error-tls-alert-internal-error-592-again/13272
            // and caddyserver/caddy#4320.
            $cacheCapacity = (float) $this->certCache()->options->capacity;
            $cacheAlmostFull = $cacheCapacity > 0 && (float) $cacheSize >= $cacheCapacity * .9;
            $loadDynamically = $this->onDemand !== null || $cacheAlmostFull;

            if ($loadDynamically && $loadOrObtainIfNecessary) {
                // Check to see if we have one on disk
                try {
                    return $this->loadCertFromStorage($hello);
                } catch (\Throwable $err) {
                    $logger->debug('did not load cert from storage', [
                        'server_name' => $hello->serverName,
                        'error' => $err->getMessage(),
                    ]);
                    if ($this->onDemand !== null) {
                        // By this point, we need to ask the CA for a certificate
                        return $this->obtainOnDemandCertificate($hello);
                    }
                    throw $err;
                }
            }

            // Fall back to another certificate if there is one (either DefaultServerName or FallbackServerName)
            if ($defaulted && $cert !== null) {
                $logger->debug('fell back to default certificate', [
                    'subjects' => $cert->names,
                    'managed' => $cert->managed,
                    'expiration' => Rfc3339::encode(CertMagic::expiresAt($cert->leaf)),
                    'hash' => $cert->hash,
                ]);
                return $cert;
            }

            $logger->debug('no certificate matching TLS ClientHello', [
                'server_name' => $hello->serverName,
                'remote' => $hello->remoteAddr,
                'identifier' => $name,
                'cipher_suites' => $hello->cipherSuites,
                'cert_cache_fill' => $cacheCapacity > 0 ? (float) $cacheSize / $cacheCapacity : \INF, // may be approximate! because we are not within the lock
                'load_or_obtain_if_necessary' => $loadOrObtainIfNecessary,
                'on_demand' => $this->onDemand !== null,
            ]);

            throw new \RuntimeException(sprintf("no certificate available for '%s'", $name));
        } finally {
            // unblock others and clean up when we're done
            unset(self::$certLoadWaitChans[$name]);
        }
    }

    /**
     * loadCertFromStorage loads the certificate for name from storage and maintains it
     * (as this is only called with on-demand TLS enabled).
     *
     * @throws \RuntimeException
     */
    private function loadCertFromStorage(ClientHelloInfo $hello): Certificate
    {
        $logger = Log::get();
        $name = $this->getNameFromClientHello($hello);
        try {
            $loadedCert = $this->cacheManagedCertificate($name);
        } catch (\Throwable $err) {
            if (!self::isNotExist($err)) {
                throw new \RuntimeException(sprintf('no matching certificate to load for %s: %s', $name, $err->getMessage()), 0, $err);
            }
            // If no exact match, try a wildcard variant, which is something we can still use
            $labels = explode('.', $name);
            $labels[0] = '*';
            try {
                $loadedCert = $this->cacheManagedCertificate(implode('.', $labels));
            } catch (\Throwable $err2) {
                throw new \RuntimeException(sprintf('no matching certificate to load for %s: %s', $name, $err2->getMessage()), 0, $err2);
            }
        }
        $logger->debug('loaded certificate from storage', [
            'subjects' => $loadedCert->names,
            'managed' => $loadedCert->managed,
            'expiration' => Rfc3339::encode(CertMagic::expiresAt($loadedCert->leaf)),
            'hash' => $loadedCert->hash,
        ]);
        try {
            $loadedCert = $this->handshakeMaintenance($hello, $loadedCert);
        } catch (\Throwable $err) {
            $logger->error('maintaining newly-loaded certificate', [
                'server_name' => $name,
                'error' => $err->getMessage(),
            ]);
        }
        return $loadedCert;
    }

    /**
     * optionalMaintenance will perform maintenance on the certificate (if necessary) and
     * will return the resulting certificate. This should only be done if the certificate
     * is managed, OnDemand is enabled, and the scope is allowed to obtain certificates.
     *
     * @throws \RuntimeException
     */
    private function optionalMaintenance(Certificate $cert, ClientHelloInfo $hello): Certificate
    {
        try {
            return $this->handshakeMaintenance($hello, $cert);
        } catch (\Throwable $err) {
            Log::get()->error('renewing certificate on-demand failed', [
                'subjects' => $cert->names,
                'not_after' => Rfc3339::encode(CertMagic::expiresAt($cert->leaf)),
                'error' => $err->getMessage(),
            ]);

            if ($cert->expired()) {
                throw $err;
            }

            // still has time remaining, so serve it anyway
            return $cert;
        }
    }

    /**
     * checkIfCertShouldBeObtained checks to see if an on-demand TLS certificate
     * should be obtained for a given domain based upon the config settings. If
     * it throws, do not issue a new certificate for name.
     *
     * @throws \RuntimeException
     */
    private function checkIfCertShouldBeObtained(string $name, bool $requireOnDemand): void
    {
        if ($requireOnDemand && $this->onDemand === null) {
            throw new \RuntimeException('not configured for on-demand certificate issuance');
        }
        if (!CertMagic::subjectQualifiesForCert($name)) {
            throw new \RuntimeException(sprintf('subject name does not qualify for certificate: %s', $name));
        }
        if ($this->onDemand !== null) {
            if ($this->onDemand->decisionFunc !== null) {
                try {
                    ($this->onDemand->decisionFunc)($name);
                } catch (\Throwable $err) {
                    throw new \RuntimeException(sprintf('decision func: %s', $err->getMessage()), 0, $err);
                }
                return;
            }
            if ($this->onDemand->hostAllowlist !== null && $this->onDemand->hostAllowlist !== []) {
                if (!isset($this->onDemand->hostAllowlist[$name])) {
                    throw new \RuntimeException(sprintf("certificate for '%s' is not managed", $name));
                }
            }
        }
    }

    /**
     * obtainOnDemandCertificate obtains a certificate for hello.
     * If another goroutine has already started obtaining a cert for
     * hello, it will wait and use what the other goroutine obtained.
     *
     * @throws \RuntimeException
     */
    private function obtainOnDemandCertificate(ClientHelloInfo $hello): Certificate
    {
        $log = Log::get();

        $name = $this->getNameFromClientHello($hello);

        // We must protect this process from happening concurrently, so synchronize.
        if (isset(self::$obtainCertWaitChans[$name])) {
            // lucky us -- another goroutine is already obtaining the certificate.
            // wait for it to finish obtaining the cert and then we'll use it.
            $log->debug('new certificate is needed, but is already being obtained; waiting for that issuance to complete', ['subject' => $name]);

            // it should now be loaded in the cache, ready to go; if not,
            // the goroutine in charge of that probably had an error
            return $this->getCertDuringHandshake($hello, false);
        }

        // looks like it's up to us to do all the work and obtain the cert.
        // make a chan others can wait on if needed
        self::$obtainCertWaitChans[$name] = true;

        $log->info('obtaining new certificate', ['server_name' => $name]);

        // set a timeout so we don't inadvertently hold a client handshake open too long
        // (timeout duration is based on https://caddy.community/t/zerossl-dns-challenge-failing-often-route53-plugin/13822/24?u=matt)
        // (PHP port: the ACME client's poll timeout bounds the issuance instead)

        // obtain the certificate (this puts it in storage) and if successful,
        // load it from storage so we and any other waiting goroutine can use it
        try {
            $this->obtainCertAsync($name);
            // load from storage while others wait to make the op as atomic as possible
            try {
                $cert = $this->loadCertFromStorage($hello);
            } catch (\Throwable $err) {
                $log->error('loading newly-obtained certificate from storage', ['server_name' => $name, 'error' => $err->getMessage()]);
                throw $err;
            }
        } finally {
            // immediately unblock anyone waiting for it
            unset(self::$obtainCertWaitChans[$name]);
        }

        return $cert;
    }

    /**
     * handshakeMaintenance performs a check on cert for expiration and OCSP validity.
     * If necessary, it will renew the certificate and/or refresh the OCSP staple.
     * OCSP stapling errors are not returned, only logged.
     *
     * (PHP port: OCSP is not maintained; the ARI refresh Go performs in a
     * goroutine happens inline.)
     *
     * @throws \RuntimeException
     */
    private function handshakeMaintenance(ClientHelloInfo $hello, Certificate $cert): Certificate
    {
        $logger = Log::get();

        $renewIfNecessary = function (ClientHelloInfo $hello, Certificate $cert): Certificate {
            if ($cert->leaf === null) {
                throw new \RuntimeException('leaf certificate is unexpectedly nil: either the Certificate got replaced by an empty value, or it was not properly initialized');
            }
            if ($this->certNeedsRenewal($cert->leaf, $cert->ari, true)) {
                // Check if the certificate still exists on disk. If not, we need to obtain a new one.
                // This can happen if the certificate was cleaned up by the storage cleaner, but still
                // remains in the in-memory cache.
                if (!$this->storageHasCertResourcesAnyIssuer($cert->names[0])) {
                    Log::get()->debug('certificate not found on disk; obtaining new certificate', ['identifiers' => $cert->names]);
                    return $this->obtainOnDemandCertificate($hello);
                }
                // Otherwise, renew the certificate.
                return $this->renewDynamicCertificate($hello, $cert);
            }
            return $cert;
        };

        // Check ARI status, but it's only relevant if the certificate is not expired (otherwise, we already know it needs renewal!)
        if (!$this->disableARI && $cert->ari->needsRefresh() && $cert->leaf !== null && microtime(true) < Rfc3339::seconds($cert->leaf->notAfter)) {
            // we ignore the second return value here because we check renewal status below regardless
            [$cert, , $err] = $this->updateARI($cert);
            if ($err !== null) {
                $logger->error('updating ARI', ['identifiers' => $cert->names, 'server_name' => $hello->serverName, 'error' => $err->getMessage()]);
            }
        }

        // We attempt to replace any certificates that were revoked.
        // Crucially, this happens OUTSIDE a lock on the certCache.
        // (PHP port: no OCSP status; certShouldBeForceRenewed is always false.)

        // Since renewal conditions may have changed, do a renewal if necessary
        return $renewIfNecessary($hello, $cert);
    }

    /**
     * renewDynamicCertificate renews the certificate for name using cfg. It returns the
     * certificate to use, or throws. name should already be lower-cased before
     * calling this function. name is the name obtained directly from the handshake's
     * ClientHello. If the certificate hasn't yet expired, currentCert will be returned
     * and the renewal will happen in the background; otherwise this blocks until the
     * certificate has been renewed, and returns the renewed certificate.
     *
     * (PHP port: the "background" renewal of an unexpired certificate runs
     * inline as well, before the current certificate is returned.)
     *
     * @throws \RuntimeException
     */
    private function renewDynamicCertificate(ClientHelloInfo $hello, Certificate $currentCert): Certificate
    {
        $logger = Log::get();

        $name = $this->getNameFromClientHello($hello);
        $timeLeft = $currentCert->leaf !== null ? Rfc3339::seconds($currentCert->leaf->expiresAt()) - microtime(true) : 0.0;
        $revoked = false;

        // see if another goroutine is already working on this certificate
        if (isset(self::$obtainCertWaitChans[$name])) {
            // lucky us -- another goroutine is already renewing the certificate

            // the current certificate hasn't expired, and another goroutine is already
            // renewing it, so we might as well serve what we have without blocking, UNLESS
            // we're forcing renewal, in which case the current certificate is not usable
            if ($timeLeft > 0 && !$revoked) {
                $logger->debug('certificate expires soon but is already being renewed; serving current certificate', [
                    'subjects' => $currentCert->names,
                    'remaining' => Rfc3339::durationString($timeLeft),
                ]);
                return $currentCert;
            }

            // otherwise, we'll have to wait for the renewal to finish so we don't serve
            // a revoked or expired certificate
            $logger->debug('certificate has expired, but is already being renewed; waiting for renewal to complete', [
                'subjects' => $currentCert->names,
                'expired' => Rfc3339::encode(CertMagic::expiresAt($currentCert->leaf)),
                'revoked' => $revoked,
            ]);

            // it should now be loaded in the cache, ready to go; if not,
            // the goroutine in charge of that probably had an error
            return $this->getCertDuringHandshake($hello, false);
        }

        // looks like it's up to us to do all the work and renew the cert
        self::$obtainCertWaitChans[$name] = true;

        $logContext = [
            'server_name' => $name,
            'subjects' => $currentCert->names,
            'expiration' => Rfc3339::encode(CertMagic::expiresAt($currentCert->leaf)),
            'remaining' => Rfc3339::durationString($timeLeft),
            'revoked' => $revoked,
        ];

        // Renew and reload the certificate
        $renewAndReload = function () use ($name, $currentCert, $logger, $logContext): Certificate {
            // Make sure a certificate for this name should be renewed on-demand
            try {
                $this->checkIfCertShouldBeObtained($name, true);
            } catch (\Throwable $err) {
                // if not, remove from cache (it will be deleted from storage later)
                $this->certCache()->remove([$currentCert->hash]);
                unset(self::$obtainCertWaitChans[$name]);

                $logger->error('certificate should not be obtained', $logContext + ['error' => $err->getMessage()]);

                throw $err;
            }

            $logger->info('attempting certificate renewal', $logContext);

            // otherwise, renew with issuer, etc.
            try {
                $this->renewCertAsync($name, false);
                // load from storage while in lock to make the replacement as atomic as possible
                $newCert = $this->reloadManagedCertificate($currentCert);
            } catch (\Throwable $err) {
                // immediately unblock anyone waiting for it; doing this in
                // a defer would risk deadlock because of the recursive call
                // to getCertDuringHandshake below when we return!
                unset(self::$obtainCertWaitChans[$name]);
                $logger->error('renewing and reloading certificate', $logContext + ['error' => $err->getMessage()]);
                throw $err;
            }
            unset(self::$obtainCertWaitChans[$name]);

            return $newCert;
        };

        // if the certificate hasn't expired, we can serve what we have and renew in the background
        if ($timeLeft > 0) {
            try {
                $renewAndReload();
            } catch (\Throwable) {
                // logged by renewAndReload; serve the current certificate anyway
            }
            return $currentCert;
        }

        // otherwise, we have to block while we renew an expired certificate
        return $renewAndReload();
    }

    /**
     * getCertFromAnyCertManager gets a certificate from cfg's Managers. If there are no Managers defined, this is
     * a no-op that returns null. Otherwise, it gets a certificate for hello from the first Manager that
     * returns a certificate and no error.
     *
     * @throws \RuntimeException
     */
    private function getCertFromAnyCertManager(ClientHelloInfo $hello): ?Certificate
    {
        $logger = Log::get();

        // fast path if nothing to do
        if ($this->onDemand === null || $this->onDemand->managers === []) {
            return null;
        }

        // try all the GetCertificate methods on external managers; use first one that returns a certificate
        $upstreamCert = null;
        $err = null;
        foreach ($this->onDemand->managers as $i => $certManager) {
            try {
                $upstreamCert = $certManager->getCertificate($hello);
                $err = null;
            } catch (\Throwable $e) {
                $err = $e;
                $logger->error('external certificate manager', [
                    'sni' => $hello->serverName,
                    'cert_manager' => $certManager::class,
                    'cert_manager_idx' => $i,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
            if ($upstreamCert !== null) {
                break;
            }
        }
        if ($err !== null) {
            throw new \RuntimeException(sprintf('external certificate manager indicated that it is unable to yield certificate: %s', $err->getMessage()), 0, $err);
        }
        if ($upstreamCert === null) {
            $logger->debug('all external certificate managers yielded no certificates and no errors', ['sni' => $hello->serverName]);
            return null;
        }

        try {
            $cert = Certificate::makeCertificate($upstreamCert->certificatePEM, $upstreamCert->privateKeyPEM);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('external certificate manager: %s: filling cert from leaf: %s', $hello->serverName, $e->getMessage()), 0, $e);
        }

        $logger->debug('using externally-managed certificate', [
            'sni' => $hello->serverName,
            'names' => $cert->names,
            'expiration' => Rfc3339::encode(CertMagic::expiresAt($cert->leaf)),
        ]);

        return $cert;
    }

    /**
     * getTLSALPNChallengeCert is to be called when the clientHello pertains to
     * a TLS-ALPN challenge and a certificate is required to solve it. This method gets
     * the relevant challenge info and then returns the associated certificate (if any)
     * or generates it anew if it's not available (as is the case when distributed
     * solving). True is returned if the challenge is being solved distributed (there
     * is no semantic difference with distributed solving; it is mainly for logging).
     *
     * @return array{0: Certificate, 1: bool}
     * @throws \RuntimeException
     */
    private function getTLSALPNChallengeCert(ClientHelloInfo $clientHello): array
    {
        [$chalData, $distributed] = $this->getChallengeInfo($clientHello->serverName);

        // fast path: we already created the certificate (this avoids having to re-create
        // it at every handshake that tries to verify, e.g. multi-perspective validation)
        if (\is_array($chalData->data) && isset($chalData->data[0], $chalData->data[1])) {
            return [Certificate::makeCertificate((string) $chalData->data[0], (string) $chalData->data[1]), $distributed];
        }

        // otherwise, we can re-create the solution certificate, but it takes a few cycles
        try {
            [$certPEM, $keyPEM] = TlsAlpn01::tlsALPN01ChallengeCert($chalData->challenge);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('making TLS-ALPN challenge certificate: %s', $err->getMessage()), 0, $err);
        }
        if ($certPEM === '') {
            throw new \RuntimeException('got nil TLS-ALPN challenge certificate but no error');
        }

        return [Certificate::makeCertificate($certPEM, $keyPEM), $distributed];
    }

    /**
     * getNameFromClientHello returns a normalized form of hello.ServerName.
     * If hello.ServerName is empty (i.e. client did not use SNI), then the
     * associated connection's local address is used to extract an IP address.
     */
    private function getNameFromClientHello(ClientHelloInfo $hello): string
    {
        $name = CertMagic::normalizedName($hello->serverName);
        if ($name !== '') {
            return $name;
        }
        if ($this->defaultServerName !== '') {
            return CertMagic::normalizedName($this->defaultServerName);
        }
        return self::localIPFromConn($hello);
    }

    /**
     * localIPFromConn returns the host portion of c's local address
     * and strips the scope ID if one exists (see RFC 4007).
     */
    public static function localIPFromConn(ClientHelloInfo $hello): string
    {
        if ($hello->conn === null && $hello->localAddr === '') {
            return '';
        }
        $localAddr = $hello->localAddr;
        $ip = CertMagic::hostOnly($localAddr);
        // IPv6 addresses can have scope IDs, e.g. "fe80::4c3:3cff:fe4f:7e0b%eth0",
        // but for our purposes, these are useless (unless a valid use case proves
        // otherwise; see issue #3911)
        $scopeIDStart = strpos($ip, '%');
        if ($scopeIDStart !== false) {
            $ip = substr($ip, 0, $scopeIDStart);
        }
        return $ip;
    }

    // ---- certificates.go ----------------------------------------------------

    /**
     * certNeedsRenewal consults ACME Renewal Info (ARI) and certificate expiration to determine
     * whether the leaf certificate needs to be renewed yet. If true is returned, the certificate
     * should be renewed as soon as possible. The reasoning for a true return value is logged
     * unless emitLogs is false; this can be useful to suppress noisy logs in the case where you
     * first call this to determine if a cert in memory needs renewal, and then right after you
     * call it again to see if the cert in storage still needs renewal -- you probably don't want
     * to log the second time for checking the cert in storage which is mainly for synchronization.
     */
    public function certNeedsRenewal(?X509Certificate $leaf, RenewalInfo $ari, bool $emitLogs): bool
    {
        // though this should never happen, safeguard to avoid panics which happened before (since patched; but just in case)
        if ($leaf === null) {
            if ($emitLogs) {
                Log::get()->error('cannot check if nil leaf cert needs renewal');
            }
            return false;
        }

        $expiration = $leaf->expiresAt();
        $renewCheckInterval = $this->certCache()->options->renewCheckInterval;

        $context = [
            'subjects' => $leaf->dnsNames,
            'expiration' => Rfc3339::encode($expiration),
            'ari_cert_id' => $ari->uniqueIdentifier,
            'next_ari_update' => Rfc3339::encode($ari->retryAfter),
            'renew_check_interval' => Rfc3339::durationString($renewCheckInterval),
            'window_start' => Rfc3339::encode($ari->suggestedWindowStart),
            'window_end' => Rfc3339::encode($ari->suggestedWindowEnd),
        ];
        $logger = $emitLogs ? Log::get() : null;

        if (!$this->disableARI) {
            // first check ARI: if it says it's time to renew, it's time to renew
            // (notice that we don't strictly require an ARI window to also exist; we presume
            // that if a time has been selected, a window does or did exist, even if it didn't
            // get stored/encoded for some reason - but also: this allows administrators to
            // manually or explicitly schedule a renewal time independently of ARI which could
            // be useful)
            $selectedTime = $ari->selectedTime;

            // if, for some reason a random time in the window hasn't been selected yet, but an ARI
            // window does exist, we can always improvise one... even if this is called repeatedly,
            // a random time is a random time, whether you generate it once or more :D
            // (code borrowed from our acme package)
            if ($selectedTime === null && $ari->suggestedWindowStart !== null && $ari->suggestedWindowEnd !== null) {
                $start = $ari->suggestedWindowStart->getTimestamp() + 1;
                $end = $ari->suggestedWindowEnd->getTimestamp();
                $selectedTime = Rfc3339::fromSeconds($end > $start ? random_int($start, $end - 1) : $start);
                $logger?->warning('no renewal time had been selected with ARI; chose an ephemeral one for now', $context + [
                    'ephemeral_selected_time' => Rfc3339::encode($selectedTime),
                ]);
            }

            // if a renewal time has been selected, start with that
            if ($selectedTime !== null) {
                // ARI spec recommends an algorithm that renews after the randomly-selected
                // time OR just before it if the next waking time would be after it; this
                // cutoff can actually be before the start of the renewal window, but the spec
                // author says that's OK: https://github.com/aarongable/draft-acme-ari/issues/71
                // (Go computes the cutoff from ari.SelectedTime, which is the zero time when
                // an ephemeral one was improvised — reproduced here.)
                $cutoff = ($ari->selectedTime !== null ? Rfc3339::seconds($ari->selectedTime) : 0.0) - $renewCheckInterval;
                if (microtime(true) > $cutoff) {
                    $logger?->info('certificate needs renewal based on ARI window', $context + [
                        'selected_time' => Rfc3339::encode($selectedTime),
                        'renewal_cutoff' => Rfc3339::encode(Rfc3339::fromSeconds((int) $cutoff)),
                    ]);
                    return true;
                }

                // according to ARI, we are not ready to renew; however, we do not rely solely on
                // ARI calculations... what if there is a bug in our implementation, or in the
                // server's, or the stored metadata? for redundancy, give credence to the expiration
                // date; ignore ARI if we are past a "dangerously close" limit, to avoid any
                // possibility of a bug in ARI compromising a site's uptime: we should always always
                // always give heed to actual validity period
                if (CertMagic::currentlyInRenewalWindow($leaf->notBefore, $expiration, 1.0 / 20.0)) {
                    $logger?->warning('certificate is in emergency renewal window; superseding ARI', $context + [
                        'remaining' => Rfc3339::durationString(Rfc3339::seconds($expiration) - microtime(true)),
                        'renewal_cutoff' => Rfc3339::encode(Rfc3339::fromSeconds((int) $cutoff)),
                    ]);
                    return true;
                }
            }
        }

        // the normal check, in the absence of ARI, is to determine if we're near enough (or past)
        // the expiration date based on the configured remaining:lifetime ratio
        if (CertMagic::currentlyInRenewalWindow($leaf->notBefore, $expiration, $this->renewalWindowRatio)) {
            $logger?->info('certificate is in configured renewal window based on expiration date', $context + [
                'remaining' => Rfc3339::durationString(Rfc3339::seconds($expiration) - microtime(true)),
            ]);
            return true;
        }

        // finally, if the certificate is expiring imminently, always attempt a renewal;
        // we check both a (very low) lifetime ratio and also a strict difference between
        // the time until expiration and the interval at which we run the standard maintenance
        // routine to check for renewals, to accommodate both exceptionally long and short
        // cert lifetimes
        if (CertMagic::currentlyInRenewalWindow($leaf->notBefore, $expiration, 1.0 / 50.0)
            || Rfc3339::seconds($expiration) - microtime(true) < $renewCheckInterval * 5) {
            $logger?->warning('certificate is in emergency renewal window; expiration imminent', $context + [
                'remaining' => Rfc3339::durationString(Rfc3339::seconds($expiration) - microtime(true)),
            ]);
            return true;
        }

        return false;
    }

    /**
     * CacheManagedCertificate loads the certificate for domain into the
     * cache, from the TLS storage for managed certificates. It returns a
     * copy of the Certificate that was put into the cache.
     *
     * This is a lower-level method; normally you'll call Manage() instead.
     *
     * @throws \RuntimeException|ErrNotExist
     */
    public function cacheManagedCertificate(string $domain): Certificate
    {
        $domain = $this->transformSubject($domain, false);
        $cert = $this->loadManagedCertificate($domain);
        $this->certCache()->cacheCertificate($cert);
        $this->emitIgnoringErrors('cached_managed_cert', ['sans' => $cert->names]);
        return $cert;
    }

    /**
     * loadManagedCertificate loads the managed certificate for domain from any
     * of the configured issuers' storage locations, but it does not add it to
     * the cache. It just loads from storage and returns it.
     *
     * @throws \RuntimeException|ErrNotExist
     */
    private function loadManagedCertificate(string $domain): Certificate
    {
        $certRes = $this->loadCertResourceAnyIssuer($domain);
        $cert = $this->makeCertificateWithOCSP($certRes->certificatePEM, $certRes->privateKeyPEM);
        $cert->managed = true;
        $cert->issuerKey = $certRes->issuerKey;
        try {
            $ari = $certRes->getARI();
            if ($ari !== null) {
                $cert->ari = $ari;
            }
        } catch (\Throwable) {
            // invalid ARI JSON: proceed without it
        }
        return $cert;
    }

    /**
     * CacheUnmanagedCertificatePEMFile loads a certificate for host using certFile
     * and keyFile, which must be in PEM format. It stores the certificate in
     * the in-memory cache and returns the hash, useful for removing from the cache.
     *
     * @param string[] $tags
     * @throws \RuntimeException
     */
    public function cacheUnmanagedCertificatePEMFile(string $certFile, string $keyFile, array $tags): string
    {
        $cert = $this->makeCertificateFromDiskWithOCSP($certFile, $keyFile);
        $cert->tags = $tags;
        $this->certCache()->cacheCertificate($cert);
        $this->emitIgnoringErrors('cached_unmanaged_cert', ['sans' => $cert->names]);
        return $cert->hash;
    }

    /**
     * CacheUnmanagedTLSCertificate adds tlsCert to the certificate cache
     * and returns the hash, useful for removing from the cache.
     *
     * (Go staples OCSP if possible; not ported.)
     *
     * @param string[] $tags
     * @throws \RuntimeException
     */
    public function cacheUnmanagedTLSCertificate(Certificate $tlsCert, array $tags): string
    {
        $cert = Certificate::makeCertificate($tlsCert->certificatePEM, $tlsCert->privateKeyPEM);
        if ($cert->leaf !== null) {
            if (microtime(true) > Rfc3339::seconds($cert->leaf->notAfter)) {
                Log::get()->warning('unmanaged certificate has expired', [
                    'not_after' => Rfc3339::encode($cert->leaf->notAfter),
                    'sans' => $cert->names,
                ]);
            } elseif (Rfc3339::seconds($cert->leaf->notAfter) - microtime(true) < 24 * 3600) {
                Log::get()->warning('unmanaged certificate expires within 1 day', [
                    'not_after' => Rfc3339::encode($cert->leaf->notAfter),
                    'sans' => $cert->names,
                ]);
            }
        }
        $this->emitIgnoringErrors('cached_unmanaged_cert', ['sans' => $cert->names]);
        $cert->tags = $tags;
        $this->certCache()->cacheCertificate($cert);
        return $cert->hash;
    }

    /**
     * CacheUnmanagedCertificatePEMBytes makes a certificate out of the PEM bytes
     * of the certificate and key, then caches it in memory,  and returns the hash,
     * which is useful for removing from the cache.
     *
     * @param string[] $tags
     * @throws \RuntimeException
     */
    public function cacheUnmanagedCertificatePEMBytes(string $certBytes, string $keyBytes, array $tags): string
    {
        $cert = $this->makeCertificateWithOCSP($certBytes, $keyBytes);
        $cert->tags = $tags;
        $this->certCache()->cacheCertificate($cert);
        $this->emitIgnoringErrors('cached_unmanaged_cert', ['sans' => $cert->names]);
        return $cert->hash;
    }

    /**
     * makeCertificateFromDiskWithOCSP makes a Certificate by loading the
     * certificate and key files. It fills out all the fields in
     * the certificate except for the Managed and OnDemand flags.
     * (It is up to the caller to set those.) It staples OCSP (Go).
     *
     * @throws \RuntimeException
     */
    private function makeCertificateFromDiskWithOCSP(string $certFile, string $keyFile): Certificate
    {
        $certPEMBlock = @file_get_contents($certFile);
        if ($certPEMBlock === false) {
            throw new \RuntimeException(sprintf('open %s: no such file or directory', $certFile));
        }
        $keyPEMBlock = @file_get_contents($keyFile);
        if ($keyPEMBlock === false) {
            throw new \RuntimeException(sprintf('open %s: no such file or directory', $keyFile));
        }
        return $this->makeCertificateWithOCSP($certPEMBlock, $keyPEMBlock);
    }

    /**
     * makeCertificateWithOCSP is the same as makeCertificate except that it also
     * staples OCSP to the certificate (Go; the PHP port does not staple).
     *
     * @throws \RuntimeException
     */
    private function makeCertificateWithOCSP(string $certPEMBlock, string $keyPEMBlock): Certificate
    {
        return Certificate::makeCertificate($certPEMBlock, $keyPEMBlock);
    }

    /**
     * managedCertInStorageNeedsRenewal returns true if cert (being a
     * managed certificate) is expiring soon (according to cfg) or if
     * ACME Renewal Information (ARI) is available and says that it is
     * time to renew (it uses existing ARI; it does not update it).
     * It returns false if the cert is not expiring
     * soon, and ARI window is still future; it throws on error. A certificate that is expiring
     * soon in our cache but is not expiring soon in storage probably
     * means that another instance renewed the certificate in the
     * meantime, and it would be a good idea to simply load the cert
     * into our cache rather than repeating the renewal process again.
     *
     * @throws \RuntimeException
     */
    public function managedCertInStorageNeedsRenewal(Certificate $cert): bool
    {
        $certRes = $this->loadCertResourceAnyIssuer($cert->names[0]);
        [, , $needsRenew] = $this->managedCertNeedsRenewal($certRes, false);
        return $needsRenew;
    }

    /**
     * reloadManagedCertificate reloads the certificate corresponding to the name(s)
     * on oldCert into the cache, from storage. This also replaces the old certificate
     * with the new one, so that all configurations that used the old cert now point
     * to the new cert. It assumes that the new certificate for oldCert.Names[0] is
     * already in storage. It returns the newly-loaded certificate if successful.
     *
     * @throws \RuntimeException
     */
    public function reloadManagedCertificate(Certificate $oldCert): Certificate
    {
        Log::get()->info('reloading managed certificate', ['identifiers' => $oldCert->names]);
        try {
            $newCert = $this->loadManagedCertificate($oldCert->names[0]);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('loading managed certificate for [%s] from storage: %s', implode(' ', $oldCert->names), $err->getMessage()), 0, $err);
        }
        $this->certCache()->replaceCertificate($oldCert, $newCert);
        return $newCert;
    }

    // ---- crypto.go ----------------------------------------------------------

    /**
     * saveCertResource saves the certificate resource to disk. This
     * includes the certificate file itself, the private key, and the
     * metadata file.
     *
     * @throws \RuntimeException
     */
    public function saveCertResource(Issuer $issuer, CertificateResource $cert): void
    {
        try {
            $metaBytes = JsonUtil::marshalIndent($cert, "\t");
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('encoding certificate metadata: %s', $err->getMessage()), 0, $err);
        }

        $issuerKey = $issuer->issuerKey();
        $certKey = $cert->namesKey();

        $all = [
            StorageKeys::sitePrivateKey($issuerKey, $certKey) => $cert->privateKeyPEM,
            StorageKeys::siteCert($issuerKey, $certKey) => $cert->certificatePEM,
            StorageKeys::siteMeta($issuerKey, $certKey) => $metaBytes,
        ];

        self::storeTx($this->storage(), $all);
    }

    /**
     * loadCertResourceAnyIssuer loads and returns the certificate resource from any
     * of the configured issuers. If multiple are found (e.g. if there are 3 issuers
     * configured, and all 3 have a resource matching certNamesKey), then the newest
     * (latest NotBefore date) resource will be chosen.
     *
     * @throws \RuntimeException|ErrNotExist
     */
    public function loadCertResourceAnyIssuer(string $certNamesKey): CertificateResource
    {
        $issuers = $this->issuers ?? [];

        // we can save some extra decoding steps if there's only one issuer, since
        // we don't need to compare potentially multiple available resources to
        // select the best one, when there's only one choice anyway
        if (\count($issuers) === 1) {
            return $this->loadCertResource($issuers[0], $certNamesKey);
        }

        /** @var array<int, array{res: CertificateResource, issuer: Issuer, decoded: X509Certificate}> $certResources */
        $certResources = [];
        $lastErr = null;

        // load and decode all certificate resources found with the
        // configured issuers so we can sort by newest
        foreach ($issuers as $issuer) {
            try {
                $certRes = $this->loadCertResource($issuer, $certNamesKey);
            } catch (ErrNotExist $err) {
                // not a problem, but we need to remember the error
                // in case we end up not finding any cert resources
                // since we'll need an error to return in that case
                $lastErr = $err;
                continue;
            }
            $certs = Crypto::parseCertsFromPEMBundle($certRes->certificatePEM);
            $certResources[] = ['res' => $certRes, 'issuer' => $issuer, 'decoded' => $certs[0]];
        }
        if ($certResources === []) {
            if ($lastErr === null) {
                $lastErr = new \RuntimeException('no certificate resources found'); // just in case; e.g. no Issuers configured
            }
            throw $lastErr;
        }

        // sort by date so the most recently issued comes first
        usort($certResources, static function (array $a, array $b): int {
            return Rfc3339::seconds($b['decoded']->notBefore) <=> Rfc3339::seconds($a['decoded']->notBefore);
        });

        Log::get()->debug('loading managed certificate', [
            'domain' => $certNamesKey,
            'expiration' => Rfc3339::encode($certResources[0]['decoded']->expiresAt()),
            'issuer_key' => $certResources[0]['issuer']->issuerKey(),
            'storage' => (($s = $this->storage()) instanceof \Stringable ? (string) $s : $s::class),
        ]);

        return $certResources[0]['res'];
    }

    /**
     * loadCertResource loads a certificate resource from the given issuer's storage location.
     *
     * @throws \RuntimeException|ErrNotExist
     */
    public function loadCertResource(Issuer $issuer, string $certNamesKey): CertificateResource
    {
        $certRes = new CertificateResource();
        $certRes->issuerKey = $issuer->issuerKey();

        try {
            $normalizedName = Crypto::idnaToASCII($certNamesKey);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf("converting '%s' to ASCII: %s", $certNamesKey, $err->getMessage()), 0, $err);
        }

        $certRes->privateKeyPEM = $this->storage()->load(StorageKeys::sitePrivateKey($certRes->issuerKey, $normalizedName));
        $certRes->certificatePEM = $this->storage()->load(StorageKeys::siteCert($certRes->issuerKey, $normalizedName));
        $metaBytes = $this->storage()->load(StorageKeys::siteMeta($certRes->issuerKey, $normalizedName));
        try {
            $certRes->applyArray(JsonUtil::unmarshal($metaBytes));
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('decoding certificate metadata: %s', $err->getMessage()), 0, $err);
        }

        return $certRes;
    }

    // ---- maintain.go --------------------------------------------------------

    /**
     * storageHasNewerARI returns true if the configured storage has ARI that is newer
     * than that of a certificate that is already loaded, along with the value from
     * storage.
     *
     * @return array{0: bool, 1: RenewalInfo}
     * @throws \RuntimeException
     */
    private function storageHasNewerARI(Certificate $cert): array
    {
        $storedCert = $this->loadStoredACMECertificateMetadata($cert);
        if ($storedCert->renewalInfo === null || $storedCert->renewalInfo->retryAfter === null) {
            return [false, new RenewalInfo()];
        }
        // prefer stored info if it has a window and the loaded one doesn't,
        // or if the one in storage has a later RetryAfter (though I suppose
        // it's not guaranteed, typically those will move forward in time)
        if ((!$cert->ari->hasWindow() && $storedCert->renewalInfo->hasWindow())
            || ($cert->ari->retryAfter === null || Rfc3339::seconds($storedCert->renewalInfo->retryAfter) > Rfc3339::seconds($cert->ari->retryAfter))) {
            return [true, $storedCert->renewalInfo];
        }
        return [false, new RenewalInfo()];
    }

    /**
     * loadStoredACMECertificateMetadata loads the stored ACME certificate data
     * from the cert's sidecar JSON file.
     *
     * @throws \RuntimeException
     */
    private function loadStoredACMECertificateMetadata(Certificate $cert): AcmeCertificate
    {
        try {
            $metaBytes = $this->storage()->load(StorageKeys::siteMeta($cert->issuerKey, $cert->names[0]));
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('loading cert metadata: %s', $err->getMessage()), 0, $err);
        }

        $certRes = new CertificateResource();
        try {
            $certRes->applyArray(JsonUtil::unmarshal($metaBytes));
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('unmarshaling cert metadata: %s', $err->getMessage()), 0, $err);
        }

        try {
            return $certRes->getACMEData();
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('unmarshaling potential ACME issuer metadata: %s', $err->getMessage()), 0, $err);
        }
    }

    /**
     * updateARI updates the cert's ACME renewal info, first by checking storage for a newer
     * one, or getting it from the CA if needed. The updated info is stored in storage and
     * updated in the cache. The certificate with the updated ARI is returned. If true is
     * returned, the ARI window or selected time has changed, and the caller should check if
     * the cert needs to be renewed now, even if there is an error.
     *
     * This will always try to ARI without checking if it needs to be refreshed. Call
     * NeedsRefresh() on the RenewalInfo first, and only call this if that returns true.
     *
     * @return array{0: Certificate, 1: bool, 2: \Throwable|null} updatedCert, changed, err
     */
    public function updateARI(Certificate $cert): array
    {
        $logger = Log::get();
        $logContext = [
            'identifiers' => $cert->names,
            'cert_hash' => $cert->hash,
            'ari_unique_id' => $cert->ari->uniqueIdentifier,
            'cert_expiry' => $cert->leaf !== null ? Rfc3339::encode($cert->leaf->notAfter) : '',
        ];

        $updatedCert = $cert;
        $oldARI = $cert->ari;
        $newARI = new RenewalInfo();
        $err = null;

        // synchronize ARI fetching; see #297
        $lockName = 'ari_' . $cert->ari->uniqueIdentifier;
        try {
            Locks::acquireLock($this->storage(), $lockName);
        } catch (\Throwable $e) {
            return [$cert, false, new \RuntimeException(sprintf('unable to obtain ARI lock: %s', $e->getMessage()), 0, $e)];
        }

        try {
            // see if the stored value has been refreshed already by another instance
            $gotNewARI = false;
            try {
                [$gotNewARI, $newARI] = $this->storageHasNewerARI($cert);
            } catch (\Throwable $e) {
                $err = $e;
            }

            if ($err === null && $gotNewARI) {
                // great, storage has a newer one we can use
                $cached = $this->certCache()->getCertificateByHash($cert->hash);
                if ($cached === null) {
                    // cert is no longer in the cache... why? what's the right thing to do here?
                    $updatedCert = $cert; // return input cert, not an empty one
                    $updatedCert->ari = $newARI; // might as well give it the new ARI for the benefit of our caller, but it won't be updated in the cache or in storage
                    $logger->warning('loaded newer ARI from storage, but certificate is no longer in cache; newer ARI will be returned to caller, but not persisted in the cache', $logContext + [
                        'selected_time' => Rfc3339::encode($newARI->selectedTime),
                        'next_update' => Rfc3339::encode($newARI->retryAfter),
                        'explanation_url' => $newARI->explanationURL,
                    ]);
                    return [$updatedCert, $this->ariChanged($oldARI, $newARI, $logger, $logContext), null];
                }
                $updatedCert = $cached;
                $updatedCert->ari = $newARI;
                $logger->info('reloaded ARI with newer one in storage', $logContext + [
                    'next_refresh' => Rfc3339::encode($newARI->retryAfter),
                    'renewal_time' => Rfc3339::encode($newARI->selectedTime),
                ]);
                return [$updatedCert, $this->ariChanged($oldARI, $newARI, $logger, $logContext), null];
            }

            if ($err !== null) {
                $logger->error('error while checking storage for updated ARI; updating ARI now', $logContext + ['error' => $err->getMessage()]);
            }

            // of the issuers configured, hopefully one of them is the ACME CA we got the cert from
            foreach ($this->issuers ?? [] as $iss) {
                if ($iss instanceof RenewalInfoGetter && $iss->issuerKey() === $cert->issuerKey) {
                    try {
                        $newARI = $iss->getRenewalInfo($cert); // be sure to use existing newARI variable so we can compare against old value in the defer
                        $err = null;
                    } catch (\Throwable $e) {
                        $err = $e;
                        // could be anything, but a common error might simply be the "wrong" ACME CA
                        // (meaning, different from the one that issued the cert, thus the only one
                        // that would have any ARI for it) if multiple ACME CAs are configured
                        $logger->error('failed updating renewal info from ACME CA', $logContext + [
                            'issuer' => $iss->issuerKey(),
                            'error' => $e->getMessage(),
                        ]);
                        continue;
                    }

                    // when we get the latest ARI, the acme package will select a time within the window
                    // for us; of course, since it's random, it's likely different from the previously-
                    // selected time; but if the window doesn't change, there's no need to change the
                    // selected time (the acme package doesn't know the previous window to know better)
                    // ... so if the window hasn't changed we'll just put back the selected time
                    if ($newARI->sameWindow($oldARI) && $oldARI->selectedTime !== null) {
                        $newARI->selectedTime = $oldARI->selectedTime;
                    }

                    // then store the updated ARI (even if the window didn't change, the Retry-After
                    // likely did) in cache and storage

                    // be sure we get the cert from the cache while inside a lock to avoid logical races
                    $cached = $this->certCache()->getCertificateByHash($cert->hash);
                    if ($cached === null) {
                        // cert is no longer in the cache; this can happen for several reasons (past expiration,
                        // rejected by on-demand permission module, random eviction due to full cache, etc), but
                        // it probably means we don't have use of this ARI update now, so while we can return it
                        // to the caller, we don't persist it anywhere beyond that...
                        $updatedCert = $cert; // return input cert, not an empty one
                        $updatedCert->ari = $newARI; // might as well give it the new ARI for the benefit of our caller, but it won't be updated in the cache or in storage
                        $logger->warning('obtained ARI update, but certificate no longer in cache; ARI update will be returned to caller, but not stored', $logContext + [
                            'selected_time' => Rfc3339::encode($newARI->selectedTime),
                            'next_update' => Rfc3339::encode($newARI->retryAfter),
                            'explanation_url' => $newARI->explanationURL,
                        ]);
                        return [$updatedCert, $this->ariChanged($oldARI, $newARI, $logger, $logContext), null];
                    }
                    $updatedCert = $cached;
                    $updatedCert->ari = $newARI;

                    // update the ARI value in storage
                    try {
                        $certData = $this->loadStoredACMECertificateMetadata($cert);
                    } catch (\Throwable $e) {
                        $err = new \RuntimeException(sprintf('got new ARI from %s, but failed loading stored certificate metadata: %s', $iss->issuerKey(), $e->getMessage()), 0, $e);
                        return [$updatedCert, $this->ariChanged($oldARI, $newARI, $logger, $logContext), $err];
                    }
                    $certData->renewalInfo = $newARI;
                    try {
                        $certDataBytes = JsonUtil::unmarshal(JsonUtil::marshal($certData));
                    } catch (\Throwable $e) {
                        $err = new \RuntimeException(sprintf('got new ARI from %s, but failed marshaling certificate ACME metadata: %s', $iss->issuerKey(), $e->getMessage()), 0, $e);
                        return [$updatedCert, $this->ariChanged($oldARI, $newARI, $logger, $logContext), $err];
                    }
                    $newRes = new CertificateResource();
                    $newRes->sans = $cert->names;
                    $newRes->issuerData = $certDataBytes;
                    try {
                        $certResBytes = JsonUtil::marshalIndent($newRes, "\t");
                    } catch (\Throwable $e) {
                        $err = new \RuntimeException(sprintf('got new ARI from %s, but could not re-encode certificate metadata: %s', $iss->issuerKey(), $e->getMessage()), 0, $e);
                        return [$updatedCert, $this->ariChanged($oldARI, $newARI, $logger, $logContext), $err];
                    }
                    try {
                        $this->storage()->store(StorageKeys::siteMeta($cert->issuerKey, $cert->names[0]), $certResBytes);
                    } catch (\Throwable $e) {
                        $err = new \RuntimeException(sprintf('got new ARI from %s, but could not store it with certificate metadata: %s', $iss->issuerKey(), $e->getMessage()), 0, $e);
                        return [$updatedCert, $this->ariChanged($oldARI, $newARI, $logger, $logContext), $err];
                    }

                    $logger->info('updated and stored ACME renewal information', $logContext + [
                        'selected_time' => Rfc3339::encode($newARI->selectedTime),
                        'next_update' => Rfc3339::encode($newARI->retryAfter),
                        'explanation_url' => $newARI->explanationURL,
                    ]);

                    return [$updatedCert, $this->ariChanged($oldARI, $newARI, $logger, $logContext), null];
                }
            }

            $err = new \RuntimeException('could not fully update ACME renewal info: either no issuer supporting ARI is configured for certificate, or all such failed (make sure the ACME CA that issued the certificate is configured)');
            return [$updatedCert, $this->ariChanged($oldARI, $newARI, $logger, $logContext), $err];
        } finally {
            try {
                Locks::releaseLock($this->storage(), $lockName);
            } catch (\Throwable $e) {
                $logger->error('unable to release ARI lock', $logContext + ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * ariChanged is the deferred comparison of updateARI: when we're all done,
     * log if something about the schedule is different ("WARN" level because
     * ARI window changing may be a sign of external trouble and we want to
     * draw their attention to a potential explanation URL).
     *
     * @param array<string, mixed> $logContext
     */
    private function ariChanged(RenewalInfo $oldARI, RenewalInfo $newARI, LoggerInterface $logger, array $logContext): bool
    {
        $changed = !$newARI->sameWindow($oldARI);

        if ($changed) {
            $logger->warning('ARI window or selected renewal time changed', $logContext + [
                'prev_start' => Rfc3339::encode($oldARI->suggestedWindowStart),
                'next_start' => Rfc3339::encode($newARI->suggestedWindowStart),
                'prev_end' => Rfc3339::encode($oldARI->suggestedWindowEnd),
                'next_end' => Rfc3339::encode($newARI->suggestedWindowEnd),
                'prev_selected_time' => Rfc3339::encode($oldARI->selectedTime),
                'next_selected_time' => Rfc3339::encode($newARI->selectedTime),
                'explanation_url' => $newARI->explanationURL,
            ]);
        }
        return $changed;
    }

    /**
     * forceRenew forcefully renews cert and replaces it in the cache, and returns the new certificate. It is intended
     * for use primarily in the case of cert revocation. This MUST NOT be called within a lock on cfg.certCacheMu.
     *
     * (PHP port: Go derives `revoked` and the key-compromise reason from the
     * OCSP response; the caller passes them.)
     *
     * @throws \RuntimeException
     */
    public function forceRenew(Certificate $cert, bool $revoked = false, bool $keyCompromised = false): Certificate
    {
        $logger = Log::get();
        if ($revoked) {
            $logger->warning('OCSP status for managed certificate is REVOKED; attempting to replace with new certificate', [
                'identifiers' => $cert->names,
                'expiration' => Rfc3339::encode(CertMagic::expiresAt($cert->leaf)),
            ]);
        } else {
            $logger->warning('forcefully renewing certificate', [
                'identifiers' => $cert->names,
                'expiration' => Rfc3339::encode(CertMagic::expiresAt($cert->leaf)),
            ]);
        }

        $renewName = $cert->names[0];

        // if revoked for key compromise, we can't be sure whether the storage of
        // the key is still safe; however, we KNOW the old key is not safe, and we
        // can only hope by the time of revocation that storage has been secured;
        // key management is not something we want to get into, but in this case
        // it seems prudent to replace the key - and since renewal requires reuse
        // of a prior key, we can't do a "renew" to replace the cert if we need a
        // new key, so we'll have to do an obtain instead
        $obtainInsteadOfRenew = false;
        if ($keyCompromised) {
            try {
                $this->moveCompromisedPrivateKey($cert);
            } catch (\Throwable $err) {
                $logger->error('could not remove compromised private key from use', [
                    'identifiers' => $cert->names,
                    'issuer' => $cert->issuerKey,
                    'error' => $err->getMessage(),
                ]);
            }
            $obtainInsteadOfRenew = true;
        }

        try {
            if ($obtainInsteadOfRenew) {
                $this->obtainCertAsync($renewName);
            } else {
                // notice that we force renewal; otherwise, it might see that the
                // certificate isn't close to expiring and return, but we really
                // need a replacement certificate! see issue #4191
                $this->renewCertAsync($renewName, true);
            }
        } catch (\Throwable $err) {
            if ($revoked) {
                // probably better to not serve a revoked certificate at all
                $logger->error('unable to obtain new to certificate after OCSP status of REVOKED; removing from cache', [
                    'identifiers' => $cert->names,
                    'error' => $err->getMessage(),
                ]);
                $this->certCache()->remove([$cert->hash]);
            }
            throw new \RuntimeException(sprintf('unable to forcefully get new certificate for [%s]: %s', implode(' ', $cert->names), $err->getMessage()), 0, $err);
        }

        return $this->reloadManagedCertificate($cert);
    }

    /**
     * moveCompromisedPrivateKey moves the private key for cert to a ".compromised" file
     * by copying the data to the new file, then deleting the old one.
     *
     * @throws \RuntimeException
     */
    private function moveCompromisedPrivateKey(Certificate $cert): void
    {
        $privKeyStorageKey = StorageKeys::sitePrivateKey($cert->issuerKey, $cert->names[0]);

        $privKeyPEM = $this->storage()->load($privKeyStorageKey);

        $compromisedPrivKeyStorageKey = $privKeyStorageKey . '.compromised';
        try {
            $this->storage()->store($compromisedPrivKeyStorageKey, $privKeyPEM);
        } catch (\Throwable $err) {
            // better safe than sorry: as a last resort, try deleting the key so it won't be reused
            try {
                $this->storage()->delete($privKeyStorageKey);
            } catch (\Throwable) {
                // ignored, as in Go
            }
            throw $err;
        }

        $this->storage()->delete($privKeyStorageKey);

        Log::get()->info("removed certificate's compromised private key from use", [
            'storage_path' => $compromisedPrivKeyStorageKey,
            'identifiers' => $cert->names,
            'issuer' => $cert->issuerKey,
        ]);
    }

    // ---- storage.go helpers -------------------------------------------------

    /**
     * storeTx stores all the values or none at all.
     *
     * @param array<string, string> $all key → value
     * @throws \RuntimeException
     */
    public static function storeTx(Storage $s, array $all): void
    {
        $stored = [];
        foreach ($all as $key => $value) {
            try {
                $s->store((string) $key, $value);
            } catch (\Throwable $err) {
                foreach (array_reverse($stored) as $storedKey) {
                    try {
                        $s->delete($storedKey);
                    } catch (\Throwable) {
                        // best effort rollback
                    }
                }
                throw $err;
            }
            $stored[] = (string) $key;
        }
    }

    /**
     * isNotExist reports whether the error (or any error it wraps) is the
     * storage's "does not exist" error (Go: errors.Is(err, fs.ErrNotExist)).
     */
    public static function isNotExist(?\Throwable $err): bool
    {
        while ($err !== null) {
            if ($err instanceof ErrNotExist) {
                return true;
            }
            $err = $err->getPrevious();
        }
        return false;
    }

    /**
     * marshalMetadata encodes an issuer's metadata for the certificate sidecar
     * file (Go: json.Marshal(issuedCert.Metadata) into json.RawMessage).
     *
     * @return array<string, mixed>|null
     * @throws \RuntimeException
     */
    private static function marshalMetadata(mixed $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }
        $decoded = json_decode(JsonUtil::marshal($metadata), true);
        return \is_array($decoded) ? $decoded : null;
    }

    /** storage returns the configured storage (never null on a valid config). */
    public function storage(): Storage
    {
        if ($this->storage === null) {
            throw new \LogicException('config has no storage; use CertMagic::new() or CertMagic::newDefault() to obtain a valid Config');
        }
        return $this->storage;
    }

    /** certCache returns the certificate cache (never null on a valid config). */
    public function certCache(): Cache
    {
        if ($this->certCache === null) {
            throw new \LogicException('config has no certificate cache; use CertMagic::new() or CertMagic::newDefault() to obtain a valid Config');
        }
        return $this->certCache;
    }

    /** @return callable(): \OpenSSLAsymmetricKey */
    private function keySource(): callable
    {
        return $this->keySource ?? self::defaultKeyGenerator();
    }
}
