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
 * Port of package `libstring` (github.com/didip/tollbooth/v7/libstring/libstring.go),
 * which provides various string related functions.
 */
final class Libstring
{
    private function __construct()
    {
    }

    /**
     * StringInSlice finds needle in a slice of strings.
     *
     * @param string[] $sliceString
     */
    public static function stringInSlice(array $sliceString, string $needle): bool
    {
        foreach ($sliceString as $b) {
            if ($b === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * RemoteIP finds IP Address given http.Request struct.
     *
     * @param string[] $ipLookups
     */
    public static function remoteIP(array $ipLookups, int $forwardedForIndexFromBehind, ServerRequestInterface $r): string
    {
        $realIP = Net::headerGet($r, 'X-Real-IP');
        $forwardedFor = Net::headerGet($r, 'X-Forwarded-For');

        foreach ($ipLookups as $lookup) {
            if ($lookup === 'RemoteAddr') {
                // 1. Cover the basic use cases for both ipv4 and ipv6
                $remoteAddr = Net::remoteAddr($r);
                $hostPort = Net::splitHostPort($remoteAddr);
                if ($hostPort === null) {
                    // 2. Upon error, just return the remote addr.
                    return $remoteAddr;
                }

                return $hostPort[0];
            }
            if ($lookup === 'X-Forwarded-For' && $forwardedFor !== '') {
                // X-Forwarded-For is potentially a list of addresses separated with ","
                $parts = explode(',', $forwardedFor);
                foreach ($parts as $i => $p) {
                    // strings.TrimSpace (ASCII white space; header values carry no other)
                    $parts[$i] = trim($p, " \t\n\v\f\r");
                }

                $partIndex = count($parts) - 1 - $forwardedForIndexFromBehind;
                if ($partIndex < 0) {
                    $partIndex = 0;
                }

                return $parts[$partIndex];
            }
            if ($lookup === 'X-Real-IP' && $realIP !== '') {
                return $realIP;
            }
        }

        return '';
    }

    /**
     * CanonicalizeIP returns a form of ip suitable for comparison to other IPs.
     * For IPv4 addresses, this is simply the whole string.
     * For IPv6 addresses, this is the /64 prefix.
     */
    public static function canonicalizeIP(string $ip): string
    {
        $isIPv6 = false;
        // This is how net.ParseIP decides if an address is IPv6
        // https://cs.opensource.google/go/go/+/refs/tags/go1.17.7:src/net/ip.go;l=704
        $len = strlen($ip);
        for ($i = 0; !$isIPv6 && $i < $len; $i++) {
            if ($ip[$i] === '.') {
                // IPv4
                return $ip;
            }
            if ($ip[$i] === ':') {
                // IPv6
                $isIPv6 = true;
            }
        }
        if (!$isIPv6) {
            // Not an IP address at all
            return $ip;
        }

        // By default, the string representation of a net.IPNet (masked IP address) is just
        // "full_address/mask_bits". But using that will result in different addresses with
        // the same /64 prefix comparing differently. So we need to zero out the last 64 bits
        // so that all IPs in the same prefix will be the same.
        //
        // Note: When 1.18 is the minimum Go version, this can be written more cleanly like:
        // netip.PrefixFrom(netip.MustParseAddr(ipv6), 64).Masked().Addr().String()
        // (With appropriate error checking.)

        $ipv6 = Net::parseIP($ip);
        if ($ipv6 === null) {
            return $ip;
        }

        $bytesToZero = intdiv(128 - 64, 8);
        for ($i = strlen($ipv6) - $bytesToZero; $i < strlen($ipv6); $i++) {
            $ipv6[$i] = "\0";
        }

        // Note that this doesn't have the "/64" suffix customary with a CIDR representation,
        // but those three bytes add nothing for us.
        return Net::ipString($ipv6);
    }
}
