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

use Psr\Http\Message\ServerRequestInterface;

/**
 * Net ports the pieces of Go's `net` and `net/http` packages that Tollbooth's
 * request handling depends on: `Request.RemoteAddr`, `Header.Get`,
 * `Request.BasicAuth`, `net.SplitHostPort`, `net.JoinHostPort`, `net.ParseIP`
 * and `IP.String`.
 */
final class Net
{
    private function __construct()
    {
    }

    /**
     * remoteAddr is the PSR-7 counterpart of Go's `http.Request.RemoteAddr`:
     * the network address that sent the request, "IP:port" ("[IPv6]:port"),
     * as the HTTP server records it. PHP's SAPI exposes the two halves as the
     * REMOTE_ADDR and REMOTE_PORT server parameters; without a port the bare
     * address is returned, without an address the empty string (Go's value
     * for a request that did not come over the network).
     */
    public static function remoteAddr(ServerRequestInterface $r): string
    {
        $params = $r->getServerParams();
        $addr = isset($params['REMOTE_ADDR']) ? (string) $params['REMOTE_ADDR'] : '';
        if ($addr === '') {
            return '';
        }
        $port = isset($params['REMOTE_PORT']) ? (string) $params['REMOTE_PORT'] : '';
        if ($port === '') {
            return $addr;
        }

        return self::joinHostPort($addr, $port);
    }

    /**
     * headerGet mirrors Go's `Header.Get(key)`: the first value associated
     * with the (case-insensitive) key, or "" when the header is absent. PSR-7's
     * getHeaderLine would instead join every value with ", ".
     */
    public static function headerGet(ServerRequestInterface $r, string $key): string
    {
        $values = $r->getHeader($key);

        return $values === [] ? '' : (string) $values[0];
    }

    /**
     * basicAuth mirrors `Request.BasicAuth()` / `parseBasicAuth`: the username
     * and password of the request's Authorization header when it uses HTTP
     * Basic Authentication, null otherwise (Go's ok == false).
     *
     * @return array{0: string, 1: string}|null [username, password]
     */
    public static function basicAuth(ServerRequestInterface $r): ?array
    {
        $auth = self::headerGet($r, 'Authorization');
        if ($auth === '') {
            return null;
        }

        $prefix = 'Basic ';
        // Case insensitive prefix match. See Issue 22736.
        if (strlen($auth) < strlen($prefix) || strcasecmp(substr($auth, 0, strlen($prefix)), $prefix) !== 0) {
            return null;
        }
        $c = base64_decode(substr($auth, strlen($prefix)), true);
        if ($c === false) {
            return null;
        }
        $pos = strpos($c, ':');
        if ($pos === false) {
            return null;
        }

        return [substr($c, 0, $pos), substr($c, $pos + 1)];
    }

    /**
     * splitHostPort ports `net.SplitHostPort`: splits a network address of
     * the form "host:port", "host%zone:port", "[host]:port" or
     * "[host%zone]:port" into host or host%zone and port. Returns null where
     * Go returns an error (missing port, too many colons, bracket problems).
     *
     * @return array{0: string, 1: string}|null [host, port]
     */
    public static function splitHostPort(string $hostport): ?array
    {
        $j = 0;
        $k = 0;

        // The port starts after the last colon.
        $i = strrpos($hostport, ':');
        if ($i === false) {
            return null; // missing port in address
        }

        if ($hostport[0] === '[') {
            // Expect the first ']' just before the last ':'.
            $end = strpos($hostport, ']');
            if ($end === false) {
                return null; // missing ']' in address
            }
            if ($end + 1 === strlen($hostport)) {
                // There can't be a ':' behind the ']' now.
                return null; // missing port in address
            }
            if ($end + 1 !== $i) {
                // Either ']' isn't followed by a colon, or it is
                // followed by a colon that is not the last one.
                return null; // too many colons / missing port in address
            }
            $host = substr($hostport, 1, $end - 1);
            $j = 1;
            $k = $end + 1; // there can't be a '[' resp. ']' before these positions
        } else {
            $host = substr($hostport, 0, $i);
            if (strpos($host, ':') !== false) {
                return null; // too many colons in address
            }
        }
        if (strpos(substr($hostport, $j), '[') !== false) {
            return null; // unexpected '[' in address
        }
        if (strpos(substr($hostport, $k), ']') !== false) {
            return null; // unexpected ']' in address
        }

        $port = substr($hostport, $i + 1);

        return [$host, $port];
    }

