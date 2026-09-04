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

namespace Blnk\Core;

/**
 * TaskIDConflictException is the PHP port's replacement for
 * `asynq.ErrTaskIDConflict` ("task ID conflicts with another task"): thrown by
 * {@see Queue::enqueueTask()} when a task with the same `asynq.TaskID` is
 * still pending, scheduled, retrying or being processed on the same queue.
 *
 * Go checks it with `errors.Is(err, asynq.ErrTaskIDConflict)`; the PHP port
 * catches this class.
 */
final class TaskIDConflictException extends \RuntimeException
{
    /** asynq's sentinel message. */
    public const MESSAGE = 'task ID conflicts with another task';

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(self::MESSAGE, 0, $previous);
    }
}
