<?php

declare(strict_types=1);

namespace Blnk\Model;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;

/**
 * distributionState holds the shared state during distribution calculation.
 *
 * @internal Go's unexported `distributionState` struct (transaction.go); it is
 * plumbing for ModelHelpers::calculateDistributionsPrecise and is not part of
 * the JSON model surface (no tags in Go, no JsonSerializable here).
 */
final class DistributionState
{
    public BigDecimal $totalAmountDec;
    public BigDecimal $amountLeftDec;
    public BigDecimal $precisionDec;
    public BigDecimal $totalPercentage;
    public BigDecimal $fixedTotalDec;

    /** @var array<string, BigDecimal> */
    public array $fixedAmounts = [];

    /** @var array<string, BigDecimal> */
    public array $percentAmounts = [];

    /** @var array<string, BigInteger> */
    public array $result = [];

    public function __construct()
    {
        // Go zero values: decimal.Decimal zero value is 0.
        $this->totalAmountDec = BigDecimal::zero();
        $this->amountLeftDec = BigDecimal::zero();
        $this->precisionDec = BigDecimal::zero();
        $this->totalPercentage = BigDecimal::zero();
        $this->fixedTotalDec = BigDecimal::zero();
    }
}
