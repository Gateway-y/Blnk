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

use Blnk\Core\QueueTask;

/**
 * ServeMux is the replacement for `asynq.ServeMux` (PORTING.md "Queue": asynq
 * is replaced by Redis-list task envelopes): it routes a popped
 * {@see QueueTask} to the handler registered for its task type.
 *
 * Matching follows asynq exactly — an exact match on the type name first, then
 * the longest registered pattern that is a prefix of the type name; a task
 * without handler fails with asynq's NotFound error ("handler not found for
 * task ..."), which makes the worker retry and eventually dead-letter it, as
 * asynq archives such tasks.
 *
 * Handlers are `callable(QueueTask): void` and signal failure by throwing
 * (Go: returning a non-nil error).
 */
final class ServeMux
{
    /** @var array<string, callable(QueueTask): void> pattern → handler */
    private array $handlers = [];

    /** @var string[] registered patterns sorted from longest to shortest (asynq `mux.es`) */
    private array $patterns = [];

    /**
     * HandleFunc registers the handler function for the given pattern.
     *
     * asynq panics on an empty pattern, a nil handler or a duplicate
     * registration; the PHP port throws the same messages.
     *
     * @param callable(QueueTask): void $handler
     * @throws \InvalidArgumentException|\LogicException
     */
    public function handleFunc(string $pattern, callable $handler): void
    {
        if (trim($pattern) === '') {
            throw new \InvalidArgumentException('asynq: invalid pattern');
        }
        if (isset($this->handlers[$pattern])) {
            throw new \LogicException('asynq: multiple registrations for ' . $pattern);
        }
        $this->handlers[$pattern] = $handler;
        $this->patterns[] = $pattern;
        usort($this->patterns, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
    }

    /**
     * Handler returns the handler to use for the given task, or null when
     * none matches (asynq returns the NotFound handler instead).
     *
     * @return callable(QueueTask): void|null
     */
    public function handler(QueueTask $task): ?callable
    {
        return $this->match($task->type);
    }

    /**
     * ProcessTask dispatches the task to the handler whose pattern matches the task type.
     *
     * @throws \RuntimeException when no handler is registered for the task type (asynq NotFound)
     * @throws \Throwable whatever the handler throws
     */
    public function processTask(QueueTask $task): void
    {
        $handler = $this->match($task->type);
        if ($handler === null) {
            throw new \RuntimeException(sprintf('handler not found for task "%s"', $task->type));
        }
        $handler($task);
    }

    /** patterns lists the registered patterns (longest first). @return string[] */
    public function patterns(): array
    {
        return $this->patterns;
    }

    /**
     * match mirrors asynq `ServeMux.match`: exact match first, then the longest
     * pattern that prefixes the type name.
     *
     * @return callable(QueueTask): void|null
     */
    private function match(string $typename): ?callable
    {
        if (isset($this->handlers[$typename])) {
            return $this->handlers[$typename];
        }
        foreach ($this->patterns as $pattern) {
            if (str_starts_with($typename, $pattern)) {
                return $this->handlers[$pattern];
            }
        }
        return null;
    }
}
