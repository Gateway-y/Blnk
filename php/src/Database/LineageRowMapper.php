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

use Blnk\Model\LineageMapping;
use Blnk\Model\LineageOutbox;

/**
 * LineageRowMapper gathers the row-scanning and value-encoding helpers of
 * {@see LineageRepository} (the port of database/lineage.go): the
 * counterparts of the inline `rows.Scan(&mapping.ID, ...)` /
 * `row.Scan(&entry.ID, ...)` calls (with their `sql.NullString` /
 * `sql.NullTime` handling), of `time.Duration.String()` (the lock duration
 * bound as a Postgres interval) and of the nanosecond-offset `created_at`
 * values of the batch outbox insert.
 *
 * It lives in its own class (rather than as private trait methods) so that
 * the traits composed into {@see Datasource} never define colliding helper
 * names.
 */
final class LineageRowMapper
{
    /** Not instantiable: static helpers only. */
    private function __construct()
    {
    }

    /**
     * scanLineageMapping fills a {@see LineageMapping} from an
     * `id, balance_id, provider, shadow_balance_id, aggregate_balance_id,
     * identity_id, created_at` row (`rows.Scan(&mapping.ID, &mapping.BalanceID, ...)`).
     *
     * @param array<string, mixed> $row
     * @throws \InvalidArgumentException when a column cannot be converted (Go: Scan error).
     */
    public static function scanLineageMapping(array $row): LineageMapping
    {
        $mapping = new LineageMapping();
        $mapping->id = RowScanner::toInt($row['id'] ?? null);
        $mapping->balanceID = RowScanner::toString($row['balance_id'] ?? null);
        $mapping->provider = RowScanner::toString($row['provider'] ?? null);
        $mapping->shadowBalanceID = RowScanner::toString($row['shadow_balance_id'] ?? null);
        $mapping->aggregateBalanceID = RowScanner::toString($row['aggregate_balance_id'] ?? null);
        $mapping->identityID = RowScanner::toString($row['identity_id'] ?? null);
        $mapping->createdAt = RowScanner::toTime($row['created_at'] ?? null);
        return $mapping;
    }

    /**
     * scanLineageOutbox fills a {@see LineageOutbox} from an
     * `id, transaction_id, source_balance_id, destination_balance_id, provider,
     * lineage_type, payload, status, attempts, max_attempts, last_error,
     * created_at, processed_at, locked_until, inflight` row.
     *
     * Go scans `source_balance_id`, `destination_balance_id`, `provider` and
     * `last_error` into `sql.NullString` (NULL → "") and `processed_at` /
     * `locked_until` into `sql.NullTime` (NULL → nil `*time.Time`); `payload`
     * is a `json.RawMessage`, kept as the raw JSON text of the JSONB column.
     *
     * @param array<string, mixed> $row
     * @throws \InvalidArgumentException when a column cannot be converted (Go: Scan error).
     */
    public static function scanLineageOutbox(array $row): LineageOutbox
    {
        $entry = new LineageOutbox();
        $entry->id = RowScanner::toInt($row['id'] ?? null);
        $entry->transactionID = RowScanner::toString($row['transaction_id'] ?? null);
        $entry->sourceBalanceID = RowScanner::toString($row['source_balance_id'] ?? null);           // sql.NullString.String
        $entry->destinationBalanceID = RowScanner::toString($row['destination_balance_id'] ?? null); // sql.NullString.String
        $entry->provider = RowScanner::toString($row['provider'] ?? null);                           // sql.NullString.String
        $entry->lineageType = RowScanner::toString($row['lineage_type'] ?? null);
        $payload = $row['payload'] ?? null;
        $entry->payload = $payload === null ? null : (string) $payload;
        $entry->status = RowScanner::toString($row['status'] ?? null);
        $entry->attempts = RowScanner::toInt($row['attempts'] ?? null);
        $entry->maxAttempts = RowScanner::toInt($row['max_attempts'] ?? null);
        $entry->lastError = RowScanner::toString($row['last_error'] ?? null);                        // sql.NullString.String
        $entry->createdAt = RowScanner::toTime($row['created_at'] ?? null);
        $entry->processedAt = RowScanner::toTime($row['processed_at'] ?? null);                      // sql.NullTime → *time.Time
        $entry->lockedUntil = RowScanner::toTime($row['locked_until'] ?? null);                      // sql.NullTime → *time.Time
        $entry->inflight = RowScanner::toBool($row['inflight'] ?? null);
        return $entry;
    }

