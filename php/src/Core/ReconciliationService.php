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

use Blnk\Config\Configuration;
use Blnk\Internal\Files\Files;
use Blnk\Internal\Log;
use Blnk\Internal\Notification\Notification;
use Blnk\Internal\Traces\Tracer;
use Blnk\Model\ExternalTransaction;
use Blnk\Model\MatchingCriteria;
use Blnk\Model\MatchingRule;
use Blnk\Model\ModelHelpers;
use Blnk\Model\Reconciliation;
use Blnk\Model\ReconciliationMatch;
use Blnk\Model\ReconciliationProgress;
use Blnk\Model\Transaction;
use Brick\Math\BigInteger;

/**
 * ReconciliationService is the port of the root-package file `reconciliation.go`.
 *
 * Standalone types of that file:
 *  - `transactionProcessor` → {@see TransactionProcessor}
 *  - `reconciler` (func type) → the callable contract
 *    `callable(\Blnk\Model\Transaction[] $txns): array{0: ReconciliationMatch[], 1: string[]}`
 *    returning `[$matches, $unmatchedIDs]` (Go: `([]model.Match, []string)`).
 *  - `model.Match` is {@see ReconciliationMatch} (`match` is reserved in PHP).
 *
 * Concurrency (documented divergences, per PORTING.md "Concurrency"):
 *  - Go detaches `processReconciliation` in a goroutine and returns the
 *    reconciliation ID immediately; the PHP port runs it synchronously, in-line,
 *    with the identical error handling (log + status "failed"), then returns the
 *    ID. Go has no dedicated asynq task type for reconciliation, so no queue task
 *    was invented for it.
 *  - The Go matching strategies fan out over goroutines bounded by
 *    `Transaction.MaxWorkers` and collect results on buffered channels; the PHP
 *    port processes the same work sequentially and the "channels" are arrays
 *    passed by reference (`$matchChan`, `$unMatchChan`), which can never be full.
 *  - `go tp.updateMatchedTransactionsMetadata(...)` and the goroutine in
 *    `postReconciliationActions` run synchronously.
 *  - Go's map iteration order is random; PHP iterates group maps in insertion
 *    order (one of the orders Go could produce).
 *
 * `otel.Tracer("blnk.reconciliation")` maps to `Tracer::get('blnk.reconciliation')`.
 */
trait ReconciliationService
{
    // Status constants representing the various states a process can be in.
    public const StatusStarted = 'started';        // Indicates the process has started.
    public const StatusInProgress = 'in_progress'; // Indicates the process is ongoing.
    public const StatusCompleted = 'completed';    // Indicates the process is finished successfully.
    public const StatusFailed = 'failed';          // Indicates the process has failed.

    /**
     * contains checks whether a slice contains a specific string.
     * Parameters:
     * - slice: The slice to check.
     * - item: The item to look for in the slice.
     * Returns:
     * - bool: True if the item is found in the slice, otherwise false.
     *
     * @param string[] $slice
     */
    private static function contains(array $slice, string $item): bool
    {
        foreach ($slice as $a) {
            if ($a === $item) {
                return true;
            }
        }
        return false;
    }

    /**
     * UploadExternalData handles the process of uploading external data by detecting file type, parsing, and storing it.
     * Parameters:
     * - source: The source of the external data.
     * - reader: An io.Reader for reading the data (PHP: a stream resource, PSR-7 stream, or raw string).
     * - filename: The name of the file being uploaded.
     * Returns:
     * - string: The ID of the upload.
     * - int: The total number of records processed.
     * - error: If any step of the process fails (thrown).
     *
     * @param resource|\Psr\Http\Message\StreamInterface|string $reader
     * @return array{0: string, 1: int} `[$uploadID, $total]`
     * @throws \Blnk\Internal\Files\FilesException
     */
    public function uploadExternalData(string $source, mixed $reader, string $filename): array
    {
        return Files::uploadExternalData($source, $reader, $filename, function (string $uploadID, ExternalTransaction $txn): void {
            $this->storeExternalTransaction($uploadID, $txn);
        });
    }

    /**
     * storeExternalTransaction stores an external transaction in the datasource.
     * Parameters:
     * - uploadID: The unique ID of the current upload.
     * - txn: The external transaction to store.
     * Returns:
     * - error: If storing the transaction fails (thrown).
     */
    protected function storeExternalTransaction(string $uploadID, ExternalTransaction $txn): void
    {
        $this->datasource->recordExternalTransaction($txn, $uploadID);
    }

    /**
     * postReconciliationActions queues the indexing of reconciliation data in the background.
     * This allows the reconciliation to be indexed without blocking the main process.
     * (Go runs this in a goroutine; the PHP port runs it synchronously.)
     * Parameters:
     * - reconciliation: The reconciliation object to be indexed.
     */
    protected function postReconciliationActions(Reconciliation $reconciliation): void
    {
        try {
            // Queue the reconciliation data for indexing.
            $this->queue->queueIndexData($reconciliation->reconciliationID, 'reconciliations', $reconciliation);
        } catch (\Throwable $err) {
            // If there is an error, notify through the notification system.
            Notification::notifyError($err);
        }
    }

    /**
     * StartReconciliation initiates the reconciliation process by creating a new reconciliation entry and starting the
     * process asynchronously. The process is detached to run in the background.
     * (PHP: the process runs in-line before the ID is returned — see the trait doc.)
     * Parameters:
     * - uploadID: The ID of the uploaded transaction file to reconcile.
     * - strategy: The reconciliation strategy to be used (e.g., "one_to_one").
     * - groupCriteria: Criteria to group transactions (optional).
     * - matchingRuleIDs: The IDs of the rules used for matching transactions.
     * - isDryRun: If true, the reconciliation will not commit changes (useful for testing).
     * Returns:
     * - string: The ID of the reconciliation process.
     * - error: If the reconciliation fails to start (thrown).
     *
     * @param string[] $matchingRuleIDs
     * @throws \Blnk\Internal\ApiError\ApiErrorException if the reconciliation cannot be recorded.
     */
    public function startReconciliation(string $uploadID, string $strategy, string $groupCriteria, array $matchingRuleIDs, bool $isDryRun): string
    {
        // Generate a unique ID for the reconciliation.
        $reconciliationID = ModelHelpers::generateUUIDWithSuffix('recon');
        // Initialize a new reconciliation object with the provided parameters.
        $reconciliation = new Reconciliation();
        $reconciliation->reconciliationID = $reconciliationID;
        $reconciliation->uploadID = $uploadID;
        $reconciliation->status = self::StatusStarted;
        $reconciliation->startedAt = new \DateTimeImmutable('now');
        $reconciliation->isDryRun = $isDryRun;

        // Record the reconciliation in the data source (e.g., database).
        $this->datasource->recordReconciliation($reconciliation);

        // Go detaches the context and starts the reconciliation process asynchronously;
        // the PHP port runs it in-line with the same error handling.
        try {
            $this->processReconciliation($reconciliation, $strategy, $groupCriteria, $matchingRuleIDs);
        } catch (\Throwable $err) {
            // If an error occurs during the reconciliation, log it and update the reconciliation status to "failed".
            Log::get()->error(sprintf('Error in reconciliation process: %s', self::goErrorString($err)));
            try {
                $this->datasource->updateReconciliationStatus($reconciliationID, self::StatusFailed, 0, 0);
            } catch (\Throwable $statusErr) {
                Log::get()->error(sprintf('Error updating reconciliation status: %s', self::goErrorString($statusErr)));
            }
        }

        return $reconciliationID;
    }

