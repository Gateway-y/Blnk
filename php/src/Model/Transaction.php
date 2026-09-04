<?php

declare(strict_types=1);

namespace Blnk\Model;

use Brick\Math\BigInteger;

/**
 * Transaction is the core ledger movement record (Go: model.Transaction,
 * transaction.go).
 *
 * Ported from:
 *
 *   Copyright 2024 Blnk Finance Authors.
 *   Licensed under the Apache License, Version 2.0.
 *
 * Field notes:
 *  - Non-pointer Go time.Time fields (created_at, scheduled_for,
 *    inflight_expiry_date, inflight_commit_date) are ALWAYS serialized —
 *    `omitempty` never fires on a struct in Go — a null property stands for
 *    Go's zero time and serializes as "0001-01-01T00:00:00Z".
 *  - `effective_date` is a *time.Time in Go, so it IS omitted when null.
 */
final class Transaction implements \JsonSerializable
{
    /** Go json:"-" (database serial id). */
    public int $id = 0;

    /** Go: *big.Int `json:"precise_amount,omitempty"`. */
    public ?BigInteger $preciseAmount = null;

    public float $amount = 0.0;

    public string $amountString = '';

    public float $precision = 0.0;

    public float $overdraftLimit = 0.0;

    public string $transactionID = '';

    public string $parentTransaction = '';

    public string $source = '';

    public string $destination = '';

    public string $reference = '';

    public string $currency = '';

    public string $description = '';

    public string $status = '';

    public string $hash = '';

    public bool $allowOverdraft = false;

    public bool $inflight = false;

    /** Go json:"-". */
    public bool $skipBalanceUpdate = false;

    public bool $skipQueue = false;

    public bool $atomic = false;

    /**
     * Go json:"-".
     *
     * @var string[]
     */
    public array $groupIds = [];

    /** @var Distribution[] */
    public array $sources = [];

    /** @var Distribution[] */
    public array $destinations = [];

    public ?\DateTimeImmutable $createdAt = null;

    public ?\DateTimeImmutable $effectiveDate = null;

    public ?\DateTimeImmutable $scheduledFor = null;

    public ?\DateTimeImmutable $inflightExpiryDate = null;

    public ?\DateTimeImmutable $inflightCommitDate = null;

    /** @var array<string, mixed>|null */
    public ?array $metaData = null;

