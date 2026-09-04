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

namespace Blnk\Internal\ApiError;

/**
 * ErrorResponse is the standard envelope for structured error payloads.
 *
 * Port of Go `apierror.ErrorResponse` — serializes as {"error": {"code": ...,
 * "message": ..., "details": ...}} with details omitempty.
 */
final class ErrorResponse implements \JsonSerializable
{
    /** The specific error code that identifies the type of error. */
    public string $code;

    /** A human-readable message that describes the error. */
    public string $message;

    /** Optional field for additional details or context about the error. */
    public mixed $details;

    public function __construct(string $code, string $message, mixed $details = null)
    {
        $this->code = $code;
        $this->message = $message;
        $this->details = $details;
    }

    /**
     * NewErrorResponse builds an ErrorResponse with the code normalized to its
     * canonical form. Unlike NewAPIError it does not log; callers own logging.
     */
    public static function newErrorResponse(string $code, string $message, mixed $details = null): self
    {
        return new self(ErrorCode::normalize($code), $message, $details);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function jsonSerialize(): array
    {
        $error = [
            'code' => $this->code,
            'message' => $this->message,
        ];
        if ($this->details !== null) {
            $error['details'] = $this->details;
        }
        return ['error' => $error];
    }
}
