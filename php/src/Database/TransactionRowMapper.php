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
use Blnk\Internal\ApiError\ErrorCode;
use Blnk\Internal\Log;
use Blnk\Model\ModelHelpers;
use Blnk\Model\Transaction;
use Brick\Math\BigInteger;

/**
 * TransactionRowMapper gathers the row-scanning and value-encoding helpers
 * shared by the transaction traits ({@see TransactionRepository},
 * {@see TransactionQueriesRepository}, {@see TransactionInflightRepository},
 * {@see TransactionQueueRepository}, {@see TransactionRecoveryRepository},
 * {@see TransactionRefundsRepository}).
 *
 * The Go code scans every `blnk.transactions` row inline with `rows.Scan(...)`
 * followed by `json.Unmarshal(metaDataJSON, &txn.MetaData)` and
 * `parseBigInt(preciseAmountStr)`; the PHP port centralises the scan in
 * {@see scanTransaction()} and keeps the two parsing steps separate
 * ({@see unmarshalMetaData()}, {@see parseBigInt()}) because each Go method
 * wraps their failures with its own error message and ordering.
 *
 * It lives in its own class (rather than as private trait methods) so that
 * the traits composed into {@see Datasource} never define colliding helper
 * names.
 *
 * Column/type conventions (see PORTING.md "Types"):
 * - `precise_amount` (NUMERIC) is read as a string and parsed into a
 *   {@see BigInteger} exactly like Go's `new(big.Int).SetString(s, 10)`.
 * - TIMESTAMP columns are naive UTC values; they are read as
 *   `\DateTimeImmutable` in UTC and written as `Y-m-d H:i:s.u`. A stored Go
 *   zero time (`0001-01-01 00:00:00`) maps to a null property, which is the
 *   model's representation of Go's zero `time.Time`.
 * - `meta_data` (JSONB) is decoded into an associative array with the same
 *   acceptance rules as `json.Unmarshal` into `map[string]interface{}`.
 */
final class TransactionRowMapper
{
    /** Go's zero `time.Time` as stored in a naive TIMESTAMP column. */
    public const ZERO_TIME = '0001-01-01 00:00:00';

    /** Format used to bind `\DateTimeImmutable` values to TIMESTAMP parameters (PORTING.md). */
    public const DB_TIME_FORMAT = 'Y-m-d H:i:s.u';

    /** The `sql.ErrNoRows` error text, used as the `details` of not-found API errors. */
    public const ERR_NO_ROWS = 'sql: no rows in result set';

    /** Not instantiable: static helpers only. */
    private function __construct()
    {
    }

    /**
     * scanTransaction fills a {@see Transaction} from an associative row (the
     * counterpart of `rows.Scan(&txn.TransactionID, ...)`). Only the columns
     * present in `$row` are assigned, so each query's SELECT list decides which
     * fields are populated — exactly as unscanned Go fields keep their zero
     * value. `meta_data` and `precise_amount` are deliberately NOT decoded
     * here; see {@see unmarshalMetaData()} and {@see parseBigInt()}.
     *
     * Divergence (documented): Go's `Scan` into a `string` fails on a SQL NULL
     * ("converting NULL to string is unsupported"); this port maps NULL text
     * columns to the empty string and NULL numeric columns to zero instead.
     *
     * @param array<string, mixed> $row
     */
    public static function scanTransaction(array $row): Transaction
    {
        $txn = new Transaction();

        if (\array_key_exists('id', $row)) {
            $txn->id = (int) $row['id'];
        }
        if (\array_key_exists('transaction_id', $row)) {
            $txn->transactionID = self::str($row['transaction_id']);
        }
        if (\array_key_exists('parent_transaction', $row)) {
            $txn->parentTransaction = self::str($row['parent_transaction']);
        }
        if (\array_key_exists('source', $row)) {
            $txn->source = self::str($row['source']);
        }
        if (\array_key_exists('reference', $row)) {
            $txn->reference = self::str($row['reference']);
        }
        if (\array_key_exists('amount', $row)) {
            $txn->amount = $row['amount'] === null ? 0.0 : (float) $row['amount'];
        }
        if (\array_key_exists('precision', $row)) {
            $txn->precision = $row['precision'] === null ? 0.0 : (float) $row['precision'];
        }
        if (\array_key_exists('currency', $row)) {
            $txn->currency = self::str($row['currency']);
        }
        if (\array_key_exists('destination', $row)) {
            $txn->destination = self::str($row['destination']);
        }
        if (\array_key_exists('description', $row)) {
            $txn->description = self::str($row['description']);
        }
        if (\array_key_exists('status', $row)) {
            $txn->status = self::str($row['status']);
        }
        if (\array_key_exists('hash', $row)) {
            $txn->hash = self::str($row['hash']);
        }
        if (\array_key_exists('created_at', $row)) {
            $txn->createdAt = self::parseTimestamp(self::strOrNull($row['created_at']));
        }
        if (\array_key_exists('scheduled_for', $row)) {
            $txn->scheduledFor = self::parseTimestamp(self::strOrNull($row['scheduled_for']));
        }
        if (\array_key_exists('effective_date', $row)) {
            $txn->effectiveDate = self::parseTimestamp(self::strOrNull($row['effective_date']));
        }

        return $txn;
    }

