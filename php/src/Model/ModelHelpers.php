<?php

declare(strict_types=1);

namespace Blnk\Model;

use Blnk\Internal\Log;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Ramsey\Uuid\Uuid;

/**
 * ModelHelpers hosts the standalone (package-level) functions of the Go
 * `model` package (model.go + transaction.go) as static methods, per
 * PORTING.md naming rules.
 *
 * Numeric conventions used throughout (see the individual method docs):
 *  - Go `*big.Int`            → Brick\Math\BigInteger (immutable; results are reassigned).
 *  - Go `decimal.Decimal`     → Brick\Math\BigDecimal. shopspring's `Div` rounds
 *    half-away-from-zero at 16 decimal places (DivisionPrecision = 16); that maps to
 *    `dividedBy($x, 16, RoundingMode::HALF_UP)`. shopspring `Round(0)` maps to
 *    `toScale(0, RoundingMode::HALF_UP)`, and `Decimal.BigInt()` (truncation toward
 *    zero) maps to `toScale(0, RoundingMode::DOWN)->toBigInteger()`.
 *  - Go `decimal.NewFromFloat(f)` → `BigDecimal::of(self::goFloatString($f))`
 *    (both use the shortest round-trip decimal representation of the float).
 */
final class ModelHelpers
{
    /** Not instantiable: static helpers only. */
    private function __construct()
    {
    }

    // ---------------------------------------------------------------------
    // model.go
    // ---------------------------------------------------------------------

    /**
     * GenerateUUIDWithSuffix generates a UUID with a given module name as a suffix.
     * This is useful for creating unique identifiers with context-specific prefixes.
     */
    public static function generateUUIDWithSuffix(string $module): string
    {
        $uuidStr = Uuid::uuid4()->toString(); // Generate a new UUID.
        return sprintf('%s_%s', $module, $uuidStr); // Append the module as a suffix to the UUID.
    }

    /**
     * Int64ToBigInt converts an int64 value to a *big.Int (here: BigInteger).
     * This is useful for handling large numbers in computations such as financial transactions.
     */
    public static function intToBigInteger(int $value): BigInteger
    {
        return BigInteger::of($value); // Create a new big.Int from an int64 value.
    }

    /**
     * compare compares two big integer values based on the provided condition (e.g., >, <, ==).
     * Returns true if the condition holds, otherwise false.
     */
    public static function compare(BigInteger $value, string $condition, BigInteger $compareTo): bool
    {
        $cmp = $value->compareTo($compareTo); // Compare value and compareTo.
        switch ($condition) {
            case '>':
                return $cmp > 0;
            case '<':
                return $cmp < 0;
            case '>=':
                return $cmp >= 0;
            case '<=':
                return $cmp <= 0;
            case '!=':
                return $cmp !== 0;
            case '==':
                return $cmp === 0;
        }
        return false;
    }

