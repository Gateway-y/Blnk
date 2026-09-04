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

use Blnk\Cmd\HttpsServer;
use Blnk\Internal\Log;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * CertMagic holds the package-level state and functions of certmagic
 * (certmagic.go, plus the package-level helpers of certificates.go,
 * filestorage.go and maintain.go): the `Default` config template, the
 * `DefaultACME` issuer template, the HTTP/HTTPS ports, and the high-level
 * convenience functions (HTTPS, TLS, ManageSync, ManageAsync, NewDefault,
 * New).
 *
 * Package certmagic automates the obtaining and renewal of TLS certificates,
 * including TLS & HTTPS best practices such as robust OCSP stapling, caching,
 * HTTP->HTTPS redirects, and more.
 *
 * Its high-level API serves your HTTP handlers over HTTPS if you simply give
 * the domain name(s) and the http.Handler; CertMagic will create and run
 * the HTTPS server for you, fully managing certificates during the lifetime
 * of the server. Similarly, it can be used to start TLS listeners or return
 * a ready-to-use tls.Config -- whatever layer you need TLS for, CertMagic
 * makes it easy. See the HTTPS, Listen, and TLS functions for that.
 *
 * If you need more control, create a Cache using NewCache() and then make
 * a Config using New(). You can then call Manage() on the config. But if
 * you use this lower-level API, you'll have to be sure to solve the HTTP
 * and TLS-ALPN challenges yourself (unless you disabled them or use the
 * DNS challenge) by using the provided Config.GetCertificate function
 * in your tls.Config and/or Config.HTTPChallengeHandler in your HTTP
 * handler.
 */
final class CertMagic
{
    /**
     * HTTPChallengePort is the officially-designated port for
     * the HTTP challenge according to the ACME spec.
     */
    public const HTTPChallengePort = 80;

    /**
     * TLSALPNChallengePort is the officially-designated port for
     * the TLS-ALPN challenge according to the ACME spec.
     */
    public const TLSALPNChallengePort = 443;

    /** Supported issuer policies. These are subject to change. */
    public const UseFirstIssuer = 'first'; // uses the first issuer that successfully returns a certificate
    public const UseFirstRandomIssuer = 'first_random'; // shuffles the list of configured issuers, then uses the first one that successfully returns a certificate

    /** Maximum size for the stack trace when recovering from panics. */
    public const stackTraceBufferSize = 1024 * 128;

    /**
     * Port variables must remain their defaults unless you
     * forward packets from the defaults to whatever these
     * are set to; otherwise ACME challenges will fail.
     */
    /**
     * HTTPPort is the port on which to serve HTTP
     * and, as such, the HTTP challenge (unless
     * Default.AltHTTPPort is set).
     */
    public static int $HTTPPort = 80;

    /**
     * HTTPSPort is the port on which to serve HTTPS
     * and, as such, the TLS-ALPN challenge
     * (unless Default.AltTLSALPNPort is set).
     */
    public static int $HTTPSPort = 443;

    /** Default contains the package defaults for the various Config fields (see {@see default()}). */
    private static ?Config $default = null;

    /** DefaultACME specifies default settings to use for ACMEIssuers (see {@see defaultACME()}). */
    private static ?ACMEIssuer $defaultACME = null;

    /** the default certificate cache shared by every config returned by NewDefault() */
    private static ?Cache $defaultCache = null;

    /** defaultFileStorage is a convenient, default storage implementation using the local file system. */
    private static ?FileStorage $defaultFileStorage = null;

    private function __construct()
    {
    }

    /**
     * Default contains the package defaults for the
     * various Config fields. This is used as a template
     * when creating your own Configs with New() or
     * NewDefault(), and it is also used as the Config
     * by all the high-level functions in this package
     * that abstract away most configuration (HTTPS(),
     * TLS(), Listen(), etc).
     *
     * The fields of this value will be used for Config
     * fields which are unset. Feel free to modify these
     * defaults, but do not use this Config by itself: it
     * is only a template. Valid configurations can be
     * obtained by calling New() (if you have your own
     * certificate cache) or NewDefault() (if you only
     * need a single config and want to use the default
     * cache).
     *
     * Even if the Issuers or Storage fields are not set,
     * defaults will be applied in the call to New().
     */
    public static function default(): Config
    {
        if (self::$default === null) {
            $d = new Config();
            $d->renewalWindowRatio = Cache::DefaultRenewalWindowRatio;
            $d->storage = self::defaultFileStorage();
            $d->keySource = Config::defaultKeyGenerator();
            self::$default = $d;
        }
        return self::$default;
    }

