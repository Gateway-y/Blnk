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

namespace Blnk\Internal\Metrics;

/**
 * Port of Go `internal/metrics` (`metrics.go`) as simple in-memory
 * instruments, per PORTING.md. The Go package-level instrument variables
 * (`metrics.TransactionTotal.Add(...)`) become static accessors
 * (`Metrics::transactionTotal()->add(...)`). Instrument names, descriptions
 * and units are kept identical; nothing is exported (see
 * {@see MonitoringExporter} for the logging stub replacing the remote
 * exporter).
 */
final class Metrics
{
    private static bool $initialized = false;

    /**
     * TransactionTotal counts transactions that reach a terminal or significant state.
     * Attributes: status (APPLIED, REJECTED, INFLIGHT, VOID, COMMIT), currency
     */
    private static Counter $transactionTotal;

    /**
     * TransactionDuration records the wall-clock time of RecordTransaction().
     * Attributes: status
     */
    private static Histogram $transactionDuration;

    /**
     * TransactionRejectedTotal counts rejected transactions broken down by reason.
     * Attributes: reason (insufficient_funds, overdraft_limit, lock_contention, max_retries)
     */
    private static Counter $transactionRejectedTotal;

    /**
     * QueueEnqueuedTotal counts transactions enqueued for async processing.
     * Attributes: queue_name
     */
    private static Counter $queueEnqueuedTotal;

    /**
     * QueueProcessingDuration records the time spent processing a transaction in the worker.
     * Attributes: result (success, error, retry)
     */
    private static Histogram $queueProcessingDuration;

    /** BalanceCreatedTotal counts newly created balances. */
    private static Counter $balanceCreatedTotal;

    /** InflightCommitTotal counts committed inflight transactions. */
    private static Counter $inflightCommitTotal;

    /** InflightVoidTotal counts voided inflight transactions. */
    private static Counter $inflightVoidTotal;

    /** TransactionBatchSize records the number of transactions in a coalesced batch. */
    private static Histogram $transactionBatchSize;

    /**
     * TransactionBatchTotal counts coalescing attempts.
     * Attributes: result (success, failure, skipped)
     */
    private static Counter $transactionBatchTotal;

    /** HotpairsContentionTotal counts lock contention events. */
    private static Counter $hotpairsContentionTotal;

    /**
     * HotpairsLaneRoutedTotal counts transactions routed to queue lanes.
     * Attributes: lane (normal, hot)
     */
    private static Counter $hotpairsLaneRoutedTotal;

    /**
     * WorkerRetriesTotal counts worker retry events.
     * Attributes: reason (insufficient_funds, lock_contention, other)
     */
    private static Counter $workerRetriesTotal;

    /**
     * ChainBacklog is the number of transactions still waiting to be sealed into the
     * hash chain (the chainer's backlog).
     */
    private static Gauge $chainBacklog;

    /** ChainHeadSeq is the sequence number of the chain head — total transactions sealed. */
    private static Gauge $chainHeadSeq;

    /**
     * ChainLagSeconds is the age of the chain head: seconds since the chainer last
     * advanced it.
     */
    private static Gauge $chainLagSeconds;

    private function __construct()
    {
    }

