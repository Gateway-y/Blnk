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
 * SignalTrap is the PHP counterpart of Go's `signal.Notify(quit, SIGINT, SIGTERM)`
 * (cmd/server.go startServer) and `signal.NotifyContext(ctx, SIGINT, SIGTERM)`
 * (cmd/workers.go workerCommands): it records the first SIGINT/SIGTERM delivered
 * to the process so the command loops can shut down gracefully. {@see done()}
 * plays the role of `<-quit` / `ctx.Done()`, {@see trigger()} of a test-driven
 * `cancel()`, and {@see release()} of `signal.Stop` / the `stop()` function.
 *
 * Signal delivery needs ext-pcntl (asynchronous signal handling); without it the
 * trap never fires — the process is simply killed by the signal — and a warning
 * is logged once.
 */
final class SignalTrap
{
    /** POSIX signal numbers (the pcntl constants only exist when the extension is loaded). */
    public const SIGINT = 2;
    public const SIGTERM = 15;

    private ?int $received = null;

    /** @var int[] */
    private array $signals;

    private bool $installed = false;

    /**
     * @param int[] $signals
     */
    private function __construct(array $signals)
    {
        $this->signals = $signals;
    }

    /**
     * install registers the handlers for the given signals (default SIGINT and SIGTERM).
     *
     * @param int[] $signals
     */
    public static function install(array $signals = [self::SIGINT, self::SIGTERM]): self
    {
        $trap = new self($signals);
        $trap->arm();
        return $trap;
    }

    private function arm(): void
    {
        if (!\function_exists('pcntl_signal') || !\function_exists('pcntl_async_signals')) {
            Log::get()->warning('ext-pcntl is not available: SIGINT/SIGTERM cannot be trapped, the process exits without graceful shutdown');
            return;
        }
        pcntl_async_signals(true);
        foreach ($this->signals as $signo) {
            pcntl_signal($signo, function (int $signo): void {
                $this->trigger($signo);
            });
        }
        $this->installed = true;
    }

    /**
     * trigger records a signal as received (also usable to cancel programmatically,
     * the analogue of the test-driven context cancellation in Go).
     */
    public function trigger(int $signo): void
    {
        if ($this->received === null) {
            $this->received = $signo;
        }
    }

    /**
     * received dispatches pending signals and returns the number of the first
     * signal received, or null while none arrived.
     */
    public function received(): ?int
    {
        if (\function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        return $this->received;
    }

    /** done reports whether a signal arrived (Go: `ctx.Done()` closed / `<-quit`). */
    public function done(): bool
    {
        return $this->received() !== null;
    }

    /** release restores the default handlers (Go: the `stop()` of signal.NotifyContext). */
    public function release(): void
    {
        if (!$this->installed) {
            return;
        }
        foreach ($this->signals as $signo) {
            pcntl_signal($signo, \SIG_DFL);
        }
        $this->installed = false;
    }

    /** signalName renders a signal the way Go's `os.Signal.String()` does. */
    public static function signalName(int $signo): string
    {
        return match ($signo) {
            self::SIGINT => 'interrupt',
            self::SIGTERM => 'terminated',
            default => 'signal ' . $signo,
        };
    }
}
