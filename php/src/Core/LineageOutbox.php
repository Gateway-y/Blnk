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
use Blnk\Model\Balance;
use Blnk\Model\LineageOutbox as LineageOutboxModel;
use Blnk\Model\Transaction;

/**
 * LineageOutbox is the port of the root-package file `lineage_outbox.go`:
 * preparation of the atomic lineage outbox entry and the deferred processing
 * of claimed entries by the outbox worker.
 *
 * (The trait shares its name with the model `Blnk\Model\LineageOutbox`, which
 * is therefore imported here as `LineageOutboxModel`.) The package-level
 * `tracer` maps to `Tracer::get('blnk.transactions')`.
 */
trait LineageOutbox
{
    /**
     * PrepareLineageOutbox creates a LineageOutbox entry for atomic insertion with the transaction.
     * This ensures lineage processing intent is captured in the same database transaction,
     * guaranteeing no lineage work is lost even if subsequent async operations fail.
     *
     * Parameters:
     * - txn *model.Transaction: The transaction being processed.
     * - sourceBalance *model.Balance: The source balance (may be nil).
     * - destinationBalance *model.Balance: The destination balance (may be nil).
     *
     * Returns:
     * - *model.LineageOutbox: The outbox entry to insert, or nil if no lineage processing needed.
     */
    public function prepareLineageOutbox(Transaction $txn, ?Balance $sourceBalance, ?Balance $destinationBalance): ?LineageOutboxModel
    {
        $span = Tracer::get('blnk.transactions')->startSpan('PrepareLineageOutbox');
        try {
            $provider = $this->getLineageProvider($txn);

            // Determine what type of lineage processing is needed
            $needsCredit = $provider !== '' && $destinationBalance !== null && $destinationBalance->trackFundLineage;
            $needsDebit = $sourceBalance !== null && $sourceBalance->trackFundLineage;

            if (!$needsCredit && !$needsDebit) {
                self::spanEvent($span, 'No lineage processing needed');
                return null;
            }

            $lineageType = 'both';
            if ($needsCredit && !$needsDebit) {
                $lineageType = 'credit';
            } elseif ($needsDebit && !$needsCredit) {
                $lineageType = 'debit';
            }

            // Build the payload with transaction data needed for processing
            $payload = new LineageOutboxPayload();
            $payload->amount = $txn->amount;
            // Go: txn.PreciseAmount.String() — a nil *big.Int prints as "<nil>".
            $payload->preciseAmount = $txn->preciseAmount === null ? '<nil>' : (string) $txn->preciseAmount;
            $payload->currency = $txn->currency;
            $payload->precision = $txn->precision;
            $payload->reference = $txn->reference;
            $payload->skipQueue = true;
            $payload->inflight = $txn->inflight;
            try {
                $payloadBytes = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (\JsonException $err) {
                Log::get()->error(sprintf('failed to marshal lineage outbox payload: %s', $err->getMessage()));
                $span->recordError($err);
                return null;
            }

            $srcID = '';
            $dstID = '';
            if ($sourceBalance !== null) {
                $srcID = $sourceBalance->balanceID;
            }
            if ($destinationBalance !== null) {
                $dstID = $destinationBalance->balanceID;
            }

            $outbox = new LineageOutboxModel();
            $outbox->transactionID = $txn->transactionID;
            $outbox->sourceBalanceID = $srcID;
            $outbox->destinationBalanceID = $dstID;
            $outbox->provider = $provider;
            $outbox->lineageType = $lineageType;
            $outbox->payload = $payloadBytes;
            $outbox->maxAttempts = 5;
            $outbox->inflight = $txn->inflight; // explicit column, don't rely on JSON payload

            self::spanEvent($span, 'Lineage outbox entry prepared', [
                'transaction_id' => $txn->transactionID,
                'lineage_type' => $lineageType,
                'provider' => $provider,
            ]);

            return $outbox;
        } finally {
            $span->end();
        }
    }

    /**
     * ProcessLineageFromOutbox processes a lineage outbox entry.
     * This is called by the outbox worker to perform deferred lineage processing.
     *
     * Parameters:
     * - entry model.LineageOutbox: The outbox entry to process.
     *
     * Returns:
     * - error: An error if processing fails (thrown).
     *
     * @throws \Throwable
     */
    public function processLineageFromOutbox(LineageOutboxModel $entry): void
    {
        $span = Tracer::get('blnk.transactions')->startSpan('ProcessLineageFromOutbox');
        try {
            $span->setAttribute('outbox.id', sprintf('%d', $entry->id));
            $span->setAttribute('outbox.transaction_id', $entry->transactionID);
            $span->setAttribute('outbox.lineage_type', $entry->lineageType);

            // Handle shadow commit/void operations
            switch ($entry->lineageType) {
                case LineageOutboxModel::LineageTypeShadowCommit:
                case LineageOutboxModel::LineageTypeShadowVoid:
                    // Extract parent transaction ID from payload
                    // (Go: json.Unmarshal(entry.Payload, &struct{ ParentTransactionID string `json:"parent_transaction_id"` }))
                    try {
                        $payload = json_decode($entry->payload ?? '', true, 512, JSON_THROW_ON_ERROR);
                    } catch (\JsonException $err) {
                        throw self::wrapError('failed to unmarshal shadow work payload', $err);
                    }
                    $parentTxnID = '';
                    if ($payload !== null) {
                        if (!\is_array($payload)) {
                            throw new \RuntimeException(sprintf('failed to unmarshal shadow work payload: json: cannot unmarshal %s into Go value of type struct', get_debug_type($payload)));
                        }
                        $rawParentID = $payload['parent_transaction_id'] ?? null;
                        if ($rawParentID !== null && !\is_string($rawParentID)) {
                            throw new \RuntimeException(sprintf('failed to unmarshal shadow work payload: json: cannot unmarshal %s into Go struct field .parent_transaction_id of type string', get_debug_type($rawParentID)));
                        }
                        $parentTxnID = $rawParentID ?? '';
                    }
                    if ($parentTxnID === '') {
                        throw new \RuntimeException('parent_transaction_id missing in shadow work payload');
                    }

                    if ($entry->lineageType === LineageOutboxModel::LineageTypeShadowCommit) {
                        self::spanEvent($span, 'Processing shadow commit from outbox', [
                            'parent.transaction_id' => $parentTxnID,
                        ]);
                        try {
                            $this->commitShadowTransactions($parentTxnID, null);
                        } catch (\Throwable $err) {
                            throw self::wrapError('failed to commit shadow transactions', $err);
                        }
                        self::spanEvent($span, 'Shadow commit completed from outbox');
                    } else {
                        self::spanEvent($span, 'Processing shadow void from outbox', [
                            'parent.transaction_id' => $parentTxnID,
                        ]);
                        try {
                            $this->voidShadowTransactions($parentTxnID);
                        } catch (\Throwable $err) {
                            throw self::wrapError('failed to void shadow transactions', $err);
                        }
                        self::spanEvent($span, 'Shadow void completed from outbox');
                    }
                    return;
            }

            // Handle regular lineage processing (credit, debit, both)
            // Fetch the transaction
            try {
                $txn = $this->getTransaction($entry->transactionID);
            } catch (\Throwable $err) {
                throw self::wrapError('failed to get transaction', $err);
            }

            $txn->inflight = $entry->inflight;

            // Fetch balances
            $sourceBalance = null;
            $destinationBalance = null;
            if ($entry->sourceBalanceID !== '') {
                try {
                    $sourceBalance = $this->datasource->getBalanceByIDLite($entry->sourceBalanceID);
                } catch (\Throwable $err) {
                    Log::get()->warning(sprintf('failed to get source balance %s for lineage processing: %s', $entry->sourceBalanceID, self::goErrorString($err)));
                }
            }
            if ($entry->destinationBalanceID !== '') {
                try {
                    $destinationBalance = $this->datasource->getBalanceByIDLite($entry->destinationBalanceID);
                } catch (\Throwable $err) {
                    Log::get()->warning(sprintf('failed to get destination balance %s for lineage processing: %s', $entry->destinationBalanceID, self::goErrorString($err)));
                }
            }

            $this->processLineage($txn, $sourceBalance, $destinationBalance);

            self::spanEvent($span, 'Lineage processing completed from outbox');
        } finally {
            $span->end();
        }
    }
}