    /**
     * DefaultACME specifies default settings to use for ACMEIssuers.
     * Using this value is optional but can be convenient. Modify the
     * returned template (e.g. `->agreed = true`, `->email = ...`) before
     * calling {@see newDefault()}.
     */
    public static function defaultACME(): ACMEIssuer
    {
        if (self::$defaultACME === null) {
            $t = new ACMEIssuer();
            $t->ca = ACMEIssuer::LetsEncryptProductionCA;
            $t->testCA = ACMEIssuer::LetsEncryptStagingCA;
            self::$defaultACME = $t;
        }
        return self::$defaultACME;
    }

    /** defaultFileStorage is a convenient, default storage implementation using the local file system. */
    public static function defaultFileStorage(): FileStorage
    {
        if (self::$defaultFileStorage === null) {
            self::$defaultFileStorage = new FileStorage(self::dataDir());
        }
        return self::$defaultFileStorage;
    }

    /**
     * HTTPS serves mux for all domainNames using the HTTP
     * and HTTPS ports, redirecting all HTTP requests to HTTPS.
     * It uses the Default config and a background context.
     *
     * This high-level convenience function is opinionated and
     * applies sane defaults for production use, including
     * timeouts for HTTP requests and responses. To allow very
     * long-lived connections, you should make your own
     * http.Server values and use this package's Listen(), TLS(),
     * or Config.TLSConfig() functions to customize to your needs.
     * For example, servers which need to support large uploads or
     * downloads with slow clients may need to use longer timeouts,
     * thus this function is not suitable.
     *
     * Calling this function signifies your acceptance to
     * the CA's Subscriber Agreement and/or Terms of Service.
     *
     * @param string[] $domainNames
     * @param callable(ServerRequestInterface): ResponseInterface $mux
     * @param callable(): bool|null $quit PHP-only: stops serving when it returns true
     * @throws \RuntimeException
     */
    public static function https(array $domainNames, callable $mux, ?callable $quit = null): void
    {
        self::defaultACME()->agreed = true;
        $cfg = self::newDefault();

        $cfg->manageSync($domainNames);

        $tlsConfig = $cfg->tlsConfig();
        $tlsConfig->nextProtos = array_merge(['h2', 'http/1.1'], $tlsConfig->nextProtos);

        // create HTTP/S servers that are configured
        // with sane default timeouts and appropriate
        // handlers (the HTTP server solves the HTTP
        // challenge and issues redirects to HTTPS,
        // while the HTTPS server simply serves the
        // user's handler)
        $httpHandler = static function (ServerRequestInterface $r): ResponseInterface {
            return self::httpRedirectHandler($r);
        };
        if ($cfg->issuers !== [] && $cfg->issuers[0] instanceof ACMEIssuer) {
            $httpHandler = $cfg->issuers[0]->httpChallengeHandler($httpHandler);
        }
        $httpServer = new HttpsServer(sprintf(':%d', self::$HTTPPort), $httpHandler, null);
        $httpServer->readHeaderTimeout = 5.0;
        $httpServer->readTimeout = 5.0;
        $httpServer->writeTimeout = 5.0;
        $httpServer->idleTimeout = 5.0;

        $httpsServer = new HttpsServer(sprintf(':%d', self::$HTTPSPort), $mux, $tlsConfig);
        $httpsServer->readHeaderTimeout = 10.0;
        $httpsServer->readTimeout = 30.0;
        $httpsServer->writeTimeout = 120.0;
        $httpsServer->idleTimeout = 300.0;

        $httpServer->listen();
        try {
            $httpsServer->listen();
        } catch (\Throwable $err) {
            $httpServer->close();
            throw $err;
        }

        Log::get()->info(sprintf('%s Serving HTTP->HTTPS on %s and %s', '[' . implode(' ', $domainNames) . ']', $httpServer->addr(), $httpsServer->addr()));

        try {
            HttpsServer::serveAll([$httpServer, $httpsServer], $quit, static function () use ($cfg): void {
                $cfg->certCache?->maintainAssets();
            });
        } finally {
            $httpServer->close();
            $httpsServer->close();
        }
    }

