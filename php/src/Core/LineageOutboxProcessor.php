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

use Blnk\Internal\ApiError\ApiErrorException;
use Blnk\Internal\Log;
use Blnk\Model\LineageOutbox;

/**
 * LineageOutboxProcessor processes pending lineage outbox entries.
 * It polls the database for pending entries and processes them asynchronously,
 * ensuring that lineage work is never lost even if Redis/queue operations fail.
 *
 * (Go: `LineageOutboxProcessor`, lineage_worker.go.)
 *
 * Concurrency divergence (documented, per PORTING.md "Concurrency"): Go's
 * `Start` launches the polling loop in a goroutine and `Stop` closes `stopCh`
 * and waits on the WaitGroup. PHP has no goroutines, so the loop is driven
 * explicitly by the workers CLI:
 *
 *  - {@see run()} is the blocking loop (`run(ctx)`): it ticks every
 *    `pollInterval`, calling {@see processBatch()} on each tick, until
 *    {@see stop()} is called (Go: `stopCh`) — from a signal handler or the
 *    optional `$stopFlag` callable, which plays the role of `ctx.Done()`.
 *  - {@see runOnce()} performs a single poll (`processBatch`) for one-shot
 *    invocations.
 *  - {@see start()} only marks the processor running (it cannot spawn a
 *    background loop); {@see stop()} flags the loop to exit.
 *
 * Durations (`pollInterval`, `lockDuration`) are seconds, like every
 * `time.Duration` in the PHP port.
 */
final class LineageOutboxProcessor
{
    /** {@see waitTick()} outcomes. */
    private const TICK = 0;
    private const STOP_CONTEXT = 1;
    private const STOP_SIGNAL = 2;

    /** Granularity of the interruptible wait, in microseconds. */
    private const WAIT_SLICE_USEC = 100_000;

    private Blnk $blnk;

    private int $batchSize;

    /** Seconds (Go: time.Duration). */
    private int|float $pollInterval;

    /** Seconds (Go: time.Duration). */
    private int|float $lockDuration;

    /** Go: `stopCh chan struct{}` — set by stop(), observed by run(). */
    private bool $stopRequested = false;

    private bool $running = false;

    /**
     * NewLineageOutboxProcessor creates a new outbox processor.
     *
     * Parameters:
     * - blnk *Blnk: The Blnk instance containing the datasource and processing logic.
     *
     * Returns:
     * - *LineageOutboxProcessor: The configured processor.
     */
    public function __construct(Blnk $blnk)
    {
        $this->blnk = $blnk;
        $this->batchSize = 100;
        $this->pollInterval = 1;  // 1 * time.Second
        $this->lockDuration = 30; // 30 * time.Second
    }

    /**
     * NewLineageOutboxProcessor — static factory mirroring the Go constructor
     * function name; equivalent to `new LineageOutboxProcessor($blnk)`.
     */
    public static function newLineageOutboxProcessor(Blnk $blnk): self
    {
        return new self($blnk);
    }

    /**
     * WithBatchSize sets the batch size for processing outbox entries.
     *
     * Parameters:
     * - size int: The number of entries to process per batch.
     *
     * Returns:
     * - *LineageOutboxProcessor: The processor for chaining.
     */
    public function withBatchSize(int $size): self
    {
        $this->batchSize = $size;
        return $this;
    }

    /**
     * WithPollInterval sets the interval between polls.
     *
     * Parameters:
     * - interval time.Duration: The poll interval (seconds).
     *
     * Returns:
     * - *LineageOutboxProcessor: The processor for chaining.
     */
    public function withPollInterval(int|float $interval): self
    {
        $this->pollInterval = $interval;
        return $this;
    }

    /**
     * WithLockDuration sets the lock duration for claimed entries.
     *
     * Parameters:
     * - duration time.Duration: The lock duration (seconds).
     *
     * Returns:
     * - *LineageOutboxProcessor: The processor for chaining.
     */
    public function withLockDuration(int|float $duration): self
    {
        $this->lockDuration = $duration;
        return $this;
    }

