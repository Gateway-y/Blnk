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
use Blnk\Internal\Log;

/**
 * Async is the port of certmagic async.go: the job manager that runs
 * certificate obtain/renew jobs in the background, and `doWithRetry`, the
 * exponential back-off loop of the non-interactive operations.
 *
 * PHP port: there are no goroutines, so {@see submit()} runs the job right
 * away (synchronously) with the same duplicate-name suppression, and the
 * back-off waits of {@see doWithRetry()} go through the ACME client's waiter
 * so challenge listeners and the HTTPS server keep being serviced meanwhile.
 */
final class Async
{
    /** maxConcurrentJobs of the job manager (`jm = &jobManager{maxConcurrentJobs: 1000}`). */
    public const MaxConcurrentJobs = 1000;

    /**
     * retryIntervals are based on the idea of exponential
     * backoff, but weighed a little more heavily to the
     * front. We figure that intermittent errors would be
     * resolved after the first retry, but any errors after
     * that would probably require at least a few minutes
     * or hours to clear up: either for DNS to propagate, for
     * the administrator to fix their DNS or network config,
     * or some other external factor needs to change. We
     * chose intervals that we think will be most useful
     * without introducing unnecessary delay. The last
     * interval in this list will be used until the time
     * of maxRetryDuration has elapsed. (seconds)
     */
    public const RetryIntervals = [
        1 * 60,
        2 * 60,
        2 * 60,
        5 * 60, // elapsed: 10 min
        10 * 60,
        10 * 60,
        10 * 60,
        20 * 60, // elapsed: 1 hr
        20 * 60,
        20 * 60,
        20 * 60, // elapsed: 2 hr
        30 * 60,
        30 * 60, // elapsed: 3 hr
        30 * 60,
        30 * 60, // elapsed: 4 hr
        30 * 60,
        30 * 60, // elapsed: 5 hr
        1 * 3600, // elapsed: 6 hr
        1 * 3600,
        1 * 3600, // elapsed: 8 hr
        2 * 3600,
        2 * 3600, // elapsed: 12 hr
        3 * 3600,
        3 * 3600, // elapsed: 18 hr
        6 * 3600, // repeat for up to maxRetryDuration
    ];

    /**
     * maxRetryDuration is the maximum duration to try
     * doing retries using the above intervals (seconds).
     */
    public const MaxRetryDuration = 24 * 3600 * 30;

    /** @var array<string, true> names of the jobs currently queued or running (`jm.names`) */
    private static array $names = [];

    /** @var array<int, array{name: string, job: callable(): void}> */
    private static array $queue = [];

    private static int $activeWorkers = 0;

    /**
     * PHP-only: when true (the default), {@see doWithRetry()} gives up after
     * the first failed attempt instead of sleeping for the back-off intervals
     * — the Go operations run in goroutines, whereas a single-threaded server
     * cannot block its request loop for up to 30 days; the certificate
     * maintenance loop ({@see Cache::maintainAssets()}) retries on its next
     * pass anyway. Set to false to get Go's blocking retry schedule (e.g. in
     * a dedicated CLI process).
     */
    public static bool $retryInline = true;

    private function __construct()
    {
    }

    /**
     * Submit enqueues the given job with the given name. If name is non-empty
     * and a job with the same name is already enqueued or running, this is a
     * no-op. If name is empty, no duplicate prevention will occur. The job
     * manager will then run this job as soon as it is able (in the port:
     * immediately, on the calling thread).
     *
     * @param callable(): void $job throws on failure (Go: returns an error)
     */
    public static function submit(string $name, callable $job): void
    {
        if ($name !== '') {
            // prevent duplicate jobs
            if (isset(self::$names[$name])) {
                return;
            }
            self::$names[$name] = true;
        }
        self::$queue[] = ['name' => $name, 'job' => $job];
        if (self::$activeWorkers < self::MaxConcurrentJobs) {
            self::$activeWorkers++;
            self::worker();
        }
    }

