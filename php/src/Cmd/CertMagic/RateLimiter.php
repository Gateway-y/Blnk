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

namespace Blnk\Cmd\CertMagic;

use Blnk\Cmd\CertMagic\Acme\Client as AcmeClient;

/**
 * RateLimiter is certmagic's `RingBufferRateLimiter`: it uses a ring to
 * enforce rate limits consisting of a maximum number of events within a
 * single sliding window of a given duration. An empty value is not valid;
 * use {@see newRateLimiter()} to get one.
 *
 * PHP port: Go hands out tickets from a scheduling goroutine; the
 * single-threaded port computes the next free slot and sleeps until it.
 */
final class RateLimiter
{
    /** window size in seconds */
    private float $window;

    /** @var float[] maxEvents == count(ring); unix timestamps of the events */
    private array $ring;

    /** always points to the oldest timestamp */
    private int $cursor = 0;

    private function __construct(float $window, array $ring)
    {
        $this->window = $window;
        $this->ring = $ring;
    }

    /**
     * NewRateLimiter returns a rate limiter that allows up to maxEvents
     * in a sliding window of size window. If maxEvents and window are
     * both 0, or if maxEvents is non-zero and window is 0, rate limiting
     * is disabled. This function throws if maxEvents is less than 0 or
     * if maxEvents is 0 and window is non-zero, which is considered to be
     * an invalid configuration, as it would never allow events.
     *
     * @throws \InvalidArgumentException
     */
    public static function newRateLimiter(int $maxEvents, float $window): self
    {
        if ($maxEvents < 0) {
            throw new \InvalidArgumentException('maxEvents cannot be less than zero');
        }
        if ($maxEvents === 0 && $window != 0) {
            throw new \InvalidArgumentException('NewRateLimiter: invalid configuration: maxEvents = 0 and window != 0 would not allow any events');
        }
        return new self($window, array_fill(0, $maxEvents, 0.0));
    }

    /** Stop cleans up r's scheduling (a no-op in the port). */
    public function stop(): void
    {
    }

    /**
     * Allow returns true if the event is allowed to
     * happen right now. It does not wait. If the event
     * is allowed, a ticket is claimed.
     */
    public function allow(): bool
    {
        if ($this->ring === []) {
            if ($this->window == 0) {
                return true; // rate limiting is disabled; always allow immediately
            }
            throw new \LogicException('invalid configuration: maxEvents = 0 and window != 0 does not allow any events');
        }
        if (microtime(true) >= $this->ring[$this->cursor] + $this->window) {
            $this->permit();
            return true;
        }
        return false;
    }

    /**
     * Wait blocks until the event is allowed to occur.
     */
    public function wait(): void
    {
        if ($this->ring === []) {
            if ($this->window == 0) {
                return;
            }
            throw new \LogicException('invalid configuration: maxEvents = 0 and window != 0 does not allow any events');
        }
        // wait until next slot is available
        $then = $this->ring[$this->cursor] + $this->window;
        $waitDuration = $then - microtime(true);
        if ($waitDuration > 0) {
            AcmeClient::wait($waitDuration);
        }
        $this->permit();
    }

    /**
     * MaxEvents returns the maximum number of events that
     * are allowed within the sliding window.
     */
    public function maxEvents(): int
    {
        return \count($this->ring);
    }

    /**
     * SetMaxEvents changes the maximum number of events that are
     * allowed in the sliding window. If the new limit is lower,
     * the oldest events will be forgotten. If the new limit is
     * higher, the window will suddenly have capacity for new
     * reservations. It throws if maxEvents is 0 and window size
     * is not zero; if setting both the events limit and the
     * window size to 0, call setWindow() first.
     */
    public function setMaxEvents(int $maxEvents): void
    {
        if ($this->window != 0 && $maxEvents === 0) {
            throw new \InvalidArgumentException('SetMaxEvents: invalid configuration: maxEvents = 0 and window != 0 would not allow any events');
        }

        // only make the change if the new limit is different
        if ($maxEvents === \count($this->ring)) {
            return;
        }

        $newRing = array_fill(0, $maxEvents, 0.0);

        // the new ring may be smaller; fast-forward to the
        // oldest timestamp that will be kept in the new
        // ring so the oldest ones are forgotten and the
        // newest ones will be remembered
        $sizeDiff = \count($this->ring) - $maxEvents;
        for ($i = 0; $i < $sizeDiff; $i++) {
            $this->advance();
        }

        if ($this->ring !== []) {
            // copy timestamps into the new ring until we
            // have either copied all of them or have reached
            // the capacity of the new ring
            $startCursor = $this->cursor;
            for ($i = 0; $i < \count($newRing); $i++) {
                $newRing[$i] = $this->ring[$this->cursor];
                $this->advance();
                if ($this->cursor === $startCursor) {
                    // new ring is larger than old one;
                    // "we've come full circle"
                    break;
                }
            }
        }

        $this->ring = $newRing;
        $this->cursor = 0;
    }

    /** Window returns the size of the sliding window (seconds). */
    public function window(): float
    {
        return $this->window;
    }

    /**
     * SetWindow changes r's sliding window duration to window.
     * It throws if window is non-zero but the max event limit is 0.
     */
    public function setWindow(float $window): void
    {
        if ($window != 0 && $this->ring === []) {
            throw new \InvalidArgumentException('SetWindow: invalid configuration: maxEvents = 0 and window != 0 would not allow any events');
        }
        $this->window = $window;
    }

    /** permit allows one event through the throttle. */
    private function permit(): void
    {
        if ($this->ring !== []) {
            $this->ring[$this->cursor] = microtime(true);
            $this->advance();
        }
    }

    /** advance moves the cursor to the next position. */
    private function advance(): void
    {
        $this->cursor++;
        if ($this->cursor >= \count($this->ring)) {
            $this->cursor = 0;
        }
    }
}
