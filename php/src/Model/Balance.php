<?php

declare(strict_types=1);

namespace Blnk\Model;

use Brick\Math\BigInteger;

/**
 * Balance is a ledger balance with committed, inflight and (optionally)
 * queued sub-balances (Go: model.Balance, balance.go + methods in model.go).
 *
 * Ported from:
 *
 *   Copyright 2024 Blnk Finance Authors.
 *   Licensed under the Apache License, Version 2.0.
 *
 * BigInteger is immutable (unlike Go's *big.Int), so every mutation method
 * reassigns the property (e.g. `$this->creditBalance =
 * $this->creditBalance->plus($x)`), preserving the semantics of the Go
 * in-place mutations.
 */
final class Balance implements \JsonSerializable
{
    /** Go json:"-" (database serial id). */
    public int $id = 0;

    public ?BigInteger $balance = null;

    public int $version = 0;

    public ?BigInteger $inflightBalance = null;

    public ?BigInteger $creditBalance = null;

    public ?BigInteger $inflightCreditBalance = null;

    public ?BigInteger $debitBalance = null;

    public ?BigInteger $inflightDebitBalance = null;

    public ?BigInteger $queuedDebitBalance = null;

    public ?BigInteger $queuedCreditBalance = null;

    public string $ledgerID = '';

    public string $identityID = '';

    public string $balanceID = '';

    public string $indicator = '';

    public string $currency = '';

    public ?Identity $identity = null;

    public ?Ledger $ledger = null;

    public ?\DateTimeImmutable $createdAt = null;

    public ?\DateTimeImmutable $inflightExpiresAt = null;

    /** @var array<string, mixed>|null */
    public ?array $metaData = null;

    public bool $trackFundLineage = false;

    public string $allocationStrategy = '';

    /**
     * InitializeBalanceFields initializes all the fields of the Balance struct that might be nil.
     * This ensures that all balance-related fields have valid big integer values for further operations.
     */
    public function initializeBalanceFields(): void
    {
        if ($this->inflightDebitBalance === null) {
            $this->inflightDebitBalance = BigInteger::zero();
        }
        if ($this->inflightCreditBalance === null) {
            $this->inflightCreditBalance = BigInteger::zero();
        }
        if ($this->inflightBalance === null) {
            $this->inflightBalance = BigInteger::zero();
        }
        if ($this->debitBalance === null) {
            $this->debitBalance = BigInteger::zero();
        }
        if ($this->creditBalance === null) {
            $this->creditBalance = BigInteger::zero();
        }
        if ($this->balance === null) {
            $this->balance = BigInteger::zero();
        }
    }

    /**
     * addCredit adds the specified amount to the credit balances (either inflight or regular).
     * inflight indicates whether the credit is inflight or not.
     *
     * @internal unexported in Go; public so ModelHelpers::updateBalances can call it.
     */
    public function addCredit(BigInteger $amountBigInt, bool $inflight): void
    {
        $this->initializeBalanceFields(); // Ensure balance fields are initialized.

        if ($inflight) {
            $this->inflightCreditBalance = $this->inflightCreditBalance->plus($amountBigInt);
        } else {
            $this->creditBalance = $this->creditBalance->plus($amountBigInt);
        }
    }

    /**
     * addDebit adds the specified amount to the debit balances (either inflight or regular).
     * inflight indicates whether the debit is inflight or not.
     *
     * @internal unexported in Go; public so ModelHelpers::updateBalances can call it.
     */
    public function addDebit(BigInteger $amountBigInt, bool $inflight): void
    {
        $this->initializeBalanceFields();
        if ($inflight) {
            $this->inflightDebitBalance = $this->inflightDebitBalance->plus($amountBigInt);
        } else {
            $this->debitBalance = $this->debitBalance->plus($amountBigInt);
        }
    }

