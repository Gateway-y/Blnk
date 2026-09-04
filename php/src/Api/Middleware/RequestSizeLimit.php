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

namespace Blnk\Api\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Port of `RequestSizeLimit` (api/middleware/middleware.go).
 *
 * RequestSizeLimit caps the size of non-upload request bodies so a large POST
 * cannot exhaust memory before a handler (or the auth middleware that reads the
 * body to inject the API key) consumes it. Multipart uploads are skipped: the
 * upload handlers apply their own, larger MaxUploadSizeMB limit.
 *
 * Documented divergence: Go wraps the body in `http.MaxBytesReader`, which
 * fails the read once more than maxBytes have been consumed. PHP has already
 * buffered the body when the script starts, so the size is checked up front
 * and an oversized body is replaced by an {@see OversizedBodyStream} whose
 * reads fail with the same "http: request body too large" error — the
 * handlers then respond exactly as their Go counterparts do.
 */
final class RequestSizeLimit implements MiddlewareInterface
{
    private int $maxBytes;

    public function __construct(int $maxBytes)
    {
        $this->maxBytes = $maxBytes;
    }

    /**
     * RequestSizeLimit — static factory mirroring the Go function name.
     */
    public static function requestSizeLimit(int $maxBytes): self
    {
        return new self($maxBytes);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->maxBytes <= 0) {
            return $handler->handle($request);
        }
        if (str_starts_with($request->getHeaderLine('Content-Type'), 'multipart/form-data')) {
            return $handler->handle($request);
        }

        $body = $request->getBody();
        $size = $body->getSize();
        if ($size === null) {
            $contentLength = $request->getHeaderLine('Content-Length');
            $size = $contentLength !== '' && ctype_digit($contentLength) ? (int) $contentLength : strlen((string) $body);
        }
        if ($size > $this->maxBytes) {
            $request = $request->withBody(new OversizedBodyStream($size));
        }

        return $handler->handle($request);
    }
}
