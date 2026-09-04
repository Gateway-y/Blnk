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

use Blnk\Model\ExternalTransaction;
use Blnk\Model\MatchingCriteria;
use Blnk\Model\MatchingRule;
use Blnk\Model\Reconciliation;
use Blnk\Model\ReconciliationMatch;

/**
 * ReconciliationRowMapper gathers the row-scanning and JSON helpers of
 * {@see ReconciliationRepository} (the port of database/reconciliation.go):
 * the counterparts of the inline `rows.Scan(&rec.ID, ...)` calls and of the
 * `json.Marshal` / `json.Unmarshal` of a matching rule's criteria.
 *
 * It lives in its own class (rather than as private trait methods) so that
 * the traits composed into {@see Datasource} never define colliding helper
 * names.
 *
 * Scanning conventions are those of {@see RowScanner}: TIMESTAMP columns are
 * read as UTC `\DateTimeImmutable` (NULL / the Go zero time → null), text as
 * strings (NULL → ''), INT/BIGINT as int, NUMERIC amounts as float.
 */
final class ReconciliationRowMapper
{
    /** Not instantiable: static helpers only. */
    private function __construct()
    {
    }

    /**
     * scanReconciliation fills a {@see Reconciliation} from an
     * `id, reconciliation_id, upload_id, status, matched_transactions,
     * unmatched_transactions, started_at, completed_at` row
     * (`rows.Scan(&rec.ID, &rec.ReconciliationID, ...)`; `CompletedAt` is a
     * `*time.Time`, NULL → null).
     *
     * @param array<string, mixed> $row
     * @throws \InvalidArgumentException when a column cannot be converted (Go: Scan error).
     */
    public static function scanReconciliation(array $row): Reconciliation
    {
        $rec = new Reconciliation();
        $rec->id = RowScanner::toInt($row['id'] ?? null);
        $rec->reconciliationID = RowScanner::toString($row['reconciliation_id'] ?? null);
        $rec->uploadID = RowScanner::toString($row['upload_id'] ?? null);
        $rec->status = RowScanner::toString($row['status'] ?? null);
        $rec->matchedTransactions = RowScanner::toInt($row['matched_transactions'] ?? null);
        $rec->unmatchedTransactions = RowScanner::toInt($row['unmatched_transactions'] ?? null);
        $rec->startedAt = RowScanner::toTime($row['started_at'] ?? null);
        $rec->completedAt = RowScanner::toTime($row['completed_at'] ?? null);
        return $rec;
    }

    /**
     * scanMatch fills a {@see ReconciliationMatch} (Go `model.Match`) from an
     * `external_transaction_id, internal_transaction_id, amount, date` row
     * (`rows.Scan(&match.ExternalTransactionID, &match.InternalTransactionID,
     * &match.Amount, &match.Date)`). The reconciliation ID is not part of the
     * selected columns and keeps its zero value, as in Go.
     *
     * @param array<string, mixed> $row
     * @throws \InvalidArgumentException when a column cannot be converted (Go: Scan error).
     */
    public static function scanMatch(array $row): ReconciliationMatch
    {
        $match = new ReconciliationMatch();
        $match->externalTransactionID = RowScanner::toString($row['external_transaction_id'] ?? null);
        $match->internalTransactionID = RowScanner::toString($row['internal_transaction_id'] ?? null);
        $match->amount = RowScanner::toFloat($row['amount'] ?? null);
        $match->date = RowScanner::toTime($row['date'] ?? null);
        return $match;
    }

    /**
     * scanExternalTransaction fills an {@see ExternalTransaction} from an
     * `id, amount, reference, currency, description, date, source` row
     * (`rows.Scan(&tx.ID, &tx.Amount, &tx.Reference, &tx.Currency,
     * &tx.Description, &tx.Date, &tx.Source)`).
     *
     * @param array<string, mixed> $row
     * @throws \InvalidArgumentException when a column cannot be converted (Go: Scan error).
     */
    public static function scanExternalTransaction(array $row): ExternalTransaction
    {
        $tx = new ExternalTransaction();
        $tx->id = RowScanner::toString($row['id'] ?? null);
        $tx->amount = RowScanner::toFloat($row['amount'] ?? null);
        $tx->reference = RowScanner::toString($row['reference'] ?? null);
        $tx->currency = RowScanner::toString($row['currency'] ?? null);
        $tx->description = RowScanner::toString($row['description'] ?? null);
        $tx->date = RowScanner::toTime($row['date'] ?? null);
        $tx->source = RowScanner::toString($row['source'] ?? null);
        return $tx;
    }

