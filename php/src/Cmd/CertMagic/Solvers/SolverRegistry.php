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
use Blnk\Cmd\CertMagic\Locks;
use Blnk\Internal\Log;

/**
 * SolverRegistry holds the package-level state of certmagic solvers.go:
 * the active challenge solvers keyed by listener address (`solvers`), the
 * information about all known, currently-active ACME challenges keyed by
 * identifier (`activeChallenges`), and the listener helpers
 * (`robustTryListen`, `dialTCPSocket`).
 *
 * CertMagic guarantees that challenges for the same identifier do not
 * overlap, by its locking mechanisms; thus if a challenge comes in for a
 * certain identifier, we can be confident that if this process initiated
 * the challenge, the correct information to solve it is in this map. (It
 * may have alternatively been initiated by another instance in a cluster,
 * in which case the distributed solver will take care of that.)
 *
 * PHP port: Go serves each challenge listener from a goroutine; here
 * {@see pump()} — installed as the ACME client's waiter — accepts and
 * answers the listeners' connections whenever the issuance flow waits
 * (polling, back-off), and also runs any registered idle callbacks (the
 * HTTPS server keeps answering API requests during a renewal that way).
 */
final class SolverRegistry
{
    /** @var array<string, SolverInfo> keyed by listener address */
    private static array $solvers = [];

    /** @var array<string, ActiveChallenge> keyed by identifier */
    private static array $activeChallenges = [];

    /** @var array<string, callable(float): void> */
    private static array $idle = [];

    private function __construct()
    {
    }

    /** getSolverInfo gets a valid solverInfo struct for address. */
    public static function getSolverInfo(string $address): SolverInfo
    {
        if (!isset(self::$solvers[$address])) {
            self::$solvers[$address] = new SolverInfo($address);
        }
        return self::$solvers[$address];
    }

    /** deleteSolverInfo forgets the solver at address (Go: `delete(solvers, address)`). */
    public static function deleteSolverInfo(string $address): void
    {
        unset(self::$solvers[$address]);
    }

    /**
     * GetACMEChallenge returns an active ACME challenge for the given identifier,
     * or null if no active challenge for that identifier is known.
     */
    public static function getACMEChallenge(string $identifier): ?ActiveChallenge
    {
        return self::$activeChallenges[$identifier] ?? null;
    }

    /** setActiveChallenge records a challenge as active under its key. */
    public static function setActiveChallenge(string $key, Challenge $challenge): void
    {
        self::$activeChallenges[$key] = new ActiveChallenge($challenge);
    }

    /** setActiveChallengeData attaches solver data (e.g. the TLS-ALPN certificate) to an active challenge. */
    public static function setActiveChallengeData(string $key, mixed $data): void
    {
        if (!isset(self::$activeChallenges[$key])) {
            self::$activeChallenges[$key] = new ActiveChallenge(new Challenge(), $data);
            return;
        }
        self::$activeChallenges[$key]->data = $data;
    }

    /** deleteActiveChallenge forgets an active challenge. */
    public static function deleteActiveChallenge(string $key): void
    {
        unset(self::$activeChallenges[$key]);
    }

    /**
     * challengeKey returns the map key for a given challenge; it is the identifier
     * unless it is an IP address using the TLS-ALPN challenge.
     */
    public static function challengeKey(Challenge $chal): string
    {
        if ($chal->type === Challenge::ChallengeTypeTLSALPN01 && $chal->identifier->type === 'ip') {
            $reversed = self::reverseAddr($chal->identifier->value);
            if ($reversed !== null) {
                return rtrim($reversed, '.'); // strip off '.'
            }
        }
        return $chal->identifier->value;
    }

