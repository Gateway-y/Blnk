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

use Blnk\Model\Chain;
use Blnk\Model\ChainRow;

/**
 * ChainWorker is the port of the root-package file `chain_worker.go`.
 *
 * The file's standalone types became their own classes in this namespace:
 *  - `ChainProcessor` (+ `NewChainProcessor`) → {@see ChainProcessor}
 *  - `ChainVerifyResult`                      → {@see ChainVerifyResult}
 *
 * The `maxBatchesPerTick` constant lives here as a trait constant (reachable
 * as `Blnk::maxBatchesPerTick`), and the one `*Blnk` method of the file,
 * `VerifyChain`, is {@see verifyChain()}.
 *
 * The workers CLI drives the chainer through `ChainProcessor::run($stopFlag)`
 * (the Go background loop) or `ChainProcessor::runOnce()` (a single tick).
 */
trait ChainWorker
{
    /**
     * maxBatchesPerTick bounds how much a single tick chains so a large backfill
     * never starves the ticker; the next tick simply continues.
     */
    public const maxBatchesPerTick = 50;

    /**
     * VerifyChain replays the whole chain from genesis, checking that each row's
     * chain_seq is contiguous, its chain_prev_hash matches the running head, and its
     * recomputed hash matches the stored chain_hash. It reports the first broken
     * link, or success with the final head. progress (optional) is called with each
     * verified seq.
     *
     * @param callable(int): void|null $progress
     * @throws \Blnk\Internal\ApiError\ApiErrorException on datasource failure (Go: returned error)
     */
    public function verifyChain(?callable $progress = null): ChainVerifyResult
    {
        $state = $this->datasource->getChainState();

        $head = $state->genesisHash;
        $lastSeq = 0;
        $expectedSeq = 1;
        $page = 1000;

        while (true) {
            $rows = $this->datasource->getChainedTransactionsAfter($lastSeq, $page);
            if (\count($rows) === 0) {
                break;
            }
            foreach ($rows as $ct) {
                // Go: ct.Row is a value struct; a null here stands for the zero row.
                $row = $ct->row ?? new ChainRow();
                if ($ct->chainSeq !== $expectedSeq) {
                    $result = new ChainVerifyResult();
                    $result->brokenSeq = $ct->chainSeq;
                    $result->brokenTxnID = $row->transactionID;
                    $result->reason = sprintf('non-contiguous chain_seq: expected %d, got %d', $expectedSeq, $ct->chainSeq);
                    return $result;
                }
                if ($ct->chainPrevHash !== $head) {
                    $result = new ChainVerifyResult();
                    $result->brokenSeq = $ct->chainSeq;
                    $result->brokenTxnID = $row->transactionID;
                    $result->reason = 'chain_prev_hash does not match the running head';
                    return $result;
                }
                if (Chain::computeChainHash($head, $row) !== $ct->chainHash) {
                    $result = new ChainVerifyResult();
                    $result->brokenSeq = $ct->chainSeq;
                    $result->brokenTxnID = $row->transactionID;
                    $result->reason = 'recomputed hash does not match stored chain_hash';
                    return $result;
                }
                $head = $ct->chainHash;
                $lastSeq = $ct->chainSeq;
                $expectedSeq++;
                if ($progress !== null) {
                    $progress($lastSeq);
                }
            }
        }

        // The replayed head must match the recorded head at the recorded length.
        if ($lastSeq === $state->lastSeq && $head !== $state->headHash) {
            $result = new ChainVerifyResult();
            $result->brokenSeq = $lastSeq;
            $result->reason = 'replayed head does not match chain_state head_hash';
            return $result;
        }

        $result = new ChainVerifyResult();
        $result->verified = true;
        $result->lastSeq = $lastSeq;
        $result->headHash = $head;
        return $result;
    }
}
