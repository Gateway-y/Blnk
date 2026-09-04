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

namespace Blnk\Api;

use Blnk\Api\Model\BulkInflightCommitRequest;
use Blnk\Api\Model\BulkInflightResponse;
use Blnk\Api\Model\BulkInflightResult;
use Blnk\Api\Model\BulkInflightVoidRequest;
use Blnk\Api\Model\BulkTransactionRequest;
use Blnk\Api\Model\InflightUpdate;
use Blnk\Api\Model\JsonBinding;
use Blnk\Api\Model\ModelHelpers as ApiModelHelpers;
use Blnk\Api\Model\PrecisionMustBeIntegerException;
use Blnk\Api\Model\RecordTransaction;
use Blnk\Api\Model\ValidationErrors;
use Blnk\Config\Configuration;
use Blnk\Core\Blnk;
use Blnk\Core\BulkInflightAction;
use Blnk\Core\BulkInflightItem;
use Blnk\Core\BulkInflightOutcome;
use Blnk\Core\BulkTransactionException;
use Blnk\Core\InflightActionQueuedException;
use Blnk\Core\Queue;
use Blnk\Database\Datasource;
use Blnk\Internal\ApiError\ApiErrorException;
use Blnk\Internal\ApiError\ErrorCode;
use Blnk\Internal\ApiError\ErrorResponse;
use Blnk\Internal\Log;
use Blnk\Model\Distribution;
use Blnk\Model\ModelHelpers;
use Blnk\Model\Transaction;
use Brick\Math\BigInteger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Port of api/transactions.go: the transaction handlers of the Go `Api`
 * struct, composed into {@see Api} (which holds the service as `$this->blnk`).
 *
 * Gin's `c.Params.Get`, `c.ShouldBindJSON` / `c.BindJSON`, `c.Query` and
 * `c.JSON` map to {@see Query}, {@see Binding}, and {@see Json}; the
 * `respondCode` / `respondError` helpers of errors.go live in {@see Errors}.
 * Go's `err.Error()` renderings go through {@see Errors::errorString()},
 * except for the `ErrInflightActionQueued` sentinel, which is a plain error
 * in Go but an ApiErrorException in the PHP core (see transactionErrorString).
 *
 * Package-level helpers of the Go file (transformTransaction,
 * handleRecordTransactionValidationError, toAPIResults) are private members
 * of this trait; Go's `time.ParseDuration` / `Duration.String`, which the
 * recovery handler relies on, are ported as goParseDuration / goDurationString.
 */
trait TransactionHandlers
{
    /**
     * bulkInflightMaxWorkers caps the worker-pool size used by the bulk handlers.
     * 8 is a deliberate compromise: large enough to win meaningful concurrency
     * vs sequential N round-trips, small enough not to flood the lock service or
     * connection pool when many bulk calls are in flight.
     */
    private const bulkInflightMaxWorkers = 8;

    /** Go: 2 * time.Minute, in nanoseconds (the default recovery threshold). */
    private const recoveryDefaultThresholdNs = 120000000000;

    /**
     * transformTransaction prepares a transaction for API response by ensuring
     * that metadata fields are properly represented as first-class fields.
     * This maintains backward compatibility while keeping responses clean.
     */
    private static function transformTransaction(?Transaction $txn): Transaction
    {
        if ($txn === null) {
            return new Transaction();
        }

        // Deep copy instead of shallow copy
        $result = clone $txn;

        // Deep copy the slices to avoid race conditions
        $result->sources = array_map(static fn (Distribution $d): Distribution => clone $d, array_values($txn->sources));
        $result->destinations = array_map(static fn (Distribution $d): Distribution => clone $d, array_values($txn->destinations));

        // Deep copy the metadata map (PHP arrays are value types; the clone
        // already holds an independent copy)
        if ($txn->metaData !== null) {
            $result->metaData = $txn->metaData;
        }

        // Check for inflight flag in metadata and move it to the main field
        if ($result->metaData !== null) {
            if (\array_key_exists('inflight', $result->metaData)) {
                $inflightVal = $result->metaData['inflight'];
                if (\is_bool($inflightVal) && $inflightVal) {
                    $result->inflight = true;
                    // Remove from metadata to avoid duplication
                    unset($result->metaData['inflight']);
                }
            }

            // If metadata is now empty, set it to nil
            if (\count($result->metaData) === 0) {
                $result->metaData = null;
            }
        }

        return $result;
    }

    /**
     * transactionErrorString renders an exception the way Go's `err.Error()`
     * prints it. The `ErrInflightActionQueued` sentinel is a plain
     * `errors.New` in Go; its PHP port extends ApiErrorException, so it is
     * rendered by message only.
     */
    private static function transactionErrorString(\Throwable $err): string
    {
        if ($err instanceof InflightActionQueuedException) {
            return $err->getMessage();
        }

        return Errors::errorString($err);
    }

    /**
     * isInflightActionQueuedError is `errors.Is(err, blnk.ErrInflightActionQueued)`:
     * the whole wrap (previous) chain is inspected.
     */
    private static function isInflightActionQueuedError(\Throwable $err): bool
    {
        for ($e = $err; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof InflightActionQueuedException) {
                return true;
            }
        }