    private static function worker(): void
    {
        try {
            while (true) {
                if (self::$queue === []) {
                    self::$activeWorkers--;
                    return;
                }
                $next = array_shift(self::$queue);
                try {
                    ($next['job'])();
                } catch (\Throwable $err) {
                    Log::get()->error('job failed', ['error' => $err->getMessage()]);
                }
                if ($next['name'] !== '') {
                    unset(self::$names[$next['name']]);
                }
            }
        } catch (\Throwable $err) {
            // recover(): log the panic and keep the worker count consistent
            Log::get()->error(sprintf('panic: certificate worker: %s', $err->getMessage()), ['stack' => $err->getTraceAsString()]);
            self::$activeWorkers--;
        }
    }

    /**
     * doWithRetry runs f until it succeeds, with the back-off intervals of
     * RetryIntervals for up to MaxRetryDuration. `$f` receives the
     * IssueContext holding the attempt counter (Go: AttemptsCtxKey).
     * Throws the last error when giving up early ({@see ErrNoRetry}) or
     * returns silently after the final attempt (Go: "final attempt; giving
     * up" returns nil).
     *
     * @param callable(IssueContext): void $f
     * @param callable(): bool|null $canceled plays the role of ctx.Done()
     * @throws \Throwable
     */
    public static function doWithRetry(callable $f, ?callable $canceled = null): void
    {
        $ctx = new IssueContext(0);

        // the initial intervalIndex is -1, signaling
        // that we should not wait for the first attempt
        $start = microtime(true);
        $intervalIndex = -1;
        $err = null;

        while (microtime(true) - $start < self::MaxRetryDuration) {
            $wait = 0;
            if ($intervalIndex >= 0) {
                $wait = self::RetryIntervals[$intervalIndex];
            }
            if ($wait > 0) {
                if (self::sleepUnlessCanceled((float) $wait, $canceled)) {
                    throw new \RuntimeException('context canceled');
                }
            } elseif ($canceled !== null && $canceled()) {
                throw new \RuntimeException('context canceled');
            }

            try {
                $f($ctx);
                return;
            } catch (\Throwable $e) {
                $err = $e;
            }
            $ctx->attempts++;
            if (str_contains($err->getMessage(), 'context canceled')) {
                throw $err;
            }
            if (ErrNoRetry::is($err)) {
                throw $err;
            }
            if ($intervalIndex < \count(self::RetryIntervals) - 1) {
                $intervalIndex++;
            }
            if (self::$retryInline) {
                // PHP-only: do not block the process for the back-off; the
                // next maintenance pass (or handshake) retries the operation
                Log::get()->error('will retry on next maintenance pass', [
                    'error' => $err->getMessage(),
                    'attempt' => $ctx->attempts,
                    'elapsed' => Rfc3339::durationString(microtime(true) - $start),
                ]);
                throw $err;
            }
            if (microtime(true) - $start < self::MaxRetryDuration) {
                Log::get()->error('will retry', [
                    'error' => $err->getMessage(),
                    'attempt' => $ctx->attempts,
                    'retrying_in' => Rfc3339::durationString((float) self::RetryIntervals[$intervalIndex]),
                    'elapsed' => Rfc3339::durationString(microtime(true) - $start),
                    'max_duration' => Rfc3339::durationString((float) self::MaxRetryDuration),
                ]);
            } else {
                Log::get()->error('final attempt; giving up', [
                    'error' => $err->getMessage(),
                    'attempt' => $ctx->attempts,
                    'elapsed' => Rfc3339::durationString(microtime(true) - $start),
                    'max_duration' => Rfc3339::durationString((float) self::MaxRetryDuration),
                ]);
                return;
            }
        }
        if ($err !== null) {
            throw $err;
        }
    }

    /**
     * sleepUnlessCanceled waits through the ACME client's waiter (which keeps
     * the challenge listeners and the HTTPS server responsive) in short slices,
     * returning true as soon as `$canceled()` reports cancellation.
     *
     * @param callable(): bool|null $canceled
     */
    private static function sleepUnlessCanceled(float $seconds, ?callable $canceled): bool
    {
        $deadline = microtime(true) + $seconds;
        while (true) {
            if ($canceled !== null && $canceled()) {
                return true;
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return false;
            }
            AcmeClient::wait(min(1.0, $remaining));
        }
    }
}
