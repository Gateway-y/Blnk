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
use Blnk\Cmd\CertMagic\Acme\Account;
use Blnk\Cmd\CertMagic\Acme\Challenge;
use Blnk\Cmd\CertMagic\Acme\Client as AcmeClient;
use Blnk\Cmd\CertMagic\Acme\EAB;
use Blnk\Cmd\CertMagic\Acme\Problem;
use Blnk\Cmd\CertMagic\Acme\RenewalInfo;
use Blnk\Cmd\CertMagic\Acmez\Client as AcmezClient;
use Blnk\Cmd\CertMagic\Acmez\OrderParameters;
use Blnk\Cmd\CertMagic\Acmez\Solver;
use Blnk\Cmd\CertMagic\Solvers\DistributedSolver;
use Blnk\Cmd\CertMagic\Solvers\HttpSolver;
use Blnk\Cmd\CertMagic\Solvers\SolverRegistry;
use Blnk\Cmd\CertMagic\Solvers\SolverWrapper;
use Blnk\Cmd\CertMagic\Solvers\TlsAlpnSolver;
use Blnk\Internal\Log;
use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * ACMEIssuer gets certificates using ACME. It implements the PreChecker,
 * Issuer, and Revoker interfaces.
 *
 * It is NOT VALID to use an ACMEIssuer without calling NewACMEIssuer().
 * It fills in any default values from DefaultACME as well as setting up
 * internal state that is necessary for valid use. Always call
 * NewACMEIssuer() to get a valid ACMEIssuer value.
 *
 * (certmagic acmeissuer.go, acmeclient.go, account.go and httphandlers.go.)
 */
final class ACMEIssuer implements PreChecker, Issuer, Revoker, RenewalInfoGetter
{
    /**
     * Some well-known CA endpoints available to use. See
     * the documentation for each service; some may require
     * External Account Binding (EAB) and possibly payment.
     * COMPATIBILITY NOTICE: These constants refer to external
     * resources and are thus subject to change or removal
     * without a major version bump.
     */
    public const LetsEncryptStagingCA = 'https://acme-staging-v02.api.letsencrypt.org/directory'; // https://letsencrypt.org/docs/staging-environment/
    public const LetsEncryptProductionCA = 'https://acme-v02.api.letsencrypt.org/directory'; // https://letsencrypt.org/getting-started/
    public const ZeroSSLProductionCA = 'https://acme.zerossl.com/v2/DV90'; // https://zerossl.com/documentation/acme/
    public const GoogleTrustStagingCA = 'https://dv.acme-v02.test-api.pki.goog/directory'; // https://cloud.google.com/certificate-manager/docs/public-ca-tutorial
    public const GoogleTrustProductionCA = 'https://dv.acme-v02.api.pki.goog/directory'; // https://cloud.google.com/certificate-manager/docs/public-ca-tutorial

    /** prefixACME is the storage key prefix used for ACME-specific assets. */
    public const prefixACME = 'acme';

    /**
     * The name of the folder for accounts where the email
     * address was not provided; default 'username' if you will,
     * but only for local/storage use, not with the CA.
     */
    public const emptyEmail = 'default';

    public const acmeHTTPChallengeBasePath = '/.well-known/acme-challenge';

    /**
     * These internal rate limits are designed to prevent accidentally
     * firehosing a CA's ACME endpoints. They are not intended to
     * replace or replicate the CA's actual rate limits.
     *
     * Let's Encrypt's rate limits can be found here:
     * https://letsencrypt.org/docs/rate-limits/
     *
     * Currently (as of December 2019), Let's Encrypt's most relevant
     * rate limit for large deployments is 300 new orders per account
     * per 3 hours (on average, or best case, that's about 1 every 36
     * seconds, or 2 every 72 seconds, etc.); but it's not reasonable
     * to try to assume that our internal state is the same as the CA's
     * (due to process restarts, config changes, failed validations,
     * etc.) and ultimately, only the CA's actual rate limiter is the
     * authority. Thus, our own rate limiters do not attempt to enforce
     * external rate limits. Doing so causes problems when the domains
     * are not in our control (i.e. serving customer sites) and/or lots
     * of domains fail validation: they clog our internal rate limiter
     * and nearly starve out (or at least slow down) the other domains
     * that need certificates. Failed transactions are already retried
     * with exponential backoff, so adding in rate limiting can slow
     * things down even more.
     *
     * Instead, the point of our internal rate limiter is to avoid
     * hammering the CA's endpoint when there are thousands or even
     * millions of certificates under management. Our goal is to
     * allow small bursts in a relatively short timeframe so as to
     * not block any one domain for too long, without unleashing
     * thousands of requests to the CA at once.
     */
    /** RateLimitEvents is how many new events can be allowed in RateLimitEventsWindow. */
    public static int $RateLimitEvents = 10;

    /** RateLimitEventsWindow is the size of the sliding window that throttles events (seconds). */
    public static float $RateLimitEventsWindow = 10.0;

    /** Some default values passed down to the underlying ACME client. */
    public static string $UserAgent = '';

    public static float $HTTPTimeout = 30.0;

    /** @var array<string, RateLimiter> keyed by CA + account email */
    private static array $rateLimiters = [];

    /**
     * When an email address is not explicitly specified, we can remember
     * the last one we discovered to avoid having to ask again later.
     * (We used to store this in DefaultACME.Email but it was racey; see #127)
     */
    private static string $discoveredEmail = '';

    /**
     * stdin is used to read the user's input if prompted;
     * this is changed by tests during tests.
     *
     * @var resource|null
     */
    public static $stdin = null;

    /**
     * The endpoint of the directory for the ACME
     * CA we are to use
     */
    public string $ca = '';

    /**
     * TestCA is the endpoint of the directory for
     * an ACME CA to use to test domain validation,
     * but any certs obtained from this CA are
     * discarded; it should perform real and valid
     * ACME verifications, but probably should not
     * issue real, publicly-trusted certificates
     */
    public string $testCA = '';

    /**
     * The email address to use when creating or
     * selecting an existing ACME server account
     */
    public string $email = '';

    /**
     * The PEM-encoded private key of the ACME
     * account to use; only needed if the account
     * is already created on the server and
     * can be looked up with the ACME protocol
     */
    public string $accountKeyPEM = '';

    /**
     * Set to true if agreed to the CA's
     * subscriber agreement
     */
    public bool $agreed = false;

    /**
     * An optional external account to associate
     * with this ACME account
     */
    public ?EAB $externalAccount = null;

    /**
     * Optionally select an ACME profile offered
     * by the ACME server. The list of supported
     * profile names can be obtained from the ACME
     * server's directory endpoint. For details:
     * https://datatracker.ietf.org/doc/draft-aaron-acme-profiles/
     *
     * (EXPERIMENTAL: Subject to change.)
     */
    public string $profile = '';

    /**
     * Optionally specify the validity period of
     * the certificate(s) here as offsets (seconds) from the
     * approximate time of certificate issuance,
     * but note that not all CAs support this
     * (EXPERIMENTAL: Subject to change)
     */
    public float $notBefore = 0;

    public float $notAfter = 0;

    /** Disable all HTTP challenges */
    public bool $disableHTTPChallenge = false;

    /** Disable all TLS-ALPN challenges */
    public bool $disableTLSALPNChallenge = false;

    /**
     * The host (ONLY the host, not port) to listen
     * on if necessary to start a listener to solve
     * an ACME challenge
     */
    public string $listenHost = '';

