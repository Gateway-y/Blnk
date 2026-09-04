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

namespace Blnk\Cmd;

use Blnk\Internal\Log;

/**
 * VerifyChainCommand is the port of cmd/verify_chain.go.
 *
 * verifyChainCommands replays the transaction hash chain from genesis and
 * reports whether history is intact. Exits non-zero on the first broken link.
 */
final class VerifyChainCommand
{
    public const Use = 'verify-chain';
    public const Short = 'Replay and verify the transaction hash chain from genesis';

    /**
     * run is the command's RunE: it throws (and the CLI exits non-zero) when
     * the replay fails or the chain is broken, and logs the head on success.
     *
     * @throws \RuntimeException
     */
    public static function run(BlnkInstance $b): void
    {
        try {
            $result = $b->blnk->verifyChain(null);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('verify-chain failed: %s', $err->getMessage()), 0, $err);
        }
        if (!$result->verified) {
            throw new \RuntimeException(sprintf(
                'chain BROKEN at seq %d (txn %s): %s',
                $result->brokenSeq,
                $result->brokenTxnID,
                $result->reason
            ));
        }
        Log::get()->info(sprintf(
            'chain verified: %d transactions sealed, head %s at seq %d',
            $result->lastSeq,
            $result->headHash,
            $result->lastSeq
        ));
    }
}
