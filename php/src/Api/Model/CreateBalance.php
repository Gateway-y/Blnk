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

use Blnk\Model\Balance;
use Blnk\Model\ModelHelpers as CoreModelHelpers;

/**
 * CreateBalance is the request payload of POST /balances
 * (Go: api/model/balance.go `CreateBalance`).
 */
final class CreateBalance implements \JsonSerializable
{
    public string $ledgerId = '';

    public string $identityId = '';

    public string $currency = '';

    public float $precision = 0.0;

    /** @var array<string, mixed>|null */
    public ?array $metaData = null;

    public bool $trackFundLineage = false;

    public string $allocationStrategy = '';

    /**
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException on a JSON type mismatch (Gin bind error)
     */
    public static function fromArray(array $data, string $struct = 'CreateBalance'): self
    {
        $b = new self();
        $b->ledgerId = JsonBinding::string($data, 'ledger_id', $struct);
        $b->identityId = JsonBinding::string($data, 'identity_id', $struct);
        $b->currency = JsonBinding::string($data, 'currency', $struct);
        $b->precision = JsonBinding::float($data, 'precision', $struct);
        $b->metaData = JsonBinding::map($data, 'meta_data', $struct);
        $b->trackFundLineage = JsonBinding::bool($data, 'track_fund_lineage', $struct);
        $b->allocationStrategy = JsonBinding::string($data, 'allocation_strategy', $struct);

        return $b;
    }

    /**
     * ValidateCreateBalance runs the ozzo-validation rules of model.go:
     * ledger_id and currency are required, identity_id is required when
     * track_fund_lineage is enabled, and a non-empty allocation_strategy must
     * be FIFO, LIFO or PROPORTIONAL.
     *
     * @throws ValidationErrors
     */
    public function validateCreateBalance(): void
    {
        // Normalize allocation strategy: trim and uppercase
        if ($this->allocationStrategy !== '') {
            $this->allocationStrategy = trim(strtoupper($this->allocationStrategy));
        }

        ModelHelpers::validateStruct([
            'ledger_id' => function (): void {
                ModelHelpers::required($this->ledgerId);
            },
            'currency' => function (): void {
                ModelHelpers::required($this->currency);
            },
            'identity_id' => function (): void {
                if ($this->trackFundLineage) { // validation.When
                    if ($this->identityId === '') { // validation.Required.Error(...)
                        throw new \RuntimeException('identity_id is required when track_fund_lineage is enabled');
                    }
                }
            },
            'allocation_strategy' => function (): void {
                if ($this->allocationStrategy !== '') { // validation.When
                    // validation.In("FIFO", "LIFO", "PROPORTIONAL").Error(...)
                    if (!in_array($this->allocationStrategy, ['FIFO', 'LIFO', 'PROPORTIONAL'], true)) {
                        throw new \RuntimeException('allocation_strategy must be one of: FIFO, LIFO, PROPORTIONAL');
                    }
                }
            },
        ]);
    }

    /**
     * ToBalance converts the request payload into the domain Balance
     * (the allocation strategy defaults to FIFO).
     */
    public function toBalance(): Balance
    {
        $allocationStrategy = $this->allocationStrategy;
        if ($allocationStrategy === '') {
            $allocationStrategy = 'FIFO';
        }

        $balance = new Balance();
        $balance->ledgerID = $this->ledgerId;
        $balance->identityID = $this->identityId;
        $balance->currency = $this->currency;
        $balance->metaData = $this->metaData;
        $balance->trackFundLineage = $this->trackFundLineage;
        $balance->allocationStrategy = $allocationStrategy;

        return $balance;
    }

    public function jsonSerialize(): array
    {
        return [
            'ledger_id' => $this->ledgerId,
            'identity_id' => $this->identityId,
            'currency' => $this->currency,
            'precision' => $this->precision,
            'meta_data' => CoreModelHelpers::mapToJson($this->metaData),
            'track_fund_lineage' => $this->trackFundLineage,
            'allocation_strategy' => $this->allocationStrategy,
        ];
    }
}