    /**
     * The alternate port to use for the ACME HTTP
     * challenge; if non-empty, this port will be
     * used instead of HTTPChallengePort to spin up
     * a listener for the HTTP challenge
     */
    public int $altHTTPPort = 0;

    /**
     * The alternate port to use for the ACME
     * TLS-ALPN challenge; the system must forward
     * TLSALPNChallengePort to this port for
     * challenge to succeed
     */
    public int $altTLSALPNPort = 0;

    /**
     * The solver for the dns-01 challenge;
     * usually this is a DNS01Solver value
     * from this package (the PHP port ships no
     * DNS provider; supply your own Solver)
     */
    public ?Solver $dns01Solver = null;

    /**
     * TrustedRoots specifies a pool of root CA
     * certificates to trust when communicating
     * over a network to a peer (PHP port: the path
     * of a PEM bundle handed to the HTTP client).
     */
    public ?string $trustedRoots = null;

    /**
     * The maximum amount of time (seconds) to allow for
     * obtaining a certificate. If empty, the
     * default from the underlying ACME lib is
     * used. If set, it must not be too low so
     * as to cancel challenges too early.
     */
    public float $certObtainTimeout = 0;

    /**
     * Address of custom DNS resolver to be used
     * when communicating with ACME server
     *
     * (PHP port: ext-curl resolves through the system resolver; a custom
     * resolver address is accepted for configuration compatibility but
     * not applied.)
     */
    public string $resolver = '';

    /**
     * Callback function that is called before a
     * new ACME account is registered with the CA;
     * it allows for last-second config changes
     * of the ACMEIssuer and the Account.
     * (TODO: this feature is still EXPERIMENTAL and subject to change)
     *
     * @var callable(ACMEIssuer, Account): Account|null
     */
    public $newAccountFunc = null;

    /**
     * Preferences for selecting alternate
     * certificate chains
     */
    public ChainPreference $preferredChains;

    /**
     * Set a http proxy to use when issuing a certificate.
     * Default is http.ProxyFromEnvironment (Guzzle honors
     * HTTP_PROXY/HTTPS_PROXY/NO_PROXY when this is null).
     */
    public ?string $httpProxy = null;

    private ?Config $config = null;

    private ?GuzzleClient $httpClient = null;

    /**
     * Some fields are changed on-the-fly during
     * certificate management. For example, the
     * email might be implicitly discovered if not
     * explicitly configured, and agreement might
     * happen during the flow. Changing the exported
     * fields field is racey (issue #195) so we
     * control unexported fields that we can
     * synchronize properly. (Go: `email`, `agreed`.)
     */
    private string $effectiveEmail = '';

    private bool $effectiveAgreed = false;

    public function __construct()
    {
        $this->preferredChains = new ChainPreference();
    }

    /**
     * NewACMEIssuer constructs a valid ACMEIssuer based on a template
     * configuration; any empty values will be filled in by defaults in
     * DefaultACME, and if any required values are still empty, sensible
     * defaults will be used.
     *
     * Typically, you'll create the Config first with New() or NewDefault(),
     * then call NewACMEIssuer(), then assign the return value to the Issuers
     * field of the Config.
     *
     * @throws \LogicException (Go: panic)
     */
    public static function newACMEIssuer(?Config $cfg, ACMEIssuer $template): self
    {
        if ($cfg === null) {
            throw new \LogicException('cannot make valid ACMEIssuer without an associated CertMagic config');
        }
        $defaultACME = CertMagic::defaultACME();
        $template = clone $template;
        $template->preferredChains = clone $template->preferredChains;

        if ($template->ca === '') {
            $template->ca = $defaultACME->ca;
        }
        if ($template->testCA === '' && $template->ca === $defaultACME->ca) {
            // only use the default test CA if the CA is also
            // the default CA; no point in testing against
            // Let's Encrypt's staging server if we are not
            // using their production server too
            $template->testCA = $defaultACME->testCA;
        }
        if ($template->email === '') {
            $template->email = $defaultACME->email;
        }
        if ($template->accountKeyPEM === '') {
            $template->accountKeyPEM = $defaultACME->accountKeyPEM;
        }
        if (!$template->agreed) {
            $template->agreed = $defaultACME->agreed;
        }
        if ($template->externalAccount === null) {
            $template->externalAccount = $defaultACME->externalAccount;
        }
        if ($template->notBefore == 0) {
            $template->notBefore = $defaultACME->notBefore;
        }
        if ($template->notAfter == 0) {
            $template->notAfter = $defaultACME->notAfter;
        }
        if (!$template->disableHTTPChallenge) {
            $template->disableHTTPChallenge = $defaultACME->disableHTTPChallenge;
        }
        if (!$template->disableTLSALPNChallenge) {
            $template->disableTLSALPNChallenge = $defaultACME->disableTLSALPNChallenge;
        }
        if ($template->listenHost === '') {
            $template->listenHost = $defaultACME->listenHost;
        }
        if ($template->altHTTPPort === 0) {
            $template->altHTTPPort = $defaultACME->altHTTPPort;
        }
        if ($template->altTLSALPNPort === 0) {
            $template->altTLSALPNPort = $defaultACME->altTLSALPNPort;
        }
        if ($template->dns01Solver === null) {
            $template->dns01Solver = $defaultACME->dns01Solver;
        }
        if ($template->trustedRoots === null) {
            $template->trustedRoots = $defaultACME->trustedRoots;
        }
        if ($template->certObtainTimeout == 0) {
            $template->certObtainTimeout = $defaultACME->certObtainTimeout;
        }
        if ($template->resolver === '') {
            $template->resolver = $defaultACME->resolver;
        }
        if ($template->newAccountFunc === null) {
            $template->newAccountFunc = $defaultACME->newAccountFunc;
        }
        if ($template->httpProxy === null) {
            $template->httpProxy = $defaultACME->httpProxy;
        }

        $template->config = $cfg;

        // set up the dialer and transport / HTTP client
        $options = [
            'timeout' => self::$HTTPTimeout,
            'connect_timeout' => 30.0, // dialer Timeout: 30 * time.Second
            'http_errors' => false,
        ];
        if ($template->trustedRoots !== null && $template->trustedRoots !== '') {
            $options['verify'] = $template->trustedRoots;
        }
        if ($template->httpProxy !== null && $template->httpProxy !== '') {
            $options['proxy'] = $template->httpProxy;
        }
        $template->httpClient = new GuzzleClient($options);

        return $template;
    }

    /** config returns the CertMagic config this issuer is bound to (null before NewACMEIssuer). */
    public function config(): ?Config
    {
        return $this->config;
    }

    /** bind associates the issuer with the config that owns it (Go: `template.config = cfg`). */
    public function bind(Config $cfg): void
    {
        $this->config = $cfg;
    }

    /**
     * IssuerKey returns the unique issuer key for the
     * configured CA endpoint.
     */
    public function issuerKey(): string
    {
        return self::issuerKeyOf($this->ca);
    }

    /** issuerKeyOf is the unexported `issuerKey(ca)`: the CA URL's host plus its path as one component. */
    public static function issuerKeyOf(string $ca): string
    {
        $key = $ca;
        $parts = parse_url($key);
        if (\is_array($parts) && isset($parts['host'])) {
            $key = $parts['host'];
            if (isset($parts['port'])) {
                $key .= ':' . $parts['port'];
            }
            if (($parts['path'] ?? '') !== '') {
                // keep the path, but make sure it's a single
                // component (i.e. no forward slashes, and for
                // good measure, no backward slashes either)
                $hyphen = '-';
                $path = trim(strtr((string) $parts['path'], ['/' => $hyphen, '\\' => $hyphen]), $hyphen);
                if ($path !== '') {
                    $key .= $hyphen . $path;
                }
            }
        }
        return $key;
    }

