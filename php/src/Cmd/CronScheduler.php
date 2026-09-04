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

namespace Blnk\Cmd;

use Blnk\Internal\Log;
use Cron\CronExpression;

/**
 * CronScheduler runs registered jobs on cron schedules from inside the
 * `blnk workers` loop (dragonmantank/cron-expression).
 *
 * It is the PHP-port stand-in for asynq's periodic-task scheduler
 * (`asynq.Scheduler`). cmd/workers.go of the ported Go revision registers NO
 * scheduled/cron jobs — its background work is the ticker-driven
 * QueuedTransactionRecoveryProcessor (and, in the server, the lineage outbox
 * and hash-chain processors), which are driven directly by the command loops —
 * so the scheduler is empty by default; {@see WorkersCommand::registerCronJob()}
 * is the extension point for deployments that need cron-style jobs in the
 * worker process.
 *
 * Jobs are `callable(): void`; a job that throws is logged and keeps its
 * schedule. Schedules are evaluated in the process time zone, one minute
 * granularity (cron semantics); a job never runs more than once per due slot,
 * and a slot missed while the loop was busy is skipped, not replayed.
 */
final class CronScheduler
{
    /** @var array<int, array{name: string, expression: CronExpression, job: callable(): void, next: \DateTimeImmutable}> */
    private array $jobs = [];

    /**
     * add registers a job; the expression is validated immediately.
     *
     * @param callable(): void $job
     * @throws \InvalidArgumentException on an invalid cron expression
     */
    public function add(string $expression, callable $job, string $name = ''): void
    {
        if (!CronExpression::isValidExpression($expression)) {
            throw new \InvalidArgumentException(sprintf('invalid cron expression %s', json_encode($expression)));
        }
        $cron = new CronExpression($expression);
        $this->jobs[] = [
            'name' => $name !== '' ? $name : sprintf('job-%d', \count($this->jobs) + 1),
            'expression' => $cron,
            'job' => $job,
            'next' => $cron->getNextRunDate(new \DateTimeImmutable('now')),
        ];
    }

    /**
     * tick runs every job whose next due time has passed and returns how many ran.
     */
    public function tick(): int
    {
        if ($this->jobs === []) {
            return 0;
        }
        $now = new \DateTimeImmutable('now');
        $ran = 0;
        foreach ($this->jobs as $i => $entry) {
            if ($entry['next'] > $now) {
                continue;
            }
            // Schedule the following slot before running so a slow job cannot re-fire for the same slot.
            $this->jobs[$i]['next'] = $entry['expression']->getNextRunDate($now);
            try {
                ($entry['job'])();
            } catch (\Throwable $err) {
                Log::get()->error(sprintf('cron job %s failed: %s', $entry['name'], $err->getMessage()));
            }
            $ran++;
        }
        return $ran;
    }

    /** count returns the number of registered jobs. */
    public function count(): int
    {
        return \count($this->jobs);
    }

    /**
     * jobs lists the registered jobs with their next run time (for logging/monitoring).
     *
     * @return array<int, array{name: string, expression: string, next: string}>
     */
    public function jobs(): array
    {
        $out = [];
        foreach ($this->jobs as $entry) {
            $out[] = [
                'name' => $entry['name'],
                'expression' => $entry['expression']->getExpression(),
                'next' => $entry['next']->format(\DateTimeInterface::RFC3339),
            ];
        }
        return $out;
    }
}
