<?php

declare(strict_types=1);

namespace Blnk\Internal\HotPairs;

/**
 * Port of Go `hotpairs.Config`. `HotPairTTL` (a `time.Duration`) is an
 * integer number of seconds in the PHP port.
 */
final class Config
{
    public bool $enabled = false;

    public string $hotQueueName = '';

    /** Seconds. */
    public int $hotPairTTL = 0;

    public int $lockContentionThreshold = 0;

    public function __construct(
        bool $enabled = false,
        string $hotQueueName = '',
        int $hotPairTTL = 0,
        int $lockContentionThreshold = 0
    ) {
        $this->enabled = $enabled;
        $this->hotQueueName = $hotQueueName;
        $this->hotPairTTL = $hotPairTTL;
        $this->lockContentionThreshold = $lockContentionThreshold;
    }
}