    private function getEmail(): string
    {
        return $this->effectiveEmail;
    }

    private function isAgreed(): bool
    {
        return $this->effectiveAgreed;
    }

    /**
     * PreCheck performs a few simple checks before obtaining or
     * renewing a certificate with ACME, and returns whether this
     * batch is eligible for certificates. It also ensures that an
     * email address is available if possible.
     *
     * IP certificates via ACME are defined in RFC 8738.
     *
     * @param string[] $names
     * @throws \RuntimeException
     */
    public function preCheck(array $names, bool $interactive): void
    {
        $publicCAsAndIPCerts = [ // map of public CAs to whether they support IP certificates (last updated: Q1 2024)
            'api.letsencrypt.org' => false, // https://community.letsencrypt.org/t/certificate-for-static-ip/84/2?u=mholt
            'acme.zerossl.com' => false, // only supported via their API, not ACME endpoint
            'api.pki.goog' => true, // https://pki.goog/faq/#faq-IPCerts
            'api.buypass.com' => false, // https://community.buypass.com/t/h7hm76w/buypass-support-for-rfc-8738
            'acme.ssl.com' => false,
        ];
        $publicCA = false;
        $ipCertAllowed = false;
        foreach ($publicCAsAndIPCerts as $caSubstr => $ipCert) {
            if (str_contains($this->ca, $caSubstr)) {
                $publicCA = true;
                $ipCertAllowed = $ipCert;
                break;
            }
        }
        if ($publicCA) {
            foreach ($names as $name) {
                if (!CertMagic::subjectQualifiesForPublicCert($name)) {
                    throw new \RuntimeException(sprintf("subject '%s' does not qualify for a public certificate", $name));
                }
                if (!$ipCertAllowed && CertMagic::subjectIsIP($name)) {
                    throw new \RuntimeException(sprintf("subject '%s' cannot have public IP certificate from %s (if CA's policy has changed, please notify the developers in an issue)", $name, $this->ca));
                }
            }
        }
        $this->setEmail($interactive);
    }

    /**
     * Issue implements the Issuer interface. It obtains a certificate for the given csr using
     * the ACME configuration am.
     *
     * @throws \RuntimeException
     */
    public function issue(CertificateRequest $csr, IssueContext $ctx): IssuedCertificate
    {
        if ($this->config === null) {
            throw new \LogicException('missing config pointer (must use NewACMEIssuer)');
        }

        $attempts = $ctx->attempts;
        $isRetry = $attempts > 0;

        [$cert, $usedTestCA] = $this->doIssue($csr, $attempts, $ctx->ariReplaces);

        // important to note that usedTestCA is not necessarily the same as isRetry
        // (usedTestCA can be true if the main CA and the test CA happen to be the same)
        if ($isRetry && $usedTestCA && $this->ca !== $this->testCA) {
            // succeeded with testing endpoint, so try again with production endpoint
            // (only if the production endpoint is different from the testing endpoint)
            // TODO: This logic is imperfect and could benefit from some refinement.
            // The two CA endpoints likely have different states, which could cause one
            // to succeed and the other to fail, even if it's not a validation error.
            // Two common cases would be:
            // 1) Rate limiter state. This is more likely to cause prod to fail while
            // staging succeeds, since prod usually has tighter rate limits. Thus, if
            // initial attempt failed in prod due to rate limit, first retry (on staging)
            // might succeed, and then trying prod again right way would probably still
            // fail; normally this would terminate retries but the right thing to do in
            // this case is to back off and retry again later. We could refine this logic
            // to stick with the production endpoint on retries unless the error changes.
            // 2) Cached authorizations state. If a domain validates successfully with
            // one endpoint, but then the other endpoint is used, it might fail, e.g. if
            // DNS was just changed or is still propagating. In this case, the second CA
            // should continue to be retried with backoff, without switching back to the
            // other endpoint. This is more likely to happen if a user is testing with
            // the staging CA as the main CA, then changes their configuration once they
            // think they are ready for the production endpoint.
            try {
                [$cert] = $this->doIssue($csr, 0, $ctx->ariReplaces);
            } catch (\Throwable $err) {
                // succeeded with test CA but failed just now with the production CA;
                // either we are observing differing internal states of each CA that will
                // work out with time, or there is a bug/misconfiguration somewhere
                // externally; it is hard to tell which! one easy cue is whether the
                // error is specifically a 429 (Too Many Requests); if so, we should
                // probably keep retrying
                $problem = Problem::from($err);
                if ($problem !== null && $problem->status === 429) {
                    // DON'T abort retries; the test CA succeeded (even
                    // if it's cached, it recently succeeded!) so we just
                    // need to keep trying (with backoff) until this CA's
                    // rate limits expire...
                    // TODO: as mentioned in comment above, we would benefit
                    // by pinning the main CA at this point instead of
                    // needlessly retrying with the test CA first each time
                    throw $err;
                }
                throw new ErrNoRetry($err);
            }
        }

        return $cert;
    }

    /**
     * @return array{0: IssuedCertificate, 1: bool} the certificate and whether the test CA was used
     * @throws \RuntimeException
     */
    private function doIssue(CertificateRequest $csr, int $attempts, ?X509Certificate $ariReplaces): array
    {
        $useTestCA = $attempts > 0;
        $client = $this->newACMEClientWithAccount($useTestCA, false);
        $usingTestCA = $client->usingTestCA();

        $nameSet = Crypto::namesFromCSR($csr);

        if (!$useTestCA) {
            $client->throttle($nameSet);
        }

        try {
            $params = OrderParameters::orderParametersFromCSR($client->account, $csr);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('generating order parameters from CSR: %s', $err->getMessage()), 0, $err);
        }
        if ($this->notBefore != 0) {
            $params->notBefore = Rfc3339::fromSeconds((int) (time() + $this->notBefore));
        }
        if ($this->notAfter != 0) {
            $params->notAfter = Rfc3339::fromSeconds((int) (time() + $this->notAfter));
        }
        $params->profile = $this->profile;

        // Notify the ACME server we are replacing a certificate (if the caller says we are),
        // only if the following conditions are met:
        // - The caller has set a Replaces value in the context, indicating this is a renewal.
        // - Not using test CA. This should be obvious, but a test CA should be in a separate
        // environment from production, and thus not have knowledge of the cert being replaced.
        // - Not a certain attempt number. We skip setting Replaces once early on in the retries
        // in case the reason the order is failing is only because there is a state inconsistency
        // between client and server or some sort of bookkeeping error with regards to the certID
        // and the server is rejecting the ARI certID. In any case, an invalid certID may cause
        // orders to fail. So try once without setting it.
        if (!$this->config()->disableARI && !$usingTestCA && $attempts !== 2 && $ariReplaces !== null) {
            $params->replaces = $ariReplaces;
        }