    /**
     * computeBalance computes the overall balance for inflight and normal balances.
     * inflight indicates whether the inflight balance or regular balance should be computed.
     *
     * @internal unexported in Go; public so ModelHelpers::updateBalances can call it.
     */
    public function computeBalance(bool $inflight): void
    {
        $this->initializeBalanceFields();
        if ($inflight) {
            $this->inflightBalance = $this->inflightCreditBalance->minus($this->inflightDebitBalance);
            return;
        }
        $this->balance = $this->creditBalance->minus($this->debitBalance);
    }

    /**
     * checkSufficientInflightDebit throws when the inflight debit balance
     * cannot cover amount (Go returns an error).
     */
    private function checkSufficientInflightDebit(BigInteger $amount): void
    {
        if ($this->inflightDebitBalance->compareTo($amount) < 0) {
            throw new \RuntimeException(sprintf(
                'insufficient inflight debit balance: have %s, need %s',
                (string) $this->inflightDebitBalance,
                (string) $amount
            ));
        }
    }

    /**
     * checkSufficientInflightCredit mirrors checkSufficientInflightDebit for the credit side.
     */
    private function checkSufficientInflightCredit(BigInteger $amount): void
    {
        if ($this->inflightCreditBalance->compareTo($amount) < 0) {
            throw new \RuntimeException(sprintf(
                'insufficient inflight credit balance: have %s, need %s',
                (string) $this->inflightCreditBalance,
                (string) $amount
            ));
        }
    }

    /**
     * CommitInflightDebit commits a debit from the inflight balance and adds it to the
     * debit balance, throwing if the inflight hold cannot cover the amount
     * (Go returns an error).
     */
    public function commitInflightDebit(Transaction $transaction): void
    {
        $this->initializeBalanceFields();
        $transactionAmount = ModelHelpers::applyPrecision($transaction);

        $this->checkSufficientInflightDebit($transactionAmount);
        // Deduct from inflight and add to regular debit balance.
        $this->inflightDebitBalance = $this->inflightDebitBalance->minus($transactionAmount);
        $this->debitBalance = $this->debitBalance->plus($transactionAmount);
        $this->computeBalance(true);  // Recompute inflight balance.
        $this->computeBalance(false); // Recompute regular balance.
    }

    /**
     * CommitInflightCredit commits a credit from the inflight balance and adds it to the credit balance.
     */
    public function commitInflightCredit(Transaction $transaction): void
    {
        $this->initializeBalanceFields();
        $transactionAmount = ModelHelpers::applyPrecision($transaction);

        $this->checkSufficientInflightCredit($transactionAmount);
        // Deduct from inflight and add to regular credit balance.
        $this->inflightCreditBalance = $this->inflightCreditBalance->minus($transactionAmount);
        $this->creditBalance = $this->creditBalance->plus($transactionAmount);
        $this->computeBalance(true);  // Recompute inflight balance.
        $this->computeBalance(false); // Recompute regular balance.
    }

    /**
     * RollbackInflightCredit rolls back (decreases) the inflight credit balance by the specified amount.
     */
    public function rollbackInflightCredit(BigInteger $amount): void
    {
        $this->initializeBalanceFields();
        $this->checkSufficientInflightCredit($amount);
        $this->inflightCreditBalance = $this->inflightCreditBalance->minus($amount);
        $this->computeBalance(true); // Update inflight balance.
    }

    /**
     * RollbackInflightDebit rolls back (decreases) the inflight debit balance by the specified amount.
     */
    public function rollbackInflightDebit(BigInteger $amount): void
    {
        $this->initializeBalanceFields();
        $this->checkSufficientInflightDebit($amount);
        $this->inflightDebitBalance = $this->inflightDebitBalance->minus($amount);
        $this->computeBalance(true); // Update inflight balance.
    }

