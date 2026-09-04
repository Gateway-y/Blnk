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

use Blnk\Internal\ApiError\ApiErrorException;
use Blnk\Internal\Traces\Tracer;
use Blnk\Model\Chain;
use Blnk\Model\ChainedTransaction;
use Blnk\Model\ChainRow;
use Blnk\Model\ChainState;

/**
 * Port of database/chain.go: the hash-chain (tamper-evidence) methods of
 * `Datasource`.
 *
 * Composed into {@see Datasource}; uses `$this->conn` (\PDO). The chained
 * SELECTs use unaliased COALESCE expressions, so rows are read positionally
 * (\PDO::FETCH_NUM) in the Go `rows.Scan` order.
 */
trait ChainRepository
{
    /**
     * chainKey is the partition key of the single global chain. The chain_state
     * table is keyed so the chain can be partitioned later without a redesign.
     */
    private const chainKey = 'global';

    /**
     * ChainPendingTransactions seals the next batch of unchained transactions into
     * the global hash chain in a single DB transaction. It locks the one chain_state
     * row FOR UPDATE (serializing every chainer instance — a second writer blocks,
     * then re-reads the advanced head), scans unchained rows older than cutoff in id
     * order, links each onto the head via model.ComputeChainHash, writes their
     * chain_* columns, and advances chain_state — all atomically, so a crash mid
     * batch loses nothing. Returns the number of rows sealed.
     *
     * @throws ApiErrorException
     */
    public function chainPendingTransactions(\DateTimeImmutable $cutoff, int $batchSize): int
    {
        $span = Tracer::get('transaction.database')->startSpan('ChainPendingTransactions');

        try {
            try {
                $this->conn->beginTransaction();
            } catch (\PDOException $e) {
                throw PgStatement::wrap($e, 'Failed to begin chain transaction');
            }

            try {
                try {
                    $state = PgStatement::execute(
                        $this->conn,
                        'SELECT last_seq, head_hash FROM blnk.chain_state WHERE chain_key = ? FOR UPDATE',
                        [self::chainKey]
                    )->fetch(\PDO::FETCH_NUM);
                } catch (\PDOException $e) {
                    throw PgStatement::wrap($e, 'Failed to lock chain state');
                }
                if ($state === false) {
                    throw new DatabaseException('Failed to lock chain state', null, 'sql: no rows in result set');
                }
                $lastSeq = RowScanner::toInt($state[0] ?? null);
                $head = RowScanner::toString($state[1] ?? null);

                try {
                    $rows = PgStatement::execute($this->conn, '
		SELECT id, transaction_id, COALESCE(source, \'\'), COALESCE(destination, \'\'),
		       COALESCE(amount::text, \'\'), COALESCE(precise_amount::text, \'\'),
		       COALESCE(currency, \'\'), COALESCE(status, \'\'), COALESCE(reference, \'\'),
		       created_at
		FROM blnk.transactions
		WHERE chain_seq IS NULL AND created_at < ?
		ORDER BY id ASC
		LIMIT ?', [$cutoff, $batchSize]);
                } catch (\PDOException $e) {
                    throw PgStatement::wrap($e, 'Failed to scan unchained transactions');
                }

                // (Go: `type link struct { id, seq int64; prevHash, hash string }`)
                /** @var list<array{id: int, seq: int, prevHash: string, hash: string}> $batch */
                $batch = [];
                try {
                    while (($row = $rows->fetch(\PDO::FETCH_NUM)) !== false) {
                        try {
                            $id = RowScanner::toInt($row[0] ?? null);
                            $r = new ChainRow();
                            $r->transactionID = RowScanner::toString($row[1] ?? null);
                            $r->source = RowScanner::toString($row[2] ?? null);
                            $r->destination = RowScanner::toString($row[3] ?? null);
                            $r->amount = RowScanner::toString($row[4] ?? null);
                            $r->preciseAmount = RowScanner::toString($row[5] ?? null);
                            $r->currency = RowScanner::toString($row[6] ?? null);
                            $r->status = RowScanner::toString($row[7] ?? null);
                            $r->reference = RowScanner::toString($row[8] ?? null);
                            $r->createdAt = RowScanner::toTime($row[9] ?? null);
                        } catch (\InvalidArgumentException $e) {
                            $rows->closeCursor();
                            throw new DatabaseException('Failed to read unchained transaction', null, $e->getMessage(), $e);
                        }
                        $prev = $head;
                        $head = Chain::computeChainHash($prev, $r);
                        $lastSeq++;
                        $batch[] = ['id' => $id, 'seq' => $lastSeq, 'prevHash' => $prev, 'hash' => $head];
                    }
                } catch (\PDOException $e) {
                    $rows->closeCursor();
                    throw PgStatement::wrap($e, 'Error iterating unchained transactions');
                }
                // The rows cursor must be closed before issuing further statements on this tx.
                $rows->closeCursor();

                if (\count($batch) === 0) {
                    // Commit to release the chain_state lock without advancing.
                    try {
                        $this->conn->commit();
                    } catch (\PDOException $e) {
                        throw PgStatement::wrap($e, 'Failed to commit empty chain batch');
                    }
                    return 0;
                }

                try {
                    $stmt = $this->conn->prepare(
                        'UPDATE blnk.transactions SET chain_seq = ?, chain_prev_hash = ?, chain_hash = ? WHERE id = ?'
                    );
                } catch (\PDOException $e) {
                    throw PgStatement::wrap($e, 'Failed to prepare chain update');
                }
                foreach ($batch as $l) {
                    try {
                        $stmt->execute(PqEncoder::args([$l['seq'], $l['prevHash'], $l['hash'], $l['id']]));
                    } catch (\PDOException $e) {
                        throw PgStatement::wrap($e, 'Failed to write chain link');
                    }
                }

                try {
                    PgStatement::execute(
                        $this->conn,
                        'UPDATE blnk.chain_state SET last_seq = ?, head_hash = ?, updated_at = timezone(\'UTC\', now()) WHERE chain_key = ?',
                        [$lastSeq, $head, self::chainKey]
                    );
                } catch (\PDOException $e) {
                    throw PgStatement::wrap($e, 'Failed to advance chain state');
                }

                try {
                    $this->conn->commit();
                } catch (\PDOException $e) {
                    throw PgStatement::wrap($e, 'Failed to commit chain batch');
                }
            } catch (\Throwable $e) {
                // Go: `defer func() { _ = tx.Rollback() }()`
                PgStatement::rollbackQuietly($this->conn);
                throw $e;
            }

            $span->setAttribute('event', 'Chained transaction batch'); // span.AddEvent("Chained transaction batch")
            return \count($batch);
        } finally {
            $span->end();
        }
    }

    /**
     * GetChainState returns the global chain bookmark row.
     *
     * (Go wraps every failure — a missing row included — as the internal-server
     * error "Failed to get chain state"; that mapping is kept.)
     *
     * @throws ApiErrorException
     */
    public function getChainState(): ChainState
    {
        try {
            $row = PgStatement::execute($this->conn, '
		SELECT chain_key, last_seq, head_hash, genesis_hash, genesis_at, updated_at
		 FROM blnk.chain_state WHERE chain_key = ?', [self::chainKey])->fetch(\PDO::FETCH_NUM);
        } catch (\PDOException $e) {
            throw PgStatement::wrap($e, 'Failed to get chain state');
        }
        if ($row === false) {
            throw new DatabaseException('Failed to get chain state', null, 'sql: no rows in result set');
        }

        $s = new ChainState();
        try {
            $s->chainKey = RowScanner::toString($row[0] ?? null);
            $s->lastSeq = RowScanner::toInt($row[1] ?? null);
            $s->headHash = RowScanner::toString($row[2] ?? null);
            $s->genesisHash = RowScanner::toString($row[3] ?? null);
            $s->genesisAt = RowScanner::toTime($row[4] ?? null);
            $s->updatedAt = RowScanner::toTime($row[5] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw new DatabaseException('Failed to get chain state', null, $e->getMessage(), $e);
        }
        return $s;
    }

    /**
     * GetChainedTransactionsAfter returns chained transactions in chain order with
     * chain_seq > afterSeq, for the verifier to page through from genesis.
     *
     * @return ChainedTransaction[]
     *
     * @throws ApiErrorException
     */
    public function getChainedTransactionsAfter(int $afterSeq, int $limit): array
    {
        try {
            $rows = PgStatement::execute($this->conn, '
		SELECT chain_seq, COALESCE(chain_prev_hash, \'\'), COALESCE(chain_hash, \'\'),
		       transaction_id, COALESCE(source, \'\'), COALESCE(destination, \'\'),
		       COALESCE(amount::text, \'\'), COALESCE(precise_amount::text, \'\'),
		       COALESCE(currency, \'\'), COALESCE(status, \'\'), COALESCE(reference, \'\'),
		       created_at
		FROM blnk.transactions
		WHERE chain_seq IS NOT NULL AND chain_seq > ?
		ORDER BY chain_seq ASC
		LIMIT ?', [$afterSeq, $limit]);
        } catch (\PDOException $e) {
            throw PgStatement::wrap($e, 'Failed to query chained transactions');
        }

        $out = [];
        try {
            while (($row = $rows->fetch(\PDO::FETCH_NUM)) !== false) {
                $ct = new ChainedTransaction();
                $ct->row = new ChainRow();
                try {
                    $ct->chainSeq = RowScanner::toInt($row[0] ?? null);
                    $ct->chainPrevHash = RowScanner::toString($row[1] ?? null);
                    $ct->chainHash = RowScanner::toString($row[2] ?? null);
                    $ct->row->transactionID = RowScanner::toString($row[3] ?? null);
                    $ct->row->source = RowScanner::toString($row[4] ?? null);
                    $ct->row->destination = RowScanner::toString($row[5] ?? null);
                    $ct->row->amount = RowScanner::toString($row[6] ?? null);
                    $ct->row->preciseAmount = RowScanner::toString($row[7] ?? null);
                    $ct->row->currency = RowScanner::toString($row[8] ?? null);
                    $ct->row->status = RowScanner::toString($row[9] ?? null);
                    $ct->row->reference = RowScanner::toString($row[10] ?? null);
                    $ct->row->createdAt = RowScanner::toTime($row[11] ?? null);
                } catch (\InvalidArgumentException $e) {
                    throw new DatabaseException('Failed to scan chained transaction', null, $e->getMessage(), $e);
                }
                $out[] = $ct;
            }
        } catch (\PDOException $e) {
            throw PgStatement::wrap($e, 'Error iterating chained transactions');
        }
        return $out;
    }

    /**
     * CountUnchainedTransactions returns how many transactions older than cutoff are
     * still unchained — the chainer's backlog, for the lag metric.
     *
     * @throws ApiErrorException
     */
    public function countUnchainedTransactions(\DateTimeImmutable $cutoff): int
    {
        try {
            $n = PgStatement::execute(
                $this->conn,
                'SELECT count(*) FROM blnk.transactions WHERE chain_seq IS NULL AND created_at < ?',
                [$cutoff]
            )->fetchColumn();
        } catch (\PDOException $e) {
            throw PgStatement::wrap($e, 'Failed to count unchained transactions');
        }
        if ($n === false) {
            throw new DatabaseException('Failed to count unchained transactions', null, 'sql: no rows in result set');
        }
        return RowScanner::toInt($n);
    }
}
