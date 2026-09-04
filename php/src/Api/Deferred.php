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

namespace Blnk\Api;

use Blnk\Internal\Log;

/**
 * Deferred is the API layer's replacement for the `go func() { ... }()`
 * calls the Go handlers make after deciding their response (the reindex run
 * in StartReindex, the balance snapshot pass): a job registered here runs
 * once the response has been sent — the front controller calls
 * {@see Deferred::run()} after `fastcgi_finish_request()` — so the client
 * gets the same immediate reply as in Go while the work continues in the
 * same PHP process.
 *
 * Under the CLI web server (`php -S`) there is no early flush; the jobs still
 * run after the handler, and the response is delivered when they finish.
 */
final class Deferred
{
    /** @var list<callable(): void> */
    private static array $jobs = [];

    private function __construct()
    {
    }

    /**
     * defer registers a job to run after the response is flushed.
     *
     * @param callable(): void $job
     */
    public static function defer(callable $job): void
    {
        self::$jobs[] = $job;
    }

    /** pending reports how many jobs are waiting. */
    public static function pending(): int
    {
        return count(self::$jobs);
    }

    /**
     * run executes and clears the registered jobs in order; a failing job is
     * logged (Go: the goroutine's error is dropped) and never stops the rest.
     */
    public static function run(): void
    {
        $jobs = self::$jobs;
        self::$jobs = [];
        foreach ($jobs as $job) {
            try {
                $job();
            } catch (\Throwable $e) {
                Log::get()->error('deferred job failed', ['error' => $e->getMessage(), 'exception' => $e]);
            }
        }
    }
}