    public static function fromArray(array $data): self
    {
        $b = new self();
        $b->balance = ModelHelpers::bigIntegerFromJson($data['balance'] ?? null);
        $b->version = (int) ($data['version'] ?? 0);
        $b->inflightBalance = ModelHelpers::bigIntegerFromJson($data['inflight_balance'] ?? null);
        $b->creditBalance = ModelHelpers::bigIntegerFromJson($data['credit_balance'] ?? null);
        $b->inflightCreditBalance = ModelHelpers::bigIntegerFromJson($data['inflight_credit_balance'] ?? null);
        $b->debitBalance = ModelHelpers::bigIntegerFromJson($data['debit_balance'] ?? null);
        $b->inflightDebitBalance = ModelHelpers::bigIntegerFromJson($data['inflight_debit_balance'] ?? null);
        $b->queuedDebitBalance = ModelHelpers::bigIntegerFromJson($data['queued_debit_balance'] ?? null);
        $b->queuedCreditBalance = ModelHelpers::bigIntegerFromJson($data['queued_credit_balance'] ?? null);
        $b->ledgerID = (string) ($data['ledger_id'] ?? '');
        $b->identityID = (string) ($data['identity_id'] ?? '');
        $b->balanceID = (string) ($data['balance_id'] ?? '');
        $b->indicator = (string) ($data['indicator'] ?? '');
        $b->currency = (string) ($data['currency'] ?? '');
        $b->identity = isset($data['identity']) && \is_array($data['identity']) ? Identity::fromArray($data['identity']) : null;
        $b->ledger = isset($data['ledger']) && \is_array($data['ledger']) ? Ledger::fromArray($data['ledger']) : null;
        $b->createdAt = ModelHelpers::parseTime($data['created_at'] ?? null);
        $b->inflightExpiresAt = ModelHelpers::parseTime($data['inflight_expires_at'] ?? null);
        $metaData = $data['meta_data'] ?? null;
        $b->metaData = \is_array($metaData) ? $metaData : null;
        $b->trackFundLineage = (bool) ($data['track_fund_lineage'] ?? false);
        $b->allocationStrategy = (string) ($data['allocation_strategy'] ?? '');
        return $b;
    }

    public function jsonSerialize(): array
    {
        $out = [];
        $out['balance'] = ModelHelpers::bigIntegerToJson($this->balance);
        $out['version'] = $this->version;
        $out['inflight_balance'] = ModelHelpers::bigIntegerToJson($this->inflightBalance);
        $out['credit_balance'] = ModelHelpers::bigIntegerToJson($this->creditBalance);
        $out['inflight_credit_balance'] = ModelHelpers::bigIntegerToJson($this->inflightCreditBalance);
        $out['debit_balance'] = ModelHelpers::bigIntegerToJson($this->debitBalance);
        $out['inflight_debit_balance'] = ModelHelpers::bigIntegerToJson($this->inflightDebitBalance);
        if ($this->queuedDebitBalance !== null) { // omitempty (*big.Int)
            $out['queued_debit_balance'] = ModelHelpers::bigIntegerToJson($this->queuedDebitBalance);
        }
        if ($this->queuedCreditBalance !== null) { // omitempty (*big.Int)
            $out['queued_credit_balance'] = ModelHelpers::bigIntegerToJson($this->queuedCreditBalance);
        }
        $out['ledger_id'] = $this->ledgerID;
        $out['identity_id'] = $this->identityID;
        $out['balance_id'] = $this->balanceID;
        if ($this->indicator !== '') { // omitempty
            $out['indicator'] = $this->indicator;
        }
        $out['currency'] = $this->currency;
        if ($this->identity !== null) { // omitempty (*Identity)
            $out['identity'] = $this->identity;
        }
        if ($this->ledger !== null) { // omitempty (*Ledger)
            $out['ledger'] = $this->ledger;
        }
        $out['created_at'] = ModelHelpers::goTimeString($this->createdAt);
        $out['inflight_expires_at'] = ModelHelpers::goTimeString($this->inflightExpiresAt);
        $out['meta_data'] = ModelHelpers::mapToJson($this->metaData);
        $out['track_fund_lineage'] = $this->trackFundLineage;
        if ($this->allocationStrategy !== '') { // omitempty
            $out['allocation_strategy'] = $this->allocationStrategy;
        }
        return $out;
    }
}
