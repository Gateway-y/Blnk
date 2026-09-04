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

namespace Blnk\Api\Middleware\Tollbooth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Port of package `tollbooth` (github.com/didip/tollbooth/v7/tollbooth.go),
 * which provides rate-limiting logic to HTTP request handler.
 *
 * Go writes the informational headers straight into the ResponseWriter's
 * header map before the handler runs. PSR-7 responses only exist after the
 * handler returns, so these functions collect the headers into a list of
 * [name, value] pairs (`$w`) that {@see applyHeaders()} adds to whichever
 * response is finally sent — the 429 or the handler's own.
 *
 * LimitHandler / LimitFuncHandler (net/http middleware constructors) are not
 * ported: the Gin middleware calls LimitByRequest directly, and so does
 * {@see \Blnk\Api\Middleware\RateLimitMiddleware}.
 */
final class Tollbooth
{
    private function __construct()
    {
    }

    /**
     * setResponseHeaders configures X-Rate-Limit-Limit and X-Rate-Limit-Duration
     *
     * @param list<array{0: string, 1: string}> $w
     */
    private static function setResponseHeaders(Limiter $lmt, array &$w, ServerRequestInterface $r): void
    {
        $w[] = ['X-Rate-Limit-Limit', sprintf('%.2F', $lmt->getMax())];
        $w[] = ['X-Rate-Limit-Duration', '1'];

        $xForwardedFor = Net::headerGet($r, 'X-Forwarded-For');
        if (trim($xForwardedFor, " \t\n\v\f\r") !== '') {
            $w[] = ['X-Rate-Limit-Request-Forwarded-For', $xForwardedFor];
        }

        $w[] = ['X-Rate-Limit-Request-Remote-Addr', Net::remoteAddr($r)];
    }

    /**
     * setRateLimitResponseHeaders configures RateLimit-Limit, RateLimit-Remaining and RateLimit-Reset
     * as seen at https://datatracker.ietf.org/doc/html/draft-ietf-httpapi-ratelimit-headers
     *
     * @param list<array{0: string, 1: string}> $w
     */
    private static function setRateLimitResponseHeaders(Limiter $lmt, array &$w, int $tokensLeft): void
    {
        $w[] = ['RateLimit-Limit', sprintf('%d', Clock::intFromFloat(round($lmt->getMax())))];
        $w[] = ['RateLimit-Reset', '1'];
        $w[] = ['RateLimit-Remaining', sprintf('%d', $tokensLeft)];
    }

    /**
     * NewLimiter is a convenience function to limiter.New.
     *
     * @param string|null $bucketDir Directory of the token bucket files ({@see TokenBucketCache}).
     */
    public static function newLimiter(float $max, ?ExpirableOptions $tbOptions, ?string $bucketDir = null): Limiter
    {
        return Limiter::new($tbOptions, $bucketDir)
            ->setMax($max)
            ->setBurst(Clock::intFromFloat(max(1.0, $max)))
            ->setIPLookups(['X-Forwarded-For', 'X-Real-IP', 'RemoteAddr']);
    }

    /**
     * LimitByKeys keeps track number of request made by keys separated by pipe.
     * It returns HTTPError when limit is exceeded.
     *
     * @param string[] $keys
     */
    public static function limitByKeys(Limiter $lmt, array $keys): ?HttpError
    {
        [$err] = self::limitByKeysAndReturn($lmt, $keys);

        return $err;
    }

    /**
     * LimitByKeysAndReturn keeps track number of request made by keys separated by pipe.
     * It returns HTTPError when limit is exceeded, and also returns the current limit value.
     *
     * @param string[] $keys
     * @return array{0: ?HttpError, 1: int}
     */
    public static function limitByKeysAndReturn(Limiter $lmt, array $keys): array
    {
        if ($lmt->limitReached(implode('|', $keys))) {
            return [new HttpError($lmt->getMessage(), $lmt->getStatusCode()), 0];
        }

        return [null, $lmt->tokens(implode('|', $keys))];
    }

