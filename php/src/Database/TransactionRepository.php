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
use Blnk\Internal\Traces\Tracer;
use Blnk\Model\Balance;
use Blnk\Model\LineageOutbox;
use Blnk\Model\ModelHelpers;
use Blnk\Model\Transaction;

/**
 * Port of Go `database/transaction.go`: the transaction-recording half of the
 * `transaction` concern of {@see Datasource} (composed as a trait, see
 * PORTING.md / Datasource.php).
 *
 * Standalone Go helpers of the file (`utcOrNil`, `recordTransactionInTx`,
 * `recordTransactionsInTx`) are private methods here. The balance helpers
 * `updateBalance` / `updateBalanceSet` (balance.go) and the outbox helper
 * `insertLineageOutboxesInTx` (lineage.go) are reached through the sibling
 * traits composed into the same class:
 * - `updateBalance(ctx, tx, balance)`      → `$this->updateBalanceInTx(\PDO $tx, Balance $balance)`
 *   (renamed in the PHP port because the exported `Datasource.UpdateBalance`
 *   already claims `updateBalance`),
 * - `updateBalanceSet(ctx, tx, balances)`  → `$this->updateBalanceSet(\PDO $tx, array $balances)`,
 * - `insertLineageOutboxesInTx(ctx, tx, o)` → `$this->insertLineageOutboxesInTx(\PDO $tx, array $outboxes)`.
 *
 * Go's `d.Conn.BeginTx(...)` / `tx.Commit()` / deferred `tx.Rollback()` map
 * onto `$this->conn->beginTransaction()` / `commit()` /
 * {@see TransactionRowMapper::rollback()}; the `*sql.Tx` handed to the helpers
 * is the very same PDO connection while its transaction is open.
 *
 * The trailing doc comment of the Go file ("GetTransaction retrieves a
 * transaction by its ID ...") documents a method that lives in
 * transaction_queries.go; it is carried by {@see TransactionQueriesRepository::getTransaction()}.
 */
