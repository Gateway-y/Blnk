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

use Blnk\Config\Configuration;
use Blnk\Internal\ApiError\ApiErrorException;
use Blnk\Internal\Filter\QueryFilterSet;
use Blnk\Internal\Filter\QueryOptions;
use Blnk\Internal\Log;
use Blnk\Internal\Metrics\Metrics;
use Blnk\Internal\Notification\Notification;
use Blnk\Internal\Traces\Tracer;
use Blnk\Model\AlertCondition;
use Blnk\Model\Balance;
use Blnk\Model\BalanceMonitor;
use Blnk\Model\BalanceTracker;
use Blnk\Model\ModelHelpers;

/**
 * Port of balance.go: the balance and balance-monitor methods of the Go
 * `Blnk` struct (plus the package-level `NewBalanceTracker`), composed into
 * {@see Blnk}.
 */
trait BalanceService
{
    /**
     * balanceTracer is an OpenTelemetry tracer for tracking balance-related transactions.
     * (Go: `var balanceTracer = otel.Tracer("blnk.transactions")`.)
     */
    private static function balanceTracer(): Tracer
    {
        return Tracer::get('blnk.transactions');
    }

    /**
     * NewBalanceTracker creates a new BalanceTracker instance.
     * It initializes the Balances and Frequencies maps.
     *
     * Returns the newly created BalanceTracker instance.
     */
    public static function newBalanceTracker(): BalanceTracker
    {
        $bt = new BalanceTracker();
        $bt->balances = [];
        $bt->frequencies = [];
        return $bt;
    }

    /**
     * checkBalanceMonitors checks the balance monitors for a given updated balance.
     * It starts a tracing span, fetches the monitors, and checks each monitor's condition.
     * If a condition is met, it sends a webhook notification.
     *
     * Parameters:
     * - $updatedBalance: The updated Balance model.
     *
     * Go sends each webhook from a goroutine; the PHP port sends them inline.
     */
    protected function checkBalanceMonitors(Balance $updatedBalance): void
    {
        $span = self::balanceTracer()->startSpan('CheckBalanceMonitors');
        try {
            // Fetch monitors using cache (avoids DB query on every transaction)
            try {
                $monitors = $this->getBalanceMonitorsCached($updatedBalance->balanceID);
            } catch (\Throwable $err) {
                $span->recordError($err);
                Notification::notifyError($err);
                return;
            }

            // Check each monitor's condition
            foreach ($monitors as $monitor) {
                if ($monitor->checkCondition($updatedBalance)) {
                    self::spanEvent($span, sprintf('Condition met for balance: %s', $monitor->monitorID));
                    try {
                        $this->sendWebhook(new NewWebhook('balance.monitor', $monitor));
                    } catch (\Throwable $err) {
                        Notification::notifyError($err);
                    }
                }
            }
        } finally {
            $span->end();
        }
    }

    /**
     * getBalanceMonitorsCached retrieves balance monitors with caching.
     * It first checks the cache for monitors, and if not found, fetches from the database
     * and caches the result with a 5-minute TTL.
     *
     * Parameters:
     * - $balanceID: The ID of the balance to get monitors for.
     *
     * @return BalanceMonitor[] The monitors for the balance.
     * @throws ApiErrorException if the monitors could not be retrieved.
     */
    protected function getBalanceMonitorsCached(string $balanceID): array
    {
        $cacheKey = 'monitors:' . $balanceID;

        $monitors = null;
        try {
            $this->cache->get($cacheKey, $monitors);
        } catch (\Throwable) {
            // Go: `err == nil && monitors != nil` — any cache error falls through to the database.
            $monitors = null;
        }
        if (\is_array($monitors)) {
            return $monitors;
        }

        $monitors = $this->datasource->getBalanceMonitors($balanceID);

        // Go: a nil slice is replaced by an empty one before caching; PHP
        // arrays are never nil.

        try {
            $this->cache->set($cacheKey, $monitors, 5 * 60); // 5*time.Minute
        } catch (\Throwable) {
            // Go: `_ = l.cache.Set(...)` — cache write failures are ignored.
        }
        return $monitors;
    }

