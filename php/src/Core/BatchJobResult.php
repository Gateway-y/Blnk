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
 * BatchJobResult represents the result of processing a transaction in a batch job.
 *
 * Fields:
 * - Txn *model.Transaction: A pointer to the processed Transaction model.
 * - Error error: An error if the transaction could not be processed.
 *
 * (Go: `BatchJobResult`, transaction.go.)
 */
final class BatchJobResult
{
    /** A pointer to the processed Transaction model. */
    public ?Transaction $txn;

    /** An error if the transaction could not be processed. */
    public ?\Throwable $error;

    public function __construct(?Transaction $txn = null, ?\Throwable $error = null)
    {
        $this->txn = $txn;
        $this->error = $error;
    }
}
