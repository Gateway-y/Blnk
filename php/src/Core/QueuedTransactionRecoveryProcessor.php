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

use Blnk\Internal\HotPairs\Manager as HotPairsManager;
use Blnk\Internal\HotPairs\Router;
use Blnk\Internal\Log;
use Blnk\Model\Transaction;

/**
 * QueuedTransactionRecoveryProcessor replays QUEUED transactions whose queue
 * copy never got processed (Go: `QueuedTransactionRecoveryProcessor`,
 * queue_recovery.go).
 *
 * Concurrency divergence (documented, PORTING.md "Concurrency"): the Go
 * processor runs a ticker loop in a goroutine (Start/Stop guarded by a mutex
 * and a WaitGroup). PHP is single-threaded, so:
 *  - {@see self::start()} / {@see self::stop()} only flip the running flag
 *    (and log, as Go does);
 *  - {@see self::run()} is the blocking ticker loop for a dedicated process
 *    (it exits when stop() is called, e.g. from a pcntl signal handler);
 *  - {@see self::tick()} is the cooperative form for the `blnk workers` loop:
 *    it runs one recovery pass when the poll interval has elapsed.
 * Durations are integer seconds.
 */
final class QueuedTransactionRecoveryProcessor
{
    private Blnk $blnk;

    private int $batchSize;

    private int $maxWorkers;

    /** Seconds (Go: pollInterval time.Duration). */
    private int $pollInterval;

    /** Seconds (Go: stuckThreshold time.Duration). */
    private int $stuckThreshold;

    private int $maxRecoveryAttempts;

    private bool $running = false;

    /** Go: `stopCh chan struct{}` — set by stop() to end run(). */
    private bool $stopRequested = false;

    /** Next scheduled pass of the ticker loop (unix time), while running. */
    private ?float $nextRunAt = null;

    /**
     * Go: `processQueuedTransaction func(ctx, txn, hotLane) (transactionExecutionResult, error)`
     * — the queued executor, replaceable for tests.
     *
     * @var callable(Transaction, bool): TransactionExecutionResult
     */
    private $processQueuedTransaction;

    /**
     * NewQueuedTransactionRecoveryProcessor creates the stuck queued-transaction recovery loop
     * with conservative single-worker defaults to avoid recovery-induced lock storms.
     */
    public function __construct(Blnk $blnk)
    {
        $this->blnk = $blnk;
        $this->batchSize = 100;
        $this->maxWorkers = 1;
        $this->pollInterval = 30 * 60; // 30 * time.Minute
        $this->stuckThreshold = 2 * 60 * 60; // 2 * time.Hour
        $this->maxRecoveryAttempts = 3;
        $this->processQueuedTransaction = $blnk->processQueuedTransactionWithResult(...);
    }

    /**
     * NewQueuedTransactionRecoveryProcessor — static factory mirroring the Go
     * constructor function name; equivalent to `new QueuedTransactionRecoveryProcessor($blnk)`.
     */
    public static function newQueuedTransactionRecoveryProcessor(Blnk $blnk): self
    {
        return new self($blnk);
    }

    /**
     * setProcessQueuedTransaction replaces the queued executor (Go: assigning
     * the struct's `processQueuedTransaction` field in tests).
     *
     * @param callable(Transaction, bool): TransactionExecutionResult $fn
     */
    public function setProcessQueuedTransaction(callable $fn): void
    {
        $this->processQueuedTransaction = $fn;
    }

    /**
     * Start begins the background recovery loop for stuck queued transactions.
     *
     * PHP: marks the processor running and schedules the first pass one poll
     * interval from now (Go's ticker fires after the first interval); drive it
     * with {@see self::tick()} or {@see self::run()}.
     */
    public function start(): void
    {
        if ($this->running) {
            return;
        }
        $this->running = true;
        $this->stopRequested = false;
        $this->nextRunAt = microtime(true) + $this->pollInterval;

        Log::get()->info('Queued transaction recovery processor started');
    }

    /**
     * Stop shuts down the background recovery loop and waits for the worker goroutine to exit.
     */
    public function stop(): void
    {
        if (!$this->running) {
            return;
        }
        $this->running = false;
        $this->stopRequested = true;
        $this->nextRunAt = null;

        Log::get()->info('Queued transaction recovery processor stopped');
    }

