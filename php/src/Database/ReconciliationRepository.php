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
use Blnk\Model\ExternalTransaction;
use Blnk\Model\MatchingRule;
use Blnk\Model\Reconciliation;
use Blnk\Model\ReconciliationMatch;
use Blnk\Model\ReconciliationProgress;
use Blnk\Model\Transaction;

/**
 * Port of Go `database/reconciliation.go`: the `reconciliation` concern of
 * {@see Datasource} (composed as a trait, see PORTING.md / Datasource.php).
 *
 * - Go `model.Match` is {@see ReconciliationMatch} (`match` is reserved in PHP).
 * - The standalone Go helper `groupedExternalTransactionsQuery(groupCriteria)`
 *   is the private static method of the same name below; the row scanners and
 *   the criteria JSON helpers live in {@see ReconciliationRowMapper}.
 * - `GetExternalTransactionsByReconciliationID` is a `Datasource` method that
 *   the Go `IDataSource` interface does not declare; it is ported as a public
 *   method of this trait without being added to {@see DataSourceInterface}.
 * - Go's `d.Conn.BeginTx` / `txn.Commit()` / deferred `txn.Rollback()` map onto
 *   `$this->conn->beginTransaction()` / `commit()` /
 *   {@see PgStatement::rollbackQuietly()}; the `*sql.Tx` handed to
 *   `recordMatchInTransaction` is the same PDO connection while its
 *   transaction is open.
 * - Cache access mirrors the Go `d.Cache.Get / d.Cache.Set` calls; because the
 *   PHP {@see Datasource} may run without a cache (`$this->cache === null`),
 *   the cache steps are skipped in that case instead of dereferencing nil.
 * - `result.RowsAffected()` cannot fail with PDO (`rowCount()`), so the Go
 *   "Failed to get rows affected" branch has no counterpart.
 */
trait ReconciliationRepository
{
    /**
     * RecordReconciliation saves a reconciliation record to the database.
     * Parameters:
     * - rec: The reconciliation data to be stored.
     * Returns:
     * - An error if the reconciliation record fails to save, otherwise nil.
     *
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to record reconciliation"
     */
    public function recordReconciliation(Reconciliation $rec): void
    {
        $span = Tracer::get('reconciliation.database')->startSpan('Saving reconciliation to db');
        try {
            try {
                PgStatement::execute($this->conn, <<<'SQL'
                    INSERT INTO blnk.reconciliations(
                        reconciliation_id, upload_id, status, matched_transactions,
                        unmatched_transactions, started_at, completed_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                    SQL, [
                    $rec->reconciliationID,
                    $rec->uploadID,
                    $rec->status,
                    $rec->matchedTransactions,
                    $rec->unmatchedTransactions,
                    PqEncoder::time($rec->startedAt),          // time.Time (the zero time when unset)
                    PqEncoder::nullableTime($rec->completedAt), // *time.Time (nil → NULL)
                ]);
            } catch (\PDOException $err) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to record reconciliation', $err);
            }
        } finally {
            $span->end();
        }
    }

    /**
     * GetReconciliation fetches a reconciliation record from the database based on its ID.
     * Parameters:
     * - id: The reconciliation ID to search for.
     * Returns:
     * - A pointer to the reconciliation record if found, or an error if not found or if a failure occurs.
     *
     * @throws NotFoundException "Reconciliation with ID '<id>' not found" (Go: sql.ErrNoRows → ErrNotFound)
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to retrieve reconciliation"
     */
    public function getReconciliation(string $id): Reconciliation
    {
        $span = Tracer::get('reconciliation.database')->startSpan('Fetching reconciliation from db');
        try {
            try {
                $stmt = PgStatement::execute($this->conn, <<<'SQL'
                    SELECT id, reconciliation_id, upload_id, status, matched_transactions,
                        unmatched_transactions, started_at, completed_at
                    FROM blnk.reconciliations
                    WHERE reconciliation_id = ?
                    SQL, [$id]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            } catch (\PDOException $err) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve reconciliation', $err);
            }

            if ($row === false) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrNotFound, sprintf("Reconciliation with ID '%s' not found", $id), TransactionRowMapper::ERR_NO_ROWS);
            }

