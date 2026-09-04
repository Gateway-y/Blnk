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

/**
 * ChainVerifyResult is the outcome of replaying the chain from genesis.
 *
 * (Go: `ChainVerifyResult`, chain_worker.go. The Go struct has no JSON tags;
 * serialization uses the Go field names.)
 */
final class ChainVerifyResult implements \JsonSerializable
{
    public bool $verified = false;

    public int $lastSeq = 0;

    public string $headHash = '';

    public int $brokenSeq = 0;

    public string $brokenTxnID = '';

    public string $reason = '';

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $r = new self();
        $r->verified = (bool) ($data['Verified'] ?? false);
        $r->lastSeq = (int) ($data['LastSeq'] ?? 0);
        $r->headHash = (string) ($data['HeadHash'] ?? '');
        $r->brokenSeq = (int) ($data['BrokenSeq'] ?? 0);
        $r->brokenTxnID = (string) ($data['BrokenTxnID'] ?? '');
        $r->reason = (string) ($data['Reason'] ?? '');
        return $r;
    }

    public function jsonSerialize(): array
    {
        return [
            'Verified' => $this->verified,
            'LastSeq' => $this->lastSeq,
            'HeadHash' => $this->headHash,
            'BrokenSeq' => $this->brokenSeq,
            'BrokenTxnID' => $this->brokenTxnID,
            'Reason' => $this->reason,
        ];
    }
}