        // do this in a loop because there's an error case that may necessitate a retry, but not more than once
        $certChains = [];
        for ($i = 0; $i < 2; $i++) {
            Log::get()->info('using ACME account', [
                'account_id' => $params->account->location,
                'account_contact' => $params->account->contact,
            ]);

            try {
                $certChains = $client->acmeClient->obtainCertificate($params);
            } catch (\Throwable $err) {
                $prob = Problem::from($err);
                if ($prob !== null && $prob->type === Problem::ProblemTypeAccountDoesNotExist) {
                    Log::get()->warning('ACME account does not exist on server; attempting to recreate', [
                        'account_id' => $client->account->location,
                        'account_contact' => $client->account->contact,
                        'key_location' => $this->storageKeyUserPrivateKey($client->acmeClient->client->directory, $this->getEmail()),
                        'problem' => $prob->toArray(),
                    ]);

                    // the account we have no longer exists on the CA, so we need to create a new one;
                    // we could use the same key pair, but this is a good opportunity to rotate keys
                    // (see https://caddy.community/t/acme-account-is-not-regenerated-when-acme-server-gets-reinstalled/22627)
                    // (basically this happens if the CA gets reset or reinstalled; usually just internal PKI)
                    try {
                        $this->deleteAccountLocally($client->iss->ca, $client->account);
                    } catch (\Throwable $delErr) {
                        throw new \RuntimeException(sprintf('[%s] ACME account no longer exists on CA, but resetting our local copy of the account info failed: %s', implode(' ', $nameSet), $delErr->getMessage()), 0, $delErr);
                    }

                    // recreate account and try again
                    $client = $this->newACMEClientWithAccount($useTestCA, false);
                    $params->account = $client->account;
                    continue;
                }
                throw new \RuntimeException(sprintf('[%s] %s (ca=%s)', implode(' ', $nameSet), $err->getMessage(), $client->acmeClient->client->directory), 0, $err);
            }
            if ($certChains === []) {
                throw new \RuntimeException('no certificate chains');
            }
            break;
        }

        $preferredChain = $this->selectPreferredChain($certChains);

        $ic = new IssuedCertificate($preferredChain->chainPEM, $preferredChain);

        Log::get()->debug('selected certificate chain', ['url' => $preferredChain->url]);