        return false;
    }

    /**
     * isPrecisionMustBeIntegerError is `errors.Is(err, model2.ErrPrecisionMustBeInteger)`.
     */
    private static function isPrecisionMustBeIntegerError(\Throwable $err): bool
    {
        for ($e = $err; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof PrecisionMustBeIntegerException) {
                return true;
            }
        }

        return false;
    }

    private function handleRecordTransactionValidationError(ResponseInterface $response, \Throwable $err): ResponseInterface
    {
        if ($err instanceof ValidationErrors) {
            // Go indexes the ozzo error map with the struct field name
            // ("Precision") while ValidateStruct keys it by the JSON tag name
            // ("precision"), so — exactly as in Go — this lookup never matches
            // and the sentinel surfaces through the generic validation
            // response below ("precision: precision must be an integer value.").
            $precisionErr = $err['Precision'];
            if ($precisionErr !== null && self::isPrecisionMustBeIntegerError($precisionErr)) {
                return Errors::respondCode($response, ErrorCode::ErrTxnPrecisionNotInteger, $precisionErr->getMessage(), null);
            }
        }

        // validation.Errors does not unwrap to its members, so this only
        // matches a directly returned sentinel.
        if (self::isPrecisionMustBeIntegerError($err)) {
            return Errors::respondCode($response, ErrorCode::ErrTxnPrecisionNotInteger, Errors::errorString($err), null);
        }

        return Errors::respondCode($response, ErrorCode::ErrTxnValidation, Errors::errorString($err), null, Errors::withLegacyKey('errors'));
    }

    /**
     * QueueTransaction handles queuing a new transaction for later processing.
     * It binds the incoming JSON request to a RecordTransaction object, validates it,
     * and then queues the transaction. If any errors occur during validation or queuing,
     * it responds with an appropriate error message.
     *
     * Responses:
     * - 400 Bad Request: If there's an error in binding JSON or validating the transaction.
     * - 201 Created: If the transaction is successfully queued.
     */
    public function queueTransaction(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        // Bind the incoming JSON request to the newTransaction model
        try {
            $newTransaction = RecordTransaction::fromArray(Binding::shouldBindJSON($request, 'model.RecordTransaction'));
        } catch (\RuntimeException $err) {
            Log::get()->error('failed to bind transaction JSON', ['error' => $err->getMessage()]);

            return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, 'Invalid input', null);
        }

        // Validate the transaction data
        try {
            $newTransaction->validateRecordTransaction();
        } catch (\RuntimeException $err) {
            return $this->handleRecordTransactionValidationError($response, $err);
        }

        // Queue the transaction using the Blnk service
        try {
            $resp = $this->blnk->queueTransaction($newTransaction->toTransaction());
        } catch (\Throwable $err) {
            Log::get()->error('failed to queue transaction', ['error' => self::transactionErrorString($err)]);

            return Errors::respondError(
                $response,
                $err,
                Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrTxnNotFound),
                Errors::withDefault(ErrorCode::ErrTxnValidation)
            );
        }

        // Return a response with the queued transaction, properly transformed
        return Json::write($response, 201, self::transformTransaction($resp));
    }

    /**
     * RefundTransaction processes a refund for a transaction based on the given ID.
     * It retrieves the transaction to be refunded and processes it in batches. If any errors
     * occur during retrieval or processing, it responds with an appropriate error message.
     *
     * An optional JSON body {"skip_queue": true} processes the refund
     * synchronously; an absent or empty body queues it (the default).
     * (Go: the unexported `refundTransactionRequest` struct — its single
     * `skip_queue` field is bound inline here.)
     *
     * Responses:
     * - 400 Bad Request: If there's an error in retrieving the transaction or no transaction is found to refund.
     * - 201 Created: If the refund is successfully processed.
     */
    public function refundTransaction(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['id'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'id is required. pass id in the route /:id', null);
        }
        $id = Query::param($args, 'id');

        // The body is optional — the long-standing bodiless
        // `POST /refund-transaction/:id` must keep working unchanged. An
        // empty body makes encoding/json (via ShouldBindJSON) return io.EOF;
        // treat ONLY that as "no options supplied" and reject any other
        // bind error. Detecting emptiness via io.EOF is reliable regardless
        // of Content-Length / chunked transfer encoding.
        $skipQueue = false;
        try {
            $body = Binding::shouldBindJSON($request, 'api.refundTransactionRequest');
            // SkipQueue, when true, processes the refund synchronously instead
            // of placing it on the transaction queue — useful for time-sensitive
            // refunds where the caller needs immediate confirmation. Mirrors the
            // skip_queue flag on Create Transaction.
            $skipQueue = JsonBinding::bool($body, 'skip_queue', 'refundTransactionRequest');
        } catch (\RuntimeException $err) {
            if (!Binding::isEOF($err)) {
                return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, $err->getMessage(), null);
            }
        }

        try {
            $transaction = $this->blnk->processTransactionInBatches(
                $id,
                BigInteger::zero(),
                1,
                false,
                [$this->blnk, 'getRefundableTransactionsByParentID'],
                $this->blnk->refundWorkerWithOptions($skipQueue)
            );
        } catch (\Throwable $err) {
            return Errors::respondError(
                $response,
                $err,
                Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrTxnNotFound),
                Errors::withDefault(ErrorCode::ErrGenBadRequest)
            );
        }
        if (\count($transaction) === 0) {
            return Errors::respondCode($response, ErrorCode::ErrTxnNotFound, 'no transaction to refund', null);
        }
        $resp = self::transformTransaction(array_values($transaction)[0]);

        return Json::write($response, 201, $resp);
    }

    /**
     * GetTransaction retrieves a transaction by its ID.
     * It returns the transaction details if found. If the ID is not provided or an error
     * occurs while retrieving the transaction, it responds with an appropriate error message.
     *
     * Responses:
     * - 400 Bad Request: If there's an error in retrieving the transaction or the ID is missing.
     * - 200 OK: If the transaction is successfully retrieved.
     */
    public function getTransaction(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['id'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'id is required. pass id in the route /:id', null);
        }
        $id = Query::param($args, 'id');

        try {
            $resp = $this->blnk->getTransaction($id);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrTxnNotFound));
        }

        return Json::write($response, 200, self::transformTransaction($resp));
    }

    /**
     * GetTransactionByRef retrieves a transaction by its reference.
     * It returns the transaction details if found. If the reference is not provided or an error
     * occurs while retrieving the transaction, it responds with an appropriate error message.
     *
     * Responses:
     * - 400 Bad Request: If there's an error in retrieving the transaction or the reference is missing.
     * - 200 OK: If the transaction is successfully retrieved.
     */
    public function getTransactionByRef(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['reference'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'reference is required. pass reference in the route /ref/:reference', null);
        }
        $reference = Query::param($args, 'reference');

        try {
            $resp = $this->blnk->getTransactionByRef($reference);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrTxnNotFound));
        }

        return Json::write($response, 200, self::transformTransaction($resp));
    }

    /**
     * GetAllTransactions retrieves all transactions with pagination and optional filtering.
     * Supports advanced filtering via query parameters in the format: field_operator=value
     * Example filters:
     *   - status_eq=APPLIED
     *   - currency_in=USD,EUR
     *   - amount_gte=1000
     *   - created_at_between=2024-01-01|2024-12-31
     *   - source_eq=bln_123
     *   - destination_eq=bln_456
     *
     * Responses:
     * - 400 Bad Request: If there's an error retrieving the transactions or invalid filters.
     * - 200 OK: If the transactions are successfully retrieved.
     */
    public function getAllTransactions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        // Extract limit and offset from query parameters
        $limitStr = Query::defaultQuery($request, 'limit', '20');
        $offsetStr = Query::defaultQuery($request, 'offset', '0');

        $limitInt = Query::atoi($limitStr);
        if ($limitInt === null || $limitInt <= 0) {
            $limitInt = 20;
        }

        $offsetInt = Query::atoi($offsetStr);
        if ($offsetInt === null || $offsetInt < 0) {
            $offsetInt = 0;
        }

        // Check if advanced filters are present
        if (FilterHelper::hasFilters($request)) {
            [$filters, $parseErrors] = FilterHelper::parseFiltersFromContext($request, null);
            if (\count($parseErrors) > 0) {
                return Errors::respondCode(
                    $response,
                    ErrorCode::ErrGenValidation,
                    'invalid filter parameters',
                    $parseErrors,
                    Errors::withLegacyKey('errors'),
                    Errors::withLegacyValue($parseErrors)
                );
            }

            // Use the new filter method
            try {
                $transactions = $this->blnk->getAllTransactionsWithFilter($filters, $limitInt, $offsetInt);
            } catch (\Throwable $err) {
                return Errors::respondError($response, $err);
            }

            // Transform each transaction for response
            $result = [];
            foreach ($transactions as $transaction) {
                $result[] = self::transformTransaction($transaction);
            }

            return Json::write($response, 200, $result);
        }

        // Fall back to legacy method when no filters are present
        try {
            $transactions = $this->blnk->getAllTransactions($limitInt, $offsetInt);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err);
        }

        // Transform each transaction for response
        $result = [];
        foreach ($transactions as $transaction) {
            $result[] = self::transformTransaction($transaction);
        }

        return Json::write($response, 200, $result);
    }

    /**
     * FilterTransactions filters transactions using a JSON request body.
     * This endpoint accepts a POST request with filters specified in JSON format,
     * providing more flexibility than query parameter filters.
     *
     * Request body format:
     *
     *	{
     *	  "filters": [
     *	    {"field": "status", "operator": "eq", "value": "APPLIED"},
     *	    {"field": "amount", "operator": "gte", "value": 1000},
     *	    {"field": "currency", "operator": "in", "values": ["USD", "EUR"]}
     *	  ],
     *	  "limit": 20,
     *	  "offset": 0
     *	}
     *
     * Responses:
     * - 400 Bad Request: If there's an error parsing the filters or retrieving transactions.
     * - 200 OK: If the transactions are successfully retrieved.
     */
    public function filterTransactions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            [$filters, $opts, $limit, $offset] = FilterHelper::parseFiltersFromBody($request, 'transactions');
        } catch (\RuntimeException $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenValidation, $err->getMessage(), null);
        }

        try {
            [$transactions, $count] = $this->blnk->getAllTransactionsWithFilterAndOptions($filters, $opts, $limit, $offset);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err);
        }

        // Transform each transaction for response
        $result = [];
        foreach ($transactions as $transaction) {
            $result[] = self::transformTransaction($transaction);
        }

        if ($opts->includeCount) {
            return Json::write($response, 200, new FilterResponse($result, $count));
        }

        return Json::write($response, 200, $result);
    }

    /**
     * UpdateInflightStatus updates the status of an inflight transaction based on the provided ID and status.
     * It processes the transaction in batches according to the specified status (commit or void).
     * If any errors occur during processing or if the status is unsupported, it responds with an appropriate error message.
     *
     * Responses:
     * - 400 Bad Request: If there's an error in updating the status or if the ID or status is missing or unsupported.
     * - 200 OK: If the inflight transaction status is successfully updated.
     */
    public function updateInflightStatus(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['txID'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'id is required. pass id in the route /:id', null);
        }
        $id = Query::param($args, 'txID');

        try {
            $req = InflightUpdate::fromArray(Binding::bindJSON($request, 'model.InflightUpdate'));
        } catch (\RuntimeException $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, $err->getMessage(), null);
        }

        try {
            $cnf = Configuration::fetch();
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err);
        }
        try {
            $ds = Datasource::getDBConnection($cnf);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err);
        }

        $lookup = new Transaction();
        $lookup->amount = $req->amount;
        $lookup->transactionID = $id;
        $amount = ModelHelpers::applyPrecisionWithDBLookup($lookup, $ds->conn);

        if ($req->preciseAmount !== null) {
            if ($req->preciseAmount->getSign() <= 0) {
                return Errors::respondCode($response, ErrorCode::ErrTxnInvalidAmount, 'precise_amount must be positive', null);
            }
            $amount = $req->preciseAmount;
        }

        $status = $req->status;
        if ($status !== Queue::InflightActionCommit && $status !== Queue::InflightActionVoid) {
            return Errors::respondCode($response, ErrorCode::ErrTxnInvalidStatusAction, 'status not supported. use either commit or void', null);
        }

        // Default: route the action through the inflight-commit queue. The response
        // is the still-inflight parent plus a queued marker; the worker applies it.
        if (!$req->skipQueue) {
            try {
                $parent = $this->blnk->queueInflightAction($id, $amount, $status);
            } catch (\Throwable $err) {
                if (self::isInflightActionQueuedError($err)) {
                    return Errors::respondCode($response, ErrorCode::ErrGenConflict, 'a commit or void is already queued for this transaction', null);
                }

                return Errors::respondError(
                    $response,
                    $err,
                    Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrTxnNotFound),
                    Errors::withDefault(ErrorCode::ErrGenBadRequest)
                );
            }

            // queuedInflightResponse: the embedded transaction promotes all its
            // fields to the top level (unchanged for existing clients); `queued`
            // marks that the action was accepted but not yet applied (the
            // transaction is still INFLIGHT).
            $payload = self::transformTransaction($parent)->jsonSerialize();
            $payload['queued'] = true;

            return Json::write($response, 200, $payload);
        }

        // skip_queue: process synchronously (legacy behavior).
        if ($status === Queue::InflightActionCommit) {
            try {
                $transaction = $this->blnk->processTransactionInBatches(
                    $id,
                    $amount,
                    1,
                    false,
                    [$this->blnk, 'getInflightTransactionsByParentID'],
                    [$this->blnk, 'commitWorker']
                );
            } catch (\Throwable $err) {
                return Errors::respondError(
                    $response,
                    $err,
                    Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrTxnNotFound),
                    Errors::withDefault(ErrorCode::ErrGenBadRequest)
                );
            }
            if (\count($transaction) === 0) {
                return Errors::respondCode($response, ErrorCode::ErrTxnNotInflight, 'no transaction to commit', null);
            }
            $resp = self::transformTransaction(array_values($transaction)[0]);
        } else {
            try {
                $transaction = $this->blnk->processTransactionInBatches(
                    $id,
                    $amount,
                    1,
                    false,
                    [$this->blnk, 'getInflightTransactionsByParentID'],
                    [$this->blnk, 'voidWorker']
                );
            } catch (\Throwable $err) {
                return Errors::respondError(
                    $response,
                    $err,
                    Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrTxnNotFound),
                    Errors::withDefault(ErrorCode::ErrGenBadRequest)
                );
            }
            if (\count($transaction) === 0) {
                return Errors::respondCode($response, ErrorCode::ErrTxnNotInflight, 'no transaction to void', null);
            }
            $resp = self::transformTransaction(array_values($transaction)[0]);
        }

        return Json::write($response, 200, $resp);
    }

    /**
     * CreateBulkTransactions handles the creation of multiple transactions in a batch.
     * It parses the request, calls the Blnk service to handle the core logic,
     * and returns the appropriate HTTP response based on the result.
     *
     * Go's `gin.H` maps marshal with sorted keys; the literal payloads below
     * are written in that order.
     */
    public function createBulkTransactions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $req = BulkTransactionRequest::fromArray(Binding::bindJSON($request, 'model.BulkTransactionRequest'));
        } catch (\RuntimeException $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, 'Invalid request body: ' . $err->getMessage(), null);
        }

        $transactions = array_values($req->transactions ?? []);
        if (\count($transactions) === 0) {
            return Errors::respondCode($response, ErrorCode::ErrTxnBulkEmpty, 'transactions cannot be empty', null);
        }
        if (\count($transactions) > ApiModelHelpers::MaxBulkTransactionItems) {
            return Errors::respondCode(
                $response,
                ErrorCode::ErrTxnBulkLimitExceeded,
                'too many transactions; max is ' . ApiModelHelpers::MaxBulkTransactionItems,
                null
            );
        }

        foreach ($transactions as $i => $transaction) {
            if ($transaction === null) {
                return Errors::respondCode(
                    $response,
                    ErrorCode::ErrTxnValidation,
                    'transactions[' . $i . '] is required',
                    ['index' => $i],
                    Errors::withLegacyKey('errors')
                );
            }

            try {
                $transaction->validateRecordTransaction();
            } catch (\RuntimeException $err) {
                return Errors::respondCode(
                    $response,
                    ErrorCode::ErrTxnValidation,
                    'transactions[' . $i . ']: ' . Errors::errorString($err),
                    ['index' => $i],
                    Errors::withLegacyKey('errors')
                );
            }
        }

        $bulkReq = $req->toBulkTransactionRequest();

        // Call the service layer method to handle bulk transaction creation
        try {
            $result = $this->blnk->createBulkTransactions($bulkReq);
        } catch (BulkTransactionException $err) {
            // If there was an error during synchronous processing (Go: a result
            // alongside the error; the PHP port carries it on the exception).
            // classifyMessage picks the most specific catalog code from the
            // detailed result error; batch_id stays a top-level sibling field.
            $result = $err->result;
            Log::get()->error('bulk transaction API error', ['error' => $err->getMessage(), 'batch_id' => $result->batchID]);
            $code = Errors::classifyMessage($result->error);
            if ($code === null) {
                $code = ErrorCode::ErrGenBadRequest;
            }
            $errorDetail = ErrorResponse::newErrorResponse($code, $result->error, ['batch_id' => $result->batchID]);

            return Json::write($response, ErrorCode::statusForCode($code), [
                'batch_id' => $result->batchID,
                'error' => $result->error, // Use the detailed error from the result
                'error_detail' => $errorDetail->jsonSerialize()['error'],
            ]);
        } catch (\Throwable $err) {
            // Some failures (e.g. async-bulk semaphore saturation) return a nil
            // result alongside a typed API error; surface that error's own status
            // code instead of dereferencing the nil result.
            return Errors::respondError($response, $err, Errors::withDefault(ErrorCode::ErrGenBadRequest));
        }

        // Handle successful responses
        if ($bulkReq->runAsync) {
            // Async request acknowledged
            return Json::write($response, 202, [
                'batch_id' => $result->batchID,
                'message' => 'Bulk transaction processing started',
                'status' => $result->status, // Should be "processing"
            ]);
        }

        // Synchronous request completed successfully
        return Json::write($response, 201, [
            'batch_id' => $result->batchID,
            'status' => $result->status, // Should be "applied" or "inflight"
            'transaction_count' => $result->transactionCount,
        ]);
    }

    public function getTransactionLineage(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['id'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'id is required. pass id in the route /:id', null);
        }
        $id = Query::param($args, 'id');

        try {
            $lineage = $this->blnk->getTransactionLineage($id);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrTxnNotFound));
        }

        return Json::write($response, 200, $lineage);
    }

    /**
     * RecoverQueuedTransactions triggers manual recovery of stuck queued transactions.
     * Accepts an optional "threshold" query parameter (e.g. "5m", "10m") specifying
     * the minimum age of transactions to recover. Defaults to 2 minutes, which is also
     * the enforced minimum.
     */
    public function recoverQueuedTransactions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $threshold = self::recoveryDefaultThresholdNs;
        $thresholdStr = Query::query($request, 'threshold');
        if ($thresholdStr !== '') {
            try {
                $parsed = self::goParseDuration($thresholdStr);
            } catch (\RuntimeException $err) {
                return Errors::respondCode($response, ErrorCode::ErrGenValidation, 'invalid threshold duration: ' . $err->getMessage(), null);
            }
            $threshold = $parsed;
        }

        // The service takes the threshold in seconds (Go: time.Duration).
        $seconds = $threshold % 1000000000 === 0 ? intdiv($threshold, 1000000000) : $threshold / 1000000000;
        try {
            $recovered = $this->blnk->recoverQueuedTransactions($seconds);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err);
        }

        return Json::write($response, 200, ['recovered' => $recovered, 'threshold' => self::goDurationString($threshold)]);
    }

    /**
     * toAPIResults converts service-layer BulkInflightOutcome values to the
     * public BulkInflightResult shape returned by the handlers.
     *
     * @param BulkInflightOutcome[] $outcomes
     *
     * @return array{0: BulkInflightResponse, 1: int} the response and its failed count
     */
    private static function toAPIResults(array $outcomes): array
    {
        $resp = new BulkInflightResponse(0, 0, []);
        foreach (array_values($outcomes) as $i => $o) {
            $r = new BulkInflightResult($o->transactionID);
            if ($o->err === null) {
                $r->status = 'succeeded';
                $resp->succeeded++;
            } else {
                $r->status = 'failed';
                $r->code = $o->code;
                $r->message = self::transactionErrorString($o->err);
                $resp->failed++;
            }
            $resp->results[$i] = $r;
        }

        return [$resp, $resp->failed];
    }

    /**
     * BulkVoidInflight voids many independently-created inflight transactions
     * in a single call. Each id is processed in its own worker; per-item
     * failures are reported in the response body and do not abort the rest of
     * the batch. Returns 200 with the breakdown even when some items fail.
     *
     * Responses:
     * - 400 Bad Request: malformed payload, empty list, or > MaxBulkInflightItems.
     * - 200 OK: bulk processed; see succeeded/failed counts in body.
     */
    public function bulkVoidInflight(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $req = BulkInflightVoidRequest::fromArray(Binding::bindJSON($request, 'model.BulkInflightVoidRequest'));
        } catch (\RuntimeException $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, $err->getMessage(), null);
        }
        $transactionIDs = array_values($req->transactionIDs ?? []);
        if (\count($transactionIDs) === 0) {
            return Errors::respondCode($response, ErrorCode::ErrTxnBulkEmpty, 'transaction_ids cannot be empty', null);
        }
        if (\count($transactionIDs) > ApiModelHelpers::MaxBulkInflightItems) {
            return Errors::respondCode(
                $response,
                ErrorCode::ErrTxnBulkLimitExceeded,
                'too many transaction_ids; max is ' . ApiModelHelpers::MaxBulkInflightItems,
                null
            );
        }

        $items = [];
        foreach ($transactionIDs as $i => $id) {
            $items[$i] = new BulkInflightItem($id);
        }

        if (!$req->skipQueue) {
            return Json::write($response, 200, $this->queueBulkInflight(Queue::InflightActionVoid, $items));
        }

        try {
            $outcomes = $this->blnk->bulkInflightUpdate(BulkInflightAction::BulkInflightVoid, $items, self::bulkInflightMaxWorkers);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withDefault(ErrorCode::ErrGenBadRequest));
        }
        [$resp] = self::toAPIResults($outcomes);

        return Json::write($response, 200, $resp);
    }

    /**
     * queueBulkInflight enqueues a commit/void per item and builds the per-item
     * response: QUEUED on success, ALREADY_QUEUED when one is already in flight
     * (both count as accepted), and a classified failure code if pre-validation
     * rejects the item synchronously.
     *
     * @param BulkInflightItem[] $items
     */
    private function queueBulkInflight(string $action, array $items): BulkInflightResponse
    {
        $resp = new BulkInflightResponse(0, 0, []);
        foreach ($items as $it) {
            try {
                $this->blnk->queueInflightAction($it->transactionID, $it->amount, $action);
                $resp->results[] = new BulkInflightResult($it->transactionID, 'queued', 'QUEUED');
                $resp->succeeded++;
            } catch (\Throwable $err) {
                if (self::isInflightActionQueuedError($err)) {
                    $resp->results[] = new BulkInflightResult($it->transactionID, 'queued', 'ALREADY_QUEUED', self::transactionErrorString($err));
                    $resp->succeeded++;
                } else {
                    $resp->results[] = new BulkInflightResult($it->transactionID, 'failed', Blnk::classifyInflightError($err), self::transactionErrorString($err));
                    $resp->failed++;
                }
            }
        }

        return $resp;
    }

    /**
     * BulkCommitInflight commits many independently-created inflight
     * transactions in a single call. Unlike the void variant, each item may
     * carry an optional `amount` / `precise_amount` for partial commits; zero
     * or missing means commit the full remaining inflight amount.
     *
     * Responses:
     * - 400 Bad Request: malformed payload, empty list, or > MaxBulkInflightItems.
     * - 200 OK: bulk processed; see succeeded/failed counts in body.
     */
    public function bulkCommitInflight(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $req = BulkInflightCommitRequest::fromArray(Binding::bindJSON($request, 'model.BulkInflightCommitRequest'));
        } catch (\RuntimeException $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, $err->getMessage(), null);
        }
        $transactions = array_values($req->transactions ?? []);
        if (\count($transactions) === 0) {
            return Errors::respondCode($response, ErrorCode::ErrTxnBulkEmpty, 'transactions cannot be empty', null);
        }
        if (\count($transactions) > ApiModelHelpers::MaxBulkInflightItems) {
            return Errors::respondCode(
                $response,
                ErrorCode::ErrTxnBulkLimitExceeded,
                'too many transactions; max is ' . ApiModelHelpers::MaxBulkInflightItems,
                null
            );
        }

        try {
            $cnf = Configuration::fetch();
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err);
        }
        try {
            $ds = Datasource::getDBConnection($cnf);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err);
        }

        $items = [];
        foreach ($transactions as $i => $it) {
            // Match single-tx semantics: precise_amount wins; else apply precision
            // via the same DB-lookup helper used by UpdateInflightStatus.
            if ($it->preciseAmount !== null) {
                $amount = $it->preciseAmount;
            } else {
                $lookup = new Transaction();
                $lookup->amount = $it->amount;
                $lookup->transactionID = $it->transactionID;
                $amount = ModelHelpers::applyPrecisionWithDBLookup($lookup, $ds->conn);
            }
            $items[$i] = new BulkInflightItem($it->transactionID, $amount);
        }

        if (!$req->skipQueue) {
            return Json::write($response, 200, $this->queueBulkInflight(Queue::InflightActionCommit, $items));
        }

        try {
            $outcomes = $this->blnk->bulkInflightUpdate(BulkInflightAction::BulkInflightCommit, $items, self::bulkInflightMaxWorkers);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withDefault(ErrorCode::ErrGenBadRequest));
        }
        [$resp] = self::toAPIResults($outcomes);

        return Json::write($response, 200, $resp);
    }

    // ------------------------------------------------------------------
    // Ports of Go's time.ParseDuration / time.Duration.String (used by
    // RecoverQueuedTransactions). Durations are int nanoseconds.
    // ------------------------------------------------------------------

    /**
     * goQuote is the `quote` helper of Go's time package: the string in
     * double quotes, with non-ASCII / control bytes as \x hex escapes.
     */
    private static function goQuote(string $s): string
    {
        $buf = '"';
        $n = \strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            $b = \ord($c);
            if ($b >= 0x80 || $b < 0x20) {
                $buf .= sprintf('\\x%02x', $b);
            } else {
                if ($c === '"' || $c === '\\') {
                    $buf .= '\\';
                }
                $buf .= $c;
            }
        }

        return $buf . '"';
    }

    /**
     * goLeadingInt consumes the leading [0-9]* from s (Go: leadingInt).
     * Values beyond PHP's int range are reported as overflow (Go allows
     * exactly 1<<63 here and rejects it later unless negated).
     *
     * @return array{0: int, 1: string, 2: bool} [x, rem, ok]
     */
    private static function goLeadingInt(string $s): array
    {
        $x = 0;
        $n = \strlen($s);
        $i = 0;
        for (; $i < $n; $i++) {
            $c = $s[$i];
            if ($c < '0' || $c > '9') {
                break;
            }
            if ($x > intdiv(PHP_INT_MAX, 10)) {
                // overflow
                return [0, $s, false];
            }
            $x = $x * 10 + (\ord($c) - 48);
            if (!\is_int($x)) {
                // overflow
                return [0, $s, false];
            }
        }

        return [$x, substr($s, $i), true];
    }

    /**
     * goLeadingFraction consumes the leading [0-9]* from s, as the digits after
     * a decimal point (Go: leadingFraction). It is used only for fractions, so
     * overflow digits are dropped rather than rejected.
     *
     * @return array{0: int, 1: float, 2: string} [x, scale, rem]
     */
    private static function goLeadingFraction(string $s): array
    {
        $x = 0;
        $scale = 1.0;
        $overflow = false;
        $n = \strlen($s);
        $i = 0;
        for (; $i < $n; $i++) {
            $c = $s[$i];
            if ($c < '0' || $c > '9') {
                break;
            }
            if ($overflow) {
                continue;
            }
            if ($x > intdiv(PHP_INT_MAX, 10)) {
                // It's possible for overflow to give a positive number, so take care.
                $overflow = true;
                continue;
            }
            $y = $x * 10 + (\ord($c) - 48);
            if (!\is_int($y)) {
                $overflow = true;
                continue;
            }
            $x = $y;
            $scale *= 10;
        }

        return [$x, $scale, substr($s, $i)];
    }

    /**
     * goParseDuration is the port of Go's `time.ParseDuration`: a possibly
     * signed sequence of decimal numbers, each with optional fraction and a
     * unit suffix ("300ms", "-1.5h", "2h45m"); valid units are "ns", "us"
     * (or "µs"), "ms", "s", "m", "h". Returns nanoseconds.
     *
     * @throws \RuntimeException with Go's error messages
     */
    private static function goParseDuration(string $s): int
    {
        // [-+]?([0-9]*(\.[0-9]*)?[a-z]+)+
        $orig = $s;
        $d = 0;
        $neg = false;

        // Consume [-+]?
        if ($s !== '') {
            $c = $s[0];
            if ($c === '-' || $c === '+') {
                $neg = $c === '-';
                $s = substr($s, 1);
            }
        }
        // Special case: if all that is left is "0", this is zero.
        if ($s === '0') {
            return 0;
        }
        if ($s === '') {
            throw new \RuntimeException('time: invalid duration ' . self::goQuote($orig));
        }

        $unitMap = [
            'ns' => 1,
            'us' => 1000,
            "\u{00B5}s" => 1000, // U+00B5 = micro symbol
            "\u{03BC}s" => 1000, // U+03BC = Greek letter mu
            'ms' => 1000000,
            's' => 1000000000,
            'm' => 60000000000,
            'h' => 3600000000000,
        ];

        while ($s !== '') {
            $v = 0;      // integers before the decimal point
            $f = 0;      // integers after the decimal point
            $scale = 1.0; // value = v + f/scale

            // The next character must be [0-9.]
            if (!($s[0] === '.' || ($s[0] >= '0' && $s[0] <= '9'))) {
                throw new \RuntimeException('time: invalid duration ' . self::goQuote($orig));
            }
            // Consume [0-9]*
            $pl = \strlen($s);
            [$v, $s, $ok] = self::goLeadingInt($s);
            if (!$ok) {
                throw new \RuntimeException('time: invalid duration ' . self::goQuote($orig));
            }
            $pre = $pl !== \strlen($s); // whether we consumed anything before a period

            // Consume (\.[0-9]*)?
            $post = false;
            if ($s !== '' && $s[0] === '.') {
                $s = substr($s, 1);
                $pl = \strlen($s);
                [$f, $scale, $s] = self::goLeadingFraction($s);
                $post = $pl !== \strlen($s);
            }
            if (!$pre && !$post) {
                // no digits (e.g. ".s" or "-.s")
                throw new \RuntimeException('time: invalid duration ' . self::goQuote($orig));
            }

            // Consume unit.
            $i = 0;
            $n = \strlen($s);
            for (; $i < $n; $i++) {
                $c = $s[$i];
                if ($c === '.' || ($c >= '0' && $c <= '9')) {
                    break;
                }
            }
            if ($i === 0) {
                throw new \RuntimeException('time: missing unit in duration ' . self::goQuote($orig));
            }
            $u = substr($s, 0, $i);
            $s = substr($s, $i);
            if (!isset($unitMap[$u])) {
                throw new \RuntimeException('time: unknown unit ' . self::goQuote($u) . ' in duration ' . self::goQuote($orig));
            }
            $unit = $unitMap[$u];
            if ($v > intdiv(PHP_INT_MAX, $unit)) {
                // overflow
                throw new \RuntimeException('time: invalid duration ' . self::goQuote($orig));
            }
            $v *= $unit;
            if ($f > 0) {
                // float64 is needed to be nanosecond accurate for fractions of hours.
                // v >= 0 && (f*unit/scale) <= 3.6e+12 (ns/h, h is the largest unit)
                $v += (int) ((float) $f * ((float) $unit / $scale));
                if (!\is_int($v)) {
                    // overflow
                    throw new \RuntimeException('time: invalid duration ' . self::goQuote($orig));
                }
            }
            $d += $v;
            if (!\is_int($d)) {
                throw new \RuntimeException('time: invalid duration ' . self::goQuote($orig));
            }
        }
        if ($neg) {
            return -$d;
        }

        return $d;
    }

    /**
     * goFmtFrac formats the fraction of v/10**prec (e.g., ".12345") omitting
     * trailing zeros — and the decimal point too when the fraction is 0
     * (Go: fmtFrac). Returns the fraction text and v/10**prec.
     *
     * @return array{0: string, 1: int}
     */
    private static function goFmtFrac(int $v, int $prec): array
    {
        // Omit trailing zeros up until and including decimal point.
        $digits = '';
        $print = false;
        for ($i = 0; $i < $prec; $i++) {
            $digit = $v % 10;
            $print = $print || $digit !== 0;
            if ($print) {
                $digits = (string) $digit . $digits;
            }
            $v = intdiv($v, 10);
        }
        if ($print) {
            $digits = '.' . $digits;
        }

        return [$digits, $v];
    }

    /**
     * goDurationString is the port of Go's `time.Duration.String`: "72h3m0.5s",
     * with smaller units below one second ("1.5ms", "500µs", "0s").
     */
    private static function goDurationString(int $d): string
    {
        $neg = $d < 0;
        $u = $neg ? -$d : $d;
        if (!\is_int($u)) {
            // -PHP_INT_MIN does not fit; clamp (Go's uint64 has the room).
            $u = PHP_INT_MAX;
        }

        if ($u < 1000000000) {
            // Special case: if duration is smaller than a second,
            // use smaller units, like 1.2ms
            if ($u === 0) {
                return '0s';
            }
            if ($u < 1000) {
                // print nanoseconds
                $prec = 0;
                $unit = 'ns';
            } elseif ($u < 1000000) {
                // print microseconds
                $prec = 3;
                $unit = "\u{00B5}s"; // U+00B5 'µ' micro sign
            } else {
                // print milliseconds
                $prec = 6;
                $unit = 'ms';
            }
            [$frac, $u] = self::goFmtFrac($u, $prec);
            $out = (string) $u . $frac . $unit;
        } else {
            [$frac, $u] = self::goFmtFrac($u, 9);

            // u is now integer seconds
            $out = (string) ($u % 60) . $frac . 's';
            $u = intdiv($u, 60);

            // u is now integer minutes
            if ($u > 0) {
                $out = (string) ($u % 60) . 'm' . $out;
                $u = intdiv($u, 60);

                // u is now integer hours
                // Stop at hours because days can be different lengths.
                if ($u > 0) {
                    $out = (string) $u . 'h' . $out;
                }
            }
        }

        return ($neg ? '-' : '') . $out;
    }
}