    /**
     * StartInstantReconciliation initiates a reconciliation process directly with provided transactions
     * instead of loading them from an uploaded file.
     * Parameters:
     * - externalTransactions: The array of external transactions to reconcile.
     * - strategy: The reconciliation strategy to be used (e.g., "one_to_one").
     * - groupCriteria: Criteria to group transactions (optional).
     * - matchingRuleIDs: The IDs of the rules used for matching transactions.
     * - isDryRun: If true, the reconciliation will not commit changes (useful for testing).
     * Returns:
     * - string: The ID of the reconciliation process.
     * - error: If the reconciliation fails to start (thrown).
     *
     * @param ExternalTransaction[] $externalTransactions
     * @param string[] $matchingRuleIDs
     * @throws \Throwable "failed to store external transaction: ..." or the record error.
     */
    public function startInstantReconciliation(array $externalTransactions, string $strategy, string $groupCriteria, array $matchingRuleIDs, bool $isDryRun): string
    {
        // Generate a unique ID for the reconciliation
        $reconciliationID = ModelHelpers::generateUUIDWithSuffix('recon');

        // Use a temporary ID for the transactions
        $tempID = ModelHelpers::generateUUIDWithSuffix('instant');

        // Initialize a new reconciliation object with the provided parameters
        $reconciliation = new Reconciliation();
        $reconciliation->reconciliationID = $reconciliationID;
        $reconciliation->uploadID = $tempID;
        $reconciliation->status = self::StatusStarted;
        $reconciliation->startedAt = new \DateTimeImmutable('now');
        $reconciliation->isDryRun = $isDryRun;

        // Record the reconciliation in the data source
        $this->datasource->recordReconciliation($reconciliation);

        // Store the provided transactions in the database with the temporary ID
        foreach ($externalTransactions as $txn) {
            try {
                $this->storeExternalTransaction($tempID, $txn);
            } catch (\Throwable $err) {
                // Log error and update reconciliation status
                Log::get()->error(sprintf('Error storing transaction: %s', self::goErrorString($err)));
                try {
                    $this->datasource->updateReconciliationStatus($reconciliationID, self::StatusFailed, 0, 0);
                } catch (\Throwable $statusErr) {
                    Log::get()->error(sprintf('Error updating reconciliation status: %s', self::goErrorString($statusErr)));
                    // Go wraps the (shadowing) status-update error here.
                    throw self::wrapError('failed to store external transaction', $statusErr);
                }
                throw self::wrapError('failed to store external transaction', $err);
            }
        }

        // Go detaches the context and starts the reconciliation process asynchronously;
        // the PHP port runs it in-line with the same error handling.
        try {
            $this->processReconciliation($reconciliation, $strategy, $groupCriteria, $matchingRuleIDs);
        } catch (\Throwable $err) {
            // If an error occurs during the reconciliation, log it and update the reconciliation status to "failed"
            Log::get()->error(sprintf('Error in instant reconciliation process: %s', self::goErrorString($err)));
            try {
                $this->datasource->updateReconciliationStatus($reconciliationID, self::StatusFailed, 0, 0);
            } catch (\Throwable $statusErr) {
                Log::get()->error(sprintf('Error updating reconciliation status: %s', self::goErrorString($statusErr)));
            }
        }

        return $reconciliationID;
    }

    /**
     * GetReconciliation retrieves a reconciliation by its ID.
     * Parameters:
     * - reconciliationID: The ID of the reconciliation to retrieve.
     * Returns:
     * - *model.Reconciliation: The retrieved reconciliation object.
     * - error: If the reconciliation cannot be found or if retrieval fails (thrown).
     *
     * @throws \Throwable "failed to retrieve reconciliation: ..."
     */
    public function getReconciliation(string $reconciliationID): Reconciliation
    {
        try {
            $reconciliation = $this->datasource->getReconciliation($reconciliationID);
        } catch (\Throwable $err) {
            throw self::wrapError('failed to retrieve reconciliation', $err);
        }

        return $reconciliation;
    }

    /**
     * matchesRules checks whether an external transaction matches a group transaction based on specified matching rules.
     * It iterates through the rules and criteria, evaluating whether the transactions meet the conditions for a match.
     * Parameters:
     * - externalTxn: The external transaction to match.
     * - groupTxn: The internal or group transaction to compare against.
     * - rules: A list of matching rules containing criteria to apply.
     * Returns:
     * - bool: True if the transactions match based on the rules, otherwise false.
     *
     * @param MatchingRule[] $rules
     */
    protected function matchesRules(Transaction $externalTxn, Transaction $groupTxn, array $rules): bool
    {
        foreach ($rules as $rule) {
            $allCriteriaMet = true;
            // Iterate through each rule's criteria to check if all conditions are satisfied.
            foreach ($rule->criteria ?? [] as $criteria) {
                $criterionMet = false;
                // Check each field specified in the criteria.
                switch ($criteria->field) {
                    case 'amount':
                        // Compare amounts between the external and group transactions.
                        $criterionMet = $this->matchesGroupAmount($externalTxn->amount, $groupTxn->amount, $criteria);
                        break;
                    case 'date':
                        // Compare the dates of the transactions.
                        $criterionMet = $this->matchesGroupDate($externalTxn->createdAt, $groupTxn->createdAt, $criteria);
                        break;
                    case 'description':
                        // Compare the description fields for a match.
                        $criterionMet = $this->matchesString($externalTxn->description, $groupTxn->description, $criteria);
                        break;
                    case 'reference':
                        // Compare the transaction references for a match.
                        $criterionMet = $this->matchesString($externalTxn->reference, $groupTxn->reference, $criteria);
                        break;
                    case 'currency':
                        // Compare the currencies of the transactions.
                        $criterionMet = $this->matchesCurrency($externalTxn->currency, $groupTxn->currency, $criteria);
                        break;
                }
                // If any criterion is not met, mark the rule as not satisfied.
                if (!$criterionMet) {
                    $allCriteriaMet = false;
                    break;
                }
            }
            // If all criteria are satisfied, return true (the transactions match).
            if ($allCriteriaMet) {
                return true;
            }
        }
        return false;
    }

    /**
     * loadReconciliationProgress retrieves the progress of a reconciliation process.
     * Parameters:
     * - reconciliationID: The ID of the reconciliation to load progress for.
     * Returns:
     * - model.ReconciliationProgress: The current progress of the reconciliation.
     * - error: If the progress cannot be retrieved (thrown).
     */
    protected function loadReconciliationProgress(string $reconciliationID): ReconciliationProgress
    {
        return $this->datasource->loadReconciliationProgress($reconciliationID);
    }

    /**
     * processReconciliation manages the full reconciliation process by executing each step: updating the status,
     * fetching rules, processing transactions, and finalizing the reconciliation.
     * Parameters:
     * - reconciliation: The reconciliation object representing the current reconciliation.
     * - strategy: The reconciliation strategy (e.g., one-to-one, one-to-many).
     * - groupCriteria: Criteria for grouping transactions (optional).
     * - matchingRuleIDs: A list of matching rule IDs to apply during the process.
     * Returns:
     * - error: If any step in the reconciliation process fails (thrown).
     *
     * @param string[] $matchingRuleIDs
     * @throws \Throwable
     */
    protected function processReconciliation(Reconciliation $reconciliation, string $strategy, string $groupCriteria, array $matchingRuleIDs): void
    {
        // Update the reconciliation status to "in progress".
        try {
            $this->updateReconciliationStatus($reconciliation->reconciliationID, self::StatusInProgress);
        } catch (\Throwable $err) {
            throw self::wrapError('failed to update reconciliation status', $err);
        }

        // Retrieve the matching rules that apply to the reconciliation.
        try {
            $matchingRules = $this->getMatchingRules($matchingRuleIDs);
        } catch (\Throwable $err) {
            throw self::wrapError('failed to get matching rules', $err);
        }

        // Initialize the reconciliation progress.
        try {
            $progress = $this->initializeReconciliationProgress($reconciliation->reconciliationID);
        } catch (\Throwable $err) {
            throw self::wrapError('failed to initialize reconciliation progress', $err);
        }

        // Create the reconciler function based on the strategy and rules.
        $reconciler = $this->createReconciler($strategy, $reconciliation->uploadID, $groupCriteria, $matchingRules);

        // Create a transaction processor to handle the reconciliation logic.
        $processor = $this->createTransactionProcessor($reconciliation, $progress, $reconciler);

        // Process the transactions for reconciliation based on the chosen strategy.
        try {
            $this->processTransactions($reconciliation->uploadID, $processor, $strategy);
        } catch (\Throwable $err) {
            throw self::wrapError('failed to process transactions', $err);
        }

        // After processing, retrieve the results (matched and unmatched counts).
        [$matched, $unmatched] = $processor->getResults();

        // Finalize the reconciliation by updating the status and recording the results.
        $this->finalizeReconciliation($reconciliation, $matched, $unmatched);
    }