    /** httpRedirectHandler redirects every HTTP request to its HTTPS counterpart (301). */
    public static function httpRedirectHandler(ServerRequestInterface $r): ResponseInterface
    {
        $toURL = 'https://';

        // since we redirect to the standard HTTPS port, we
        // do not need to include it in the redirect URL
        $requestHost = self::hostOnly($r->getHeaderLine('Host') !== '' ? $r->getHeaderLine('Host') : $r->getUri()->getHost());

        $toURL .= $requestHost;
        $toURL .= $r->getRequestTarget();

        // get rid of this disgusting unencrypted HTTP connection 🤢
        $response = (new ResponseFactory())->createResponse(301)
            ->withHeader('Connection', 'close')
            ->withHeader('Location', $toURL)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
        $response->getBody()->write(sprintf("<a href=\"%s\">Moved Permanently</a>.\n\n", htmlspecialchars($toURL, \ENT_QUOTES)));
        return $response;
    }

    /**
     * TLS enables management of certificates for domainNames
     * and returns a valid tls.Config. It uses the Default
     * config.
     *
     * Because this is a convenience function that returns
     * only a tls.Config, it does not assume HTTP is being
     * served on the HTTP port, so the HTTP challenge is
     * disabled (no HTTPChallengeHandler is necessary). The
     * package variable Default is modified so that the
     * HTTP challenge is disabled.
     *
     * Calling this function signifies your acceptance to
     * the CA's Subscriber Agreement and/or Terms of Service.
     *
     * @param string[] $domainNames
     * @throws \RuntimeException
     */
    public static function tls(array $domainNames): TlsConfig
    {
        self::defaultACME()->agreed = true;
        self::defaultACME()->disableHTTPChallenge = true;
        $cfg = self::newDefault();
        $tlsConfig = $cfg->tlsConfig();
        $cfg->manageSync($domainNames);
        return $tlsConfig;
    }

    /**
     * Listen manages certificates for domainName and returns a
     * TLS listener. It uses the Default config.
     *
     * Because this convenience function returns only a TLS-enabled
     * listener and does not presume HTTP is also being served,
     * the HTTP challenge will be disabled. The package variable
     * Default is modified so that the HTTP challenge is disabled.
     *
     * Calling this function signifies your acceptance to
     * the CA's Subscriber Agreement and/or Terms of Service.
     *
     * PHP port: the "listener" is an {@see HttpsServer} bound to
     * `:HTTPSPort` without a handler yet; set `->handler` and call
     * `serve()`.
     *
     * @param string[] $domainNames
     * @throws \RuntimeException
     */
    public static function listen(array $domainNames): HttpsServer
    {
        self::defaultACME()->agreed = true;
        self::defaultACME()->disableHTTPChallenge = true;
        $cfg = self::newDefault();
        $cfg->manageSync($domainNames);
        $server = new HttpsServer(sprintf(':%d', self::$HTTPSPort), null, $cfg->tlsConfig());
        $server->listen();
        return $server;
    }

    /**
     * ManageSync obtains certificates for domainNames and keeps them
     * renewed using the Default config.
     *
     * This is a slightly lower-level function; you will need to
     * wire up support for the ACME challenges yourself. You can
     * obtain a Config to help you do that by calling NewDefault().
     *
     * You will need to ensure that you use a TLS config that gets
     * certificates from this Config and that the HTTP and TLS-ALPN
     * challenges can be solved. The easiest way to do this is to
     * use NewDefault().TLSConfig() as your TLS config and to wrap
     * your HTTP handler with NewDefault().HTTPChallengeHandler().
     * If you don't have an HTTP server, you will need to disable
     * the HTTP challenge.
     *
     * If you already have a TLS config you want to use, you can
     * simply set its GetCertificate field to
     * NewDefault().GetCertificate.
     *
     * Calling this function signifies your acceptance to
     * the CA's Subscriber Agreement and/or Terms of Service.
     *
     * @param string[] $domainNames
     * @throws \RuntimeException
     */
    public static function manageSync(array $domainNames): void
    {
        self::defaultACME()->agreed = true;
        self::newDefault()->manageSync($domainNames);
    }

    /**
     * ManageAsync is the same as ManageSync, except that
     * certificates are managed asynchronously. This means
     * that the function will return before certificates
     * are ready, and errors that occur during certificate
     * obtain or renew operations are only logged. It is
     * vital that you monitor the logs if using this method,
     * which is only recommended for automated/non-interactive
     * environments.
     *
     * @param string[] $domainNames
     * @throws \RuntimeException
     */
    public static function manageAsync(array $domainNames): void
    {
        self::defaultACME()->agreed = true;
        self::newDefault()->manageAsync($domainNames);
    }

