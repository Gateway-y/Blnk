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

use Blnk\Model\Transaction;

/**
 * QueueRecovery is the port of the root-package file `queue_recovery.go` as
 * composed into {@see Blnk}: the `*Blnk` method RecoverQueuedTransactions and
 * the package-level helper isReferenceAlreadyUsedError. The file's
 * `QueuedTransactionRecoveryProcessor` struct is
 * {@see QueuedTransactionRecoveryProcessor}.
 */
trait QueueRecovery
{
    /**
     * RecoverQueuedTransactions triggers an immediate recovery of stuck queued transactions
     * using the provided threshold. This is exposed for the manual trigger API endpoint.
     *
     * Parameters:
     * - threshold time.Duration: the stuck age threshold, in seconds (raised to 2 minutes when lower).
     *
     * Returns:
     * - int: the number of stuck transactions processed.
     * - error: always nil in Go (the PHP port never throws).
     */
    public function recoverQueuedTransactions(int|float $threshold): int
    {
        if ($threshold < 2 * 60) {
            $threshold = 2 * 60; // 2 * time.Minute
        }

        $processor = QueuedTransactionRecoveryProcessor::newQueuedTransactionRecoveryProcessor($this);
        return $processor->recoverWithThreshold($threshold);
    }

    /**
     * isReferenceAlreadyUsedError reports whether an error is a duplicate
     * transaction-reference failure (delegates to IsDuplicateReferenceError).
     */
    public static function isReferenceAlreadyUsedError(?\Throwable $err): bool
    {
        return self::isDuplicateReferenceError($err);
    }

    /**
     * processQueuedTransactionWithResult exposes the unexported Go
     * `processQueuedTransaction` (returning the transactionExecutionResult) to
     * {@see QueuedTransactionRecoveryProcessor}, whose Go counterpart reaches it
     * as a same-package method value (`blnk.processQueuedTransaction`).
     * PHP visibility bridge only — no Go counterpart.
     *
     * @throws \Throwable
     */
    public function processQueuedTransactionWithResult(Transaction $transaction, bool $hotLane): TransactionExecutionResult
    {
        return $this->processQueuedTransactionInternal($transaction, $hotLane);
    }
}