    /**
     * getOrCreateBalanceByIndicator retrieves a balance by its indicator and currency.
     * If the balance does not exist, it creates a new one.
     * It starts a tracing span, fetches or creates the balance, and records relevant events.
     * When EnableQueuedChecks is enabled in the transaction config, it will fetch the balance with queued data included.
     *
     * Parameters:
     * - $indicator: The indicator for the balance.
     * - $currency: The currency for the balance.
     *
     * Returns the Balance model.
     *
     * @throws \Throwable if the balance could not be retrieved or created.
     */
    protected function getOrCreateBalanceByIndicator(string $indicator, string $currency): Balance
    {
        $span = self::balanceTracer()->startSpan('GetOrCreateBalanceByIndicator');
        try {
            // Get configuration to check if queued checks are enabled
            try {
                $cfg = Configuration::fetch();
            } catch (\Throwable $err) {
                $span->recordError($err);
                Log::get()->error(sprintf('failed to fetch config: %s', $err->getMessage()));
                throw $err;
            }

            try {
                $balance = $this->datasource->getBalanceByIndicator($indicator, $currency);
            } catch (\Throwable) {
                self::spanEvent($span, 'Creating new balance');
                $balance = new Balance();
                $balance->indicator = $indicator;
                $balance->ledgerID = self::GeneralLedgerID;
                $balance->currency = $currency;
                try {
                    $this->createBalance($balance);
                } catch (\Throwable $err) {
                    if (!str_contains(self::goErrorString($err), 'Balance already exist')) {
                        $span->recordError($err);
                        throw $err;
                    }
                }
                try {
                    $balance = $this->datasource->getBalanceByIndicator($indicator, $currency);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    throw $err;
                }
                self::spanEvent($span, 'New balance created', ['balance.id' => $balance->balanceID]);

                // If queued checks are enabled, fetch the balance with queued data
                if ($cfg->transaction->enableQueuedChecks) {
                    try {
                        $balance = $this->datasource->getBalanceByID($balance->balanceID, [], true);
                    } catch (\Throwable $err) {
                        $span->recordError($err);
                        throw $err;
                    }
                }

                return $balance;
            }

            // If queued checks are enabled, fetch the balance with queued data
            if ($cfg->transaction->enableQueuedChecks) {
                try {
                    $balance = $this->datasource->getBalanceByID($balance->balanceID, [], true);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    throw $err;
                }
            }

            self::spanEvent($span, 'Balance found', ['balance.id' => $balance->balanceID]);
            return $balance;
        } finally {
            $span->end();
        }
    }

    /**
     * postBalanceActions performs some actions after a balance has been created.
     * It starts a tracing span, sends the balance to the search index queue, and sends a webhook notification.
     *
     * Parameters:
     * - $balance: The newly created Balance model.
     *
     * Go runs the actions in a goroutine; the PHP port runs them inline.
     * Failures never reach the caller: they are reported through
     * Notification::notifyError, as in Go.
     */
    protected function postBalanceActions(Balance $balance): void
    {
        $span = self::balanceTracer()->startSpan('PostBalanceActions');
        try {
            try {
                $this->queue->queueIndexData($balance->balanceID, 'balances', $balance);
            } catch (\Throwable $err) {
                $span->recordError($err);
                Notification::notifyError($err);
            }
            try {
                $this->sendWebhook(new NewWebhook('balance.created', $balance));
            } catch (\Throwable $err) {
                $span->recordError($err);
                Notification::notifyError($err);
            }
            self::spanEvent($span, 'Post balance actions completed', ['balance.id' => $balance->balanceID]);
        } finally {
            $span->end();
        }
    }

    /**
     * CreateBalance creates a new balance.
     * It starts a tracing span, creates the balance, and performs post-creation actions.
     *
     * Parameters:
     * - $balance: The Balance model to be created.
     *
     * Returns the created Balance model.
     *
     * @throws ApiErrorException if the balance could not be created.
     */
    public function createBalance(Balance $balance): Balance
    {
        $span = self::balanceTracer()->startSpan('CreateBalance');
        try {
            try {
                $balance = $this->datasource->createBalance($balance);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }
            $this->postBalanceActions($balance);
            Metrics::balanceCreatedTotal()->add(1);
            self::spanEvent($span, 'Balance created', ['balance.id' => $balance->balanceID]);
            return $balance;
        } finally {
            $span->end();
        }
    }