    /**
     * IsRunning reports whether the recovery processor is currently active.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * run executes the poll loop that periodically scans for stuck queued transactions.
     *
     * PHP: blocks the calling process until {@see self::stop()} is invoked
     * (from a signal handler or another callback); sleeps in one-second steps
     * between checks so a stop request is honored promptly.
     */
    public function run(): void
    {
        if (!$this->running) {
            $this->start();
        }

        while (!$this->stopRequested) {
            if ($this->tick()) {
                continue;
            }
            sleep(1);
        }

        Log::get()->info('Queued transaction recovery processor stop signal received');
    }

    /**
     * tick runs one recovery pass when the processor is running and the poll
     * interval has elapsed (the ticker firing). Returns whether a pass ran.
     * PHP-only cooperative scheduling hook for the worker loop.
     */
    public function tick(): bool
    {
        if (!$this->running || $this->nextRunAt === null || microtime(true) < $this->nextRunAt) {
            return false;
        }
        $this->nextRunAt = microtime(true) + $this->pollInterval;
        $this->processBatch();
        return true;
    }

    /**
     * processBatch performs one periodic stuck-queue recovery pass using the configured threshold.
     */
    public function processBatch(): void
    {
        $this->recoverWithThreshold($this->stuckThreshold);
    }

    /**
     * recoverWithThreshold loads currently stuck queued transactions and reprocesses them serially.
     *
     * Parameters:
     * - threshold: the stuck age threshold in seconds (Go: time.Duration).
     *
     * (Unexported in Go; public here because {@see QueueRecovery::recoverQueuedTransactions()}
     * lives on another class.)
     */
    public function recoverWithThreshold(int|float $threshold): int
    {
        try {
            $stuckTxns = $this->blnk->getDataSource()->getStuckQueuedTransactions($threshold, $this->batchSize);
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('failed to get stuck queued transactions: %s', $err->getMessage()));
            return 0;
        }

        if (\count($stuckTxns) === 0) {
            return 0;
        }

        Log::get()->info(sprintf('Processing %d stuck queued transactions with %d workers (threshold=%s)', \count($stuckTxns), $this->maxWorkers, self::goDurationString((float) $threshold)));

        foreach ($stuckTxns as $txn) {
            try {
                $this->processStuckTransaction($txn);
            } catch (\Throwable $err) {
                Log::get()->error(sprintf('failed to process stuck transaction %s: %s', $txn->transactionID, $err->getMessage()));
            }
        }