    /**
     * reverseAddr is `dns.ReverseAddr`: the in-addr.arpa/ip6.arpa name of an IP.
     */
    public static function reverseAddr(string $ip): ?string
    {
        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) !== false) {
            return implode('.', array_reverse(explode('.', $ip))) . '.in-addr.arpa.';
        }
        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);
            if ($packed === false) {
                return null;
            }
            $nibbles = str_split(bin2hex($packed));
            return implode('.', array_reverse($nibbles)) . '.ip6.arpa.';
        }
        return null;
    }

    /**
     * robustTryListen calls net.Listen for a TCP socket at addr.
     * This function may return null without throwing!
     * If it was able to bind the socket, it returns the listener.
     * If it wasn't able to bind the socket because
     * the socket is already in use, then it returns null.
     * If it had any other error, it throws.
     * The intended error handling logic for this function
     * is to proceed if the returned listener is not null; otherwise
     * return. In other words, this function ignores errors if the
     * socket is already in use, which is useful for our challenge
     * servers, where we assume that whatever is already listening
     * can solve the challenges.
     *
     * @return resource|null
     * @throws \RuntimeException
     */
    public static function robustTryListen(string $addr)
    {
        $listenErr = '';
        for ($i = 0; $i < 2; $i++) {
            // doesn't hurt to sleep briefly before the second
            // attempt in case the OS has timing issues
            if ($i > 0) {
                usleep(100_000);
            }

            // if we can bind the socket right away, great!
            $ln = self::listen($addr, $listenErr);
            if ($ln !== null) {
                return $ln;
            }

            // if it failed just because the socket is already in use, we
            // have no choice but to assume that whatever is using the socket
            // can answer the challenge already, so we ignore the error
            if (self::dialTCPSocket($addr)) {
                return null;
            }

            // Hmm, we couldn't connect to the socket, so something else must
            // be wrong, right? wrong!! Apparently if a port is bound by another
            // listener with a specific host, i.e. 'x:1234', we cannot bind to
            // ':1234' -- it is considered a conflict, but 'y:1234' is not.
            // I guess we need to assume the conflicting listener is properly
            // configured and continue. But we should tell the user to specify
            // the correct ListenHost to avoid conflict or at least so we can
            // know that the user is intentional about that port and hopefully
            // has an ACME solver on it.
            //
            // History:
            // https://caddy.community/t/caddy-retry-error/7317
            // https://caddy.community/t/v2-upgrade-to-caddy2-failing-with-errors/7423
            // https://github.com/caddyserver/certmagic/issues/250
            if (str_contains($listenErr, 'address already in use')
                || str_contains($listenErr, 'Address already in use')
                || str_contains($listenErr, 'one usage of each socket address')) {
                Log::get()->warning(sprintf('[WARNING] listen tcp %s: %s - be sure to set the ACMEIssuer.ListenHost field; assuming conflicting listener is correctly configured and continuing', $addr, $listenErr));
                return null;
            }
        }
        throw new \RuntimeException(sprintf('could not start listener for challenge server at %s: %s', $addr, $listenErr));
    }

    /**
     * dialTCPSocket connects to a TCP address just for the sake of
     * seeing if it is open. It returns true if a TCP connection
     * can successfully be made to addr within a short timeout.
     */
    public static function dialTCPSocket(string $addr): bool
    {
        [$host, $port] = self::hostPort($addr);
        if ($host === '') {
            $host = '127.0.0.1';
        }
        $errno = 0;
        $errstr = '';
        $conn = @stream_socket_client('tcp://' . self::bracket($host) . ':' . $port, $errno, $errstr, 0.25);
        if ($conn === false) {
            return false;
        }
        fclose($conn);
        return true;
    }

    /**
     * registerIdle adds a callback the pump runs on every slice (the HTTPS
     * server registers its connection poll here so API requests keep being
     * answered during a renewal).
     *
     * @param callable(float): void $fn
     */
    public static function registerIdle(string $name, callable $fn): void
    {
        self::$idle[$name] = $fn;
    }

    public static function unregisterIdle(string $name): void
    {
        unset(self::$idle[$name]);
    }

    /**
     * pump waits for `$seconds` (Go: `<-time.After(d)`) while answering the
     * connections of every active challenge listener, refreshing the lock
     * files this process holds, and running the idle callbacks.
     */
    public static function pump(float $seconds): void
    {
        $deadline = microtime(true) + max(0.0, $seconds);
        do {
            Locks::keepFresh();

            $remaining = $deadline - microtime(true);
            $slice = min(max($remaining, 0.0), 0.1);

            $listeners = [];
            $byId = [];
            foreach (self::$solvers as $si) {
                if ($si->listener !== null && \is_resource($si->listener) && $si->handler !== null) {
                    $listeners[] = $si->listener;
                    $byId[(int) $si->listener] = $si;
                }
            }

            if ($listeners === []) {
                if ($slice > 0) {
                    usleep((int) ($slice * 1_000_000));
                }
            } else {
                $read = $listeners;
                $write = null;
                $except = null;
                $sec = (int) floor($slice);
                $usec = (int) (($slice - $sec) * 1_000_000);
                $n = @stream_select($read, $write, $except, $sec, $usec);
                if ($n !== false && $n > 0) {
                    foreach ($read as $ln) {
                        $si = $byId[(int) $ln] ?? null;
                        if ($si === null || $si->handler === null) {
                            continue;
                        }
                        $conn = @stream_socket_accept($ln, 0);
                        if ($conn === false) {
                            continue;
                        }
                        try {
                            ($si->handler)($conn);
                        } catch (\Throwable $err) {
                            Log::get()->error(sprintf('[ERROR] challenge server on %s: %s', $si->address, $err->getMessage()));
                        } finally {
                            if (\is_resource($conn)) {
                                @fclose($conn);
                            }
                        }
                    }
                }
            }

            foreach (self::$idle as $fn) {
                try {
                    $fn(0.0);
                } catch (\Throwable $err) {
                    Log::get()->error(sprintf('idle callback failed: %s', $err->getMessage()));
                }
            }
        } while (microtime(true) < $deadline);
    }

    /**
     * listen binds a TCP listener at addr (":80" binds every interface,
     * dual-stack when IPv6 is available, like Go's net.Listen).
     *
     * @return resource|null
     */
    private static function listen(string $addr, string &$errstr)
    {
        [$host, $port] = self::hostPort($addr);
        $candidates = $host === '' ? ['[::]', '0.0.0.0'] : [self::bracket($host)];
        foreach ($candidates as $bind) {
            $errno = 0;
            $err = '';
            $ln = @stream_socket_server('tcp://' . $bind . ':' . $port, $errno, $err, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN);
            if ($ln !== false) {
                stream_set_blocking($ln, false);
                return $ln;
            }
            $errstr = $err !== '' ? $err : 'error ' . $errno;
            if (str_contains($errstr, 'already in use')) {
                return null;
            }
        }
        return null;
    }

    /** @return array{0: string, 1: string} */
    private static function hostPort(string $addr): array
    {
        $pos = strrpos($addr, ':');
        if ($pos === false) {
            return ['', $addr];
        }
        return [trim(substr($addr, 0, $pos), '[]'), substr($addr, $pos + 1)];
    }

    private static function bracket(string $host): string
    {
        return str_contains($host, ':') && !str_starts_with($host, '[') ? '[' . $host . ']' : $host;
    }
}
