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

namespace Blnk\Cmd\CertMagic\Acme;

use Psr\Http\Message\ResponseInterface;

/**
 * HttpError is a non-problem-document HTTP failure of an ACME request
 * (Go: the `fmt.Errorf("HTTP %d: ...")` errors of acme/http.go). It carries
 * the response so callers can inspect the status code, as Go's `httpReq`
 * returns the response alongside the error.
 */
final class HttpError extends \RuntimeException
{
    public ?ResponseInterface $response;

    public function __construct(string $message, ?ResponseInterface $response = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $response !== null ? $response->getStatusCode() : 0, $previous);
        $this->response = $response;
    }

    /**
     * responseOf returns the HTTP response attached anywhere in an error chain.
     */
    public static function responseOf(?\Throwable $err): ?ResponseInterface
    {
        while ($err !== null) {
            if ($err instanceof self && $err->response !== null) {
                return $err->response;
            }
            if ($err instanceof Problem && $err->httpResponse !== null) {
                return $err->httpResponse;
            }
            $err = $err->getPrevious();
        }
        return null;
    }
}