    /**
     * scanMatchingRule fills a {@see MatchingRule} from an
     * `id, rule_id, created_at, updated_at, name, description, criteria` row
     * (`rows.Scan(&rule.ID, &rule.RuleID, &rule.CreatedAt, &rule.UpdatedAt,
     * &rule.Name, &rule.Description, &criteriaJSON)`). The `criteria` JSON is
     * deliberately NOT decoded here — see {@see unmarshalCriteria()} — because
     * the Go methods wrap that failure with their own error message.
     *
     * @param array<string, mixed> $row
     * @throws \InvalidArgumentException when a column cannot be converted (Go: Scan error).
     */
    public static function scanMatchingRule(array $row): MatchingRule
    {
        $rule = new MatchingRule();
        $rule->id = RowScanner::toInt($row['id'] ?? null);
        $rule->ruleID = RowScanner::toString($row['rule_id'] ?? null);
        $rule->createdAt = RowScanner::toTime($row['created_at'] ?? null);
        $rule->updatedAt = RowScanner::toTime($row['updated_at'] ?? null);
        $rule->name = RowScanner::toString($row['name'] ?? null);
        $rule->description = RowScanner::toString($row['description'] ?? null);
        return $rule;
    }

    /**
     * marshalCriteria mirrors `json.Marshal(rule.Criteria)` for a
     * `[]model.MatchingCriteria`: a nil slice marshals as `null`, otherwise as a
     * JSON array of objects carrying the Go JSON tag names
     * ({@see MatchingCriteria::jsonSerialize()}).
     *
     * @param MatchingCriteria[]|null $criteria
     * @throws \JsonException when the value cannot be encoded (Go: json.Marshal error).
     */
    public static function marshalCriteria(?array $criteria): string
    {
        if ($criteria === null) {
            return 'null';
        }
        return json_encode(
            array_values($criteria),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /**
     * unmarshalCriteria mirrors `json.Unmarshal(criteriaJSON, &rule.Criteria)`
     * where `Criteria` is a `[]model.MatchingCriteria`:
     * - a SQL NULL scans into a nil `[]byte`, which Go rejects with
     *   "unexpected end of JSON input";
     * - the JSON literal `null` yields a nil slice (null);
     * - a JSON array of objects yields the criteria list;
     * - any other JSON value is rejected with Go's "json: cannot unmarshal
     *   <kind> into Go value of type ..." message.
     *
     * @param mixed $json The raw `criteria` cell as returned by PDO (string or null).
     * @return MatchingCriteria[]|null
     * @throws \JsonException on any of the failures above (callers wrap it with
     *   the Go method's own "Failed to unmarshal matching rule criteria" error).
     */
    public static function unmarshalCriteria(mixed $json): ?array
    {
        if ($json === null) {
            throw new \JsonException('unexpected end of JSON input');
        }
        $json = (string) $json;
        $trimmed = ltrim($json, " \t\r\n");
        if ($trimmed === '') {
            throw new \JsonException('unexpected end of JSON input');
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if ($decoded === null) {
            return null; // JSON null → nil slice
        }
        if ($trimmed[0] !== '[' || !\is_array($decoded)) {
            throw new \JsonException(sprintf(
                'json: cannot unmarshal %s into Go value of type []model.MatchingCriteria',
                self::jsonKind($trimmed[0])
            ));
        }

        $criteria = [];
        foreach ($decoded as $item) {
            if (!\is_array($item) || array_is_list($item) && \count($item) > 0) {
                throw new \JsonException(sprintf(
                    'json: cannot unmarshal %s into Go value of type model.MatchingCriteria',
                    \is_array($item) ? 'array' : self::valueKind($item)
                ));
            }
            /** @var array<string, mixed> $item */
            $criteria[] = MatchingCriteria::fromArray($item);
        }
        return $criteria;
    }

    /** The Go `json` package's name for the kind of value a JSON text starts with. */
    private static function jsonKind(string $firstChar): string
    {
        return match (true) {
            $firstChar === '{' => 'object',
            $firstChar === '"' => 'string',
            $firstChar === 't', $firstChar === 'f' => 'bool',
            $firstChar === '-', ctype_digit($firstChar) => 'number',
            default => 'value',
        };
    }

    /** The Go `json` package's name for the kind of a decoded scalar. */
    private static function valueKind(mixed $value): string
    {
        return match (true) {
            \is_string($value) => 'string',
            \is_bool($value) => 'bool',
            \is_int($value), \is_float($value) => 'number',
            default => 'value',
        };
    }
}
