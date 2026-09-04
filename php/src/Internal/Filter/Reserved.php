<?php

declare(strict_types=1);

namespace Blnk\Internal\Filter;

/**
 * Port of internal/filter/reserved.go: query parameters that are never
 * interpreted as filters.
 */
final class Reserved
{
    /** @var array<string, bool> */
    private const RESERVED_PARAMS = [
        'page' => true,
        'pagesize' => true,
        'per_page' => true,
        'limit' => true,
        'offset' => true,
        'sort' => true,
        'order' => true,
        'order_by' => true,
        'order_dir' => true,
        'logical_operator' => true,
        'instance_id' => true,
        'org_id' => true,
    ];

    /**
     * @internal Used by Parser::parseFromQuery().
     */
    public static function isReservedParam(string $param): bool
    {
        return self::RESERVED_PARAMS[strtolower($param)] ?? false;
    }
}
