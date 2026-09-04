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

namespace Blnk\Api\Model;

/**
 * Package-level constants of api/model/transaction.go (the structs of that
 * file are {@see RecordTransaction}, {@see BulkTransactionRequest},
 * {@see InflightUpdate}, {@see BulkInflightVoidRequest},
 * {@see BulkInflightCommitItem}, {@see BulkInflightCommitRequest},
 * {@see BulkInflightResult} and {@see BulkInflightResponse}).
 */
final class Transaction
{
    /**
     * MaxBulkInflightItems caps the number of transactions accepted in a single
     * bulk commit or bulk void call. Bulk calls are processed synchronously, so
     * the cap exists to keep request latency and lock-holding bounded.
     */
    public const MaxBulkInflightItems = 100;

    /**
     * MaxBulkTransactionItems caps the number of transactions accepted in a single
     * CreateBulkTransactions request. The whole payload is held in memory, so the
     * cap bounds memory use; it is larger than the inflight cap because bulk
     * creates can run asynchronously.
     */
    public const MaxBulkTransactionItems = 10000;

    /**
     * MaxInstantReconciliationItems caps the number of external_transactions
     * accepted in a single instant-reconciliation request. The whole array is
     * held in memory, so the cap bounds memory use.
     */
    public const MaxInstantReconciliationItems = 10000;

    private function __construct()
    {
    }
}
