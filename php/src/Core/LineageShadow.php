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
use Blnk\Internal\Traces\Tracer;
use Blnk\Model\LineageOutbox as LineageOutboxModel;
use Brick\Math\BigInteger;

/**
 * LineageShadow is the port of the root-package file `lineage_shadow.go`:
 * commit / void of the inflight shadow transactions of a parent transaction,
 * with outbox-backed retry.
 *
 * The package-level `tracer` maps to `Tracer::get('blnk.transactions')`.
 */
trait LineageShadow
{
    /**
     * commitShadowTransactions commits all inflight shadow transactions for a parent transaction.
     * It attempts to commit all shadows and returns an error if any fail (for outbox retry).
     * Already-committed shadows return "already committed" error which is treated as success.
     *
     * Parameters:
     * - parentTransactionID string: The parent transaction ID.
     * - amount *big.Int: The amount to commit (unused, shadow transactions use their own amounts).
     *
     * Returns:
     * - error: An error if any shadow transaction failed to commit (excluding already-committed) (thrown).
     *
     * @throws \Throwable
     */
    protected function commitShadowTransactions(string $parentTransactionID, ?BigInteger $amount): void
    {
        $span = Tracer::get('blnk.transactions')->startSpan('CommitShadowTransactions');
        try {
            try {
                $shadowTxns = $this->datasource->getTransactionsByShadowFor($parentTransactionID);
            } catch (\Throwable $err) {
                throw self::wrapError('failed to get shadow transactions', $err);
            }

            $failedShadows = [];
            foreach ($shadowTxns as $shadow) {
                try {
                    $this->commitInflightTransaction($shadow->transactionID, $shadow->preciseAmount);
                } catch (\Throwable $err) {
                    $errMsg = self::goErrorString($err);
                    if (str_contains($errMsg, 'already committed')
                        || str_contains($errMsg, 'not in inflight status')) {
                        self::spanEvent($span, 'Shadow transaction already processed, skipping', [
                            'shadow.id' => $shadow->transactionID,
                        ]);
                        continue;
                    }
                    Log::get()->error(sprintf('failed to commit shadow transaction %s: %s', $shadow->transactionID, $errMsg));
                    $failedShadows[] = $shadow->transactionID;
                    continue;
                }
                self::spanEvent($span, 'Shadow transaction committed', [
                    'shadow.id' => $shadow->transactionID,
                    'parent.id' => $parentTransactionID,
                ]);
            }

            if (\count($failedShadows) > 0) {
                // Go: fmt.Errorf("...: %v", failedShadows) renders the slice as "[id1 id2]".
                throw new \RuntimeException(sprintf('failed to commit %d shadow transactions: [%s]', \count($failedShadows), implode(' ', $failedShadows)));
            }
        } finally {
            $span->end();
        }
    }

    /**
     * voidShadowTransactions voids all inflight shadow transactions for a parent transaction.
     * It attempts to void all shadows and returns an error if any fail (for outbox retry).
     * Already-voided/committed shadows return "already committed" error which is treated as success.
     *
     * Parameters:
     * - parentTransactionID string: The parent transaction ID.
     *
     * Returns:
     * - error: An error if any shadow transaction failed to void (excluding already-processed) (thrown).
     *
     * @throws \Throwable
     */
    protected function voidShadowTransactions(string $parentTransactionID): void
    {
        $span = Tracer::get('blnk.transactions')->startSpan('VoidShadowTransactions');
        try {
            try {
                $shadowTxns = $this->datasource->getTransactionsByShadowFor($parentTransactionID);
            } catch (\Throwable $err) {
                throw self::wrapError('failed to get shadow transactions', $err);
            }

            $failedShadows = [];
            foreach ($shadowTxns as $shadow) {
                try {
                    $this->voidInflightTransaction($shadow->transactionID);
                } catch (\Throwable $err) {
                    $errMsg = self::goErrorString($err);
                    if (str_contains($errMsg, 'already committed')
                        || str_contains($errMsg, 'not in inflight status')) {
                        self::spanEvent($span, 'Shadow transaction already processed, skipping', [
                            'shadow.id' => $shadow->transactionID,
                        ]);
                        continue;
                    }
                    Log::get()->error(sprintf('failed to void shadow transaction %s: %s', $shadow->transactionID, $errMsg));
                    $failedShadows[] = $shadow->transactionID;
                    continue;
                }
                self::spanEvent($span, 'Shadow transaction voided', [
                    'shadow.id' => $shadow->transactionID,
                    'parent.id' => $parentTransactionID,
                ]);
            }

            if (\count($failedShadows) > 0) {
                throw new \RuntimeException(sprintf('failed to void %d shadow transactions: [%s]', \count($failedShadows), implode(' ', $failedShadows)));
            }
        } finally {
            $span->end();
        }
    }

