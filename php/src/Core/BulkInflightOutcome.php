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
 * BulkInflightOutcome is the per-item outcome from BulkInflightUpdate.
 * On success Err is nil; on failure Code carries a stable classification
 * (see classifyInflightError) and Err preserves the original message.
 *
 * (Go: `BulkInflightOutcome`, transaction_inflight.go.)
 */
final class BulkInflightOutcome
{
    public string $transactionID;

    public ?Transaction $txn;

    public ?\Throwable $err;

    public string $code;

    public function __construct(string $transactionID = '', ?Transaction $txn = null, ?\Throwable $err = null, string $code = '')
    {
        $this->transactionID = $transactionID;
        $this->txn = $txn;
        $this->err = $err;
        $this->code = $code;
    }
}
