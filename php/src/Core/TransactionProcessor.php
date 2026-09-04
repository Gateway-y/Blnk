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

use Blnk\Database\DataSourceInterface;
use Blnk\Internal\Log;
use Blnk\Model\Reconciliation;
use Blnk\Model\ReconciliationMatch;
use Blnk\Model\ReconciliationProgress;
use Blnk\Model\Transaction;

/**
 * transactionProcessor represents the processor for handling reconciliation-related transactions.
 * Fields:
 * - reconciliation: The reconciliation object that holds transaction data to be processed.
 * - progress: Tracks the progress of the reconciliation process.
 * - reconciler: A function that handles the reconciliation logic for a batch of transactions.
 * - matches: Counter for transactions that have been successfully matched.
 * - unmatched: Counter for transactions that couldn't be matched.
 * - datasource: The interface for database operations, enabling interaction with the data source.
 * - progressSaveCount: The number of transactions processed before saving progress.
 *
 * (Go: `transactionProcessor`, reconciliation.go. The `mu sync.Mutex` guarding
 * matches, unmatched and progress is dropped: the PHP port processes
 * transactions sequentially.)
 */
final class TransactionProcessor
{
    public Reconciliation $reconciliation;

    public ReconciliationProgress $progress;

    /**
     * reconciler defines the function type for reconciling a batch of transactions.
     * It accepts a slice of transactions, and returns the matched transactions and unmatched ones.
     *
     * @var \Closure(Transaction[]): array{0: ReconciliationMatch[], 1: string[]}
     */
    public \Closure $reconciler;

    public int $matches = 0;

    public int $unmatched = 0;

    public DataSourceInterface $datasource;

    public int $progressSaveCount = 0;

    public Blnk $blnk;

    /**
     * @param callable(Transaction[]): array{0: ReconciliationMatch[], 1: string[]} $reconciler
     */
    public function __construct(
        Reconciliation $reconciliation,
        ReconciliationProgress $progress,
        callable $reconciler,
        DataSourceInterface $datasource,
        int $progressSaveCount,
        Blnk $blnk
    ) {
        $this->reconciliation = $reconciliation;
        $this->progress = $progress;
        $this->reconciler = \Closure::fromCallable($reconciler);
        $this->datasource = $datasource;
        $this->progressSaveCount = $progressSaveCount;
        $this->blnk = $blnk;
    }

    /**
     * process handles individual transaction processing, applying the reconciliation logic and recording results.
     * It also updates internal transaction metadata asynchronously when matches are found
     * (PHP: synchronously).
     * Parameters:
     * - txn: The transaction to process.
     * Returns:
     * - error: If processing or recording results fails (thrown).
     *
     * @throws \Throwable
     */
    public function process(Transaction $txn): void
    {
        // Reconcile the batch of transactions and get matches and unmatched transactions.
        [$batchMatches, $batchUnmatched] = ($this->reconciler)([$txn]);
        $batchMatches = $batchMatches ?? [];
        $batchUnmatched = $batchUnmatched ?? [];

        // Increment the counters for matched and unmatched transactions.
        $this->matches += \count($batchMatches);
        $this->unmatched += \count($batchUnmatched);

        // If the reconciliation is not a dry run, record the matches and unmatched transactions.
        if (!$this->reconciliation->isDryRun) {
            if (\count($batchMatches) > 0) {
                // Record the matched transactions.
                $this->datasource->recordMatches($this->reconciliation->reconciliationID, $batchMatches);

                // Asynchronously (Go) update the metadata for each matched internal transaction
                $this->updateMatchedTransactionsMetadata($batchMatches);
            }

            if (\count($batchUnmatched) > 0) {
                // Record the unmatched transactions.
                $this->datasource->recordUnmatched($this->reconciliation->reconciliationID, $batchUnmatched);
            }
        }

        // Update the progress with the last processed transaction ID and increment the processed count.
        $this->progress->lastProcessedExternalTxnID = $txn->transactionID;
        $this->progress->processedCount++;
        // (Go: integer divide by zero panics when progressSaveCount is 0; PHP throws DivisionByZeroError.)
        $shouldSave = $this->progress->processedCount % $this->progressSaveCount === 0;
        $progressSnapshot = clone $this->progress;

        // Periodically save the reconciliation progress.
        if ($shouldSave) {
            try {
                $this->datasource->saveReconciliationProgress($this->reconciliation->reconciliationID, $progressSnapshot);
            } catch (\Throwable $err) {
                Log::get()->error(sprintf('Error saving reconciliation progress: %s', self::errorString($err)));
            }
        }
    }

    /**
     * updateMatchedTransactionsMetadata updates the metadata for internal transactions that were matched.
     * This function adds reconciliation information to the internal transaction's metadata.
     * Parameters:
     * - matches: The list of matches to process.
     *
     * @param ReconciliationMatch[] $matches
     */
    public function updateMatchedTransactionsMetadata(array $matches): void
    {
        foreach ($matches as $match) {
            // Prepare metadata with reconciliation information
            $metadata = [
                'reconciled' => true,
                'reconciliation_id' => $this->reconciliation->reconciliationID,
                'reconciled_at' => self::goRFC3339(new \DateTimeImmutable('now')),
                'external_txn_id' => $match->externalTransactionID,
                'reconciliation_amount' => $match->amount,
            ];

            // Update the internal transaction's metadata
            try {
                $this->callUpdateEntityMetadata('transactions', $match->internalTransactionID, $metadata);
            } catch (\Throwable $err) {
                Log::get()->error(sprintf('Error updating metadata for transaction %s: %s', $match->internalTransactionID, self::errorString($err)));
            }
        }
    }

    /**
     * getResults returns the total counts of matched and unmatched transactions processed during reconciliation.
     * Returns:
     * - int: The count of matched transactions.
     * - int: The count of unmatched transactions.
     *
     * @return array{0: int, 1: int} `[$matches, $unmatched]`
     */
    public function getResults(): array
    {
        return [$this->matches, $this->unmatched];
    }

    /**
     * callUpdateEntityMetadata invokes `Blnk::updateEntityMetadata()` (Go:
     * `tp.blnk.updateEntityMetadata`). The Go method is unexported but reachable
     * within the package; in PHP it is protected, so the call is made through a
     * closure bound to the Blnk scope.
     *
     * @param array<string, mixed> $metadata
     * @throws \Throwable
     */
    private function callUpdateEntityMetadata(string $entityType, string $entityID, array $metadata): void
    {
        $updater = \Closure::bind(
            function (string $entityType, string $entityID, array $metadata): void {
                $this->updateEntityMetadata($entityType, $entityID, $metadata);
            },
            $this->blnk,
            Blnk::class
        );
        $updater($entityType, $entityID, $metadata);
    }

    /**
     * goRFC3339 formats a time like Go's `time.Now().Format(time.RFC3339)`
     * ("2006-01-02T15:04:05Z07:00": no fractional seconds, "Z" for UTC).
     */
    private static function goRFC3339(\DateTimeImmutable $t): string
    {
        $offset = $t->format('P');
        if ($offset === '+00:00') {
            $offset = 'Z';
        }
        return $t->format('Y-m-d\TH:i:s') . $offset;
    }

    /**
     * errorString renders an exception the way Go's `err.Error()` would (see
     * Blnk::goErrorString, which is not reachable from this class).
     */
    private static function errorString(\Throwable $err): string
    {
        if ($err instanceof \Blnk\Internal\ApiError\ApiErrorException) {
            return $err->error();
        }
        return $err->getMessage();
    }
}
