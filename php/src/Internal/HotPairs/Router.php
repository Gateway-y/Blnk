<?php

declare(strict_types=1);

namespace Blnk\Internal\HotPairs;

use Blnk\Internal\Lock\LockHeldException;
use Blnk\Internal\Lock\LockWaitTimeoutException;
use Blnk\Internal\Log;
use Blnk\Model\Transaction;

/**
 * Port of Go `hotpairs` `router.go`: routes transactions between the normal
 * and hot queue lanes based on the pair's contention state.
 *
 * `context.Context` parameters are dropped. The Go `errors.Is(err,
 * redlock.ErrLockHeld/ErrLockWaitTimeout)` checks become instanceof checks on
 * {@see LockHeldException}/{@see LockWaitTimeoutException} through the
 * exception `previous` chain; the `context.Canceled`/`DeadlineExceeded`
 * exclusions have no PHP equivalent and are omitted.
 */
final class Router
{
    public const QueueLaneMetaKey = 'queue_lane';
    public const QueuePairMetaKey = 'queue_pair_key';

    private function __construct()
    {
    }

    /**
     * AssignQueueLane resolves the lane for a transaction and stamps
     * `queue_lane` (and `queue_pair_key`) into its metadata. Resolution
     * failures fall back to the normal lane with a warning, mirroring Go.
     */
    public static function assignQueueLane(?Manager $manager, ?PairLaneCounter $counter, ?Transaction $txn): void
    {
        if ($txn === null) {
            return;
        }
        if ($txn->metaData === null) {
            $txn->metaData = [];
        }

        $lane = Manager::LaneNormal;
        $pairKey = self::pairKeyForTransaction($manager, $txn);
        if ($pairKey !== '') {
            try {
                $lane = self::resolveQueueLane($manager, $counter, $txn->source, $txn->destination, $txn->currency);
            } catch (\Throwable $e) {
                Log::get()->warning('failed to resolve hot-pair lane, using normal lane', [
                    'error' => $e->getMessage(),
                    'transaction_id' => $txn->transactionID,
                ]);
            }
            $txn->metaData[self::QueuePairMetaKey] = $pairKey;
        }

        $txn->metaData[self::QueueLaneMetaKey] = $lane;
    }

    /**
     * ResolveQueueLane returns the lane ("normal" or "hot") for the pair,
     * advancing the pair's state machine (promoting, hot, cooling_down) as it
     * goes.
     *
     * @return string Manager::LaneNormal or Manager::LaneHot.
     * @throws \Throwable on Redis/counter failure (Go returns the error with LaneNormal).
     */
    public static function resolveQueueLane(?Manager $manager, ?PairLaneCounter $counter, string $source, string $destination, string $currency): string
    {
        if ($manager === null || !$manager->enabled()) {
            return Manager::LaneNormal;
        }

        $pairKey = $manager->pairKey($source, $destination, $currency);
        $state = $manager->getState($pairKey);

        switch ($state) {
            case State::StatePromoting:
                $pendingNormal = self::pendingQueuedTransactionsForPairLane($counter, $source, $destination, $currency, Manager::LaneNormal);
                if ($pendingNormal === 0) {
                    $manager->activateHot($pairKey);
                    Log::get()->info('Promoted hot pair to hot queue', [
                        'pair_key' => $pairKey,
                        'source' => $source,
                        'dest' => $destination,
                        'currency' => strtoupper($currency),
                    ]);
                    return Manager::LaneHot;
                }
                return Manager::LaneNormal;
            case State::StateHot:
                $active = $manager->hasRecentContention($pairKey);
                if ($active) {
                    return Manager::LaneHot;
                }

                $pendingHot = self::pendingQueuedTransactionsForPairLane($counter, $source, $destination, $currency, Manager::LaneHot);
                if ($pendingHot > 0) {
                    $manager->startCoolingDown($pairKey);
                    return Manager::LaneHot;
                }

                $manager->setNormal($pairKey);
                Log::get()->info('Demoted hot pair back to normal queue', ['pair_key' => $pairKey]);
                return Manager::LaneNormal;
            case State::StateCoolingDown:
                $pendingHot = self::pendingQueuedTransactionsForPairLane($counter, $source, $destination, $currency, Manager::LaneHot);
                if ($pendingHot > 0) {
                    return Manager::LaneHot;
                }
                $manager->setNormal($pairKey);
                Log::get()->info('Completed hot-pair cooldown; returning to normal queue', ['pair_key' => $pairKey]);
                return Manager::LaneNormal;
            default:
                return Manager::LaneNormal;
        }
    }