    /**
     * ShouldSkipLimiter is a series of filter that decides if request should be limited or not.
     */
    public static function shouldSkipLimiter(Limiter $lmt, ServerRequestInterface $r): bool
    {
        // ---------------------------------
        // Filter by remote ip
        // If we are unable to find remoteIP, skip limiter
        $remoteIP = Libstring::remoteIP($lmt->getIPLookups(), $lmt->getForwardedForIndexFromBehind(), $r);
        $remoteIP = Libstring::canonicalizeIP($remoteIP);
        if ($remoteIP === '') {
            return true;
        }

        // ---------------------------------
        // Filter by request method
        $lmtMethods = $lmt->getMethods();
        $lmtMethodsIsSet = count($lmtMethods) > 0;

        if ($lmtMethodsIsSet) {
            // If request does not contain all of the methods in limiter,
            // skip limiter
            $requestMethodDefinedInLimiter = Libstring::stringInSlice($lmtMethods, $r->getMethod());

            if (!$requestMethodDefinedInLimiter) {
                return true;
            }
        }

        // ---------------------------------
        // Filter by request headers
        $lmtHeaders = $lmt->getHeaders();
        $lmtHeadersIsSet = count($lmtHeaders) > 0;

        if ($lmtHeadersIsSet) {
            // If request does not contain all of the headers in limiter,
            // skip limiter
            $requestHeadersDefinedInLimiter = false;

            foreach (array_keys($lmtHeaders) as $headerKey) {
                $reqHeaderValue = Net::headerGet($r, $headerKey);
                if ($reqHeaderValue !== '') {
                    $requestHeadersDefinedInLimiter = true;
                    break;
                }
            }

            if (!$requestHeadersDefinedInLimiter) {
                return true;
            }

            // ------------------------------
            // If request contains the header key but not the values,
            // skip limiter
            $requestHeadersDefinedInLimiter = false;

            foreach ($lmtHeaders as $headerKey => $headerValues) {
                if (count($headerValues) === 0) {
                    $requestHeadersDefinedInLimiter = true;
                    continue;
                }
                foreach ($headerValues as $headerValue) {
                    if (Net::headerGet($r, $headerKey) === $headerValue) {
                        $requestHeadersDefinedInLimiter = true;
                        break;
                    }
                }
            }

            if (!$requestHeadersDefinedInLimiter) {
                return true;
            }
        }

        // ---------------------------------
        // Filter by context values
        $lmtContextValues = $lmt->getContextValues();
        $lmtContextValuesIsSet = count($lmtContextValues) > 0;

        if ($lmtContextValuesIsSet) {
            // If request does not contain all of the contexts in limiter,
            // skip limiter
            $requestContextValuesDefinedInLimiter = false;

            foreach (array_keys($lmtContextValues) as $contextKey) {
                $reqContextValue = self::sprintfV($r->getAttribute($contextKey));
                if ($reqContextValue !== '') {
                    $requestContextValuesDefinedInLimiter = true;
                    break;
                }
            }

            if (!$requestContextValuesDefinedInLimiter) {
                return true;
            }

            // ------------------------------
            // If request contains the context key but not the values,
            // skip limiter
            $requestContextValuesDefinedInLimiter = false;

            foreach ($lmtContextValues as $contextKey => $contextValues) {
                foreach ($contextValues as $contextValue) {
                    // (sic) Tollbooth compares the request *header* of that name here.
                    if (Net::headerGet($r, $contextKey) === $contextValue) {
                        $requestContextValuesDefinedInLimiter = true;
                        break;
                    }
                }
            }

            if (!$requestContextValuesDefinedInLimiter) {
                return true;
            }
        }

        // ---------------------------------
        // Filter by basic auth usernames
        $lmtBasicAuthUsers = $lmt->getBasicAuthUsers();
        $lmtBasicAuthUsersIsSet = count($lmtBasicAuthUsers) > 0;

        if ($lmtBasicAuthUsersIsSet) {
            // If request does not contain all of the basic auth users in limiter,
            // skip limiter
            $requestAuthUsernameDefinedInLimiter = false;

            $basicAuth = Net::basicAuth($r);
            if ($basicAuth !== null && Libstring::stringInSlice($lmtBasicAuthUsers, $basicAuth[0])) {
                $requestAuthUsernameDefinedInLimiter = true;
            }

            if (!$requestAuthUsernameDefinedInLimiter) {
                return true;
            }
        }

        return false;
    }

