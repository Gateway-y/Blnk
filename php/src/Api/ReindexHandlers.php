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

use Blnk\Internal\ApiError\ErrorCode;
use Blnk\Internal\ApiError\ErrorResponse;
use Blnk\Internal\Search\ReindexConfig;
use Blnk\Internal\Search\ReindexService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Port of api/reindex.go: the search reindex handlers of the Go `Api` struct,
 * composed into {@see Api}. The request struct is {@see ReindexRequest} and
 * the `reindexManager` singleton is {@see ReindexManager}.
 */
trait ReindexHandlers
{
    /**
     * StartReindex triggers a full reindex of all data from the database to Typesense.
     * The reindex runs asynchronously to avoid HTTP timeouts.
     *
     * Responses:
     * - 202 Accepted: Reindex started successfully, returns initial progress.
     * - 409 Conflict: If a reindex is already in progress.
     */
    public function startReindex(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $req = new ReindexRequest();
        try {
            $req = ReindexRequest::fromArray(Binding::shouldBindJSON($request, 'api.ReindexRequest'));
        } catch (\RuntimeException) {
            $req->batchSize = 0;
        }

        if ($req->batchSize <= 0) {
            $req->batchSize = 1000;
        }

        $manager = ReindexManager::global();
        $progress = $manager->getProgress();
        if ($progress !== null) {
            if ($progress->status === 'in_progress') {
                $msg = 'A reindex operation is already in progress';
                $detail = ErrorResponse::newErrorResponse(ErrorCode::ErrSrchReindexInProgress, $msg, $progress);

                return Json::write($response, 409, [
                    'error' => $msg,
                    'progress' => $progress,
                    'error_detail' => Errors::apiErrorPayload($detail->code, $detail->message, $detail->details),
                ]);
            }
        }

        $config = new ReindexConfig($req->batchSize);

        $reindexService = new ReindexService(
            $this->blnk->getSearchClient(),
            $this->blnk->getDataSource(),
            $config
        );
        $manager->setService($reindexService);

        // Mark the run as started for other processes before answering.
        $started = $reindexService->getProgress();
        $started->status = 'in_progress';
        $started->phase = 'starting';
        $started->startedAt = new \DateTimeImmutable('now');
        $manager->persistProgress($started);

        // Go: `go func() { _, _ = reindexService.StartReindex(context.Background()) }()`
        // — runs once the 202 has been flushed (see Deferred).
        Deferred::defer(static function () use ($reindexService, $manager): void {
            try {
                $reindexService->startReindex();
            } catch (\Throwable) {
                // Go discards the error; the progress records the failure.
            } finally {
                $manager->persistProgress($reindexService->getProgress());
            }
        });

        return Json::write($response, 202, [
            'message' => 'Reindex operation started',
            'progress' => $reindexService->getProgress(),
        ]);
    }

    /**
     * GetReindexProgress returns the current progress of the reindex operation.
     *
     * Responses:
     * - 200 OK: Returns current progress.
     * - 404 Not Found: If no reindex operation has been started.
     */
    public function getReindexProgress(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $progress = ReindexManager::global()->getProgress();
        if ($progress === null) {
            return Errors::respondCode($response, ErrorCode::ErrSrchReindexNotStarted, 'No reindex operation has been started', null);
        }

        return Json::write($response, 200, $progress);
    }
}
