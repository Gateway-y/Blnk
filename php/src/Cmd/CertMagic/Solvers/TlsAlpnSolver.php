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

namespace Blnk\Cmd\CertMagic\Solvers;

use Blnk\Cmd\CertMagic\Acme\Challenge;
use Blnk\Cmd\CertMagic\Acmez\Solver;
use Blnk\Cmd\CertMagic\Acmez\TlsAlpn01;
use Blnk\Cmd\CertMagic\Config;
use Blnk\Internal\Log;

/**
 * tlsALPNSolver is a type that can solve TLS-ALPN challenges.
 * It must have an associated config and address on which to
 * serve the challenge.
 *
 * PHP port: Go completes the handshake with the challenge certificate
 * selected by SNI/ALPN through the config's GetCertificate; PHP's TLS
 * stack selects server certificates from a static SNI table, so the
 * challenge certificates of the active challenges are installed on the
 * listener's stream context (`local_cert` / `SNI_server_certs`), with the
 * `acme-tls/1` ALPN protocol.
 */
final class TlsAlpnSolver implements Solver
{
    private Config $config;

    private string $address;

    /** @var array<string, string> challenge key → PEM file (certificate + key) */
    private static array $certFiles = [];

    public function __construct(Config $config, string $address)
    {
        $this->config = $config;
        $this->address = $address;
    }

    /**
     * Present adds the certificate to the certificate cache and, if
     * needed, starts a TLS server for answering TLS-ALPN challenges.
     */
    public function present(Challenge $chal): void
    {
        // we pre-generate the certificate for efficiency with multi-perspective
        // validation, so it only has to be done once (at least, by this instance;
        // distributed solving does not have that luxury, oh well) - update the
        // challenge data in memory to be the generated certificate
        [$certPEM, $keyPEM] = TlsAlpn01::tlsALPN01ChallengeCert($chal);

        $key = SolverRegistry::challengeKey($chal);
        SolverRegistry::setActiveChallengeData($key, [$certPEM, $keyPEM]);

        $file = @tempnam(sys_get_temp_dir(), 'blnk-alpn-cert-');
        if ($file === false || @file_put_contents($file, $certPEM . $keyPEM) === false) {
            throw new \RuntimeException('tls-alpn solver: cannot write challenge certificate');
        }
        @chmod($file, 0600);
        if (isset(self::$certFiles[$key])) {
            @unlink(self::$certFiles[$key]);
        }
        self::$certFiles[$key] = $file;

        // the rest of this function increments the
        // challenge count for the solver at this
        // listener address, and if necessary, starts
        // a simple TLS server

        $si = SolverRegistry::getSolverInfo($this->address);
        $si->count++;
        if ($si->listener !== null) {
            $this->installCertificates($si->listener);
            return; // already be served by us
        }

        // notice the unusual error handling here; we
        // only continue to start a challenge server if
        // we got a listener; in all other cases return
        $ln = SolverRegistry::robustTryListen($this->address);
        if ($ln === null) {
            return;
        }

        // we were able to bind the socket, so make it into a TLS
        // listener, store it with the solverInfo, and start the
        // challenge server
        $this->installCertificates($ln);
        $si->listener = $ln;
        $si->handler = function ($conn): void {
            $this->handleConn($conn);
        };
    }

    /**
     * handleConn completes the TLS handshake and then closes conn.
     *
     * @param resource $conn
     */
    private function handleConn($conn): void
    {
        stream_set_blocking($conn, true);
        stream_set_timeout($conn, 5);
        $ok = @stream_socket_enable_crypto($conn, true, $this->config->tlsConfig()->cryptoMethod);
        if ($ok !== true) {
            Log::get()->error(sprintf('[ERROR] TLS-ALPN challenge server: handshake: %s', openssl_error_string() ?: 'handshake failed'));
        }
    }

    /**
     * CleanUp removes the challenge certificate from the cache, and if
     * it is the last one to finish, stops the TLS server.
     */
    public function cleanUp(Challenge $chal): void
    {
        $key = SolverRegistry::challengeKey($chal);
        if (isset(self::$certFiles[$key])) {
            @unlink(self::$certFiles[$key]);
            unset(self::$certFiles[$key]);
        }

        $si = SolverRegistry::getSolverInfo($this->address);
        $si->count--;
        if ($si->count <= 0) {
            // last one out turns off the lights
            $si->closed = true;
            if ($si->listener !== null) {
                if (\is_resource($si->listener)) {
                    @fclose($si->listener);
                }
                $si->listener = null;
                $si->handler = null;
            }
            SolverRegistry::deleteSolverInfo($this->address);
        } elseif ($si->listener !== null) {
            $this->installCertificates($si->listener);
        }
    }

    /**
     * installCertificates puts the active challenge certificates on the
     * listener's TLS context: the first as default, all of them in the SNI
     * table, with the acme-tls/1 ALPN protocol (Go: `s.config.TLSConfig()`).
     *
     * @param resource $ln
     */
    private function installCertificates($ln): void
    {
        $options = $this->config->tlsConfig()->sslContextOptions();
        $options['alpn_protocols'] = TlsAlpn01::ACMETLS1Protocol;
        $options['SNI_server_certs'] = self::$certFiles;
        $first = reset(self::$certFiles);
        if ($first !== false) {
            $options['local_cert'] = $first;
        }
        foreach ($options as $name => $value) {
            stream_context_set_option($ln, 'ssl', (string) $name, $value);
        }
    }
}