    /**
     * Init creates all metric instruments. It should be called once during
     * application startup (idempotent — the Go package runs it in init()).
     * Safe to call even when observability is disabled.
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        self::$transactionTotal = new Counter(
            'blnk.transaction.total',
            'Total number of transactions by status and currency',
            '{transaction}'
        );

        self::$transactionDuration = new Histogram(
            'blnk.transaction.duration',
            'Duration of RecordTransaction processing',
            's'
        );

        self::$transactionRejectedTotal = new Counter(
            'blnk.transaction.rejected.total',
            'Total number of rejected transactions by reason',
            '{transaction}'
        );

        self::$queueEnqueuedTotal = new Counter(
            'blnk.queue.enqueued.total',
            'Total number of transactions enqueued for processing',
            '{transaction}'
        );

        self::$queueProcessingDuration = new Histogram(
            'blnk.queue.processing.duration',
            'Duration of worker transaction processing',
            's'
        );

        self::$balanceCreatedTotal = new Counter(
            'blnk.balance.created.total',
            'Total number of balances created',
            '{balance}'
        );

        self::$inflightCommitTotal = new Counter(
            'blnk.inflight.commit.total',
            'Total number of inflight transactions committed',
            '{transaction}'
        );

        self::$inflightVoidTotal = new Counter(
            'blnk.inflight.void.total',
            'Total number of inflight transactions voided',
            '{transaction}'
        );

        self::$transactionBatchSize = new Histogram(
            'blnk.transaction.batch.size',
            'Number of transactions in a coalesced batch',
            '{transaction}'
        );

        self::$transactionBatchTotal = new Counter(
            'blnk.transaction.batch.total',
            'Total number of batch coalescing attempts by result',
            '{batch}'
        );

        self::$hotpairsContentionTotal = new Counter(
            'blnk.hotpairs.contention.total',
            'Total number of lock contention events',
            '{event}'
        );

        self::$hotpairsLaneRoutedTotal = new Counter(
            'blnk.hotpairs.lane.routed.total',
            'Total number of transactions routed to queue lanes',
            '{transaction}'
        );

        self::$workerRetriesTotal = new Counter(
            'blnk.worker.retries.total',
            'Total number of worker retry events by reason',
            '{retry}'
        );

        self::$chainBacklog = new Gauge(
            'blnk.chain.backlog',
            'Number of transactions not yet sealed into the hash chain',
            '{transaction}'
        );

        self::$chainHeadSeq = new Gauge(
            'blnk.chain.head_seq',
            'Sequence number of the hash-chain head',
            '{transaction}'
        );

        self::$chainLagSeconds = new Gauge(
            'blnk.chain.lag_seconds',
            'Seconds since the hash chain last advanced',
            's'
        );
    }

    public static function transactionTotal(): Counter
    {
        self::init();
        return self::$transactionTotal;
    }

    public static function transactionDuration(): Histogram
    {
        self::init();
        return self::$transactionDuration;
    }

    public static function transactionRejectedTotal(): Counter
    {
        self::init();
        return self::$transactionRejectedTotal;
    }

    public static function queueEnqueuedTotal(): Counter
    {
        self::init();
        return self::$queueEnqueuedTotal;
    }

    public static function queueProcessingDuration(): Histogram
    {
        self::init();
        return self::$queueProcessingDuration;
    }

    public static function balanceCreatedTotal(): Counter
    {
        self::init();
        return self::$balanceCreatedTotal;
    }

    public static function inflightCommitTotal(): Counter
    {
        self::init();
        return self::$inflightCommitTotal;
    }

    public static function inflightVoidTotal(): Counter
    {
        self::init();
        return self::$inflightVoidTotal;
    }

    public static function transactionBatchSize(): Histogram
    {
        self::init();
        return self::$transactionBatchSize;
    }

    public static function transactionBatchTotal(): Counter
    {
        self::init();
        return self::$transactionBatchTotal;
    }

    public static function hotpairsContentionTotal(): Counter
    {
        self::init();
        return self::$hotpairsContentionTotal;
    }

    public static function hotpairsLaneRoutedTotal(): Counter
    {
        self::init();
        return self::$hotpairsLaneRoutedTotal;
    }

    public static function workerRetriesTotal(): Counter
    {
        self::init();
        return self::$workerRetriesTotal;
    }

    public static function chainBacklog(): Gauge
    {
        self::init();
        return self::$chainBacklog;
    }

    public static function chainHeadSeq(): Gauge
    {
        self::init();
        return self::$chainHeadSeq;
    }

    public static function chainLagSeconds(): Gauge
    {
        self::init();
        return self::$chainLagSeconds;
    }

    /**
     * Snapshot of every instrument's in-memory state, for debugging/inspection.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function snapshot(): array
    {
        self::init();
        $out = [];
        foreach ([
            self::$transactionTotal, self::$transactionRejectedTotal, self::$queueEnqueuedTotal,
            self::$balanceCreatedTotal, self::$inflightCommitTotal, self::$inflightVoidTotal,
            self::$transactionBatchTotal, self::$hotpairsContentionTotal,
            self::$hotpairsLaneRoutedTotal, self::$workerRetriesTotal,
        ] as $counter) {
            $out[$counter->name] = $counter->values();
        }
        foreach ([self::$transactionDuration, self::$queueProcessingDuration, self::$transactionBatchSize] as $histogram) {
            $out[$histogram->name] = $histogram->values();
        }
        foreach ([self::$chainBacklog, self::$chainHeadSeq, self::$chainLagSeconds] as $gauge) {
            $out[$gauge->name] = $gauge->values();
        }
        return $out;
    }
}
