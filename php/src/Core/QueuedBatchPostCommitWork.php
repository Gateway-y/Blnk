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

use Blnk\Model\Balance;
use Blnk\Model\LineageOutbox;
use Blnk\Model\Transaction;

/**
 * queuedBatchPostCommitWork is the shared persistence and post-commit work
 * shape used by both the single and the batched (coalesced) execution paths
 * (Go: `queuedBatchPostCommitWork`, transaction.go).
 *
 * Go passes this struct by value; PHP passes the object by handle. Every Go
 * call site uses the returned copy, so mutating in place and returning the
 * same object preserves the semantics.
 */
final class QueuedBatchPostCommitWork
{
    public ?Transaction $transaction;

    /** Can be null for rejected transactions (no balances were updated). */
    public ?Balance $sourceBalance;

    /** Can be null for rejected transactions (no balances were updated). */
    public ?Balance $destinationBalance;

    /** The lineage outbox entry to persist atomically with the transaction, if any. */
    public ?LineageOutbox $outbox;

    public function __construct(
        ?Transaction $transaction = null,
        ?Balance $sourceBalance = null,
        ?Balance $destinationBalance = null,
        ?LineageOutbox $outbox = null
    ) {
        $this->transaction = $transaction;
        $this->sourceBalance = $sourceBalance;
        $this->destinationBalance = $destinationBalance;
        $this->outbox = $outbox;
    }
}