    /**
     * updateReconciliationStatus updates the status of a reconciliation process in the database.
     * Parameters:
     * - reconciliationID: The ID of the reconciliation.
     * - status: The new status to set.
     * Returns:
     * - error: If the status update fails (thrown).
     */
    protected function updateReconciliationStatus(string $reconciliationID, string $status): void
    {
        $this->datasource->updateReconciliationStatus($reconciliationID, $status, 0, 0);
    }

    /**
     * initializeReconciliationProgress initializes or retrieves the progress of a reconciliation.
     * If no progress exists, it creates a new progress entry.
     * Parameters:
     * - reconciliationID: The ID of the reconciliation.
     * Returns:
     * - model.ReconciliationProgress: The current or newly initialized progress.
     * - error: If the initialization or retrieval fails (never — load errors yield a fresh progress, as in Go).
     */
    protected function initializeReconciliationProgress(string $reconciliationID): ReconciliationProgress
    {
        try {
            $progress = $this->loadReconciliationProgress($reconciliationID);
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('Error loading reconciliation progress: %s', self::goErrorString($err)));
            return new ReconciliationProgress();
        }
        return $progress;
    }

    /**
     * createReconciler creates a reconciler function based on the specified strategy, group criteria, and matching rules.
     * Parameters:
     * - strategy: The reconciliation strategy (e.g., one-to-one, one-to-many).
     * - groupCriteria: Criteria for grouping transactions (optional).
     * - matchingRules: A list of matching rules to apply during reconciliation.
     * Returns:
     * - reconciler: A function that performs reconciliation according to the specified strategy.
     *
     * @param MatchingRule[] $matchingRules
     * @return \Closure(Transaction[]): array{0: ReconciliationMatch[], 1: string[]}
     */
    protected function createReconciler(string $strategy, string $uploadID, string $groupCriteria, array $matchingRules): \Closure
    {
        return function (array $txns) use ($strategy, $uploadID, $groupCriteria, $matchingRules): array {
            switch ($strategy) {
                case 'one_to_one':
                    // Perform one-to-one reconciliation.
                    return $this->oneToOneReconciliation($txns, $matchingRules);
                case 'one_to_many':
                    // Perform one-to-many reconciliation.
                    return $this->oneToManyReconciliation($txns, $groupCriteria, $matchingRules, false);
                case 'many_to_one':
                    // Perform many-to-one reconciliation.
                    return $this->manyToOneReconciliation($txns, $uploadID, $groupCriteria, $matchingRules, true);
                default:
                    Log::get()->warning('unsupported reconciliation strategy', ['strategy' => $strategy]);
                    return [[], []]; // Go: nil, nil
            }
        };
    }

    /**
     * createTransactionProcessor creates a new transaction processor for the reconciliation.
     * Parameters:
     * - reconciliation: The reconciliation object representing the current process.
     * - progress: The current progress of the reconciliation.
     * - reconciler: The reconciler function to apply.
     * Returns:
     * - *transactionProcessor: The created transaction processor.
     *
     * @param callable(Transaction[]): array{0: ReconciliationMatch[], 1: string[]} $reconciler
     */
    protected function createTransactionProcessor(Reconciliation $reconciliation, ReconciliationProgress $progress, callable $reconciler): TransactionProcessor
    {
        // Go: conf, err := config.Fetch(); a fetch error is logged and conf.Reconciliation
        // is then dereferenced (nil pointer panic) — the PHP port logs and rethrows.
        try {
            $conf = Configuration::fetch();
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('Error fetching configuration: %s', self::goErrorString($err)));
            throw $err;
        }
        return new TransactionProcessor(
            $reconciliation,
            $progress,
            $reconciler,
            $this->datasource,
            $conf->reconciliation->progressInterval,
            $this
        );
    }

    /**
     * processTransactions processes the transactions in batches, applying the reconciliation logic for each transaction.
     * Parameters:
     * - uploadID: The ID of the uploaded transaction file to reconcile.
     * - processor: The transaction processor handling the reconciliation logic.
     * - strategy: The reconciliation strategy to apply.
     * Returns:
     * - error: If any error occurs during processing (thrown).
     *
     * @throws \Throwable
     */
    protected function processTransactions(string $uploadID, TransactionProcessor $processor, string $strategy): void
    {
        $conf = Configuration::fetch();
        $processedCount = 0;
        // Use different transaction retrieval methods depending on the strategy.
        if ($strategy === 'many_to_one') {
            $transactionProcessor = fn (string $id, int $limit, int $offset): array => $this->getInternalTransactionsPaginated($id, $limit, $offset);
        } else {
            $transactionProcessor = fn (string $id, int $limit, int $offset): array => $this->getExternalTransactionsPaginated($id, $limit, $offset);
        }

        try {
            // Process the transactions in batches.
            $this->processTransactionInBatches(
                $uploadID,
                BigInteger::zero(),
                $conf->transaction->maxWorkers,
                false, // Stream mode is disabled.
                $transactionProcessor,
                function (iterable $txns, ?BigInteger $amount) use ($processor, &$processedCount): array {
                    $results = [];
                    foreach ($txns as $txn) {
                        try {
                            $processor->process($txn);
                        } catch (\Throwable $err) {
                            Log::get()->error(sprintf('Error processing transaction %s: %s', $txn->transactionID, self::goErrorString($err)));
                            $results[] = new BatchJobResult(null, $err);
                            return $results;
                        }
                        $processedCount++;
                        if ($processedCount % 10 === 0) {
                            Log::get()->info(sprintf('Processed %d transactions', $processedCount));
                        }
                        $results[] = new BatchJobResult();
                    }
                    return $results;
                }
            );
        } finally {
            Log::get()->info(sprintf('Total transactions processed: %d', $processedCount));
        }
    }

    /**
     * finalizeReconciliation finalizes the reconciliation process by updating its status and recording the final match/unmatch counts.
     * Parameters:
     * - reconciliation: The reconciliation object representing the current process.
     * - matchCount: The number of matched transactions.
     * - unmatchedCount: The number of unmatched transactions.
     * Returns:
     * - error: If any error occurs during finalization (thrown).
     *
     * @throws \Throwable
     */
    protected function finalizeReconciliation(Reconciliation $reconciliation, int $matchCount, int $unmatchedCount): void
    {
        // Update the reconciliation status to "completed".
        $reconciliation->status = self::StatusCompleted;
        $reconciliation->unmatchedTransactions = $unmatchedCount;
        $reconciliation->matchedTransactions = $matchCount;
        $reconciliation->completedAt = new \DateTimeImmutable('now');

        Log::get()->info(sprintf('Finalizing reconciliation. Matches: %d, Unmatched: %d', $matchCount, $unmatchedCount));

        if (!$reconciliation->isDryRun) {
            $this->postReconciliationActions($reconciliation);
        } else {
            Log::get()->info(sprintf('Dry run completed. Matches: %d, Unmatched: %d', $matchCount, $unmatchedCount));
        }

        // Update the final reconciliation status and counts in the data source.
        try {
            $this->datasource->updateReconciliationStatus($reconciliation->reconciliationID, self::StatusCompleted, $matchCount, $unmatchedCount);
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('Error updating reconciliation status: %s', self::goErrorString($err)));
            throw $err;
        }

        Log::get()->info(sprintf('Reconciliation %s completed. Total matches: %d, Total unmatched: %d', $reconciliation->reconciliationID, $matchCount, $unmatchedCount));
    }

    /**
     * oneToOneReconciliation performs a one-to-one reconciliation, where each external transaction is matched against
     * a single internal transaction. The process is parallelized using goroutines (PHP: sequential).
     * Parameters:
     * - externalTxns: The list of external transactions to be reconciled.
     * - matchingRules: The rules used to match external transactions to internal transactions.
     * Returns:
     * - []model.Match: A list of matched transactions.
     * - []string: A list of unmatched transaction IDs.
     *
     * @param Transaction[] $externalTxns
     * @param MatchingRule[] $matchingRules
     * @return array{0: ReconciliationMatch[], 1: string[]}
     */
    protected function oneToOneReconciliation(array $externalTxns, array $matchingRules): array
    {
        $conf = null;
        try {
            $conf = Configuration::fetch();
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('Error fetching configuration: %s', self::goErrorString($err)));
        }
        $maxWorkers = 10; // Default
        if ($conf !== null) {
            $maxWorkers = $conf->transaction->maxWorkers;
        }
        // $maxWorkers bounds the Go goroutine semaphore; workers run sequentially in the PHP port.
        unset($maxWorkers);

        $matches = [];
        $unmatched = [];

        $matchChan = [];     // Channel to collect matched transactions.
        $unmatchedChan = []; // Channel to collect unmatched transactions.

        // Iterate over each external transaction and attempt to match it against internal transactions.
        foreach ($externalTxns as $extTxn) {
            try {
                $this->findMatchingInternalTransaction($extTxn, $matchingRules, $matchChan, $unmatchedChan);
            } catch (\Throwable $err) {
                $unmatchedChan[] = $extTxn->transactionID;
                Log::get()->error(sprintf('No match found for external transaction %s: %s', $extTxn->transactionID, self::goErrorString($err)));
            }
        }

        // Collect matches and unmatched transactions from channels.
        foreach ($matchChan as $match) {
            $matches[] = $match;
        }
        foreach ($unmatchedChan as $unmatchedID) {
            $unmatched[] = $unmatchedID;
        }

        return [$matches, $unmatched];
    }

    /**
     * oneToManyReconciliation performs a one-to-many reconciliation, where each external transaction can match
     * multiple internal transactions grouped by specific criteria.
     * Parameters are the same as `oneToOneReconciliation`, with additional support for grouping criteria and a flag
     * to determine if the external transactions are grouped.
     * Returns:
     * - []model.Match: A list of matched transactions.
     * - []string: A list of unmatched transaction IDs.
     *
     * @param Transaction[] $externalTxns
     * @param MatchingRule[] $matchingRules
     * @return array{0: ReconciliationMatch[], 1: string[]}
     */
    protected function oneToManyReconciliation(array $externalTxns, string $groupCriteria, array $matchingRules, bool $isExternalGrouped): array
    {
        // Go logs a fetch error and then dereferences the nil config (panic); the PHP port logs and rethrows.
        try {
            $conf = Configuration::fetch();
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('Error fetching configuration: %s', self::goErrorString($err)));
            throw $err;
        }

        $matches = [];
        $unmatched = [];

        $matchChan = [];
        $unmatchedChan = [];

        // Initiate the one-to-many reconciliation process.
        try {
            $this->oneToMany($externalTxns, $matchingRules, $isExternalGrouped, $groupCriteria, $conf->transaction->batchSize, $matchChan, $unmatchedChan);
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('Error in one-to-many reconciliation: %s', self::goErrorString($err)));
        }

        foreach ($matchChan as $match) {
            $matches[] = $match;
        }
        foreach ($unmatchedChan as $unmatchedID) {
            $unmatched[] = $unmatchedID;
        }

        return [$matches, $unmatched];
    }

    /**
     * manyToOneReconciliation performs a many-to-one reconciliation, where multiple internal transactions
     * are grouped and matched against a single external transaction.
     * Similar parameters as `oneToManyReconciliation`, but operates in reverse (many internal to one external).
     * Returns:
     * - []model.Match: A list of matched transactions.
     * - []string: A list of unmatched transaction IDs.
     *
     * @param Transaction[] $internalTxns
     * @param MatchingRule[] $matchingRules
     * @return array{0: ReconciliationMatch[], 1: string[]}
     */
    protected function manyToOneReconciliation(array $internalTxns, string $uploadID, string $groupCriteria, array $matchingRules, bool $isExternalGrouped): array
    {
        // Go logs a fetch error and then dereferences the nil config (panic); the PHP port logs and rethrows.
        try {
            $conf = Configuration::fetch();
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('Error fetching configuration: %s', self::goErrorString($err)));
            throw $err;
        }

        $matches = [];
        $unmatched = [];

        $matchChan = [];
        $unmatchedChan = [];

        // Initiate the many-to-one reconciliation process.
        try {
            $this->manyToOne($internalTxns, $uploadID, $matchingRules, $isExternalGrouped, $groupCriteria, $conf->transaction->batchSize, $matchChan, $unmatchedChan);
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('Error in many-to-one reconciliation: %s', self::goErrorString($err)));
        }

        // Close channels after processing.
        foreach ($matchChan as $match) {
            $matches[] = $match;
        }
        foreach ($unmatchedChan as $unmatchedID) {
            $unmatched[] = $unmatchedID;
        }

        return [$matches, $unmatched];
    }

    /**
     * manyToOne handles the core logic of many-to-one reconciliation.
     * It groups external transactions and processes them in batches, comparing them to internal transactions.
     * Parameters:
     * - internalTxns: The internal transactions to match against.
     * - matchingRules: The rules to apply during reconciliation.
     * - isExternalGrouped: Whether the external transactions are grouped or not.
     * - groupingCriteria: The criteria used to group transactions.
     * - batchSize: The size of each batch of transactions to process.
     * - matchChan: Channel to collect matched transactions.
     * - unMatchChan: Channel to collect unmatched transactions.
     * Returns:
     * - error: If any error occurs during processing (never: errors are logged, as in Go).
     *
     * @param Transaction[] $internalTxns
     * @param MatchingRule[] $matchingRules
     * @param ReconciliationMatch[] $matchChan
     * @param string[] $unMatchChan
     */
    protected function manyToOne(array $internalTxns, string $uploadID, array $matchingRules, bool $isExternalGrouped, string $groupingCriteria, int $batchSize, array &$matchChan, array &$unMatchChan): void
    {
        $offset = 0;
        $span = Tracer::get('blnk.reconciliation')->startSpan('ProcessManyToOne');

        $processedAny = false;
        try {
            // Loop to process transactions in batches.
            while (true) {
                try {
                    $groupedExternalTxns = $this->groupExternalTransactions($uploadID, $groupingCriteria, $batchSize, $offset);
                } catch (\Throwable $err) {
                    Log::get()->error(sprintf('Error grouping external transactions: %s', self::goErrorString($err)));
                    break;
                }
                if (\count($groupedExternalTxns) === 0) {
                    self::spanEvent($span, 'No more grouped transactions to process');
                    break;
                }
                $processedAny = true;
                $groupMap = $this->buildGroupMap($groupedExternalTxns);
                try {
                    $this->processGroupedTransactions($internalTxns, $groupedExternalTxns, $groupMap, $matchingRules, $isExternalGrouped, $matchChan, $unMatchChan);
                } catch (\Throwable $err) {
                    Log::get()->error(sprintf('Error processing grouped transactions: %s', self::goErrorString($err)));
                }
                $groupMapEmpty = \count($groupMap) === 0;
                if ($groupMapEmpty) {
                    break;
                }
                $offset += $batchSize;
            }
        } finally {
            // Every input transaction must end up matched or unmatched; if the loop
            // exits before any group batch was processed, report them all unmatched
            // instead of silently dropping them from the reconciliation results.
            if (!$processedAny) {
                foreach ($internalTxns as $txn) {
                    $unMatchChan[] = $txn->transactionID;
                }
            }
            $span->end();
        }
    }

    /**
     * groupExternalTransactions retrieves and groups external transactions based on the specified criteria.
     * This is used in many-to-one reconciliation.
     * Parameters:
     * - groupingCriteria: The criteria to group transactions.
     * - batchSize: The number of transactions to process in each batch.
     * - offset: The offset for pagination.
     * Returns:
     * - map[string][]*model.Transaction: A map of grouped transactions.
     * - error: If there is an error retrieving the transactions (thrown).
     *
     * @return array<string, Transaction[]>
     */
    protected function groupExternalTransactions(string $uploadID, string $groupingCriteria, int $batchSize, int $offset): array
    {
        return $this->datasource->fetchAndGroupExternalTransactions($uploadID, $groupingCriteria, $batchSize, $offset);
    }

    /**
     * processGroupedTransactions handles the matching of transactions for both one-to-many and many-to-one reconciliations.
     * It processes each transaction group in parallel (PHP: sequentially), comparing them to the target transactions.
     * Parameters:
     * - singleTxns: The single transactions (either external or internal).
     * - groupedTxns: The grouped transactions (either internal or external).
     * - groupMap: A map indicating the groups that have not yet been matched.
     * - matchingRules: The rules to apply for matching transactions.
     * - isExternalGrouped: Whether the external transactions are grouped.
     * - matchChan: Channel for collecting matches.
     * - unMatchChan: Channel for collecting unmatched transaction IDs.
     * Returns:
     * - error: If any error occurs during processing (never, as in Go).
     *
     * @param Transaction[] $singleTxns
     * @param array<string, Transaction[]> $groupedTxns
     * @param array<string, bool> $groupMap
     * @param MatchingRule[] $matchingRules
     * @param ReconciliationMatch[] $matchChan
     * @param string[] $unMatchChan
     */
    protected function processGroupedTransactions(array $singleTxns, array $groupedTxns, array &$groupMap, array $matchingRules, bool $isExternalGrouped, array &$matchChan, array &$unMatchChan): void
    {
        $conf = null;
        try {
            $conf = Configuration::fetch();
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('Error fetching configuration: %s', self::goErrorString($err)));
        }
        $maxWorkers = 10; // Default
        if ($conf !== null) {
            $maxWorkers = $conf->transaction->maxWorkers;
        }
        // $maxWorkers bounds the Go goroutine semaphore; workers run sequentially in the PHP port.
        unset($maxWorkers);

        foreach ($singleTxns as $txn) {
            $matched = $this->matchSingleTransaction($txn, $groupedTxns, $groupMap, $matchingRules, $isExternalGrouped, $matchChan);
            if (!$matched) {
                $unMatchChan[] = $txn->transactionID;
            }
        }
    }

    /**
     * matchSingleTransaction attempts to match a single transaction with a group of transactions based on matching rules.
     * If a match is found, it sends the match to the match channel and marks the group as processed.
     * Parameters:
     * - singleTxn: The single transaction to match.
     * - groupedTxns: The grouped transactions to compare against.
     * - groupMap: A map tracking unprocessed groups.
     * - matchingRules: The rules for matching transactions.
     * - isExternalGrouped: Whether the external transactions are grouped.
     * - matchChan: Channel for sending matches.
     * Returns:
     * - bool: True if a match is found, false otherwise.
     *
     * @param array<string, Transaction[]> $groupedTxns
     * @param array<string, bool> $groupMap
     * @param MatchingRule[] $matchingRules
     * @param ReconciliationMatch[] $matchChan
     */
    protected function matchSingleTransaction(Transaction $singleTxn, array $groupedTxns, array &$groupMap, array $matchingRules, bool $isExternalGrouped, array &$matchChan): bool
    {
        // Snapshot the candidate group keys under the lock so concurrent workers
        // don't iterate the map while another deletes from it.
        $keys = array_keys($groupMap);

        foreach ($keys as $groupKey) {
            if (!$this->matchesGroup($singleTxn, $groupedTxns[$groupKey] ?? [], $matchingRules)) {
                continue;
            }
            // Claim the group atomically: only the worker that deletes it owns the match.
            if (!($groupMap[$groupKey] ?? false)) {
                continue; // already claimed by another worker
            }
            unset($groupMap[$groupKey]);

            foreach ($groupedTxns[$groupKey] ?? [] as $groupedTxn) {
                if ($isExternalGrouped) {
                    $externalID = $groupedTxn->transactionID;
                    $internalID = $singleTxn->transactionID;
                } else {
                    $externalID = $singleTxn->transactionID;
                    $internalID = $groupedTxn->transactionID;
                }
                $match = new ReconciliationMatch();
                $match->externalTransactionID = $externalID;
                $match->internalTransactionID = $internalID;
                $match->amount = $groupedTxn->amount;
                $match->date = $groupedTxn->createdAt;
                $matchChan[] = $match;
            }
            return true;
        }
        return false;
    }

    /**
     * oneToMany performs the core logic for one-to-many reconciliation.
     * Parameters are similar to `manyToOne`, but it processes transactions in reverse (one external to many internal).
     * Returns:
     * - error: If any error occurs during processing (never: errors are logged, as in Go).
     *
     * @param Transaction[] $singleTxn
     * @param MatchingRule[] $matchingRules
     * @param ReconciliationMatch[] $matchChan
     * @param string[] $unMatchChan
     */
    protected function oneToMany(array $singleTxn, array $matchingRules, bool $isExternalGrouped, string $groupingCriteria, int $batchSize, array &$matchChan, array &$unMatchChan): void
    {
        $offset = 0;
        $span = Tracer::get('blnk.reconciliation')->startSpan('ProcessOneToMany');

        $processedAny = false;
        try {
            while (true) {
                try {
                    $txns = $this->groupInternalTransactions($groupingCriteria, $batchSize, $offset);
                } catch (\Throwable $err) {
                    Log::get()->error(sprintf('Error grouping internal transactions: %s', self::goErrorString($err)));
                    break;
                }
                if (\count($txns) === 0) {
                    self::spanEvent($span, 'No more grouped transactions to process');
                    break;
                }
                $processedAny = true;
                $groupMap = $this->buildGroupMap($txns);
                try {
                    $this->processGroupedTransactions($singleTxn, $txns, $groupMap, $matchingRules, $isExternalGrouped, $matchChan, $unMatchChan);
                } catch (\Throwable $err) {
                    Log::get()->error(sprintf('Error in one-to-many reconciliation: %s', self::goErrorString($err)));
                }
                $groupMapEmpty = \count($groupMap) === 0;
                if ($groupMapEmpty) {
                    break;
                }
                $offset += $batchSize;
            }
        } finally {
            // Mirror manyToOne: inputs must never silently vanish when no group
            // batch was processed.
            if (!$processedAny) {
                foreach ($singleTxn as $txn) {
                    $unMatchChan[] = $txn->transactionID;
                }
            }
            $span->end();
        }
    }

    /**
     * buildGroupMap creates a map for tracking unprocessed transaction groups.
     * Parameters:
     * - groupedTxns: The grouped transactions.
     * Returns:
     * - map[string]bool: A map with group keys as true, indicating that they have not yet been processed.
     *
     * @param array<string, Transaction[]> $groupedTxns
     * @return array<string, bool>
     */
    protected function buildGroupMap(array $groupedTxns): array
    {
        $groupMap = [];
        foreach ($groupedTxns as $key => $_) {
            $groupMap[$key] = true;
        }
        return $groupMap;
    }

    /**
     * groupInternalTransactions groups internal transactions based on the specified criteria for reconciliation.
     * Parameters are the same as `groupExternalTransactions`.
     * Returns:
     * - map[string][]*model.Transaction: A map of grouped internal transactions.
     * - error: If any error occurs during grouping (thrown).
     *
     * @return array<string, Transaction[]>
     */
    protected function groupInternalTransactions(string $groupingCriteria, int $batchSize, int $offset): array
    {
        return $this->datasource->groupTransactions($groupingCriteria, $batchSize, $offset);
    }

    /**
     * findMatchingInternalTransaction attempts to find an internal transaction that matches the given external transaction.
     * It processes the transactions in batches and applies the matching rules.
     * Parameters:
     * - externalTxn: The external transaction to match.
     * - matchingRules: The rules for matching transactions.
     * - matchChan: Channel to send matched transactions.
     * - unMatchChan: Channel to send unmatched transaction IDs.
     * Returns:
     * - error: If any error occurs during processing (thrown).
     *
     * The Go worker returns from the job loop on the first match; the remaining
     * jobs are then drained by the other workers. The PHP worker stops iterating
     * at the first match. Go's "failed to send unmatched transaction ID to
     * channel" path (a full channel) cannot happen with the array-backed channel.
     *
     * @param MatchingRule[] $matchingRules
     * @param ReconciliationMatch[] $matchChan
     * @param string[] $unMatchChan
     * @throws \Throwable
     */
    protected function findMatchingInternalTransaction(Transaction $externalTxn, array $matchingRules, array &$matchChan, array &$unMatchChan): void
    {
        $conf = Configuration::fetch();

        $matchFound = false;

        [$minAmount, $maxAmount, $minDate, $maxDate, $currency] = $this->calculateMatchingBounds($externalTxn, $matchingRules);

        $this->processTransactionInBatches(
            $externalTxn->transactionID,
            BigInteger::of((int) $externalTxn->amount),
            $conf->transaction->maxWorkers,
            false, // Stream mode
            function (string $id, int $limit, int $offset) use ($minAmount, $maxAmount, $currency, $minDate, $maxDate): array {
                return $this->datasource->getTransactionsByCriteria($minAmount, $maxAmount, $currency, $minDate, $maxDate, $limit, $offset);
            },
            function (iterable $jobs, ?BigInteger $amount) use ($externalTxn, $matchingRules, &$matchChan, &$matchFound): array {
                foreach ($jobs as $internalTxn) {
                    if ($this->matchesRules($externalTxn, $internalTxn, $matchingRules)) {
                        $match = new ReconciliationMatch();
                        $match->externalTransactionID = $externalTxn->transactionID;
                        $match->internalTransactionID = $internalTxn->transactionID;
                        $match->amount = $externalTxn->amount;
                        $match->date = $externalTxn->createdAt;
                        $matchChan[] = $match;
                        $matchFound = true;
                        return [];
                    }
                }
                return [];
            }
        );

        if (!$matchFound) {
            $unMatchChan[] = $externalTxn->transactionID;
        }
    }

    /**
     * getExternalTransactionsPaginated retrieves paginated external transactions from the data source.
     * Parameters:
     * - uploadID: The ID of the upload to retrieve transactions for.
     * - limit: The maximum number of transactions to retrieve.
     * - offset: The offset for pagination.
     * Returns:
     * - []*model.Transaction: A list of external transactions converted to internal transactions.
     * - error: If any error occurs during retrieval (thrown).
     *
     * @return Transaction[]
     * @throws \Throwable
     */
    protected function getExternalTransactionsPaginated(string $uploadID, int $limit, int $offset): array
    {
        Log::get()->info(sprintf('Fetching external transactions: uploadID=%s, limit=%d, offset=%d', $uploadID, $limit, $offset));
        try {
            $externalTransaction = $this->datasource->getExternalTransactionsPaginated($uploadID, $limit, $offset);
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('Error fetching external transactions: %s', self::goErrorString($err)));
            throw $err;
        }
        Log::get()->info(sprintf('Fetched %d external transactions', \count($externalTransaction)));
        $transactions = [];

        foreach ($externalTransaction as $txn) {
            $transactions[] = $txn->toInternalTransaction();
        }
        return $transactions;
    }

    /**
     * getInternalTransactionsPaginated retrieves paginated internal transactions from the data source.
     * Parameters:
     * - id: The ID of the internal transactions to retrieve.
     * - limit: The maximum number of transactions to retrieve.
     * - offset: The offset for pagination.
     * Returns:
     * - []*model.Transaction: A list of internal transactions.
     * - error: If any error occurs during retrieval (thrown).
     *
     * @return Transaction[]
     */
    protected function getInternalTransactionsPaginated(string $id, int $limit, int $offset): array
    {
        return $this->datasource->getTransactionsPaginated('', $limit, $offset);
    }

    /**
     * matchesGroup compares a group of internal transactions with a single external transaction using matching rules.
     * If a match is found, it returns true; otherwise, it returns false.
     * Parameters:
     * - externalTxn: The external transaction to compare.
     * - group: The group of internal transactions to compare against.
     * - matchingRules: The rules for matching transactions.
     * Returns:
     * - bool: True if the group matches the external transaction, false otherwise.
     *
     * @param Transaction[] $group
     * @param MatchingRule[] $matchingRules
     */
    protected function matchesGroup(Transaction $externalTxn, array $group, array $matchingRules): bool
    {
        $totalAmount = 0.0;
        $minDate = null; // Go zero time
        $maxDate = null;
        $descriptions = [];
        $references = [];
        $currencies = [];

        // Iterate over the group of internal transactions and accumulate information.
        $i = 0;
        foreach ($group as $internalTxn) {
            $totalAmount += $internalTxn->amount;

            $createdAt = self::goTimeOrZero($internalTxn->createdAt);
            if ($i === 0 || $createdAt < self::goTimeOrZero($minDate)) {
                $minDate = $createdAt;
            }
            if ($i === 0 || $createdAt > self::goTimeOrZero($maxDate)) {
                $maxDate = $createdAt;
            }

            $descriptions[] = $internalTxn->description;
            $references[] = $internalTxn->reference;
            $currencies[$internalTxn->currency] = true;
            $i++;
        }

        // Create a virtual transaction representing the group for comparison.
        $groupTxn = new Transaction();
        $groupTxn->amount = $totalAmount;
        $groupTxn->createdAt = $minDate; // Use the earliest date in the group.
        $groupTxn->description = implode(' | ', $descriptions);
        $groupTxn->reference = implode(' | ', $references);
        $groupTxn->currency = $this->dominantCurrency($currencies); // Determine the dominant currency in the group.

        // Use the matching rules to compare the group with the external transaction.
        return $this->matchesRules($externalTxn, $groupTxn, $matchingRules);
    }

    /**
     * dominantCurrency returns the dominant currency in a group of transactions.
     * If there is only one currency, it returns that currency, otherwise, it returns "MIXED".
     *
     * @param array<string, bool> $currencies
     */
    protected function dominantCurrency(array $currencies): string
    {
        if (\count($currencies) === 1) {
            foreach ($currencies as $currency => $_) {
                return (string) $currency;
            }
        }
        return 'MIXED';
    }

    /**
     * CreateMatchingRule creates a new matching rule after validating it.
     * Parameters:
     * - rule: The matching rule to be created.
     * Returns the created rule, or an error if validation or storage fails (thrown).
     *
     * (Go receives the rule by value; the PHP port clones it so the caller's
     * object is left untouched.)
     *
     * @throws \RuntimeException on validation failure
     * @throws \Blnk\Internal\ApiError\ApiErrorException on storage failure
     */
    public function createMatchingRule(MatchingRule $rule): MatchingRule
    {
        $rule = clone $rule;
        $rule->ruleID = ModelHelpers::generateUUIDWithSuffix('rule'); // Generate a unique rule ID.
        $rule->createdAt = new \DateTimeImmutable('now');
        $rule->updatedAt = new \DateTimeImmutable('now');

        // Validate the rule before storing it.
        $this->validateRule($rule);

        // Store the rule in the datasource.
        $this->datasource->recordMatchingRule($rule);

        return $rule;
    }

    /**
     * GetMatchingRule retrieves a matching rule by its ID.
     * Parameters:
     * - id: The ID of the matching rule to retrieve.
     * Returns the matching rule, or an error if retrieval fails (thrown).
     *
     * @throws \Blnk\Internal\ApiError\ApiErrorException
     */
    public function getMatchingRule(string $id): MatchingRule
    {
        return $this->datasource->getMatchingRule($id);
    }

    /**
     * UpdateMatchingRule updates an existing matching rule.
     * It retrieves the existing rule, validates the new data, and then updates the rule.
     * Parameters:
     * - rule: The updated rule data.
     * Returns the updated rule, or an error if validation or update fails (thrown).
     *
     * @throws \RuntimeException on validation failure
     * @throws \Blnk\Internal\ApiError\ApiErrorException on retrieval/update failure
     */
    public function updateMatchingRule(MatchingRule $rule): MatchingRule
    {
        $rule = clone $rule;
        // Retrieve the existing rule by its ID.
        $existingRule = $this->getMatchingRule($rule->ruleID);

        // Preserve the original creation time and update the modified time.
        $rule->createdAt = $existingRule->createdAt;
        $rule->updatedAt = new \DateTimeImmutable('now');

        // Validate the updated rule.
        $this->validateRule($rule);

        // Update the rule in the datasource.
        $this->datasource->updateMatchingRule($rule);

        return $rule;
    }

    /**
     * DeleteMatchingRule deletes a matching rule by its ID.
     * Parameters:
     * - id: The ID of the rule to delete.
     * Returns an error if deletion fails (thrown).
     *
     * @throws \Blnk\Internal\ApiError\ApiErrorException
     */
    public function deleteMatchingRule(string $id): void
    {
        $this->datasource->deleteMatchingRule($id);
    }

    /**
     * ListMatchingRules retrieves all matching rules.
     * Returns a list of matching rules, or an error if retrieval fails (thrown).
     *
     * @return MatchingRule[]
     * @throws \Blnk\Internal\ApiError\ApiErrorException
     */
    public function listMatchingRules(): array
    {
        return $this->datasource->getMatchingRules();
    }

    /**
     * validateRule validates a matching rule, including checking its basic structure and criteria.
     * Parameters:
     * - rule: The rule to validate.
     * Returns an error if the rule or any of its criteria are invalid (thrown).
     *
     * @throws \RuntimeException
     */
    protected function validateRule(MatchingRule $rule): void
    {
        // Validate basic structure of the rule.
        $this->validateRuleBasics($rule);

        // Validate each individual criterion.
        foreach ($rule->criteria ?? [] as $criteria) {
            $this->validateCriteria($criteria);
        }
    }

    /**
     * validateRuleBasics checks that the rule has a valid name and at least one criterion.
     * Parameters:
     * - rule: The rule to validate.
     * Returns an error if the rule is missing required fields (thrown).
     *
     * @throws \RuntimeException "rule name is required" | "at least one matching criteria is required"
     */
    protected function validateRuleBasics(MatchingRule $rule): void
    {
        if ($rule->name === '') {
            throw new \RuntimeException('rule name is required');
        }

        if (\count($rule->criteria ?? []) === 0) {
            throw new \RuntimeException('at least one matching criteria is required');
        }
    }

    /**
     * validateCriteria checks the validity of a criterion, including its field, operator, and drift values.
     * Parameters:
     * - criteria: The criteria to validate.
     * Returns an error if the criteria are invalid (thrown).
     *
     * @throws \RuntimeException
     */
    protected function validateCriteria(MatchingCriteria $criteria): void
    {
        if ($criteria->field === '' || $criteria->operator === '') {
            throw new \RuntimeException('field and operator are required for each criteria');
        }

        $this->validateOperator($criteria->operator);

        $this->validateField($criteria->field);

        $this->validateDrift($criteria);
    }

    /**
     * validateOperator checks if the provided operator is valid.
     * Parameters:
     * - operator: The operator to validate.
     * Returns an error if the operator is invalid (thrown).
     *
     * @throws \RuntimeException "invalid operator"
     */
    protected function validateOperator(string $operator): void
    {
        $validOperators = ['equals', 'greater_than', 'less_than', 'contains'];
        if (!self::contains($validOperators, $operator)) {
            throw new \RuntimeException('invalid operator');
        }
    }

    /**
     * validateField checks if the provided field is valid for a matching rule.
     * Parameters:
     * - field: The field to validate.
     * Returns an error if the field is invalid (thrown).
     *
     * @throws \RuntimeException "invalid field"
     */
    protected function validateField(string $field): void
    {
        $validFields = ['amount', 'date', 'description', 'reference', 'currency'];
        if (!self::contains($validFields, $field)) {
            throw new \RuntimeException('invalid field');
        }
    }

    /**
     * validateDrift checks the allowable drift for the given field and operator.
     * Drift refers to acceptable deviations (e.g., percentage for amounts or seconds for dates).
     * Parameters:
     * - criteria: The criteria to validate.
     * Returns an error if the drift is invalid (thrown).
     *
     * @throws \RuntimeException
     */
    protected function validateDrift(MatchingCriteria $criteria): void
    {
        if ($criteria->operator === 'equals') {
            switch ($criteria->field) {
                case 'amount':
                    // Amount drift is a fraction of the amount: 0.01 allows 1%
                    // deviation, 1 allows 100%.
                    if ($criteria->allowableDrift < 0 || $criteria->allowableDrift > 1) {
                        throw new \RuntimeException('drift for amount must be between 0 and 1 (fraction, e.g. 0.01 = 1%)');
                    }
                    break;
                case 'date':
                    if ($criteria->allowableDrift < 0) {
                        throw new \RuntimeException('drift for date must be non-negative (seconds)');
                    }
                    break;
            }
        }
    }

    /**
     * getMatchingRules retrieves a list of matching rules by their IDs.
     * Parameters:
     * - matchingRuleIDs: The list of matching rule IDs to retrieve.
     * Returns a list of matching rules, or an error if retrieval fails (thrown).
     *
     * @param string[] $matchingRuleIDs
     * @return MatchingRule[]
     * @throws \Blnk\Internal\ApiError\ApiErrorException
     */
    protected function getMatchingRules(array $matchingRuleIDs): array
    {
        $rules = [];
        foreach ($matchingRuleIDs as $id) {
            $rule = $this->getMatchingRule($id);
            $rules[] = $rule;
        }
        return $rules;
    }

    /**
     * matchesString compares the external and internal string values based on the matching criteria.
     * Parameters:
     * - externalValue: The value from the external transaction.
     * - internalValue: The value from the internal transaction.
     * - criteria: The matching criteria, including operator and allowable drift.
     * Returns true if the values match according to the criteria, otherwise false.
     */
    protected function matchesString(string $externalValue, string $internalValue, MatchingCriteria $criteria): bool
    {
        switch ($criteria->operator) {
            case 'equals':
                // Check if any part of the internal value matches the external value exactly.
                foreach (explode(' | ', $internalValue) as $part) {
                    if (self::equalFold($externalValue, $part)) {
                        return true;
                    }
                }
                return false;
            case 'contains':
                // Check if the external value is contained in any part of the internal value.
                foreach (explode(' | ', $internalValue) as $part) {
                    if ($this->partialMatch($externalValue, $part, $criteria->allowableDrift)) {
                        return true;
                    }
                }
                return false;
        }
        return false;
    }

    /**
     * partialMatch compares two strings and checks if they match within a certain allowable drift, using Levenshtein distance.
     * Parameters:
     * - str1, str2: The strings to compare.
     * - allowableDrift: The allowable difference between the two strings (as a percentage).
     * Returns true if the strings match within the allowable drift, otherwise false.
     *
     * Mirrors Go exactly: the distance is computed over runes (Unicode code
     * points, like `levenshtein.DistanceForStrings([]rune(...))`), while the
     * maximum length uses Go's byte length `len(str)`.
     */
    protected function partialMatch(string $str1, string $str2, float $allowableDrift): bool
    {
        $str1 = mb_strtolower($str1); // Convert to lowercase for case-insensitive comparison.
        $str2 = mb_strtolower($str2);

        // Check if either string contains the other.
        if (str_contains($str1, $str2) || str_contains($str2, $str1)) {
            return true;
        }

        // Calculate the Levenshtein distance between the strings.
        $distance = self::levenshteinDistanceForStrings(mb_str_split($str1), mb_str_split($str2));

        // Calculate the maximum allowable distance based on the length of the longer string.
        $maxLength = (float) max(\strlen($str1), \strlen($str2));
        $maxAllowedDistance = (int) ($maxLength * ($allowableDrift / 100));

        // Return true if the distance is within the allowable drift.
        return $distance <= $maxAllowedDistance;
    }

    /**
     * matchesCurrency compares currency values, handling special cases like "MIXED" for group transactions.
     * Parameters:
     * - externalValue: The currency value from the external transaction.
     * - internalValue: The currency value from the internal transaction.
     * - criteria: The matching criteria.
     * Returns true if the currencies match, otherwise false.
     */
    protected function matchesCurrency(string $externalValue, string $internalValue, MatchingCriteria $criteria): bool
    {
        if ($internalValue === 'MIXED') {
            // TODO: Handle the special case where the internal value is "MIXED" (multiple currencies in a group).
            return true;
        }
        return $this->matchesString($externalValue, $internalValue, $criteria);
    }

    /**
     * matchesGroupAmount compares the amount of a single external transaction with the total amount of a grouped set of internal transactions.
     * Parameters:
     * - externalAmount: The amount from the external transaction.
     * - groupAmount: The total amount from the group of internal transactions.
     * - criteria: The matching criteria, including operator and allowable drift.
     * Returns true if the amounts match according to the criteria, otherwise false.
     */
    protected function matchesGroupAmount(float $externalAmount, float $groupAmount, MatchingCriteria $criteria): bool
    {
        switch ($criteria->operator) {
            case 'equals':
                $allowableDrift = abs($groupAmount) * $criteria->allowableDrift;
                return abs($externalAmount - $groupAmount) <= $allowableDrift;
            case 'greater_than':
                return $externalAmount > $groupAmount;
            case 'less_than':
                return $externalAmount < $groupAmount;
        }
        return false;
    }

    /**
     * matchesGroupDate compares the date of a single external transaction with the earliest date in a group of internal transactions.
     * Parameters:
     * - externalDate: The date from the external transaction.
     * - groupEarliestDate: The earliest date from the group of internal transactions.
     * - criteria: The matching criteria, including operator and allowable drift.
     * Returns true if the dates match according to the criteria, otherwise false.
     */
    protected function matchesGroupDate(?\DateTimeImmutable $externalDate, ?\DateTimeImmutable $groupEarliestDate, MatchingCriteria $criteria): bool
    {
        $external = self::goTimeOrZero($externalDate);
        $groupEarliest = self::goTimeOrZero($groupEarliestDate);
        switch ($criteria->operator) {
            case 'equals':
                $difference = self::secondsBetween($external, $groupEarliest);
                return abs($difference) <= $criteria->allowableDrift;
            case 'after':
            case 'greater_than':
                return $external > $groupEarliest;
            case 'before':
            case 'less_than':
                return $external < $groupEarliest;
        }
        return false;
    }

    /**
     * calculateMatchingBounds calculates the query bounds (amount, date) for matching external transactions against internal ones.
     * It iterates through matching rules to find the widest possible range that satisfies all criteria.
     *
     * Go returns `(*float64, *float64, *time.Time, *time.Time, *string)`; the
     * PHP port returns `[$minAmount, $maxAmount, $minDate, $maxDate, $currency]`
     * with null standing for a nil pointer.
     *
     * @param MatchingRule[] $matchingRules
     * @return array{0: ?float, 1: ?float, 2: ?\DateTimeImmutable, 3: ?\DateTimeImmutable, 4: ?string}
     */
    protected function calculateMatchingBounds(Transaction $externalTxn, array $matchingRules): array
    {
        $minAmount = null;
        $maxAmount = null;
        $minDate = null;
        $maxDate = null;

        $currency = $externalTxn->currency;

        $lowestMinAmount = 0.0;
        $highestMaxAmount = 0.0;
        $earliestMinDate = self::goTimeOrZero(null);
        $latestMaxDate = self::goTimeOrZero(null);

        $firstAmount = true;
        $firstDate = true;
        $hasAmountCriteria = false;
        $hasDateCriteria = false;

        foreach ($matchingRules as $rule) {
            foreach ($rule->criteria ?? [] as $criteria) {
                if ($criteria->field === 'amount' && $criteria->operator === 'equals') {
                    $drift = $criteria->allowableDrift; // fraction: 0.01 = 1%
                    $amt = $externalTxn->amount;
                    $delta = abs($amt) * $drift;
                    $low = $amt - $delta;
                    $high = $amt + $delta;

                    if ($firstAmount) {
                        $lowestMinAmount = $low;
                        $highestMaxAmount = $high;
                        $firstAmount = false;
                    } else {
                        if ($low < $lowestMinAmount) {
                            $lowestMinAmount = $low;
                        }
                        if ($high > $highestMaxAmount) {
                            $highestMaxAmount = $high;
                        }
                    }
                    $hasAmountCriteria = true;
                }
                if ($criteria->field === 'date' && $criteria->operator === 'equals') {
                    // Go: time.Duration(criteria.AllowableDrift) * time.Second — the
                    // float64 → Duration conversion truncates toward zero, so the
                    // drift is whole seconds.
                    $driftSeconds = (int) $criteria->allowableDrift;
                    $d = self::goTimeOrZero($externalTxn->createdAt);
                    $low = $d->modify(sprintf('%+d seconds', -$driftSeconds));
                    $high = $d->modify(sprintf('%+d seconds', $driftSeconds));

                    if ($firstDate) {
                        $earliestMinDate = $low;
                        $latestMaxDate = $high;
                        $firstDate = false;
                    } else {
                        if ($low < $earliestMinDate) {
                            $earliestMinDate = $low;
                        }
                        if ($high > $latestMaxDate) {
                            $latestMaxDate = $high;
                        }
                    }
                    $hasDateCriteria = true;
                }
            }
        }

        if ($hasAmountCriteria) {
            $minAmount = $lowestMinAmount;
            $maxAmount = $highestMaxAmount;
        }
        if ($hasDateCriteria) {
            $minDate = $earliestMinDate;
            $maxDate = $latestMaxDate;
        }

        return [$minAmount, $maxAmount, $minDate, $maxDate, $currency];
    }

    // ------------------------------------------------------------------
    // Porting helpers (Go standard-library / third-party calls without a
    // direct PHP equivalent).
    // ------------------------------------------------------------------

    /**
     * equalFold is the analogue of Go's `strings.EqualFold`: equality under
     * simple Unicode case folding.
     */
    private static function equalFold(string $a, string $b): bool
    {
        return mb_convert_case($a, MB_CASE_FOLD_SIMPLE, 'UTF-8') === mb_convert_case($b, MB_CASE_FOLD_SIMPLE, 'UTF-8');
    }

    /**
     * secondsBetween is the analogue of Go's `a.Sub(b).Seconds()` (a float
     * number of seconds, microsecond resolution in PHP).
     */
    private static function secondsBetween(\DateTimeImmutable $a, \DateTimeImmutable $b): float
    {
        $seconds = $a->getTimestamp() - $b->getTimestamp();
        $micros = (int) $a->format('u') - (int) $b->format('u');
        return $seconds + $micros / 1_000_000;
    }

    /**
     * levenshteinDistanceForStrings is the port of
     * `levenshtein.DistanceForStrings(source, target, levenshtein.DefaultOptionsWithSub)`
     * (texttheater/golang-levenshtein): insertion, deletion and substitution
     * all cost 1, computed over code points. PHP's built-in `levenshtein()`
     * is byte-based, hence this rune-based implementation.
     *
     * @param string[] $source runes of the source string
     * @param string[] $target runes of the target string
     */
    private static function levenshteinDistanceForStrings(array $source, array $target): int
    {
        $sourceLen = \count($source);
        $targetLen = \count($target);
        if ($sourceLen === 0) {
            return $targetLen;
        }
        if ($targetLen === 0) {
            return $sourceLen;
        }

        $previous = range(0, $targetLen);
        for ($i = 1; $i <= $sourceLen; $i++) {
            $current = [$i];
            for ($j = 1; $j <= $targetLen; $j++) {
                $substitution = $previous[$j - 1] + ($source[$i - 1] === $target[$j - 1] ? 0 : 1);
                $insertion = $current[$j - 1] + 1;
                $deletion = $previous[$j] + 1;
                $current[$j] = min($substitution, $insertion, $deletion);
            }
            $previous = $current;
        }

        return $previous[$targetLen];
    }
}
