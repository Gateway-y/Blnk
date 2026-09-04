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

namespace Blnk\Api\Middleware\Tollbooth\Rate;

use Blnk\Api\Middleware\Tollbooth\Clock;

/**
 * Port of `rate.Reservation` (github.com/didip/tollbooth/v7/internal/time/rate).
 *
 * A Reservation holds information about events that are permitted by a Limiter to happen after a delay.
 * A Reservation may be canceled, which may enable the Limiter to permit additional events.
 *
 * Only the parts behind Allow/AllowN are ported: OK and Delay/DelayFrom.
 * Cancel/CancelAt (used by Wait, which Tollbooth never calls) are not.
 */
final class Reservation
{
    public bool $ok;
    public ?Limiter $lim;
    public int $tokens;
    /** Instant (nanoseconds) at which the reserved action may happen; null while not ok. */
    public ?int $timeToAct;
    /** This is the Limit at reservation time, it can change later. */
    public float $limit;

    public function __construct(bool $ok, ?Limiter $lim = null, int $tokens = 0, ?int $timeToAct = null, float $limit = 0.0)
    {
        $this->ok = $ok;
        $this->lim = $lim;
        $this->tokens = $tokens;
        $this->timeToAct = $timeToAct;
        $this->limit = $limit;
    }

    /**
     * OK returns whether the limiter can provide the requested number of tokens
     * within the maximum wait time.  If OK is false, Delay returns InfDuration, and
     * Cancel does nothing.
     */
    public function ok(): bool
    {
        return $this->ok;
    }

    /**
     * Delay is shorthand for DelayFrom(time.Now()).
     */
    public function delay(): int
    {
        return $this->delayFrom(Clock::now());
    }

    /**
     * DelayFrom returns the duration for which the reservation holder must wait
     * before taking the reserved action.  Zero duration means act immediately.
     * InfDuration means the limiter cannot grant the tokens requested in this
     * Reservation within the maximum wait time.
     */
    public function delayFrom(int $now): int
    {
        if (!$this->ok || $this->timeToAct === null) {
            return Limiter::InfDuration;
        }
        $delay = Clock::sub($this->timeToAct, $now);
        if ($delay < 0) {
            return 0;
        }

        return $delay;
    }
}