    /**
     * joinHostPort ports `net.JoinHostPort`: combines host and port into a
     * network address of the form "host:port". If host contains a colon, as
     * found in literal IPv6 addresses, then JoinHostPort returns "[host]:port".
     */
    public static function joinHostPort(string $host, string $port): string
    {
        // We assume that host is a literal IPv6 address if host has colons.
        if (strpos($host, ':') !== false) {
            return '[' . $host . ']:' . $port;
        }

        return $host . ':' . $port;
    }

    /**
     * parseIP ports `net.ParseIP`: parses s as an IP address, returning the
     * 16-byte form (IPv4 addresses as IPv4-mapped IPv6, like Go's `IP` slice
     * from ParseIP) or null when s is not a valid textual representation of
     * an IP address. Zones ("%eth0") are rejected as in Go.
     */
    public static function parseIP(string $s): ?string
    {
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if ($s[$i] === '.') {
                if (filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    return null;
                }
                $packed = inet_pton($s);

                return $packed === false ? null : "\0\0\0\0\0\0\0\0\0\0\xff\xff" . $packed;
            }
            if ($s[$i] === ':') {
                if (filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                    return null;
                }
                $packed = inet_pton($s);

                return $packed === false ? null : $packed;
            }
        }

        return null;
    }

    /**
     * to4 ports `IP.To4`: converts the IPv4 address ip to a 4-byte
     * representation. If ip is not an IPv4 address, To4 returns null.
     */
    public static function to4(string $ip): ?string
    {
        if (strlen($ip) === 4) {
            return $ip;
        }
        if (strlen($ip) === 16
            && substr($ip, 0, 10) === "\0\0\0\0\0\0\0\0\0\0"
            && $ip[10] === "\xff"
            && $ip[11] === "\xff") {
            return substr($ip, 12, 4);
        }

        return null;
    }

    /**
     * ipString ports `IP.String()`: returns the string form of the IP address ip.
     * It returns one of 4 forms:
     *   - "<nil>", if ip has length 0
     *   - dotted decimal ("192.0.2.1"), if ip is an IPv4 or IP4-in-IP6 address
     *   - IPv6 conforming to RFC 5952 ("2001:db8::1"), if ip is a valid IPv6 address
     *   - the hexadecimal form of ip, without punctuation, if no other cases apply
     */
    public static function ipString(string $ip): string
    {
        $len = strlen($ip);
        if ($len === 0) {
            return '<nil>';
        }
        if ($len !== 4 && $len !== 16) {
            return '?' . bin2hex($ip);
        }

        // If IPv4, use dotted notation.
        $p4 = self::to4($ip);
        if ($p4 !== null) {
            return ord($p4[0]) . '.' . ord($p4[1]) . '.' . ord($p4[2]) . '.' . ord($p4[3]);
        }

        /** @var int[] $groups */
        $groups = array_values(unpack('n8', $ip));

        // Find the longest run of zeros (at least two 16-bit groups); the
        // leftmost run wins ties — Go's netip.Addr.appendTo6.
        $zeroStart = -1;
        $zeroEnd = -1;
        for ($i = 0; $i < 8; $i++) {
            $j = $i;
            while ($j < 8 && $groups[$j] === 0) {
                $j++;
            }
            $l = $j - $i;
            if ($l >= 2 && $l > $zeroEnd - $zeroStart) {
                $zeroStart = $i;
                $zeroEnd = $j;
            }
        }

        $out = '';
        for ($i = 0; $i < 8; $i++) {
            if ($i === $zeroStart) {
                $out .= '::';
                $i = $zeroEnd;
                if ($i >= 8) {
                    break;
                }
            } elseif ($i > 0) {
                $out .= ':';
            }
            $out .= dechex($groups[$i]);
        }

        return $out;
    }
}