    /**
     * canProcessTransaction checks if a transaction can be processed given the source balance.
     * It throws a \RuntimeException if the balance is insufficient and overdraft is not allowed
     * (Go returns an error).
     * This function includes inflight balances and optionally queued balances in the available balance calculation
     * to prevent creating transactions that would exceed the actual available funds.
     *
     * @throws \RuntimeException "transaction exceeds overdraft limit" | "insufficient funds in source balance"
     */
    public static function canProcessTransaction(Transaction $transaction, Balance $sourceBalance): void
    {
        if ($transaction->allowOverdraft && $transaction->overdraftLimit == 0.0) {
            // If unconditional overdraft is allowed, skip all balance checks
            return;
        }

        // Initialize balance fields to ensure inflight balances are not nil
        $sourceBalance->initializeBalanceFields();

        // Convert transaction.PreciseAmount to *big.Int for comparison.
        $transactionAmount = $transaction->preciseAmount;
        if ($transactionAmount === null) {
            // Go would panic on a nil *big.Int here; the callers (UpdateBalances)
            // always apply precision first. Mirror the hard failure.
            throw new \TypeError('transaction precise amount is not set');
        }

        // Calculate available balance by subtracting inflight balances from committed balance
        // This ensures inflight balances are considered when checking if new transactions can be processed
        $availableBalance = $sourceBalance->balance->minus($sourceBalance->inflightDebitBalance);

        // If queued balances are available (when enable_queued_checks is on), also subtract queued debit balance
        // This provides an even more comprehensive check by considering pending queued transactions
        if ($sourceBalance->queuedDebitBalance !== null) {
            $availableBalance = $availableBalance->minus($sourceBalance->queuedDebitBalance);
        }

        if ($availableBalance->compareTo($transactionAmount) >= 0) {
            // Sufficient funds considering inflight and queued debits
            return;
        }

        // Insufficient funds, check if within overdraft limit
        if ($transaction->overdraftLimit > 0) {
            // Calculate the resulting balance after transaction, considering inflight and queued debits
            $resultingBalance = $availableBalance->minus($transactionAmount);

            // Convert overdraft limit to big.Int with precision applied using
            // decimal arithmetic: int64(limit*precision) truncates binary-float
            // products (0.29*100 -> 28), rejecting transactions exactly at the
            // configured limit.
            // (Go: decimal.NewFromFloat(limit).Mul(decimal.NewFromFloat(precision)).Round(0).IntPart())
            $overdraftLimitPrecise = BigDecimal::of(self::goFloatString($transaction->overdraftLimit))
                ->multipliedBy(BigDecimal::of(self::goFloatString($transaction->precision)))
                ->toScale(0, RoundingMode::HALF_UP)
                ->toBigInteger();

            // Negative of overdraft limit (as balance will be negative)
            $negativeOverdraftLimit = $overdraftLimitPrecise->negated();

            // Check if resulting balance is within overdraft limit
            if ($resultingBalance->compareTo($negativeOverdraftLimit) >= 0) {
                return;
            }
            throw new \RuntimeException('transaction exceeds overdraft limit');
        }

        // Insufficient funds and no overdraft allowed
        throw new \RuntimeException('insufficient funds in source balance');
    }

    /**
     * ApplyPrecision handles both operations involving precision:
     * 1. If PreciseAmount exists: converts it to a decimal Amount
     * 2. If Amount exists: converts it to a PreciseAmount
     * This function is now a wrapper that sets default precision if needed and calls applyPrecisionLogic.
     */
    public static function applyPrecision(Transaction $transaction): BigInteger
    {
        if ($transaction->precision == 0.0) {
            $transaction->precision = 1.0;
        }
        return self::applyPrecisionLogic($transaction);
    }

    /**
     * applyPrecisionLogic contains the core logic for converting amounts based on precision.
     * It assumes transaction.Precision has been set appropriately before this call.
     *
     * @internal unexported in Go; public here so Transaction/Balance code can reach it.
     */
    public static function applyPrecisionLogic(Transaction $transaction): BigInteger
    {
        // Ensure precision is not zero to avoid division by zero, though ApplyPrecision and ApplyPrecisionWithDBLookup should handle this.
        if ($transaction->precision == 0.0) {
            Log::get()->warning('applyPrecisionLogic called with transaction.Precision = 0, defaulting to 1');
            $transaction->precision = 1.0;
        }

        if ($transaction->preciseAmount !== null && $transaction->preciseAmount->compareTo(BigInteger::zero()) > 0) {
            self::convertPreciseToDecimal($transaction);
            return $transaction->preciseAmount;
        }

        $transaction->preciseAmount = self::convertDecimalToPrecise($transaction);
        return $transaction->preciseAmount;
    }