        return [$ic, $usingTestCA];
    }

    /**
     * selectPreferredChain sorts and then filters the certificate chains to find the optimal
     * chain preferred by the client. If there's only one chain, that is returned without any
     * processing. If there are no matches, the first chain is returned.
     *
     * @param AcmeCertificate[] $certChains
     */
    private function selectPreferredChain(array $certChains): AcmeCertificate
    {
        $certChains = array_values($certChains);
        if (\count($certChains) === 1) {
            if ($this->preferredChains->anyCommonName !== [] || $this->preferredChains->rootCommonName !== []) {
                Log::get()->debug('there is only one chain offered; selecting it regardless of preferences', ['chain_url' => $certChains[0]->url]);
            }
            return $certChains[0];
        }

        if ($this->preferredChains->smallest !== null) {
            if ($this->preferredChains->smallest) {
                usort($certChains, static fn (AcmeCertificate $a, AcmeCertificate $b): int => \strlen($a->chainPEM) <=> \strlen($b->chainPEM));
            } else {
                usort($certChains, static fn (AcmeCertificate $a, AcmeCertificate $b): int => \strlen($b->chainPEM) <=> \strlen($a->chainPEM));
            }
        }

        if ($this->preferredChains->anyCommonName !== [] || $this->preferredChains->rootCommonName !== []) {
            // in order to inspect, we need to decode their PEM contents
            /** @var array<int, X509Certificate[]> $decodedChains */
            $decodedChains = [];
            foreach ($certChains as $i => $chain) {
                try {
                    $decodedChains[$i] = Crypto::parseCertsFromPEMBundle($chain->chainPEM);
                } catch (\Throwable $err) {
                    Log::get()->error('unable to parse PEM certificate chain', ['chain' => $i, 'error' => $err->getMessage()]);
                    $decodedChains[$i] = [];
                    continue;
                }
            }

            if ($this->preferredChains->anyCommonName !== []) {
                foreach ($this->preferredChains->anyCommonName as $prefAnyCN) {
                    foreach ($decodedChains as $i => $chain) {
                        foreach ($chain as $cert) {
                            if ($cert->issuerCommonName === $prefAnyCN) {
                                Log::get()->debug('found preferred certificate chain by issuer common name', ['preference' => $prefAnyCN, 'chain' => $i]);
                                return $certChains[$i];
                            }
                        }
                    }
                }
            }

            if ($this->preferredChains->rootCommonName !== []) {
                foreach ($this->preferredChains->rootCommonName as $prefRootCN) {
                    foreach ($decodedChains as $i => $chain) {
                        if ($chain !== [] && $chain[\count($chain) - 1]->issuerCommonName === $prefRootCN) {
                            Log::get()->debug('found preferred certificate chain by root common name', ['preference' => $prefRootCN, 'chain' => $i]);
                            return $certChains[$i];
                        }
                    }
                }
            }

            Log::get()->warning('did not find chain matching preferences; using first');
        }

        return $certChains[0];
    }

    /**
     * Revoke implements the Revoker interface. It revokes the given certificate.
     *
     * @throws \RuntimeException
     */
    public function revoke(CertificateResource $cert, int $reason): void
    {
        $client = $this->newACMEClientWithAccount(false, false);

        $certs = Crypto::parseCertsFromPEMBundle($cert->certificatePEM);

        $client->revoke($certs[0], $reason);
    }

    // ---- acmeclient.go ------------------------------------------------------

    /**
     * newACMEClientWithAccount creates an ACME client ready to use with an account, including
     * loading one from storage or registering a new account with the CA if necessary. If
     * useTestCA is true, am.TestCA will be used if set; otherwise, the primary CA will be used.
     *
     * @throws \RuntimeException
     */
    public function newACMEClientWithAccount(bool $useTestCA, bool $interactive): AcmeClientWithAccount
    {
        // first, get underlying ACME client
        $client = $this->newACMEClient($useTestCA);

        // we try loading the account from storage before a potential
        // lock, and after obtaining the lock as well, to ensure we don't
        // repeat work done by another instance or goroutine
        $getAccount = function () use ($client): Account {
            // look up or create the ACME account
            try {
                if ($this->accountKeyPEM !== '') {
                    Log::get()->info('using configured ACME account');
                    return $this->getAccountWithKey($this->accountKeyPEM);
                }
                return $this->getAccount($client->client->directory, $this->getEmail());
            } catch (\Throwable $err) {
                throw new \RuntimeException(sprintf('getting ACME account: %s', $err->getMessage()), 0, $err);
            }
        };

        // first try getting the account
        $account = $getAccount();

        // register account if it is new
        if ($account->status === '') {
            Log::get()->info('ACME account has empty status; registering account with ACME server', [
                'contact' => $account->contact,
                'location' => $account->location,
            ]);

            // synchronize this so the account is only created once
            $acctLockKey = self::accountRegLockKey($account);
            try {
                Locks::acquireLock($this->config()->storage(), $acctLockKey);
            } catch (\Throwable $err) {
                throw new \RuntimeException(sprintf('locking account registration: %s', $err->getMessage()), 0, $err);
            }
            try {
                // if we're not the only one waiting for this account, then by this point it should already be registered and in storage; reload it
                $account = $getAccount();

                // if we are the only or first one waiting for this account, then proceed to register it while we have the lock
                if ($account->status === '') {
                    if ($this->newAccountFunc !== null) {
                        // obtain lock here, since NewAccountFunc calls happen concurrently and they typically read and change the issuer
                        try {
                            $account = ($this->newAccountFunc)($this, $account);
                        } catch (\Throwable $err) {
                            throw new \RuntimeException(sprintf('account pre-registration callback: %s', $err->getMessage()), 0, $err);
                        }
                    }

                    // agree to terms
                    if ($interactive) {
                        if (!$this->isAgreed()) {
                            $termsURL = '';
                            try {
                                $dir = $client->client->getDirectory();
                            } catch (\Throwable $err) {
                                throw new \RuntimeException(sprintf('getting directory: %s', $err->getMessage()), 0, $err);
                            }
                            if ($dir->meta !== null) {
                                $termsURL = $dir->meta->termsOfService;
                            }
                            if ($termsURL !== '') {
                                $agreed = $this->askUserAgreement($termsURL);
                                if (!$agreed) {
                                    throw new \RuntimeException('user must agree to CA terms');
                                }
                                $this->effectiveAgreed = $agreed;
                            }
                        }
                    } else {
                        // can't prompt a user who isn't there; they should
                        // have reviewed the terms beforehand
                        $this->effectiveAgreed = true;
                    }
                    $account->termsOfServiceAgreed = $this->isAgreed();

                    // associate account with external binding, if configured
                    if ($this->externalAccount !== null) {
                        $account->setExternalAccountBinding($client->client, $this->externalAccount);
                    }

                    // create account
                    try {
                        $account = $client->client->newAccount($account);
                    } catch (\Throwable $err) {
                        throw new \RuntimeException(sprintf('registering account [%s] with server: %s', implode(' ', $account->contact), $err->getMessage()), 0, $err);
                    }
                    Log::get()->info('new ACME account registered', [
                        'contact' => $account->contact,
                        'status' => $account->status,
                    ]);

                    // persist the account to storage
                    try {
                        $this->saveAccount($client->client->directory, $account);
                    } catch (\Throwable $err) {
                        throw new \RuntimeException(sprintf('could not save account [%s]: %s', implode(' ', $account->contact), $err->getMessage()), 0, $err);
                    }
                } else {
                    Log::get()->info('account has already been registered; reloaded', [
                        'contact' => $account->contact,
                        'status' => $account->status,
                        'location' => $account->location,
                    ]);
                }
            } finally {
                try {
                    Locks::releaseLock($this->config()->storage(), $acctLockKey);
                } catch (\Throwable $err) {
                    Log::get()->error('failed to unlock account registration lock', ['error' => $err->getMessage()]);
                }
            }
        }

        return new AcmeClientWithAccount($this, $client, $account);
    }

    /**
     * newACMEClient creates a new underlying ACME client using the settings in am,
     * independent of any particular ACME account. If useTestCA is true, am.TestCA
     * will be used if it is set; otherwise, the primary CA will be used.
     *
     * @throws \RuntimeException
     */
    public function newACMEClient(bool $useTestCA): AcmezClient
    {
        $client = $this->newBasicACMEClient();

        // fill in a little more beyond a basic client
        if ($useTestCA && $this->testCA !== '') {
            $client->client->directory = $this->testCA;
        }
        $certObtainTimeout = $this->certObtainTimeout;
        if ($certObtainTimeout == 0) {
            $certObtainTimeout = CertMagic::defaultACME()->certObtainTimeout;
        }
        $client->client->pollTimeout = $certObtainTimeout;
        $client->challengeSolvers = [];

        // configure challenges (most of the time, DNS challenge is
        // exclusive of other ones because it is usually only used
        // in situations where the default challenges would fail)
        if ($this->dns01Solver === null) {
            // enable HTTP-01 challenge
            if (!$this->disableHTTPChallenge) {
                $client->challengeSolvers[Challenge::ChallengeTypeHTTP01] = new DistributedSolver(
                    $this->config()->storage(),
                    $this->storageKeyCAPrefix($client->client->directory),
                    new HttpSolver(
                        $this->httpChallengeHandler(static function (ServerRequestInterface $r): ResponseInterface {
                            // http.NewServeMux(): 404 for everything that is not a challenge
                            $response = (new ResponseFactory())->createResponse(404)
                                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                                ->withHeader('X-Content-Type-Options', 'nosniff');
                            $response->getBody()->write("404 page not found\n");
                            return $response;
                        }),
                        self::joinHostPort($this->listenHost, $this->getHTTPPort())
                    )
                );
            }

            // enable TLS-ALPN-01 challenge
            if (!$this->disableTLSALPNChallenge) {
                $client->challengeSolvers[Challenge::ChallengeTypeTLSALPN01] = new DistributedSolver(
                    $this->config()->storage(),
                    $this->storageKeyCAPrefix($client->client->directory),
                    new TlsAlpnSolver($this->config(), self::joinHostPort($this->listenHost, $this->getTLSALPNPort()))
                );
            }
        } else {
            // use DNS challenge exclusively
            $client->challengeSolvers[Challenge::ChallengeTypeDNS01] = $this->dns01Solver;
        }

        // wrap solvers in our wrapper so that we can keep track of challenge
        // info: this is useful for solving challenges globally as a process;
        // for example, usually there is only one process that can solve the
        // HTTP and TLS-ALPN challenges, and only one server in that process
        // that can bind the necessary port(s), so if a server listening on
        // a different port needed a certificate, it would have to know about
        // the other server listening on that port, and somehow convey its
        // challenge info or share its config, but this isn't always feasible;
        // what the wrapper does is it accesses a global challenge memory so
        // that unrelated servers in this process can all solve each others'
        // challenges without having to know about each other - Caddy's admin
        // endpoint uses this functionality since it and the HTTP/TLS modules
        // do not know about each other
        // (doing this here in a separate loop ensures that even if we expose
        // solver config to users later, we will even wrap their own solvers)
        foreach ($client->challengeSolvers as $name => $solver) {
            $client->challengeSolvers[$name] = new SolverWrapper($solver);
        }

        // PHP port: the challenge listeners are served while the ACME flow
        // waits (Go: goroutines), so install the registry pump as the waiter
        AcmeClient::setWaiter(static function (float $seconds): void {
            SolverRegistry::pump($seconds);
        });

        return $client;
    }

    /**
     * newBasicACMEClient sets up a basically-functional ACME client that is not capable
     * of solving challenges but can provide basic interactions with the server.
     *
     * @throws \RuntimeException
     */
    private function newBasicACMEClient(): AcmezClient
    {
        $caURL = $this->ca;
        if ($caURL === '') {
            $caURL = CertMagic::defaultACME()->ca;
        }
        // ensure endpoint is secure (assume HTTPS if scheme is missing)
        if (!str_contains($caURL, '://')) {
            $caURL = 'https://' . $caURL;
        }
        $u = parse_url($caURL);
        if (!\is_array($u) || !isset($u['host'])) {
            throw new \RuntimeException(sprintf('parse "%s": invalid URL', $caURL));
        }
        $host = $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
        if (($u['scheme'] ?? '') !== 'https' && !CertMagic::subjectIsInternal($host)) {
            throw new \RuntimeException(sprintf('%s: insecure CA URL (HTTPS required for non-internal CA)', $caURL));
        }
        $acme = new AcmeClient();
        $acme->directory = $caURL;
        $acme->userAgent = self::buildUAString();
        $acme->httpClient = $this->httpClient;
        $acme->logger = Log::get();
        return new AcmezClient($acme);
    }

    /**
     * GetRenewalInfo gets the ACME Renewal Information (ARI) for the certificate.
     *
     * @throws \RuntimeException
     */
    public function getRenewalInfo(Certificate $cert): RenewalInfo
    {
        $acmeClient = $this->newBasicACMEClient();
        if ($cert->leaf === null) {
            throw new \RuntimeException('certificate has no leaf');
        }
        return $acmeClient->client->getRenewalInfo($cert->leaf);
    }

    public function getHTTPPort(): int
    {
        $useHTTPPort = CertMagic::HTTPChallengePort;
        if (CertMagic::$HTTPPort > 0 && CertMagic::$HTTPPort !== CertMagic::HTTPChallengePort) {
            $useHTTPPort = CertMagic::$HTTPPort;
        }
        if ($this->altHTTPPort > 0) {
            $useHTTPPort = $this->altHTTPPort;
        }
        return $useHTTPPort;
    }

    public function getTLSALPNPort(): int
    {
        $useTLSALPNPort = CertMagic::TLSALPNChallengePort;
        if (CertMagic::$HTTPSPort > 0 && CertMagic::$HTTPSPort !== CertMagic::TLSALPNChallengePort) {
            $useTLSALPNPort = CertMagic::$HTTPSPort;
        }
        if ($this->altTLSALPNPort > 0) {
            $useTLSALPNPort = $this->altTLSALPNPort;
        }
        return $useTLSALPNPort;
    }

    /**
     * throttle waits on the internal rate limiter scoped to CA + account email
     * (Go: `acmeClient.throttle`).
     *
     * @param string[] $names
     */
    public function throttle(string $directory, array $names): void
    {
        $email = $this->getEmail();

        // throttling is scoped to CA + account email
        $rateLimiterKey = $directory . ',' . $email;
        if (!isset(self::$rateLimiters[$rateLimiterKey])) {
            self::$rateLimiters[$rateLimiterKey] = RateLimiter::newRateLimiter(self::$RateLimitEvents, self::$RateLimitEventsWindow);
            // TODO: stop rate limiter when it is garbage-collected...
        }
        $rl = self::$rateLimiters[$rateLimiterKey];
        Log::get()->info('waiting on internal rate limiter', [
            'identifiers' => $names,
            'ca' => $directory,
            'account' => $email,
        ]);
        $rl->wait();
        Log::get()->info('done waiting on internal rate limiter', [
            'identifiers' => $names,
            'ca' => $directory,
            'account' => $email,
        ]);
    }

    public static function buildUAString(): string
    {
        $ua = 'CertMagic';
        if (self::$UserAgent !== '') {
            $ua = self::$UserAgent . ' ' . $ua;
        }
        return $ua;
    }

    /** joinHostPort is net.JoinHostPort. */
    private static function joinHostPort(string $host, int $port): string
    {
        if (str_contains($host, ':')) {
            return '[' . $host . ']:' . $port;
        }
        return $host . ':' . $port;
    }

    // ---- account.go ---------------------------------------------------------

    /**
     * getAccount either loads or creates a new account, depending on if
     * an account can be found in storage for the given CA + email combo.
     *
     * @throws \RuntimeException
     */
    private function getAccount(string $ca, string $email): Account
    {
        try {
            $acct = $this->loadAccount($ca, $email);
        } catch (\Throwable $err) {
            if (Config::isNotExist($err)) {
                Log::get()->info('creating new account because no account for configured email is known to us', [
                    'email' => $email,
                    'ca' => $ca,
                    'error' => $err->getMessage(),
                ]);
                return self::newAccount($email);
            }
            throw $err;
        }
        Log::get()->debug('using existing ACME account because key found in storage associated with email', [
            'email' => $email,
            'ca' => $ca,
        ]);
        return $acct;
    }

    /**
     * loadAccount loads an account from storage, but does not create a new one.
     *
     * @throws \RuntimeException|ErrNotExist
     */
    private function loadAccount(string $ca, string $email): Account
    {
        $regBytes = $this->config()->storage()->load($this->storageKeyUserReg($ca, $email));
        $keyBytes = $this->config()->storage()->load($this->storageKeyUserPrivateKey($ca, $email));

        $acct = Account::fromArray(JsonUtil::unmarshal($regBytes));
        try {
            $acct->privateKey = Crypto::pemDecodePrivateKey($keyBytes);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf("could not decode account's private key: %s", $err->getMessage()), 0, $err);
        }

        return $acct;
    }

    /**
     * newAccount generates a new private key for a new ACME account, but
     * it does not register or save the account.
     *
     * @throws \RuntimeException
     */
    private static function newAccount(string $email): Account
    {
        $acct = new Account();
        if ($email !== '') {
            $acct->contact = ['mailto:' . $email]; // TODO: should we abstract the contact scheme?
        }
        try {
            $privateKey = Crypto::generateKey(Crypto::P256);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('generating private key: %s', $err->getMessage()), 0, $err);
        }
        $acct->privateKey = $privateKey;
        return $acct;
    }

    /**
     * GetAccount first tries loading the account with the associated private key from storage.
     * If it does not exist in storage, it will be retrieved from the ACME server and added to storage.
     * The account must already exist; it does not create a new account.
     *
     * (Go: exported `GetAccount(ctx, privateKeyPEM)`; renamed in the port
     * because the unexported `getAccount(ctx, ca, email)` shares its name.)
     *
     * @throws \RuntimeException
     */
    public function getAccountWithKey(string $privateKeyPEM): Account
    {
        $email = $this->getEmail();
        if ($email === '') {
            try {
                return $this->loadAccountByKey($privateKeyPEM);
            } catch (\Throwable) {
                // fall through to the server lookup
            }
        } else {
            try {
                $keyBytes = $this->config()->storage()->load($this->storageKeyUserPrivateKey($this->ca, $email));
                if (trim($keyBytes) === trim($privateKeyPEM)) {
                    return $this->loadAccount($this->ca, $email);
                }
            } catch (\Throwable) {
                // fall through to the server lookup
            }
        }
        return $this->lookUpAccount($privateKeyPEM);
    }

    /**
     * loadAccountByKey loads the account with the given private key from storage, if it exists.
     * If it does not exist, an error of type fs.ErrNotExist is thrown. This is not very efficient
     * for lots of accounts.
     *
     * @throws \RuntimeException|ErrNotExist
     */
    private function loadAccountByKey(string $privateKeyPEM): Account
    {
        $accountList = $this->config()->storage()->list($this->storageKeyUsersPrefix($this->ca), false);
        foreach ($accountList as $accountFolderKey) {
            $email = basename($accountFolderKey);
            try {
                $keyBytes = $this->config()->storage()->load($this->storageKeyUserPrivateKey($this->ca, $email));
            } catch (\Throwable) {
                // Try the next account: This one is missing its private key, if it turns out to be the one we're looking
                // for we will try to save it again after confirming with the ACME server.
                continue;
            }
            if (trim($keyBytes) === trim($privateKeyPEM)) {
                // Found the account with the correct private key, try loading it. If this fails we we will follow
                // the same procedure as if the private key was not found and confirm with the ACME server before saving
                // it again.
                return $this->loadAccount($this->ca, $email);
            }
        }
        throw new ErrNotExist();
    }

    /**
     * lookUpAccount looks up the account associated with privateKeyPEM from the ACME server.
     * If the account is found by the server, it will be saved to storage and returned.
     *
     * @throws \RuntimeException
     */
    private function lookUpAccount(string $privateKeyPEM): Account
    {
        try {
            $client = $this->newACMEClient(false);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('creating ACME client: %s', $err->getMessage()), 0, $err);
        }

        try {
            $privateKey = Crypto::pemDecodePrivateKey($privateKeyPEM);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('decoding private key: %s', $err->getMessage()), 0, $err);
        }

        // look up the account
        $account = new Account();
        $account->privateKey = $privateKey;
        try {
            $account = $client->client->getAccount($account);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('looking up account with server: %s', $err->getMessage()), 0, $err);
        }

        // save the account details to storage
        try {
            $this->saveAccount($client->client->directory, $account);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('could not save account to storage: %s', $err->getMessage()), 0, $err);
        }

        return $account;
    }

    /**
     * saveAccount persists an ACME account's info and private key to storage.
     * It does NOT register the account via ACME or prompt the user.
     *
     * @throws \RuntimeException
     */
    private function saveAccount(string $ca, Account $account): void
    {
        $regBytes = JsonUtil::marshalIndent($account, "\t");
        if ($account->privateKey === null) {
            throw new \RuntimeException('account has no private key');
        }
        $keyBytes = Crypto::pemEncodePrivateKey($account->privateKey);
        // extract primary contact (email), without scheme (e.g. "mailto:")
        $primaryContact = self::getPrimaryContact($account);
        $all = [
            $this->storageKeyUserReg($ca, $primaryContact) => $regBytes,
            $this->storageKeyUserPrivateKey($ca, $primaryContact) => $keyBytes,
        ];
        Config::storeTx($this->config()->storage(), $all);
    }

    /**
     * deleteAccountLocally deletes the registration info and private key of the account
     * for the given CA from storage.
     *
     * @throws \RuntimeException
     */
    private function deleteAccountLocally(string $ca, Account $account): void
    {
        $primaryContact = self::getPrimaryContact($account);
        $this->config()->storage()->delete($this->storageKeyUserReg($ca, $primaryContact));
        $this->config()->storage()->delete($this->storageKeyUserPrivateKey($ca, $primaryContact));
    }

    /**
     * setEmail does everything it can to obtain an email address
     * from the user within the scope of memory and storage to use
     * for ACME TLS. If it cannot get an email address, it does nothing
     * (If user is prompted, it will warn the user of
     * the consequences of an empty email.) This function MAY prompt
     * the user for input. If allowPrompts is false, the user
     * will NOT be prompted and an empty email may be returned.
     *
     * @throws \RuntimeException
     */
    private function setEmail(bool $allowPrompts): void
    {
        $leEmail = $this->email;

        // First try package default email, or a discovered email address
        if ($leEmail === '') {
            $leEmail = CertMagic::defaultACME()->email;
        }
        if ($leEmail === '') {
            $leEmail = self::$discoveredEmail;
        }

        // Then try to get most recent user email from storage
        $gotRecentEmail = false;
        if ($leEmail === '') {
            [$leEmail, $gotRecentEmail] = $this->mostRecentAccountEmail($this->ca);
        }
        if (!$gotRecentEmail && $leEmail === '' && $allowPrompts) {
            // Looks like there is no email address readily available,
            // so we will have to ask the user if we can.
            $leEmail = $this->promptUserForEmail();

            // User might have just signified their agreement
            $this->effectiveAgreed = CertMagic::defaultACME()->agreed;
        }

        // Save the email for later and ensure it is consistent
        // for repeated use; then update cfg with the email
        $leEmail = trim(strtolower($leEmail));
        if (self::$discoveredEmail === '') {
            self::$discoveredEmail = $leEmail;
        }

        // The unexported email field is the one we use
        // because we have thread-safe control over it
        $this->effectiveEmail = $leEmail;
    }

    /**
     * promptUserForEmail prompts the user for an email address
     * and returns the email address they entered (which could
     * be the empty string). If no error is thrown, then Agreed
     * will also be set to true, since continuing through the
     * prompt signifies agreement.
     *
     * @throws \RuntimeException
     */
    private function promptUserForEmail(): string
    {
        // prompt the user for an email address and terms agreement
        $this->promptUserAgreement('');
        echo "Please enter your email address to signify agreement and to be notified\n";
        echo "in case of issues. You can leave it blank, but we don't recommend it.\n";
        echo '  Email address: ';
        $leEmail = fgets(self::stdin());
        if ($leEmail === false) {
            $leEmail = ''; // io.EOF
        }
        $leEmail = trim($leEmail);
        CertMagic::defaultACME()->agreed = true;
        return $leEmail;
    }

    /**
     * promptUserAgreement simply outputs the standard user
     * agreement prompt with the given agreement URL.
     * It outputs a newline after the message.
     */
    private function promptUserAgreement(string $agreementURL): void
    {
        $userAgreementPrompt = "Your sites will be served over HTTPS automatically using an automated CA.\nBy continuing, you agree to the CA's terms of service";
        if ($agreementURL === '') {
            printf("\n\n%s.\n", $userAgreementPrompt);
            return;
        }
        printf("\n\n%s at:\n  %s\n", $userAgreementPrompt, $agreementURL);
    }

    /**
     * askUserAgreement prompts the user to agree to the agreement
     * at the given agreement URL via stdin. It returns whether the
     * user agreed or not.
     */
    private function askUserAgreement(string $agreementURL): bool
    {
        $this->promptUserAgreement($agreementURL);
        echo 'Do you agree to the terms? (y/n): ';

        $answer = fgets(self::stdin());
        if ($answer === false) {
            return false;
        }
        $answer = strtolower(trim($answer));

        return $answer === 'y' || $answer === 'yes';
    }

    /** @return resource */
    private static function stdin()
    {
        if (self::$stdin !== null && \is_resource(self::$stdin)) {
            return self::$stdin;
        }
        return \STDIN;
    }

    public static function storageKeyACMECAPrefix(string $issuerKey): string
    {
        return StorageKeys::join(self::prefixACME, StorageKeys::safe($issuerKey));
    }

    public function storageKeyCAPrefix(string $caURL): string
    {
        return self::storageKeyACMECAPrefix(self::issuerKeyOf($caURL));
    }

    public function storageKeyUsersPrefix(string $caURL): string
    {
        return StorageKeys::join($this->storageKeyCAPrefix($caURL), 'users');
    }

    public function storageKeyUserPrefix(string $caURL, string $email): string
    {
        if ($email === '') {
            $email = self::emptyEmail;
        }
        return StorageKeys::join($this->storageKeyUsersPrefix($caURL), StorageKeys::safe($email));
    }

    public function storageKeyUserReg(string $caURL, string $email): string
    {
        return $this->storageSafeUserKey($caURL, $email, 'registration', '.json');
    }

    public function storageKeyUserPrivateKey(string $caURL, string $email): string
    {
        return $this->storageSafeUserKey($caURL, $email, 'private', '.key');
    }

    /**
     * storageSafeUserKey returns a key for the given email, with the default
     * filename, and the filename ending in the given extension.
     */
    private function storageSafeUserKey(string $ca, string $email, string $defaultFilename, string $extension): string
    {
        if ($email === '') {
            $email = self::emptyEmail;
        }
        $email = strtolower($email);
        $filename = self::emailUsername($email);
        if ($filename === '') {
            $filename = $defaultFilename;
        }
        $filename = StorageKeys::safe($filename);
        return StorageKeys::join($this->storageKeyUserPrefix($ca, $email), $filename . $extension);
    }

    /**
     * emailUsername returns the username portion of an email address (part before
     * '@') or the original input if it can't find the "@" symbol.
     */
    private static function emailUsername(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false) {
            return $email;
        }
        if ($at === 0) {
            return substr($email, 1);
        }
        return substr($email, 0, $at);
    }

    /**
     * mostRecentAccountEmail finds the most recently-written account file
     * in storage. Since this is part of a complex sequence to get a user
     * account, errors here are discarded to simplify code flow in
     * the caller, and errors are not important here anyway.
     *
     * @return array{0: string, 1: bool}
     */
    private function mostRecentAccountEmail(string $caURL): array
    {
        try {
            $accountList = $this->config()->storage()->list($this->storageKeyUsersPrefix($caURL), false);
        } catch (\Throwable) {
            return ['', false];
        }
        if ($accountList === []) {
            return ['', false];
        }

        // get all the key infos ahead of sorting, because
        // we might filter some out
        /** @var array<string, KeyInfo> $stats */
        $stats = [];
        $filtered = [];
        foreach ($accountList as $u) {
            try {
                $keyInfo = $this->config()->storage()->stat($u);
            } catch (\Throwable) {
                $filtered[] = $u; // Go keeps entries whose Stat failed (they sort with a zero time)
                continue;
            }
            if ($keyInfo->isTerminal) {
                // I found a bug when macOS created a .DS_Store file in
                // the users folder, and CertMagic tried to use that as
                // the user email because it was newer than the other one
                // which existed... sure, this isn't a perfect fix but
                // frankly one's OS shouldn't mess with the data folder
                // in the first place.
                continue;
            }
            $stats[$u] = $keyInfo;
            $filtered[] = $u;
        }
        $accountList = $filtered;

        usort($accountList, static function (string $i, string $j) use ($stats): int {
            $iMod = isset($stats[$i]) && $stats[$i]->modified !== null ? Rfc3339::seconds($stats[$i]->modified) : 0.0;
            $jMod = isset($stats[$j]) && $stats[$j]->modified !== null ? Rfc3339::seconds($stats[$j]->modified) : 0.0;
            return $jMod <=> $iMod; // jInfo.Modified.Before(iInfo.Modified)
        });

        if ($accountList === []) {
            return ['', false];
        }

        try {
            $account = $this->getAccount($caURL, basename($accountList[0]));
        } catch (\Throwable) {
            return ['', false];
        }

        return [self::getPrimaryContact($account), true];
    }

    public static function accountRegLockKey(Account $acc): string
    {
        $key = 'register_acme_account';
        if ($acc->contact === []) {
            return $key;
        }
        $key .= '_' . self::getPrimaryContact($acc);
        return $key;
    }

    /**
     * getPrimaryContact returns the first contact on the account (if any)
     * without the scheme. (I guess we assume an email address.)
     */
    public static function getPrimaryContact(Account $account): string
    {
        // TODO: should this be abstracted with some lower-level helper?
        $primaryContact = '';
        if ($account->contact !== []) {
            $primaryContact = $account->contact[0];
            $idx = strpos($primaryContact, ':');
            if ($idx !== false) {
                $primaryContact = substr($primaryContact, $idx + 1);
            }
        }
        return $primaryContact;
    }

    // ---- httphandlers.go ----------------------------------------------------

    /**
     * HTTPChallengeHandler wraps h in a handler that can solve the ACME
     * HTTP challenge. cfg is required, and it must have a certificate
     * cache backed by a functional storage facility, since that is where
     * the challenge state is stored between initiation and solution.
     *
     * If a request is not an ACME HTTP challenge, h will be invoked.
     *
     * @param callable(ServerRequestInterface): ResponseInterface $h
     * @return callable(ServerRequestInterface): ResponseInterface
     */
    public function httpChallengeHandler(callable $h): callable
    {
        return function (ServerRequestInterface $r) use ($h): ResponseInterface {
            $response = $this->handleHTTPChallenge($r);
            if ($response !== null) {
                return $response;
            }
            return $h($r);
        };
    }

    /**
     * HandleHTTPChallenge uses am to solve challenge requests from an ACME
     * server that were initiated by this instance or any other instance in
     * this cluster (being, any instances using the same storage am does).
     *
     * If the HTTP challenge is disabled, this function is a no-op.
     *
     * If am is nil or if am does not have a certificate cache backed by
     * usable storage, solving the HTTP challenge will fail.
     *
     * It returns the response if it handled the request. If null is returned,
     * this call was a no-op and the request has not been handled.
     */
    public function handleHTTPChallenge(ServerRequestInterface $r): ?ResponseInterface
    {
        if ($this->disableHTTPChallenge) {
            return null;
        }
        if (!self::looksLikeHTTPChallenge($r)) {
            return null;
        }
        return $this->distributedHTTPChallengeSolver($r);
    }

    /**
     * distributedHTTPChallengeSolver checks to see if this challenge
     * request was initiated by this or another instance which uses the
     * same storage as am does, and attempts to complete the challenge for
     * it. It returns the response if the request was handled; null otherwise.
     */
    private function distributedHTTPChallengeSolver(ServerRequestInterface $r): ?ResponseInterface
    {
        $host = CertMagic::hostOnly(self::requestHost($r));
        try {
            [$chalInfo, $distributed] = $this->config()->getChallengeInfo($host);
        } catch (\Throwable $err) {
            Log::get()->warning('looking up info for HTTP challenge', [
                'host' => $host,
                'remote_addr' => (string) ($r->getServerParams()['REMOTE_ADDR'] ?? ''),
                'user_agent' => $r->getHeaderLine('User-Agent'),
                'error' => $err->getMessage(),
            ]);
            return null;
        }
        return self::solveHTTPChallengeInternal($r, $chalInfo->challenge, $distributed);
    }

    /**
     * solveHTTPChallenge solves the HTTP challenge using the given challenge information.
     * If the challenge is being solved in a distributed fahsion, set distributed to true for logging purposes.
     * It returns the response if the properties of the request check out in relation to the HTTP challenge.
     * Most of this code borrowed from xenolf's built-in HTTP-01 challenge solver in March 2018.
     */
    private static function solveHTTPChallengeInternal(ServerRequestInterface $r, Challenge $challenge, bool $distributed): ?ResponseInterface
    {
        $challengeReqPath = $challenge->http01ResourcePath();
        if ($r->getUri()->getPath() === $challengeReqPath
            && strcasecmp(CertMagic::hostOnly(self::requestHost($r)), $challenge->identifier->value) === 0 // mitigate DNS rebinding attacks
            && $r->getMethod() === 'GET') {
            $response = (new ResponseFactory())->createResponse(200)
                ->withAddedHeader('Content-Type', 'text/plain')
                ->withHeader('Connection', 'close'); // r.Close = true
            $response->getBody()->write($challenge->keyAuthorization);
            Log::get()->info('served key authentication', [
                'identifier' => $challenge->identifier->value,
                'challenge' => 'http-01',
                'remote' => (string) ($r->getServerParams()['REMOTE_ADDR'] ?? ''),
                'distributed' => $distributed,
            ]);
            return $response;
        }
        return null;
    }

    /**
     * SolveHTTPChallenge solves the HTTP challenge. It should be used only on HTTP requests that are
     * from ACME servers trying to validate an identifier (i.e. LooksLikeHTTPChallenge() == true). It
     * returns the response if the request criteria check out and it answered with key authentication, in which
     * case no further handling of the request is necessary.
     */
    public static function solveHTTPChallenge(ServerRequestInterface $r, Challenge $challenge): ?ResponseInterface
    {
        return self::solveHTTPChallengeInternal($r, $challenge, false);
    }

    /**
     * LooksLikeHTTPChallenge returns true if r looks like an ACME
     * HTTP challenge request from an ACME server.
     */
    public static function looksLikeHTTPChallenge(ServerRequestInterface $r): bool
    {
        return $r->getMethod() === 'GET'
            && str_starts_with($r->getUri()->getPath(), self::acmeHTTPChallengeBasePath);
    }

    /** requestHost is Go's `r.Host`: the Host header, or the URL host. */
    private static function requestHost(ServerRequestInterface $r): string
    {
        $host = $r->getHeaderLine('Host');
        if ($host !== '') {
            return $host;
        }
        $uri = $r->getUri();
        return $uri->getPort() !== null ? $uri->getHost() . ':' . $uri->getPort() : $uri->getHost();
    }
}