    /**
     * BuildKeys generates a slice of keys to rate-limit by given limiter and request structs.
     *
     * @return string[][]
     */
    public static function buildKeys(Limiter $lmt, ServerRequestInterface $r): array
    {
        $remoteIP = Libstring::remoteIP($lmt->getIPLookups(), $lmt->getForwardedForIndexFromBehind(), $r);
        $remoteIP = Libstring::canonicalizeIP($remoteIP);
        $path = self::urlPath($r);
        $sliceKeys = [];

        $lmtMethods = $lmt->getMethods();
        $lmtHeaders = $lmt->getHeaders();
        $lmtContextValues = $lmt->getContextValues();
        $lmtBasicAuthUsers = $lmt->getBasicAuthUsers();
        $lmtIgnoreURL = $lmt->getIgnoreURL();

        $lmtHeadersIsSet = count($lmtHeaders) > 0;
        $lmtContextValuesIsSet = count($lmtContextValues) > 0;
        $lmtBasicAuthUsersIsSet = count($lmtBasicAuthUsers) > 0;

        $usernameToLimit = '';
        if ($lmtBasicAuthUsersIsSet) {
            $basicAuth = Net::basicAuth($r);
            if ($basicAuth !== null && Libstring::stringInSlice($lmtBasicAuthUsers, $basicAuth[0])) {
                $usernameToLimit = $basicAuth[0];
            }
        }

        $headerValuesToLimit = [];
        if ($lmtHeadersIsSet) {
            foreach ($lmtHeaders as $headerKey => $headerValues) {
                $reqHeaderValue = Net::headerGet($r, $headerKey);
                if ($reqHeaderValue === '') {
                    continue;
                }

                if (count($headerValues) === 0) {
                    // If header values are empty, rate-limit all request containing headerKey.
                    $headerValuesToLimit[] = [$headerKey, $reqHeaderValue];
                } else {
                    // If header values are not empty, rate-limit all request with headerKey and headerValues.
                    foreach ($headerValues as $headerValue) {
                        if (Net::headerGet($r, $headerKey) === $headerValue) {
                            $headerValuesToLimit[] = [$headerKey, $headerValue];
                            break;
                        }
                    }
                }
            }
        }

        $contextValuesToLimit = [];
        if ($lmtContextValuesIsSet) {
            foreach ($lmtContextValues as $contextKey => $contextValues) {
                $reqContextValue = self::sprintfV($r->getAttribute($contextKey));
                if ($reqContextValue === '') {
                    continue;
                }

                if (count($contextValues) === 0) {
                    // If context values are empty, rate-limit all request containing contextKey.
                    $contextValuesToLimit[] = [$contextKey, $reqContextValue];
                } else {
                    // If context values are not empty, rate-limit all request with contextKey and contextValues.
                    foreach ($contextValues as $contextValue) {
                        if ($reqContextValue === $contextValue) {
                            $contextValuesToLimit[] = [$contextKey, $contextValue];
                            break;
                        }
                    }
                }
            }
        }

        $sliceKey = [$remoteIP];
        if (!$lmtIgnoreURL) {
            $sliceKey[] = $path;
        }

        foreach ($lmtMethods as $method) {
            $sliceKey[] = $method;
        }

        foreach ($headerValuesToLimit as $header) {
            $sliceKey[] = $header[0];
            $sliceKey[] = $header[1];
        }

        foreach ($contextValuesToLimit as $contextValue) {
            $sliceKey[] = $contextValue[0];
            $sliceKey[] = $contextValue[1];
        }

        $sliceKey[] = $usernameToLimit;

        $sliceKeys[] = $sliceKey;

        return $sliceKeys;
    }

    /**
     * LimitByRequest builds keys based on http.Request struct,
     * loops through all the keys, and check if any one of them returns HTTPError.
     *
     * @param list<array{0: string, 1: string}> $w Collected response headers (Go: w.Header().Add).
     */
    public static function limitByRequest(Limiter $lmt, array &$w, ServerRequestInterface $r): ?HttpError
    {
        self::setResponseHeaders($lmt, $w, $r);

        $shouldSkip = self::shouldSkipLimiter($lmt, $r);
        if ($shouldSkip) {
            return null;
        }

        $sliceKeys = self::buildKeys($lmt, $r);

        // Get the lowest value over all keys to return in headers.
        // Start with high arbitrary number so that any limit returned would be lower and would
        // overwrite the value we start with.
        $tokensLeft = Clock::MaxInt32;

        // Loop sliceKeys and check if one of them has error.
        foreach ($sliceKeys as $keys) {
            [$httpError, $keysLimit] = self::limitByKeysAndReturn($lmt, $keys);
            if ($tokensLeft > $keysLimit) {
                $tokensLeft = $keysLimit;
            }
            if ($httpError !== null) {
                self::setRateLimitResponseHeaders($lmt, $w, $tokensLeft);

                return $httpError;
            }
        }

        self::setRateLimitResponseHeaders($lmt, $w, $tokensLeft);

        return null;
    }

    /**
     * applyHeaders adds the collected headers to the response, the PSR-7
     * counterpart of the `w.Header().Add` calls Go performs before writing.
     *
     * @param list<array{0: string, 1: string}> $w
     */
    public static function applyHeaders(ResponseInterface $response, array $w): ResponseInterface
    {
        foreach ($w as [$name, $value]) {
            $response = $response->withAddedHeader($name, $value);
        }

        return $response;
    }

    /**
     * urlPath is Go's `r.URL.Path`: the decoded path of the request URL
     * (PSR-7 keeps the path percent-encoded).
     */
    private static function urlPath(ServerRequestInterface $r): string
    {
        return rawurldecode($r->getUri()->getPath());
    }

    /**
     * sprintfV mirrors `fmt.Sprintf("%v", value)` for the context values the
     * filters format: a missing value prints as "<nil>", booleans as
     * true/false, scalars verbatim.
     */
    private static function sprintfV(mixed $value): string
    {
        if ($value === null) {
            return '<nil>';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
    }
}