    /**
     * fetchTransactionPrecisionFromDB is a placeholder for fetching precision from the database.
     * In a real application, this would query your database.
     *
     * Go returns `(precision float64, found bool, err error)`; PHP returns
     * `[precision, found]` and throws \PDOException on query errors (callers
     * catch and fall back, mirroring the Go error path).
     *
     * @return array{0: float, 1: bool}
     *
     * @throws \PDOException
     */
    public static function fetchTransactionPrecisionFromDB(\PDO $db, string $transactionID): array
    {
        // Check cache first
        $precision = PrecisionCache::getCachedPrecision($transactionID);
        if ($precision !== null) {
            Log::get()->debug('cache hit for transaction precision', [
                'transaction_id' => $transactionID,
                'precision' => $precision,
            ]);
            return [$precision, true];
        }

        // First try to find precision by transaction_id
        // (SQL text copied verbatim from Go; $1 → positional ?, bound twice.)
        $query = '
		SELECT precision
		FROM blnk.transactions
		WHERE transaction_id = ?
		OR parent_transaction = ?
		LIMIT 1';

        try {
            $stmt = $db->prepare($query);
            $stmt->execute([$transactionID, $transactionID]);
            $value = $stmt->fetchColumn();
        } catch (\PDOException $err) {
            Log::get()->error('error querying precision', [
                'error' => $err->getMessage(),
                'transaction_id' => $transactionID,
            ]);
            throw $err;
        }

        if ($value === false) {
            // sql.ErrNoRows
            return [0.0, false];
        }

        $precision = (float) $value;
        if ($precision <= 0) { // Or any other validation for valid precision
            Log::get()->warning('invalid precision found in DB', [
                'transaction_id' => $transactionID,
                'precision' => $precision,
            ]);
            return [0.0, false]; // Treat invalid precision as not found for fallback logic
        }

        // Store in cache
        PrecisionCache::setCachedPrecision($transactionID, $precision);
        Log::get()->debug('cache miss, fetched precision from DB and cached', [
            'transaction_id' => $transactionID,
            'precision' => $precision,
        ]);
        return [$precision, true];
    }

    /**
     * ApplyPrecisionWithDBLookup attempts to fetch precision from the database
     * and then applies it to the transaction. Falls back to transaction-defined
     * precision or a default of 1 if DB lookup fails or precision is invalid.
     */
    public static function applyPrecisionWithDBLookup(Transaction $transaction, ?\PDO $db): BigInteger
    {
        $dbPrecision = 0.0;
        $found = false;

        if ($db !== null && $transaction->transactionID !== '') {
            try {
                [$dbPrecision, $found] = self::fetchTransactionPrecisionFromDB($db, $transaction->transactionID);
            } catch (\Throwable $err) {
                Log::get()->warning('error fetching precision from DB, using local/default precision', [
                    'error' => $err->getMessage(),
                    'transaction_id' => $transaction->transactionID,
                ]);
                // Fall through to use local or default precision
            }
        } else {
            Log::get()->debug('DB connection or TransactionID is nil/empty; skipping DB lookup for precision');
        }

        if ($found && $dbPrecision > 0) {
            $transaction->precision = $dbPrecision;
            Log::get()->debug('using precision from DB', [
                'transaction_id' => $transaction->transactionID,
                'precision' => $transaction->precision,
            ]);
        } else {
            if ($transaction->precision == 0.0) {
                Log::get()->debug('precision not found in DB, defaulting to 1', [
                    'transaction_id' => $transaction->transactionID,
                ]);
                $transaction->precision = 1.0;
            } else {
                Log::get()->debug('precision not found in DB, using pre-set precision', [
                    'transaction_id' => $transaction->transactionID,
                    'precision' => $transaction->precision,
                ]);
            }
        }

        return self::applyPrecisionLogic($transaction);
    }

    /**
     * convertPreciseToDecimal converts the precise integer amount to a decimal value
     * by dividing by precision, storing the exact string representation.
     *
     * shopspring semantics mirrored: `Div` rounds half-away-from-zero at 16
     * decimal places, and `String()` trims trailing zeros.
     *
     * @internal unexported in Go; public here so Transaction can reach it.
     */
    public static function convertPreciseToDecimal(Transaction $transaction): void
    {
        // Use the decimal package for exact decimal arithmetic
        // (Go ignores the NewFromString error, leaving a zero decimal; a nil
        // PreciseAmount therefore behaves as zero.)
        $preciseAmountStr = $transaction->preciseAmount !== null ? (string) $transaction->preciseAmount : '';
        $preciseAmountDec = self::decimalFromStringOrZero($preciseAmountStr);

        // Create decimal for precision
        $precisionDec = BigDecimal::of(self::goFloatString($transaction->precision));

        // Perform division with exact decimal arithmetic
        $resultDec = $preciseAmountDec->dividedBy($precisionDec, 16, RoundingMode::HALF_UP);

        // Store the exact string representation
        $transaction->amountString = (string) $resultDec->stripTrailingZeros();

        // Also store the float64 for backward compatibility
        // This may still have precision issues but is kept for existing code
        $transaction->amount = (float) $transaction->amountString;
    }