    /**
     * GetBalanceByID retrieves a balance by its ID.
     * It starts a tracing span, fetches the balance, and records relevant events.
     *
     * Parameters:
     * - $id: The ID of the balance to retrieve.
     * - $include: A slice of strings specifying additional data to include.
     * - $withQueued: Whether to include queued balance data.
     *
     * @param string[] $include
     * @throws ApiErrorException if the balance could not be retrieved.
     */
    public function getBalanceByID(string $id, array $include, bool $withQueued): Balance
    {
        $span = self::balanceTracer()->startSpan('GetBalanceByID');
        try {
            try {
                $balance = $this->datasource->getBalanceByID($id, $include, $withQueued);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }
            self::spanEvent($span, 'Balance retrieved', ['balance.id' => $id]);
            return $balance;
        } finally {
            $span->end();
        }
    }

    /**
     * GetAllBalances retrieves all balances.
     * It starts a tracing span, fetches all balances, and records relevant events.
     *
     * @return Balance[]
     * @throws ApiErrorException if the balances could not be retrieved.
     */
    public function getAllBalances(int $limit, int $offset): array
    {
        $span = self::balanceTracer()->startSpan('GetAllBalances');
        try {
            try {
                $balances = $this->datasource->getAllBalances($limit, $offset);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }
            self::spanEvent($span, 'All balances retrieved', ['balance.count' => \count($balances)]);
            return $balances;
        } finally {
            $span->end();
        }
    }

    /**
     * GetAllBalancesWithFilter retrieves balances using advanced filters.
     * It starts a tracing span, fetches balances matching the filter criteria, and records relevant events.
     *
     * Parameters:
     * - $filters: Filter conditions to apply.
     * - $limit: Maximum number of balances to return.
     * - $offset: Offset for pagination.
     *
     * @return Balance[] Balance models matching the filter criteria.
     * @throws ApiErrorException if the balances could not be retrieved.
     */
    public function getAllBalancesWithFilter(?QueryFilterSet $filters, int $limit, int $offset): array
    {
        $span = self::balanceTracer()->startSpan('GetAllBalancesWithFilter');
        try {
            try {
                $balances = $this->datasource->getAllBalancesWithFilter($filters, $limit, $offset);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }
            self::spanEvent($span, 'Balances with filter retrieved', ['balance.count' => \count($balances)]);
            return $balances;
        } finally {
            $span->end();
        }
    }

    /**
     * GetAllBalancesWithFilterAndOptions retrieves balances with advanced filters, sorting, and optional count.
     *
     * Go returns `([]model.Balance, *int64, error)`.
     *
     * @return array{0: Balance[], 1: int|null} `[$balances, $totalCount]`; the count is null unless `$opts->includeCount`.
     * @throws ApiErrorException if the balances could not be retrieved.
     */
    public function getAllBalancesWithFilterAndOptions(?QueryFilterSet $filters, ?QueryOptions $opts, int $limit, int $offset): array
    {
        $span = self::balanceTracer()->startSpan('GetAllBalancesWithFilterAndOptions');
        try {
            try {
                [$balances, $count] = $this->datasource->getAllBalancesWithFilterAndOptions($filters, $opts, $limit, $offset);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }
            self::spanEvent($span, 'Balances with filter and options retrieved', ['balance.count' => \count($balances)]);
            return [$balances, $count];
        } finally {
            $span->end();
        }
    }