    /**
     * goDurationString renders a duration given in seconds exactly like Go's
     * `time.Duration.String()` ("72h3m0.5s"; sub-second durations use the
     * smaller units, e.g. "1.5ms", and zero is "0s"), which is the text
     * `ClaimPendingOutboxEntries` binds to `NOW() + $2::interval` — PostgreSQL's
     * interval parser understands the `h` / `m` / `s` / `ms` unit suffixes.
     *
     * @param int|float $seconds Go `time.Duration`, expressed in seconds.
     */
    public static function goDurationString(int|float $seconds): string
    {
        // u := uint64(d) in nanoseconds
        $u = (int) round($seconds * 1_000_000_000);
        $neg = $u < 0;
        if ($neg) {
            $u = -$u;
        }

        if ($u < 1_000_000_000) {
            // Special case: if duration is smaller than a second,
            // use smaller units, like 1.2ms
            if ($u === 0) {
                return '0s';
            }
            if ($u < 1_000) {
                $prec = 0;
                $unit = 'ns';
            } elseif ($u < 1_000_000) {
                $prec = 3;
                $unit = "\u{00B5}s"; // U+00B5 'µ' micro sign
            } else {
                $prec = 6;
                $unit = 'ms';
            }
            [$frac, $u] = self::fmtFrac($u, $prec);
            return ($neg ? '-' : '') . $u . $frac . $unit;
        }

        [$frac, $u] = self::fmtFrac($u, 9);

        // u is now integer seconds
        $out = ($u % 60) . $frac . 's';
        $u = intdiv($u, 60);

        // u is now integer minutes
        if ($u > 0) {
            $out = ($u % 60) . 'm' . $out;
            $u = intdiv($u, 60);

            // u is now integer hours
            // Stop at hours because days can be different lengths.
            if ($u > 0) {
                $out = $u . 'h' . $out;
            }
        }

        return ($neg ? '-' : '') . $out;
    }

    /**
     * fmtFrac is Go's `fmtFrac`: it formats the fraction of v/10**prec (e.g.
     * ".1234"), omitting trailing zeros and the decimal point when the fraction
     * is zero, and returns it together with v/10**prec.
     *
     * @return array{0: string, 1: int} `[$fraction, $integerPart]`
     */
    private static function fmtFrac(int $v, int $prec): array
    {
        // Omit trailing zeros up until and including decimal point.
        $print = false;
        $digits = '';
        for ($i = 0; $i < $prec; $i++) {
            $digit = $v % 10;
            $print = $print || $digit !== 0;
            if ($print) {
                $digits = $digit . $digits;
            }
            $v = intdiv($v, 10);
        }
        return [$print ? '.' . $digits : '', $v];
    }

    /**
     * timestampWithNanos renders `now.Add(time.Duration(nanos) * time.Nanosecond)`
     * the way lib/pq encodes a `time.Time` parameter
     * (`"2006-01-02 15:04:05.999999999Z07:00"`: up to nine fractional digits,
     * trailing zeros trimmed, offset appended). PHP timestamps carry
     * microseconds only, so the nanosecond offset is folded into the textual
     * fraction; PostgreSQL rounds it to microseconds on input exactly as it
     * rounds the value Go sends.
     */
    public static function timestampWithNanos(\DateTimeImmutable $now, int $nanos): string
    {
        $totalNanos = ((int) $now->format('u')) * 1000 + $nanos;
        $carrySeconds = intdiv($totalNanos, 1_000_000_000);
        $fracNanos = $totalNanos % 1_000_000_000;
        if ($carrySeconds > 0) {
            $now = $now->modify(sprintf('+%d seconds', $carrySeconds));
        }
        $frac = rtrim(str_pad((string) $fracNanos, 9, '0', STR_PAD_LEFT), '0');
        return $now->format('Y-m-d H:i:s') . ($frac !== '' ? '.' . $frac : '') . $now->format('P');
    }
}