    /**
     * convertDecimalToPrecise converts a decimal amount to precise integer
     * by multiplying by precision.
     *
     * @internal unexported in Go; public for parity with applyPrecisionLogic callers.
     */
    public static function convertDecimalToPrecise(Transaction $transaction): BigInteger
    {
        // We should avoid float multiplication due to precision loss
        // Convert the components to strings first and use the decimal package

        // Using decimal package approach
        // Go: strconv.FormatFloat(amount, 'f', -1, 64) / strconv.FormatFloat(precision, 'f', 0, 64)
        $amountStr = self::goFloatString($transaction->amount);
        $precisionStr = sprintf('%.0F', $transaction->precision);

        // Go ignores decimal.NewFromString errors (NaN/Inf → zero decimal).
        $amountDec = self::decimalFromStringOrZero($amountStr);
        $precisionDec = self::decimalFromStringOrZero($precisionStr);

        // Round to the nearest minor unit: an amount finer than the precision
        // (e.g. 1.005 at precision 100) would otherwise produce a non-integer
        // string that big.Int.SetString silently rejects, yielding zero.
        $preciseAmount = $amountDec->multipliedBy($precisionDec)->toScale(0, RoundingMode::HALF_UP);

        // Convert to big.Int
        return $preciseAmount->toBigInteger();
    }

    /**
     * UpdateBalances updates the balances for both the source and destination based on the transaction details.
     * It ensures precision is applied and checks for overdraft before updating.
     *
     * @throws \RuntimeException on validation/overdraft failure (Go returns an error)
     */
    public static function updateBalances(Transaction $transaction, Balance $source, Balance $destination): void
    {
        // Apply precision to get precise amount
        $transaction->preciseAmount = self::applyPrecision($transaction);
        $transaction->validate();

        // Check if source has sufficient funds
        self::canProcessTransaction($transaction, $source);

        $source->initializeBalanceFields();
        $destination->initializeBalanceFields();

        // Update source balance with original precise amount
        $source->addDebit($transaction->preciseAmount, $transaction->inflight);
        $source->computeBalance($transaction->inflight);

        // Credit the destination the same precise amount that was debited from
        // the source. (A per-transaction FX rate was removed: it was unused in
        // practice and broke inflight commit, which credited the rated amount on
        // hold but the un-rated amount on commit.)
        $destination->addCredit($transaction->preciseAmount, $transaction->inflight);
        $destination->computeBalance($transaction->inflight);
    }

    // ---------------------------------------------------------------------
    // transaction.go (standalone funcs)
    // ---------------------------------------------------------------------

    /**
     * PrecisionBankersRound rounds num at the given precision (scale factor),
     * applying banker's rounding (round half to even) on exact .5 fractions.
     *
     * Note: Go's math.Round (half away from zero) maps to PHP's round(), which
     * is correctly rounding on PHP >= 8.4.
     */
    public static function precisionBankersRound(float $num, float $precision): float
    {
        // // For standard 2-decimal precision, use the original bankersRound
        // if precision == 100 {
        // 	return bankersRound(num)
        // }

        // Directly use the precision as the scale factor
        // This is more accurate than calculating decimal places with log10
        $shifted = $num * $precision;
        $whole = floor($shifted);
        $fraction = $shifted - $whole;

        // Apply banker's rounding logic (round half to even)
        if (abs($fraction - 0.5) < 1e-10) {
            if (fmod($whole, 2.0) == 0.0) {
                return $whole / $precision; // Round down for even
            }
            return ($whole + 1) / $precision; // Round up for odd
        }

        return round($shifted) / $precision;
    }

