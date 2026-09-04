<?php

declare(strict_types=1);

namespace Blnk\Internal\Filter;

/**
 * BuildResult mirrors the Go `BuildResult` struct (internal/filter/types.go):
 * the output of the SQL builder.
 *
 * Divergences from Go (documented):
 *
 * - Placeholders: the Go builder emits PostgreSQL `$n` placeholders; per the
 *   porting conventions (PORTING.md, "SQL") the PHP port emits PDO positional
 *   `?` placeholders. Where the Go SQL reuses one numbered placeholder several
 *   times (e.g. the virtual `balance_id` filter,
 *   `(source = $1 OR destination = $1)`), the PHP fragment carries one `?` per
 *   occurrence and the bound value is repeated, so
 *   `nextArgPos - startArgPos === count($args)` always holds.
 *
 * - Argument order: with numbered placeholders Go can append CTE-bound
 *   arguments wherever the filter appears; with positional `?` the binding
 *   order must follow SQL text order. Because a `WITH` clause precedes the
 *   WHERE conditions in the assembled query, `$args` here is ordered as all
 *   CTE arguments first (`$cteArgs`), then all condition arguments
 *   (`$conditionArgs`). Callers whose base query carries its own positional
 *   arguments must interleave them accordingly when `$ctes` is non-empty:
 *   bind `$cteArgs`, then the base query's arguments, then `$conditionArgs`.
 */
final class BuildResult
{
    /**
     * Common table expressions (without the leading WITH) required by the
     * conditions, or null when none.
     *
     * @var string[]|null
     */
    public ?array $ctes = null;

    /** @var string[] Individual SQL condition fragments. */
    public array $conditions = [];

    /**
     * All bind arguments: CTE arguments followed by condition arguments (see
     * class doc for ordering).
     *
     * @var array<int, mixed>
     */
    public array $args = [];

    /** @var array<int, mixed> Arguments bound by placeholders inside $ctes. */
    public array $cteArgs = [];

    /** @var array<int, mixed> Arguments bound by placeholders inside $conditions. */
    public array $conditionArgs = [];

    /** The next free argument position after the built conditions. */
    public int $nextArgPos = 0;

    /** The ORDER BY clause (without "ORDER BY" prefix). */
    public string $orderBy = '';
}