            try {
                return ReconciliationRowMapper::scanReconciliation($row);
            } catch (\InvalidArgumentException $err) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve reconciliation', $err);
            }
        } finally {
            $span->end();
        }
    }

    /**
     * UpdateReconciliationStatus updates the status, matched transactions, unmatched transactions,
     * and completed_at timestamp of a reconciliation in the database.
     * Parameters:
     * - id: The reconciliation ID to update.
     * - status: The new status of the reconciliation.
     * - matchedCount: The number of matched transactions.
     * - unmatchedCount: The number of unmatched transactions.
     * Returns:
     * - An error if the update fails or the reconciliation is not found.
     *
     * @throws NotFoundException "Reconciliation with ID '<id>' not found" (no row updated)
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to update reconciliation status"
     */
    public function updateReconciliationStatus(string $id, string $status, int $matchedCount, int $unmatchedCount): void
    {
        $span = Tracer::get('reconciliation.database')->startSpan('Updating reconciliation status');
        try {
            // completedAt := sql.NullTime{Time: time.Now(), Valid: status == "completed"}
            $completedAt = $status === 'completed' ? PqEncoder::time(PqEncoder::now()) : null;

            try {
                // Go binds $1 = id, $2 = status, $3 = matched, $4 = unmatched, $5 = completedAt;
                // the positional arguments follow the placeholder order of the SQL text.
                $stmt = PgStatement::execute($this->conn, <<<'SQL'
                    UPDATE blnk.reconciliations
                    SET status = ?, matched_transactions = ?, unmatched_transactions = ?, completed_at = ?
                    WHERE reconciliation_id = ?
                    SQL, [$status, $matchedCount, $unmatchedCount, $completedAt, $id]);
            } catch (\PDOException $err) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to update reconciliation status', $err);
            }

            $rowsAffected = $stmt->rowCount();

            if ($rowsAffected === 0) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrNotFound, sprintf("Reconciliation with ID '%s' not found", $id), null);
            }
        } finally {
            $span->end();
        }
    }

    /**
     * GetReconciliationsByUploadID retrieves all reconciliations associated with a specific upload ID, ordered by the start date in descending order.
     * Parameters:
     * - uploadID: The upload ID to filter the reconciliations.
     * Returns:
     * - A slice of Reconciliation pointers and an error if the query fails.
     *
     * @return Reconciliation[]
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to retrieve reconciliations" / "Failed to scan reconciliation data" / "Error occurred while iterating over reconciliations"
     */
    public function getReconciliationsByUploadID(string $uploadID): array
    {
        $span = Tracer::get('reconciliation.database')->startSpan('Fetching reconciliations by upload ID');
        try {
            try {
                $stmt = PgStatement::execute($this->conn, <<<'SQL'
                    SELECT id, reconciliation_id, upload_id, status, matched_transactions,
                        unmatched_transactions, started_at, completed_at
                    FROM blnk.reconciliations
                    WHERE upload_id = ?
                    ORDER BY started_at DESC
                    SQL, [$uploadID]);
            } catch (\PDOException $err) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve reconciliations', $err);
            }

            $reconciliations = [];

            try {
                while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    try {
                        $rec = ReconciliationRowMapper::scanReconciliation($row);
                    } catch (\InvalidArgumentException $err) {
                        throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to scan reconciliation data', $err);
                    }

                    $reconciliations[] = $rec;
                }
            } catch (\PDOException $err) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Error occurred while iterating over reconciliations', $err);
            }

            return $reconciliations;
        } finally {
            $span->end();
        }
    }

    /**
     * RecordMatches batches the saving of match records associated with a specific reconciliation ID.
     * It uses a database transaction to ensure atomicity and consistency of the batch insert operation.
     * Parameters:
     * - reconciliationID: The ID of the reconciliation the matches are associated with.
     * - matches: A slice of Match objects to be recorded.
     * Returns:
     * - An error if the operation fails, wrapped in an APIError for consistency.
     *
     * Go ranges over the slice by value, so assigning the reconciliation ID never
     * touches the caller's matches; the port clones each match for the same reason.
     *
     * @param ReconciliationMatch[] $matches
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to start transaction" / "Failed to record match" / "Failed to commit transaction"
     */
    public function recordMatches(string $reconciliationID, array $matches): void
    {
        $span = Tracer::get('reconciliation.database')->startSpan('Batch saving matches to db');
        try {
            try {
                $this->conn->beginTransaction();
            } catch (\PDOException $err) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to start transaction', $err);
            }
            $txn = $this->conn;

            try {
                foreach ($matches as $match) {
                    $match = clone $match;
                    $match->reconciliationID = $reconciliationID;
                    $this->recordMatchInTransaction($txn, $match); // The error is already wrapped in an APIError by recordMatchInTransaction
                }

                try {
                    $txn->commit();
                } catch (\PDOException $err) {
                    throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to commit transaction', $err);
                }
            } catch (\Throwable $err) {
                // defer txn.Rollback() — a no-op once committed (Go: sql.ErrTxDone is ignored).
                PgStatement::rollbackQuietly($txn);
                throw $err;
            }
        } finally {
            $span->end();
        }
    }

    /**
     * RecordUnmatched batches the saving of unmatched external transactions related to a specific reconciliation.
     * It uses a database transaction to ensure atomicity and consistency of the batch insert operation.
     * Parameters:
     * - reconciliationID: The ID of the reconciliation the unmatched transactions are associated with.
     * - results: A slice of external transaction IDs (as strings) representing unmatched transactions.
     * Returns:
     * - An error if the operation fails, wrapped in an APIError for consistency.
     *
     * @param string[] $results
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to start transaction" / "Failed to record unmatched match" / "Failed to commit transaction"
     */
    public function recordUnmatched(string $reconciliationID, array $results): void
    {
        $span = Tracer::get('reconciliation.database')->startSpan('Batch saving unmatched to db');
        try {
            try {
                $this->conn->beginTransaction();
            } catch (\PDOException $err) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to start transaction', $err);
            }
            $txn = $this->conn;

            try {
                foreach ($results as $externalId) {
                    try {
                        PgStatement::execute($txn, <<<'SQL'
                            INSERT INTO blnk.unmatched(
                                    external_transaction_id, reconciliation_id, date
                                ) VALUES (?, ?, ?)
                            SQL, [$externalId, $reconciliationID, PqEncoder::time(PqEncoder::now())]);
                    } catch (\PDOException $err) {
                        throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to record unmatched match', $err);
                    }
                }

                try {
                    $txn->commit();
                } catch (\PDOException $err) {
                    throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to commit transaction', $err);
                }
            } catch (\Throwable $err) {
                // defer txn.Rollback() — a no-op once committed (Go: sql.ErrTxDone is ignored).
                PgStatement::rollbackQuietly($txn);
                throw $err;
            }
        } finally {
            $span->end();
        }
    }

    /**
     * recordMatchInTransaction inserts a match into the database within a transaction context.
     * It is used to save matches during a reconciliation process, ensuring atomicity in batch operations.
     * Parameters:
     * - tx: The current database transaction in which the match is recorded.
     * - match: A pointer to the Match struct containing details of the external and internal transactions, and their match status.
     * Returns:
     * - An error if the operation fails, wrapped in an APIError for consistency.
     *
     * @param \PDO $tx The connection whose `beginTransaction()` is currently open (Go: `*sql.Tx`).
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to record match"
     */
    private function recordMatchInTransaction(\PDO $tx, ReconciliationMatch $match): void
    {
        try {
            PgStatement::execute($tx, <<<'SQL'
                INSERT INTO blnk.matches(
                    external_transaction_id, internal_transaction_id, reconciliation_id, amount, date
                ) VALUES (?, ?, ?, ?, ?)
                SQL, [
                $match->externalTransactionID,
                $match->internalTransactionID,
                $match->reconciliationID,
                $match->amount,
                PqEncoder::time($match->date),
            ]);
        } catch (\PDOException $err) {
            // For other errors, return the original error
            throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to record match', $err);
        }
    }

    /**
     * RecordMatch inserts a single match into the database.
     * It is used to record the match between external and internal transactions during reconciliation.
     * Parameters:
     * - match: A pointer to the Match struct containing details of the external and internal transactions, and their match status.
     * Returns:
     * - An error if the operation fails, wrapped in an APIError for consistency.
     *
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to record match"
     */
    public function recordMatch(ReconciliationMatch $match): void
    {
        $span = Tracer::get('reconciliation.database')->startSpan('Saving match to db');
        try {
            try {
                PgStatement::execute($this->conn, <<<'SQL'
                    INSERT INTO blnk.matches(
                        external_transaction_id, internal_transaction_id, reconciliation_id, amount, date
                    ) VALUES (?, ?, ?, ?, ?)
                    SQL, [
                    $match->externalTransactionID,
                    $match->internalTransactionID,
                    $match->reconciliationID,
                    $match->amount,
                    PqEncoder::time($match->date),
                ]);
            } catch (\PDOException $err) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to record match', $err);
            }
        } finally {
            $span->end();
        }
    }

    /**
     * GetMatchesByReconciliationID retrieves all matches associated with a given reconciliation ID.
     * It joins the matches table with external transactions to filter matches by reconciliation ID.
     * Parameters:
     * - reconciliationID: The ID of the reconciliation to filter matches.
     * Returns:
     * - A slice of Match structs representing the matched transactions.
     * - An error if the operation fails, wrapped in an APIError for consistency.
     *
     * @return ReconciliationMatch[]
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to retrieve matches" / "Failed to scan match data" / "Error occurred while iterating over matches"
     */
    public function getMatchesByReconciliationID(string $reconciliationID): array
    {
        $span = Tracer::get('reconciliation.database')->startSpan('Fetching matches by reconciliation ID');
        try {
            try {
                $stmt = PgStatement::execute($this->conn, <<<'SQL'
                    SELECT m.external_transaction_id, m.internal_transaction_id, m.amount, m.date
                    FROM blnk.matches m
                    JOIN blnk.external_transactions et ON m.external_transaction_id = et.id
                    WHERE et.reconciliation_id = ?
                    SQL, [$reconciliationID]);
            } catch (\PDOException $err) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve matches', $err);
            }

            $matches = [];

            try {
                while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    try {
                        $match = ReconciliationRowMapper::scanMatch($row);
                    } catch (\InvalidArgumentException $err) {
                        throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to scan match data', $err);
                    }

                    $matches[] = $match;
                }
            } catch (\PDOException $err) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Error occurred while iterating over matches', $err);
            }

            return $matches;
        } finally {
            $span->end();
        }
    }

    /**
     * RecordExternalTransaction inserts a new external transaction into the database.
     * It associates the transaction with an upload ID and records its details.
     * Parameters:
     * - tx: Pointer to the ExternalTransaction struct containing transaction data.
     * - uploadID: The ID of the upload batch this transaction belongs to.
     * Returns:
     * - An error if the operation fails, wrapped in an APIError for consistency.
     *
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to record external transaction"
     */
    public function recordExternalTransaction(ExternalTransaction $tx, string $uploadID): void
    {
        $span = Tracer::get('reconciliation.database')->startSpan('Saving external transaction to db');
        try {
            try {
                PgStatement::execute($this->conn, <<<'SQL'
                    INSERT INTO blnk.external_transactions(
                        id, amount, reference, currency, description, date, source, upload_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    SQL, [
                    $tx->id,
                    $tx->amount,
                    $tx->reference,
                    $tx->currency,
                    $tx->description,
                    PqEncoder::time($tx->date),
                    $tx->source,
                    $uploadID,
                ]);
            } catch (\PDOException $err) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to record external transaction', $err);
            }
        } finally {
            $span->end();
        }
    }

    /**
     * GetExternalTransactionsByReconciliationID fetches all external transactions associated with a given reconciliation ID.
     * Parameters:
     * - reconciliationID: The ID of the reconciliation to fetch external transactions for.
     * Returns:
     * - A slice of ExternalTransaction pointers or an error wrapped in an APIError if the operation fails.
     *
     * (Not part of the Go `IDataSource` interface.)
     *
     * @return ExternalTransaction[]
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to retrieve external transactions" / "Failed to scan external transaction data" / "Error occurred while iterating over external transactions"
     */
    public function getExternalTransactionsByReconciliationID(string $reconciliationID): array
    {
        $span = Tracer::get('reconciliation.database')->startSpan('Fetching external transactions by reconciliation ID');
        try {
            try {
                $stmt = PgStatement::execute($this->conn, <<<'SQL'
                    SELECT id, amount, reference, currency, description, date, source
                    FROM blnk.external_transactions
                    WHERE reconciliation_id = ?
                    SQL, [$reconciliationID]);
            } catch (\PDOException $err) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve external transactions', $err);
            }

            $transactions = [];

            try {
                while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    try {
                        $tx = ReconciliationRowMapper::scanExternalTransaction($row);
                    } catch (\InvalidArgumentException $err) {
                        throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to scan external transaction data', $err);
                    }

                    $transactions[] = $tx;
                }
            } catch (\PDOException $err) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Error occurred while iterating over external transactions', $err);
            }

            return $transactions;
        } finally {
            $span->end();
        }
    }

    /**
     * RecordMatchingRule saves a new matching rule to the database.
     * Parameters:
     * - rule: A pointer to the MatchingRule object containing rule details.
     * Returns:
     * - An error wrapped in an APIError if the operation fails.
     *
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to marshal matching rule criteria" / "Failed to record matching rule"
     */
    public function recordMatchingRule(MatchingRule $rule): void
    {
        $span = Tracer::get('reconciliation.database')->startSpan('Saving matching rule to db');
        try {
            // Marshal the matching rule criteria into JSON format
            try {
                $criteriaJSON = ReconciliationRowMapper::marshalCriteria($rule->criteria);
            } catch (\JsonException $err) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to marshal matching rule criteria', $err);
            }

            // Execute the SQL insert query to record the rule in the database
            try {
                PgStatement::execute($this->conn, <<<'SQL'
                    INSERT INTO blnk.matching_rules(
                        rule_id, created_at, updated_at, name, description, criteria
                    ) VALUES (?, ?, ?, ?, ?, ?)
                    SQL, [
                    $rule->ruleID,
                    PqEncoder::time($rule->createdAt),
                    PqEncoder::time($rule->updatedAt),
                    $rule->name,
                    $rule->description,
                    $criteriaJSON,
                ]);
            } catch (\PDOException $err) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to record matching rule', $err);
            }
        } finally {
            $span->end();
        }
    }

    /**
     * GetMatchingRules retrieves all matching rules from the database.
     * Returns:
     * - A slice of MatchingRule pointers or an error wrapped in an APIError if the operation fails.
     *
     * @return MatchingRule[]
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to retrieve matching rules" / "Failed to scan matching rule data" / "Failed to unmarshal matching rule criteria" / "Error occurred while iterating over matching rules"
     */
    public function getMatchingRules(): array
    {
        $span = Tracer::get('reconciliation.database')->startSpan('Fetching matching rules');
        try {
            // Execute the query to fetch all matching rules
            try {
                $stmt = PgStatement::execute($this->conn, <<<'SQL'
                    SELECT id, rule_id, created_at, updated_at, name, description, criteria
                    FROM blnk.matching_rules
                    SQL);
            } catch (\PDOException $err) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve matching rules', $err);
            }

            $rules = [];

            // Iterate over the rows to scan each rule into a MatchingRule object
            try {
                while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    try {
                        $rule = ReconciliationRowMapper::scanMatchingRule($row);
                    } catch (\InvalidArgumentException $err) {
                        throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to scan matching rule data', $err);
                    }

                    // Unmarshal the criteria JSON into the MatchingRule's Criteria field
                    try {
                        $rule->criteria = ReconciliationRowMapper::unmarshalCriteria($row['criteria'] ?? null);
                    } catch (\JsonException $err) {
                        throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to unmarshal matching rule criteria', $err);
                    }

                    // Append the rule to the rules slice
                    $rules[] = $rule;
                }
            } catch (\PDOException $err) {
                // Check for any errors that occurred during the iteration
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Error occurred while iterating over matching rules', $err);
            }

            return $rules;
        } finally {
            $span->end();
        }
    }

    /**
     * UpdateMatchingRule updates a specific matching rule in the database.
     * Parameters:
     * - rule: The matching rule to be updated, including the RuleID, name, description, and criteria.
     * Returns:
     * - An error wrapped in an APIError if the operation fails, or nil if the update is successful.
     *
     * @throws NotFoundException "Matching rule with ID '<rule_id>' not found" (no row updated)
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to marshal matching rule criteria" / "Failed to update matching rule"
     */
    public function updateMatchingRule(MatchingRule $rule): void
    {
        $span = Tracer::get('reconciliation.database')->startSpan('Updating matching rule');
        try {
            // Marshal the Criteria field into JSON
            try {
                $criteriaJSON = ReconciliationRowMapper::marshalCriteria($rule->criteria);
            } catch (\JsonException $err) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to marshal matching rule criteria', $err);
            }

            // Execute the SQL update statement
            try {
                // Go binds $1 = rule_id, $2 = name, $3 = description, $4 = criteria;
                // the positional arguments follow the placeholder order of the SQL text.
                $stmt = PgStatement::execute($this->conn, <<<'SQL'
                    UPDATE blnk.matching_rules
                    SET name = ?, description = ?, criteria = ?
                    WHERE rule_id = ?
                    SQL, [$rule->name, $rule->description, $criteriaJSON, $rule->ruleID]);
            } catch (\PDOException $err) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to update matching rule', $err);
            }

            // Check how many rows were affected
            $rowsAffected = $stmt->rowCount();

            // If no rows were affected, return a NotFound error
            if ($rowsAffected === 0) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrNotFound, sprintf("Matching rule with ID '%s' not found", $rule->ruleID), null);
            }
        } finally {
            $span->end();
        }
    }

    /**
     * DeleteMatchingRule deletes a specific matching rule from the database.
     * Parameters:
     * - id: The ID of the matching rule to be deleted.
     * Returns:
     * - An error wrapped in an APIError if the operation fails, or nil if the deletion is successful.
     *
     * @throws NotFoundException "Matching rule with ID '<id>' not found" (no row deleted)
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to delete matching rule"
     */
    public function deleteMatchingRule(string $id): void
    {
        $span = Tracer::get('reconciliation.database')->startSpan('Deleting matching rule');
        try {
            // Execute the SQL delete statement
            try {
                $stmt = PgStatement::execute($this->conn, <<<'SQL'
                    DELETE FROM blnk.matching_rules
                    WHERE rule_id = ?
                    SQL, [$id]);
            } catch (\PDOException $err) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to delete matching rule', $err);
            }

            // Check how many rows were affected
            $rowsAffected = $stmt->rowCount();

            // If no rows were affected, return a NotFound error
            if ($rowsAffected === 0) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrNotFound, sprintf("Matching rule with ID '%s' not found", $id), null);
            }
        } finally {
            $span->end();
        }
    }

    /**
     * GetMatchingRule retrieves a specific matching rule from the database by its rule ID.
     * Parameters:
     * - id: The ID of the matching rule to be retrieved.
     * Returns:
     * - A pointer to the MatchingRule object if found, or an APIError if the operation fails.
     *
     * @throws NotFoundException "Matching rule with ID '<id>' not found" (Go: sql.ErrNoRows → ErrNotFound)
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to retrieve matching rule" / "Failed to unmarshal matching rule criteria"
     */
    public function getMatchingRule(string $id): MatchingRule
    {
        $span = Tracer::get('reconciliation.database')->startSpan('Fetching matching rule');
        try {
            // Execute SQL query to retrieve the matching rule by rule_id
            try {
                $stmt = PgStatement::execute($this->conn, <<<'SQL'
                    SELECT id, rule_id, created_at, updated_at, name, description, criteria
                    FROM blnk.matching_rules
                    WHERE rule_id = ?
                    SQL, [$id]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            } catch (\PDOException $err) {
                // Return InternalServer error for any other failure
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve matching rule', $err);
            }

            // Return NotFound error if no rows are returned
            if ($row === false) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrNotFound, sprintf("Matching rule with ID '%s' not found", $id), TransactionRowMapper::ERR_NO_ROWS);
            }

            try {
                $rule = ReconciliationRowMapper::scanMatchingRule($row);
            } catch (\InvalidArgumentException $err) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve matching rule', $err);
            }

            // Unmarshal JSON criteria into the MatchingRule object
            try {
                $rule->criteria = ReconciliationRowMapper::unmarshalCriteria($row['criteria'] ?? null);
            } catch (\JsonException $err) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to unmarshal matching rule criteria', $err);
            }

            return $rule;
        } finally {
            $span->end();
        }
    }

    /**
     * GetExternalTransactionsPaginated retrieves external transactions based on the provided upload ID
     * with pagination. It first checks the cache, and if the data is not available, it fetches from the
     * database and caches the result for 5 minutes.
     * Parameters:
     * - uploadID: The ID of the upload to filter external transactions.
     * - batchSize: The number of transactions to retrieve per batch.
     * - offset: The starting point to retrieve transactions from.
     * Returns:
     * - A slice of ExternalTransaction objects or an APIError if the operation fails.
     *
     * (The Go cache key deliberately mirrors the original: it is built from the
     * pagination parameters only, not from the upload ID.)
     *
     * @return ExternalTransaction[]
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to retrieve external transactions" / "Failed to scan external transaction data" / "Error occurred while iterating over external transactions"
     */
    public function getExternalTransactionsPaginated(string $uploadID, int $batchSize, int $offset): array
    {
        $span = Tracer::get('reconciliation.database')->startSpan('Fetching external transactions with pagination');
        try {
            // Create a cache key based on the pagination parameters
            $cacheKey = sprintf('transactions:external:paginated:%d:%d', $batchSize, $offset);
            /** @var ExternalTransaction[] $transactions */
            $transactions = [];

            // Check if the data exists in the cache
            if ($this->cache !== null) {
                try {
                    $cached = null;
                    $this->cache->get($cacheKey, $cached);
                    if (\is_array($cached) && \count($cached) > 0) {
                        $transactions = $cached;
                    }
                } catch (\Throwable) {
                    // A cache error falls through to the database, as in Go (err != nil).
                }
                if (\count($transactions) > 0) {
                    return $transactions;
                }
            }

            // Query the database for external transactions if not found in cache
            try {
                $stmt = PgStatement::execute($this->conn, <<<'SQL'
                    SELECT id, amount, reference, currency, description, date, source
                    FROM blnk.external_transactions
                    WHERE upload_id = ?
                    ORDER BY date DESC
                    LIMIT ? OFFSET ?
                    SQL, [$uploadID, $batchSize, $offset]);
            } catch (\PDOException $err) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve external transactions', $err);
            }

            // Iterate through the rows and scan data into the transaction slice
            try {
                while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    try {
                        $tx = ReconciliationRowMapper::scanExternalTransaction($row);
                    } catch (\InvalidArgumentException $err) {
                        throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to scan external transaction data', $err);
                    }

                    $transactions[] = $tx;
                }
            } catch (\PDOException $err) {
                // Handle any error encountered during row iteration
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Error occurred while iterating over external transactions', $err);
            }

            // Cache the fetched data for future queries
            if (\count($transactions) > 0 && $this->cache !== null) {
                try {
                    $this->cache->set($cacheKey, $transactions, 5 * 60); // Cache for 5 minutes
                } catch (\Throwable $err) {
                    // Log the error, but don't return it as the main operation succeeded
                    Log::get()->error(sprintf('Failed to cache transactions: %s', $err->getMessage()));
                }
            }
            return $transactions;
        } finally {
            $span->end();
        }
    }

    /**
     * SaveReconciliationProgress saves the progress of a reconciliation process to the database. If a record with the given reconciliation ID
     * already exists, it updates the progress information. The function ensures that the reconciliation progress is stored and updated properly.
     * Parameters:
     * - reconciliationID: The ID of the reconciliation to track progress.
     * - progress: A ReconciliationProgress object containing the number of processed transactions and the last processed external transaction ID.
     * Returns:
     * - An error if the operation fails, wrapped in an APIError if needed.
     *
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to save reconciliation progress"
     */
    public function saveReconciliationProgress(string $reconciliationID, ReconciliationProgress $progress): void
    {
        $span = Tracer::get('reconciliation.database')->startSpan('Saving reconciliation progress to db');
        try {
            // Execute the query, with conflict handling to update if the record already exists
            try {
                // Go binds $1 = reconciliationID, $2 = processed_count, $3 = last_processed_external_txn_id,
                // reusing $2 and $3 in the DO UPDATE clause; each occurrence is bound positionally here.
                PgStatement::execute($this->conn, <<<'SQL'
                    INSERT INTO blnk.reconciliation_progress (reconciliation_id, processed_count, last_processed_external_txn_id)
                    VALUES (?, ?, ?)
                    ON CONFLICT (reconciliation_id) DO UPDATE
                    SET processed_count = ?, last_processed_external_txn_id = ?
                    SQL, [
                    $reconciliationID,
                    $progress->processedCount,
                    $progress->lastProcessedExternalTxnID,
                    $progress->processedCount,
                    $progress->lastProcessedExternalTxnID,
                ]);
            } catch (\PDOException $err) {
                // Return an API error if the query fails
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to save reconciliation progress', $err);
            }
        } finally {
            $span->end();
        }
    }

    /**
     * LoadReconciliationProgress retrieves the progress of a reconciliation process from the database. If the reconciliation progress
     * is not found, it returns an empty ReconciliationProgress object.
     * Parameters:
     * - reconciliationID: The ID of the reconciliation whose progress is being retrieved.
     * Returns:
     * - A ReconciliationProgress object containing the current progress, or an error wrapped in an APIError if any issues occur.
     *
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to load reconciliation progress"
     */
    public function loadReconciliationProgress(string $reconciliationID): ReconciliationProgress
    {
        $span = Tracer::get('reconciliation.database')->startSpan('Loading reconciliation progress from db');
        try {
            $progress = new ReconciliationProgress();
            try {
                $stmt = PgStatement::execute($this->conn, <<<'SQL'
                    SELECT processed_count, last_processed_external_txn_id
                    FROM blnk.reconciliation_progress
                    WHERE reconciliation_id = ?
                    SQL, [$reconciliationID]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            } catch (\PDOException $err) {
                // Handle potential errors
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to load reconciliation progress', $err);
            }

            if ($row === false) {
                return new ReconciliationProgress(); // Return empty progress if not found
            }

            $progress->processedCount = RowScanner::toInt($row['processed_count'] ?? null);
            $progress->lastProcessedExternalTxnID = RowScanner::toString($row['last_processed_external_txn_id'] ?? null);

            return $progress;
        } finally {
            $span->end();
        }
    }

    /**
     * FetchAndGroupExternalTransactions retrieves external transactions from the database based on a specific grouping criterion and paginates the results.
     * The function first checks if the results are available in cache, and if not, fetches the data from the database, groups it by the specified criterion, and stores the result in cache.
     * Parameters:
     * - uploadID: The ID of the upload to filter external transactions.
     * - groupCriteria: The field by which to group the transactions (e.g., "amount", "currency").
     * - batchSize: The number of transactions to retrieve.
     * - offset: The pagination offset.
     * Returns:
     * - A map of grouped transactions where the key is the group criterion value and the value is a slice of transactions, or an error wrapped in an APIError if any issues occur.
     *
     * Note: PHP coerces integer-like string keys ("42") to int keys in the returned
     * map; consumers should cast the key back with `(string)`.
     *
     * @return array<string, Transaction[]>
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Invalid group criteria: <criteria>" (BAD_REQUEST); "Failed to retrieve grouped external transactions" / "Failed to scan external transaction data" / "Error occurred while iterating over external transactions"
     */
    public function fetchAndGroupExternalTransactions(string $uploadID, string $groupCriteria, int $batchSize, int $offset): array
    {
        $span = Tracer::get('reconciliation.database')->startSpan('FetchAndGroupExternalTransactions');
        try {
            [$query, $ok] = self::groupedExternalTransactionsQuery($groupCriteria);
            if (!$ok) {
                $span->recordError(new \RuntimeException(sprintf('invalid group criteria: %s', $groupCriteria)));
                throw TransactionRowMapper::apiError(ErrorCode::ErrBadRequest, sprintf('Invalid group criteria: %s', $groupCriteria), null);
            }

            // Create a cache key based on the grouping and pagination parameters
            $cacheKey = sprintf('external_transactions:grouped:%s:%s:%d:%d', $uploadID, $groupCriteria, $batchSize, $offset);

            /** @var array<string, Transaction[]> $groupedTransactions */
            $groupedTransactions = [];
            if ($this->cache !== null) {
                try {
                    $cached = null;
                    $this->cache->get($cacheKey, $cached);
                    if (\is_array($cached) && \count($cached) > 0) {
                        $groupedTransactions = $cached;
                    }
                } catch (\Throwable) {
                    // A cache error falls through to the database, as in Go (err != nil).
                }
                if (\count($groupedTransactions) > 0) {
                    $span->setAttribute('Grouped external transactions retrieved from cache', [
                        'group.count' => \count($groupedTransactions),
                    ]);
                    return $groupedTransactions;
                }
            }

            // If not in cache or error occurred, fetch from database
            try {
                $stmt = PgStatement::execute($this->conn, $query, [$uploadID, $batchSize, $offset]);
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve grouped external transactions', $err);
            }

            $groupedTransactions = [];

            try {
                while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    try {
                        $groupKey = RowScanner::toString($row['group_key'] ?? null);
                        $tx = ReconciliationRowMapper::scanExternalTransaction($row);
                    } catch (\InvalidArgumentException $err) {
                        $span->recordError($err);
                        throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to scan external transaction data', $err);
                    }

                    // Convert ExternalTransaction to Transaction
                    $transaction = $tx->toInternalTransaction();
                    $groupedTransactions[$groupKey][] = $transaction;
                }
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Error occurred while iterating over external transactions', $err);
            }

            // Cache the fetched data if not empty
            if (\count($groupedTransactions) > 0 && $this->cache !== null) {
                try {
                    $this->cache->set($cacheKey, $groupedTransactions, 5 * 60); // 5*time.Minute
                } catch (\Throwable $err) {
                    Log::get()->error(sprintf('Failed to cache grouped external transactions: %s', $err->getMessage()));
                }
            }

            $span->setAttribute('Grouped external transactions retrieved', [
                'group.count' => \count($groupedTransactions),
            ]);
            return $groupedTransactions;
        } finally {
            $span->end();
        }
    }

    /**
     * groupedExternalTransactionsQuery returns the grouped-listing query for a supported
     * grouping column of `blnk.external_transactions`. Go:
     * `groupedExternalTransactionsQuery(groupCriteria string) (string, bool)` — the pair is
     * returned as `[$query, $ok]`; `$ok` is false (and the query empty) for an unknown
     * criterion.
     *
     * @return array{0: string, 1: bool}
     */
    private static function groupedExternalTransactionsQuery(string $groupCriteria): array
    {
        switch ($groupCriteria) {
            case 'id':
                return [<<<'SQL'
                    SELECT id::text AS group_key, id, amount, reference, currency, description, date, source
                    FROM blnk.external_transactions
                    WHERE upload_id = ? AND id::text IS NOT NULL AND id::text != ''
                    ORDER BY id::text
                    LIMIT ? OFFSET ?
                    SQL, true];
            case 'amount':
                return [<<<'SQL'
                    SELECT amount::text AS group_key, id, amount, reference, currency, description, date, source
                    FROM blnk.external_transactions
                    WHERE upload_id = ? AND amount::text IS NOT NULL AND amount::text != ''
                    ORDER BY amount::text
                    LIMIT ? OFFSET ?
                    SQL, true];
            case 'reference':
                return [<<<'SQL'
                    SELECT reference::text AS group_key, id, amount, reference, currency, description, date, source
                    FROM blnk.external_transactions
                    WHERE upload_id = ? AND reference::text IS NOT NULL AND reference::text != ''
                    ORDER BY reference::text
                    LIMIT ? OFFSET ?
                    SQL, true];
            case 'currency':
                return [<<<'SQL'
                    SELECT currency::text AS group_key, id, amount, reference, currency, description, date, source
                    FROM blnk.external_transactions
                    WHERE upload_id = ? AND currency::text IS NOT NULL AND currency::text != ''
                    ORDER BY currency::text
                    LIMIT ? OFFSET ?
                    SQL, true];
            case 'description':
                return [<<<'SQL'
                    SELECT description::text AS group_key, id, amount, reference, currency, description, date, source
                    FROM blnk.external_transactions
                    WHERE upload_id = ? AND description::text IS NOT NULL AND description::text != ''
                    ORDER BY description::text
                    LIMIT ? OFFSET ?
                    SQL, true];
            case 'date':
                return [<<<'SQL'
                    SELECT date::text AS group_key, id, amount, reference, currency, description, date, source
                    FROM blnk.external_transactions
                    WHERE upload_id = ? AND date::text IS NOT NULL AND date::text != ''
                    ORDER BY date::text
                    LIMIT ? OFFSET ?
                    SQL, true];
            case 'source':
                return [<<<'SQL'
                    SELECT source::text AS group_key, id, amount, reference, currency, description, date, source
                    FROM blnk.external_transactions
                    WHERE upload_id = ? AND source::text IS NOT NULL AND source::text != ''
                    ORDER BY source::text
                    LIMIT ? OFFSET ?
                    SQL, true];
            default:
                return ['', false];
        }
    }
}