        return \count($stuckTxns);
    }

    /**
     * processStuckTransaction replays one stuck queued transaction, preserving the existing recovery
     * attempt tracking and rejection semantics while preferring the shared queued executor path.
     *
     * @throws \Throwable
     */
    private function processStuckTransaction(Transaction $stuckTxn): void
    {
        Blnk::restoreTransactionFlagsFromMetadata($stuckTxn);

        $attempts = 0;
        if ($stuckTxn->metaData !== null) {
            if (\array_key_exists('recovery_attempts', $stuckTxn->metaData)) {
                $v = $stuckTxn->metaData['recovery_attempts'];
                // Go: float64 (JSON-decoded) or int
                if (\is_float($v) || \is_int($v)) {
                    $attempts = (int) $v;
                }
            }
        }
        $attempts++;

        if ($attempts > $this->maxRecoveryAttempts) {
            Log::get()->warning(sprintf('Stuck transaction %s exceeded max recovery attempts (%d), rejecting', $stuckTxn->transactionID, $this->maxRecoveryAttempts));
            $rejectionCopy = Blnk::createQueueCopy($stuckTxn, $stuckTxn->reference);
            try {
                $this->blnk->rejectTransaction($rejectionCopy, 'exceeded max queued recovery attempts');
            } catch (\Throwable $err) {
                if (Blnk::isReferenceAlreadyUsedError($err)) {
                    return;
                }
                throw $err;
            }
            return;
        }

        if ($stuckTxn->atomic) {
            $parentID = $stuckTxn->metaData['QUEUED_PARENT_TRANSACTION'] ?? null;
            if (\is_string($parentID) && $parentID !== '') {
                $siblings = $this->blnk->getDataSource()->getTransactionsByParent($parentID, 100, 0);
                foreach ($siblings as $sibling) {
                    if ($sibling->status === Blnk::StatusRejected) {
                        Log::get()->info(sprintf('Skipping stuck transaction %s: sibling %s is REJECTED in atomic group', $stuckTxn->transactionID, $sibling->transactionID));
                        return;
                    }
                }
            }
        }

        $queueCopy = Blnk::createQueueCopy($stuckTxn, $stuckTxn->reference);
        try {
            $result = $this->tryRecordRecoveredTransaction($queueCopy);
        } catch (\Throwable $err) {
            if (Blnk::isReferenceAlreadyUsedError($err)) {
                Log::get()->info(sprintf('Stuck transaction %s already processed (reference %s already used)', $stuckTxn->transactionID, $queueCopy->reference));
                $this->updateRecoveryMetadata($stuckTxn, $attempts, 'already_processed');
                return;
            }

            $this->updateRecoveryMetadata($stuckTxn, $attempts, 'failed');
            throw $err;
        }

        if ($result->usedCoalescing()) {
            Log::get()->info(sprintf('Successfully recovered stuck transaction %s via coalesced batch', $stuckTxn->transactionID));
        } else {
            Log::get()->info(sprintf('Successfully recovered stuck transaction %s via queue copy %s', $stuckTxn->transactionID, $queueCopy->transactionID));
        }
        $this->updateRecoveryMetadata($stuckTxn, $attempts, 'recovered');
    }

    /**
     * tryRecordRecoveredTransaction routes stuck-transaction replay through the shared queued
     * processing path, selecting hot-lane execution when the queued metadata requires it.
     *
     * @throws \Throwable
     */
    private function tryRecordRecoveredTransaction(Transaction $queueCopy): TransactionExecutionResult
    {
        $hotLane = Router::queueLaneFromMetadata($queueCopy->metaData) === HotPairsManager::LaneHot;
        return ($this->processQueuedTransaction)($queueCopy, $hotLane);
    }

    /**
     * updateRecoveryMetadata stores recovery attempt and status information on the stuck parent
     * transaction so later recovery passes can make bounded retry decisions.
     */
    private function updateRecoveryMetadata(Transaction $txn, int $attempts, string $status): void
    {
        if ($txn->metaData === null) {
            $txn->metaData = [];
        }
        $txn->metaData['recovery_attempts'] = $attempts;
        $txn->metaData['recovery_status'] = $status;
        // Go: time.Now().UTC().Format(time.RFC3339)
        $txn->metaData['recovery_last_attempt'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        try {
            $this->blnk->getDataSource()->updateTransactionMetadata($txn->transactionID, $txn->metaData);
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('failed to update recovery metadata for transaction %s: %s', $txn->transactionID, $err->getMessage()));
        }
    }

    /**
     * goDurationString formats seconds the way Go prints a time.Duration with
     * `%v` (e.g. "2h0m0s", "30m0s", "2m0s", "1.5s", "500ms").
     */
    private static function goDurationString(float $seconds): string
    {
        if ($seconds < 0) {
            return '-' . self::goDurationString(-$seconds);
        }
        if ($seconds < 1) {
            $ns = (int) round($seconds * 1e9);
            if ($ns === 0) {
                return '0s';
            }
            if ($ns < 1000) {
                return $ns . 'ns';
            }
            if ($ns < 1000000) {
                return self::trimFloat($ns / 1000) . 'µs';
            }
            return self::trimFloat($ns / 1e6) . 'ms';
        }

        $hours = (int) floor($seconds / 3600);
        $rem = $seconds - $hours * 3600;
        $minutes = (int) floor($rem / 60);
        $secs = $rem - $minutes * 60;

        $out = '';
        if ($hours > 0) {
            $out .= $hours . 'h';
        }
        if ($hours > 0 || $minutes > 0) {
            $out .= $minutes . 'm';
        }
        return $out . self::trimFloat($secs) . 's';
    }

    /** trimFloat renders a number without trailing zeros ("2", "1.5"). */
    private static function trimFloat(float $value): string
    {
        $s = rtrim(rtrim(sprintf('%.9F', $value), '0'), '.');
        return $s === '' ? '0' : $s;
    }
}
