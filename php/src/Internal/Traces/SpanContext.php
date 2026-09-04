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

namespace Blnk\Internal\Traces;

/**
 * SpanContext is the port of `trace.SpanContext`: the immutable identity of a
 * span (trace id, span id, W3C trace flags, tracestate, remote flag).
 */
final class SpanContext
{
    /** W3C trace flag: the trace is sampled. */
    public const FlagsSampled = 0x01;

    private const InvalidTraceID = '00000000000000000000000000000000';
    private const InvalidSpanID = '0000000000000000';

    /** 32 lower-case hex characters. */
    public readonly string $traceId;

    /** 16 lower-case hex characters. */
    public readonly string $spanId;

    public readonly int $traceFlags;

    /** W3C `tracestate` header value (may be empty). */
    public readonly string $traceState;

    /** Whether the context was propagated from a remote parent. */
    public readonly bool $remote;

    public function __construct(string $traceId, string $spanId, int $traceFlags = self::FlagsSampled, string $traceState = '', bool $remote = false)
    {
        $this->traceId = strtolower($traceId);
        $this->spanId = strtolower($spanId);
        $this->traceFlags = $traceFlags & 0xFF;
        $this->traceState = $traceState;
        $this->remote = $remote;
    }

    /** The invalid (zero) span context, `trace.SpanContext{}`. */
    public static function empty(): self
    {
        return new self(self::InvalidTraceID, self::InvalidSpanID, 0);
    }

    /**
     * generate creates a new root context with random trace and span ids
     * (the SDK's default `IDGenerator`, which never yields all-zero ids).
     */
    public static function generate(?string $traceId = null): self
    {
        $traceId ??= self::randomHex(16);
        return new self($traceId, self::randomHex(8), self::FlagsSampled);
    }

    /**
     * IsValid mirrors `SpanContext.IsValid`: both ids well-formed and non-zero.
     */
    public function isValid(): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $this->traceId) === 1
            && $this->traceId !== self::InvalidTraceID
            && preg_match('/^[0-9a-f]{16}$/', $this->spanId) === 1
            && $this->spanId !== self::InvalidSpanID;
    }

    /** IsSampled reports whether the sampled trace flag is set. */
    public function isSampled(): bool
    {
        return ($this->traceFlags & self::FlagsSampled) === self::FlagsSampled;
    }

    /** IsRemote mirrors `SpanContext.IsRemote`. */
    public function isRemote(): bool
    {
        return $this->remote;
    }

    /** A copy marked as remote (the propagators' extract result). */
    public function withRemote(bool $remote): self
    {
        return new self($this->traceId, $this->spanId, $this->traceFlags, $this->traceState, $remote);
    }

    private static function randomHex(int $bytes): string
    {
        do {
            $hex = bin2hex(random_bytes($bytes));
        } while (trim($hex, '0') === '');
        return $hex;
    }
}
