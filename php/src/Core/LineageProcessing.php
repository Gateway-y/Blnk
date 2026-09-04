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
use Blnk\Internal\Notification\Notification;
use Blnk\Internal\Traces\Tracer;
use Blnk\Model\Balance;
use Blnk\Model\Transaction;

/**
 * LineageProcessing is the port of the root-package file `lineage_processing.go`:
 * the lineage entry point that validates the provider and dispatches credit and
 * debit processing.
 *
 * The package-level `tracer` maps to `Tracer::get('blnk.transactions')`.
 */
trait LineageProcessing
{
    /**
     * processLineage tracks fund lineage for a transaction. A benign provider
     * mismatch is not an error; database/processing failures are returned so the
     * outbox worker retries the entry.
     *
     * processLineage handles fund lineage tracking for a transaction.
     * It processes both credit (incoming funds with provider tracking) and debit (fund allocation from shadow balances).
     *
     * Parameters:
     * - txn *model.Transaction: The transaction being processed.
     * - sourceBalance *model.Balance: The source balance for the transaction.
     * - destinationBalance *model.Balance: The destination balance for the transaction.
     *
     * @throws \Throwable
     */
    protected function processLineage(Transaction $txn, ?Balance $sourceBalance, ?Balance $destinationBalance): void
    {
        $span = Tracer::get('blnk.transactions')->startSpan('ProcessLineage');
        try {
            $provider = $this->getLineageProvider($txn);

            try {
                $validatedProvider = $this->validateLineageProvider($provider, $sourceBalance);
            } catch (\Throwable $err) {
                $span->recordError($err);
                Notification::notifyError($err);
                throw self::wrapError('lineage provider validation failed', $err);
            }

            if ($provider !== '' && $validatedProvider === '' && $sourceBalance !== null) {
                self::spanEvent($span, 'Provider validation failed', [
                    'requested_provider' => $provider,
                    'source_balance_id' => $sourceBalance->balanceID,
                ]);
            }

            // Credit must run before debit: debit allocates from the shadow balances credit creates.
            if ($validatedProvider !== '' && $destinationBalance !== null && $destinationBalance->trackFundLineage) {
                try {
                    $this->processLineageCredit($txn, $destinationBalance, $validatedProvider);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    Notification::notifyError($err);
                    throw self::wrapError('lineage credit processing failed', $err);
                }
            }

            // Debit processing doesn't require a provider - it allocates from existing shadow balances
            if ($sourceBalance !== null && $sourceBalance->trackFundLineage) {
                try {
                    $this->processLineageDebit($txn, $sourceBalance, $destinationBalance);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    Notification::notifyError($err);
                    throw self::wrapError('lineage debit processing failed', $err);
                }
            }

            self::spanEvent($span, 'Lineage processing completed');
        } finally {
            $span->end();
        }
    }

    /**
     * getLineageProvider extracts the fund provider from the transaction metadata.
     *
     * Parameters:
     * - txn *model.Transaction: The transaction to extract the provider from.
     *
     * Returns:
     * - string: The provider identifier, or empty string if not set.
     */
    protected function getLineageProvider(Transaction $txn): string
    {
        if ($txn->metaData === null) {
            return '';
        }
        $provider = $txn->metaData[self::LineageProviderKey] ?? null;
        if (!\is_string($provider)) {
            return '';
        }
        return $provider;
    }

    /**
     * validateLineageProvider checks if the specified provider exists on the source balance.
     * If the source tracks fund lineage but doesn't have the specified provider,
     * returns empty string (provider should be ignored).
     * If the source doesn't track lineage (e.g., @world), any provider is valid.
     *
     * Parameters:
     * - provider string: The provider specified in transaction metadata.
     * - sourceBalance *model.Balance: The source balance (may be nil or not track lineage).
     *
     * Returns:
     * - string: The validated provider name (empty if invalid/should be ignored).
     * - error: An error if validation fails (database errors only, not for invalid providers) (thrown).
     *
     * @throws \Throwable "failed to validate provider on source: ..."
     */
    protected function validateLineageProvider(string $provider, ?Balance $sourceBalance): string
    {
        if ($provider === '') {
            return '';
        }

        // Source is nil or doesn't track lineage - provider is valid
        if ($sourceBalance === null || !$sourceBalance->trackFundLineage) {
            return $provider;
        }

        // Source tracks lineage - verify provider exists
        try {
            $mapping = $this->datasource->getLineageMappingByProvider($sourceBalance->balanceID, $provider);
        } catch (\Throwable $err) {
            throw self::wrapError('failed to validate provider on source', $err);
        }

        if ($mapping === null) {
            // Go: %q — a double-quoted, escaped string.
            Log::get()->warning(sprintf(
                'lineage provider validation: provider %s does not exist on source balance %s - ignoring provider',
                json_encode($provider, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $sourceBalance->balanceID
            ));
            return '';
        }

        return $provider;
    }
}
