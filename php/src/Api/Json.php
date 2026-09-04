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

use Psr\Http\Message\ResponseInterface;

/**
 * Json is the PSR-7 counterpart of the Gin response helpers used by the
 * handlers: `c.JSON(status, body)` → {@see Json::write()} and
 * `c.Status(status)` → {@see Json::status()}.
 *
 * Encoding mirrors Go's encoding/json as used by Gin's render.JSON: HTML
 * characters `<`, `>` and `&` are escaped as \u escapes, `/` and non-ASCII
 * characters are emitted verbatim, invalid UTF-8 is replaced with U+FFFD and
 * integral floats serialize without a fractional part.
 */
final class Json
{
    /** Gin's render.JSON content type. */
    public const ContentType = 'application/json; charset=utf-8';

    private const ENCODE_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_INVALID_UTF8_SUBSTITUTE;

    private function __construct()
    {
    }

    /**
     * encode marshals a value the way Gin's c.JSON does.
     *
     * @throws \JsonException when the value cannot be encoded.
     */
    public static function encode(mixed $data): string
    {
        return json_encode($data, self::ENCODE_FLAGS | JSON_THROW_ON_ERROR);
    }

    /**
     * write is the port of `c.JSON(status, data)`: it serializes the data as
     * JSON into the response body and sets the status and content type.
     */
    public static function write(ResponseInterface $response, int $status, mixed $data): ResponseInterface
    {
        $response->getBody()->write(self::encode($data));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', self::ContentType);
    }

    /**
     * status is the port of `c.Status(status)`: sets the status code and writes
     * no body.
     */
    public static function status(ResponseInterface $response, int $status): ResponseInterface
    {
        return $response->withStatus($status);
    }

    /**
     * text is the port of `c.String(status, text)` / Gin's default plain-text
     * responses (e.g. the "404 page not found" NoRoute body).
     */
    public static function text(ResponseInterface $response, int $status, string $text): ResponseInterface
    {
        $response->getBody()->write($text);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/plain');
    }
}
