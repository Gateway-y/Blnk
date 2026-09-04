<?php

declare(strict_types=1);

namespace Blnk\Internal\HotPairs;

/**
 * Port of Go `hotpairs.PairLaneCounter` (router.go): counts queued
 * transactions for a (source, destination, currency) pair on a given lane.
 */
interface PairLaneCounter
{
    /**
     * @throws \Throwable on lookup failure (Go returns `(int, error)`).
     */
    public function countQueuedTransactionsForPairLane(string $source, string $destination, string $currency, string $lane): int;
}
