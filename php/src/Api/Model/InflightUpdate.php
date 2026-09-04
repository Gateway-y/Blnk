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
 * InflightUpdate is the body of PUT /transactions/inflight/:txID
 * (Go: api/model/transaction.go `InflightUpdate`).
 */
final class InflightUpdate implements \JsonSerializable
{
    public string $status = '';

    public float $amount = 0.0;

    /** Go: *big.Int `json:"precise_amount,omitempty"`. */
    public ?BigInteger $preciseAmount = null;

    /**
     * SkipQueue processes the commit/void synchronously instead of routing it
     * through the inflight-commit queue (the default).
     */
    public bool $skipQueue = false;

    /**
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException on a JSON type mismatch (Gin bind error)
     */
    public static function fromArray(array $data, string $struct = 'InflightUpdate'): self
    {
        $u = new self();
        $u->status = JsonBinding::string($data, 'status', $struct);
        $u->amount = JsonBinding::float($data, 'amount', $struct);
        $u->preciseAmount = JsonBinding::bigInt($data, 'precise_amount', $struct);
        $u->skipQueue = JsonBinding::bool($data, 'skip_queue', $struct);
        return $u;
    }

    public function jsonSerialize(): array
    {
        $out = [
            'status' => $this->status,
            'amount' => $this->amount,
        ];
        if ($this->preciseAmount !== null) { // omitempty
            $out['precise_amount'] = CoreModelHelpers::bigIntegerToJson($this->preciseAmount);
        }
        $out['skip_queue'] = $this->skipQueue;
        return $out;
    }
}
