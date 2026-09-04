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
use Blnk\Model\LineageMapping;
use Blnk\Model\LineageOutbox;

/**
 * Port of Go `database/lineage.go`: the `lineage` concern of {@see Datasource}
 * (composed as a trait, see PORTING.md / Datasource.php) — lineage mappings
 * and the lineage outbox.
 *
 * - The standalone Go helper `insertLineageOutboxesInTx(ctx, tx, outboxes)` is
 *   the private method of the same name below; {@see TransactionRepository}
 *   reaches it as `$this->insertLineageOutboxesInTx(\PDO $tx, array $outboxes)`.
 * - Row scanning, `time.Duration.String()` and the nanosecond-offset
 *   timestamps live in {@see LineageRowMapper}.
 * - `*sql.Tx` parameters are the PDO connection whose `beginTransaction()` is
 *   currently open (PDO carries the transaction on the connection itself).
 * - `time.Now()` is bound as {@see PqEncoder::now()} (UTC).
 * - Outbox status constants are {@see LineageOutbox}::OutboxStatus* (Go:
 *   `model.OutboxStatusPending` etc.).
 * - `result.RowsAffected()` cannot fail with PDO (`rowCount()`), so the Go
 *   "Failed to get rows affected" branch has no counterpart.
 */
trait LineageRepository
{
    /**
     * UpsertLineageMapping creates or updates a lineage mapping between a main balance and its shadow balances.
     * If a mapping already exists for the same balance_id and provider, it updates the existing record.
     *
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Invalid balance or identity ID" (BAD_REQUEST, foreign_key_violation) / "Database error occurred" (other server errors) / "Failed to upsert lineage mapping"
     */
    public function upsertLineageMapping(LineageMapping $mapping): void
    {
        try {
            PgStatement::execute($this->conn, <<<'SQL'
                INSERT INTO blnk.lineage_mappings (balance_id, provider, shadow_balance_id, aggregate_balance_id, identity_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?)
                ON CONFLICT (balance_id, provider) DO UPDATE SET
                    shadow_balance_id = EXCLUDED.shadow_balance_id,
                    aggregate_balance_id = EXCLUDED.aggregate_balance_id
                SQL, [
                $mapping->balanceID,
                $mapping->provider,
                $mapping->shadowBalanceID,
                $mapping->aggregateBalanceID,
                $mapping->identityID,
                PqEncoder::time(PqEncoder::now()),
            ]);
        } catch (\PDOException $err) {
            if (PgStatement::isServerError($err)) { // pqErr, ok := err.(*pq.Error)
                switch (PgStatement::conditionName($err)) {
                    case 'foreign_key_violation':
                        throw TransactionRowMapper::apiError(ErrorCode::ErrBadRequest, 'Invalid balance or identity ID', $err);
                    default:
                        throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Database error occurred', $err);
                }
            }
            throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to upsert lineage mapping', $err);
        }
    }

    /**
     * GetLineageMappings retrieves all lineage mappings for a given balance ID.
     *
     * @return LineageMapping[]
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to retrieve lineage mappings" / "Failed to scan lineage mapping" / "Error occurred while iterating over lineage mappings"
     */
    public function getLineageMappings(string $balanceID): array
    {
        try {
            $stmt = PgStatement::execute($this->conn, <<<'SQL'
                SELECT id, balance_id, provider, shadow_balance_id, aggregate_balance_id, identity_id, created_at
                FROM blnk.lineage_mappings
                WHERE balance_id = ?
                ORDER BY created_at ASC
                SQL, [$balanceID]);
        } catch (\PDOException $err) {
            throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve lineage mappings', $err);
        }

        $mappings = [];
        try {
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                try {
                    $mapping = LineageRowMapper::scanLineageMapping($row);
                } catch (\InvalidArgumentException $err) {
                    throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to scan lineage mapping', $err);
                }
                $mappings[] = $mapping;
            }
        } catch (\PDOException $err) {
            throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Error occurred while iterating over lineage mappings', $err);
        }