    /**
     * CalculateDistributionsPrecise calculates distributions using big integers for precision.
     *
     * The Go version takes a context and emits OTEL span events; tracing is a
     * no-op in this port (PORTING.md) so the context parameter is dropped.
     *
     * @param Distribution[] $distributions
     *
     * @return array<string, BigInteger> map of identifier → precise amount
     *
     * @throws \RuntimeException with the same messages as the Go errors
     */
    public static function calculateDistributionsPrecise(BigInteger $totalPreciseAmount, array $distributions, int $precision): array
    {
        if ($totalPreciseAmount->compareTo(BigInteger::zero()) === 0) {
            return self::handleZeroAmount($distributions);
        }

        $totalAmountDec = BigDecimal::of((string) $totalPreciseAmount);

        $result = self::handleSmallAmount($totalAmountDec, $totalPreciseAmount, $distributions);
        if ($result !== null) {
            return $result;
        }

        $state = new DistributionState();
        $state->totalAmountDec = $totalAmountDec;
        $state->amountLeftDec = $totalAmountDec;
        $state->precisionDec = BigDecimal::of($precision);

        self::processFixedDistributions($distributions, $state);
        self::processPercentageDistributions($distributions, $state);
        self::validateDistributions($state);

        self::adjustPercentageAmounts($state);
        self::combineDistributions($state);

        self::processLeftDistribution($distributions, $state);

        self::balanceDistributions($state);

        return $state->result;
    }

    /**
     * processFixedDistributions handles the first pass: fixed amount distributions.
     *
     * @param Distribution[] $distributions
     */
    private static function processFixedDistributions(array $distributions, DistributionState $state): void
    {
        foreach ($distributions as $dist) {
            if ($dist->isLeftDistribution() || $dist->isPercentageDistribution()) {
                continue;
            }

            $fixedAmountDec = self::parseFixedAmount($dist, $state->precisionDec);
            if ($fixedAmountDec->isZero()) {
                continue;
            }

            if ($fixedAmountDec->compareTo($state->amountLeftDec) > 0) {
                throw new \RuntimeException('fixed amount exceeds remaining transaction amount');
            }

            $state->fixedAmounts[$dist->identifier] = $fixedAmountDec;
            $state->fixedTotalDec = $state->fixedTotalDec->plus($fixedAmountDec);
            $state->amountLeftDec = $state->amountLeftDec->minus($fixedAmountDec);
        }
    }

    /**
     * parseFixedAmount parses a fixed amount from a distribution.
     *
     * @throws \RuntimeException on malformed values (Go returns an error)
     */
    private static function parseFixedAmount(Distribution $dist, BigDecimal $precisionDec): BigDecimal
    {
        if ($dist->preciseDistribution !== '') {
            // Go: strconv.ParseInt(value, 10, 64) — digits with optional sign, within int64 range.
            if (preg_match('/^[+-]?[0-9]+$/', $dist->preciseDistribution) !== 1) {
                throw new \RuntimeException('invalid precise_distribution format: must be an integer value in minor units');
            }
            $preciseAmount = BigInteger::of($dist->preciseDistribution);
            if ($preciseAmount->compareTo(BigInteger::of(PHP_INT_MAX)) > 0
                || $preciseAmount->compareTo(BigInteger::of(PHP_INT_MIN)) < 0
            ) {
                throw new \RuntimeException('invalid precise_distribution format: must be an integer value in minor units');
            }
            return $preciseAmount->toBigDecimal();
        }

        if ($dist->distribution !== '') {
            // Go: strconv.ParseFloat(value, 64) — decimal/scientific forms only
            // (Go's hex-float and Inf/NaN spellings are not accepted here).
            if (preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/', $dist->distribution) !== 1) {
                throw new \RuntimeException('invalid fixed amount format');
            }
            $fixedAmount = (float) $dist->distribution;
            return BigDecimal::of(self::goFloatString($fixedAmount))->multipliedBy($precisionDec);
        }

        return BigDecimal::zero();
    }

