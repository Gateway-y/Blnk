<?php

declare(strict_types=1);

namespace Blnk\Internal\Filter;

/**
 * ParseError mirrors the Go `ParseError` struct (internal/filter/types.go):
 * a per-parameter parse failure collected (not thrown) during query parsing.
 */
final class ParseError implements \JsonSerializable
{
    public string $param;
    public string $message;

    public function __construct(string $param, string $message)
    {
        $this->param = $param;
        $this->message = $message;
    }

    public function jsonSerialize(): array
    {
        return [
            'param' => $this->param,
            'message' => $this->message,
        ];
    }
}
