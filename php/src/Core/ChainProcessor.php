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

namespace Blnk\Core;

use Blnk\Internal\Log;
use Blnk\Internal\Metrics\Metrics;

/**
 * ChainProcessor seals transactions into the tamper-evident hash chain off the
 * hot path. It polls for unchained rows and links them in a single DB
 * transaction per batch — modeled on LineageOutboxProcessor.
 *
 * (Go: `ChainProcessor`, chain_worker.go.)
 *
 * Concurrency divergence (documented, per PORTING.md "Concurrency"): as for
 * {@see LineageOutboxProcessor}, the Go goroutine loop is driven explicitly —
 * {@see run()} is the blocking loop (`run(ctx)`), {@see runOnce()} a single
 * tick (`processTick`), {@see start()} only marks the processor running and
 * {@see stop()} flags the loop to exit. Durations are seconds.
 */
final class ChainProcessor
{
    /** {@see waitTick()} outcomes. */
    private const TICK = 0;
    private const STOP_CONTEXT = 1;
    private const STOP_SIGNAL = 2;

    /** Granularity of the interruptible wait, in microseconds. */
    private const WAIT_SLICE_USEC = 100_000;

    private Blnk $blnk;

    /** Seconds (Go: time.Duration). */
    private int|float $pollInterval;

    private int $batchSize;

    /** Seconds (Go: time.Duration). */
    private int|float $trailingDelay;

    /** Go: `stopCh chan struct{}` — set by stop(), observed by run(). */
    private bool $stopRequested = false;

    private bool $running = false;

    /**
     * NewChainProcessor builds a ChainProcessor from the HashChain config (with safe
     * fallbacks).
     */
    public function __construct(Blnk $blnk)
    {
        $hc = $blnk->config()->transaction->hashChain;
        $pollInterval = $hc->pollInterval;
        if ($pollInterval <= 0) {
            $pollInterval = 5; // 5 * time.Second
        }
        $batchSize = $hc->batchSize;
        if ($batchSize <= 0) {
            $batchSize = 1000;
        }
        $trailingDelay = $hc->trailingDelay;
        if ($trailingDelay <= 0) {
            $trailingDelay = 30; // 30 * time.Second
        }
        $this->blnk = $blnk;
        $this->pollInterval = $pollInterval;
        $this->batchSize = $batchSize;
        $this->trailingDelay = $trailingDelay;
    }

    /**
     * NewChainProcessor — static factory mirroring the Go constructor function
     * name; equivalent to `new ChainProcessor($blnk)`.
     */
    public static function newChainProcessor(Blnk $blnk): self
    {
        return new self($blnk);
    }

    /**
     * Start launches the background chainer loop.
     *
     * PHP: marks the processor as running and resets the stop signal; the loop
     * itself must be driven with {@see run()} (see the class doc).
     */
    public function start(): void
    {
        if ($this->running) {
            return;
        }
        $this->running = true;
        $this->stopRequested = false; // Go: p.stopCh = make(chan struct{})
        Log::get()->info('Hash-chain processor started');
    }

    /**
     * Stop signals the loop to exit and waits for it.
     *
     * PHP: flags the running loop to exit after the tick it is processing;
     * {@see run()} logs "Hash-chain processor stopped" once it has exited.
     */
    public function stop(): void
    {
        if (!$this->running) {
            return;
        }
        $this->running = false;
        $this->stopRequested = true; // Go: close(p.stopCh)
    }

    /**
     * IsRunning reports whether the loop is active.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * run is the chainer loop (Go: `run(ctx)`), blocking until stopped.
     *
     * Parameters:
     * - $stopFlag: optional `callable(): bool` playing the role of `ctx.Done()`;
     *   when it returns true the loop exits.
     *
     * @param callable(): bool|null $stopFlag
     */
    public function run(?callable $stopFlag = null): void
    {
        $this->start();
        try {
            while (true) {
                $outcome = $this->waitTick($this->pollInterval, $stopFlag);
                if ($outcome !== self::TICK) {
                    return;
                }
                $this->processTick();
            }
        } finally {
            $this->running = false;
            if ($this->stopRequested) {
                // Go: logged by Stop() once wg.Wait() returns.
                Log::get()->info('Hash-chain processor stopped');
            }
        }
    }

    /**
     * runOnce performs a single tick (one ticker iteration of the Go loop) —
     * the one-shot entry point for the workers CLI.
     */
    public function runOnce(): void
    {
        $this->processTick();
    }

    /**
     * processTick chains as many batches as are ready (bounded), then records metrics.
     */
    public function processTick(): void
    {
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(sprintf('-%d seconds', (int) $this->trailingDelay));
        for ($i = 0; $i < Blnk::maxBatchesPerTick; $i++) {
            try {
                $n = $this->blnk->getDataSource()->chainPendingTransactions($cutoff, $this->batchSize);
            } catch (\Throwable $err) {
                Log::get()->error('hash-chain: failed to seal batch', ['error' => $err->getMessage()]);
                break;
            }
            if ($n < $this->batchSize) {
                break; // caught up
            }
        }
        $this->recordMetrics($cutoff);
    }

    /**
     * recordMetrics records the chain head, lag and backlog gauges (errors are
     * ignored, as in Go's `if ..., err := ...; err == nil` guards).
     */
    private function recordMetrics(\DateTimeImmutable $cutoff): void
    {
        try {
            $state = $this->blnk->getDataSource()->getChainState();
            Metrics::chainHeadSeq()->record($state->lastSeq);
            Metrics::chainLagSeconds()->record(self::secondsSince($state->updatedAt));
        } catch (\Throwable) {
            // Go: metrics only recorded when err == nil.
        }
        try {
            $backlog = $this->blnk->getDataSource()->countUnchainedTransactions($cutoff);
            Metrics::chainBacklog()->record($backlog);
        } catch (\Throwable) {
            // Go: metrics only recorded when err == nil.
        }
    }

    /**
     * secondsSince is the analogue of `time.Since(t).Seconds()`; a null stands
     * for Go's zero time.
     */
    private static function secondsSince(?\DateTimeImmutable $t): float
    {
        $t ??= new \DateTimeImmutable('0001-01-01T00:00:00+00:00');
        return microtime(true) - ((float) $t->format('U') + ((int) $t->format('u')) / 1_000_000);
    }

    /**
     * waitTick sleeps for one poll interval (Go: `ticker.C`) in small slices so
     * a stop request (Go: `stopCh`) or the stop flag (Go: `ctx.Done()`) is
     * noticed promptly; pending POSIX signals are dispatched between slices when
     * ext-pcntl is available, so a signal handler calling {@see stop()} works.
     *
     * @param callable(): bool|null $stopFlag
     * @return int one of TICK, STOP_CONTEXT, STOP_SIGNAL
     */
    private function waitTick(int|float $seconds, ?callable $stopFlag): int
    {
        $deadline = microtime(true) + $seconds;
        while (true) {
            if (\function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            if ($stopFlag !== null && $stopFlag()) {
                return self::STOP_CONTEXT;
            }
            if ($this->stopRequested) {
                return self::STOP_SIGNAL;
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return self::TICK;
            }
            usleep((int) min(self::WAIT_SLICE_USEC, (int) ceil($remaining * 1_000_000)));
        }
    }
}
