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

use Blnk\Model\Transaction;

/**
 * transactionExecutionResult is the outcome of running a
 * {@see TransactionExecutionPlan}: the mode that actually handled the work
 * and the resulting transaction (Go: `transactionExecutionResult`,
 * transaction.go; `usedCoalescing` is declared in transaction_execution.go).
 */
final class TransactionExecutionResult
{
    public string $mode;

    public ?Transaction $transaction;

    public function __construct(string $mode = '', ?Transaction $transaction = null)
    {
        $this->mode = $mode;
        $this->transaction = $transaction;
    }

    /**
     * usedCoalescing reports whether the work was handled by one of the
     * queued-batch (coalescing) modes rather than the single-transaction path.
     */
    public function usedCoalescing(): bool
    {
        return $this->mode === Blnk::transactionExecutionModeQueuedBatch || $this->mode === Blnk::transactionExecutionModeHotQueuedBatch;
    }
}
