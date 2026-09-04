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

use Blnk\Internal\Log;
use Blnk\Model\Distribution;
use Blnk\Model\ModelHelpers as CoreModelHelpers;
use Blnk\Model\Transaction;
use Brick\Math\BigInteger;

/**
 * RecordTransaction is the request payload of POST /transactions and of every
 * item of POST /transactions/bulk (Go: api/model/transaction.go `RecordTransaction`).
 */
final class RecordTransaction implements \JsonSerializable
{
    public float $amount = 0.0;

    public float $precision = 0.0;

    public float $overdraftLimit = 0.0;

    /** Go: *big.Int `json:"precise_amount"`. */
    public ?BigInteger $preciseAmount = null;

    public bool $allowOverDraft = false;

    public bool $inflight = false;

    public bool $skipQueue = false;

    public bool $atomic = false;

    public string $source = '';

    public string $reference = '';

    public string $destination = '';

    public string $description = '';

    public string $currency = '';

    public string $balanceId = '';

    public string $scheduledFor = '';

    /** JSON: "inflight_expiry_date,omitempty". */
    public string $inflightExpiryDate = '';

    /** JSON: "inflight_commit_date,omitempty". */
    public string $inflightCommitDate = '';

    /** @var Distribution[] */
    public array $sources = [];

    /** @var Distribution[] */
    public array $destinations = [];

    /** @var array<string, mixed>|null */
    public ?array $metaData = null;

    /** Go: *time.Time `json:"effective_date,omitempty"`. */
    public ?\DateTimeImmutable $effectiveDate = null;

    /**
     * Binds a decoded JSON object with encoding/json's semantics (see
     * {@see JsonBinding}); $struct is the Go struct path used in type
     * mismatch messages.
     *
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException on a JSON type mismatch (Gin bind error)
     */
    public static function fromArray(array $data, string $struct = 'RecordTransaction'): self
    {
        $t = new self();
        $t->amount = JsonBinding::float($data, 'amount', $struct);
        $t->precision = JsonBinding::float($data, 'precision', $struct);
        $t->overdraftLimit = JsonBinding::float($data, 'overdraft_limit', $struct);
        $t->preciseAmount = JsonBinding::bigInt($data, 'precise_amount', $struct);
        $t->allowOverDraft = JsonBinding::bool($data, 'allow_overdraft', $struct);
        $t->inflight = JsonBinding::bool($data, 'inflight', $struct);
        $t->skipQueue = JsonBinding::bool($data, 'skip_queue', $struct);
        $t->atomic = JsonBinding::bool($data, 'atomic', $struct);
        $t->source = JsonBinding::string($data, 'source', $struct);
        $t->reference = JsonBinding::string($data, 'reference', $struct);
        $t->destination = JsonBinding::string($data, 'destination', $struct);
        $t->description = JsonBinding::string($data, 'description', $struct);
        $t->currency = JsonBinding::string($data, 'currency', $struct);
        $t->balanceId = JsonBinding::string($data, 'balance_id', $struct);
        $t->scheduledFor = JsonBinding::string($data, 'scheduled_for', $struct);
        $t->inflightExpiryDate = JsonBinding::string($data, 'inflight_expiry_date', $struct);
        $t->inflightCommitDate = JsonBinding::string($data, 'inflight_commit_date', $struct);
        $t->sources = self::distributionsFromArray($data, 'sources', $struct);
        $t->destinations = self::distributionsFromArray($data, 'destinations', $struct);
        $t->metaData = JsonBinding::map($data, 'meta_data', $struct);
        $t->effectiveDate = JsonBinding::time($data, 'effective_date', $struct);
        return $t;
    }

    /**
     * distributionsFromArray binds a `[]model.Distribution` field (a JSON
     * null element decodes to the zero Distribution, as in Go).
     *
     * @param array<string, mixed> $data
     *
     * @return Distribution[]
     *
     * @throws \RuntimeException on a JSON type mismatch
     */
    private static function distributionsFromArray(array $data, string $key, string $struct): array
    {
        $items = JsonBinding::objectList($data, $key, $struct, 'model.Distribution');
        if ($items === null) {
            return [];
        }
        $path = $struct . '.' . $key;
        $out = [];
        foreach ($items as $item) {
            $item ??= [];
            $d = new Distribution();
            $d->identifier = JsonBinding::string($item, 'identifier', $path);
            $d->distribution = JsonBinding::string($item, 'distribution', $path);
            $d->preciseDistribution = JsonBinding::string($item, 'precise_distribution', $path);
            $d->transactionID = JsonBinding::string($item, 'transaction_id', $path);
            $out[] = $d;
        }
        return $out;
    }