    /**
     * NewDefault makes a valid config based on the package
     * Default config. Most users will call this function
     * instead of New() since most use cases require only a
     * single config for any and all certificates.
     *
     * If your requirements are more advanced (for example,
     * multiple configs depending on the certificate), then use
     * New() instead. (You will need to make your own Cache
     * first.) If you only need a single Config to manage your
     * certs (even if that config changes, as long as it is the
     * only one), customize the Default package variable before
     * calling NewDefault().
     *
     * All calls to NewDefault() will return configs that use the
     * same, default certificate cache. All configs returned
     * by NewDefault() are based on the values of the fields of
     * Default at the time it is called.
     *
     * This is the only way to get a config that uses the
     * default certificate cache.
     */
    public static function newDefault(): Config
    {
        if (self::$defaultCache === null) {
            $opts = new CacheOptions();
            // the cache will likely need to renew certificates,
            // so it will need to know how to do that, which
            // depends on the certificate being managed and which
            // can change during the lifetime of the cache; this
            // callback makes it possible to get the latest and
            // correct config with which to manage the cert,
            // but if the user does not provide one, we can only
            // assume that we are to use the default config
            $opts->getConfigForCert = static function (Certificate $cert): Config {
                return self::newDefault();
            };
            self::$defaultCache = Cache::newCache($opts);
        }
        $certCache = self::$defaultCache;

        return self::newWithCache($certCache, self::default());
    }

    /**
     * New makes a new, valid config based on cfg and
     * uses the provided certificate cache. certCache
     * MUST NOT be null or this function will throw.
     *
     * Use this method when you have an advanced use case
     * that requires a custom certificate cache and config
     * that may differ from the Default. For example, if
     * not all certificates are managed/renewed the same
     * way, you need to make your own Cache value with a
     * GetConfigForCert callback that returns the correct
     * configuration for each certificate. However, for
     * the vast majority of cases, there will be only a
     * single Config, thus the default cache (which always
     * uses the default Config) and default config will
     * suffice, and you should use NewDefault() instead.
     *
     * @throws \LogicException (Go: panic)
     */
    public static function new(?Cache $certCache, Config $cfg): Config
    {
        if ($certCache === null) {
            throw new \LogicException('a certificate cache is required');
        }
        if ($certCache->options->getConfigForCert === null) {
            throw new \LogicException('cache must have GetConfigForCert set in its options');
        }
        return self::newWithCache($certCache, $cfg);
    }

    /**
     * newWithCache ensures that cfg is a valid config by populating
     * zero-value fields from the Default Config. If certCache is
     * null, this function throws. (Go copies the Config value; the
     * port clones it.)
     *
     * @throws \LogicException
     */
    public static function newWithCache(?Cache $certCache, Config $template): Config
    {
        if ($certCache === null) {
            throw new \LogicException('cannot make a valid config without a pointer to a certificate cache');
        }
        $default = self::default();
        $cfg = clone $template;

        if ($cfg->onDemand === null) {
            $cfg->onDemand = $default->onDemand;
        }
        if (!$cfg->mustStaple) {
            $cfg->mustStaple = $default->mustStaple;
        }
        if ($cfg->issuers === null) {
            $cfg->issuers = $default->issuers;
            if ($cfg->issuers === null) {
                // at least one issuer is absolutely required if not nil
                $cfg->issuers = [ACMEIssuer::newACMEIssuer($cfg, self::defaultACME())];
            }
        }
        if ($cfg->renewalWindowRatio == 0) {
            $cfg->renewalWindowRatio = $default->renewalWindowRatio;
        }
        if ($cfg->onEvent === null) {
            $cfg->onEvent = $default->onEvent;
        }
        if ($cfg->keySource === null) {
            $cfg->keySource = $default->keySource;
        }
        if ($cfg->defaultServerName === '') {
            $cfg->defaultServerName = $default->defaultServerName;
        }
        if ($cfg->fallbackServerName === '') {
            $cfg->fallbackServerName = $default->fallbackServerName;
        }
        if ($cfg->storage === null) {
            $cfg->storage = $default->storage;
        }

        // absolutely don't allow a nil storage,
        // because that would make almost anything
        // a config can do pointless
        if ($cfg->storage === null) {
            $cfg->storage = self::defaultFileStorage();
        }

        // absolutely don't allow a nil key source either
        if ($cfg->keySource === null) {
            $cfg->keySource = Config::defaultKeyGenerator();
        }

        $cfg->certCache = $certCache;

        // the issuers were created against the template; rebind them to this config
        foreach ($cfg->issuers as $issuer) {
            if ($issuer instanceof ACMEIssuer && $issuer->config() === null) {
                $issuer->bind($cfg);
            }
        }

        return $cfg;
    }