        return $mappings;
    }

    /**
     * GetLineageMappingByProvider retrieves a specific lineage mapping for a balance and provider.
     *
     * @return LineageMapping|null null when no mapping exists (Go: `nil, nil` on sql.ErrNoRows).
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to retrieve lineage mapping"
     */
    public function getLineageMappingByProvider(string $balanceID, string $provider): ?LineageMapping
    {
        try {
            $stmt = PgStatement::execute($this->conn, <<<'SQL'
                SELECT id, balance_id, provider, shadow_balance_id, aggregate_balance_id, identity_id, created_at
                FROM blnk.lineage_mappings
                WHERE balance_id = ? AND provider = ?
                SQL, [$balanceID, $provider]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $err) {
            throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve lineage mapping', $err);
        }

        if ($row === false) {
            return null; // sql.ErrNoRows → nil, nil
        }

        try {
            return LineageRowMapper::scanLineageMapping($row);
        } catch (\InvalidArgumentException $err) {
            throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve lineage mapping', $err);
        }
    }

    /**
     * DeleteLineageMapping deletes a lineage mapping by its ID.
     *
     * @throws NotFoundException "Lineage mapping not found" (no row deleted)
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to delete lineage mapping"
     */
    public function deleteLineageMapping(int $id): void
    {
        try {
            $stmt = PgStatement::execute($this->conn, <<<'SQL'
                DELETE FROM blnk.lineage_mappings WHERE id = ?
                SQL, [$id]);
        } catch (\PDOException $err) {
            throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to delete lineage mapping', $err);
        }

        $rowsAffected = $stmt->rowCount();

        if ($rowsAffected === 0) {
            throw TransactionRowMapper::apiError(ErrorCode::ErrNotFound, 'Lineage mapping not found', null);
        }
    }

    /**
     * InsertLineageOutboxInTx inserts a lineage outbox entry within an existing database transaction.
     * This ensures the outbox entry is committed atomically with the main transaction.
     *
     * The generated `id` (RETURNING id) is written back into `$outbox->id`.
     *
     * @param \PDO $tx The connection whose `beginTransaction()` is currently open (Go: `*sql.Tx`).
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to insert lineage outbox entry"
     */
    public function insertLineageOutboxInTx(\PDO $tx, LineageOutbox $outbox): void
    {
        $query = <<<'SQL'
            INSERT INTO blnk.lineage_outbox
            (transaction_id, source_balance_id, destination_balance_id, provider, lineage_type, payload, status, max_attempts, created_at, inflight)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
            SQL;
        try {
            $stmt = PgStatement::execute($tx, $query, [
                $outbox->transactionID,
                $outbox->sourceBalanceID,
                $outbox->destinationBalanceID,
                $outbox->provider,
                $outbox->lineageType,
                $outbox->payload,
                LineageOutbox::OutboxStatusPending,
                $outbox->maxAttempts,
                PqEncoder::time(PqEncoder::now()),
                $outbox->inflight,
            ]);
            $id = $stmt->fetchColumn();
        } catch (\PDOException $err) {
            throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to insert lineage outbox entry', $err);
        }
        if ($id === false) {
            throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to insert lineage outbox entry', TransactionRowMapper::ERR_NO_ROWS);
        }
        $outbox->id = RowScanner::toInt($id);
    }

    /**
     * insertLineageOutboxesInTx inserts several lineage outbox entries with a single
     * multi-row INSERT inside an existing database transaction, writing the generated
     * ids back into the entries (matched by transaction ID). Nil entries are skipped;
     * each row's `created_at` is `now + i nanoseconds` (i = position in the input) so
     * the entries keep their relative order.
     *
     * Go: `insertLineageOutboxesInTx(ctx context.Context, tx *sql.Tx, outboxes []*model.LineageOutbox) error`.
     *
     * @param \PDO $tx The connection whose `beginTransaction()` is currently open (Go: `*sql.Tx`).
     * @param (LineageOutbox|null)[] $outboxes
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to insert lineage outbox entries" / "Failed to scan inserted lineage outbox entry" / "Failed while iterating inserted lineage outbox entries" / "Failed to insert all lineage outbox entries"
     */
    private function insertLineageOutboxesInTx(\PDO $tx, array $outboxes): void
    {
        if (\count($outboxes) === 0) {
            return;
        }

        $query = <<<'SQL'
            INSERT INTO blnk.lineage_outbox
            (transaction_id, source_balance_id, destination_balance_id, provider, lineage_type, payload, status, max_attempts, created_at, inflight)
            VALUES

            SQL;

        $args = [];
        $now = PqEncoder::now();
        foreach (array_values($outboxes) as $i => $outbox) {
            if ($outbox === null) {
                continue;
            }
            if (\count($args) > 0) {
                $query .= ',';
            }
            // Go: fmt.Sprintf("($%d,$%d,...,$%d)", base, base+1, ..., base+9) with base = len(args)+1
            $query .= '(?,?,?,?,?,?,?,?,?,?)';
            array_push(
                $args,
                $outbox->transactionID,
                $outbox->sourceBalanceID,
                $outbox->destinationBalanceID,
                $outbox->provider,
                $outbox->lineageType,
                $outbox->payload,
                LineageOutbox::OutboxStatusPending,
                $outbox->maxAttempts,
                LineageRowMapper::timestampWithNanos($now, $i), // now.Add(time.Duration(i)*time.Nanosecond)
                $outbox->inflight,
            );
        }

        if (\count($args) === 0) {
            return;
        }

        $query .= ' RETURNING id, transaction_id';
        try {
            $rows = PgStatement::execute($tx, $query, $args);
        } catch (\PDOException $err) {
            throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to insert lineage outbox entries', $err);
        }

        /** @var array<string, LineageOutbox> $outboxesByTransactionID */
        $outboxesByTransactionID = [];
        foreach ($outboxes as $outbox) {
            if ($outbox === null) {
                continue;
            }
            $outboxesByTransactionID[$outbox->transactionID] = $outbox;
        }

        $inserted = 0;
        try {
            while (($row = $rows->fetch(\PDO::FETCH_ASSOC)) !== false) {
                if (!\array_key_exists('id', $row) || !\array_key_exists('transaction_id', $row)) {
                    $err = new \RuntimeException('sql: expected 2 destination arguments in Scan, not ' . \count($row));
                    throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to scan inserted lineage outbox entry', $err);
                }
                $id = RowScanner::toInt($row['id']);
                $transactionID = RowScanner::toString($row['transaction_id']);
                if (isset($outboxesByTransactionID[$transactionID])) {
                    $outboxesByTransactionID[$transactionID]->id = $id;
                }
                $inserted++;
            }
        } catch (\PDOException $err) {
            throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed while iterating inserted lineage outbox entries', $err);
        }

        if ($inserted !== \count($outboxesByTransactionID)) {
            throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to insert all lineage outbox entries', null);
        }
    }

    /**
     * InsertLineageOutbox inserts a lineage outbox entry directly (not within a transaction).
     * Use this for queueing work like shadow commit/void that happens after the main transaction commits.
     *
     * The generated `id` (RETURNING id) is written back into `$outbox->id`.
     *
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to insert lineage outbox entry"
     */
    public function insertLineageOutbox(LineageOutbox $outbox): void
    {
        $query = <<<'SQL'
            INSERT INTO blnk.lineage_outbox
            (transaction_id, source_balance_id, destination_balance_id, provider, lineage_type, payload, status, max_attempts, created_at, inflight)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
            SQL;
        try {
            $stmt = PgStatement::execute($this->conn, $query, [
                $outbox->transactionID,
                $outbox->sourceBalanceID,
                $outbox->destinationBalanceID,
                $outbox->provider,
                $outbox->lineageType,
                $outbox->payload,
                LineageOutbox::OutboxStatusPending,
                $outbox->maxAttempts,
                PqEncoder::time(PqEncoder::now()),
                $outbox->inflight,
            ]);
            $id = $stmt->fetchColumn();
        } catch (\PDOException $err) {
            throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to insert lineage outbox entry', $err);
        }
        if ($id === false) {
            throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to insert lineage outbox entry', TransactionRowMapper::ERR_NO_ROWS);
        }
        $outbox->id = RowScanner::toInt($id);
    }

    /**
     * ClaimPendingOutboxEntries claims a batch of pending outbox entries for processing.
     * It uses SELECT FOR UPDATE SKIP LOCKED to allow concurrent processors.
     *
     * @param int|float $lockDuration Go `time.Duration`, expressed in seconds; bound as
     *   `lockDuration.String()` (e.g. "5m0s"), which PostgreSQL parses as an interval.
     * @return LineageOutbox[]
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to claim pending outbox entries" / "Failed to scan outbox entry" / "Error iterating over outbox entries"
     */
    public function claimPendingOutboxEntries(int $batchSize, int|float $lockDuration): array
    {
        // UPDATE ... RETURNING does not preserve the inner ORDER BY, so the
        // claimed rows are re-ordered through a CTE to guarantee FIFO delivery
        // within the batch.
        $query = <<<'SQL'
            WITH claimed AS (
                UPDATE blnk.lineage_outbox
                SET status = ?, locked_until = NOW() + ?::interval
                WHERE id IN (
                    SELECT id FROM blnk.lineage_outbox
                    WHERE status IN ('pending', 'processing')
                      AND (locked_until IS NULL OR locked_until < NOW())
                      AND attempts < max_attempts
                    ORDER BY created_at ASC
                    LIMIT ?
                    FOR UPDATE SKIP LOCKED
                )
                RETURNING id, transaction_id, source_balance_id, destination_balance_id, provider, lineage_type, payload, status, attempts, max_attempts, last_error, created_at, processed_at, locked_until, inflight
            )
            SELECT * FROM claimed ORDER BY created_at ASC
            SQL;

        try {
            $stmt = PgStatement::execute($this->conn, $query, [
                LineageOutbox::OutboxStatusProcessing,
                LineageRowMapper::goDurationString($lockDuration), // lockDuration.String()
                $batchSize,
            ]);
        } catch (\PDOException $err) {
            throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to claim pending outbox entries', $err);
        }

        $entries = [];
        try {
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                try {
                    $entry = LineageRowMapper::scanLineageOutbox($row);
                } catch (\InvalidArgumentException $err) {
                    throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to scan outbox entry', $err);
                }

                $entries[] = $entry;
            }
        } catch (\PDOException $err) {
            throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Error iterating over outbox entries', $err);
        }

        return $entries;
    }

    /**
     * MarkOutboxCompleted marks an outbox entry as completed.
     *
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to mark outbox entry as completed"
     */
    public function markOutboxCompleted(int $id): void
    {
        try {
            PgStatement::execute($this->conn, <<<'SQL'
                UPDATE blnk.lineage_outbox
                SET status = ?, processed_at = NOW(), locked_until = NULL
                WHERE id = ?
                SQL, [LineageOutbox::OutboxStatusCompleted, $id]);
        } catch (\PDOException $err) {
            throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to mark outbox entry as completed', $err);
        }
    }

    /**
     * MarkOutboxFailed marks an outbox entry as failed and increments the attempt counter.
     * If attempts exceed max_attempts, the status becomes 'failed', otherwise it returns to 'pending' for retry.
     *
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to mark outbox entry as failed"
     */
    public function markOutboxFailed(int $id, string $errMsg): void
    {
        try {
            PgStatement::execute($this->conn, <<<'SQL'
                UPDATE blnk.lineage_outbox
                SET status = CASE WHEN attempts + 1 >= max_attempts THEN ? ELSE ? END,
                    attempts = attempts + 1,
                    last_error = ?,
                    locked_until = NULL
                WHERE id = ?
                SQL, [LineageOutbox::OutboxStatusFailed, LineageOutbox::OutboxStatusPending, $errMsg, $id]);
        } catch (\PDOException $err) {
            throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to mark outbox entry as failed', $err);
        }
    }

    /**
     * GetOutboxByTransactionID retrieves an outbox entry by its transaction ID.
     *
     * @return LineageOutbox|null null when no entry exists (Go: `nil, nil` on sql.ErrNoRows).
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to retrieve outbox entry"
     */
    public function getOutboxByTransactionID(string $transactionID): ?LineageOutbox
    {
        try {
            $stmt = PgStatement::execute($this->conn, <<<'SQL'
                SELECT id, transaction_id, source_balance_id, destination_balance_id, provider, lineage_type, payload, status, attempts, max_attempts, last_error, created_at, processed_at, locked_until, inflight
                FROM blnk.lineage_outbox
                WHERE transaction_id = ?
                SQL, [$transactionID]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $err) {
            throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve outbox entry', $err);
        }

        if ($row === false) {
            return null; // sql.ErrNoRows → nil, nil
        }

        try {
            return LineageRowMapper::scanLineageOutbox($row);
        } catch (\InvalidArgumentException $err) {
            throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve outbox entry', $err);
        }
    }

    /**
     * HasPendingCreditOutbox checks if there are pending credit outbox entries for a given balance.
     * This is used to detect race conditions where a debit is being processed before credits are complete.
     *
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to check pending credit outbox"
     */
    public function hasPendingCreditOutbox(string $balanceID): bool
    {
        try {
            $stmt = PgStatement::execute($this->conn, <<<'SQL'
                SELECT COUNT(*) FROM blnk.lineage_outbox
                WHERE destination_balance_id = ?
                  AND lineage_type IN ('credit', 'both')
                  AND status IN ('pending', 'processing')
                SQL, [$balanceID]);
            $count = RowScanner::toInt($stmt->fetchColumn());
        } catch (\PDOException $err) {
            throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to check pending credit outbox', $err);
        }
        return $count > 0;
    }
}
