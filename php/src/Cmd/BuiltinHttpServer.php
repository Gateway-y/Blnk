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

namespace Blnk\Cmd;

use Blnk\Internal\Log;

/**
 * BuiltinHttpServer is the PHP counterpart of the `*http.Server` that
 * `blnk start` runs (cmd/server.go newHTTPServer/startServer/gracefulShutdown).
 *
 * PHP cannot serve HTTP from the CLI process itself, so the server is a
 * supervised child process running PHP's built-in web server:
 *
 *   php -S 0.0.0.0:<port> -t <project>/public <project>/public/index.php
 *
 * with public/index.php as router script (it mirrors `initializeRouter` for
 * every request). {@see listenAndServe()} launches the child, {@see pump()}
 * streams its stdout/stderr to ours and notices an unexpected exit (Go:
 * `ListenAndServe` returning an error), {@see shutdown()} forwards SIGTERM and
 * waits up to the timeout before killing it (Go: `server.Shutdown(ctx)`
 * returning `context deadline exceeded`).
 *
 * Production deployments serve public/index.php through php-fpm + nginx (or
 * any FastCGI front end) instead; set BLNK_HTTP_SERVER=external and
 * `blnk start` skips the child process and only runs the background
 * processors (see {@see ServerCommand}). The built-in server handles one
 * request at a time unless PHP_CLI_SERVER_WORKERS is set in the environment
 * (it is passed through to the child).
 */
final class BuiltinHttpServer
{
    /** Environment variable selecting the serving mode of `blnk start`. */
    public const ModeEnv = 'BLNK_HTTP_SERVER';
    public const ModeBuiltin = 'builtin';
    public const ModeExternal = 'external';

    /** Router script of the built-in server, relative to the document root. */
    public const RouterScript = 'index.php';

    /** Go `http.Server.Addr`, e.g. ":5001". */
    private string $addr;

    private string $docroot;

    /** @var array<string, string> environment overrides for the child process */
    private array $env;

    private bool $external;

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private bool $stopping = false;

    private ?int $exitCode = null;

    /**
     * @param array<string, string> $env environment overrides for the child (merged over the current environment)
     */
    public function __construct(string $addr, string $docroot, array $env = [], bool $external = false)
    {
        $this->addr = $addr;
        $this->docroot = rtrim($docroot, '/');
        $this->env = $env;
        $this->external = $external;
    }

    /** addr returns the listen address (Go `Addr`). */
    public function addr(): string
    {
        return $this->addr;
    }

    /** isExternal reports whether the API is served by php-fpm/nginx rather than a child process. */
    public function isExternal(): bool
    {
        return $this->external;
    }

    /**
     * ListenAndServe starts the built-in server child process.
     *
     * @throws \RuntimeException when the child cannot be started or dies immediately (e.g. port in use)
     */
    public function listenAndServe(): void
    {
        if ($this->external) {
            Log::get()->info(sprintf('%s=%s: not starting the built-in server; serve %s through php-fpm/nginx', self::ModeEnv, self::ModeExternal, $this->docroot . '/' . self::RouterScript));
            return;
        }
        if ($this->process !== null) {
            throw new \RuntimeException('http: Server already running');
        }

        $routerScript = $this->docroot . '/' . self::RouterScript;
        if (!is_file($routerScript)) {
            throw new \RuntimeException(sprintf('router script %s not found', $routerScript));
        }

        $host = $this->listenHost();
        $command = [\PHP_BINARY, '-S', $host, '-t', $this->docroot, $routerScript];

        $env = [];
        foreach (getenv() as $k => $v) {
            $env[(string) $k] = (string) $v;
        }
        foreach ($this->env as $k => $v) {
            $env[$k] = $v;
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes, $this->docroot, $env);
        if (!\is_resource($process)) {
            throw new \RuntimeException(sprintf('listen tcp %s: could not start %s', $this->addr, implode(' ', $command)));
        }
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $this->process = $process;
        $this->pipes = $pipes;
        $this->stopping = false;
        $this->exitCode = null;

        // A bind failure ("Address already in use") kills the child right away.
        usleep(300_000);
        $this->forwardOutput();
        $status = proc_get_status($process);
        if (!$status['running']) {
            $this->exitCode = (int) $status['exitcode'];
            $this->release();
            throw new \RuntimeException(sprintf('listen tcp %s: php built-in server exited with code %d', $this->addr, $this->exitCode));
        }
    }