    /**
     * CreateMonitor creates a new balance monitor.
     * It starts a tracing span, applies precision to the monitor's condition value, and creates the monitor.
     * It records relevant events and errors.
     *
     * Parameters:
     * - $monitor: The BalanceMonitor model to be created.
     *
     * Returns the created BalanceMonitor model.
     *
     * @throws ApiErrorException if the monitor could not be created.
     */
    public function createMonitor(BalanceMonitor $monitor): BalanceMonitor
    {
        $span = self::balanceTracer()->startSpan('CreateMonitor');
        try {
            // Go: Condition is a value struct, never nil.
            if ($monitor->condition === null) {
                $monitor->condition = new AlertCondition();
            }
            $amount = (int) ($monitor->condition->value * $monitor->condition->precision); // apply precision to value
            $amountBigInt = ModelHelpers::intToBigInteger($amount);
            $monitor->condition->preciseValue = $amountBigInt;
            try {
                $monitor = $this->datasource->createMonitor($monitor);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }

            try {
                $this->cache->delete('monitors:' . $monitor->balanceID);
            } catch (\Throwable) {
                // Go: `_ = l.cache.Delete(...)`
            }

            self::spanEvent($span, 'Monitor created', ['monitor.id' => $monitor->monitorID]);
            return $monitor;
        } finally {
            $span->end();
        }
    }

    /**
     * GetMonitorByID retrieves a balance monitor by its ID.
     * It starts a tracing span, fetches the monitor, and records relevant events.
     *
     * Parameters:
     * - $id: The ID of the monitor to retrieve.
     *
     * @throws ApiErrorException if the monitor could not be retrieved.
     */
    public function getMonitorByID(string $id): BalanceMonitor
    {
        $span = self::balanceTracer()->startSpan('GetMonitorByID');
        try {
            try {
                $monitor = $this->datasource->getMonitorByID($id);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }
            self::spanEvent($span, 'Monitor retrieved', ['monitor.id' => $id]);
            return $monitor;
        } finally {
            $span->end();
        }
    }

    /**
     * GetAllMonitors retrieves all balance monitors.
     * It starts a tracing span, fetches all monitors, and records relevant events.
     *
     * @return BalanceMonitor[]
     * @throws ApiErrorException if the monitors could not be retrieved.
     */
    public function getAllMonitors(): array
    {
        $span = self::balanceTracer()->startSpan('GetAllMonitors');
        try {
            try {
                $monitors = $this->datasource->getAllMonitors();
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }
            self::spanEvent($span, 'All monitors retrieved', ['monitor.count' => \count($monitors)]);
            return $monitors;
        } finally {
            $span->end();
        }
    }

    /**
     * GetBalanceMonitors retrieves all monitors for a given balance ID.
     * It starts a tracing span, fetches the monitors, and records relevant events.
     *
     * Parameters:
     * - $balanceID: The ID of the balance for which to retrieve monitors.
     *
     * @return BalanceMonitor[]
     * @throws ApiErrorException if the monitors could not be retrieved.
     */
    public function getBalanceMonitors(string $balanceID): array
    {
        $span = self::balanceTracer()->startSpan('GetBalanceMonitors');
        try {
            try {
                $monitors = $this->datasource->getBalanceMonitors($balanceID);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }
            self::spanEvent($span, 'Monitors retrieved for balance', ['balance.id' => $balanceID, 'monitor.count' => \count($monitors)]);
            return $monitors;
        } finally {
            $span->end();
        }
    }

    /**
     * UpdateMonitor updates an existing balance monitor.
     * It starts a tracing span, updates the monitor, and records relevant events and errors.
     *
     * Parameters:
     * - $monitor: The BalanceMonitor model to be updated.
     *
     * @throws ApiErrorException if the monitor could not be updated.
     */
    public function updateMonitor(BalanceMonitor $monitor): void
    {
        $span = self::balanceTracer()->startSpan('UpdateMonitor');
        try {
            try {
                $this->datasource->updateMonitor($monitor);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }

            try {
                $this->cache->delete('monitors:' . $monitor->balanceID);
            } catch (\Throwable) {
                // Go: `_ = l.cache.Delete(...)`
            }

            self::spanEvent($span, 'Monitor updated', ['monitor.id' => $monitor->monitorID]);
        } finally {
            $span->end();
        }
    }