    /**
     * processPercentageDistributions handles the second pass: percentage distributions.
     *
     * @param Distribution[] $distributions
     */
    private static function processPercentageDistributions(array $distributions, DistributionState $state): void
    {
        $hundredDec = BigDecimal::of(100);
        $minValueDec = BigDecimal::of(1);

        foreach ($distributions as $dist) {
            if ($dist->isLeftDistribution() || !$dist->isPercentageDistribution()) {
                continue;
            }

            $percentageStr = substr($dist->distribution, 0, -1);
            if (preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/', $percentageStr) !== 1) {
                throw new \RuntimeException('invalid percentage format');
            }
            $percentage = (float) $percentageStr;

            $percentageDec = BigDecimal::of(self::goFloatString($percentage));
            $state->totalPercentage = $state->totalPercentage->plus($percentageDec);

            // shopspring Div == 16-decimal-place half-away-from-zero rounding.
            $rawAmountDec = $state->totalAmountDec->multipliedBy($percentageDec)
                ->dividedBy($hundredDec, 16, RoundingMode::HALF_UP);

            if ($rawAmountDec->getSign() > 0 && $rawAmountDec->compareTo($minValueDec) < 0) {
                $state->percentAmounts[$dist->identifier] = BigDecimal::zero();
            } else {
                $state->percentAmounts[$dist->identifier] = $rawAmountDec;
            }
        }
    }

    /**
     * validateDistributions checks that total distributions don't exceed limits.
     */
    private static function validateDistributions(DistributionState $state): void
    {
        $hundredDec = BigDecimal::of(100);
        if ($state->totalPercentage->compareTo($hundredDec) > 0 || $state->fixedTotalDec->compareTo($state->totalAmountDec) > 0) {
            throw new \RuntimeException('total distributions exceed 100% or total amount');
        }
    }

    /**
     * adjustPercentageAmounts adjusts percentage amounts to maintain the correct total.
     */
    private static function adjustPercentageAmounts(DistributionState $state): void
    {
        if (\count($state->percentAmounts) === 0) {
            return;
        }

        $hundredDec = BigDecimal::of(100);
        $targetTotal = $state->totalAmountDec->multipliedBy($state->totalPercentage)
            ->dividedBy($hundredDec, 16, RoundingMode::HALF_UP);

        $currentTotal = BigDecimal::zero();
        foreach ($state->percentAmounts as $amount) {
            $currentTotal = $currentTotal->plus($amount);
        }

        $diff = $targetTotal->minus($currentTotal);
        if ($diff->abs()->compareTo(BigDecimal::zero()) <= 0) {
            return;
        }

        [$largestKey, $largestAmount] = self::findLargestDecimal($state->percentAmounts);
        if ($largestKey !== '' && $largestAmount->getSign() > 0) {
            $state->percentAmounts[$largestKey] = $state->percentAmounts[$largestKey]->plus($diff);
        }
    }

    /**
     * findLargestDecimal finds the key with the largest decimal value.
     *
     * @param array<string, BigDecimal> $amounts
     *
     * @return array{0: string, 1: BigDecimal}
     */
    private static function findLargestDecimal(array $amounts): array
    {
        $largestKey = '';
        $largestAmount = BigDecimal::zero();
        foreach ($amounts as $id => $amount) {
            if ($amount->compareTo($largestAmount) > 0) {
                $largestAmount = $amount;
                $largestKey = (string) $id;
            }
        }
        return [$largestKey, $largestAmount];
    }

    /**
     * combineDistributions merges fixed and percentage amounts into the result.
     * (Go `Decimal.BigInt()` truncates toward zero.)
     */
    private static function combineDistributions(DistributionState $state): void
    {
        foreach ($state->fixedAmounts as $id => $amountDec) {
            $state->result[$id] = $amountDec->toScale(0, RoundingMode::DOWN)->toBigInteger();
        }

        foreach ($state->percentAmounts as $id => $amountDec) {
            $state->result[$id] = $amountDec->toScale(0, RoundingMode::DOWN)->toBigInteger();
            $state->amountLeftDec = $state->amountLeftDec->minus($amountDec);
        }
    }

