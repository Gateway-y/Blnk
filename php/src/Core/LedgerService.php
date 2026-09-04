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

use Blnk\Internal\ApiError\ApiErrorException;
use Blnk\Internal\Filter\QueryFilterSet;
use Blnk\Internal\Filter\QueryOptions;
use Blnk\Internal\Notification\Notification;
use Blnk\Model\Ledger;

/**
 * Port of ledger.go: the ledger methods of the Go `Blnk` struct, composed into
 * {@see Blnk}.
 */
trait LedgerService
{
    /**
     * postLedgerActions performs some actions after a ledger has been created.
     * It sends the newly created ledger to the search index queue, which indexes the ledger in Typesense.
     * It also sends a webhook notification.
     *
     * Parameters:
     * - $ledger: The newly created Ledger model.
     *
     * Go runs this in a goroutine; per PORTING.md ("Concurrency") the PHP port
     * runs it inline. Failures never reach the caller: they are reported
     * through Notification::notifyError, as in Go.
     */
    protected function postLedgerActions(Ledger $ledger): void
    {
        try {
            $this->queue->queueIndexData($ledger->ledgerID, 'ledgers', $ledger);
        } catch (\Throwable $err) {
            Notification::notifyError($err);
        }
        try {
            $this->sendWebhook(new NewWebhook('ledger.created', $ledger));
        } catch (\Throwable $err) {
            Notification::notifyError($err);
        }
    }

    /**
     * CreateLedger creates a new ledger.
     * It calls postLedgerActions after a successful creation.
     *
     * Parameters:
     * - $ledger: A Ledger model representing the ledger to be created.
     *
     * Returns the created Ledger model.
     *
     * @throws ApiErrorException if the ledger could not be created.
     */
    public function createLedger(Ledger $ledger): Ledger
    {
        $ledger = $this->datasource->createLedger($ledger);
        $this->postLedgerActions($ledger);
        return $ledger;
    }

    /**
     * GetAllLedgers retrieves all ledgers from the datasource.
     * It returns a slice of Ledger models and an error if the operation fails.
     *
     * @return Ledger[]
     * @throws ApiErrorException if the ledgers could not be retrieved.
     */
    public function getAllLedgers(int $limit, int $offset): array
    {
        return $this->datasource->getAllLedgers($limit, $offset);
    }

    /**
     * GetAllLedgersWithFilter retrieves ledgers from the datasource using advanced filters.
     * It returns a slice of Ledger models and an error if the operation fails.
     *
     * Parameters:
     * - $filters: A QueryFilterSet containing filter conditions.
     * - $limit: Maximum number of ledgers to return.
     * - $offset: Offset for pagination.
     *
     * @return Ledger[] Ledger models matching the filter criteria.
     * @throws ApiErrorException if the ledgers could not be retrieved.
     */
    public function getAllLedgersWithFilter(?QueryFilterSet $filters, int $limit, int $offset): array
    {
        return $this->datasource->getAllLedgersWithFilter($filters, $limit, $offset);
    }

    /**
     * GetAllLedgersWithFilterAndOptions retrieves ledgers with filters, sorting, and optional count.
     *
     * Parameters:
     * - $filters: A QueryFilterSet containing filter conditions.
     * - $opts: Query options including sorting and count settings.
     * - $limit: Maximum number of ledgers to return.
     * - $offset: Offset for pagination.
     *
     * Go returns `([]model.Ledger, *int64, error)`.
     *
     * @return array{0: Ledger[], 1: int|null} `[$ledgers, $totalCount]` — the
     *         total count of matching records is null unless `$opts->includeCount` is true.
     * @throws ApiErrorException if the ledgers could not be retrieved.
     */
    public function getAllLedgersWithFilterAndOptions(?QueryFilterSet $filters, ?QueryOptions $opts, int $limit, int $offset): array
    {
        return $this->datasource->getAllLedgersWithFilterAndOptions($filters, $opts, $limit, $offset);
    }

    /**
     * GetLedgerByID retrieves a ledger by its ID from the datasource.
     * It returns a pointer to the Ledger model and an error if the operation fails.
     *
     * Parameters:
     * - $id: A string representing the ID of the ledger to retrieve.
     *
     * @throws ApiErrorException if the ledger could not be retrieved.
     */
    public function getLedgerByID(string $id): Ledger
    {
        return $this->datasource->getLedgerByID($id);
    }

    /**
     * UpdateLedger updates an existing ledger's name.
     * It calls postLedgerActions after a successful update to handle indexing and webhooks.
     *
     * Parameters:
     * - $id: A string representing the ID of the ledger to update.
     * - $name: A string representing the new name for the ledger.
     *
     * Returns the updated Ledger model.
     *
     * @throws ApiErrorException if the ledger could not be updated.
     */
    public function updateLedger(string $id, string $name): Ledger
    {
        $ledger = $this->datasource->updateLedger($id, $name);
        $this->postLedgerActions($ledger);
        return $ledger;
    }
}
