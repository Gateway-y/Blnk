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

namespace Blnk\Api\Model;

/**
 * BulkInflightResult is the per-item outcome reported in BulkInflightResponse.
 * On success Status == "succeeded" and Code is empty. On failure Status ==
 * "failed" with a stable Code (e.g. "ALREADY_VOIDED", "NOT_FOUND") that
 * callers can branch on, plus a human-readable Message.
 * (Go: api/model/transaction.go `BulkInflightResult`.)
 */
final class BulkInflightResult implements \JsonSerializable
{
    public string $transactionID = '';

    public string $status = '';

    /** JSON: "code,omitempty". */
    public string $code = '';

    /** JSON: "message,omitempty". */
    public string $message = '';

    public function __construct(string $transactionID = '', string $status = '', string $code = '', string $message = '')
    {
        $this->transactionID = $transactionID;
        $this->status = $status;
        $this->code = $code;
        $this->message = $message;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['transaction_id'] ?? ''),
            (string) ($data['status'] ?? ''),
            (string) ($data['code'] ?? ''),
            (string) ($data['message'] ?? '')
        );
    }

    public function jsonSerialize(): array
    {
        $out = [
            'transaction_id' => $this->transactionID,
            'status' => $this->status,
        ];
        if ($this->code !== '') { // omitempty
            $out['code'] = $this->code;
        }
        if ($this->message !== '') { // omitempty
            $out['message'] = $this->message;
        }
        return $out;
    }
}
