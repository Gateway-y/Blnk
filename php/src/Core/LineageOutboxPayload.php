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
 * LineageOutboxPayload contains the transaction data needed for deferred lineage processing.
 *
 * (Go: `LineageOutboxPayload`, lineage.go.) JSON names follow the Go tags.
 */
final class LineageOutboxPayload implements \JsonSerializable
{
    /** json:"amount" */
    public float $amount = 0.0;

    /** json:"precise_amount" */
    public string $preciseAmount = '';

    /** json:"currency" */
    public string $currency = '';

    /** json:"precision" */
    public float $precision = 0.0;

    /** json:"reference" */
    public string $reference = '';

    /** json:"skip_queue" */
    public bool $skipQueue = false;

    /** json:"inflight" */
    public bool $inflight = false;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $p = new self();
        $p->amount = (float) ($data['amount'] ?? 0.0);
        $p->preciseAmount = (string) ($data['precise_amount'] ?? '');
        $p->currency = (string) ($data['currency'] ?? '');
        $p->precision = (float) ($data['precision'] ?? 0.0);
        $p->reference = (string) ($data['reference'] ?? '');
        $p->skipQueue = (bool) ($data['skip_queue'] ?? false);
        $p->inflight = (bool) ($data['inflight'] ?? false);
        return $p;
    }

    public function jsonSerialize(): array
    {
        return [
            'amount' => self::goFloatJson($this->amount),
            'precise_amount' => $this->preciseAmount,
            'currency' => $this->currency,
            'precision' => self::goFloatJson($this->precision),
            'reference' => $this->reference,
            'skip_queue' => $this->skipQueue,
            'inflight' => $this->inflight,
        ];
    }

    /**
     * goFloatJson renders a float64 the way encoding/json does: integral
     * values without a fractional part (`10`, where PHP's json_encode would
     * emit `10.0`).
     */
    private static function goFloatJson(float $f): int|float
    {
        if (is_finite($f) && floor($f) === $f && abs($f) < 1e15) {
            return (int) $f;
        }
        return $f;
    }
}
