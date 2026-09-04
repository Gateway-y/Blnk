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

namespace Blnk\Database;

use Blnk\Internal\ApiError\ErrorCode;
use Blnk\Internal\Log;
use Blnk\Internal\Traces\Tracer;
use Blnk\Model\ModelHelpers;
use Blnk\Model\Transaction;

/**
 * Port of Go `database/transaction_recovery.go`: the stuck-QUEUED sweep of
 * the `transaction` concern of {@see Datasource} (composed as a trait).
 *
 * The trailing doc comment of the Go file ("GetQueuedTransactionsForCoalescing
 * retrieves QUEUED transactions for the exact same pair ...") documents a
 * method that lives in transaction_coalescing.go and is carried by
 * {@see TransactionCoalescingRepository}.
 */
trait TransactionRecoveryRepository
{
    /**
     * GetStuckQueuedTransactions retrieves QUEUED transactions older than the
     * threshold that never got a child transaction.
     *
     * @param int|float $threshold Go `time.Duration`, in seconds: the cutoff is `now (UTC) - threshold`.
     * @return Transaction[]
     * @throws \Blnk\Internal\ApiError\ApiErrorException
     * @throws \RuntimeException "failed to parse precise_amount: ..." (Go: fmt.Errorf wrapping)
     */
    public function getStuckQueuedTransactions(int|float $threshold, int $batchSize): array
    {
        $span = Tracer::get('transaction.database')->startSpan('GetStuckQueuedTransactions');
        try {
            // cutoff := time.Now().UTC().Add(-threshold)
            $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify(sprintf('%+d microseconds', -(int) round($threshold * 1_000_000)));

            try {
                $stmt = $this->conn->prepare(<<<'SQL'
                    SELECT transaction_id, parent_transaction, source, reference, amount, precise_amount, precision, currency, destination, description, status, created_at, meta_data, scheduled_for, hash
                    FROM blnk.transactions t
                    WHERE t.status = 'QUEUED'
                      AND t.created_at < ?
                      AND NOT EXISTS (
                          SELECT 1 FROM blnk.transactions child
                          WHERE child.parent_transaction = t.transaction_id
                      )
                    ORDER BY t.created_at ASC
                    LIMIT ?
                    SQL);
                $stmt->execute([TransactionRowMapper::formatTimestamp($cutoff), $batchSize]);
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve stuck queued transactions', $err);
            }

            $transactions = [];
            try {
                while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    $txn = TransactionRowMapper::scanTransaction($row);

                    // A single row with malformed metadata (e.g. a legacy array-shaped
                    // meta_data) must not abort the whole recovery sweep; skip it and keep
                    // recovering the rest.
                    try {
                        $txn->metaData = TransactionRowMapper::unmarshalMetaData($row['meta_data']);
                    } catch (\JsonException $err) {
                        Log::get()->warning(sprintf('skipping stuck transaction %s: unparseable metadata: %s', $txn->transactionID, $err->getMessage()));
                        continue;
                    }

                    try {
                        $txn->preciseAmount = TransactionRowMapper::parseBigInt((string) $row['precise_amount']);
                    } catch (\RuntimeException $err) {
                        $span->recordError($err);
                        throw new \RuntimeException('failed to parse precise_amount: ' . $err->getMessage(), 0, $err);
                    }

                    ModelHelpers::applyPrecision($txn);

                    $transactions[] = $txn;
                }
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Error iterating stuck queued transactions', $err);
            }

            $span->setAttribute('Stuck queued transactions retrieved', [
                'transaction.count' => \count($transactions),
            ]);
            return $transactions;
        } finally {
            $span->end();
        }
    }
}