    /**
     * DeleteMonitor deletes a balance monitor by its ID.
     * It starts a tracing span, deletes the monitor, and records relevant events and errors.
     *
     * Parameters:
     * - $id: The ID of the monitor to delete.
     *
     * @throws ApiErrorException if the monitor could not be deleted.
     */
    public function deleteMonitor(string $id): void
    {
        $span = self::balanceTracer()->startSpan('DeleteMonitor');
        try {
            try {
                $monitor = $this->datasource->getMonitorByID($id);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }

            try {
                $this->datasource->deleteMonitor($id);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }

            try {
                $this->cache->delete('monitors:' . $monitor->balanceID);
            } catch (\Throwable) {
                // Go: `_ = l.cache.Delete(...)`
            }

            self::spanEvent($span, 'Monitor deleted', ['monitor.id' => $id]);
        } finally {
            $span->end();
        }
    }

    /**
     * TakeBalanceSnapshots creates daily snapshots of balances in batches.
     * It accepts a batch size parameter to control the number of balances processed at once,
     * helping to manage memory usage for large datasets.
     *
     * Parameters:
     * - $batchSize: The number of balances to process in each batch
     *
     * Go runs the whole operation in a goroutine and reports nothing back to
     * the caller (errors are only logged); the PHP port runs it inline with the
     * same contract — it never throws.
     */
    public function takeBalanceSnapshots(int $batchSize): void
    {
        $startTime = microtime(true);

        // Log the start of snapshot operation
        Log::get()->info('Balance snapshot operation starting', [
            'batch_size' => $batchSize,
            'operation' => 'balance_snapshots',
            'status' => 'started',
            'timestamp' => (new \DateTimeImmutable('now'))->format(\DateTimeInterface::RFC3339),
        ]);

        $span = self::balanceTracer()->startSpan('TakeBalanceSnapshots');
        try {
            // Call the datasource method to create snapshots
            try {
                $total = $this->datasource->takeBalanceSnapshots($batchSize);
            } catch (\Throwable $err) {
                // Calculate duration
                $duration = microtime(true) - $startTime;

                // Log error with details
                Log::get()->error('Balance snapshot operation failed', [
                    'batch_size' => $batchSize,
                    'operation' => 'balance_snapshots',
                    'status' => 'failed',
                    'duration_ms' => (int) ($duration * 1000),
                    'error' => $err->getMessage(),
                ]);

                $span->recordError($err);
                return;
            }

            // Calculate duration
            $duration = microtime(true) - $startTime;
            $snapshotsPerSecond = fdiv((float) $total, $duration); // Go float division: Inf/NaN on a zero duration

            // Log successful completion with metrics
            Log::get()->info('Balance snapshot operation completed successfully', [
                'batch_size' => $batchSize,
                'operation' => 'balance_snapshots',
                'status' => 'completed',
                'total_snapshots' => $total,
                'duration_ms' => (int) ($duration * 1000),
                'snapshots_per_second' => $snapshotsPerSecond,
                'timestamp' => (new \DateTimeImmutable('now'))->format(\DateTimeInterface::RFC3339),
            ]);

            self::spanEvent($span, 'Balance snapshots created', [
                'total_snapshots' => $total,
                'batch_size' => $batchSize,
                'duration_ms' => (int) ($duration * 1000),
                'snapshots_per_second' => $snapshotsPerSecond,
            ]);
        } finally {
            $span->end();
        }
    }