    /**
     * PairKeyForTransaction returns the pair key for a transaction, or "" when
     * any of source/destination/currency is missing.
     */
    public static function pairKeyForTransaction(?Manager $manager, ?Transaction $txn): string
    {
        if ($txn === null || $manager === null || $txn->source === '' || $txn->destination === '' || $txn->currency === '') {
            return '';
        }
        return $manager->pairKey($txn->source, $txn->destination, $txn->currency);
    }

    /**
     * QueueLaneFromMetadata extracts the lane stamped into transaction
     * metadata; anything but "hot" maps to "normal".
     *
     * @param array<string, mixed>|null $meta
     */
    public static function queueLaneFromMetadata(?array $meta): string
    {
        if ($meta === null) {
            return Manager::LaneNormal;
        }
        $lane = $meta[self::QueueLaneMetaKey] ?? null;
        if ($lane === Manager::LaneHot) {
            return Manager::LaneHot;
        }
        return Manager::LaneNormal;
    }

    /**
     * RecordContention records a lock-contention event for the pair when the
     * given error is a lock-contention error. Recording failures are warned,
     * never thrown (mirroring Go).
     */
    public static function recordContention(?Manager $manager, string $source, string $destination, string $currency, ?\Throwable $err): void
    {
        if (!self::isLockContentionError($err) || $manager === null || !$manager->enabled()) {
            return;
        }
        if ($source === '' || $destination === '' || $currency === '') {
            return;
        }

        $pairKey = $manager->pairKey($source, $destination, $currency);
        try {
            [$count, $promoted] = $manager->recordContention($pairKey);
        } catch (\Throwable $recordErr) {
            Log::get()->warning('failed to record hot-pair contention', [
                'error' => $recordErr->getMessage(),
                'pair_key' => $pairKey,
            ]);
            return;
        }

        $fields = [
            'pair_key' => $pairKey,
            'source' => $source,
            'destination' => $destination,
            'currency' => strtoupper($currency),
            'count' => $count,
        ];
        if ($promoted) {
            Log::get()->info('Hot-pair contention threshold reached; pair marked for promotion', $fields);
            return;
        }
    }

    /**
     * IsLockContentionError reports whether the error represents lock
     * contention: a {@see LockHeldException}/{@see LockWaitTimeoutException}
     * anywhere in the `previous` chain, or a message matching the known
     * contention phrases.
     */
    public static function isLockContentionError(?\Throwable $err): bool
    {
        if ($err === null) {
            return false;
        }
        for ($e = $err; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof LockHeldException || $e instanceof LockWaitTimeoutException) {
                return true;
            }
        }
        $msg = strtolower($err->getMessage());
        return str_contains($msg, 'already held')
            || str_contains($msg, 'failed to acquire lock')
            || str_contains($msg, 'failed to acquire batch lock');
    }

    /**
     * @throws \Throwable when the counter lookup fails.
     */
    private static function pendingQueuedTransactionsForPairLane(?PairLaneCounter $counter, string $source, string $destination, string $currency, string $lane): int
    {
        if ($counter === null) {
            return 0;
        }
        return $counter->countQueuedTransactionsForPairLane($source, $destination, $currency, $lane);
    }
}
