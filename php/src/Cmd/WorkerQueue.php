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

use Blnk\Core\Queue;
use Blnk\Core\QueueTask;

/**
 * WorkerQueue is the consumer-side view of {@see Queue} used by the
 * `blnk workers` loop (the asynq server's dequeue path).
 *
 * {@see Queue::pop()} blocks — up to one second per call on a single queue —
 * which would stall the single-process worker loop of the PHP port (see
 * {@see WorkersCommand}) between the transaction, hot-lane and webhook server
 * groups. {@see tryPop()} performs the same steps without blocking (forward
 * due scheduled/retried tasks, RPOPLPUSH the oldest pending envelope into the
 * processing list, take its lease) so the loop can interleave every group and
 * its periodic work, sleeping only when every queue is empty.
 */
final class WorkerQueue extends Queue
{
    /**
     * tryPop takes the next task of the given queues without blocking: due
     * scheduled tasks are forwarded first, then the queues are tried in the
     * given order (the caller orders them by asynq weight) and the first
     * pending envelope is moved to its processing list and leased. Returns
     * null when every queue is empty. Undecodable envelopes are dead-lettered
     * and skipped, as in {@see Queue::pop()}.
     *
     * @param string[] $queueNames
     */
    public function tryPop(array $queueNames): ?QueueTask
    {
        $queues = array_values(array_unique(array_map('strval', $queueNames)));
        if ($queues === []) {
            return null;
        }

        foreach ($queues as $queue) {
            $this->forwardScheduled($queue);
        }

        foreach ($queues as $queue) {
            while (true) {
                $raw = $this->client->rpoplpush(self::pendingKey($queue), self::processingKey($queue));
                if (!\is_string($raw) || $raw === '') {
                    break; // queue empty: try the next one
                }
                $task = $this->leaseTask($queue, $raw);
                if ($task !== null) {
                    return $task;
                }
                // an undecodable envelope was dead-lettered; keep popping this queue
            }
        }

        return null;
    }
}