    /**
     * GetBalanceAtTime retrieves a balance's state at a specific point in time.
     * It can either use balance snapshots for efficiency or calculate from all source transactions
     * based on the fromSource parameter.
     *
     * Parameters:
     * - $balanceID: The ID of the balance to retrieve.
     * - $targetTime: The point in time for which to retrieve the balance state.
     * - $fromSource: If true, calculates balance from all transactions instead of using snapshots.
     *
     * Returns the Balance model representing the state at the given time.
     *
     * Note: Go additionally guards against a nil balance with no error
     * ("no balance data found for time: %v"); the PHP datasource never returns
     * null (it throws NotFoundException instead), which surfaces here wrapped
     * as "failed to get balance at time: ...".
     *
     * @throws \RuntimeException if the historical balance state could not be retrieved.
     */
    public function getBalanceAtTime(string $balanceID, \DateTimeImmutable $targetTime, bool $fromSource): Balance
    {
        $span = self::balanceTracer()->startSpan('GetBalanceAtTime');
        try {
            $span->setAttribute('balance.id', $balanceID);
            $span->setAttribute('target.time', self::goTimeValueString($targetTime));
            $span->setAttribute('from.source', $fromSource);

            if ($fromSource) {
                self::spanEvent($span, 'Calculating balance from source transactions');
            } else {
                self::spanEvent($span, 'Using snapshots to calculate balance');
            }

            try {
                $balance = $this->datasource->getBalanceAtTime($balanceID, $targetTime, $fromSource);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw self::wrapError('failed to get balance at time', $err);
            }

            /** @phpstan-ignore-next-line Go: `if balance == nil` — unreachable with the non-null PHP datasource contract, kept for fidelity. */
            if ($balance === null) {
                self::spanEvent($span, 'No balance data found for the specified time');
                throw new \RuntimeException(sprintf('no balance data found for time: %s', self::goTimeValueString($targetTime)));
            }

            $calculationMethod = 'snapshot-based';
            if ($fromSource) {
                $calculationMethod = 'transaction-based';
            }

            self::spanEvent($span, 'Historical balance state retrieved', [
                'balance.id' => $balance->balanceID,
                'snapshot.time' => self::goTimeValueString($targetTime),
                'calculation.method' => $calculationMethod,
            ]);

            return $balance;
        } finally {
            $span->end();
        }
    }

    /**
     * GetBalanceByIndicator retrieves a balance by its indicator and currency.
     * It starts a tracing span, fetches the balance, and records relevant events.
     *
     * Parameters:
     * - $indicator: The indicator of the balance to retrieve.
     * - $currency: The currency of the balance to retrieve.
     *
     * @throws ApiErrorException if the balance could not be retrieved.
     */
    public function getBalanceByIndicator(string $indicator, string $currency): Balance
    {
        $span = self::balanceTracer()->startSpan('GetBalanceByIndicator');
        try {
            $span->setAttribute('balance.indicator', $indicator);
            $span->setAttribute('balance.currency', $currency);

            try {
                $balance = $this->datasource->getBalanceByIndicator($indicator, $currency);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }

            self::spanEvent($span, 'Balance retrieved by indicator', ['balance.id' => $balance->balanceID]);
            return $balance;
        } finally {
            $span->end();
        }
    }

    /**
     * UpdateBalanceIdentity updates only the identity_id associated with a balance.
     * It validates that both the balance and the identity exist before applying the change.
     *
     * Parameters:
     * - $balanceID: The ID of the balance whose identity reference should be modified.
     * - $identityID: The new identity ID to associate with the balance.
     *
     * @throws \RuntimeException if either the balance or identity records are not found or the update fails.
     */
    public function updateBalanceIdentity(string $balanceID, string $identityID): void
    {
        // Ensure the referenced identity exists
        try {
            $this->datasource->getIdentityByID($identityID);
        } catch (\Throwable $err) {
            throw self::wrapError('identity validation failed', $err);
        }

        // Ensure the balance exists (lite lookup)
        try {
            $this->datasource->getBalanceByIDLite($balanceID);
        } catch (\Throwable $err) {
            throw self::wrapError('balance validation failed', $err);
        }

        // Apply the update
        $this->datasource->updateBalanceIdentity($balanceID, $identityID);
    }

    /**
     * goTimeValueString formats a time the way Go's `time.Time.String()` /
     * `%v` does ("2006-01-02 15:04:05.999999999 -0700 MST"), at the
     * microsecond resolution PHP carries.
     */
    private static function goTimeValueString(\DateTimeImmutable $t): string
    {
        $s = $t->format('Y-m-d H:i:s');
        $us = $t->format('u');
        if ($us !== '000000') {
            $s .= '.' . rtrim($us, '0');
        }
        return $s . ' ' . $t->format('O T');
    }
}