    /**
     * unmarshalMetaData mirrors `json.Unmarshal(metaDataJSON, &txn.MetaData)`
     * where `MetaData` is a `map[string]interface{}`:
     * - a SQL NULL scans into a nil `[]byte`, which Go rejects with
     *   "unexpected end of JSON input";
     * - the JSON literal `null` yields a nil map (null);
     * - a JSON object yields the map (associative array);
     * - any other JSON value (array, string, number, bool) is rejected with
     *   Go's "json: cannot unmarshal <kind> into Go value of type
     *   map[string]interface {}" message (e.g. legacy array-shaped meta_data).
     *
     * @param mixed $json The raw `meta_data` cell as returned by PDO (string or null).
     * @return array<string, mixed>|null
     * @throws \JsonException on any of the failures above (callers wrap it with
     *   the Go method's own "Failed to unmarshal metadata" error).
     */
    public static function unmarshalMetaData(mixed $json): ?array
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
            return null; // JSON null → nil map
        }
        if ($trimmed[0] !== '{' || !\is_array($decoded)) {
            throw new \JsonException(sprintf(
                'json: cannot unmarshal %s into Go value of type map[string]interface {}',
                self::jsonKind($trimmed[0])
            ));
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * marshalMetaData mirrors `json.Marshal(txn.MetaData)` for a
     * `map[string]interface{}`: a nil map encodes as `null`, an empty map as
     * `{}`, and a populated map always as a JSON object (never a list, even
     * when the PHP array happens to have sequential integer keys).
     *
     * @param array<string, mixed>|null $metaData
     * @throws \JsonException when the value cannot be encoded (Go: "Failed to marshal metadata").
     */
    public static function marshalMetaData(?array $metaData): string
    {
        $value = ModelHelpers::mapToJson($metaData);
        if (\is_array($value) && array_is_list($value)) {
            $value = (object) $value;
        }
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * parseBigInt is the port of Go `database.parseBigInt` (balance.go):
     * `new(big.Int).SetString(s, 10)` — an optional sign followed by decimal
     * digits only; anything else (empty string, exponent, fraction, spaces)
     * fails with `invalid big.Int value: "<s>"`.
     *
     * @throws \RuntimeException when the string is not a base-10 integer.
     */
    public static function parseBigInt(string $s): BigInteger
    {
        if (preg_match('/^[+-]?[0-9]+$/', $s) !== 1) {
            throw new \RuntimeException(sprintf('invalid big.Int value: "%s"', $s));
        }
        return BigInteger::of($s);
    }

    /**
     * parseTimestamp reads a naive TIMESTAMP column value (UTC) into a
     * `\DateTimeImmutable`. NULL, and the stored Go zero time
     * (`0001-01-01 00:00:00`), both map to null — the model's representation of
     * Go's zero `time.Time` (see {@see Transaction}).
     */
    public static function parseTimestamp(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $t = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        if ($t->format('Y-m-d H:i:s') === self::ZERO_TIME) {
            return null;
        }
        return $t;
    }

    /**
     * formatTimestamp encodes a non-pointer Go `time.Time` argument
     * (`txn.CreatedAt.UTC()`, `txn.ScheduledFor.UTC()`): the value is
     * normalised to UTC, and a null property (Go's zero time) is written as
     * `0001-01-01 00:00:00.000000`, exactly what lib/pq would send.
     */
    public static function formatTimestamp(?\DateTimeImmutable $t): string
    {
        if ($t === null) {
            return self::ZERO_TIME . '.000000';
        }
        return $t->setTimezone(new \DateTimeZone('UTC'))->format(self::DB_TIME_FORMAT);
    }

    /**
     * formatOptionalTimestamp encodes a Go `*time.Time` argument: nil is a SQL
     * NULL, otherwise the UTC-normalised value.
     */
    public static function formatOptionalTimestamp(?\DateTimeImmutable $t): ?string
    {
        if ($t === null) {
            return null;
        }
        return $t->setTimezone(new \DateTimeZone('UTC'))->format(self::DB_TIME_FORMAT);
    }

    /**
     * toBool converts a scanned Postgres boolean (pdo_pgsql returns PHP bools
     * for `boolean` columns, but 't'/'f' or '1'/'0' may appear depending on the
     * driver settings) into a PHP bool — the counterpart of `Scan(&exists)`.
     */
    public static function toBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    /**
     * pgTextArray encodes a list of strings as a Postgres `text[]` literal —
     * the counterpart of `pq.Array([]string)` bound to `= ANY($1)`.
     *
     * @param string[] $values
     */
    public static function pgTextArray(array $values): string
    {
        $parts = [];
        foreach ($values as $value) {
            $parts[] = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value) . '"';
        }
        return '{' . implode(',', $parts) . '}';
    }

    /**
     * copyLine encodes one row for `COPY ... FROM STDIN` in the text format
     * (tab-delimited, `\N` for NULL, backslash / tab / newline / carriage-return
     * escaped) — the counterpart of lib/pq's `CopyIn` row encoding.
     *
     * @param array<int, string|int|float|bool|null> $values
     */
    public static function copyLine(array $values): string
    {
        $fields = [];
        foreach ($values as $value) {
            if ($value === null) {
                $fields[] = '\\N';
                continue;
            }
            if (\is_bool($value)) {
                $value = $value ? 't' : 'f';
            }
            $fields[] = str_replace(
                ['\\', "\t", "\n", "\r"],
                ['\\\\', '\\t', '\\n', '\\r'],
                (string) $value
            );
        }
        return implode("\t", $fields) . "\n";
    }

    /**
     * apiError is the counterpart of `apierror.NewAPIError(code, message,
     * details)` at the repository boundary: it logs the error details exactly
     * like the Go constructor, then builds the exception class PORTING.md
     * prescribes for the situation —
     * - {@see NotFoundException} for `ErrNotFound` (Go: `sql.ErrNoRows`),
     * - {@see DatabaseException} (message + SQLSTATE preserved, so callers can
     *   check `isUniqueViolation()` / SQLSTATE 23505) when the details are a
     *   `\PDOException` and the code is `ErrInternalServer`,
     * - a plain {@see ApiErrorException} otherwise.
     *
     * A `\Throwable` passed as details becomes the `previous` exception; its
     * message is kept as the `details` payload.
     */
    public static function apiError(string $code, string $message, mixed $details = null): ApiErrorException
    {
        $previous = $details instanceof \Throwable ? $details : null;
        $detailValue = $details instanceof \Throwable ? $details->getMessage() : $details;

        // Log the error details for monitoring and debugging. (Go: apierror.NewAPIError)
        Log::get()->error('API error', ['details' => $detailValue]);

        if ($code === ErrorCode::ErrNotFound) {
            return new NotFoundException($message, $detailValue, $previous);
        }
        if ($code === ErrorCode::ErrInternalServer && $details instanceof \PDOException) {
            return new DatabaseException($message, self::sqlState($details), $detailValue, $details);
        }
        return new ApiErrorException($code, $message, $detailValue, $previous);
    }

    /**
     * errorString mirrors Go's `err.Error()` for `fmt.Errorf("...: %w", err)`
     * wrapping: an API error prints as "CODE: message", anything else as its
     * plain message.
     */
    public static function errorString(\Throwable $err): string
    {
        if ($err instanceof ApiErrorException) {
            return $err->error();
        }
        return $err->getMessage();
    }

    /**
     * rollback is the counterpart of Go's `defer func(tx *sql.Tx) { _ =
     * tx.Rollback() }(tx)`: it rolls the open PDO transaction back and swallows
     * any failure (after a successful commit there is nothing to roll back).
     */
    public static function rollback(\PDO $tx): void
    {
        try {
            if ($tx->inTransaction()) {
                $tx->rollBack();
            }
        } catch (\PDOException) {
            // Go ignores the rollback error (`_ = tx.Rollback()`).
        }
    }

    /**
     * The five-character SQLSTATE carried by a PDOException, when known
     * (pdo_pgsql puts it in errorInfo[0] / the exception code).
     */
    public static function sqlState(\PDOException $e): ?string
    {
        if (isset($e->errorInfo[0]) && \is_string($e->errorInfo[0]) && $e->errorInfo[0] !== '') {
            return $e->errorInfo[0];
        }
        $code = $e->getCode();
        if (\is_string($code) && $code !== '') {
            return $code;
        }
        return null;
    }

    private static function str(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    private static function strOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /** The Go `json` package's name for the kind of value a JSON text starts with. */
    private static function jsonKind(string $firstChar): string
    {
        return match (true) {
            $firstChar === '[' => 'array',
            $firstChar === '"' => 'string',
            $firstChar === 't', $firstChar === 'f' => 'bool',
            $firstChar === '-', ctype_digit($firstChar) => 'number',
            default => 'value',
        };
    }
}