    /**
     * processLeftDistribution handles the "left" distribution type.
     *
     * @param Distribution[] $distributions
     */
    private static function processLeftDistribution(array $distributions, DistributionState $state): void
    {
        foreach ($distributions as $dist) {
            if (!$dist->isLeftDistribution()) {
                continue;
            }

            if (\array_key_exists($dist->identifier, $state->result)) {
                throw new \RuntimeException("multiple identifiers with 'left' distribution");
            }

            $state->result[$dist->identifier] = $state->amountLeftDec->toScale(0, RoundingMode::DOWN)->toBigInteger();
            break;
        }
    }

    /**
     * balanceDistributions ensures the sum of all distributions equals the total amount.
     */
    private static function balanceDistributions(DistributionState $state): void
    {
        $sumDec = BigDecimal::zero();
        foreach ($state->result as $amount) {
            $sumDec = $sumDec->plus(BigDecimal::of((string) $amount));
        }

        if ($sumDec->compareTo($state->totalAmountDec) === 0) {
            return;
        }

        $largestKey = '';
        $largestAmount = BigInteger::zero();
        foreach ($state->result as $id => $amount) {
            if ($amount->compareTo($largestAmount) > 0) {
                $largestAmount = $amount;
                $largestKey = (string) $id;
            }
        }

        if ($largestKey !== '') {
            $diff = $state->totalAmountDec->minus($sumDec);
            $state->result[$largestKey] = $state->result[$largestKey]
                ->plus($diff->toScale(0, RoundingMode::DOWN)->toBigInteger());
        }
    }

    /**
     * handleZeroAmount returns zero amounts for all distributions when total is zero.
     *
     * @param Distribution[] $distributions
     *
     * @return array<string, BigInteger>
     */
    private static function handleZeroAmount(array $distributions): array
    {
        $result = [];
        foreach ($distributions as $dist) {
            $result[$dist->identifier] = BigInteger::zero();
        }
        return $result;
    }

    /**
     * handleSmallAmount assigns entire amount to first distribution for very small amounts.
     *
     * @param Distribution[] $distributions
     *
     * @return array<string, BigInteger>|null
     */
    private static function handleSmallAmount(BigDecimal $totalAmountDec, BigInteger $totalPreciseAmount, array $distributions): ?array
    {
        $minAmountDec = BigDecimal::of(1);
        if ($totalAmountDec->compareTo($minAmountDec) > 0 || $totalAmountDec->getSign() <= 0 || \count($distributions) === 0) {
            return null;
        }

        $result = [];
        $result[$distributions[0]->identifier] = $totalPreciseAmount;
        for ($i = 1; $i < \count($distributions); $i++) {
            $result[$distributions[$i]->identifier] = BigInteger::zero();
        }
        return $result;
    }

    // ---------------------------------------------------------------------
    // Porting helpers (formatting / parsing shared by the model classes)
    // ---------------------------------------------------------------------

    /**
     * goFloatString formats a float exactly like Go's
     * `strconv.FormatFloat(f, 'f', -1, 64)`: the shortest decimal string that
     * round-trips back to the same float64, always in plain (non-scientific)
     * notation.
     *
     * Implementation: PHP's json_encode uses serialize_precision = -1 by
     * default, which yields the shortest round-trip representation (same
     * Ryū/Grisu-style algorithm family as Go). When PHP chooses scientific
     * notation (e.g. "1.0e-5" or "1.0e+20"), the value is expanded to plain
     * notation via BigDecimal, preserving the exact digits ("0.00001",
     * "100000000000000000000") just as Go's 'f' format does. Non-finite values
     * use Go's spellings ("NaN", "+Inf", "-Inf").
     */
    public static function goFloatString(float $f): string
    {
        if (is_nan($f)) {
            return 'NaN';
        }
        if (is_infinite($f)) {
            return $f > 0 ? '+Inf' : '-Inf';
        }

        $s = json_encode($f);
        if ($s === false) {
            $s = sprintf('%.17G', $f); // defensive fallback, normally unreachable
        }

        if (strpbrk($s, 'eE') !== false) {
            $negative = $s[0] === '-';
            $plain = (string) BigDecimal::of($s)->stripTrailingZeros();
            // BigDecimal normalizes "-0" to "0"; Go keeps the sign of zero.
            if ($negative && $plain !== '' && $plain[0] !== '-') {
                $plain = '-' . $plain;
            }
            return $plain;
        }

        // json_encode already emits shortest plain notation ("0.1", "100", "-0").
        return $s;
    }