    /**
     * queueShadowWork processes shadow commit or void work synchronously first, and queues
     * to outbox for retry only if there are failures. This provides both immediate processing
     * and guaranteed delivery for failed operations.
     *
     * Parameters:
     * - parentTransactionID string: The parent transaction ID whose shadows need processing.
     * - lineageType string: Either LineageTypeShadowCommit or LineageTypeShadowVoid.
     *
     * Returns:
     * - error: An error if all processing attempts failed (thrown: the original processing error).
     *
     * @throws \Throwable
     */
    protected function queueShadowWork(string $parentTransactionID, string $lineageType): void
    {
        $span = Tracer::get('blnk.transactions')->startSpan('QueueShadowWork');
        try {
            $processingErr = null;

            // Try to process shadows synchronously first
            switch ($lineageType) {
                case LineageOutboxModel::LineageTypeShadowCommit:
                    try {
                        $this->commitShadowTransactions($parentTransactionID, null);
                    } catch (\Throwable $err) {
                        $processingErr = $err;
                    }
                    break;
                case LineageOutboxModel::LineageTypeShadowVoid:
                    try {
                        $this->voidShadowTransactions($parentTransactionID);
                    } catch (\Throwable $err) {
                        $processingErr = $err;
                    }
                    break;
            }

            // If synchronous processing succeeded, we're done
            if ($processingErr === null) {
                self::spanEvent($span, 'Shadow work processed synchronously', [
                    'parent.id' => $parentTransactionID,
                    'lineage.type' => $lineageType,
                ]);
                return;
            }

            // Synchronous processing failed - queue to outbox for retry
            Log::get()->warning(sprintf('Shadow %s failed for %s, queueing for retry: %s', $lineageType, $parentTransactionID, self::goErrorString($processingErr)));

            // Create outbox entry for shadow work retry
            // Use a distinct ID to avoid conflict with regular lineage entries for same transaction
            $shadowWorkID = sprintf('%s_%s', $parentTransactionID, $lineageType);
            $outbox = new LineageOutboxModel();
            $outbox->transactionID = $shadowWorkID;
            $outbox->lineageType = $lineageType;
            $outbox->payload = sprintf('{"parent_transaction_id":"%s"}', $parentTransactionID);
            $outbox->maxAttempts = 5;

            try {
                $this->datasource->insertLineageOutbox($outbox);
            } catch (\Throwable $err) {
                $span->recordError($err);
                // Log but don't fail - the original error is more important
                Log::get()->error(sprintf('failed to queue shadow work for retry: %s', self::goErrorString($err)));
                throw $processingErr;
            }

            self::spanEvent($span, 'Shadow work queued for retry via outbox', [
                'parent.id' => $parentTransactionID,
                'lineage.type' => $lineageType,
                'original.error' => self::goErrorString($processingErr),
            ]);

            // Return original error since processing failed
            throw $processingErr;
        } finally {
            $span->end();
        }
    }
}
