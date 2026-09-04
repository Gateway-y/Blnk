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
use Blnk\Cmd\HttpConn;
use Blnk\Internal\Log;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * httpSolver solves the HTTP challenge. It must be
 * associated with a config and an address to use
 * for solving the challenge. If multiple httpSolvers
 * are initialized concurrently, the first one to
 * begin will start the server, and the last one to
 * finish will stop the server. This solver must be
 * wrapped by a distributedSolver to work properly,
 * because the only way the HTTP challenge handler
 * can access the keyAuth material is by loading it
 * from storage, which is done by distributedSolver.
 */
final class HttpSolver implements Solver
{
    private bool $closed = false;

    /** @var callable(ServerRequestInterface): ResponseInterface */
    private $handler;

    private string $address;

    /**
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    public function __construct(callable $handler, string $address)
    {
        $this->handler = $handler;
        $this->address = $address;
    }

    /** Present starts an HTTP server if none is already listening on s.address. */
    public function present(Challenge $challenge): void
    {
        $si = SolverRegistry::getSolverInfo($this->address);
        $si->count++;
        if ($si->listener !== null) {
            return; // already be served by us
        }

        // notice the unusual error handling here; we
        // only continue to start a challenge server if
        // we got a listener; in all other cases return
        $ln = SolverRegistry::robustTryListen($this->address);
        if ($ln === null) {
            return;
        }

        // successfully bound socket, so save listener and start key auth HTTP server
        $si->listener = $ln;
        $si->handler = function ($conn): void {
            $this->serve($conn);
        };
    }

    /**
     * serve is an HTTP server that serves only HTTP challenge responses
     * (one connection at a time; keep-alives disabled as in Go).
     *
     * @param resource $conn
     */
    private function serve($conn): void
    {
        try {
            $request = HttpConn::readRequest($conn, 'http', 5.0, 5.0, HttpConn::MaxHeaderBytes);
        } catch (\RuntimeException $err) {
            $status = $err->getCode() >= 400 && $err->getCode() < 600 ? $err->getCode() : 400;
            HttpConn::writeResponse($conn, HttpConn::errorResponse($status, $err->getMessage()), '1.1');
            return;
        }
        if ($request === null) {
            return;
        }
        try {
            $response = ($this->handler)($request);
        } catch (\Throwable $err) {
            if (!$this->closed) {
                Log::get()->error(sprintf('[ERROR] key auth HTTP server: %s', $err->getMessage()));
            }
            $response = HttpConn::errorResponse(500, 'Internal Server Error');
        }
        HttpConn::writeResponse($conn, $response, $request->getProtocolVersion(), $request->getMethod() === 'HEAD');
    }

    /** CleanUp cleans up the HTTP server if it is the last one to finish. */
    public function cleanUp(Challenge $challenge): void
    {
        $si = SolverRegistry::getSolverInfo($this->address);
        $si->count--;
        if ($si->count <= 0) {
            // last one out turns off the lights
            $this->closed = true;
            if ($si->listener !== null) {
                if (\is_resource($si->listener)) {
                    @fclose($si->listener);
                }
                $si->listener = null;
                $si->handler = null;
            }
            SolverRegistry::deleteSolverInfo($this->address);
        }
    }
}