    /**
     * pump waits up to `$timeoutSec` for child output, forwards it, and
     * throws when the child exited while it was not being shut down
     * (Go: `ListenAndServe` returning an error other than ErrServerClosed).
     *
     * @throws \RuntimeException
     */
    public function pump(float $timeoutSec): void
    {
        if ($this->external || $this->process === null) {
            if ($timeoutSec > 0) {
                usleep((int) ($timeoutSec * 1_000_000));
            }
            return;
        }

        $read = array_values($this->pipes);
        $write = null;
        $except = null;
        $seconds = (int) floor($timeoutSec);
        $micro = (int) round(($timeoutSec - $seconds) * 1_000_000);
        if ($read !== [] && @stream_select($read, $write, $except, $seconds, $micro) === false) {
            // Interrupted by a signal: nothing to forward this round.
            return;
        }
        $this->forwardOutput();

        $status = proc_get_status($this->process);
        if (!$status['running']) {
            $this->exitCode = (int) $status['exitcode'];
            $this->forwardOutput();
            $this->release();
            if (!$this->stopping) {
                throw new \RuntimeException(sprintf('php built-in server on %s exited unexpectedly with code %d', $this->addr, $this->exitCode));
            }
        }
    }

    /** isRunning reports whether the child process is alive. */
    public function isRunning(): bool
    {
        if ($this->process === null) {
            return false;
        }
        $status = proc_get_status($this->process);
        return (bool) $status['running'];
    }

    /**
     * Shutdown stops the child gracefully: SIGTERM, then up to `$timeoutSec`
     * for it to exit, then SIGKILL — in which case the `context deadline
     * exceeded` error of Go's `server.Shutdown(ctx)` is thrown.
     *
     * @throws \RuntimeException when the child had to be killed
     */
    public function shutdown(float $timeoutSec): void
    {
        if ($this->external || $this->process === null) {
            return;
        }
        $this->stopping = true;
        proc_terminate($this->process, SignalTrap::SIGTERM);

        $deadline = microtime(true) + $timeoutSec;
        while (microtime(true) < $deadline) {
            $this->pump(0.1);
            if ($this->process === null) {
                return; // exited and released by pump()
            }
        }

        proc_terminate($this->process, 9);
        usleep(100_000);
        $this->forwardOutput();
        $this->release();
        throw new \RuntimeException('context deadline exceeded');
    }

    /** exitCode returns the child's exit code once it has exited. */
    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    /**
     * listenHost converts Go's `":port"` address into the `host:port` form
     * expected by `php -S` (an empty host listens on every interface).
     */
    private function listenHost(): string
    {
        $addr = $this->addr;
        if (str_starts_with($addr, ':')) {
            return '0.0.0.0' . $addr;
        }
        return $addr;
    }

    /** forwardOutput copies pending child stdout/stderr to our own streams. */
    private function forwardOutput(): void
    {
        foreach ($this->pipes as $fd => $pipe) {
            if (!\is_resource($pipe)) {
                continue;
            }
            $data = stream_get_contents($pipe);
            if ($data === false || $data === '') {
                continue;
            }
            fwrite($fd === 1 ? \STDOUT : \STDERR, $data);
        }
    }

    /** release closes the pipes and the process handle. */
    private function release(): void
    {
        foreach ($this->pipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];
        if ($this->process !== null) {
            proc_close($this->process);
            $this->process = null;
        }
    }
}