    /**
     * ToJSON serializes the transaction to a JSON string (Go: ToJSON returning
     * ([]byte, error); errors throw \JsonException). The Go version records an
     * OTEL span; tracing is a no-op in this port.
     */
    public function toJSON(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * GetEffectiveDate returns the effective date, falling back to CreatedAt
     * for old records. (A null CreatedAt stands for Go's zero time.)
     */
    public function getEffectiveDate(): \DateTimeImmutable
    {
        if ($this->effectiveDate !== null) {
            return $this->effectiveDate;
        }
        // Fall back to CreatedAt for old records
        return $this->createdAt ?? new \DateTimeImmutable('0001-01-01T00:00:00+00:00');
    }

    /**
     * HashTxn generates a SHA-256 hash of a transaction's relevant fields.
     * This ensures the integrity of the transaction by creating a unique hash from its details.
     *
     * The amount is formatted exactly like Go's
     * strconv.FormatFloat(amount, 'f', -1, 64) via ModelHelpers::goFloatString.
     */
    public function hashTxn(): string
    {
        $data = sprintf(
            '%s%s%s%s%s',
            ModelHelpers::goFloatString($this->amount),
            $this->reference,
            $this->currency,
            $this->source,
            $this->destination
        );
        return hash('sha256', $data); // Hash the concatenated data, hex-encoded.
    }

    /**
     * validate checks if the transaction has a positive amount.
     *
     * @internal unexported in Go; public so ModelHelpers::updateBalances can call it.
     *
     * @throws \RuntimeException (Go returns an error)
     */
    public function validate(): void
    {
        if ($this->preciseAmount !== null) {
            if ($this->preciseAmount->getSign() <= 0) {
                throw new \RuntimeException('transaction precise amount must be positive');
            }
            return;
        }
        if ($this->amount <= 0) {
            throw new \RuntimeException('transaction amount must be positive');
        }
    }

    /**
     * SplitTransactionPrecise splits the transaction according to its Sources
     * or Destinations distributions using precise (big integer) arithmetic.
     *
     * The Go version iterates the distribution map in random order; PHP
     * iterates in insertion order (fixed, then percentage, then "left"), which
     * is one of the orders Go could produce. The Go context/tracing parameters
     * are dropped (no-op tracing per PORTING.md).
     *
     * @return Transaction[]
     *
     * @throws \RuntimeException on distribution errors
     */
    public function splitTransactionPrecise(): array
    {
        $ds = [];
        if (\count($this->sources) > 0) {
            $ds = $this->sources;
        } elseif (\count($this->destinations) > 0) {
            $ds = $this->destinations;
        }

        // Use PreciseAmount for distribution calculation
        $precisionInt = (int) $this->precision; // Go: int64(transaction.Precision) truncates
        if ($this->preciseAmount === null) {
            // Go would panic dereferencing a nil PreciseAmount (span attribute +
            // CalculateDistributionsPrecise); mirror the hard failure.
            throw new \TypeError('transaction precise amount is not set');
        }
        $distributions = ModelHelpers::calculateDistributionsPrecise($this->preciseAmount, $ds, $precisionInt);

        $transactions = [];
        $counter = 1;
        foreach ($distributions as $direction => $preciseAmount) {
            $direction = (string) $direction;
            $newTransaction = clone $this;                                                 // Create a copy of the original transaction
            $newTransaction->transactionID = ModelHelpers::generateUUIDWithSuffix('txn');  // Set the transaction ID
            $newTransaction->preciseAmount = $preciseAmount;                               // Set the precise amount based on the distribution

            // Convert PreciseAmount to Amount for backward compatibility
            ModelHelpers::convertPreciseToDecimal($newTransaction);

            $newTransaction->hash = $newTransaction->hashTxn();          // Set the transaction hash
            $newTransaction->sources = [];                               // Clear the Sources slice
            $newTransaction->destinations = [];                          // Clear the Destinations slice
            $newTransaction->parentTransaction = $this->transactionID;   // Set the parent transaction ID

            if (\count($this->sources) > 0) {
                $newTransaction->source = $direction; // Set the source
                $this->sources[$counter - 1]->transactionID = $newTransaction->transactionID;
            } elseif (\count($this->destinations) > 0) {
                $newTransaction->destination = $direction; // Set the destination
                $this->destinations[$counter - 1]->transactionID = $newTransaction->transactionID;
            }

            $newTransaction->reference = sprintf('%s-%d', $this->reference, $counter);
            $counter++;
            $transactions[] = $newTransaction;
        }

        return $transactions;
    }

    public static function fromArray(array $data): self
    {
        $t = new self();
        $t->preciseAmount = ModelHelpers::bigIntegerFromJson($data['precise_amount'] ?? null);
        $t->amount = (float) ($data['amount'] ?? 0.0);
        $t->amountString = (string) ($data['amount_string'] ?? '');
        $t->precision = (float) ($data['precision'] ?? 0.0);
        $t->overdraftLimit = (float) ($data['overdraft_limit'] ?? 0.0);
        $t->transactionID = (string) ($data['transaction_id'] ?? '');
        $t->parentTransaction = (string) ($data['parent_transaction'] ?? '');
        $t->source = (string) ($data['source'] ?? '');
        $t->destination = (string) ($data['destination'] ?? '');
        $t->reference = (string) ($data['reference'] ?? '');
        $t->currency = (string) ($data['currency'] ?? '');
        $t->description = (string) ($data['description'] ?? '');
        $t->status = (string) ($data['status'] ?? '');
        $t->hash = (string) ($data['hash'] ?? '');
        $t->allowOverdraft = (bool) ($data['allow_overdraft'] ?? false);
        $t->inflight = (bool) ($data['inflight'] ?? false);
        $t->skipQueue = (bool) ($data['skip_queue'] ?? false);
        $t->atomic = (bool) ($data['atomic'] ?? false);

        foreach (($data['sources'] ?? []) as $dist) {
            if (\is_array($dist)) {
                $t->sources[] = Distribution::fromArray($dist);
            }
        }
        foreach (($data['destinations'] ?? []) as $dist) {
            if (\is_array($dist)) {
                $t->destinations[] = Distribution::fromArray($dist);
            }
        }

        $t->createdAt = ModelHelpers::parseTime($data['created_at'] ?? null);
        $t->effectiveDate = ModelHelpers::parseTime($data['effective_date'] ?? null);
        $t->scheduledFor = ModelHelpers::parseTime($data['scheduled_for'] ?? null);
        $t->inflightExpiryDate = ModelHelpers::parseTime($data['inflight_expiry_date'] ?? null);
        $t->inflightCommitDate = ModelHelpers::parseTime($data['inflight_commit_date'] ?? null);

        $metaData = $data['meta_data'] ?? null;
        $t->metaData = \is_array($metaData) ? $metaData : null;

        return $t;
    }

    public function jsonSerialize(): array
    {
        $out = [];
        if ($this->preciseAmount !== null) { // omitempty (*big.Int)
            $out['precise_amount'] = ModelHelpers::bigIntegerToJson($this->preciseAmount);
        }
        $out['amount'] = $this->amount;
        if ($this->amountString !== '') { // omitempty
            $out['amount_string'] = $this->amountString;
        }
        $out['precision'] = $this->precision;
        $out['overdraft_limit'] = $this->overdraftLimit;
        $out['transaction_id'] = $this->transactionID;
        $out['parent_transaction'] = $this->parentTransaction;
        if ($this->source !== '') { // omitempty
            $out['source'] = $this->source;
        }
        if ($this->destination !== '') { // omitempty
            $out['destination'] = $this->destination;
        }
        $out['reference'] = $this->reference;
        $out['currency'] = $this->currency;
        if ($this->description !== '') { // omitempty
            $out['description'] = $this->description;
        }
        $out['status'] = $this->status;
        $out['hash'] = $this->hash;
        $out['allow_overdraft'] = $this->allowOverdraft;
        $out['inflight'] = $this->inflight;
        $out['skip_queue'] = $this->skipQueue;
        $out['atomic'] = $this->atomic;
        if (\count($this->sources) > 0) { // omitempty
            $out['sources'] = array_values($this->sources);
        }
        if (\count($this->destinations) > 0) { // omitempty
            $out['destinations'] = array_values($this->destinations);
        }
        $out['created_at'] = ModelHelpers::goTimeString($this->createdAt);
        if ($this->effectiveDate !== null) { // omitempty (*time.Time)
            $out['effective_date'] = ModelHelpers::goTimeString($this->effectiveDate);
        }
        // time.Time values are never "empty" to Go's encoding/json, so the
        // omitempty tags on the next three fields never fire.
        $out['scheduled_for'] = ModelHelpers::goTimeString($this->scheduledFor);
        $out['inflight_expiry_date'] = ModelHelpers::goTimeString($this->inflightExpiryDate);
        $out['inflight_commit_date'] = ModelHelpers::goTimeString($this->inflightCommitDate);
        if ($this->metaData !== null && \count($this->metaData) > 0) { // omitempty (map)
            $out['meta_data'] = $this->metaData;
        }
        return $out;
    }
}
