<?php

declare(strict_types=1);

namespace Blnk\Internal\HotPairs;

/**
 * Port of Go `hotpairs.Manager` (`hotpairs.go`): Redis-backed hot
 * account-pair tracking. A "pair" is (source, destination, CURRENCY); pairs
 * that repeatedly contend on locks are promoted to a dedicated hot queue.
 *
 * `context.Context` parameters are dropped; Redis errors are thrown
 * (\RedisException / \RuntimeException) where the Go methods return them.
 */
class Manager
{
    // Lane names (Go package-level consts in hotpairs.go).
    public const LaneNormal = 'normal';
    public const LaneHot = 'hot';

    protected ?\Redis $client;

    protected bool $enabled;

    protected string $hotQueue;

    /** Seconds (Go: pairTTL time.Duration). */
    protected int $pairTTL;

    protected int $threshold;

    /**
     * NewManager mirrors Go `hotpairs.NewManager`: applies the same fallback
     * defaults (TTL 5m, threshold 3, hot queue "hot_transactions") and only
     * enables the manager when a Redis client is present.
     */
    public function __construct(?\Redis $client, Config $cfg)
    {
        $ttl = $cfg->hotPairTTL;
        if ($ttl <= 0) {
            $ttl = 5 * 60; // 5 * time.Minute
        }

        $threshold = $cfg->lockContentionThreshold;
        if ($threshold <= 0) {
            $threshold = 3;
        }

        $hotQueue = $cfg->hotQueueName;
        if ($hotQueue === '') {
            $hotQueue = 'hot_transactions';
        }

        $this->client = $client;
        $this->enabled = $cfg->enabled && $client !== null;
        $this->hotQueue = $hotQueue;
        $this->pairTTL = $ttl;
        $this->threshold = $threshold;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function hotQueueName(): string
    {
        if ($this->hotQueue === '') {
            return 'hot_transactions';
        }
        return $this->hotQueue;
    }

    public function pairKey(string $source, string $destination, string $currency): string
    {
        return sprintf('%s|%s|%s', $source, $destination, strtoupper($currency));
    }

    /**
     * GetState returns the pair's routing state; unknown/missing states map to
     * StateNormal.
     *
     * @return string One of the {@see State} constants.
     * @throws \RedisException on Redis failure (Go returns the error).
     */
    public function getState(string $pairKey): string
    {
        if (!$this->enabled()) {
            return State::StateNormal;
        }

        $state = $this->client->get($this->stateKey($pairKey));
        if ($state === false) {
            // redis.Nil — key does not exist.
            return State::StateNormal;
        }

        switch ($state) {
            case State::StatePromoting:
            case State::StateHot:
            case State::StateCoolingDown:
                return (string) $state;
            default:
                return State::StateNormal;
        }
    }

    /**
     * RecordContention increments the pair's contention counter and, once the
     * threshold is crossed, marks the pair for promotion.
     *
     * Go returns `(count int64, promoted bool, err error)`; the PHP port
     * returns `[count, promoted]` and throws on Redis errors.
     *
     * @return array{0: int, 1: bool} [contention count, promoted]
     * @throws \RedisException|\RuntimeException on Redis failure.
     */
    public function recordContention(string $pairKey): array
    {
        if (!$this->enabled()) {
            return [0, false];
        }

        $pipe = $this->client->multi();
        $pipe->incr($this->contentionKey($pairKey));
        $pipe->expire($this->contentionKey($pairKey), $this->pairTTL);
        $pipe->set($this->activityKey($pairKey), '1', $this->pairTTL);
        $results = $pipe->exec();
        if ($results === false || !is_array($results)) {
            throw new \RuntimeException(sprintf('failed to record hot-pair contention for %s', $pairKey));
        }

        $current = (int) ($results[0] ?? 0);
        if ($current < $this->threshold) {
            return [$current, false];
        }

        $state = $this->getState($pairKey);
        if ($state === State::StateHot || $state === State::StateCoolingDown || $state === State::StatePromoting) {
            try {
                $this->client->expire($this->stateKey($pairKey), $this->stateTTL());
            } catch (\RedisException) {
                // Go ignores this error (`_ = m.client.Expire(...).Err()`).
            }
            return [$current, $state === State::StatePromoting];
        }

        $this->setState($pairKey, State::StatePromoting);

        return [$current, true];
    }

    /**
     * HasRecentContention reports whether the pair saw contention within the TTL.
     *
     * @throws \RedisException on Redis failure.
     */
    public function hasRecentContention(string $pairKey): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $n = $this->client->exists($this->activityKey($pairKey));
        return ((int) $n) > 0;
    }

    /**
     * ActivateHot transitions the pair to the hot state and refreshes activity.
     *
     * @throws \RedisException|\RuntimeException on Redis failure.
     */
    public function activateHot(string $pairKey): void
    {
        if (!$this->enabled()) {
            return;
        }

        $pipe = $this->client->multi();
        $pipe->set($this->stateKey($pairKey), State::StateHot, $this->stateTTL());
        $pipe->set($this->activityKey($pairKey), '1', $this->pairTTL);
        $results = $pipe->exec();
        if ($results === false || !is_array($results)) {
            throw new \RuntimeException(sprintf('failed to activate hot pair %s', $pairKey));
        }
    }

    /**
     * StartCoolingDown transitions the pair to the cooling-down state.
     *
     * @throws \RedisException on Redis failure.
     */
    public function startCoolingDown(string $pairKey): void
    {
        if (!$this->enabled()) {
            return;
        }
        $this->setState($pairKey, State::StateCoolingDown);
    }

    /**
     * SetNormal clears all hot-pair keys, returning the pair to normal routing.
     *
     * @throws \RedisException|\RuntimeException on Redis failure.
     */
    public function setNormal(string $pairKey): void
    {
        if (!$this->enabled()) {
            return;
        }

        $pipe = $this->client->multi();
        $pipe->del($this->stateKey($pairKey));
        $pipe->del($this->activityKey($pairKey));
        $pipe->del($this->contentionKey($pairKey));
        $results = $pipe->exec();
        if ($results === false || !is_array($results)) {
            throw new \RuntimeException(sprintf('failed to reset hot pair %s', $pairKey));
        }
    }

    /**
     * @throws \RedisException|\RuntimeException on Redis failure.
     */
    protected function setState(string $pairKey, string $state): void
    {
        $ok = $this->client->set($this->stateKey($pairKey), $state, $this->stateTTL());
        if ($ok === false) {
            throw new \RuntimeException(sprintf('failed to set hot-pair state for %s', $pairKey));
        }
    }

    protected function stateKey(string $pairKey): string
    {
        return sprintf('blnk:hot_pairs:state:%s', $pairKey);
    }

    protected function activityKey(string $pairKey): string
    {
        return sprintf('blnk:hot_pairs:activity:%s', $pairKey);
    }

    protected function contentionKey(string $pairKey): string
    {
        return sprintf('blnk:hot_pairs:contention:%s', $pairKey);
    }

    /** Seconds. */
    protected function stateTTL(): int
    {
        return $this->pairTTL * 4;
    }
}