trait TransactionRepository
{
    /**
     * utcOrNil normalizes an optional timestamp to UTC so the naive value stored
     * in timestamp-without-time-zone columns is timezone-independent.
     */
    private static function utcOrNil(?\DateTimeImmutable $t): ?\DateTimeImmutable
    {
        if ($t === null) {
            return null;
        }
        return $t->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * transactionRowValues builds, in column order, the sixteen values every
     * insert path of this file binds (`transaction_id, parent_transaction,
     * source, reference, amount, precise_amount, precision, currency,
     * destination, description, status, created_at, meta_data, scheduled_for,
     * hash, effective_date`), exactly as the Go argument lists do:
     * `txn.AmountString`, `txn.PreciseAmount.String()`, `txn.Precision`
     * (float64, sent in Go's shortest float notation), `txn.CreatedAt.UTC()`,
     * `txn.ScheduledFor.UTC()` and `utcOrNil(txn.EffectiveDate)`.
     *
     * Go dereferences `txn.PreciseAmount` (a nil pointer panics); the port
     * throws a \TypeError in that case.
     *
     * @return array<int, string|null>
     */
    private static function transactionRowValues(Transaction $txn, string $metaDataJSON): array
    {
        if ($txn->preciseAmount === null) {
            throw new \TypeError('transaction precise amount is not set');
        }

        return [
            $txn->transactionID,
            $txn->parentTransaction,
            $txn->source,
            $txn->reference,
            $txn->amountString,
            (string) $txn->preciseAmount,
            ModelHelpers::goFloatString($txn->precision),
            $txn->currency,
            $txn->destination,
            $txn->description,
            $txn->status,
            TransactionRowMapper::formatTimestamp($txn->createdAt),
            $metaDataJSON,
            TransactionRowMapper::formatTimestamp($txn->scheduledFor),
            $txn->hash,
            TransactionRowMapper::formatOptionalTimestamp(self::utcOrNil($txn->effectiveDate)),
        ];
    }

    /**
     * RecordTransaction persists a transaction.
     *
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to marshal metadata" / "Failed to record transaction"
     */
    public function recordTransaction(Transaction $txn): Transaction
    {
        // Start a new tracing span for the database operation
        $span = Tracer::get('transaction.database')->startSpan('PersistTransaction');
        try {
            // Marshal transaction metadata into JSON format
            try {
                $metaDataJSON = TransactionRowMapper::marshalMetaData($txn->metaData);
            } catch (\JsonException $err) {
                $span->recordError($err); // Record the error in the tracing span
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to marshal metadata', $err);
            }

            // Execute the SQL insert statement to record the transaction
            try {
                $stmt = $this->conn->prepare(
                    'INSERT INTO blnk.transactions(transaction_id, parent_transaction, source, reference, amount, precise_amount, precision, currency, destination, description, status, created_at, meta_data, scheduled_for, hash, effective_date)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute(self::transactionRowValues($txn, $metaDataJSON));
            } catch (\PDOException $err) {
                // Handle errors that may occur during the execution of the query
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to record transaction', $err);
            }

            // Log the successful transaction recording as an event in the tracing span
            $span->setAttribute('Transaction recorded', [
                'transaction.id' => $txn->transactionID,
                'transaction.reference' => $txn->reference,
            ]);

            return $txn;
        } finally {
            $span->end();
        }
    }

    /**
     * recordTransactionInTx inserts a transaction record within an existing database transaction.
     * This is a helper function used by RecordTransactionWithBalances for atomic operations.
     *
     * @param \PDO $tx The connection whose `beginTransaction()` is currently open (Go: `*sql.Tx`).
     * @throws \Blnk\Internal\ApiError\ApiErrorException
     */
    private function recordTransactionInTx(\PDO $tx, Transaction $txn): void
    {
        $span = Tracer::get('transaction.database')->startSpan('recordTransactionInTx');
        try {
            try {
                $metaDataJSON = TransactionRowMapper::marshalMetaData($txn->metaData);
            } catch (\JsonException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to marshal metadata', $err);
            }

            try {
                $stmt = $tx->prepare(
                    'INSERT INTO blnk.transactions(transaction_id, parent_transaction, source, reference, amount, precise_amount, precision, currency, destination, description, status, created_at, meta_data, scheduled_for, hash, effective_date)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute(self::transactionRowValues($txn, $metaDataJSON));
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to record transaction', $err);
            }

            $span->setAttribute('Transaction recorded in transaction', [
                'transaction.id' => $txn->transactionID,
            ]);
        } finally {
            $span->end();
        }
    }

    /**
     * recordTransactionsInTx inserts multiple transaction records within an existing database
     * transaction using PostgreSQL's COPY protocol to reduce SQL parsing and roundtrips.
     *
     * Go streams the rows through `pq.CopyInSchema("blnk", "transactions", ...)`
     * (prepare → Exec per row → flushing Exec() → Close). pdo_pgsql exposes the
     * same COPY FROM STDIN protocol as a single call
     * (`Pdo\Pgsql::copyFromArray` / legacy `PDO::pgsqlCopyFromArray`), so the
     * rows are encoded up front ({@see TransactionRowMapper::copyLine()}) and
     * any server-side failure — which lib/pq reports from the flushing
     * `Exec()` — surfaces as "Failed to flush transaction copy".
     *
     * @param \PDO $tx The connection whose `beginTransaction()` is currently open (Go: `*sql.Tx`).
     * @param Transaction[] $txns
     * @throws \Blnk\Internal\ApiError\ApiErrorException
     */
    private function recordTransactionsInTx(\PDO $tx, array $txns): void
    {
        $span = Tracer::get('transaction.database')->startSpan('recordTransactionsInTx');
        try {
            if (\count($txns) === 0) {
                return;
            }

            // pq.CopyInSchema("blnk", "transactions", <columns>)
            $columns = [
                'transaction_id',
                'parent_transaction',
                'source',
                'reference',
                'amount',
                'precise_amount',
                'precision',
                'currency',
                'destination',
                'description',
                'status',
                'created_at',
                'meta_data',
                'scheduled_for',
                'hash',
                'effective_date',
            ];

            $rows = [];
            foreach ($txns as $txn) {
                try {
                    $metaDataJSON = TransactionRowMapper::marshalMetaData($txn->metaData);
                } catch (\JsonException $err) {
                    $span->recordError($err);
                    throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to marshal metadata', $err);
                }

                $rows[] = TransactionRowMapper::copyLine(self::transactionRowValues($txn, $metaDataJSON));
            }

            try {
                if ($tx instanceof \Pdo\Pgsql) {
                    $ok = $tx->copyFromArray('blnk.transactions', $rows, "\t", '\\\\N', implode(',', $columns));
                } else {
                    // Driver-specific method of the pdo_pgsql extension on a plain \PDO handle.
                    /** @phpstan-ignore-next-line */
                    $ok = $tx->pgsqlCopyFromArray('blnk.transactions', $rows, "\t", '\\\\N', implode(',', $columns));
                }
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to flush transaction copy', $err);
            }
            if ($ok !== true) {
                $errorInfo = $tx->errorInfo();
                $err = new \RuntimeException((string) ($errorInfo[2] ?? 'COPY FROM STDIN failed'));
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to flush transaction copy', $err);
            }

            $span->setAttribute('Transactions recorded in transaction', [
                'transaction.count' => \count($txns),
            ]);
        } finally {
            $span->end();
        }
    }

    /**
     * RecordTransactionWithBalances atomically records a transaction and updates both source and destination balances
     * within a single database transaction. This ensures that either all operations succeed together,
     * or none of them are committed, preventing inconsistent ledger states.
     *
     * Parameters:
     * - txn: The transaction object containing details to be recorded.
     * - sourceBalance: The source balance to be updated.
     * - destinationBalance: The destination balance to be updated.
     *
     * Returns:
     * - The recorded transaction if successful, or throws if any operation fails.
     *
     * @throws \Blnk\Internal\ApiError\ApiErrorException
     */
    public function recordTransactionWithBalances(Transaction $txn, Balance $sourceBalance, Balance $destinationBalance): Transaction
    {
        $span = Tracer::get('transaction.database')->startSpan('RecordTransactionWithBalances');
        try {
            try {
                $this->conn->beginTransaction();
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to begin transaction', $err);
            }
            $tx = $this->conn;

            try {
                $this->updateBalanceInTx($tx, $sourceBalance);

                $this->updateBalanceInTx($tx, $destinationBalance);

                $this->recordTransactionInTx($tx, $txn);

                try {
                    $tx->commit();
                } catch (\PDOException $err) {
                    throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to commit transaction', $err);
                }
            } catch (\Throwable $err) {
                $span->recordError($err);
                TransactionRowMapper::rollback($tx); // defer tx.Rollback()
                throw $err;
            }

            $span->setAttribute('Transaction and balances recorded atomically', [
                'transaction.id' => $txn->transactionID,
                'source.balance_id' => $sourceBalance->balanceID,
                'destination.balance_id' => $destinationBalance->balanceID,
            ]);

            return $txn;
        } finally {
            $span->end();
        }
    }

    /**
     * RecordTransactionWithBalancesAndOutbox atomically records a transaction, updates balances,
     * and optionally inserts a lineage outbox entry within a single database transaction.
     * This ensures that the lineage processing intent is captured atomically with the main transaction,
     * guaranteeing no lineage work is lost even if subsequent async operations fail.
     *
     * Parameters:
     * - txn: The transaction object containing details to be recorded.
     * - sourceBalance: The source balance to be updated.
     * - destinationBalance: The destination balance to be updated.
     * - outbox: Optional lineage outbox entry to insert atomically (can be null if no lineage processing needed).
     *
     * Returns:
     * - The recorded transaction if successful, or throws if any operation fails.
     *
     * @throws \Blnk\Internal\ApiError\ApiErrorException
     * @throws \RuntimeException "failed to insert lineage outbox: ..." (Go: fmt.Errorf wrapping)
     */
    public function recordTransactionWithBalancesAndOutbox(Transaction $txn, Balance $sourceBalance, Balance $destinationBalance, ?LineageOutbox $outbox): Transaction
    {
        $span = Tracer::get('transaction.database')->startSpan('RecordTransactionWithBalancesAndOutbox');
        try {
            try {
                $this->conn->beginTransaction();
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to begin transaction', $err);
            }
            $tx = $this->conn;

            try {
                $this->updateBalanceInTx($tx, $sourceBalance);

                $this->updateBalanceInTx($tx, $destinationBalance);

                $this->recordTransactionInTx($tx, $txn);

                // Insert lineage outbox entry atomically if provided
                if ($outbox !== null) {
                    try {
                        $this->insertLineageOutboxInTx($tx, $outbox);
                    } catch (\Throwable $err) {
                        throw new \RuntimeException('failed to insert lineage outbox: ' . TransactionRowMapper::errorString($err), 0, $err);
                    }
                    $span->setAttribute('Lineage outbox entry inserted', [
                        'outbox.transaction_id' => $outbox->transactionID,
                        'outbox.lineage_type' => $outbox->lineageType,
                    ]);
                }

                try {
                    $tx->commit();
                } catch (\PDOException $err) {
                    throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to commit transaction', $err);
                }
            } catch (\Throwable $err) {
                $span->recordError($err);
                TransactionRowMapper::rollback($tx); // defer tx.Rollback()
                throw $err;
            }

            $span->setAttribute('Transaction, balances, and outbox recorded atomically', [
                'transaction.id' => $txn->transactionID,
                'source.balance_id' => $sourceBalance->balanceID,
                'destination.balance_id' => $destinationBalance->balanceID,
                'outbox.included' => $outbox !== null,
            ]);

            return $txn;
        } finally {
            $span->end();
        }
    }

    /**
     * RecordTransactionsWithBalancesAndOutboxes atomically records multiple transactions, updates
     * the source and destination balances once, and inserts any lineage outbox entries in the same
     * database transaction.
     *
     * @param Transaction[] $txns
     * @param (LineageOutbox|null)[] $outboxes
     * @return Transaction[]
     * @throws \Blnk\Internal\ApiError\ApiErrorException
     */
    public function recordTransactionsWithBalancesAndOutboxes(array $txns, ?Balance $sourceBalance, ?Balance $destinationBalance, array $outboxes): array
    {
        return $this->recordTransactionsWithBalanceSetAndOutboxes($txns, [$sourceBalance, $destinationBalance], $outboxes);
    }

    /**
     * RecordTransactionsWithBalanceSetAndOutboxes atomically records multiple transactions, updates
     * all changed balances, and inserts any lineage outbox entries in the same database transaction.
     *
     * @param Transaction[] $txns
     * @param (Balance|null)[] $balances nil / empty-ID entries are skipped by updateBalanceSet
     * @param (LineageOutbox|null)[] $outboxes nil entries are skipped by insertLineageOutboxesInTx
     * @return Transaction[]
     * @throws \Blnk\Internal\ApiError\ApiErrorException
     * @throws \RuntimeException "failed to insert lineage outboxes: ..." (Go: fmt.Errorf wrapping)
     */
    public function recordTransactionsWithBalanceSetAndOutboxes(array $txns, array $balances, array $outboxes): array
    {
        // (The Go span keeps the name of the two-balance variant.)
        $span = Tracer::get('transaction.database')->startSpan('RecordTransactionsWithBalancesAndOutboxes');
        try {
            try {
                $this->conn->beginTransaction();
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to begin transaction', $err);
            }
            $tx = $this->conn;

            try {
                $this->updateBalanceSet($tx, $balances);

                $this->recordTransactionsInTx($tx, $txns);

                try {
                    $this->insertLineageOutboxesInTx($tx, $outboxes);
                } catch (\Throwable $err) {
                    throw new \RuntimeException('failed to insert lineage outboxes: ' . TransactionRowMapper::errorString($err), 0, $err);
                }

                try {
                    $tx->commit();
                } catch (\PDOException $err) {
                    throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to commit transaction', $err);
                }
            } catch (\Throwable $err) {
                $span->recordError($err);
                TransactionRowMapper::rollback($tx); // defer tx.Rollback()
                throw $err;
            }

            $span->setAttribute('Transactions, balances, and outboxes recorded atomically', [
                'transaction.count' => \count($txns),
                'balance.count' => \count($balances),
                'outbox.count' => \count($outboxes),
            ]);

            return $txns;
        } finally {
            $span->end();
        }
    }
}