    /**
     * Start begins processing outbox entries in the background.
     * The processor will poll for pending entries at the configured interval.
     *
     * PHP: marks the processor as running and resets the stop signal; the
     * polling loop itself must be driven with {@see run()} (see the class doc).
     */
    public function start(): void
    {
        if ($this->running) {
            return;
        }
        $this->running = true;
        $this->stopRequested = false; // Go: p.stopCh = make(chan struct{})
    }

    /**
     * Stop gracefully stops the outbox processor.
     * It signals the processor to stop and waits for pending work to complete.
     *
     * PHP: flags the running loop to exit after the entry it is processing;
     * {@see run()} logs "Lineage outbox processor stopped" once it has exited.
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
     * IsRunning returns whether the processor is currently running.
     *
     * Returns:
     * - bool: True if the processor is running.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * run is the main processing loop (Go: `run(ctx)`), blocking until stopped.
     *
     * Parameters:
     * - $stopFlag: optional `callable(): bool` playing the role of `ctx.Done()`;
     *   when it returns true the loop exits ("context cancelled").
     *
     * @param callable(): bool|null $stopFlag
     */
    public function run(?callable $stopFlag = null): void
    {
        $this->start();
        try {
            while (true) {
                $outcome = $this->waitTick($this->pollInterval, $stopFlag);
                if ($outcome === self::STOP_CONTEXT) {
                    Log::get()->info('Lineage outbox processor context cancelled');
                    return;
                }
                if ($outcome === self::STOP_SIGNAL) {
                    Log::get()->info('Lineage outbox processor stop signal received');
                    return;
                }
                $this->processBatch();
            }
        } finally {
            $this->running = false;
            if ($this->stopRequested) {
                // Go: logged by Stop() once wg.Wait() returns.
                Log::get()->info('Lineage outbox processor stopped');
            }
        }
    }

    /**
     * runOnce performs a single poll of the outbox (one ticker iteration of the
     * Go loop) — the one-shot entry point for the workers CLI.
     */
    public function runOnce(): void
    {
        $this->processBatch();
    }

    /**
     * processBatch claims and processes a batch of pending outbox entries.
     */
    public function processBatch(): void
    {
        try {
            $entries = $this->blnk->getDataSource()->claimPendingOutboxEntries($this->batchSize, $this->lockDuration);
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('failed to claim outbox entries: %s', self::errorString($err)));
            return;
        }

        if (\count($entries) === 0) {
            return;
        }

        Log::get()->info(sprintf('Processing %d lineage outbox entries', \count($entries)));

        foreach ($entries as $entry) {
            try {
                $this->processEntry($entry);
            } catch (\Throwable $err) {
                Log::get()->error(sprintf('failed to process outbox entry %d (txn: %s): %s', $entry->id, $entry->transactionID, self::errorString($err)));
                try {
                    $this->blnk->getDataSource()->markOutboxFailed($entry->id, self::errorString($err));
                } catch (\Throwable $markErr) {
                    Log::get()->error(sprintf('failed to mark outbox entry %d as failed: %s', $entry->id, self::errorString($markErr)));
                }
                continue;
            }
            try {
                $this->blnk->getDataSource()->markOutboxCompleted($entry->id);
            } catch (\Throwable $markErr) {
                Log::get()->error(sprintf('failed to mark outbox entry %d as completed: %s', $entry->id, self::errorString($markErr)));
            }
        }
    }

    /**
     * processEntry processes a single outbox entry.
     *
     * @throws \Throwable
     */
    public function processEntry(LineageOutbox $entry): void
    {
        $this->blnk->processLineageFromOutbox($entry);
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

    /**
     * errorString renders an exception the way Go's `err.Error()` would (see
     * Blnk::goErrorString, which is not reachable from this class).
     */
    private static function errorString(\Throwable $err): string
    {
        if ($err instanceof ApiErrorException) {
            return $err->error();
        }
        return $err->getMessage();
    }
}
