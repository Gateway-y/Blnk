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

use Blnk\Model\BulkTransactionResult;

/**
 * BulkTransactionException is thrown by {@see TransactionBulk::createBulkTransactions()}
 * when synchronous bulk processing fails.
 *
 * Go returns BOTH a `*model.BulkTransactionResult{Status: "failed", Error: ...}`
 * and `errors.New(responseError)`, and the API handler reads `result.Error` /
 * `result.BatchID` from the result while the error is set. The PHP port keeps
 * the throw convention of PORTING.md and carries that result on the exception
 * (`$result`), whose message equals the result's `error` text.
 */
final class BulkTransactionException extends \RuntimeException
{
    public readonly BulkTransactionResult $result;

    public function __construct(BulkTransactionResult $result, ?\Throwable $previous = null)
    {
        parent::__construct($result->error, 0, $previous);
        $this->result = $result;
    }
}
