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

namespace Blnk\Cmd\CertMagic\Acme;

use Blnk\Cmd\CertMagic\JsonUtil;
use Blnk\Cmd\CertMagic\Rfc3339;
use Blnk\Cmd\CertMagic\X509Certificate;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Client facilitates ACME client operations as defined by the spec
 * (acmez acme/client.go, http.go, account.go, order.go, authorization.go,
 * challenge.go, certificate.go, ari.go — RFC 8555).
 *
 * It is designed to work smoothly in large-scale deployments with
 * high resilience to errors and intermittent network or server issues,
 * with retries built-in at every layer of the HTTP request stack.
 *
 * Many errors that are thrown by a Client are likely to be of type
 * {@see Problem} as long as the ACME server returns a structured error
 * response. This package wraps errors that may be of type Problem, so you
 * can access the details with {@see Problem::from()} (Go's `errors.As`).
 *
 * All Problem errors originate from the ACME server.
 *
 * PHP port: every wait of the protocol (polling, retry back-off) goes through
 * {@see wait()}, which a caller may hook ({@see setWaiter()}) to service
 * challenge listeners meanwhile — the single-threaded replacement for the
 * goroutines the solvers run in Go.
 */
final class Client
{
    /**
     * replayNonce is the header field that contains a new
     * anti-replay nonce from the server.
     */
    public const replayNonce = 'Replay-Nonce';

    public const defaultPollInterval = 0.25;
    public const defaultPollTimeout = 300.0;

    /** ErrUnsupported is used to indicate lack of support by an ACME server. */
    public const ErrUnsupported = 'unsupported by ACME server';

    /**
     * Reasons for revoking a certificate, as defined
     * by RFC 5280 §5.3.1.
     * https://tools.ietf.org/html/rfc5280#section-5.3.1
     */
    public const ReasonUnspecified = 0;
    public const ReasonKeyCompromise = 1;
    public const ReasonCACompromise = 2;
    public const ReasonAffiliationChanged = 3;
    public const ReasonSuperseded = 4;
    public const ReasonCessationOfOperation = 5;
    public const ReasonCertificateHold = 6;
    public const ReasonRemoveFromCRL = 8;
    public const ReasonPrivilegeWithdrawn = 9;
    public const ReasonAACompromise = 10;

    /** The ACME server's directory endpoint. */
    public string $directory = '';

    /** Custom HTTP client. */
    public ?ClientInterface $httpClient = null;

    /**
     * Augmentation of the User-Agent header. Please set
     * this so that CAs can troubleshoot bugs more easily.
     */
    public string $userAgent = '';

    /**
     * Delay between poll attempts (seconds). Only used if server
     * does not supply a Retry-After header. Default: 250ms
     */
    public float $pollInterval = 0;

    /** Maximum duration for polling (seconds). Default: 5m */
    public float $pollTimeout = 0;

    /** An optional logger. Default: no logs */
    public ?LoggerInterface $logger = null;

    private ?Directory $dir = null;

    /** @var string[] a simple stack of nonces (at most 64) */
    private array $nonces = [];

    /**
     * Directories seldom (if ever) change in practice, and
     * client structs are often ephemeral, so we can cache
     * directories to speed things up a bit for the user.
     * Keyed by directory URL.
     *
     * @var array<string, array{0: Directory, 1: float}>
     */
    private static array $directories = [];

    /** @var callable(float): void|null */
    private static $waiter = null;

    /**
     * setWaiter installs the function that performs the protocol's waits
     * (null restores plain sleeping). The certificate manager uses it to
     * answer HTTP-01 / TLS-ALPN-01 challenge requests while polling.
     *
     * @param callable(float): void|null $waiter
     */
    public static function setWaiter(?callable $waiter): void
    {
        self::$waiter = $waiter;
    }

    /**
     * wait pauses for the given number of seconds (Go: `<-time.After(d)`),
     * through the installed waiter when there is one.
     */
    public static function wait(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        if (self::$waiter !== null) {
            (self::$waiter)($seconds);
            return;
        }
        usleep((int) round($seconds * 1_000_000));
    }

    /**
     * GetDirectory retrieves the directory configured at $directory. It is
     * NOT necessary to call this to provision the client. It is only useful
     * if you want to access a copy of the directory yourself.
     *
     * @throws \RuntimeException
     */
    public function getDirectory(): Directory
    {
        $this->provision();
        /** @var Directory $dir */
        $dir = $this->dir;
        return $dir;
    }

    /**
     * provision fetches the directory once.
     *
     * @throws \RuntimeException "provisioning client: ..."
     */
    public function provision(): void
    {
        try {
            $this->provisionDirectory();
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('provisioning client: %s', $err->getMessage()), 0, $err);
        }
    }

    private function provisionDirectory(): void
    {
        // don't get directory again if we already have it;
        // checking any one of the required fields will do
        if ($this->dir !== null && $this->dir->newNonce !== '') {
            return;
        }
        if ($this->directory === '') {
            throw new \RuntimeException('missing directory URL');
        }
        // prefer cached version if it's recent enough
        if (isset(self::$directories[$this->directory])) {
            [$dir, $retrieved] = self::$directories[$this->directory];
            if (microtime(true) - $retrieved < 12 * 3600) {
                $this->dir = $dir;
                return;
            }
        }
        [, $decoded] = $this->httpReq('GET', $this->directory, null, true);
        $dir = Directory::fromArray(\is_array($decoded) ? $decoded : []);
        if ($dir->newOrder === '') {
            // catch faulty ACME servers that may not return proper HTTP status on errors
            throw new \RuntimeException(sprintf('server did not return error headers, but required directory fields are missing: %s', JsonUtil::marshal($dir->toArray())));
        }
        $this->dir = $dir;
        self::$directories[$this->directory] = [$dir, microtime(true)];
    }

    /**
     * @throws \RuntimeException
     */
    private function nonce(): string
    {
        $nonce = array_pop($this->nonces);
        if ($nonce !== null && $nonce !== '') {
            return $nonce;
        }

        if ($this->dir === null || $this->dir->newNonce === '') {
            throw new \RuntimeException('directory missing newNonce endpoint');
        }

        try {
            [$resp] = $this->httpReq('HEAD', $this->dir->newNonce, null, false);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('fetching new nonce from server: %s', $err->getMessage()), 0, $err);
        }

        return $resp->getHeaderLine(self::replayNonce);
    }

    /** push remembers a nonce (the stack keeps at most 64). */
    private function pushNonce(string $v): void
    {
        if ($v === '') {
            return;
        }
        if (\count($this->nonces) >= 64) {
            return;
        }
        $this->nonces[] = $v;
    }

    public function pollIntervalSec(): float
    {
        if ($this->pollInterval == 0) {
            return self::defaultPollInterval;
        }
        return $this->pollInterval;
    }

    public function pollTimeoutSec(): float
    {
        if ($this->pollTimeout == 0) {
            return self::defaultPollTimeout;
        }
        return $this->pollTimeout;
    }

    /*
     * ---- http.go ----
     */

    /**
     * httpPostJWS performs robust HTTP requests by JWS-encoding the JSON of input.
     * If output is true, the response body is returned decoded: if the response
     * Content-Type is JSON, it is JSON-decoded into an array; otherwise the raw
     * body string is returned. It automatically retries in the case of network,
     * I/O, or badNonce errors.
     *
     * @return array{0: ResponseInterface, 1: mixed}
     * @throws Problem|HttpError|\RuntimeException
     */
    public function httpPostJWS(\OpenSSLAsymmetricKey $privateKey, string $kid, string $endpoint, mixed $input, bool $output): array
    {
        $this->provision();

        $err = null;

        // we can retry on internal server errors just in case it was a hiccup,
        // but we probably don't need to retry so many times in that case
        $internalServerErrors = 0;
        $maxInternalServerErrors = 3;

        // set a hard cap on the number of retries for any other reason
        $maxAttempts = 10;
        for ($attempts = 1; $attempts <= $maxAttempts; $attempts++) {
            if ($attempts > 1) {
                self::wait(0.25);
            }

            $nonce = $this->nonce();

            try {
                $encodedPayload = Jws::jwsEncodeJSON($input, $privateKey, $kid, $nonce, $endpoint);
            } catch (\Throwable $e) {
                throw new \RuntimeException(sprintf('encoding payload: %s', $e->getMessage()), 0, $e);
            }

            try {
                return $this->httpReq('POST', $endpoint, $encodedPayload, $output);
            } catch (\Throwable $e) {
                $err = $e;
            }

            // "When a server rejects a request because its nonce value was
            // unacceptable (or not present), it MUST provide HTTP status code 400
            // (Bad Request), and indicate the ACME error type
            // 'urn:ietf:params:acme:error:badNonce'.  An error response with the
            // 'badNonce' error type MUST include a Replay-Nonce header field with a
            // fresh nonce that the server will accept in a retry of the original
            // query (and possibly in other requests, according to the server's
            // nonce scoping policy).  On receiving such a response, a client SHOULD
            // retry the request using the new nonce." §6.5
            $problem = Problem::from($err);
            if ($problem !== null && $problem->type === Problem::ProblemTypeBadNonce) {
                $this->logger?->debug('server rejected our nonce; retrying', ['detail' => $problem->detail, 'error' => $err->getMessage()]);
                continue;
            }

            // internal server errors *could* just be a hiccup and it may be worth
            // trying again, but not nearly so many times as for other reasons
            $resp = HttpError::responseOf($err);
            if ($resp !== null && $resp->getStatusCode() >= 500) {
                $internalServerErrors++;
                if ($internalServerErrors < $maxInternalServerErrors) {
                    continue;
                }
            }

            // for any other error, there's not much we can do automatically
            break;
        }

        throw new \RuntimeException(sprintf('attempt %d: %s: %s', $attempts, $endpoint, $err !== null ? $err->getMessage() : 'unknown error'), 0, $err);
    }

    /**
     * httpReq robustly performs an HTTP request using the given method to the given endpoint.
     * The joseJSONPayload is optional; if not null, it is expected to be a JOSE+JSON encoding.
     * If output is true, the response body is returned: JSON-decoded into an array when the
     * response Content-Type is JSON, as the raw string otherwise.
     *
     * If there are any network or I/O errors, the request will be retried as safely and
     * resiliently as possible.
     *
     * @return array{0: ResponseInterface, 1: mixed}
     * @throws Problem|HttpError|\RuntimeException
     */
    public function httpReq(string $method, string $endpoint, ?string $joseJSONPayload, bool $output): array
    {
        $resp = null;
        $err = null;
        $body = '';

        // potentially retry the request if there's network, I/O, or server internal errors
        $maxAttempts = 3;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($attempt > 0) {
                // traffic calming ahead
                self::wait(0.25);
            }

            $headers = ['User-Agent' => $this->userAgentString()];
            if ($joseJSONPayload !== null && $joseJSONPayload !== '') {
                $headers['Content-Type'] = 'application/jose+json';
            }

            // doHTTPRequest
            try {
                $resp = $this->httpClientInstance()->request($method, $endpoint, [
                    'headers' => $headers,
                    'body' => $joseJSONPayload,
                    'http_errors' => false,
                ]);
            } catch (GuzzleException $e) {
                $err = new HttpError(sprintf('performing request: %s', $e->getMessage()), null, $e);
                $this->logger?->warning('HTTP request failed; retrying', ['url' => $endpoint, 'error' => $err->getMessage()]);
                continue;
            }

            $this->logger?->debug('http request', [
                'method' => $method,
                'url' => $endpoint,
                'response_headers' => $resp->getHeaders(),
                'status_code' => $resp->getStatusCode(),
            ]);

            // "The server MUST include a Replay-Nonce header field
            // in every successful response to a POST request and
            // SHOULD provide it in error responses as well." §6.5
            //
            // "Before sending a POST request to the server, an ACME
            // client needs to have a fresh anti-replay nonce to put
            // in the 'nonce' header of the JWS.  In most cases, the
            // client will have gotten a nonce from a previous
            // request." §7.2
            //
            // So basically, we need to remember the nonces we get
            // and use them at the next opportunity.
            $this->pushNonce($resp->getHeaderLine(self::replayNonce));

            // drain the response body, even if we aren't keeping it
            // (this allows us to reuse the connection and also read
            // any error information)
            try {
                $body = (string) $resp->getBody();
            } catch (\Throwable $e) {
                // this is likely a network or I/O error, but is it worth retrying?
                // technically the request has already completed, it was just our
                // download of the response that failed; so we probably should not
                // retry if the request succeeded... however, if there was an HTTP
                // error, it likely didn't count against any server-enforced rate
                // limits, and we DO want to know the error information, so it should
                // be safe to retry the request in those cases AS LONG AS there is
                // no request body, which in the context of ACME likely includes an
                // anti-replay nonce, which obviously we can't reuse
                $err = new HttpError(sprintf('reading response body: %s', $e->getMessage()), $resp, $e);
                $retry = $resp->getStatusCode() >= 400 && $joseJSONPayload === null;
                if ($retry) {
                    $this->logger?->warning('HTTP request failed; retrying', ['url' => $endpoint, 'error' => $err->getMessage()]);
                    continue;
                }
                break;
            }
            $err = null;

            // check for HTTP errors
            $status = $resp->getStatusCode();
            if ($status >= 200 && $status < 300) { // OK
                break;
            }
            if ($status >= 400 && $status < 600) { // error
                if (self::parseMediaType($resp) === 'application/problem+json') {
                    // "When the server responds with an error status, it SHOULD provide
                    // additional information using a problem document [RFC7807]." (§6.7)
                    $decoded = json_decode($body, true);
                    if (!\is_array($decoded)) {
                        throw new HttpError(sprintf("HTTP %d: JSON-decoding problem details: %s (raw='%s')", $status, json_last_error_msg(), $body), $resp);
                    }
                    if ((int) ($decoded['status'] ?? 0) === 0) {
                        // for some reason, some servers omit the status, for example:
                        // https://caddy.community/t/acme-account-is-not-regenerated-when-acme-server-gets-reinstalled/22627
                        $decoded['status'] = $status;
                    }
                    $problem = Problem::fromArray($decoded);
                    $problem->httpResponse = $resp;
                    if ($status >= 500 && $joseJSONPayload === null) {
                        // a 5xx status is probably safe to retry on even after a
                        // request that had no I/O errors; it could be that the
                        // server just had a hiccup... so try again, but only if
                        // there is no request body, because we can't replay a
                        // request that has an anti-replay nonce, obviously
                        $err = $problem;
                        continue;
                    }
                    throw $problem;
                }
                throw new HttpError(sprintf('HTTP %d: %s', $status, $body), $resp);
            }
            // what even is this
            throw new HttpError(sprintf('unexpected status code: HTTP %d', $status), $resp);
        }
        if ($err !== null) {
            throw $err;
        }
        if ($resp === null) {
            throw new \RuntimeException('performing request: no response');
        }

        // if expecting a body, finally decode it
        $decoded = null;
        if ($output) {
            $contentType = self::parseMediaType($resp);
            if ($contentType === 'application/json') {
                // unmarshal JSON
                $decoded = json_decode($body, true);
                if (!\is_array($decoded)) {
                    throw new HttpError(sprintf('JSON-decoding response body: %s', json_last_error_msg()), $resp);
                }
            } else {
                // don't interpret anything else here; just hope
                // it's a Writer and copy the bytes
                $decoded = $body;
            }
        }

        return [$resp, $decoded];
    }

    private function httpClientInstance(): ClientInterface
    {
        if ($this->httpClient === null) {
            $this->httpClient = new GuzzleClient(['timeout' => 30, 'connect_timeout' => 30]);
        }
        return $this->httpClient;
    }

    private function userAgentString(): string
    {
        $ua = sprintf('acmez (%s; %s)', strtolower(\PHP_OS_FAMILY), php_uname('m'));
        if ($this->userAgent !== '') {
            $ua = $this->userAgent . ' ' . $ua;
        }
        return $ua;
    }

    /**
     * extractLinks extracts the URL from the Link header with the
     * designated relation rel. It may return more than value
     * if there are multiple matching Link values.
     *
     * @return string[]
     */
    public static function extractLinks(?ResponseInterface $resp, string $rel): array
    {
        if ($resp === null) {
            return [];
        }
        $links = [];
        foreach ($resp->getHeader('Link') as $l) {
            if (preg_match_all('/<(.+?)>;\s*rel="(.+?)"/', $l, $matches, \PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    if ($m[2] === $rel) {
                        $links[] = $m[1];
                    }
                }
            }
        }
        return $links;
    }

    /**
     * parseMediaType returns only the media type from the
     * Content-Type header of resp.
     */
    public static function parseMediaType(?ResponseInterface $resp): string
    {
        if ($resp === null) {
            return '';
        }
        $ct = $resp->getHeaderLine('Content-Type');
        $sep = strpos($ct, ';');
        if ($sep === false) {
            return $ct;
        }
        return trim(substr($ct, 0, $sep));
    }

    /**
     * retryAfter returns a duration (seconds) from the response's Retry-After
     * header field, if it exists. It throws if the header contains an invalid
     * value. If there is no Retry-After header provided, then the fallback
     * duration is returned instead.
     *
     * @throws \RuntimeException
     */
    public static function retryAfter(?ResponseInterface $resp, float $fallback): float
    {
        if ($resp === null) {
            return $fallback;
        }
        try {
            $raTime = self::retryAfterTime($resp);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('response had invalid Retry-After header: %s', $err->getMessage()), 0, $err);
        }
        if ($raTime === null) {
            return $fallback;
        }
        return Rfc3339::seconds($raTime) - microtime(true);
    }

    /**
     * retryAfterTime returns the timestamp represented by the Retry-After header of the response.
     * It returns null if there is no Retry-After header.
     *
     * @throws \RuntimeException
     */
    public static function retryAfterTime(?ResponseInterface $resp): ?\DateTimeImmutable
    {
        if ($resp === null) {
            return null;
        }
        $raHeader = $resp->getHeaderLine('Retry-After');
        if ($raHeader === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $raHeader)) {
            return Rfc3339::now()->modify('+' . (int) $raHeader . ' seconds');
        }
        $t = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC7231, $raHeader);
        if ($t === false) {
            throw new \RuntimeException(sprintf('parsing time "%s" as HTTP-date', $raHeader));
        }
        return $t;
    }

    /*
     * ---- account.go ----
     */

    /**
     * NewAccount creates a new account on the ACME server.
     *
     * "A client creates a new account with the server by sending a POST
     * request to the server's newAccount URL." §7.3
     *
     * @throws \RuntimeException
     */
    public function newAccount(Account $account): Account
    {
        $this->provision();
        return $this->postAccount($this->getDirectory()->newAccount, $account, false);
    }

    /**
     * GetAccount looks up an account on the ACME server.
     *
     * "If a client wishes to find the URL for an existing account and does
     * not want an account to be created if one does not already exist, then
     * it SHOULD do so by sending a POST request to the newAccount URL with
     * a JWS whose payload has an 'onlyReturnExisting' field set to 'true'."
     * §7.3.1
     *
     * @throws \RuntimeException
     */
    public function getAccount(Account $account): Account
    {
        $this->provision();
        return $this->postAccount($this->getDirectory()->newAccount, $account, true);
    }

    /**
     * UpdateAccount updates account information on the ACME server.
     *
     * "If the client wishes to update this information in the future, it
     * sends a POST request with updated information to the account URL.
     * The server MUST ignore any updates to the 'orders' field,
     * 'termsOfServiceAgreed' field (see Section 7.3.3), the 'status' field
     * (except as allowed by Section 7.3.6), or any other fields it does not
     * recognize." §7.3.2
     *
     * This method uses the account.Location value as the account URL.
     *
     * @throws \RuntimeException
     */
    public function updateAccount(Account $account): Account
    {
        return $this->postAccount($account->location, $account, false);
    }

    /**
     * AccountKeyRollover changes an account's associated key.
     *
     * "To change the key associated with an account, the client sends a
     * request to the server containing signatures by both the old and new
     * keys." §7.3.5
     *
     * @throws \RuntimeException
     */
    public function accountKeyRollover(Account $account, \OpenSSLAsymmetricKey $newPrivateKey): Account
    {
        $this->provision();
        if ($account->privateKey === null) {
            throw new \RuntimeException('encoding old private key: account has no private key');
        }

        try {
            $oldPublicKeyJWK = Jws::jwkEncode($account->privateKey);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('encoding old private key: %s', $err->getMessage()), 0, $err);
        }

        $keyChangeReq = [
            'account' => $account->location,
            'oldKey' => json_decode($oldPublicKeyJWK, true),
        ];

        try {
            $innerJWS = Jws::jwsEncodeJSON($keyChangeReq, $newPrivateKey, '', '', $this->getDirectory()->keyChange);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('encoding inner JWS: %s', $err->getMessage()), 0, $err);
        }

        try {
            $this->httpPostJWS($account->privateKey, $account->location, $this->getDirectory()->keyChange, json_decode($innerJWS, true), false);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('rolling key on server: %s', $err->getMessage()), 0, $err);
        }

        $account = clone $account;
        $account->privateKey = $newPrivateKey;

        return $account;
    }

    /**
     * @throws \RuntimeException
     */
    private function postAccount(string $endpoint, Account $account, bool $onlyReturnExisting): Account
    {
        $account = clone $account;
        if ($account->privateKey === null) {
            throw new \RuntimeException('account has no private key');
        }

        // Normally, the account URL is the key ID ("kid")... except when the user
        // is trying to get the correct account URL. In that case, we must ignore
        // any existing URL we may have and not set the kid field on the request.
        // Arguably, this is a user error (spec says "If client wishes to find the
        // URL for an existing account", so why would the URL already be filled
        // out?) but it's easy enough to infer their intent and make it work.
        $kid = $account->location;
        if ($onlyReturnExisting) {
            $kid = '';
        }

        $payload = $account->jsonSerialize();
        if ($onlyReturnExisting) {
            $payload['onlyReturnExisting'] = true;
        }

        [$resp, $decoded] = $this->httpPostJWS($account->privateKey, $kid, $endpoint, $payload, true);
        if (\is_array($decoded)) {
            $account->applyArray($decoded);
        }

        $account->location = $resp->getHeaderLine('Location');

        return $account;
    }

    /*
     * ---- order.go ----
     */

    /**
     * NewOrder creates a new order with the server.
     *
     * "The client begins the certificate issuance process by sending a POST
     * request to the server's newOrder resource." §7.4
     *
     * @throws \RuntimeException
     */
    public function newOrder(Account $account, Order $order): Order
    {
        $this->provision();
        $order = clone $order;
        $this->logger?->debug('creating order', ['account' => $account->location, 'identifiers' => $order->identifierValues()]);
        $dir = $this->getDirectory();
        if ($order->profile !== '') {
            // "The client MUST NOT request a profile name that is not advertised in the server's Directory metadata object."
            // https://www.ietf.org/archive/id/draft-aaron-acme-profiles-00.html#section-4
            if ($dir->meta === null) {
                throw new \RuntimeException('ACME server does not advertise support for profiles: <nil>');
            }
            if (!\array_key_exists($order->profile, $dir->meta->profiles)) {
                throw new \RuntimeException(sprintf("unknown profile name '%s'; supported profiles: %s", $order->profile, JsonUtil::marshal($dir->meta->profiles)));
            }
        }
        if ($account->privateKey === null) {
            throw new \RuntimeException('account has no private key');
        }
        [$resp, $decoded] = $this->httpPostJWS($account->privateKey, $account->location, $dir->newOrder, $order, true);
        if (\is_array($decoded)) {
            $order->applyArray($decoded);
        }
        $order->location = $resp->getHeaderLine('Location');
        return $order;
    }

    /**
     * GetOrder retrieves an order from the server. The Order's Location field must be populated.
     *
     * @throws \RuntimeException
     */
    public function getOrder(Account $account, Order $order): Order
    {
        $this->provision();
        $order = clone $order;
        if ($account->privateKey === null) {
            throw new \RuntimeException('account has no private key');
        }
        [, $decoded] = $this->httpPostJWS($account->privateKey, $account->location, $order->location, null, true);
        if (\is_array($decoded)) {
            $order->applyArray($decoded);
        }
        return $order;
    }

    /**
     * FinalizeOrder finalizes the order with the server and polls until the server has
     * updated the order status. The CSR must be in ASN.1 DER-encoded format. If this
     * succeeds, the certificate is ready to download once this returns.
     *
     * "Once the client believes it has fulfilled the server's requirements,
     * it should send a POST request to the order resource's finalize URL." §7.4
     *
     * @throws \RuntimeException
     */
    public function finalizeOrder(Account $account, Order $order, string $csrASN1DER): Order
    {
        $this->provision();
        $order = clone $order;
        if ($account->privateKey === null) {
            throw new \RuntimeException('account has no private key');
        }

        $body = [
            // csr (required, string):  A CSR encoding the parameters for the
            // certificate being requested [RFC2986].  The CSR is sent in the
            // base64url-encoded version of the DER format.  (Note: Because this
            // field uses base64url, and does not include headers, it is
            // different from PEM.) §7.4
            'csr' => Jws::base64url($csrASN1DER),
        ];

        $resp = null;
        try {
            [$resp, $decoded] = $this->httpPostJWS($account->privateKey, $account->location, $order->finalize, $body, true);
            if (\is_array($decoded)) {
                $order->applyArray($decoded);
            }
        } catch (\Throwable $err) {
            // "A request to finalize an order will result in error if the order is
            // not in the 'ready' state.  In such cases, the server MUST return a
            // 403 (Forbidden) error with a problem document of type
            // 'orderNotReady'.  The client should then send a POST-as-GET request
            // to the order resource to obtain its current state.  The status of the
            // order will indicate what action the client should take (see below)."
            // §7.4
            $problem = Problem::from($err);
            if ($problem === null || $problem->type !== Problem::ProblemTypeOrderNotReady) {
                throw $err;
            }
            $resp = HttpError::responseOf($err);
        }

        // unlike with accounts and authorizations, the spec isn't clear on whether
        // the server MUST set this on finalizing the order, but their example shows a
        // Location header, so I guess if it's set in the response, we should keep it
        if ($resp !== null && ($newLocation = $resp->getHeaderLine('Location')) !== '') {
            $order->location = $newLocation;
        }

        if (self::orderIsFinished($order)) {
            return $order;
        }

        // TODO: "The elements of the "authorizations" and "identifiers" arrays are
        // immutable once set. If a client observes a change
        // in the contents of either array, then it SHOULD consider the order
        // invalid."

        $maxDuration = $this->pollTimeoutSec();
        $start = microtime(true);
        while (microtime(true) - $start < $maxDuration) {
            // querying an order is expensive on the server-side, so we
            // shouldn't do it too frequently; honor server preference
            $interval = self::retryAfter($resp, $this->pollIntervalSec());
            self::wait($interval);

            try {
                [$resp, $decoded] = $this->httpPostJWS($account->privateKey, $account->location, $order->location, null, true);
            } catch (\Throwable $err) {
                throw new \RuntimeException(sprintf('polling order status: %s', $err->getMessage()), 0, $err);
            }
            if (\is_array($decoded)) {
                $order->applyArray($decoded);
            }

            // (same reasoning as above)
            if (($newLocation = $resp->getHeaderLine('Location')) !== '') {
                $order->location = $newLocation;
            }

            if (self::orderIsFinished($order)) {
                return $order;
            }
        }

        throw new \RuntimeException('order took too long');
    }

    /**
     * orderIsFinished returns true if the order processing is complete,
     * regardless of success or failure. If this function returns true,
     * polling an order status should stop. If there is an error with the
     * order, an error will be thrown. This function should be called
     * only after a request to finalize an order. See §7.4.
     *
     * @throws \RuntimeException
     */
    private static function orderIsFinished(Order $order): bool
    {
        switch ($order->status) {
            case Account::StatusInvalid:
                // "invalid": The certificate will not be issued.  Consider this
                //      order process abandoned.
                throw new \RuntimeException(sprintf('final order is invalid: %s', $order->error !== null ? $order->error->getMessage() : '%!w(<nil>)'), 0, $order->error);

            case Account::StatusPending:
                // "pending": The server does not believe that the client has
                //      fulfilled the requirements.  Check the "authorizations" array for
                //      entries that are still pending.
                throw new \RuntimeException(sprintf('order pending, authorizations remaining: [%s]', implode(' ', $order->authorizations ?? [])));

            case Account::StatusReady:
                // "ready": The server agrees that the requirements have been
                //      fulfilled, and is awaiting finalization.  Submit a finalization
                //      request.
                // (we did just submit a finalization request, so this is an error)
                throw new \RuntimeException(sprintf('unexpected state: %s - order already finalized', $order->status));

            case Account::StatusProcessing:
                // "processing": The certificate is being issued.  Send a GET request
                //      after the time given in the "Retry-After" header field of the
                //      response, if any.
                return false;

            case Account::StatusValid:
                // "valid": The server has issued the certificate and provisioned its
                //      URL to the "certificate" field of the order.  Download the
                //      certificate.
                return true;

            default:
                throw new \RuntimeException(sprintf('unrecognized order status: %s', $order->status));
        }
    }

    /*
     * ---- authorization.go ----
     */

    /**
     * NewAuthorization creates a new authorization for an identifier using
     * the newAuthz endpoint of the directory, if available. This function
     * creates authzs out of the regular order flow.
     *
     * "Note that because the identifier in a pre-authorization request is
     * the exact identifier to be included in the authorization object, pre-
     * authorization cannot be used to authorize issuance of certificates
     * containing wildcard domain names." §7.4.1
     *
     * @throws \RuntimeException
     */
    public function newAuthorization(Account $account, Identifier $id): Authorization
    {
        $this->provision();
        $dir = $this->getDirectory();
        if ($dir->newAuthz === '') {
            throw new \RuntimeException('server does not support newAuthz endpoint');
        }
        if ($account->privateKey === null) {
            throw new \RuntimeException('account has no private key');
        }

        $authz = new Authorization();
        [$resp, $decoded] = $this->httpPostJWS($account->privateKey, $account->location, $dir->newAuthz, $id, true);
        if (\is_array($decoded)) {
            $authz->applyArray($decoded);
        }

        $authz->location = $resp->getHeaderLine('Location');

        $authz->fillChallengeFields($account);

        return $authz;
    }

    /**
     * GetAuthorization fetches an authorization object from the server.
     *
     * "Authorization resources are created by the server in response to
     * newOrder or newAuthz requests submitted by an account key holder;
     * their URLs are provided to the client in the responses to these
     * requests."
     *
     * "When a client receives an order from the server in reply to a
     * newOrder request, it downloads the authorization resources by sending
     * POST-as-GET requests to the indicated URLs.  If the client initiates
     * authorization using a request to the newAuthz resource, it will have
     * already received the pending authorization object in the response to
     * that request." §7.5
     *
     * @throws \RuntimeException
     */
    public function getAuthorization(Account $account, string $authzURL): Authorization
    {
        $this->provision();
        if ($account->privateKey === null) {
            throw new \RuntimeException('account has no private key');
        }

        $authz = new Authorization();
        [, $decoded] = $this->httpPostJWS($account->privateKey, $account->location, $authzURL, null, true);
        if (\is_array($decoded)) {
            $authz->applyArray($decoded);
        }

        $authz->location = $authzURL;

        $authz->fillChallengeFields($account);

        return $authz;
    }

    /**
     * PollAuthorization polls the authorization resource endpoint until the authorization is
     * considered "finalized" which means that it either succeeded, failed, or was abandoned.
     * It blocks until that happens or until the configured timeout.
     *
     * "Usually, the validation process will take some time, so the client
     * will need to poll the authorization resource to see when it is
     * finalized."
     *
     * "For challenges where the client can tell when the server
     * has validated the challenge (e.g., by seeing an HTTP or DNS request
     * from the server), the client SHOULD NOT begin polling until it has
     * seen the validation request from the server." §7.5.1
     *
     * @throws \RuntimeException
     */
    public function pollAuthorization(Account $account, Authorization $authz): Authorization
    {
        $start = microtime(true);
        $interval = $this->pollIntervalSec();
        $maxDuration = $this->pollTimeoutSec();

        if ($authz->status !== '') {
            if (self::authzIsFinalized($authz)) {
                return $authz;
            }
        }
        if ($account->privateKey === null) {
            throw new \RuntimeException('account has no private key');
        }

        while (microtime(true) - $start < $maxDuration) {
            self::wait($interval);

            // get the latest authz object
            try {
                [$resp, $decoded] = $this->httpPostJWS($account->privateKey, $account->location, $authz->location, null, true);
            } catch (\Throwable $err) {
                throw new \RuntimeException(sprintf('checking authorization status: %s', $err->getMessage()), 0, $err);
            }
            if (\is_array($decoded)) {
                $authz->applyArray($decoded);
            }
            if (self::authzIsFinalized($authz)) {
                return $authz;
            }

            // "The server MUST provide information about its retry state to the
            // client via the 'error' field in the challenge and the Retry-After
            // HTTP header field in response to requests to the challenge resource."
            // §8.2
            $interval = self::retryAfter($resp, $interval);
        }

        throw new \RuntimeException('authorization took too long');
    }

    /**
     * DeactivateAuthorization deactivates an authorization on the server, which is
     * a good idea if the authorization is not going to be utilized by the client.
     *
     * "If a client wishes to relinquish its authorization to issue
     * certificates for an identifier, then it may request that the server
     * deactivate each authorization associated with it by sending POST
     * requests with the static object {"status": "deactivated"} to each
     * authorization URL." §7.5.2
     *
     * @throws \RuntimeException
     */
    public function deactivateAuthorization(Account $account, string $authzURL): Authorization
    {
        $this->provision();

        if ($authzURL === '') {
            throw new \RuntimeException('empty authz url');
        }
        if ($account->privateKey === null) {
            throw new \RuntimeException('account has no private key');
        }

        $deactivate = ['status' => 'deactivated'];

        $authz = new Authorization();
        $authz->location = $authzURL;
        try {
            [, $decoded] = $this->httpPostJWS($account->privateKey, $account->location, $authzURL, $deactivate, true);
        } catch (\Throwable $err) {
            throw new \RuntimeException($err->getMessage(), 0, $err);
        }
        if (\is_array($decoded)) {
            $authz->applyArray($decoded);
        }

        return $authz;
    }

    /**
     * authzIsFinalized returns true if the authorization is finished,
     * whether successfully or not. If not, an error will be thrown.
     * Post-valid statuses that make an authz unusable are treated as
     * errors.
     *
     * @throws \RuntimeException
     */
    private static function authzIsFinalized(Authorization $authz): bool
    {
        switch ($authz->status) {
            case Account::StatusPending:
                // "Authorization objects are created in the 'pending' state." §7.1.6
                return false;

            case Account::StatusValid:
                // "If one of the challenges listed in the authorization transitions
                // to the 'valid' state, then the authorization also changes to the
                // 'valid' state." §7.1.6
                return true;

            case Account::StatusInvalid:
                // "If the client attempts to fulfill a challenge and fails, or if
                // there is an error while the authorization is still pending, then
                // the authorization transitions to the 'invalid' state." §7.1.6
                $firstProblem = null;
                foreach ($authz->challenges as $chal) {
                    if ($chal->error !== null) {
                        $firstProblem = $chal->error;
                        break;
                    }
                }
                $firstProblem ??= new Problem();
                $firstProblem->resource = $authz;
                throw new \RuntimeException(sprintf('authorization failed: %s', $firstProblem->getMessage()), 0, $firstProblem);

            case Account::StatusExpired:
            case Account::StatusDeactivated:
            case Account::StatusRevoked:
                // Once the authorization is in the 'valid' state, it can expire
                // ('expired'), be deactivated by the client ('deactivated', see
                // Section 7.5.2), or revoked by the server ('revoked')." §7.1.6
                throw new \RuntimeException(sprintf('authorization %s', $authz->status));

            case '':
                throw new \RuntimeException('status unknown');

            default:
                throw new \RuntimeException(sprintf('server set unrecognized authorization status: %s', $authz->status));
        }
    }

    /*
     * ---- challenge.go ----
     */

    /**
     * InitiateChallenge "indicates to the server that it is ready for the challenge
     * validation by sending an empty JSON body ('{}') carried in a POST request to
     * the challenge URL (not the authorization URL)." §7.5.1
     *
     * @throws \RuntimeException
     */
    public function initiateChallenge(Account $account, Challenge $challenge): Challenge
    {
        $this->provision();
        $challenge = clone $challenge;
        if ($challenge->payload === null) {
            $challenge->payload = new \stdClass();
        }
        if ($account->privateKey === null) {
            throw new \RuntimeException('account has no private key');
        }
        [, $decoded] = $this->httpPostJWS($account->privateKey, $account->location, $challenge->url, $challenge->payload, true);
        if (\is_array($decoded)) {
            $challenge->applyArray($decoded);
        }
        return $challenge;
    }

    /*
     * ---- certificate.go ----
     */

    /**
     * GetCertificateChain downloads all available certificate chains originating from
     * the given certURL. This is to be done after an order is finalized.
     *
     * "To download the issued certificate, the client simply sends a POST-
     * as-GET request to the certificate URL."
     *
     * "The server MAY provide one or more link relation header fields
     * [RFC8288] with relation 'alternate'.  Each such field SHOULD express
     * an alternative certificate chain starting with the same end-entity
     * certificate.  This can be used to express paths to various trust
     * anchors.  Clients can fetch these alternates and use their own
     * heuristics to decide which is optimal." §7.4.2
     *
     * @return AcmeCertificate[]
     * @throws \RuntimeException
     */
    public function getCertificateChain(Account $account, string $certURL): array
    {
        $this->provision();
        if ($account->privateKey === null) {
            throw new \RuntimeException('account has no private key');
        }

        /** @var AcmeCertificate[] $chains */
        $chains = [];

        $addChain = function (string $certURL) use (&$chains, $account): ResponseInterface {
            // TODO: set the Accept header? ("application/pem-certificate-chain") See end of §7.4.2
            /** @var \OpenSSLAsymmetricKey $key */
            $key = $account->privateKey;
            [$resp, $decoded] = $this->httpPostJWS($key, $account->location, $certURL, null, true);
            $contentType = self::parseMediaType($resp);

            // extract the chain depending on Content-Type
            switch ($contentType) {
                case 'application/pem-certificate-chain':
                    $chainPEM = \is_string($decoded) ? $decoded : '';
                    break;
                default:
                    throw new HttpError(sprintf('unrecognized Content-Type from server: %s', $contentType), $resp);
            }

            $certChain = new AcmeCertificate();
            $certChain->url = $certURL;
            $certChain->chainPEM = $chainPEM;
            $certChain->ca = $this->directory;
            $certChain->account = $account->location;

            // attach renewal information, if applicable (draft-ietf-acme-ari-03)
            if ($this->getDirectory()->renewalInfo !== '') {
                if (preg_match('/-----BEGIN CERTIFICATE-----/', $chainPEM)) {
                    try {
                        $leafCert = X509Certificate::parsePEM($chainPEM);
                    } catch (\Throwable $err) {
                        throw new HttpError(sprintf('invalid first PEM block of chain: %s', $err->getMessage()), $resp, $err);
                    }
                    $ari = new RenewalInfo();
                    try {
                        $ari = $this->getRenewalInfo($leafCert);
                    } catch (\Throwable $err) {
                        $this->logger?->error('failed getting renewal information', ['error' => $err->getMessage()]);
                    }
                    $certChain->renewalInfo = $ari;
                }
            }

            $chains[] = $certChain;

            // "For formats that can only express a single certificate, the server SHOULD
            // provide one or more "Link: rel="up"" header fields pointing to an
            // issuer or issuers so that ACME clients can build a certificate chain
            // as defined in TLS (see Section 4.4.2 of [RFC8446])." (end of §7.4.2)
            $allUp = self::extractLinks($resp, 'up');
            foreach ($allUp as $upURL) {
                try {
                    $upCerts = $this->getCertificateChain($account, $upURL);
                } catch (\Throwable $err) {
                    throw new \RuntimeException(sprintf('retrieving next certificate in chain: %s: %s', $upURL, $err->getMessage()), 0, $err);
                }
                foreach ($upCerts as $upCert) {
                    $chains[\count($chains) - 1]->chainPEM .= $upCert->chainPEM;
                }
            }

            return $resp;
        };

        // always add preferred/first certificate chain
        $resp = $addChain($certURL);

        // "The server MAY provide one or more link relation header fields
        // [RFC8288] with relation 'alternate'.  Each such field SHOULD express
        // an alternative certificate chain starting with the same end-entity
        // certificate.  This can be used to express paths to various trust
        // anchors.  Clients can fetch these alternates and use their own
        // heuristics to decide which is optimal." §7.4.2
        $alternates = self::extractLinks($resp, 'alternate');
        foreach ($alternates as $altURL) {
            try {
                $addChain($altURL);
            } catch (\Throwable $err) {
                throw new \RuntimeException(sprintf('retrieving alternate certificate chain at %s: %s', $altURL, $err->getMessage()), 0, $err);
            }
        }

        return $chains;
    }

    /**
     * RevokeCertificate revokes the given certificate. If the certificate key is not
     * provided, then the account key is used instead. See §7.6.
     *
     * @throws \RuntimeException
     */
    public function revokeCertificate(Account $account, X509Certificate $cert, ?\OpenSSLAsymmetricKey $certKey, int $reason): void
    {
        $this->provision();

        $body = [
            'certificate' => Jws::base64url($cert->raw),
            'reason' => $reason,
        ];

        // "Revocation requests are different from other ACME requests in that
        // they can be signed with either an account key pair or the key pair in
        // the certificate." §7.6
        $kid = '';
        if ($certKey === null || $certKey === $account->privateKey) {
            $certKey = $account->privateKey;
            $kid = $account->location;
        }
        if ($certKey === null) {
            throw new \RuntimeException('account has no private key');
        }

        $this->httpPostJWS($certKey, $kid, $this->getDirectory()->revokeCert, $body, false);
    }

    /*
     * ---- ari.go ----
     */

    /**
     * GetRenewalInfo returns the ACME Renewal Information (ARI) for the certificate.
     * It fills in the Retry-After value, if present, onto the returned struct so
     * the caller can poll appropriately. If the ACME server does not support ARI,
     * an error mentioning {@see ErrUnsupported} is thrown.
     *
     * @throws \RuntimeException
     */
    public function getRenewalInfo(X509Certificate $leafCert): RenewalInfo
    {
        $this->provision();
        if ($this->getDirectory()->renewalInfo === '') {
            throw new \RuntimeException(sprintf('%s: directory does not indicate ARI support (missing renewalInfo)', self::ErrUnsupported));
        }

        $this->logger?->debug('getting renewal info', ['names' => $leafCert->dnsNames]);

        $certID = self::ariUniqueIdentifier($leafCert);

        $ari = new RenewalInfo();
        $resp = null;
        $err = null;
        for ($i = 0; $i < 3; $i++) {
            // backoff between retries; the if is probably not needed, but just for "properness"...
            if ($i > 0) {
                self::wait((float) ($i * $i + 1));
            }

            try {
                [$resp, $decoded] = $this->httpReq('GET', $this->ariEndpoint($certID), null, true);
                $err = null;
            } catch (\Throwable $e) {
                $err = $e;
                $resp = null;
                $this->logger?->warning('error getting ARI response', ['error' => $e->getMessage(), 'attempt' => $i, 'names' => $leafCert->dnsNames]);
                continue;
            }
            $ari = RenewalInfo::fromArray(\is_array($decoded) ? $decoded : []);

            // "If the client receives no response or a malformed response
            // (e.g. an end timestamp which is equal to or precedes the start
            // timestamp), it SHOULD make its own determination of when to
            // renew the certificate, and MAY retry the renewalInfo request
            // with appropriate exponential backoff behavior."
            // draft-ietf-acme-ari-04 §4.2
            if ($ari->suggestedWindowStart === null
                || $ari->suggestedWindowEnd === null
                || $ari->suggestedWindowStart->format('U.u') === $ari->suggestedWindowEnd->format('U.u')
                || ($ari->suggestedWindowEnd->getTimestamp() - $ari->suggestedWindowStart->getTimestamp() - 1 <= 0)) {
                $this->logger?->debug('invalid ARI window', [
                    'start' => Rfc3339::encode($ari->suggestedWindowStart),
                    'end' => Rfc3339::encode($ari->suggestedWindowEnd),
                    'names' => $leafCert->dnsNames,
                ]);
                $resp = null;
                continue;
            }

            // valid ARI window
            $ari->uniqueIdentifier = $certID;
            break;
        }
        if ($err !== null || $resp === null) {
            throw new \RuntimeException(sprintf('could not get a valid ARI response; last error: %s', $err !== null ? $err->getMessage() : '<nil>'), 0, $err);
        }

        // "The server SHOULD include a Retry-After header indicating the polling
        // interval that the ACME server recommends." draft-ietf-acme-ari-03 §4.2
        $raTime = null;
        try {
            $raTime = self::retryAfterTime($resp);
        } catch (\Throwable $e) {
            $this->logger?->error('invalid Retry-After value', ['error' => $e->getMessage()]);
        }
        if ($raTime !== null) {
            $ari->retryAfter = $raTime;
        }

        // "Conforming clients MUST attempt renewal at a time of their choosing
        // based on the suggested renewal window. ... Select a uniform random
        // time within the suggested window." §4.2
        // TODO: It's unclear whether this time should be selected once
        // or every time the client wakes to check ARI (see step 5 of the
        // recommended algorithm); I've enquired here:
        // https://github.com/aarongable/draft-acme-ari/issues/70
        // We add 1 to the start time since we are dealing in seconds for
        // simplicity, but the server may provide sub-second timestamps.
        $start = $ari->suggestedWindowStart->getTimestamp() + 1;
        $end = $ari->suggestedWindowEnd->getTimestamp();
        $ari->selectedTime = Rfc3339::fromSeconds(random_int(0, max(0, $end - $start - 1)) + $start);

        $this->logger?->info('got renewal info', [
            'names' => $leafCert->dnsNames,
            'window_start' => Rfc3339::encode($ari->suggestedWindowStart),
            'window_end' => Rfc3339::encode($ari->suggestedWindowEnd),
            'selected_time' => Rfc3339::encode($ari->selectedTime),
            'recheck_after' => Rfc3339::encode($raTime),
            'explanation_url' => $ari->explanationURL,
        ]);

        return $ari;
    }

    /**
     * ariEndpoint returns the ARI endpoint URI for certificate with the
     * given ARI certificate ID, according to the configured CA's directory.
     */
    private function ariEndpoint(string $ariCertID): string
    {
        if ($this->dir === null || $this->dir->renewalInfo === '' || $ariCertID === '') {
            return '';
        }
        return $this->dir->renewalInfo . '/' . $ariCertID;
    }

    /**
     * ARIUniqueIdentifier returns the unique identifier for the certificate
     * as used by ACME Renewal Information.
     * EXPERIMENTAL: ARI is a draft RFC spec: draft-ietf-acme-ari-03
     *
     * @throws \RuntimeException
     */
    public static function ariUniqueIdentifier(X509Certificate $leafCert): string
    {
        if ($leafCert->serialNumberHex === '') {
            throw new \RuntimeException('no serial number');
        }
        // use the DER content octets to be correct even when the leading byte is 0x80
        // or greater to ensure the number is interpreted as positive; note that
        // SerialNumber.Bytes() does not account for this because it is a nuance
        // of ASN.1 DER encodings. See https://github.com/letsencrypt/website/issues/1670.
        $serialDER = $leafCert->serialNumberDERContent();
        if (\strlen($serialDER) < 1) {
            throw new \RuntimeException(sprintf('serial number DER too short: %d (%s)', \strlen($serialDER) + 2, bin2hex($serialDER)));
        }
        return Jws::base64url($leafCert->authorityKeyId) . '.' . Jws::base64url($serialDER);
    }
}