    /**
     * SubjectQualifiesForCert returns true if subj is a name which,
     * as a quick sanity check, looks like it could be the subject
     * of a certificate. Requirements are:
     * - must not be empty
     * - must not start or end with a dot (RFC 1034; RFC 6066 section 3)
     * - must not contain common accidental special characters
     */
    public static function subjectQualifiesForCert(string $subj): bool
    {
        // must not be empty
        return trim($subj) !== ''

            // must not start or end with a dot
            && !str_starts_with($subj, '.')
            && !str_ends_with($subj, '.')

            // if it has a wildcard, must be a left-most label (or exactly "*"
            // which won't be trusted by browsers but still technically works)
            && (!str_contains($subj, '*') || str_starts_with($subj, '*.') || $subj === '*')

            // must not contain other common special characters
            && strpbrk($subj, "()[]{}<> \t\n\"\\!@#$%^&|;'+=") === false;
    }

    /**
     * SubjectQualifiesForPublicCert returns true if the subject
     * name appears eligible for automagic TLS with a public
     * CA such as Let's Encrypt. For example: internal IP addresses
     * and localhost are not eligible because we cannot obtain certs
     * for those names with a public CA. Wildcard names are
     * allowed, as long as they conform to CABF requirements (only
     * one wildcard label, and it must be the left-most label).
     */
    public static function subjectQualifiesForPublicCert(string $subj): bool
    {
        // must at least qualify for a certificate
        return self::subjectQualifiesForCert($subj)

            // loopback hosts and internal IPs are ineligible
            && !self::subjectIsInternal($subj)

            // only one wildcard label allowed, and it must be left-most, with 3+ labels
            && (!str_contains($subj, '*')
                || (substr_count($subj, '*') === 1
                    && substr_count($subj, '.') > 1
                    && \strlen($subj) > 2
                    && str_starts_with($subj, '*.')));
    }

    /** SubjectIsIP returns true if subj is an IP address. */
    public static function subjectIsIP(string $subj): bool
    {
        return filter_var($subj, \FILTER_VALIDATE_IP) !== false;
    }

    /**
     * SubjectIsInternal returns true if subj is an internal-facing
     * hostname or address, including localhost/loopback hosts.
     * Ports are ignored, if present.
     */
    public static function subjectIsInternal(string $subj): bool
    {
        $subj = strtolower(rtrim(self::hostOnly($subj), '.'));
        return $subj === 'localhost'
            || str_ends_with($subj, '.localhost')
            || str_ends_with($subj, '.local')
            || str_ends_with($subj, '.internal')
            || str_ends_with($subj, '.home.arpa')
            || self::isInternalIP($subj);
    }

    /**
     * isInternalIP returns true if the IP of addr
     * belongs to a private network IP range. addr
     * must only be an IP or an IP:port combination.
     */
    public static function isInternalIP(string $addr): bool
    {
        $privateNetworks = [
            '127.0.0.0/8', // IPv4 loopback
            '0.0.0.0/16',
            '10.0.0.0/8', // RFC1918
            '172.16.0.0/12', // RFC1918
            '192.168.0.0/16', // RFC1918
            '169.254.0.0/16', // RFC3927 link-local
            '::1/7', // IPv6 loopback
            'fe80::/10', // IPv6 link-local
            'fc00::/7', // IPv6 unique local addr
        ];
        $host = self::hostOnly($addr);
        $ip = @inet_pton($host);
        if ($ip === false) {
            return false;
        }
        foreach ($privateNetworks as $privateNetwork) {
            if (self::cidrContains($privateNetwork, $ip)) {
                return true;
            }
        }
        return false;
    }

