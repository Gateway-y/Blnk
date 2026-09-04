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

use Blnk\Internal\Log;

/**
 * ApiErrorException represents a custom error structure for the API.
 * It includes an error code, message, and optional details to provide
 * additional context for the error.
 *
 * Port of the Go `apierror.APIError` struct. Go returns `(T, error)`; the PHP
 * port throws — this is the root exception carrying the same error codes as
 * `internal/apierror` (see {@see ErrorCode}).
 */
class ApiErrorException extends \RuntimeException implements \JsonSerializable
{
    /**
     * The specific error code that identifies the type of error.
     * One of the {@see ErrorCode} constants. JSON tag: "code".
     */
    public readonly string $errorCode;

    /**
     * Optional field for additional details or context about the error.
     * JSON tag: "details,omitempty".
     */
    public readonly mixed $details;

    /**
     * @param string $errorCode One of the {@see ErrorCode} constants.
     * @param string $message A human-readable message that describes the error.
     * @param mixed $details Optional additional details or context about the error.
     */
    public function __construct(string $errorCode, string $message, mixed $details = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
        $this->details = $details;
    }

    /**
     * Error implements the error interface for APIError.
     * It returns a formatted string combining the error code and message.
     * (Go: `func (e APIError) Error() string`.)
     */
    public function error(): string
    {
        return sprintf('%s: %s', $this->errorCode, $this->getMessage());
    }

    /**
     * NewAPIError creates a new APIError instance.
     * It logs the error details and returns the error object with the provided
     * code, message, and additional details.
     *
     * Mirrors Go `apierror.NewAPIError`; the returned exception is meant to be
     * thrown by the caller.
     */
    public static function newApiError(string $code, string $message, mixed $details = null): self
    {
        // Log the error details for monitoring and debugging.
        Log::get()->error('API error', ['details' => $details]);
        return new self($code, $message, $details);
    }

    /**
     * MapErrorToHTTPStatus maps APIError codes to appropriate HTTP status codes.
     * It unwraps the error chain (the `previous` exception chain in PHP) to find
     * an ApiErrorException and resolves the status from the code catalog,
     * normalizing legacy codes.
     */
    public static function mapErrorToHTTPStatus(\Throwable $err): int
    {
        for ($e = $err; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof self) {
                return ErrorCode::statusForCode(ErrorCode::normalize($e->errorCode));
            }
        }
        return 500; // Default to 500 Internal Server Error if no specific mapping is found.
    }

    /**
     * Serializes exactly like the Go struct's JSON tags:
     * {"code": ..., "message": ..., "details": ...} with details omitempty.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $out = [
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ];
        if ($this->details !== null) {
            $out['details'] = $this->details;
        }
        return $out;
    }
}
