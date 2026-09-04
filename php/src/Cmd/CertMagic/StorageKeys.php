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
 * StorageKeys provides methods for accessing keys and key prefixes for items
 * in a Storage (certmagic storage.go `KeyBuilder` / the `StorageKeys` package
 * variable). Typically, you will not need to use this because accessing
 * storage is abstracted away for most cases. Only use this if you need to
 * directly access TLS assets in your application.
 *
 * The layout is byte-for-byte the one CertMagic (and Caddy) use, so a
 * certificate storage directory is interchangeable between the Go and PHP
 * servers:
 *
 *   certificates/<issuer key>/<domain>/<domain>.crt|.key|.json
 *   acme/<ca host>/users/<email>/<username>.json|.key
 *   locks/<name>.lock
 */
final class StorageKeys
{
    public const prefixCerts = 'certificates';
    public const prefixOCSP = 'ocsp';

    /** prefixACME is the storage key prefix used for ACME-specific assets. */
    public const prefixACME = 'acme';

    /**
     * safeKeyRE matches any undesirable characters in storage keys.
     * Note that this allows dots, so you'll have to strip ".." manually.
     * (Go: `[^\w@.-]`; RE2's \w is ASCII-only.)
     */
    private const safeKeyRE = '/[^0-9A-Za-z_@.\-]/';

    private function __construct()
    {
    }

    /**
     * CertsPrefix returns the storage key prefix for
     * the given certificate issuer.
     */
    public static function certsPrefix(string $issuerKey): string
    {
        return self::join(self::prefixCerts, self::safe($issuerKey));
    }

    /**
     * CertsSitePrefix returns a key prefix for items associated with
     * the site given by domain using the given issuer key.
     */
    public static function certsSitePrefix(string $issuerKey, string $domain): string
    {
        return self::join(self::certsPrefix($issuerKey), self::safe($domain));
    }

    /**
     * SiteCert returns the path to the certificate file for domain
     * that is associated with the issuer with the given issuerKey.
     */
    public static function siteCert(string $issuerKey, string $domain): string
    {
        $safeDomain = self::safe($domain);
        return self::join(self::certsSitePrefix($issuerKey, $domain), $safeDomain . '.crt');
    }

    /**
     * SitePrivateKey returns the path to the private key file for domain
     * that is associated with the certificate from the given issuer with
     * the given issuerKey.
     */
    public static function sitePrivateKey(string $issuerKey, string $domain): string
    {
        $safeDomain = self::safe($domain);
        return self::join(self::certsSitePrefix($issuerKey, $domain), $safeDomain . '.key');
    }

    /**
     * SiteMeta returns the path to the metadata file for domain that
     * is associated with the certificate from the given issuer with
     * the given issuerKey.
     */
    public static function siteMeta(string $issuerKey, string $domain): string
    {
        $safeDomain = self::safe($domain);
        return self::join(self::certsSitePrefix($issuerKey, $domain), $safeDomain . '.json');
    }

    /**
     * OCSPStaple returns a key for the OCSP staple associated
     * with the given certificate. If you have the PEM bundle
     * handy, pass that in to save an extra encoding step.
     */
    public static function ocspStaple(Certificate $cert, string $pemBundle): string
    {
        $ocspFileName = '';
        if ($cert->names !== []) {
            $firstName = self::safe($cert->names[0]);
            $ocspFileName = $firstName . '-';
        }
        $ocspFileName .= Crypto::fastHash($pemBundle !== '' ? $pemBundle : $cert->certificatePEM);
        return self::join(self::prefixOCSP, $ocspFileName);
    }

    /**
     * Safe standardizes and sanitizes str for use as
     * a single component of a storage key. This method
     * is idempotent.
     */
    public static function safe(string $str): string
    {
        $str = strtolower($str);
        $str = trim($str);

        // replace a few specific characters
        $str = strtr($str, [
            ' ' => '_',
            '+' => '_plus_',
            '*' => 'wildcard_',
            ':' => '-',
            '..' => '', // prevent directory traversal (regex allows single dots)
        ]);

        // finally remove all non-word characters
        return (string) preg_replace(self::safeKeyRE, '', $str);
    }

    /**
     * join is Go's `path.Join`: joins the elements with slashes and cleans the
     * result (empty elements ignored, duplicate slashes collapsed).
     */
    public static function join(string ...$elems): string
    {
        $parts = [];
        foreach ($elems as $e) {
            if ($e === '') {
                continue;
            }
            $parts[] = $e;
        }
        if ($parts === []) {
            return '';
        }
        $joined = implode('/', $parts);
        $joined = (string) preg_replace('#/+#', '/', $joined);
        $segments = [];
        foreach (explode('/', $joined) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $seg;
        }
        $clean = implode('/', $segments);
        if (str_starts_with($joined, '/')) {
            $clean = '/' . $clean;
        }
        return $clean === '' ? '.' : $clean;
    }
}