    /**
     * cidrContains reports whether the packed IP is in the CIDR network
     * (Go: `net.ParseCIDR` + `ipnet.Contains`; an IPv4 address is compared
     * against IPv4 networks only and an IPv6 address against IPv6 ones,
     * except that Go maps IPv4 into IPv6 for `::1/7`-style checks — which
     * cannot match a v4 address anyway).
     */
    private static function cidrContains(string $cidr, string $ip): bool
    {
        [$network, $bits] = explode('/', $cidr, 2);
        $net = @inet_pton($network);
        if ($net === false || \strlen($net) !== \strlen($ip)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($net, 0, $bytes) !== substr($ip, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (\ord($net[$bytes]) & $mask) === (\ord($ip[$bytes]) & $mask);
    }

    /**
     * hostOnly returns only the host portion of hostport.
     * If there is no port or if there is an error splitting
     * the port off, the whole input string is returned.
     */
    public static function hostOnly(string $hostport): string
    {
        // net.SplitHostPort: "[v6]:port", "host:port"; anything else is an error
        if (preg_match('/^\[([^\]]*)\]:(\d*)$/', $hostport, $m)) {
            return $m[1];
        }
        if (preg_match('/^([^:\[\]]*):(\d*)$/', $hostport, $m)) {
            return $m[1];
        }
        return $hostport; // OK; probably had no port to begin with
    }

    /**
     * MatchWildcard returns true if subject (a candidate DNS name)
     * matches wildcard (a reference DNS name), mostly according to
     * RFC 6125-compliant wildcard rules. See also RFC 2818 which
     * states that IP addresses must match exactly, but this function
     * does not attempt to distinguish IP addresses from internal or
     * external DNS names that happen to look like IP addresses.
     * It uses DNS wildcard matching logic and is case-insensitive.
     * https://tools.ietf.org/html/rfc2818#section-3.1
     */
    public static function matchWildcard(string $subject, string $wildcard): bool
    {
        $subject = strtolower($subject);
        $wildcard = strtolower($wildcard);
        if ($subject === $wildcard) {
            return true;
        }
        if (!str_contains($wildcard, '*')) {
            return false;
        }
        $labels = explode('.', $subject);
        foreach ($labels as $i => $label) {
            if ($label === '') {
                continue; // invalid label
            }
            $labels[$i] = '*';
            $candidate = implode('.', $labels);
            if ($candidate === $wildcard) {
                return true;
            }
        }
        return false;
    }

    /**
     * normalizedName returns a cleaned form of serverName that is
     * used for consistency when referring to a SNI value.
     */
    public static function normalizedName(string $serverName): string
    {
        return strtolower(trim($serverName));
    }

    /**
     * currentlyInRenewalWindow returns true if the current time is within
     * (or after) the renewal window, according to the given start/end
     * dates and the ratio of the renewal window. If true is returned,
     * the certificate being considered is due for renewal. The ratio
     * is remaining:total time, i.e. 1/3 = 1/3 of lifetime remaining,
     * or 9/10 = 9/10 of time lifetime remaining.
     */
    public static function currentlyInRenewalWindow(\DateTimeImmutable $notBefore, \DateTimeImmutable $notAfter, float $renewalWindowRatio): bool
    {
        if ($notAfter->getTimestamp() === 0) {
            return false;
        }
        $lifetime = Rfc3339::seconds($notAfter) - Rfc3339::seconds($notBefore);
        if ($renewalWindowRatio == 0) {
            $renewalWindowRatio = Cache::DefaultRenewalWindowRatio;
        }
        $renewalWindow = $lifetime * $renewalWindowRatio;
        $renewalWindowStart = Rfc3339::seconds($notAfter) - $renewalWindow;
        return microtime(true) > $renewalWindowStart;
    }

    /**
     * expiresAt return the time that a certificate expires. Account for the 1s
     * resolution of ASN.1 UTCTime/GeneralizedTime by including the extra fraction
     * of a second of certificate validity beyond the NotAfter value.
     */
    public static function expiresAt(?X509Certificate $cert): ?\DateTimeImmutable
    {
        if ($cert === null) {
            return null;
        }
        return $cert->expiresAt();
    }

    /**
     * homeDir returns the best guess of the current user's home
     * directory from environment variables (certmagic filestorage.go).
     */
    public static function homeDir(): string
    {
        $home = (string) getenv('HOME');
        if ($home === '' && \PHP_OS_FAMILY === 'Windows') {
            $drive = (string) getenv('HOMEDRIVE');
            $path = (string) getenv('HOMEPATH');
            $home = $drive . $path;
            if ($drive === '' || $path === '') {
                $home = (string) getenv('USERPROFILE');
            }
        }
        if ($home === '') {
            $home = '.';
        }
        return $home;
    }

    /**
     * dataDir returns a directory path that is suitable for storing
     * application data on disk. It uses the environment for finding
     * the best place to store data, and appends a "certmagic" or
     * "certmagic" subfolder.
     *
     * For a base directory path:
     * If XDG_DATA_HOME is set, it returns: $XDG_DATA_HOME/certmagic; otherwise,
     * on Unix: $HOME/.local/share/certmagic (per XDG Base Directory spec).
     */
    public static function dataDir(): string
    {
        $baseDir = self::homeDir() . \DIRECTORY_SEPARATOR . '.local' . \DIRECTORY_SEPARATOR . 'share';
        $xdgData = (string) getenv('XDG_DATA_HOME');
        if ($xdgData !== '') {
            $baseDir = $xdgData;
        }
        return $baseDir . \DIRECTORY_SEPARATOR . 'certmagic';
    }

    /**
     * CleanStorage removes assets which are no longer useful,
     * according to opts (certmagic maintain.go).
     *
     * PHP port: OCSP staples are not maintained by the port, so the
     * `ocspStaples` option only deletes staple files older than their
     * modification would suggest is impossible to judge — it is ignored.
     *
     * @param array{instanceID?: string, interval?: float, ocspStaples?: bool, expiredCerts?: bool, expiredCertGracePeriod?: float} $opts
     * @throws \RuntimeException
     */
    public static function cleanStorage(Storage $storage, array $opts = []): void
    {
        $lockName = 'storage_clean';
        $storageKey = 'last_clean.json';
        $logger = Log::get();

        // storage cleaning should be globally exclusive
        try {
            Locks::acquireLock($storage, $lockName);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('unable to acquire %s lock: %s', $lockName, $err->getMessage()), 0, $err);
        }
        try {
            // cleaning should not happen more often than the interval
            $interval = (float) ($opts['interval'] ?? 0);
            if ($interval > 0) {
                $lastCleanBytes = null;
                try {
                    $lastCleanBytes = $storage->load($storageKey);
                } catch (ErrNotExist) {
                    // never cleaned
                } catch (\Throwable $err) {
                    throw new \RuntimeException(sprintf('loading last clean timestamp: %s', $err->getMessage()), 0, $err);
                }
                if ($lastCleanBytes !== null) {
                    try {
                        $lastClean = JsonUtil::unmarshal($lastCleanBytes);
                    } catch (\Throwable $err) {
                        throw new \RuntimeException(sprintf('decoding last clean data: %s', $err->getMessage()), 0, $err);
                    }
                    $lastTLSClean = \is_array($lastClean['tls'] ?? null) ? $lastClean['tls'] : [];
                    $ts = Rfc3339::decode(isset($lastTLSClean['timestamp']) ? (string) $lastTLSClean['timestamp'] : null);
                    $since = $ts !== null ? microtime(true) - Rfc3339::seconds($ts) : \PHP_FLOAT_MAX;
                    if ($since < $interval) {
                        $nextTime = microtime(true) + $interval;
                        $logger->info('storage cleaning happened too recently; skipping for now', [
                            'instance' => (string) ($lastTLSClean['instance_id'] ?? ''),
                            'try_again' => Rfc3339::encode(Rfc3339::fromSeconds((int) $nextTime)),
                            'try_again_in' => Rfc3339::durationString($nextTime - microtime(true)),
                        ]);
                        return;
                    }
                }
            }

            $logger->info('cleaning storage unit', ['storage' => ($storage instanceof \Stringable ? (string) $storage : $storage::class)]);

            if (!empty($opts['expiredCerts'])) {
                try {
                    self::deleteExpiredCerts($storage, (float) ($opts['expiredCertGracePeriod'] ?? 0));
                } catch (\Throwable $err) {
                    $logger->error('deleting expired certificates staples', ['error' => $err->getMessage()]);
                }
            }
            // TODO: delete stale locks?

            // update the last-clean time
            $payload = ['tls' => ['timestamp' => Rfc3339::encode(Rfc3339::now())]];
            if (!empty($opts['instanceID'])) {
                $payload['tls']['instance_id'] = (string) $opts['instanceID'];
            }
            try {
                $lastCleanBytes = JsonUtil::marshal($payload);
            } catch (\Throwable $err) {
                throw new \RuntimeException(sprintf('encoding last cleaned info: %s', $err->getMessage()), 0, $err);
            }
            try {
                $storage->store($storageKey, $lastCleanBytes);
            } catch (\Throwable $err) {
                throw new \RuntimeException(sprintf('storing last clean info: %s', $err->getMessage()), 0, $err);
            }
        } finally {
            try {
                Locks::releaseLock($storage, $lockName);
            } catch (\Throwable $err) {
                $logger->error('unable to release lock', ['error' => $err->getMessage()]);
            }
        }
    }

    /**
     * deleteExpiredCerts removes certificate assets that expired more than
     * gracePeriod seconds ago, and the site folders left empty.
     *
     * @throws \RuntimeException
     */
    public static function deleteExpiredCerts(Storage $storage, float $gracePeriod): void
    {
        $logger = Log::get();
        try {
            $issuerKeys = $storage->list(StorageKeys::prefixCerts, false);
        } catch (\Throwable) {
            // maybe just hasn't been created yet; no big deal
            return;
        }

        foreach ($issuerKeys as $issuerKey) {
            try {
                $siteKeys = $storage->list($issuerKey, false);
            } catch (\Throwable $err) {
                $logger->error('listing contents', ['issuer_key' => $issuerKey, 'error' => $err->getMessage()]);
                continue;
            }

            foreach ($siteKeys as $siteKey) {
                try {
                    $siteAssets = $storage->list($siteKey, false);
                } catch (\Throwable $err) {
                    $logger->error('listing site contents', ['site_key' => $siteKey, 'error' => $err->getMessage()]);
                    continue;
                }

                foreach ($siteAssets as $assetKey) {
                    if (pathinfo($assetKey, \PATHINFO_EXTENSION) !== 'crt') {
                        continue;
                    }

                    try {
                        $certFile = $storage->load($assetKey);
                    } catch (\Throwable $err) {
                        throw new \RuntimeException(sprintf('loading certificate file %s: %s', $assetKey, $err->getMessage()), 0, $err);
                    }
                    if (!preg_match('/-----BEGIN ([^-]+)-----/', $certFile, $m) || trim($m[1]) !== 'CERTIFICATE') {
                        throw new \RuntimeException(sprintf('certificate file %s does not contain PEM-encoded certificate', $assetKey));
                    }
                    try {
                        $cert = X509Certificate::parsePEM($certFile);
                    } catch (\Throwable $err) {
                        throw new \RuntimeException(sprintf('certificate file %s is malformed; error parsing PEM: %s', $assetKey, $err->getMessage()), 0, $err);
                    }

                    $expiredTime = microtime(true) - Rfc3339::seconds($cert->expiresAt());
                    if ($expiredTime >= $gracePeriod) {
                        $logger->info('certificate expired beyond grace period; cleaning up', [
                            'asset_key' => $assetKey,
                            'expired_for' => Rfc3339::durationString($expiredTime),
                            'grace_period' => Rfc3339::durationString($gracePeriod),
                        ]);
                        $baseName = substr($assetKey, 0, -\strlen('.crt'));
                        foreach ([$assetKey, $baseName . '.key', $baseName . '.json'] as $relatedAsset) {
                            $logger->info('deleting asset because resource expired', ['asset_key' => $relatedAsset]);
                            try {
                                $storage->delete($relatedAsset);
                            } catch (\Throwable $err) {
                                $logger->error('could not clean up asset related to expired certificate', [
                                    'base_name' => $baseName,
                                    'related_asset' => $relatedAsset,
                                    'error' => $err->getMessage(),
                                ]);
                            }
                        }
                    }
                }

                // update listing; if folder is empty, delete it
                try {
                    $siteAssets = $storage->list($siteKey, false);
                } catch (\Throwable) {
                    continue;
                }
                if ($siteAssets === []) {
                    $logger->info('deleting site folder because key is empty', ['site_key' => $siteKey]);
                    try {
                        $storage->delete($siteKey);
                    } catch (\Throwable $err) {
                        throw new \RuntimeException(sprintf('deleting empty site folder %s: %s', $siteKey, $err->getMessage()), 0, $err);
                    }
                }
            }
        }
    }

    /** resetDefaults forgets the package-level state (tests). */
    public static function resetDefaults(): void
    {
        self::$default = null;
        self::$defaultACME = null;
        self::$defaultCache = null;
        self::$defaultFileStorage = null;
    }
}
