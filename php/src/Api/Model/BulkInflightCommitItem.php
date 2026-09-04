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

namespace Blnk\Api\Model;

use Blnk\Model\ModelHelpers as CoreModelHelpers;
use Brick\Math\BigInteger;

/**
 * BulkInflightCommitItem describes one transaction in a bulk commit request.
 * Amount/PreciseAmount carry the same semantics as the single-tx endpoint:
 * zero means commit the full remaining inflight amount; non-zero performs a
 * partial commit. PreciseAmount, when set, wins over Amount.
 * (Go: api/model/transaction.go `BulkInflightCommitItem`.)
 */
final class BulkInflightCommitItem implements \JsonSerializable
{
    public string $transactionID = '';

    /** JSON: "amount,omitempty". */
    public float $amount = 0.0;

    /** Go: *big.Int `json:"precise_amount,omitempty"`. */
    public ?BigInteger $preciseAmount = null;

    /**
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException on a JSON type mismatch (Gin bind error)
     */
    public static function fromArray(array $data, string $struct = 'BulkInflightCommitItem'): self
    {
        $i = new self();
        $i->transactionID = JsonBinding::string($data, 'transaction_id', $struct);
        $i->amount = JsonBinding::float($data, 'amount', $struct);
        $i->preciseAmount = JsonBinding::bigInt($data, 'precise_amount', $struct);
        return $i;
    }

    public function jsonSerialize(): array
    {
        $out = ['transaction_id' => $this->transactionID];
        if ($this->amount != 0) { // omitempty
            $out['amount'] = $this->amount;
        }
        if ($this->preciseAmount !== null) { // omitempty
            $out['precise_amount'] = CoreModelHelpers::bigIntegerToJson($this->preciseAmount);
        }
        return $out;
    }
}