    /**
     * ValidateRecordTransaction runs the ozzo-validation rules of model.go
     * (`validation.ValidateStruct`) and throws a {@see ValidationErrors} keyed
     * by JSON field name when any rule fails.
     *
     * @throws ValidationErrors
     */
    public function validateRecordTransaction(): void
    {
        ModelHelpers::validateStruct([
            'amount' => function (): void {
                if ($this->amount != 0 && $this->preciseAmount !== null) {
                    throw new \RuntimeException('either amount or precise_amount should be provided, not both');
                }
                if ($this->amount == 0 && $this->preciseAmount === null) {
                    throw new \RuntimeException('either amount or precise_amount is required');
                }

                // Check for high precision amounts that might lead to rounding errors
                if ($this->amount != 0) {
                    // Convert to string to check significant digits
                    // (Go: strconv.FormatFloat(t.Amount, 'f', -1, 64))
                    $amountStr = CoreModelHelpers::goFloatString($this->amount);

                    // Remove decimal point for counting significant digits
                    $dot = strpos($amountStr, '.');
                    if ($dot !== false) {
                        $amountStr = substr_replace($amountStr, '', $dot, 1);
                    }

                    // Remove leading zeros which aren't significant
                    $amountStr = ltrim($amountStr, '0');

                    // Count significant digits
                    $significantDigits = \strlen($amountStr);

                    // If more than 15 significant digits, warn about potential rounding errors
                    if ($significantDigits > 15) {
                        throw new \RuntimeException('amount has more than 15 significant digits which may cause rounding errors; use precise_amount instead');
                    }
                }
            },
            'precision' => function (): void {
                ModelHelpers::validatePrecisionIsInteger($this->precision);
            },
            'currency' => function (): void {
                ModelHelpers::required($this->currency);
            },
            'reference' => function (): void {
                ModelHelpers::required($this->reference);
            },
            'description' => function (): void {
                ModelHelpers::required($this->description);
            },
            'source' => ModelHelpers::sourceOrSourcesValidation($this),
            'destination' => ModelHelpers::destinationOrDestinationsValidation($this),
            'scheduled_for' => function (): void {
                if ($this->scheduledFor !== '') { // validation.When
                    ModelHelpers::validateDateFormat(JsonBinding::RFC3339, $this->scheduledFor);
                }
            },
            'inflight_expiry_date' => function (): void {
                if ($this->inflightExpiryDate !== '') { // validation.When
                    ModelHelpers::validateDateFormat(JsonBinding::RFC3339, $this->inflightExpiryDate);
                }
            },
            'inflight_commit_date' => function (): void {
                if ($this->inflightCommitDate !== '') { // validation.When
                    ModelHelpers::validateDateFormat(JsonBinding::RFC3339, $this->inflightCommitDate);
                }
            },
        ]);
    }

    /**
     * parseDate ports the `time.Parse("2006-01-02T15:04:05Z07:00", value)`
     * calls of ToTransaction: a parse error is logged and the zero time
     * (null in the PHP models) is used.
     */
    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        try {
            return JsonBinding::parseRFC3339($value);
        } catch (\RuntimeException $err) {
            Log::get()->error($err->getMessage());
            return null;
        }
    }

    /**
     * ToTransaction converts the request payload into the domain Transaction.
     */
    public function toTransaction(): Transaction
    {
        $scheduledFor = null;
        $inflightExpiryDate = null;
        $inflightCommitDate = null;

        if ($this->scheduledFor !== '') {
            $scheduledFor = self::parseDate($this->scheduledFor);
        }

        if ($this->inflightExpiryDate !== '') {
            $inflightExpiryDate = self::parseDate($this->inflightExpiryDate);
        }

        if ($this->inflightCommitDate !== '') {
            $inflightCommitDate = self::parseDate($this->inflightCommitDate);
        }

        $transaction = new Transaction();
        $transaction->currency = $this->currency;
        $transaction->source = $this->source;
        $transaction->description = $this->description;
        $transaction->reference = $this->reference;
        $transaction->scheduledFor = $scheduledFor;
        $transaction->destination = $this->destination;
        $transaction->amount = $this->amount;
        $transaction->allowOverdraft = $this->allowOverDraft;
        $transaction->metaData = $this->metaData;
        $transaction->sources = $this->sources;
        $transaction->destinations = $this->destinations;
        $transaction->inflight = $this->inflight;
        $transaction->precision = $this->precision;
        $transaction->inflightExpiryDate = $inflightExpiryDate;
        $transaction->inflightCommitDate = $inflightCommitDate;
        $transaction->skipQueue = $this->skipQueue;
        $transaction->effectiveDate = $this->effectiveDate;
        $transaction->overdraftLimit = $this->overdraftLimit;
        $transaction->preciseAmount = $this->preciseAmount;
        $transaction->atomic = $this->atomic;
        return $transaction;
    }

    public function jsonSerialize(): array
    {
        $out = [
            'amount' => $this->amount,
            'precision' => $this->precision,
            'overdraft_limit' => $this->overdraftLimit,
            'precise_amount' => CoreModelHelpers::bigIntegerToJson($this->preciseAmount),
            'allow_overdraft' => $this->allowOverDraft,
            'inflight' => $this->inflight,
            'skip_queue' => $this->skipQueue,
            'atomic' => $this->atomic,
            'source' => $this->source,
            'reference' => $this->reference,
            'destination' => $this->destination,
            'description' => $this->description,
            'currency' => $this->currency,
            'balance_id' => $this->balanceId,
            'scheduled_for' => $this->scheduledFor,
        ];
        if ($this->inflightExpiryDate !== '') { // omitempty
            $out['inflight_expiry_date'] = $this->inflightExpiryDate;
        }
        if ($this->inflightCommitDate !== '') { // omitempty
            $out['inflight_commit_date'] = $this->inflightCommitDate;
        }
        // Go nil slices / nil maps marshal as null.
        $out['sources'] = \count($this->sources) > 0 ? array_values($this->sources) : null;
        $out['destinations'] = \count($this->destinations) > 0 ? array_values($this->destinations) : null;
        $out['meta_data'] = CoreModelHelpers::mapToJson($this->metaData);
        if ($this->effectiveDate !== null) { // omitempty (*time.Time)
            $out['effective_date'] = CoreModelHelpers::goTimeString($this->effectiveDate);
        }
        return $out;
    }
}