    /**
     * goTimeString formats a timestamp the way Go's encoding/json marshals a
     * time.Time (RFC 3339 with sub-second digits only when non-zero, trailing
     * zeros trimmed; "Z" for UTC). A null value stands for Go's zero time and
     * formats as "0001-01-01T00:00:00Z".
     *
     * PHP DateTimeImmutable carries microseconds (Go: nanoseconds); fractions
     * are therefore emitted at up to microsecond resolution.
     */
    public static function goTimeString(?\DateTimeImmutable $t): string
    {
        if ($t === null) {
            return '0001-01-01T00:00:00Z';
        }

        $s = $t->format('Y-m-d\TH:i:s');
        $us = $t->format('u');
        if ($us !== '000000') {
            $s .= '.' . rtrim($us, '0');
        }

        $offset = $t->format('P');
        if ($offset === '+00:00') {
            $offset = 'Z';
        }
        return $s . $offset;
    }

    /**
     * parseTime converts a JSON value (RFC 3339 string, or null) to a
     * DateTimeImmutable. Empty/null inputs and the Go zero time remain null.
     */
    public static function parseTime(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        if (!\is_string($value)) {
            throw new \InvalidArgumentException('invalid time value');
        }
        if ($value === '0001-01-01T00:00:00Z') {
            return null; // Go zero time
        }
        return new \DateTimeImmutable($value);
    }

    /**
     * bigIntegerToJson mirrors big.Int JSON marshalling (a JSON number):
     * values within PHP's int range serialize as numbers, larger ones as
     * strings (see PORTING.md "Types").
     */
    public static function bigIntegerToJson(?BigInteger $value): int|string|null
    {
        if ($value === null) {
            return null;
        }
        if ($value->compareTo(BigInteger::of(PHP_INT_MAX)) <= 0 && $value->compareTo(BigInteger::of(PHP_INT_MIN)) >= 0) {
            return $value->toInt();
        }
        return (string) $value;
    }

    /**
     * bigIntegerFromJson converts a JSON value (int, numeric string, or float)
     * into a BigInteger; null/empty stays null. A fractional float throws, as
     * Go's big.Int unmarshalling would error.
     */
    public static function bigIntegerFromJson(mixed $value): ?BigInteger
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof BigInteger) {
            return $value;
        }
        if (\is_int($value)) {
            return BigInteger::of($value);
        }
        if (\is_float($value)) {
            return BigDecimal::of(self::goFloatString($value))->toBigInteger();
        }
        if (\is_string($value)) {
            return BigInteger::of($value);
        }
        throw new \InvalidArgumentException('invalid big integer value');
    }

    /**
     * mapToJson mirrors Go's JSON marshalling of a `map[string]interface{}`
     * field WITHOUT omitempty: a nil map serializes as null, an empty map as
     * {} (stdClass forces the object form), a populated map as an object.
     */
    public static function mapToJson(?array $map): mixed
    {
        if ($map === null) {
            return null;
        }
        if (\count($map) === 0) {
            return new \stdClass();
        }
        return $map;
    }

    /**
     * decimalFromStringOrZero mirrors Go call sites that ignore
     * decimal.NewFromString errors: a malformed string yields the zero decimal.
     */
    public static function decimalFromStringOrZero(string $value): BigDecimal
    {
        try {
            return BigDecimal::of($value);
        } catch (\Throwable) {
            return BigDecimal::zero();
        }
    }
}
